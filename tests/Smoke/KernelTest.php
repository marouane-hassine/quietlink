<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Smoke;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Kernel;
use QuietLink\Version;

/**
 * Smoke test: the micro-kernel boots in the test environment.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(Version::class)]
final class KernelTest extends TestCase
{
    public function testKernelBootsInTestEnvironment(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        try {
            self::assertSame('test', $kernel->getEnvironment());
            self::assertTrue($kernel->getContainer()->has('kernel'));
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * Compiled caches are separated by application version and installation (each installation
     * has its own project directory; filesystem cache pools live below the cache directory), so
     * an upgrade never reuses a container or entry of the previous release (§9.6).
     */
    #[Group('EXG-CACHE-019')]
    public function testCachesAreSeparatedByApplicationVersion(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        try {
            self::assertStringEndsWith('/var/cache/test/' . Version::APP, $kernel->getCacheDir());
            self::assertSame(dirname(__DIR__, 2), $kernel->getProjectDir());
        } finally {
            $kernel->shutdown();
        }
    }
}
