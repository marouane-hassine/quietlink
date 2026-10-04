<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Cli\CliException;
use QuietLink\Cli\OutputFile;
use QuietLink\Tests\Support\TempDirectory;

/**
 * Stream that accepts only a few bytes, like a disk that fills up during the write.
 */
final class FullDiskStream
{
    /** @var array<string, string> */
    public static array $files = [];
    /** @var resource|null */
    public $context;
    private string $path = '';

    public function stream_open(string $path, string $mode): bool
    {
        if (isset(self::$files[$path])) {
            return false;
        }
        $this->path = $path;
        self::$files[$path] = '';

        return true;
    }

    public function stream_write(string $data): int
    {
        $accepted = substr($data, 0, max(0, 4 - strlen(self::$files[$this->path])));
        self::$files[$this->path] .= $accepted;

        return strlen($accepted);
    }

    public function unlink(string $path): bool
    {
        unset(self::$files[$path]);

        return true;
    }

    /** @return array<int|string, int> */
    public function url_stat(string $path, int $flags): array|false
    {
        return isset(self::$files[$path]) ? ['mode' => 0100600, 'size' => strlen(self::$files[$path])] : false;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return true;
    }
}

#[CoversClass(OutputFile::class)]
final class OutputFileTest extends TestCase
{
    protected function setUp(): void
    {
        stream_wrapper_register('fulldisk', FullDiskStream::class);
        FullDiskStream::$files = [];
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister('fulldisk');
    }

    public function testWritesANewPrivateFile(): void
    {
        $tmp = new TempDirectory();
        try {
            OutputFile::write($tmp->path . '/out.txt', 'dummy text');
            self::assertSame('dummy text', file_get_contents($tmp->path . '/out.txt'));
            self::assertSame(0600, fileperms($tmp->path . '/out.txt') & 0777);
            $this->expectException(CliException::class);
            OutputFile::write($tmp->path . '/out.txt', 'again');
        } finally {
            $tmp->remove();
        }
    }

    /**
     * A short write (full disk, quota) must fail before a read-once paste is consumed, and leave
     * no truncated file behind (§11).
     */
    #[Group('EXG-CLI-004')]
    #[Group('EXG-CLI-005')]
    public function testShortWriteFailsAndRemovesThePartialFile(): void
    {
        try {
            OutputFile::write('fulldisk://out.txt', 'dummy text longer than the space left');
            self::fail('A short write must fail.');
        } catch (CliException) {
        }

        self::assertSame([], FullDiskStream::$files);
    }
}
