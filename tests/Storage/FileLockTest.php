<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Storage\FileLock;
use QuietLink\Storage\StorageException;
use QuietLink\Tests\Support\TempDirectory;

#[CoversClass(FileLock::class)]
final class FileLockTest extends TestCase
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

    /**
     * A waiter that obtains the lock on a file replaced meanwhile (paste deleted and its
     * directory reused) must not proceed: it holds a lock nobody else will ever see.
     */
    #[Group('EXG-STORE-023')]
    public function testLockOnAReplacedFileIsRefused(): void
    {
        $path = $this->tmp->path . '/state.lock';
        touch($path);
        $holder = <<<'PHP'
            $path = $argv[1];
            $handle = fopen($path, 'r+');
            flock($handle, LOCK_EX);
            echo "locked\n";
            usleep(300000);
            touch($path . '.new');
            rename($path . '.new', $path);
            flock($handle, LOCK_UN);
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $holder, $path], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame("locked\n", fgets($pipes[1]));

        $lock = FileLock::acquire($path, true);
        proc_close($process);

        self::assertNull($lock);
        $fresh = FileLock::acquire($path, true);
        self::assertInstanceOf(FileLock::class, $fresh);
        $fresh->release();
    }

    #[Group('EXG-STORE-023')]
    public function testMissingOrSymlinkedLockFileIsRefused(): void
    {
        self::assertNull(FileLock::acquire($this->tmp->path . '/missing.lock', true));
        touch($this->tmp->path . '/target');
        symlink($this->tmp->path . '/target', $this->tmp->path . '/link.lock');
        self::assertNull(FileLock::acquire($this->tmp->path . '/link.lock', true));
    }

    public function testBusyLockTimesOutWithoutWaitingWhenAsked(): void
    {
        $path = $this->tmp->path . '/state.lock';
        touch($path);
        $held = FileLock::acquire($path, true);
        self::assertNotNull($held);

        try {
            $this->expectException(StorageException::class);
            FileLock::acquire($path, true, false);
        } finally {
            $held->release();
        }
    }
}
