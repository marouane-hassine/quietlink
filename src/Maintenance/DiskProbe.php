<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Maintenance;

/**
 * Free space, free inodes and filesystem type of a storage path (CLI only, §7.5).
 */
class DiskProbe
{
    public const SUPPORTED_FILESYSTEMS = ['ext4', 'xfs', 'btrfs'];

    public function freeBytes(string $path): int
    {
        $free = @disk_free_space($path);

        return $free === false ? 0 : (int) $free;
    }

    /**
     * Percentage of free inodes measured with `df -P -i`, or null when not reported.
     */
    public function freeInodesPercent(string $path): ?int
    {
        $process = @proc_open(['df', '-P', '-i', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            return null;
        }
        $lines = array_values(array_filter(explode("\n", trim($output)), static fn (string $line): bool => $line !== ''));
        $columns = preg_split('/\s+/', $lines[count($lines) - 1] ?? '');
        if ($columns === false || count($columns) < 5 || !ctype_digit($columns[1]) || !ctype_digit($columns[3]) || (int) $columns[1] === 0) {
            return null;
        }

        return intdiv((int) $columns[3] * 100, (int) $columns[1]);
    }

    /**
     * Filesystem type from /proc/self/mounts (Linux), or null when unknown.
     */
    public function filesystemType(string $path): ?string
    {
        $mounts = @file_get_contents('/proc/self/mounts');
        $real = realpath($path);
        if ($mounts === false || $real === false) {
            return null;
        }
        $best = null;
        $bestLength = -1;
        foreach (explode("\n", $mounts) as $line) {
            $parts = explode(' ', $line);
            if (count($parts) < 3) {
                continue;
            }
            $mountPoint = str_replace('\040', ' ', $parts[1]);
            $prefix = rtrim($mountPoint, '/') . '/';
            if (($real === $mountPoint || str_starts_with($real . '/', $prefix)) && strlen($mountPoint) > $bestLength) {
                $best = $parts[2];
                $bestLength = strlen($mountPoint);
            }
        }

        return $best;
    }
}
