<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\RateLimit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Config\AppSecret;
use QuietLink\Config\RateLimitSettings;
use QuietLink\Http\ClientAddress;
use QuietLink\RateLimit\RateLimiter;
use QuietLink\Storage\FileLock;
use QuietLink\Tests\Support\FrozenClock;
use QuietLink\Tests\Support\TempDirectory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(RateLimiter::class)]
#[CoversClass(ClientAddress::class)]
final class RateLimiterTest extends TestCase
{
    private TempDirectory $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    private function limiter(FrozenClock $clock, int $limit = 3, int $interval = 60): RateLimiter
    {
        $limiter = new RateLimiter($this->tmp->path, ['open' => new RateLimitSettings($limit, $interval), 'status' => new RateLimitSettings($limit, $interval)], new AppSecret(str_repeat("\x07", 32)), $clock);
        $limiter->ensureLockFiles();

        return $limiter;
    }

    #[Group('EXG-SEC-074')]
    #[Group('EXG-SEC-091')]
    #[Group('EXG-SEC-079')]
    public function testBucketsAndSubjectsAreCountedSeparately(): void
    {
        $limiter = $this->limiter(new FrozenClock(1790000000));
        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($limiter->consume('open', '198.51.100.1')->isAccepted());
        }
        self::assertFalse($limiter->consume('open', '198.51.100.1')->isAccepted());
        self::assertTrue($limiter->consume('status', '198.51.100.1')->isAccepted());
        self::assertTrue($limiter->consume('open', '198.51.100.2')->isAccepted());
    }

    public function testWindowResets(): void
    {
        $clock = new FrozenClock(1790000000 - (1790000000 % 60));
        $limiter = $this->limiter($clock, 1);
        self::assertTrue($limiter->consume('open', 'a')->isAccepted());
        self::assertFalse($limiter->consume('open', 'a')->isAccepted());
        $clock->advance(60);
        self::assertTrue($limiter->consume('open', 'a')->isAccepted());
    }

    #[Group('EXG-SEC-074')]
    #[Group('EXG-SEC-094')]
    #[Group('EXG-TEST-044')]
    public function testAWindowOverlappingMidnightCountsBothDays(): void
    {
        // Daily keys change at 00:00 UTC; a window started the day before keeps counting.
        // A 7-minute window does not divide a day, so one window spans midnight.
        $midnight = 1790035200;
        self::assertSame('00:00', gmdate('H:i', $midnight));
        $windowStart = intdiv($midnight, 420) * 420;
        self::assertLessThan($midnight, $windowStart);
        $clock = new FrozenClock($windowStart + 1);
        $limiter = $this->limiter($clock, 2, 420);
        self::assertTrue($limiter->consume('open', 'a')->isAccepted());
        self::assertTrue($limiter->consume('open', 'a')->isAccepted());
        $clock->advance($midnight - $windowStart);
        self::assertSame(intdiv($clock->now(), 420) * 420, $windowStart, 'still in the same window, on the next day');
        self::assertFalse($limiter->consume('open', 'a')->isAccepted());
    }

    #[Group('EXG-OBS-007')]
    #[Group('EXG-SEC-095')]
    public function testNoAddressIsWrittenAndExpiredEntriesArePurged(): void
    {
        $clock = new FrozenClock(1790000000);
        $limiter = $this->limiter($clock);
        $limiter->consume('open', '198.51.100.77');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tmp->path, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                self::assertStringNotContainsString('198.51.100.77', (string) file_get_contents($file->getPathname()));
                self::assertStringNotContainsString('198.51.100.77', $file->getFilename());
            }
        }
        $clock->advance(120);
        self::assertSame(1, $limiter->purgeExpired());
    }

    #[Group('EXG-SEC-095')]
    public function testExactly256LockFilesAreCreated(): void
    {
        $this->limiter(new FrozenClock());
        $locks = glob($this->tmp->path . '/locks/*.lock');
        self::assertIsArray($locks);
        self::assertCount(256, $locks);
    }

    #[Group('EXG-SEC-074')]
    #[Group('EXG-SEC-093')]
    #[Group('EXG-TEST-044')]
    public function testAddressesAreNormalised(): void
    {
        self::assertSame('192.0.2.1', ClientAddress::normalize('::ffff:192.0.2.1', 64));
        self::assertSame('2001:db8:1:2::/64', ClientAddress::normalize('2001:db8:1:2:aaaa:bbbb:cccc:dddd', 64));
        self::assertSame('2001:db8:1::/48', ClientAddress::normalize('2001:db8:1:2:aaaa::1', 48));
        self::assertSame('2001:db8:1:2::/63', ClientAddress::normalize('2001:db8:1:3::1', 63));
        self::assertSame('unknown', ClientAddress::normalize(null, 64));
        self::assertSame('unknown', ClientAddress::normalize('not-an-ip', 64));
    }

    /**
     * The purge takes each shard's lock, like consume(): otherwise it could unlink a counter
     * consume() had just rewritten for a new window, letting an extra request through.
     */
    #[Group('EXG-SEC-074')]
    public function testPurgeLeavesShardsLockedByAConsumer(): void
    {
        $clock = new FrozenClock(1790000000);
        $limiter = $this->limiter($clock);
        $limiter->consume('open', '198.51.100.77');
        $clock->advance(120);
        $locks = glob($this->tmp->path . '/locks/*.lock');
        self::assertNotFalse($locks);
        $held = [];
        foreach ($locks as $lock) {
            $held[] = FileLock::acquire($lock, true);
        }

        try {
            self::assertSame(0, $limiter->purgeExpired());
        } finally {
            foreach ($held as $lock) {
                $lock?->release();
            }
        }
        self::assertSame(1, $limiter->purgeExpired());
    }

    /**
     * A counter that cannot be written (disk full) lets the request through: otherwise every
     * route, reads and deletions included, answers 503 and nothing can free space (§7.5).
     */
    #[Group('EXG-STORE-006')]
    public function testUnwritableCountersFailOpen(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Permissions are not enforced for root.');
        }
        $limiter = $this->limiter(new FrozenClock(), 1);
        for ($i = 0; $i < 256; ++$i) {
            $shard = sprintf('%s/%02x', $this->tmp->path, $i);
            @mkdir($shard, 0500);
            @chmod($shard, 0500);
        }
        try {
            self::assertTrue($limiter->consume('open', 'subject')->isAccepted());
            self::assertTrue($limiter->consume('open', 'subject')->isAccepted());
        } finally {
            for ($i = 0; $i < 256; ++$i) {
                @chmod(sprintf('%s/%02x', $this->tmp->path, $i), 0700);
            }
        }
    }
}
