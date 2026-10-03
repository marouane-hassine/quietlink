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

    private static function probe(?string $filesystem): DiskProbe
    {
        return new class ($filesystem) extends DiskProbe {
            public function __construct(private readonly ?string $filesystem)
            {
            }

            public function freeBytes(string $path): int
            {
                return 1 << 40;
            }

            public function freeInodesPercent(string $path): int
            {
                return 90;
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
}
