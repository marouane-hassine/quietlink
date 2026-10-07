<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Paste;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Client\ClientCrypto;
use QuietLink\Client\PreparedPaste;
use QuietLink\Crypto\Aad;
use QuietLink\Crypto\DecryptionFailedException;
use QuietLink\Encoding\Base64Url;
use QuietLink\Paste\IdempotencyConflictException;
use QuietLink\Paste\InvalidRequestException;
use QuietLink\Paste\PasteService;
use QuietLink\Paste\PasteUnavailableException;
use QuietLink\Paste\PayloadTooLargeException;
use QuietLink\Paste\ReservationConflictException;
use QuietLink\Storage\FilesystemPasteStore;
use QuietLink\Storage\IdempotencyStore;
use QuietLink\Storage\QuotaExceededException;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\UsageCounter;
use QuietLink\Tests\Support\FrozenClock;
use QuietLink\Tests\Support\SpyPasteStore;
use QuietLink\Tests\Support\TempDirectory;
use QuietLink\Tests\Support\TestInstance;

#[CoversClass(PasteService::class)]
#[CoversClass(ClientCrypto::class)]
final class PasteServiceTest extends TestCase
{
    private const ENVELOPE = '{"format":"plain","language":null,"template":null,"text":"dummy text","v":1}';

    private TempDirectory $tmp;
    private FrozenClock $clock;
    private SpyPasteStore $store;
    private PasteService $service;
    private StateFiles $stateFiles;
    private int $freeSpace = PHP_INT_MAX;

    protected function setUp(): void
    {
        $this->tmp = new TempDirectory();
        $this->clock = new FrozenClock();
        $this->build();
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    /**
     * @param array<string, array<string, mixed>> $overrides
     */
    private function build(array $overrides = []): void
    {
        $config = TestInstance::config($this->tmp, $overrides);
        $layout = TestInstance::layout($config);
        $usage = new UsageCounter($layout, $config->storage->maxTotalBytes, $config->storage->maxItems);
        $this->store = new SpyPasteStore(new FilesystemPasteStore($layout, $usage, $this->clock));
        $this->stateFiles = new StateFiles($layout);
        $this->stateFiles->writeHealth($this->clock->now(), 1 << 40, 90);
        $this->service = new PasteService(
            $config,
            $this->store,
            new IdempotencyStore($layout, $this->clock),
            $this->stateFiles,
            $this->clock,
            fn (string $dir): int => $this->freeSpace,
        );
    }

    /**
     * @return array{PreparedPaste, string}
     */
    private function createPaste(bool $readOnce = false, ?string $passphrase = null, string $expiration = '1d'): array
    {
        $prepared = ClientCrypto::prepare(self::ENVELOPE, $expiration, $readOnce, $passphrase, 19456, 2);
        $result = $this->service->create($prepared->json(), $prepared->idempotencyKey);
        self::assertTrue($result['created']);

        return [$prepared, $result['id']->encoded()];
    }

    /**
     * @param array<string, string> $extra
     */
    private function proofBody(PreparedPaste $prepared, string $id, string $usage, array $extra = []): string
    {
        $challenge = $this->service->challenge($id, json_encode(['usage' => $usage], JSON_THROW_ON_ERROR));

        return json_encode([
            'challenge' => $challenge,
            'access_pk' => ClientCrypto::accessPublicKey($prepared->urlKey),
            'signature' => ClientCrypto::prove($prepared->accessSeed, $challenge),
        ] + $extra, JSON_THROW_ON_ERROR);
    }

    private function consumeBody(PreparedPaste $prepared, string $reservationId, string $challenge, ?string $seed = null): string
    {
        return json_encode([
            'access_pk' => ClientCrypto::accessPublicKey($prepared->urlKey),
            'reservation_id' => $reservationId,
            'challenge' => $challenge,
            'signature' => ClientCrypto::prove($seed ?? (string) $prepared->consumeSeed, $challenge),
        ], JSON_THROW_ON_ERROR);
    }

    #[Group('EXG-READ-009')]
    #[Group('EXG-READ-013')]
    #[Group('EXG-CRYPTO-032')]
    #[Group('EXG-API-013')]
    public function testCreateOpenAndDecryptRoundTrip(): void
    {
        [$prepared, $id] = $this->createPaste();

        $status = $this->service->status($id, $this->proofBody($prepared, $id, 'status'));
        self::assertFalse($status['read_once']);
        self::assertSame($this->clock->now() + 86400, $status['expires_at']);

        $opened = $this->service->open($id, $this->proofBody($prepared, $id, 'open'));
        $aad = Aad::fromBytes(Base64Url::decode($opened['aad']));
        $plaintext = ClientCrypto::decrypt($prepared->urlKey, null, $aad, Base64Url::decode($opened['nonce']), Base64Url::decode($opened['ciphertext']));

        self::assertSame(self::ENVELOPE, $plaintext);
    }

    #[Group('EXG-API-015')]
    #[Group('EXG-API-017')]
    #[Group('EXG-API-022')]
    #[Group('EXG-TEST-100')]
    public function testIdempotentReplayReturnsTheSameIdentifier(): void
    {
        [$prepared, $id] = $this->createPaste();
        $replay = $this->service->create($prepared->json(), $prepared->idempotencyKey);

        self::assertFalse($replay['created']);
        self::assertSame($id, $replay['id']->encoded());

        $this->expectException(IdempotencyConflictException::class);
        $other = ClientCrypto::prepare(self::ENVELOPE, '1d', false);
        $this->service->create($other->json(), $prepared->idempotencyKey);
    }

    #[Group('EXG-API-016')]
    #[Group('EXG-API-021')]
    #[Group('EXG-API-022')]
    #[Group('EXG-API-023')]
    public function testReplaySurvivesALoweredSizeLimitButNewCreationsDoNot(): void
    {
        $envelope = '{"format":"plain","language":null,"template":null,"text":"' . str_repeat('a', 2000) . '","v":1}';
        $prepared = ClientCrypto::prepare($envelope, '1d', false);
        $created = $this->service->create($prepared->json(), $prepared->idempotencyKey);
        self::assertTrue($created['created']);

        // The administrator lowers the envelope limit below the size of the earlier creation.
        $this->build(['paste' => ['max_envelope_bytes' => 1024]]);
        $replay = $this->service->create($prepared->json(), $prepared->idempotencyKey);
        self::assertFalse($replay['created']);
        self::assertSame($created['id']->encoded(), $replay['id']->encoded());
        self::assertSame($created['expires_at'], $replay['expires_at']);

        $other = ClientCrypto::prepare($envelope, '1d', false);
        $this->expectException(PayloadTooLargeException::class);
        $this->service->create($other->json(), $other->idempotencyKey);
    }

    #[Group('EXG-API-021')]
    public function testOversizedAadIsRejectedWhateverTheConfiguration(): void
    {
        $prepared = ClientCrypto::prepare(self::ENVELOPE, '1d', false);
        $body = ['aad' => Base64Url::encode(str_repeat('a', 4097))] + $prepared->body;

        $this->expectException(InvalidRequestException::class);
        $this->service->create(json_encode($body, JSON_THROW_ON_ERROR), $prepared->idempotencyKey);
    }

    #[Group('EXG-API-014')]
    #[Group('EXG-TEST-055')]
    public function testMissingIdempotencyKeyIsRejected(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->service->create(ClientCrypto::prepare(self::ENVELOPE, '1d', false)->json(), null);
    }

    #[Group('EXG-API-010')]
    #[Group('EXG-API-011')]
    #[Group('EXG-CRYPTO-034')]
    #[Group('EXG-API-021')]
    #[Group('EXG-GEN-009')]
    #[Group('EXG-SEC-106')]
    #[Group('EXG-TEST-057')]
    public function testUnknownBodyMemberIsRejected(): void
    {
        $prepared = ClientCrypto::prepare(self::ENVELOPE, '1d', false);
        $this->expectException(InvalidRequestException::class);
        $this->service->create(json_encode($prepared->body + ['text' => 'plain'], JSON_THROW_ON_ERROR), $prepared->idempotencyKey);
    }

    #[Group('EXG-API-023')]
    public function testOptionDisabledByTheInstanceIsRejected(): void
    {
        $this->build(['paste' => ['allow_read_once' => false]]);
        $this->expectException(InvalidRequestException::class);
        $this->createPaste(readOnce: true);
    }

    #[Group('EXG-STORE-007')]
    public function testLowDiskSpaceRefusesCreation(): void
    {
        $this->freeSpace = 1024;
        $this->expectException(QuotaExceededException::class);
        $this->createPaste();
    }

    #[Group('EXG-READ-017')]
    #[Group('EXG-READ-025')]
    #[Group('EXG-READ-026')]
    #[Group('EXG-TEST-040')]
    #[Group('EXG-TEST-057')]
    public function testInvalidProofNeverTouchesStorage(): void
    {
        [$prepared, $id] = $this->createPaste();
        $intruder = ClientCrypto::prepare(self::ENVELOPE, '1d', false);
        $this->store->accesses = 0;

        foreach (['status', 'open'] as $usage) {
            try {
                $body = $this->proofBody($intruder, $id, $usage);
                $usage === 'status' ? $this->service->status($id, $body) : $this->service->open($id, $body);
                self::fail('Proof with a foreign key must fail.');
            } catch (PasteUnavailableException) {
            }
        }
        try {
            $this->service->status($id, $this->proofBody($prepared, $id, 'open'));
            self::fail('A challenge of another usage must fail.');
        } catch (PasteUnavailableException) {
        }

        self::assertSame(0, $this->store->accesses);
    }

    /**
     * Shared hosting runs the purge at most hourly: a stale health.json no longer blocks
     * creation, whose free disk space is measured live; the inode threshold (measured by the
     * purge only) still refuses while a recent measurement reports it.
     */
    #[Group('EXG-STORE-006')]
    #[Group('EXG-STORE-008')]
    #[Group('EXG-TEST-046')]
    public function testCreationMeasuresDiskSpaceItselfWhenHealthIsStale(): void
    {
        $this->clock->advance(3600);
        $this->createPaste();

        $this->freeSpace = 1;
        try {
            $this->createPaste();
            self::fail('Low free space must still refuse creation.');
        } catch (QuotaExceededException) {
        }

        $this->freeSpace = 1 << 40;
        $this->stateFiles->writeHealth($this->clock->now(), 1 << 40, 1);
        $this->expectException(QuotaExceededException::class);
        $this->createPaste();
    }

    public function testExpiredChallengeIsRefused(): void
    {
        [$prepared, $id] = $this->createPaste();
        $body = $this->proofBody($prepared, $id, 'status');
        $this->clock->advance(61);

        $this->expectException(PasteUnavailableException::class);
        $this->service->status($id, $body);
    }

    #[Group('EXG-LIFE-008')]
    #[Group('EXG-LIFE-026')]
    #[Group('EXG-LIFE-003')]
    public function testExpiredPasteIsUnavailable(): void
    {
        [$prepared, $id] = $this->createPaste(expiration: '5m');
        $this->clock->advance(300);
        $this->stateFiles->writeHealth($this->clock->now(), 1 << 40, 90);

        $this->expectException(PasteUnavailableException::class);
        $this->service->open($id, $this->proofBody($prepared, $id, 'open'));
    }

    #[Group('EXG-READ-030')]
    #[Group('EXG-READ-031')]
    #[Group('EXG-READ-032')]
    #[Group('EXG-READ-034')]
    #[Group('EXG-READ-036')]
    #[Group('EXG-LIFE-012')]
    #[Group('EXG-LIFE-015')]
    #[Group('EXG-READ-021')]
    /**
     * §7.5: paste.max_retention bounds everything stored, pastes created before the operator
     * lowered it included: the shorter expiry is reported, then the paste is unavailable.
     */
    #[Group('EXG-LIFE-020')]
    public function testLoweredMaxRetentionAppliesToExistingPastes(): void
    {
        $prepared = ClientCrypto::prepare('{"format":"plain","language":null,"template":null,"text":"x","v":1}', '30d', false);
        $id = $this->service->create($prepared->json(), $prepared->idempotencyKey)['id']->encoded();
        $created = $this->clock->now();

        $this->build(['paste' => ['allowed_expirations' => ['1h', '1d', '7d'], 'default_expiration' => '1d', 'max_retention' => '7d']]);
        $status = $this->service->status($id, $this->proofBody($prepared, $id, 'status'));
        self::assertSame($created + 7 * 86400, $status['expires_at']);

        $this->clock->advance(8 * 86400);
        $this->expectException(PasteUnavailableException::class);
        $this->service->status($id, $this->proofBody($prepared, $id, 'status'));
    }

    /**
     * The reservation clock runs while a large response downloads: a confirmation arriving after
     * the reservation lapsed is accepted for one more reservation lifetime as long as nobody
     * else took the paste (only the reader who decrypted can sign the consume challenge).
     */
    #[Group('EXG-READ-020')]
    public function testLateConfirmationAfterASlowDownloadIsAccepted(): void
    {
        [$prepared, $id] = $this->createPaste(readOnce: true);
        $rid = Base64Url::encode(random_bytes(16));
        $opened = $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => $rid]));
        self::assertNotNull($opened['consume_challenge']);

        $this->clock->advance(75);
        $consume = $this->consumeBody($prepared, $rid, $opened['consume_challenge']);
        $this->service->consume($id, $consume);
        // Consumed: the exact replay succeeds, a new reader gets the uniform 404.
        $this->service->consume($id, $consume);
        $this->expectException(PasteUnavailableException::class);
        $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => Base64Url::encode(random_bytes(16))]));
    }

    /** Beyond the grace period, or once another reader holds the paste, a late one is refused. */
    #[Group('EXG-READ-020')]
    public function testLateConfirmationIsRefusedAfterTheGraceOrAnotherReservation(): void
    {
        [$prepared, $id] = $this->createPaste(readOnce: true);
        $rid = Base64Url::encode(random_bytes(16));
        $opened = $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => $rid]));
        $this->clock->advance(121);
        try {
            $this->service->consume($id, $this->consumeBody($prepared, $rid, (string) $opened['consume_challenge']));
            self::fail('A confirmation after twice the reservation lifetime must be refused.');
        } catch (PasteUnavailableException) {
        }

        [$prepared2, $id2] = $this->createPaste(readOnce: true);
        $first = Base64Url::encode(random_bytes(16));
        $opened2 = $this->service->open($id2, $this->proofBody($prepared2, $id2, 'open', ['reservation_id' => $first]));
        $this->clock->advance(61);
        $this->service->open($id2, $this->proofBody($prepared2, $id2, 'open', ['reservation_id' => Base64Url::encode(random_bytes(16))]));
        $this->expectException(PasteUnavailableException::class);
        $this->service->consume($id2, $this->consumeBody($prepared2, $first, (string) $opened2['consume_challenge']));
    }

    #[Group('EXG-TEST-036')]
    #[Group('EXG-TEST-039')]
    public function testReadOnceReservationConsumptionAndReplay(): void
    {
        [$prepared, $id] = $this->createPaste(readOnce: true);
        $rid = Base64Url::encode(random_bytes(16));

        $status = $this->service->status($id, $this->proofBody($prepared, $id, 'status'));
        self::assertSame('available', $status['state']);
        self::assertSame(0, $status['unconfirmed_opens']);

        $opened = $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => $rid]));
        self::assertNotNull($opened['consume_challenge']);
        self::assertSame(0, $opened['unconfirmed_opens']);

        try {
            $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => Base64Url::encode(random_bytes(16))]));
            self::fail('A concurrent reader must get a conflict.');
        } catch (ReservationConflictException $e) {
            self::assertSame(60, $e->retryAfter);
        }

        $this->clock->advance(10);
        $resumed = $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => $rid]));
        self::assertSame($opened['consume_challenge'], $resumed['consume_challenge']);
        self::assertSame(50, $resumed['retry_after']);

        $consume = $this->consumeBody($prepared, $rid, $opened['consume_challenge']);
        $this->service->consume($id, $consume);
        $this->service->consume($id, $consume);

        try {
            $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => $rid]));
            self::fail('A consumed paste must be unavailable.');
        } catch (PasteUnavailableException) {
        }

        $this->clock->advance(PasteService::CONSUMED_RETENTION);
        $this->expectException(PasteUnavailableException::class);
        $this->service->consume($id, $consume);
    }

    #[Group('EXG-API-036')]
    public function testConsumeWithAnotherKeyOrReservationFails(): void
    {
        [$prepared, $id] = $this->createPaste(readOnce: true);
        $rid = Base64Url::encode(random_bytes(16));
        $opened = $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => $rid]));
        self::assertNotNull($opened['consume_challenge']);

        foreach ([
            $this->consumeBody($prepared, $rid, $opened['consume_challenge'], $prepared->accessSeed),
            $this->consumeBody($prepared, Base64Url::encode(random_bytes(16)), $opened['consume_challenge']),
        ] as $body) {
            try {
                $this->service->consume($id, $body);
                self::fail('Invalid consumption must fail.');
            } catch (PasteUnavailableException) {
            }
        }
        $this->service->consume($id, $this->consumeBody($prepared, $rid, $opened['consume_challenge']));
        self::addToAssertionCount(1);
    }

    #[Group('EXG-LIFE-013')]
    #[Group('EXG-LIFE-014')]
    #[Group('EXG-LIFE-016')]
    #[Group('EXG-TEST-037')]
    public function testUnconfirmedOpensAreCountedThenDestroyThePaste(): void
    {
        [$prepared, $id] = $this->createPaste(readOnce: true);

        for ($i = 0; $i < 2; ++$i) {
            $opened = $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => Base64Url::encode(random_bytes(16))]));
            self::assertSame($i, $opened['unconfirmed_opens']);
            $this->clock->advance(60);
        }
        $status = $this->service->status($id, $this->proofBody($prepared, $id, 'status'));
        self::assertSame(2, $status['unconfirmed_opens']);

        $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => Base64Url::encode(random_bytes(16))]));
        $this->clock->advance(60);

        $this->expectException(PasteUnavailableException::class);
        $this->service->status($id, $this->proofBody($prepared, $id, 'status'));
    }

    #[Group('EXG-READ-028')]
    #[Group('EXG-TEST-038')]
    public function testPassphraseIsCheckedLocallyAgainstConsumeKey(): void
    {
        [$prepared, $id] = $this->createPaste(readOnce: true, passphrase: 'dummy passphrase');
        $status = $this->service->status($id, $this->proofBody($prepared, $id, 'status'));
        $aad = Aad::fromBytes(Base64Url::decode($status['aad']));

        $kPass = ClientCrypto::passphraseKey($aad, 'dummy passphrase');
        self::assertSame($prepared->consumeSeed, ClientCrypto::consumeSeed($aad, $prepared->urlKey, $kPass));

        $this->expectException(DecryptionFailedException::class);
        ClientCrypto::consumeSeed($aad, $prepared->urlKey, ClientCrypto::passphraseKey($aad, 'wrong'));
    }

    #[Group('EXG-API-038')]
    #[Group('EXG-API-039')]
    public function testDeletionRequiresTheBoundToken(): void
    {
        [$prepared, $id] = $this->createPaste();
        $this->store->accesses = 0;

        try {
            $this->service->delete($id, Base64Url::encode(random_bytes(32)));
            self::fail('Wrong token must fail.');
        } catch (PasteUnavailableException) {
        }
        self::assertSame(0, $this->store->accesses);

        $this->service->delete($id, Base64Url::encode($prepared->deletionToken));
        $this->expectException(PasteUnavailableException::class);
        $this->service->open($id, $this->proofBody($prepared, $id, 'open'));
    }

    /**
     * Two deletions racing (or a deletion racing the purge): the loser finds the paste, then its
     * removal fails because the winner already unlinked the lock; it gets the generic 404.
     */
    #[Group('EXG-API-038')]
    public function testDeletionThatLosesARaceIsUnavailable(): void
    {
        [$prepared, $id] = $this->createPaste();
        $this->store->loseRemovals = true;

        $this->expectException(PasteUnavailableException::class);
        $this->service->delete($id, Base64Url::encode($prepared->deletionToken));
    }

    #[Group('EXG-API-040')]
    #[Group('EXG-TEST-035')]
    public function testDeletionCancelsAnActiveReservation(): void
    {
        [$prepared, $id] = $this->createPaste(readOnce: true);
        $rid = Base64Url::encode(random_bytes(16));
        $opened = $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => $rid]));
        self::assertNotNull($opened['consume_challenge']);

        $this->service->delete($id, Base64Url::encode($prepared->deletionToken));

        $this->expectException(PasteUnavailableException::class);
        $this->service->consume($id, $this->consumeBody($prepared, $rid, $opened['consume_challenge']));
    }

    #[Group('EXG-API-029')]
    #[Group('EXG-READ-015')]
    #[Group('EXG-TEST-040')]
    public function testChallengeIsIdenticalInShapeForUnknownIdentifiers(): void
    {
        $this->store->accesses = 0;
        $challenge = $this->service->challenge(Base64Url::encode(random_bytes(24)), '{"usage":"open"}');

        self::assertSame(110, strlen($challenge));
        self::assertSame(0, $this->store->accesses);
    }

    #[Group('EXG-READ-018')]
    #[Group('EXG-TEST-058')]
    public function testValidProofForAnotherAccessKeyThanTheStoredAadFails(): void
    {
        // The identifier binds access_pk through A; a stored AAD with another key must still be refused.
        [$prepared, $id] = $this->createPaste();
        [$other] = $this->createPaste();
        $otherAad = Base64Url::decode($other->body['aad']);
        $dir = $this->tmp->path . '/data/pastes/' . substr($id, 0, 2) . '/' . substr($id, 2, 2) . '/' . $id;
        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        self::assertIsArray($meta);
        $meta['aad'] = Base64Url::encode($otherAad);
        file_put_contents($dir . '/meta.json', json_encode($meta, JSON_UNESCAPED_SLASHES));

        $this->expectException(PasteUnavailableException::class);
        $this->service->status($id, $this->proofBody($prepared, $id, 'status'));
    }

    #[Group('EXG-READ-022')]
    #[Group('EXG-TEST-042')]
    public function testConsumeChallengeSurvivesASecretRotation(): void
    {
        [$prepared, $id] = $this->createPaste(readOnce: true);
        $rid = Base64Url::encode(random_bytes(16));
        $opened = $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => $rid]));
        self::assertNotNull($opened['consume_challenge']);

        $rotated = \QuietLink\Config\ConfigLoader::load($this->tmp->path . '/config', ['QUIETLINK_APP_SECRET' => base64_encode(str_repeat("\x42", 32))]);
        $layout = TestInstance::layout($rotated);
        $service = new PasteService($rotated, $this->store, new IdempotencyStore($layout, $this->clock), $this->stateFiles, $this->clock, static fn (): int => PHP_INT_MAX);

        $service->consume($id, $this->consumeBody($prepared, $rid, $opened['consume_challenge']));
        self::addToAssertionCount(1);
    }

    #[Group('EXG-LIFE-017')]
    public function testExpiryDuringAReservationRefusesConsumption(): void
    {
        [$prepared, $id] = $this->createPaste(readOnce: true, expiration: '5m');
        $this->clock->advance(290);
        $rid = Base64Url::encode(random_bytes(16));
        $opened = $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => $rid]));
        self::assertNotNull($opened['consume_challenge']);
        $this->clock->advance(20);

        $this->expectException(PasteUnavailableException::class);
        $this->service->consume($id, $this->consumeBody($prepared, $rid, $opened['consume_challenge']));
    }

    #[Group('EXG-API-027')]
    #[Group('EXG-READ-027')]
    #[Group('EXG-TEST-056')]
    public function testSameKeysUnderANewIdempotencyKeyGetANewIdentifier(): void
    {
        [$prepared, $id] = $this->createPaste();
        $again = $this->service->create($prepared->json(), Base64Url::encode(random_bytes(16)));

        self::assertTrue($again['created']);
        self::assertNotSame($id, $again['id']->encoded());
        self::assertSame(substr(Base64Url::decode($id), 0, 16), substr($again['id']->bytes(), 0, 16));
    }

    #[Group('EXG-API-019')]
    public function testIdempotencyRecordIsKeptForTheShorterOfExpiryAndMaximumTtl(): void
    {
        $short = ClientCrypto::prepare(self::ENVELOPE, '1h', false);
        $this->service->create($short->json(), $short->idempotencyKey);
        $long = ClientCrypto::prepare(self::ENVELOPE, '7d', false);
        $this->service->create($long->json(), $long->idempotencyKey);

        $retain = [];
        $records = glob($this->tmp->path . '/data/idempotency/*/*.json');
        self::assertIsArray($records);
        foreach ($records as $file) {
            $record = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($record);
            self::assertIsInt($record['retain_until']);
            $retain[] = $record['retain_until'] - $this->clock->now();
        }
        sort($retain);
        self::assertSame([3600, 86400], $retain);
    }

    #[Group('EXG-API-024')]
    #[Group('EXG-TEST-053')]
    public function testFailedRecordPublicationDeletesTheNewPaste(): void
    {
        $prepared = ClientCrypto::prepare(self::ENVELOPE, '1h', false);
        $dir = $this->tmp->path . '/data/idempotency';
        chmod($dir, 0500);
        try {
            $this->service->create($prepared->json(), $prepared->idempotencyKey);
            self::fail('Creation must fail when the record cannot be written.');
        } catch (\QuietLink\Storage\StorageException) {
        } finally {
            chmod($dir, 0700);
        }
        self::assertSame([], glob($this->tmp->path . '/data/pastes/*/*/*', GLOB_ONLYDIR));
    }

    #[Group('EXG-API-040')]
    #[Group('EXG-TEST-035')]
    public function testDeletingAConsumedPasteRemovesItAndLaterReplaysFail(): void
    {
        // ADR-0008: spec §10 prevails, a valid token deletes in any state, including consumed.
        [$prepared, $id] = $this->createPaste(readOnce: true);
        $rid = Base64Url::encode(random_bytes(16));
        $opened = $this->service->open($id, $this->proofBody($prepared, $id, 'open', ['reservation_id' => $rid]));
        self::assertNotNull($opened['consume_challenge']);
        $consume = $this->consumeBody($prepared, $rid, $opened['consume_challenge']);
        $this->service->consume($id, $consume);

        $this->service->delete($id, Base64Url::encode($prepared->deletionToken));

        self::assertSame([], glob($this->tmp->path . '/data/pastes/*/*/*', GLOB_ONLYDIR));
        $this->expectException(PasteUnavailableException::class);
        $this->service->consume($id, $consume);
    }
}
