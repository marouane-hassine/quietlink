<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Storage\FilesystemPasteStore;
use QuietLink\Storage\PasteId;
use QuietLink\Storage\PasteMeta;
use QuietLink\Storage\StorageLayout;
use QuietLink\Storage\UsageCounter;
use QuietLink\Tests\Support\FrozenClock;

/**
 * On a genuinely full filesystem, deleting a paste must free its space: the deletion marker
 * cannot be written before something is removed. Needs a small dedicated tmpfs, provided by
 * tools/docker/qa.sh (QUIETLINK_SMALL_TMPFS); skipped elsewhere.
 */
#[CoversClass(FilesystemPasteStore::class)]
final class FullDiskTest extends TestCase
{
    #[Group('EXG-STORE-006')]
    #[Group('EXG-STORE-043')]
    public function testDeletionFreesSpaceOnAFullFilesystem(): void
    {
        $root = getenv('QUIETLINK_SMALL_TMPFS');
        if (!is_string($root) || $root === '' || !is_dir($root)) {
            self::markTestSkipped('No small tmpfs (run through tools/docker/qa.sh).');
        }
        $base = $root . '/' . bin2hex(random_bytes(4));
        $layout = new StorageLayout($base . '/pastes', $base . '/idempotency', $base . '/state');
        $layout->ensureDirectories();
        $usage = new UsageCounter($layout, 1 << 30, 100);
        $clock = new FrozenClock();
        $store = new FilesystemPasteStore($layout, $usage, $clock);
        $id = $store->create(
            static fn (PasteId $id): PasteMeta => new PasteMeta($id, '{}', $clock->now(), $clock->now() + 3600, false, str_repeat("\x05", 32), str_repeat("\x06", 32)),
            static fn (): PasteId => PasteId::fromBytes(random_bytes(24)),
            str_repeat('x', 300_000),
        );
        // Fill the filesystem completely.
        $filler = fopen($base . '/filler', 'w');
        self::assertIsResource($filler);
        $chunk = str_repeat("\0", 65536);
        while (@fwrite($filler, $chunk) === 65536) {
        }
        while (@fwrite($filler, "\0") === 1) {
        }
        fclose($filler);

        try {
            self::assertTrue($store->remove($id));
            self::assertDirectoryDoesNotExist($layout->pasteDir($id));
            self::assertGreaterThan(250_000, (int) disk_free_space($root));
        } finally {
            @unlink($base . '/filler');
        }
    }
}
