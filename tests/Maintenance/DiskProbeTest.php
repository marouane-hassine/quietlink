<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Maintenance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Maintenance\DiskProbe;

/**
 * `df -P -i` prints different columns on GNU, BusyBox and BSD/macOS: inodes are read by header
 * name, never by position, and a layout without inode columns gives null (not measured).
 */
#[CoversClass(DiskProbe::class)]
final class DiskProbeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function outputs(): iterable
    {
        yield 'gnu coreutils' => ["Filesystem      Inodes  IUsed   IFree IUse% Mounted on\n/dev/sda1     1000000 250000  750000   25% /\n", 75];
        yield 'busybox' => ["Filesystem           Inodes      Used Available Use% Mounted on\noverlay             1000000    100000    900000  10% /\n", 90];
        yield 'macos' => ["Filesystem     512-blocks      Used Available Capacity iused     ifree %iused  Mounted on\n/dev/disk3s5   1000000000 900000000 100000000    90%  50000 4950000    1%   /System/Volumes/Data\n", 99];
        yield 'zero inodes (btrfs)' => ["Filesystem      Inodes  IUsed   IFree IUse% Mounted on\n/dev/sdb1            0      0       0     - /data\n", null];
        yield 'no inode columns' => ["Filesystem     1024-blocks      Used Available Capacity Mounted on\n/dev/sda1      100 50 50 50% /\n", null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('outputs')]
    #[Group('EXG-OPS-002')]
    public function testInodesAreReadByHeaderName(string $output, ?int $expected): void
    {
        self::assertSame($expected, DiskProbe::parseInodes($output));
    }
}
