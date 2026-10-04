<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * flock() on an existing lock file opened without creation, with a bounded wait
 * (2 s, ADR-0007 for storage OQ-06) and inode identity check (§5.2).
 */
final class FileLock
{
    public const WAIT_MICROSECONDS = 2_000_000;
    private const STEP_MICROSECONDS = 5_000;

    /**
     * @param resource $handle
     */
    private function __construct(private $handle)
    {
    }

    /**
     * @return self|null null when the lock file does not exist (or was replaced)
     *
     * @throws StorageException on timeout
     */
    public static function acquire(string $path, bool $exclusive, bool $wait = true): ?self
    {
        if (is_link($path)) {
            return null;
        }
        $handle = @fopen($path, 'r+');
        if ($handle === false) {
            return null;
        }

        $operation = ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB;
        $waited = 0;
        while (!flock($handle, $operation)) {
            if (!$wait || $waited >= self::WAIT_MICROSECONDS) {
                fclose($handle);
                throw new StorageException('Lock wait timeout.');
            }
            usleep(self::STEP_MICROSECONDS);
            $waited += self::STEP_MICROSECONDS;
        }

        clearstatcache(true, $path);
        $held = fstat($handle);
        $current = @stat($path);
        if ($held === false || $current === false || $held['ino'] !== $current['ino'] || $held['dev'] !== $current['dev']) {
            flock($handle, LOCK_UN);
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
