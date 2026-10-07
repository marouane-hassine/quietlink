<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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

    #[Group('EXG-SEC-068')]
    #[Group('EXG-CONF-031')]
    public function testProductionNeverEnablesDebug(): void
    {
        self::assertFalse(Environment::fromVariables(['APP_ENV' => 'prod', 'APP_DEBUG' => '1'])->debug);
    }

    /**
     * Shared hosting: APP_ENV and APP_DEBUG may come from the .env file at the project root when
     * the process does not set them; production still never enables debug.
     */
    #[Group('EXG-OPS-009')]
    #[Group('EXG-SEC-068')]
    public function testEnvironmentCanComeFromTheDotEnvFile(): void
    {
        $file = sys_get_temp_dir() . '/ql-env-' . bin2hex(random_bytes(4));
        file_put_contents($file, "APP_ENV=dev\nAPP_DEBUG=0\nOTHER=x\nQUIETLINK_APP_SECRET=ignored-here\n");
        try {
            $variables = Environment::withDotEnv(['PATH' => '/bin'], $file);
            self::assertSame(['PATH' => '/bin', 'APP_ENV' => 'dev', 'APP_DEBUG' => '0'], $variables);
            self::assertFalse(Environment::fromVariables($variables)->debug);
            // The process wins over the file.
            self::assertSame('prod', Environment::withDotEnv(['APP_ENV' => 'prod'], $file)['APP_ENV']);
            file_put_contents($file, "APP_ENV=prod\nAPP_DEBUG=1\n");
            self::assertFalse(Environment::fromVariables(Environment::withDotEnv([], $file))->debug);
            self::assertSame([], Environment::withDotEnv([], $file . '-missing'));
        } finally {
            @unlink($file);
        }
    }

    public function testRejectsUnknownEnvironmentName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Environment::fromVariables(['APP_ENV' => '../etc']);
    }

    /**
     * "prod\n" (a trailing newline from an env file) must not pass as a valid name that is not
     * "prod" and silently enable debug.
     */
    #[Group('EXG-SEC-058')]
    public function testEnvironmentNameWithTrailingNewlineIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Environment::fromVariables(['APP_ENV' => "prod\n"]);
    }
}
