<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * Durable file writes: exclusive temp file in the target directory, fsync, atomic rename
 * (docs/storage-format.md §6.1).
 */
final class AtomicFile
{
    /**
     * Atomically replaces $path with $content.
     */
    public static function write(string $path, string $content): void
    {
        $tmp = dirname($path) . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(8));
        self::createExclusive($tmp, $content, true);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new StorageException('Atomic rename failed.');
        }
        self::syncDirectory(dirname($path));
    }

    /**
     * Creates a new file (fails if it exists), writes it fully, sets mode 0600 and syncs it.
     */
    public static function createExclusive(string $path, string $content, bool $sync): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new StorageException('Unable to create a storage file.');
        }
        try {
            @chmod($path, 0600);
            $length = strlen($content);
            $written = $length === 0 ? 0 : fwrite($handle, $content);
            if ($written !== $length || !fflush($handle) || ($sync && !fsync($handle))) {
                throw new StorageException('Short or failed write.');
            }
        } catch (StorageException $e) {
            fclose($handle);
            @unlink($path);
            throw $e;
        }
        fclose($handle);
    }

    /**
     * Best-effort directory sync (storage open question OQ-20): not portable in PHP, failures are ignored.
     */
    public static function syncDirectory(string $dir): void
    {
        $handle = @fopen($dir, 'r');
        if ($handle !== false) {
            @fsync($handle);
            fclose($handle);
        }
    }

    /**
     * Reads a whole file, or returns null when it is missing or unreadable.
     */
    public static function read(string $path): ?string
    {
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }
}
