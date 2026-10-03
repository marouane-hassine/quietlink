<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Smoke;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuietLink\Kernel;

/**
 * Smoke test: the micro-kernel boots in the test environment.
 */
#[CoversClass(Kernel::class)]
final class KernelTest extends TestCase
{
    public function testKernelBootsInTestEnvironment(): void
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();

        try {
            self::assertSame('test', $kernel->getEnvironment());
            self::assertTrue($kernel->getContainer()->has('kernel'));
        } finally {
            $kernel->shutdown();
        }
    }
}
