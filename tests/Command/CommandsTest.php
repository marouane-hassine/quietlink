<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Command;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use QuietLink\Cli\ApiClient;
use QuietLink\Cli\CliContext;
use QuietLink\Cli\DecryptCommand;
use QuietLink\Cli\StreamTransport;
use QuietLink\Kernel;
use QuietLink\Tests\Support\FakePrompt;
use QuietLink\Tests\Support\KernelTestCase;
use QuietLink\Tests\Support\TestInstance;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversNothing]
final class CommandsTest extends KernelTestCase
{
    private function command(string $name): CommandTester
    {
        $application = new Application(new Kernel('test', true));

        return new CommandTester($application->find($name));
    }

    #[Group('EXG-CONF-029')]
    public function testSecretGenerationProducesThirtyTwoRandomBytes(): void
    {
        $this->bootInstance();
        $first = $this->command('app:secret:generate');
        $first->execute([]);
        $second = $this->command('app:secret:generate');
        $second->execute([]);
        $secret = base64_decode(trim($first->getDisplay()), true);

        self::assertIsString($secret);
        self::assertSame(32, strlen($secret));
        self::assertNotSame(trim($first->getDisplay()), trim($second->getDisplay()));
    }

    #[Group('EXG-CONF-023')]
    public function testConfigCheckNeverPrintsTheSecret(): void
    {
        $this->bootInstance();
        $tester = $this->command('app:config:check');

        self::assertSame(0, $tester->execute([]));
        self::assertStringNotContainsString(TestInstance::SECRET_BASE64, $tester->getDisplay());
        self::assertStringContainsString('secret: present (not shown)', $tester->getDisplay());
        self::assertStringContainsString('boot marker: matches', $tester->getDisplay());
    }

    #[Group('EXG-LIFE-024')]
    #[Group('EXG-TEST-051')]
    public function testPurgeRefusesToRunWithoutAValidBootMarker(): void
    {
        $this->bootInstance([], false);
        $tester = $this->command('app:purge-expired');

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('run app:boot first', $tester->getDisplay());
    }

    #[Group('EXG-CLI-006')]
    #[Group('EXG-CLI-010')]
    #[Group('EXG-TEST-066')]
    public function testCliOffersNoWayToSkipConsumptionOrPassAPassphraseInline(): void
    {
        $stdin = fopen('php://memory', 'r');
        self::assertIsResource($stdin);
        $command = new DecryptCommand(new CliContext(new ApiClient(new StreamTransport()), new FakePrompt(), $stdin));
        $names = array_keys($command->getDefinition()->getOptions());

        foreach ($names as $name) {
            self::assertDoesNotMatchRegularExpression('/no-consume|keep|peek|^passphrase$/', $name);
        }
        self::assertNotContains('passphrase', $names);
    }

    #[Group('EXG-CACHE-018')]
    public function testCachePurgeRemovesOnlyUnreferencedThemeStylesheets(): void
    {
        $this->bootInstance();
        $dir = $this->config->storage->generatedAssetsDir;
        file_put_contents($dir . '/tokens.0123456789abcdef.css', ':root{}');
        file_put_contents($dir . '/tokens.fedcba9876543210.css', ':root{}');
        file_put_contents($dir . '/tokens.json', '{"file":"tokens.fedcba9876543210.css"}');

        $tester = $this->command('app:cache:purge');
        self::assertSame(0, $tester->execute([]));
        self::assertFileDoesNotExist($dir . '/tokens.0123456789abcdef.css');
        self::assertFileExists($dir . '/tokens.fedcba9876543210.css');
    }

    #[Group('EXG-THEME-010')]
    #[Group('EXG-DOC-033')]
    public function testThemePreviewWritesStaticPagesWithoutRemoteResources(): void
    {
        $this->bootInstance();
        file_put_contents($this->tmp->path . '/config/themes/brand.json', '{"light":{"color-primary":"#123456"}}');
        TestInstance::config($this->tmp, ['theme' => ['custom_tokens_file' => 'brand.json']]);
        $out = $this->tmp->path . '/preview';

        $tester = $this->command('app:theme:preview');
        self::assertSame(0, $tester->execute(['--output' => $out]), $tester->getDisplay());
        foreach (['index.html', 'dark.html', 'tokens.css'] as $file) {
            self::assertFileExists($out . '/' . $file);
        }
        self::assertStringContainsString('#123456', (string) file_get_contents($out . '/tokens.css'));
        $html = (string) file_get_contents($out . '/dark.html');
        self::assertStringContainsString('data-theme="dark"', $html);
        self::assertDoesNotMatchRegularExpression('#(src|href)="https?://#', $html);
        self::assertStringNotContainsString('<script', $html);
    }
}
