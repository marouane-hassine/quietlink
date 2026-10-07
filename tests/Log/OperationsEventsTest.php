<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use QuietLink\Clock\SystemClock;
use QuietLink\Controller\HealthController;
use QuietLink\Log\OperationsLog;
use QuietLink\RateLimit\RateLimiter;
use QuietLink\Runtime\RuntimeStatus;
use QuietLink\Storage\StateFiles;
use QuietLink\Tests\Support\TempDirectory;
use QuietLink\Tests\Support\TestInstance;
use Symfony\Component\HttpFoundation\Request;

/**
 * Operations events (§7.5, CDC l.945): logged so that a stale health measurement or a boot
 * marker mismatch is visible, at most once a minute per worker, and only through allowlisted
 * fields (no identifier, path or address).
 */
#[CoversClass(OperationsLog::class)]
#[CoversClass(HealthController::class)]
final class OperationsEventsTest extends TestCase
{
    protected function setUp(): void
    {
        OperationsLog::reset();
    }

    #[Group('EXG-OPS-005')]
    public function testEachEventIsLoggedAtMostOnceAMinute(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{string, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [is_string($level) ? $level : 'unknown', (string) $message, $context];
            }
        };
        $log = new OperationsLog($logger);

        $log->warnOnce('health_stale', 'Disk health measurement is stale: is the purge running?', 1000);
        $log->warnOnce('health_stale', 'Disk health measurement is stale: is the purge running?', 1030);
        $log->warnOnce('boot_marker_mismatch', 'Configuration differs from the boot marker: run app:boot.', 1030);
        $log->warnOnce('health_stale', 'Disk health measurement is stale: is the purge running?', 1061);

        self::assertSame([
            ['warning', 'Disk health measurement is stale: is the purge running?', ['event' => 'health_stale']],
            ['warning', 'Configuration differs from the boot marker: run app:boot.', ['event' => 'boot_marker_mismatch']],
            ['warning', 'Disk health measurement is stale: is the purge running?', ['event' => 'health_stale']],
        ], $logger->records);
    }

    #[Group('EXG-OPS-005')]
    public function testHealthEndpointLogsAStaleMeasurementWithoutRequestDetails(): void
    {
        $tmp = new TempDirectory();
        try {
            $config = TestInstance::config($tmp);
            $layout = RuntimeStatus::layout($config);
            $layout->ensureDirectories();
            $clock = new SystemClock();
            $limiter = new RateLimiter($config->storage->ratelimitDir, $config->http->rateLimits, $config->secret, $clock);
            $limiter->ensureLockFiles();
            $logger = new class () extends AbstractLogger {
                /** @var list<array{string, string, array<array-key, mixed>}> */
                public array $records = [];

                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    $this->records[] = [is_string($level) ? $level : 'unknown', (string) $message, $context];
                }
            };
            $files = new StateFiles($layout);
            $controller = new HealthController($files, $config, $limiter, $clock, new OperationsLog($logger));

            // No health.json at all: degraded and logged.
            self::assertSame(503, $controller(Request::create('/healthz', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']))->getStatusCode());
            self::assertSame([['warning', 'Disk health measurement is missing or stale: is the purge running?', ['event' => 'health_stale']]], $logger->records);

            OperationsLog::reset();
            $logger->records = [];
            $files->writeHealth($clock->now(), 1 << 40, 90);
            self::assertSame(200, $controller(Request::create('/healthz', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']))->getStatusCode());
            self::assertSame([], $logger->records);

            // An hourly purge (shared hosting) stays healthy under the default 2-hour threshold,
            // while an instance configured for a per-minute purge reports it stale (EXG-STORE-008).
            $files->writeHealth($clock->now() - 3600, 1 << 40, 90);
            self::assertSame(200, $controller(Request::create('/healthz', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']))->getStatusCode());
            self::assertSame([], $logger->records);
            $strict = TestInstance::config($tmp, ['storage' => ['health_max_age' => '10m']]);
            $strictController = new HealthController($files, $strict, $limiter, $clock, new OperationsLog($logger));
            self::assertSame(503, $strictController(Request::create('/healthz', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']))->getStatusCode());
            self::assertSame([['warning', 'Disk health measurement is missing or stale: is the purge running?', ['event' => 'health_stale']]], $logger->records);
        } finally {
            $tmp->remove();
        }
    }
}
