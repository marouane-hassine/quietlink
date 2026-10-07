<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Command;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use QuietLink\Kernel;
use QuietLink\Tests\Support\KernelTestCase;
use QuietLink\Tests\Support\TestInstance;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Operations commands are scriptable: documented exit codes, a JSON format, a dry run that
 * writes nothing, and outputs that never contain the secret (README-admin, exit codes table).
 */
#[CoversNothing]
final class OperationsCommandsTest extends KernelTestCase
{
    /** Command tests run on whatever filesystem the host or container uses. */
    private const ANY_FS = ['storage' => ['allow_unsupported_fs' => true]];

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(new Kernel('test', true)))->find($name));
    }

    /**
     * @return array<string, mixed>
     */
    private static function report(CommandTester $tester): array
    {
        $data = json_decode($tester->getDisplay(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    #[Group('EXG-OPS-001')]
    #[Group('EXG-OPS-003')]
    public function testBootDryRunReportsAndWritesNoMarker(): void
    {
        $this->bootInstance(self::ANY_FS, false);
        $tester = $this->command('app:boot');

        self::assertSame(0, $tester->execute(['--dry-run' => true]), $tester->getDisplay());
        self::assertStringContainsString('boot: ok (dry run: nothing was written)', $tester->getDisplay());
        self::assertFileDoesNotExist($this->config->storage->stateDir . '/boot.json');

        self::assertSame(0, $tester->execute(['--dry-run' => true, '--format' => 'json']));
        $report = self::report($tester);
        self::assertSame('ok', $report['status']);
        self::assertTrue($report['dry_run']);
        self::assertSame([], $report['errors']);
        self::assertIsArray($report['warnings']);
        self::assertFileDoesNotExist($this->config->storage->stateDir . '/boot.json');
    }

    #[Group('EXG-OPS-003')]
    public function testBootWritesTheMarkerAndFailsWithExitCodeOneOnInvalidConfiguration(): void
    {
        $this->bootInstance(self::ANY_FS, false);
        $tester = $this->command('app:boot');
        self::assertSame(0, $tester->execute(['--format' => 'json']), $tester->getDisplay());
        self::assertFalse(self::report($tester)['dry_run']);
        self::assertFileExists($this->config->storage->stateDir . '/boot.json');
        self::assertStringNotContainsString(TestInstance::SECRET_BASE64, $tester->getDisplay());

        $_SERVER['QUIETLINK_APP_SECRET'] = 'not-base64!';
        $failing = $this->command('app:boot');
        self::assertSame(1, $failing->execute(['--format' => 'json']));
        $report = self::report($failing);
        self::assertSame('failed', $report['status']);
        self::assertNotSame([], $report['errors']);
        self::assertStringNotContainsString('not-base64!', $failing->getDisplay());
    }

    /**
     * JSON is written raw: console markup characters in messages (backslashes, <tags>) and
     * invalid UTF-8 never corrupt the document; text mode shows them literally.
     */
    #[Group('EXG-OPS-003')]
    public function testJsonOutputSurvivesConsoleMarkupAndInvalidUtf8(): void
    {
        $this->bootInstance(self::ANY_FS, false);
        file_put_contents($this->tmp->path . '/config/config.local.php', "<?php\nreturn ['a\\\\<b' => 1, '<fg=red>x</>' => 1, \"bad\\xff\" => 1];\n");
        $tester = $this->command('app:boot');

        self::assertSame(1, $tester->execute(['--format' => 'json']));
        $list = self::report($tester)['errors'];
        self::assertIsArray($list);
        $errors = implode("\n", array_map(strval(...), array_filter($list, is_string(...))));
        self::assertStringContainsString('a\\<b', $errors);
        self::assertStringContainsString('<fg=red>x</>', $errors);

        $text = $this->command('app:boot');
        self::assertSame(1, $text->execute([]));
        self::assertStringContainsString('<fg=red>x</>', $text->getDisplay());
    }

    #[Group('EXG-OPS-003')]
    public function testUnknownFormatIsAUsageError(): void
    {
        $this->bootInstance(self::ANY_FS, false);
        self::assertSame(2, $this->command('app:boot')->execute(['--format' => 'xml']));
        self::assertSame(2, $this->command('app:config:check')->execute(['--format' => 'xml']));
    }

    #[Group('EXG-OPS-003')]
    #[Group('EXG-CONF-023')]
    public function testConfigCheckJsonIsOneDocumentWithStatusAndNoSecret(): void
    {
        $this->bootInstance();
        $tester = $this->command('app:config:check');

        self::assertSame(0, $tester->execute(['--format' => 'json']));
        $report = self::report($tester);
        self::assertTrue($report['ready']);
        self::assertSame('matches', $report['boot_marker']);
        self::assertSame('present (not shown)', $report['secret']);
        self::assertIsString($report['fingerprint']);
        self::assertIsArray($report['config']);
        self::assertIsArray($report['health']);
        self::assertTrue($report['health']['creation_allowed']);
        self::assertIsInt($report['health']['age_seconds']);
        self::assertStringNotContainsString(TestInstance::SECRET_BASE64, $tester->getDisplay());
        self::assertStringNotContainsString(bin2hex($this->config->secret->bytes()), $tester->getDisplay());
    }

    #[Group('EXG-OPS-003')]
    public function testConfigCheckExitsWithTwoWhenTheInstanceIsNotBooted(): void
    {
        $this->bootInstance([], false);
        $text = $this->command('app:config:check');
        self::assertSame(2, $text->execute([]));
        self::assertStringContainsString('boot marker: missing or outdated, run app:boot', $text->getDisplay());

        $json = $this->command('app:config:check');
        self::assertSame(2, $json->execute(['--format' => 'json']));
        $report = self::report($json);
        self::assertFalse($report['ready']);
        self::assertSame('missing_or_outdated', $report['boot_marker']);
        self::assertNull($report['health']);
    }

    /**
     * Not ready: the reason is given (marker missing, fingerprint or secret different), with the
     * project root these commands resolve paths from, to compare with what the web server sees
     * (hosts reaching one directory through two paths: staging on shared hosting).
     */
    #[Group('EXG-OPS-003')]
    public function testConfigCheckExplainsWhyTheInstanceIsNotReady(): void
    {
        $this->bootInstance([], false);
        $missing = $this->command('app:config:check');
        self::assertSame(2, $missing->execute(['--format' => 'json']));
        $report = self::report($missing);
        self::assertSame('marker_missing', $report['not_ready_reason']);
        self::assertSame(dirname(__DIR__, 2), $report['project_root']);

        $this->bootInstance();
        TestInstance::config($this->tmp, ['app' => ['name' => 'Renamed instance']]);
        $changed = $this->command('app:config:check');
        self::assertSame(2, $changed->execute([]));
        self::assertStringContainsString('reason: fingerprint_differs', $changed->getDisplay());
        self::assertStringContainsString('project root: ' . dirname(__DIR__, 2), $changed->getDisplay());
    }

    #[Group('EXG-OPS-003')]
    public function testConfigCheckJsonReportsAnInvalidConfigurationWithExitCodeOne(): void
    {
        $this->bootInstance();
        $_SERVER['QUIETLINK_APP_SECRET'] = 'not-base64!';
        $tester = $this->command('app:config:check');

        self::assertSame(1, $tester->execute(['--format' => 'json']));
        $report = self::report($tester);
        self::assertSame('invalid', $report['status']);
        self::assertNotSame([], $report['errors']);
        self::assertStringNotContainsString('not-base64!', $tester->getDisplay());
    }

    #[Group('EXG-OPS-006')]
    #[Group('EXG-CONF-029')]
    public function testSecretCanBeWrittenToANewPrivateFileWithoutBeingPrinted(): void
    {
        $this->bootInstance();
        $file = $this->tmp->path . '/app_secret';
        $tester = $this->command('app:secret:generate');

        self::assertSame(0, $tester->execute(['--output' => $file]));
        $secret = base64_decode(trim((string) file_get_contents($file)), true);
        self::assertIsString($secret);
        self::assertSame(32, strlen($secret));
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertStringNotContainsString(trim((string) file_get_contents($file)), $tester->getDisplay());
        self::assertStringContainsString('secret written to the file (not shown)', $tester->getDisplay());

        unlink($file);
        self::assertSame(0, $this->command('app:secret:generate')->execute(['--output' => $file, '--group-readable' => true]));
        self::assertSame(0640, fileperms($file) & 0777);
    }

    #[Group('EXG-OPS-006')]
    public function testAnExistingSecretFileIsReplacedOnlyWithForce(): void
    {
        $this->bootInstance();
        $file = $this->tmp->path . '/app_secret';
        file_put_contents($file, "previous\n");

        $refused = $this->command('app:secret:generate');
        self::assertSame(1, $refused->execute(['--output' => $file]));
        self::assertSame("previous\n", file_get_contents($file));
        self::assertStringContainsString('--force', $refused->getDisplay());

        self::assertSame(0, $this->command('app:secret:generate')->execute(['--output' => $file, '--force' => true]));
        self::assertNotSame("previous\n", file_get_contents($file));
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertSame([], glob($this->tmp->path . '/.app_secret.*'));
    }

    #[Group('EXG-OPS-006')]
    public function testSecretOutputIntoAMissingDirectoryFailsCleanly(): void
    {
        $this->bootInstance();
        $tester = $this->command('app:secret:generate');

        self::assertSame(1, $tester->execute(['--output' => $this->tmp->path . '/missing/app_secret']));
        self::assertStringContainsString('cannot be written', $tester->getDisplay());
    }

    /**
     * The Symfony secrets vault is disabled: QuietLink reads QUIETLINK_APP_SECRET(_FILE) only,
     * and secrets:* commands would suggest another, unsupported place for it.
     */
    #[Group('EXG-OPS-007')]
    public function testSymfonySecretsVaultCommandsAreNotAvailable(): void
    {
        $this->bootInstance();
        $application = new Application(new Kernel('test', true));

        self::assertFalse($application->has('secrets:set'));
        self::assertFalse($application->has('secrets:list'));
        self::assertTrue($application->has('app:secret:generate'));
    }

    /**
     * A symbolic link at the target is refused, with or without --force: replacing it would
     * leave its real target holding the old secret, and a dangling link is not "missing".
     */
    #[Group('EXG-OPS-006')]
    public function testSecretOutputRefusesSymbolicLinks(): void
    {
        $this->bootInstance();
        $link = $this->tmp->path . '/app_secret';
        symlink($this->tmp->path . '/nowhere', $link);

        foreach ([[], ['--force' => true]] as $options) {
            $tester = $this->command('app:secret:generate');
            self::assertSame(1, $tester->execute(['--output' => $link] + $options));
            self::assertStringContainsString('symbolic link', $tester->getDisplay());
            self::assertTrue(is_link($link));
            self::assertFileDoesNotExist($this->tmp->path . '/nowhere');
        }
    }

    /** --dotenv writes the KEY=value line a .env file needs, mode 0600, without printing it. */
    #[Group('EXG-OPS-006')]
    public function testSecretCanBeWrittenAsADotEnvFile(): void
    {
        $this->bootInstance();
        $file = $this->tmp->path . '/.env';
        $tester = $this->command('app:secret:generate');

        self::assertSame(0, $tester->execute(['--output' => $file, '--dotenv' => true]));
        $content = (string) file_get_contents($file);
        self::assertMatchesRegularExpression('#^QUIETLINK_APP_SECRET=[A-Za-z0-9+/]{43}=\n$#D', $content);
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertStringNotContainsString(substr($content, 21, 20), $tester->getDisplay());
        self::assertSame(32, strlen(\QuietLink\Config\ConfigLoader::secretFromDotEnv($file)['QUIETLINK_APP_SECRET'] !== '' ? (string) base64_decode(\QuietLink\Config\ConfigLoader::secretFromDotEnv($file)['QUIETLINK_APP_SECRET'], true) : ''));
    }
}
