<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * Paths of the file storage (docs/storage-format.md §2).
 */
final readonly class StorageLayout
{
    public function __construct(
        public string $rootDir,
        public string $idempotencyDir,
        public string $stateDir,
    ) {
    }

    /**
     * Shard directory <root>/<c1c2>/<c3c4> of an identifier; the id is validated by PasteId.
     */
    public function shardDir(PasteId $id): string
    {
        $encoded = $id->encoded();

        return $this->rootDir . '/' . substr($encoded, 0, 2) . '/' . substr($encoded, 2, 2);
    }

    public function pasteDir(PasteId $id): string
    {
        return $this->shardDir($id) . '/' . $id->encoded();
    }

    public function usageFile(): string
    {
        return $this->stateDir . '/usage.json';
    }

    public function usageLock(): string
    {
        return $this->stateDir . '/usage.lock';
    }

    /**
     * Creates the storage directories with restrictive permissions (§9.5).
     */
    public function ensureDirectories(): void
    {
        foreach ([$this->rootDir, $this->idempotencyDir, $this->stateDir] as $dir) {
            if (is_link($dir)) {
                throw new StorageException('Storage directories must not be symbolic links.');
            }
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new StorageException('Unable to create a storage directory.');
            }
        }
        if (!is_file($this->usageLock())) {
            AtomicFile::createExclusive($this->usageLock(), '', true);
        }
    }
}
