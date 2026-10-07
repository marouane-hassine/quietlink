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

    /**
     * An archive carries its commit in a BUILD file: two builds of the same version (a staging
     * archive, a rebuilt release) never share a compiled container; prod never recompiles it.
     */
    #[Group('EXG-CACHE-019')]
    public function testArchiveBuildsAreSeparatedByCommit(): void
    {
        $dir = sys_get_temp_dir() . '/ql-build-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            self::assertSame($dir . '/var/cache/prod/' . Version::APP, Kernel::cacheDirectory($dir, 'prod'));
            file_put_contents($dir . '/BUILD', "b327cc2\n");
            self::assertSame($dir . '/var/cache/prod/' . Version::APP . '-b327cc2', Kernel::cacheDirectory($dir, 'prod'));
            file_put_contents($dir . '/BUILD', "../../etc\n");
            self::assertSame($dir . '/var/cache/prod/' . Version::APP, Kernel::cacheDirectory($dir, 'prod'));
        } finally {
            @unlink($dir . '/BUILD');
            rmdir($dir);
        }
    }
}
