<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Storage\IdempotencyRecord;
use QuietLink\Storage\IdempotencyStore;
use QuietLink\Storage\PasteId;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\StorageLayout;
use QuietLink\Tests\Support\FrozenClock;
use QuietLink\Tests\Support\TempDirectory;

#[CoversClass(IdempotencyStore::class)]
#[CoversClass(StateFiles::class)]
final class IdempotencyAndStateFilesTest extends TestCase
{
    private TempDirectory $tmp;
    private FrozenClock $clock;
    private StorageLayout $layout;

    protected function setUp(): void
    {
        $this->tmp = new TempDirectory();
        $this->clock = new FrozenClock();
        $this->layout = new StorageLayout($this->tmp->path . '/pastes', $this->tmp->path . '/idempotency', $this->tmp->path . '/state');
        $this->layout->ensureDirectories();
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    private function record(string $requestHash = 'a', int $ttl = 3600): IdempotencyRecord
    {
        return new IdempotencyRecord(
            hash('sha256', 'dummy-key', true),
            hash('sha256', $requestHash, true),
            PasteId::fromBytes(str_repeat("\x01", 24)),
            $this->clock->now() + 7200,
            $this->clock->now() + $ttl,
        );
    }

    #[Group('EXG-STORE-013')]
    #[Group('EXG-STORE-036')]
    public function testRecordIsPublishedOnceAndReadBack(): void
    {
        $store = new IdempotencyStore($this->layout, $this->clock);

        self::assertTrue($store->publish($this->record()));
        self::assertFalse($store->publish($this->record('b')));

        $found = $store->find(hash('sha256', 'dummy-key', true));
        self::assertNotNull($found);
        self::assertSame(hash('sha256', 'a', true), $found->requestSha256);
        self::assertTrue($store->designates($found->keyHash, PasteId::fromBytes(str_repeat("\x01", 24))));
        self::assertFalse($store->designates($found->keyHash, PasteId::fromBytes(str_repeat("\x02", 24))));
    }

    public function testExpiredRecordIsIgnoredReplacedAndPurged(): void
    {
        $store = new IdempotencyStore($this->layout, $this->clock);
        $store->publish($this->record(ttl: 10));
        $this->clock->advance(10);

        self::assertNull($store->find(hash('sha256', 'dummy-key', true)));
        self::assertTrue($store->publish($this->record('b')));
        $this->clock->advance(4000);
        self::assertSame(1, $store->purgeExpired(3600));
        self::assertSame(0, $store->purgeExpired(3600));
    }

    #[Group('EXG-STORE-008')]
    public function testStaleOrMissingHealthBlocksCreation(): void
    {
        $files = new StateFiles($this->layout);
        self::assertFalse($files->healthAllowsCreation($this->clock->now(), 10));

        $files->writeHealth($this->clock->now(), 1 << 30, 50);
        self::assertTrue($files->healthAllowsCreation($this->clock->now(), 10));
        self::assertFalse($files->healthAllowsCreation($this->clock->now(), 60));
        self::assertFalse($files->healthAllowsCreation($this->clock->now() + 601, 10));
    }

    #[Group('EXG-CONF-009')]
    public function testBootMarkerMustMatch(): void
    {
        $files = new StateFiles($this->layout);
        self::assertFalse($files->bootMatches('fingerprint', 'check'));

        $files->writeBoot($this->clock->now(), 'fingerprint', 'check');
        self::assertTrue($files->bootMatches('fingerprint', 'check'));
        self::assertFalse($files->bootMatches('other', 'check'));
        self::assertFalse($files->bootMatches('fingerprint', 'other'));
    }
}
