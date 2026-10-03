<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Disposable directory for filesystem tests.
 */
final class TempDirectory
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/quietlink-test-' . bin2hex(random_bytes(8));
        mkdir($this->path, 0700, true);
    }

    public function remove(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isDir() && !$file->isLink()) {
                @chmod($file->getPathname(), 0700);
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->path);
    }
}
