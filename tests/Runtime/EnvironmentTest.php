<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QuietLink\Runtime\Environment;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    public function testDefaultsToProductionWithoutDebug(): void
    {
        $environment = Environment::fromVariables([]);

        self::assertSame('prod', $environment->name);
        self::assertFalse($environment->debug);
    }

    public function testNonProductionEnvironmentEnablesDebugByDefault(): void
    {
        $environment = Environment::fromVariables(['APP_ENV' => 'dev']);

        self::assertSame('dev', $environment->name);
        self::assertTrue($environment->debug, 'Without debug the container cache is never refreshed.');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function debugFlags(): iterable
    {
        yield 'zero' => ['0', false];
        yield 'false' => ['false', false];
        yield 'one' => ['1', true];
        yield 'true' => ['true', true];
    }

    #[DataProvider('debugFlags')]
    public function testExplicitDebugFlagWins(string $flag, bool $expected): void
    {
        self::assertSame($expected, Environment::fromVariables(['APP_ENV' => 'test', 'APP_DEBUG' => $flag])->debug);
    }

    public function testProductionNeverEnablesDebug(): void
    {
        self::assertFalse(Environment::fromVariables(['APP_ENV' => 'prod', 'APP_DEBUG' => '1'])->debug);
    }

    public function testRejectsUnknownEnvironmentName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Environment::fromVariables(['APP_ENV' => '../etc']);
    }
}
