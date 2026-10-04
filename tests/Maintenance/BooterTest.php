<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Maintenance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Clock\SystemClock;
use QuietLink\Maintenance\Booter;
use QuietLink\Maintenance\DiskProbe;
use QuietLink\Tests\Support\TempDirectory;
use QuietLink\Tests\Support\TestInstance;

#[CoversClass(Booter::class)]
final class BooterTest extends TestCase
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

    private static function probe(?string $filesystem, ?int $inodes = 90): DiskProbe
    {
        return new class ($filesystem, $inodes) extends DiskProbe {
            public function __construct(private readonly ?string $filesystem, private readonly ?int $inodes)
            {
            }

            public function freeBytes(string $path): int
            {
                return 1 << 40;
            }

            public function freeInodesPercent(string $path): ?int
            {
                return $this->inodes;
            }

            public function filesystemType(string $path): ?string
            {
                return $this->filesystem;
            }
        };
    }

    #[Group('EXG-CONF-007')]
    #[Group('EXG-CONF-008')]
    #[Group('EXG-CONF-016')]
    #[Group('EXG-STORE-042')]
    #[Group('EXG-CRYPTO-078')]
    #[Group('EXG-CRYPTO-076')]
    #[Group('EXG-CRYPTO-077')]
    public function testBootPreparesStorageLocksAndAnIrreversibleMarker(): void
    {
        $config = TestInstance::config($this->tmp);
        $booter = new Booter($this->tmp->path . '/public', self::probe('ext4'), new SystemClock());

        self::assertSame([], $booter->boot($config, null));
        self::assertSame([], Booter::checkRuntime());
        $locks = glob($config->storage->ratelimitDir . '/locks/*.lock');
        self::assertIsArray($locks);
        self::assertCount(256, $locks);
        self::assertFileExists($config->storage->stateDir . '/usage.lock');
        self::assertFileExists($config->storage->stateDir . '/purge.lock');
        self::assertSame(0700, fileperms($config->storage->rootDir) & 0777);
        $marker = (string) file_get_contents($config->storage->stateDir . '/boot.json');
        self::assertStringNotContainsString(TestInstance::SECRET_BASE64, $marker);
        self::assertStringNotContainsString(bin2hex($config->secret->bytes()), $marker);
        self::assertStringContainsString($config->secret->check(), $marker);
    }

    #[Group('EXG-STORE-029')]
    #[Group('EXG-STORE-032')]
    public function testUnsupportedFilesystemsAreRefusedUnlessExplicitlyAllowed(): void
    {
        $config = TestInstance::config($this->tmp);
        $errors = (new Booter($this->tmp->path . '/public', self::probe('nfs4'), new SystemClock()))->boot($config, null);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('unsupported filesystem (nfs4)', $errors[0]);

        $allowed = TestInstance::config($this->tmp, ['storage' => ['allow_unsupported_fs' => true]]);
        $booter = new Booter($this->tmp->path . '/public', self::probe('nfs4'), new SystemClock());
        self::assertSame([], $booter->boot($allowed, null));
        self::assertStringContainsString('allowed by storage.allow_unsupported_fs', implode("\n", $booter->warnings()));
    }

    #[Group('EXG-STORE-041')]
    public function testStorageInsideTheWebRootIsRefused(): void
    {
        mkdir($this->tmp->path . '/public', 0700);
        $config = TestInstance::config($this->tmp, ['storage' => ['root_dir' => $this->tmp->path . '/public/pastes']]);
        $errors = (new Booter($this->tmp->path . '/public', self::probe('ext4'), new SystemClock()))->boot($config, null);

        self::assertStringContainsString('outside the web root', implode("\n", $errors));
    }

    #[Group('EXG-CONF-016')]
    public function testAPoolThatDoesNotPassTheSecretIsRefused(): void
    {
        $config = TestInstance::config($this->tmp);
        $pool = $this->tmp->path . '/pool.conf';
        file_put_contents($pool, "[quietlink]\nclear_env = yes\n");
        $booter = new Booter($this->tmp->path . '/public', self::probe('ext4'), new SystemClock());
        self::assertNotEmpty($booter->boot($config, $pool));

        file_put_contents($pool, "[quietlink]\nenv[QUIETLINK_APP_SECRET_FILE] = /run/secrets/app_secret\n");
        self::assertSame([], $booter->boot($config, $pool));
    }

    #[Group('EXG-SEC-101')]
    public function testAWorldReadableConfigurationFileIsReported(): void
    {
        $config = TestInstance::config($this->tmp);
        chmod($this->tmp->path . '/config/config.php', 0644);
        $booter = new Booter($this->tmp->path . '/public', self::probe('ext4'), new SystemClock(), [], $this->tmp->path . '/config');
        self::assertSame([], $booter->boot($config, null));
        self::assertStringContainsString('config.php is readable by every account', implode("\n", $booter->warnings()));

        chmod($this->tmp->path . '/config/config.php', 0600);
        $booter->boot($config, null);
        self::assertStringNotContainsString('readable by every account', implode("\n", $booter->warnings()));
    }

    /**
     * @return list<string> every file below the temporary instance, with its modification time
     */
    private function snapshot(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp->path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $files[] = $file->getPathname() . '@' . $file->getMTime() . ':' . $file->getSize();
        }
        sort($files);

        return $files;
    }

    #[Group('EXG-OPS-001')]
    public function testDryRunChecksEverythingButCreatesNothingOnAFreshInstance(): void
    {
        $config = TestInstance::config($this->tmp);
        $before = $this->snapshot();
        $booter = new Booter($this->tmp->path . '/public', self::probe('ext4'), new SystemClock());

        self::assertSame([], $booter->boot($config, null, true));
        self::assertSame($before, $this->snapshot());
        self::assertDirectoryDoesNotExist($config->storage->stateDir);
        self::assertStringContainsString('storage.state_dir does not exist yet: app:boot will create it.', implode("\n", $booter->warnings()));
    }

    #[Group('EXG-OPS-001')]
    public function testDryRunAfterABootRewritesNeitherTheMarkerNorTheHealthFile(): void
    {
        $config = TestInstance::config($this->tmp);
        $booter = new Booter($this->tmp->path . '/public', self::probe('ext4'), new SystemClock());
        self::assertSame([], $booter->boot($config, null));
        $before = $this->snapshot();
        sleep(1);

        self::assertSame([], $booter->boot($config, null, true));
        self::assertSame($before, $this->snapshot());
    }

    #[Group('EXG-OPS-001')]
    public function testDryRunStillReportsBlockingErrors(): void
    {
        $config = TestInstance::config($this->tmp);
        $errors = (new Booter($this->tmp->path . '/public', self::probe('nfs4'), new SystemClock()))->boot($config, null, true);

        self::assertStringContainsString('unsupported filesystem (nfs4)', implode("\n", $errors));
    }

    #[Group('EXG-OPS-002')]
    public function testStorageDirectoriesOpenedToOtherAccountsAreRefused(): void
    {
        $config = TestInstance::config($this->tmp);
        $booter = new Booter($this->tmp->path . '/public', self::probe('ext4'), new SystemClock());
        self::assertSame([], $booter->boot($config, null));
        self::assertStringNotContainsString('accessible to other accounts', implode("\n", $booter->warnings()));

        chmod($config->storage->rootDir, 0755);
        self::assertSame(['storage.root_dir is accessible to other accounts (mode 0755): restore mode 0700 (chmod 700).'], $booter->boot($config, null));
        self::assertNotEmpty($booter->boot($config, null, true));
        chmod($config->storage->rootDir, 0700);
        self::assertSame([], $booter->boot($config, null));
    }

    #[Group('EXG-OPS-002')]
    public function testAWorldReadableSecretFileIsReportedWithoutItsContent(): void
    {
        $config = TestInstance::config($this->tmp);
        $file = $this->tmp->path . '/app_secret';
        file_put_contents($file, TestInstance::SECRET_BASE64);
        chmod($file, 0644);
        $booter = new Booter($this->tmp->path . '/public', self::probe('ext4'), new SystemClock());

        self::assertSame([], $booter->boot($config, null, false, $file));
        $warnings = implode("\n", $booter->warnings());
        self::assertStringContainsString('The secret file (QUIETLINK_APP_SECRET_FILE) is readable by every account', $warnings);
        self::assertStringNotContainsString(TestInstance::SECRET_BASE64, $warnings);

        chmod($file, 0640);
        $booter->boot($config, null, false, $file);
        self::assertStringNotContainsString('secret file', implode("\n", $booter->warnings()));
    }

    #[Group('EXG-OPS-002')]
    public function testUnmeasurableInodesAreReportedInsteadOfAssumedSufficient(): void
    {
        $config = TestInstance::config($this->tmp);
        $booter = new Booter($this->tmp->path . '/public', self::probe('ext4', null), new SystemClock());

        self::assertSame([], $booter->boot($config, null));
        self::assertStringContainsString('Free inodes could not be measured', implode("\n", $booter->warnings()));
    }

    /**
     * A PHP body limit below http.max_request_bytes makes large creations fail with a confusing
     * error: boot reports it (the web server limit is documented next to it).
     */
    #[Group('EXG-OPS-002')]
    public function testPhpBodyLimitBelowTheConfiguredRequestSizeIsReported(): void
    {
        self::assertSame(2 * 1024 * 1024, Booter::iniBytes('2M'));
        self::assertSame(512 * 1024, Booter::iniBytes('512k'));
        self::assertSame(1024 ** 3, Booter::iniBytes('1G'));
        self::assertSame(PHP_INT_MAX, Booter::iniBytes('0'));
        self::assertSame(1000, Booter::iniBytes('1000'));

        $config = TestInstance::config($this->tmp, ['http' => ['max_request_bytes' => 5 * 1024 * 1024], 'paste' => ['max_envelope_bytes' => 3 * 1024 * 1024]]);
        $booter = new Booter($this->tmp->path . '/public', self::probe('ext4'), new SystemClock(), [], '', '2M');
        self::assertSame([], $booter->boot($config, null));
        self::assertStringContainsString('post_max_size (2M) is below http.max_request_bytes', implode("\n", $booter->warnings()));
    }
}
