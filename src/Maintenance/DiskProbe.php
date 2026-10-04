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
        return self::parseInodes($output);
    }

    /**
     * Free inode percentage from `df -P -i` output, read by column name: GNU and BusyBox print
     * Inodes/IFree (or Inodes/Available), BSD and macOS print iused/ifree after block columns.
     */
    public static function parseInodes(string $output): ?int
    {
        $lines = array_values(array_filter(explode("\n", trim($output)), static fn (string $line): bool => trim($line) !== ''));
        if (count($lines) < 2) {
            return null;
        }
        $headerColumns = preg_split('/\s+/', trim($lines[0]));
        $values = preg_split('/\s+/', trim($lines[count($lines) - 1]));
        if ($headerColumns === false || $values === false) {
            return null;
        }
        $header = array_map('strtolower', $headerColumns);
        $column = static function (string $name) use ($header, $values): ?int {
            $index = array_search($name, $header, true);
            $value = $index === false ? null : ($values[$index] ?? null);

            return is_string($value) && ctype_digit($value) ? (int) $value : null;
        };
        if (in_array('ifree', $header, true) && in_array('iused', $header, true)) {
            $free = $column('ifree');
            $used = $column('iused');
            $total = $free === null || $used === null ? null : $free + $used;
        } elseif (($header[1] ?? null) === 'inodes') {
            $total = $column('inodes');
            $free = $column('ifree') ?? $column('available');
        } else {
            return null;
        }

        return $total === null || $free === null || $total === 0 ? null : intdiv($free * 100, $total);
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
