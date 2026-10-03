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
use QuietLink\Storage\StorageLayout;
use QuietLink\Tests\Support\FrozenClock;
use QuietLink\Tests\Support\TempDirectory;

#[CoversClass(IdempotencyStore::class)]
final class IdempotencyRaceTest extends TestCase
{
    private const PUBLISHERS = 12;
    private const ROUNDS = 5;

    /**
     * Replacing an expired record must let exactly one concurrent publisher win;
     * otherwise a winner's record is overwritten and its paste later purged as an orphan.
     */
    #[Group('EXG-API-025')]
    #[Group('EXG-STORE-013')]
    public function testOnlyOnePublisherReplacesAnExpiredRecord(): void
    {
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $tmp = new TempDirectory();
            try {
                $layout = new StorageLayout($tmp->path . '/pastes', $tmp->path . '/idempotency', $tmp->path . '/state');
                $layout->ensureDirectories();
                $clock = new FrozenClock(1790000000);
                $store = new IdempotencyStore($layout, $clock);
                self::assertTrue($store->publish(new IdempotencyRecord(hash('sha256', 'shared-key', true), hash('sha256', 'old', true), PasteId::fromBytes(str_repeat("\xff", 24)), null, $clock->now() + 10)));

                $barrier = $tmp->path . '/go';
                $processes = [];
                for ($i = 1; $i <= self::PUBLISHERS; ++$i) {
                    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/fixtures/concurrent-publish.php', $tmp->path, $barrier, (string) $i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    self::assertIsResource($process);
                    $processes[] = [$process, $pipes];
                }
                usleep(100_000);
                touch($barrier);
                $outcomes = [];
                foreach ($processes as [$process, $pipes]) {
                    $outcomes[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
                    proc_close($process);
                }

                self::assertCount(1, array_keys($outcomes, 'won', true), implode(',', $outcomes));
                $winner = (new IdempotencyStore($layout, new FrozenClock(1790000100)))->find(hash('sha256', 'shared-key', true));
                self::assertNotNull($winner);
            } finally {
                $tmp->remove();
            }
        }
    }
}
