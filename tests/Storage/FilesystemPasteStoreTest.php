<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Storage\AtomicFile;
use QuietLink\Storage\FilesystemPasteStore;
use QuietLink\Storage\PasteId;
use QuietLink\Storage\PasteMeta;
use QuietLink\Storage\PasteRecord;
use QuietLink\Storage\PasteState;
use QuietLink\Storage\Reservation;
use QuietLink\Storage\StateName;
use QuietLink\Storage\StorageLayout;
use QuietLink\Storage\UsageCounter;
use QuietLink\Tests\Support\FrozenClock;
use QuietLink\Tests\Support\TempDirectory;

#[CoversClass(FilesystemPasteStore::class)]
#[CoversClass(PasteId::class)]
#[CoversClass(PasteState::class)]
#[CoversClass(PasteMeta::class)]
#[CoversClass(AtomicFile::class)]
#[CoversClass(UsageCounter::class)]
final class FilesystemPasteStoreTest extends TestCase
{
    private TempDirectory $tmp;
    private FrozenClock $clock;
    private FilesystemPasteStore $store;
    private UsageCounter $usage;

    protected function setUp(): void
    {
        $this->tmp = new TempDirectory();
        $this->clock = new FrozenClock();
        $layout = new StorageLayout(
            $this->tmp->path . '/pastes',
            $this->tmp->path . '/idempotency',
            $this->tmp->path . '/state',
        );
        $layout->ensureDirectories();
        $this->usage = new UsageCounter($layout, 1000, 3);
        $this->store = new FilesystemPasteStore($layout, $this->usage, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    private function meta(PasteId $id, bool $readOnce = false, ?int $expiresAt = null): PasteMeta
    {
        return new PasteMeta($id, '{"dummy":"aad"}', $this->clock->now(), $expiresAt ?? $this->clock->now() + 3600, $readOnce, str_repeat("\x05", 32), str_repeat("\x06", 32));
    }

    private function createPaste(bool $readOnce = false, string $payload = 'nonce+ciphertext'): PasteId
    {
        return $this->store->create(
            fn (PasteId $id): PasteMeta => $this->meta($id, $readOnce),
            fn (): PasteId => PasteId::fromBytes(random_bytes(24)),
            $payload,
        );
    }

    #[Group('EXG-STORE-019')]
    #[Group('EXG-STORE-003')]
    public function testCreatedPasteCanBeFoundWithItsPayload(): void
    {
        $id = $this->createPaste();
        $record = $this->store->find($id);

        self::assertInstanceOf(PasteRecord::class, $record);
        self::assertSame($id->bytes(), $record->meta->id->bytes());
        self::assertSame('{"dummy":"aad"}', $record->meta->aad);
        self::assertSame(StateName::Available, $record->state->name);
        self::assertSame('nonce+ciphertext', $this->store->readPayload($id));
        self::assertSame(['bytes' => 16, 'items' => 1], $this->usage->read());
    }

    #[Group('EXG-STORE-016')]
    #[Group('EXG-STORE-042')]
    public function testFilesAreShardedAndPrivate(): void
    {
        $id = $this->createPaste();
        $encoded = $id->encoded();
        $dir = sprintf('%s/pastes/%s/%s/%s', $this->tmp->path, substr($encoded, 0, 2), substr($encoded, 2, 2), $encoded);

        self::assertDirectoryExists($dir);
        self::assertSame(0700, fileperms($dir) & 0777);
        foreach (['payload.bin', 'meta.json', 'state.json', 'state.lock'] as $file) {
            self::assertFileExists($dir . '/' . $file);
            self::assertSame(0600, fileperms($dir . '/' . $file) & 0777, $file);
        }
        self::assertSame([], glob(dirname($dir) . '/.*tmp*'));
    }

    #[Group('EXG-STORE-011')]
    #[Group('EXG-STORE-018')]
    public function testStoredFilesContainNoForbiddenField(): void
    {
        $id = $this->createPaste();
        $encoded = $id->encoded();
        $dir = sprintf('%s/pastes/%s/%s/%s', $this->tmp->path, substr($encoded, 0, 2), substr($encoded, 2, 2), $encoded);
        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        $state = json_decode((string) file_get_contents($dir . '/state.json'), true);

        self::assertIsArray($meta);
        self::assertIsArray($state);
        self::assertSame(['schema_version', 'id', 'aad', 'created_at', 'expires_at', 'read_once', 'deletion_token_hash', 'idempotency_key_hash'], array_keys($meta));
        self::assertSame(['schema_version', 'state', 'terminal_at', 'unconfirmed_opens', 'reservation', 'consumed_signature_hash'], array_keys($state));
    }

    public function testUnknownPasteIsNotFound(): void
    {
        self::assertNull($this->store->find(PasteId::fromBytes(random_bytes(24))));
        self::assertNull($this->store->readPayload(PasteId::fromBytes(random_bytes(24))));
    }

    #[Group('EXG-STORE-012')]
    #[Group('EXG-STORE-045')]
    public function testCorruptedOrUnknownVersionStateFailsClosed(): void
    {
        $id = $this->createPaste();
        $encoded = $id->encoded();
        $stateFile = sprintf('%s/pastes/%s/%s/%s/state.json', $this->tmp->path, substr($encoded, 0, 2), substr($encoded, 2, 2), $encoded);

        file_put_contents($stateFile, str_replace('"schema_version":1', '"schema_version":2', (string) file_get_contents($stateFile)));
        self::assertNull($this->store->find($id));

        file_put_contents($stateFile, '{not json');
        self::assertNull($this->store->find($id));
    }

    #[Group('EXG-STORE-014')]
    #[Group('EXG-STORE-027')]
    public function testMutationIsAppliedAtomicallyUnderLock(): void
    {
        $id = $this->createPaste(readOnce: true);
        $reservation = new Reservation(str_repeat("\x01", 32), str_repeat("\x02", 82), $this->clock->now() + 60);

        $result = $this->store->mutate($id, static function (PasteRecord $record) use ($reservation): array {
            return [$record->state->withReservation($reservation), 'reserved'];
        });

        self::assertSame('reserved', $result);
        $record = $this->store->find($id);
        self::assertNotNull($record);
        self::assertSame(StateName::Reserved, $record->state->name);
        self::assertNotNull($record->state->reservation);
        self::assertSame(str_repeat("\x02", 82), $record->state->reservation->consumeChallenge);
    }

    public function testMutationOnMissingPasteReturnsNull(): void
    {
        self::assertNull($this->store->mutate(PasteId::fromBytes(random_bytes(24)), static fn (): array => [null, 'never']));
    }

    #[Group('EXG-STORE-021')]
    public function testConsumedPasteLosesItsPayloadAndBytes(): void
    {
        $id = $this->createPaste(readOnce: true);

        $this->store->mutate($id, fn (PasteRecord $r): array => [$r->state->consumed($this->clock->now(), str_repeat("\x09", 32)), null]);

        $record = $this->store->find($id);
        self::assertNotNull($record);
        self::assertSame(StateName::Consumed, $record->state->name);
        self::assertNull($this->store->readPayload($id));
        self::assertSame(['bytes' => 0, 'items' => 1], $this->usage->read());
    }

    #[Group('EXG-STORE-022')]
    #[Group('EXG-STORE-039')]
    public function testRemovalDeletesEverythingAndReleasesUsage(): void
    {
        $id = $this->createPaste();

        self::assertTrue($this->store->remove($id));
        self::assertNull($this->store->find($id));
        self::assertSame(['bytes' => 0, 'items' => 0], $this->usage->read());
        self::assertFalse($this->store->remove($id));
    }

    #[Group('EXG-STORE-005')]
    public function testQuotaRefusesCreationAndRollsBackNothing(): void
    {
        $this->createPaste();
        $this->createPaste();
        $this->createPaste();

        $this->expectException(\QuietLink\Storage\QuotaExceededException::class);
        try {
            $this->createPaste();
        } finally {
            self::assertSame(['bytes' => 48, 'items' => 3], $this->usage->read());
        }
    }

    public function testByteQuotaIsEnforced(): void
    {
        $this->expectException(\QuietLink\Storage\QuotaExceededException::class);
        $this->createPaste(payload: str_repeat('x', 1001));
    }

    #[Group('EXG-STORE-020')]
    public function testIdentifierCollisionDrawsANewIdentifier(): void
    {
        $first = $this->createPaste();
        $draws = [$first, PasteId::fromBytes(random_bytes(24))];

        $second = $this->store->create(
            fn (PasteId $id): PasteMeta => $this->meta($id),
            static function () use (&$draws): PasteId {
                $next = array_shift($draws);
                self::assertNotNull($next);

                return $next;
            },
            'payload',
        );

        self::assertNotSame($first->bytes(), $second->bytes());
        self::assertSame('nonce+ciphertext', $this->store->readPayload($first));
    }

    public function testListsStoredIdentifiers(): void
    {
        $a = $this->createPaste();
        $b = $this->createPaste();
        $ids = array_map(static fn (PasteId $id): string => $id->encoded(), iterator_to_array($this->store->ids(), false));
        sort($ids);
        $expected = [$a->encoded(), $b->encoded()];
        sort($expected);

        self::assertSame($expected, $ids);
    }
}
