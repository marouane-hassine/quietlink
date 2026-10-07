<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Http;

use PHPUnit\Framework\Attributes\Group;
use QuietLink\EventSubscriber\WebPurgeSubscriber;
use QuietLink\Runtime\RuntimeStatus;
use QuietLink\Storage\StateFiles;
use QuietLink\Tests\Support\KernelTestCase;

/**
 * Shared hosting without a CLI cron (or with an HTTP cron calling any page, /healthz included):
 * once the last purge is older than WebPurgeSubscriber::INTERVAL, a web request runs it after its
 * response has been sent. No dedicated endpoint, no token (no administration endpoint, §7).
 */
final class WebPurgeTest extends KernelTestCase
{
    #[Group('EXG-STORE-047')]
    public function testARequestRunsTheOverduePurgeAfterItsResponse(): void
    {
        $this->bootInstance();
        $files = new StateFiles(RuntimeStatus::layout($this->config));
        $files->writeHealth(time() - WebPurgeSubscriber::INTERVAL - 5, 1, 90);

        self::assertSame(200, $this->request('GET', '/healthz')->getStatusCode());

        $health = $files->health();
        self::assertNotNull($health);
        self::assertGreaterThanOrEqual(time() - 5, $health['measured_at']);
        self::assertSame(90, $health['free_inodes_percent']);
    }

    #[Group('EXG-STORE-047')]
    public function testARecentPurgeIsNotRepeated(): void
    {
        $this->bootInstance();
        $files = new StateFiles(RuntimeStatus::layout($this->config));
        $files->writeHealth(time() - 60, 1, 90);

        $this->request('GET', '/healthz');

        self::assertSame(1, $files->health()['free_bytes'] ?? null);
    }

    #[Group('EXG-STORE-047')]
    public function testTheWebPurgeCanBeDisabled(): void
    {
        $this->bootInstance(['storage' => ['web_purge' => false]]);
        $files = new StateFiles(RuntimeStatus::layout($this->config));
        $files->writeHealth(time() - 3600, 1, 90);

        $this->request('GET', '/healthz');

        self::assertSame(1, $files->health()['free_bytes'] ?? null);
    }
}
