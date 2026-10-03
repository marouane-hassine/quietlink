<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Maintenance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Client\ClientCrypto;
use QuietLink\Client\PreparedPaste;
use QuietLink\Config\InstanceConfig;
use QuietLink\Encoding\Base64Url;
use QuietLink\Maintenance\DiskProbe;
use QuietLink\Maintenance\Purger;
use QuietLink\Paste\PasteService;
use QuietLink\RateLimit\RateLimiter;
use QuietLink\Storage\AtomicFile;
use QuietLink\Storage\FileLock;
use QuietLink\Storage\FilesystemPasteStore;
use QuietLink\Storage\IdempotencyRecord;
use QuietLink\Storage\IdempotencyStore;
use QuietLink\Storage\PasteId;
use QuietLink\Storage\PasteMeta;
use QuietLink\Storage\PasteState;
use QuietLink\Storage\RecordCodec;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\StorageException;
use QuietLink\Storage\StorageLayout;
use QuietLink\Storage\UsageCounter;
use QuietLink\Tests\Support\FrozenClock;
use QuietLink\Tests\Support\TempDirectory;
use QuietLink\Tests\Support\TestInstance;

#[CoversClass(Purger::class)]
final class PurgerTest extends TestCase
{
    private TempDirectory $tmp;
    private FrozenClock $clock;
    private InstanceConfig $config;
    private StorageLayout $layout;
    private FilesystemPasteStore $store;
    private UsageCounter $usage;
    private PasteService $service;
    private Purger $purger;
    /** @var \Psr\Log\AbstractLogger&object{records: list<array{string, string, array<array-key, mixed>}>} */
    private \Psr\Log\AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->tmp = new TempDirectory();
        $this->clock = new FrozenClock();
        $this->config = TestInstance::config($this->tmp);
        $this->layout = TestInstance::layout($this->config);
        $this->usage = new UsageCounter($this->layout, $this->config->storage->maxTotalBytes, $this->config->storage->maxItems);
        $this->store = new FilesystemPasteStore($this->layout, $this->usage, $this->clock);
        $idempotency = new IdempotencyStore($this->layout, $this->clock);
        $stateFiles = new StateFiles($this->layout);
        $stateFiles->writeHealth($this->clock->now(), 1 << 40, 90);
        $limiter = new RateLimiter($this->config->storage->ratelimitDir, $this->config->http->rateLimits, $this->config->secret, $this->clock);
        $limiter->ensureLockFiles();
        $this->service = new PasteService($this->config, $this->store, $idempotency, $stateFiles, $this->clock, static fn (): int => PHP_INT_MAX);
        $disk = new class () extends DiskProbe {
            public function freeInodesPercent(string $path): int
            {
                return 80;
            }
        };
        $this->logger = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<array{string, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [is_string($level) ? $level : "unknown", (string) $message, $context];
            }
        };
        $this->purger = new Purger($this->config, $this->layout, $this->store, $idempotency, $this->usage, $stateFiles, $limiter, $this->service, $disk, $this->clock, $this->logger);
        $this->purger->ensureLockFile();
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    /**
     * @return array{PreparedPaste, PasteId}
     */
    private function create(string $expiration = '1h', bool $readOnce = false): array
    {
        $prepared = ClientCrypto::prepare('{"format":"plain","language":null,"template":null,"text":"x","v":1}', $expiration, $readOnce);

        return [$prepared, $this->service->create($prepared->json(), $prepared->idempotencyKey)['id']];
    }

    private function open(PreparedPaste $prepared, PasteId $id): void
    {
        $challenge = $this->service->challenge($id->encoded(), '{"usage":"open"}');
        $this->service->open($id->encoded(), (string) json_encode([
            'challenge' => $challenge,
            'access_pk' => ClientCrypto::accessPublicKey($prepared->urlKey),
            'signature' => ClientCrypto::prove($prepared->accessSeed, $challenge),
            'reservation_id' => Base64Url::encode(random_bytes(16)),
        ]));
    }

    #[Group('EXG-LIFE-020')]
    #[Group('EXG-LIFE-022')]
    #[Group('EXG-STORE-004')]
    #[Group('EXG-STORE-044')]
    #[Group('EXG-LIFE-019')]
    #[Group('EXG-LIFE-021')]
    #[Group('EXG-TEST-035')]
    public function testExpiredPastesAreRemovedAndPurgeIsIdempotent(): void
    {
        [, $expired] = $this->create('5m');
        [, $alive] = $this->create('1d');
        $this->clock->advance(301);

        $stats = $this->purger->run();
        self::assertNotNull($stats);
        self::assertSame(1, $stats['removed']);
        self::assertNull($this->store->find($expired));
        self::assertNotNull($this->store->find($alive));
        self::assertSame(1, $this->usage->read()['items']);

        $again = $this->purger->run();
        self::assertNotNull($again);
        self::assertSame(0, $again['removed']);
    }

    #[Group('EXG-LIFE-016')]
    public function testStaleReservationsAreReleasedWithoutARequest(): void
    {
        [$prepared, $id] = $this->create(readOnce: true);
        $this->open($prepared, $id);
        $this->clock->advance(61);

        $stats = $this->purger->run();
        self::assertNotNull($stats);
        self::assertSame(1, $stats['released']);
        $record = $this->store->find($id);
        self::assertNotNull($record);
        self::assertSame(1, $record->state->unconfirmedOpens);
    }

    #[Group('EXG-STORE-038')]
    public function testConsumedPastesAreKeptTenMinutes(): void
    {
        [$prepared, $id] = $this->create(readOnce: true);
        for ($i = 0; $i < 3; ++$i) {
            $this->open($prepared, $id);
            $this->clock->advance(61);
        }
        $this->purger->run();
        self::assertNotNull($this->store->find($id), 'consumed paste must be kept for idempotent replays');

        $this->clock->advance(PasteService::CONSUMED_RETENTION);
        $this->purger->run();
        self::assertNull($this->store->find($id));
        self::assertSame(['bytes' => 0, 'items' => 0], $this->usage->read());
    }

    #[Group('EXG-API-026')]
    #[Group('EXG-TEST-053')]
    public function testOrphanWithoutIdempotencyRecordIsRemovedAfterFifteenMinutes(): void
    {
        $id = $this->store->create(
            fn (PasteId $id): PasteMeta => new PasteMeta($id, 'aad', $this->clock->now(), $this->clock->now() + 86400, false, str_repeat("\x01", 32), str_repeat("\x02", 32)),
            static fn (): PasteId => PasteId::fromBytes(random_bytes(24)),
            'payload',
        );
        [, $legit] = $this->create('1d');

        $this->clock->advance(Purger::ORPHAN_MIN_AGE + 1);
        $stats = $this->purger->run();

        self::assertNotNull($stats);
        self::assertSame(1, $stats['orphans']);
        self::assertNull($this->store->find($id));
        self::assertNotNull($this->store->find($legit));
    }

    /**
     * A crash between writing the `deleted` state and removing the directory leaves a paste that
     * nobody can read; the next purge completes the deletion (§9.4.1, storage-format T12).
     */
    #[Group('EXG-STORE-022')]
    public function testInterruptedDeletionIsCompletedByThePurge(): void
    {
        [, $id] = $this->create('1d');
        $dir = $this->layout->pasteDir($id);
        AtomicFile::write($dir . '/state.json', RecordCodec::encodeState(PasteState::initial()->deleted($this->clock->now())));

        $stats = $this->purger->run();

        self::assertNotNull($stats);
        self::assertSame(1, $stats['removed']);
        self::assertDirectoryDoesNotExist($dir);
        self::assertSame(['bytes' => 0, 'items' => 0], $this->usage->read());
    }

    /**
     * One paste that cannot be removed (unwritable directory, full disk, busy lock) must not stop
     * the purge of the others nor the rest of the run (§9.7).
     */
    #[Group('EXG-STORE-006')]
    public function testOneFailingRemovalDoesNotAbortTheRun(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Permissions are not enforced for root.');
        }
        [, $stuck] = $this->create('5m');
        [, $other] = $this->create('5m');
        $this->clock->advance(400);
        chmod($this->layout->pasteDir($stuck), 0500);

        try {
            $stats = $this->purger->run();
        } finally {
            chmod($this->layout->pasteDir($stuck), 0700);
        }

        self::assertNotNull($stats);
        self::assertSame(1, $stats['removed']);
        self::assertNull($this->store->find($other));
    }

    /**
     * A record's retention is fixed at creation with the TTL configured then. After the TTL is
     * raised, a published paste whose record has expired must not be taken for an orphan.
     */
    #[Group('EXG-API-026')]
    #[Group('EXG-TEST-053')]
    public function testPublishedPasteIsKeptAfterItsRecordExpiredUnderAnEarlierShorterTtl(): void
    {
        $keyHash = str_repeat("\x07", 32);
        $now = $this->clock->now();
        $id = $this->store->create(
            static fn (PasteId $id): PasteMeta => new PasteMeta($id, 'aad', $now, $now + 86400, false, str_repeat("\x01", 32), $keyHash),
            static fn (): PasteId => PasteId::fromBytes(random_bytes(24)),
            'payload',
        );
        // Published while paste.idempotency_max_ttl was 1h (the configuration now says 24h).
        (new IdempotencyStore($this->layout, $this->clock))->publish(new IdempotencyRecord($keyHash, str_repeat("\x08", 32), $id, $now + 86400, $now + 3600));

        $this->clock->advance(3601);
        $stats = $this->purger->run();

        self::assertNotNull($stats);
        self::assertSame(0, $stats['orphans']);
        self::assertNotNull($this->store->find($id));
    }

    #[Group('EXG-STORE-043')]
    #[Group('EXG-STORE-006')]
    #[Group('EXG-TEST-045')]
    public function testHourlyRecomputationCorrectsDrift(): void
    {
        $this->create();
        AtomicFile::write($this->layout->usageFile(), '{"schema_version":1,"bytes":999,"items":7,"recomputed_at":null}');

        $this->purger->run();
        $usage = $this->usage->read();
        self::assertSame(1, $usage['items']);
        self::assertLessThan(999, $usage['bytes']);
    }

    /**
     * Deletions by other processes while the recomputation scans must not be subtracted twice:
     * the counter would fall below the real usage and let quotas be exceeded (§9.7).
     */
    #[Group('EXG-STORE-043')]
    #[Group('EXG-STORE-006')]
    public function testRecomputationIgnoresChangesMadeDuringTheScan(): void
    {
        $ids = [];
        for ($i = 0; $i < 6; ++$i) {
            $ids[] = $this->create('1d')[1];
        }
        $store = $this->store;
        $layout = $this->layout;
        $frozen = $this->clock;
        // A user deletes every paste not locked by the purge while it scans the first one.
        $clock = new class ($frozen, static function () use ($store, $layout, $ids): void {
            foreach ($ids as $id) {
                try {
                    // Throws while the purge holds this paste's lock (the one being scanned).
                    FileLock::acquire($layout->pasteDir($id) . '/state.lock', true, false)?->release();
                } catch (StorageException) {
                    continue;
                }
                $store->remove($id);
            }
        }) implements \QuietLink\Clock\Clock {
            private bool $fired = false;

            public function __construct(private readonly FrozenClock $inner, private readonly \Closure $hook)
            {
            }

            public function now(): int
            {
                if (!$this->fired && debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] === 'decide') {
                    $this->fired = true;
                    ($this->hook)();
                }

                return $this->inner->now();
            }
        };
        $stateFiles = new StateFiles($this->layout);
        $limiter = new RateLimiter($this->config->storage->ratelimitDir, $this->config->http->rateLimits, $this->config->secret, $clock);
        $disk = new class () extends DiskProbe {
            public function freeInodesPercent(string $path): int
            {
                return 80;
            }
        };
        $purger = new Purger($this->config, $this->layout, $this->store, new IdempotencyStore($this->layout, $clock), $this->usage, $stateFiles, $limiter, $this->service, $disk, $clock);

        self::assertNotNull($purger->run());
        $onDisk = iterator_count($this->store->ids());
        self::assertLessThan(6, $onDisk);
        self::assertSame($onDisk, $this->usage->read()['items']);
    }

    #[Group('EXG-STORE-037')]
    #[Group('EXG-TEST-051')]
    public function testConcurrentPurgeExitsImmediately(): void
    {
        $lock = FileLock::acquire(Purger::lockPath($this->layout), true);
        self::assertNotNull($lock);
        try {
            self::assertNull($this->purger->run());
        } finally {
            $lock->release();
        }
    }

    #[Group('EXG-STORE-044')]
    public function testHealthFileIsRefreshed(): void
    {
        $this->clock->advance(3600);
        $this->purger->run();

        self::assertTrue((new StateFiles($this->layout))->healthAllowsCreation($this->clock->now(), 10));
    }

    #[Group('EXG-OBS-001')]
    public function testQuotaAboveEightyPercentRaisesAnOperationalAlert(): void
    {
        $this->create();
        AtomicFile::write($this->layout->usageFile(), sprintf('{"schema_version":1,"bytes":0,"items":%d,"recomputed_at":%d}', 80001, $this->clock->now()));

        $this->purger->run();

        $alerts = array_filter($this->logger->records, static fn (array $r): bool => $r[0] === 'warning' && $r[1] === 'Storage quota above 80%');
        self::assertCount(1, $alerts);
        $alert = array_values($alerts)[0];
        self::assertSame(80, $alert[2]['percent']);
    }

    public function testNoAlertBelowEightyPercent(): void
    {
        $this->create();
        $this->purger->run();

        self::assertSame([], array_filter($this->logger->records, static fn (array $r): bool => $r[0] === 'warning'));
    }
}
