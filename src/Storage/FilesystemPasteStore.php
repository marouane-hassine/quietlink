<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

use Closure;
use Generator;
use QuietLink\Clock\Clock;
use Throwable;

/**
 * Local file storage of pastes (docs/storage-format.md).
 *
 * A paste exists only when its directory holds meta.json, state.json, state.lock and,
 * unless consumed, payload.bin. Every state transition happens under an exclusive
 * flock() on state.lock with an inode identity check and an atomic state.json rename.
 */
final class FilesystemPasteStore implements PasteStore
{
    private const MAX_ID_DRAWS = 8;

    public function __construct(
        private readonly StorageLayout $layout,
        private readonly UsageCounter $usage,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Creates a paste through a staging directory renamed atomically (§6.2).
     *
     * @param Closure(PasteId): PasteMeta $meta     builds the metadata for the drawn identifier
     * @param Closure(): PasteId          $drawId   draws a new identifier (fresh random part)
     * @param string                      $payload  nonce ‖ ciphertext
     *
     * @throws QuotaExceededException|StorageException
     */
    public function create(Closure $meta, Closure $drawId, string $payload): PasteId
    {
        $size = strlen($payload);
        $this->usage->reserve($size);

        try {
            for ($attempt = 0; $attempt < self::MAX_ID_DRAWS; ++$attempt) {
                $id = $drawId();
                $final = $this->layout->pasteDir($id);
                $shard = dirname($final);
                if (!is_dir($shard) && !@mkdir($shard, 0700, true) && !is_dir($shard)) {
                    throw new StorageException('Unable to create a shard directory.');
                }
                if (file_exists($final)) {
                    continue;
                }

                $staging = $shard . '/.' . $id->encoded() . '.tmp-' . bin2hex(random_bytes(8));
                if (!@mkdir($staging, 0700)) {
                    throw new StorageException('Unable to create a staging directory.');
                }
                try {
                    AtomicFile::createExclusive($staging . '/payload.bin', $payload, true);
                    AtomicFile::createExclusive($staging . '/meta.json', RecordCodec::encodeMeta($meta($id)), true);
                    AtomicFile::createExclusive($staging . '/state.json', RecordCodec::encodeState(PasteState::initial()), true);
                    AtomicFile::createExclusive($staging . '/state.lock', '', false);
                    AtomicFile::syncDirectory($staging);

                    if (file_exists($final) || !@rename($staging, $final)) {
                        self::removeTree($staging);
                        continue;
                    }
                } catch (Throwable $e) {
                    self::removeTree($staging);
                    throw $e;
                }
                AtomicFile::syncDirectory($shard);

                return $id;
            }
            throw new StorageException('Unable to allocate a unique identifier.');
        } catch (Throwable $e) {
            $this->usage->release($size, 1);
            throw $e;
        }
    }

    /**
     * Reads a paste under a shared lock; null when missing, deleted or unreadable (fail closed).
     */
    public function find(PasteId $id): ?PasteRecord
    {
        $lock = FileLock::acquire($this->layout->pasteDir($id) . '/state.lock', false);
        if ($lock === null) {
            return null;
        }
        try {
            return $this->readRecord($id);
        } finally {
            $lock->release();
        }
    }

    /**
     * Returns nonce ‖ ciphertext, or null when the payload is gone.
     */
    public function readPayload(PasteId $id): ?string
    {
        return AtomicFile::read($this->layout->pasteDir($id) . '/payload.bin');
    }

    /**
     * Runs $transition under the exclusive lock. It returns [new state or null, result].
     * A new consumed state removes the payload right after state.json is written (§9.4.1).
     *
     * @template T
     *
     * @param Closure(PasteRecord): array{0: PasteState|null, 1: T} $transition
     *
     * @return T|null null when the paste does not exist
     */
    public function mutate(PasteId $id, Closure $transition): mixed
    {
        $dir = $this->layout->pasteDir($id);
        $lock = FileLock::acquire($dir . '/state.lock', true);
        if ($lock === null) {
            return null;
        }
        try {
            $record = $this->readRecord($id);
            if ($record === null) {
                return null;
            }
            [$state, $result] = $transition($record);
            if ($state !== null) {
                AtomicFile::write($dir . '/state.json', RecordCodec::encodeState($state));
                if ($state->name === StateName::Consumed) {
                    $this->unlinkPayload($dir);
                }
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Two-phase deletion under the exclusive lock (§6.4). $guard may veto the removal
     * (e.g. a manual deletion of a consumed paste kept for 10 minutes).
     *
     * @param (Closure(PasteRecord): bool)|null $guard
     */
    public function remove(PasteId $id, ?Closure $guard = null): bool
    {
        $dir = $this->layout->pasteDir($id);
        $lock = FileLock::acquire($dir . '/state.lock', true);
        if ($lock === null) {
            return false;
        }
        try {
            $state = AtomicFile::read($dir . '/state.json');
            $decoded = $state === null ? null : RecordCodec::decodeState($state);
            $record = $this->readRecord($id);
            if ($record !== null && $guard !== null && !$guard($record)) {
                return false;
            }
            if ($decoded !== null && $decoded->name !== StateName::Deleted) {
                AtomicFile::write($dir . '/state.json', RecordCodec::encodeState($decoded->deleted($this->clock->now())));
            }

            return $this->removeDirectory($dir);
        } finally {
            $lock->release();
        }
    }

    /**
     * Removes a paste directory whose state files are missing (interrupted deletion, §9.4.1).
     * Callers must hold no lock on it; used by the purge.
     */
    public function removeIncomplete(PasteId $id): bool
    {
        $dir = $this->layout->pasteDir($id);
        if (is_file($dir . '/state.lock') && is_file($dir . '/state.json')) {
            return false;
        }

        return is_dir($dir) && $this->removeDirectory($dir);
    }

    /**
     * True when a deletion was interrupted after writing the `deleted` state (§9.4.1): the paste
     * is unreadable and only the purge can complete its removal.
     */
    public function isPendingDeletion(PasteId $id): bool
    {
        $state = AtomicFile::read($this->layout->pasteDir($id) . '/state.json');
        $decoded = $state === null ? null : RecordCodec::decodeState($state);

        return $decoded !== null && $decoded->name === StateName::Deleted;
    }

    public function isIncomplete(PasteId $id): bool
    {
        $dir = $this->layout->pasteDir($id);

        return is_dir($dir) && !(is_file($dir . '/state.lock') && is_file($dir . '/state.json'));
    }

    /**
     * Identifiers of every paste directory, without following symbolic links.
     *
     * @return Generator<PasteId>
     */
    public function ids(): Generator
    {
        foreach (self::entries($this->layout->rootDir, '/^[A-Za-z0-9_-]{2}$/D') as $s1) {
            foreach (self::entries($s1, '/^[A-Za-z0-9_-]{2}$/D') as $s2) {
                foreach (self::entries($s2, '/^[A-Za-z0-9_-]{32}$/D') as $dir) {
                    try {
                        $id = PasteId::fromEncoded(basename($dir));
                    } catch (Throwable) {
                        continue;
                    }
                    if (self::sameDirectory($this->layout->pasteDir($id), $dir)) {
                        yield $id;
                    }
                }
            }
        }
    }

    /**
     * True when both paths name the same directory. On a case-insensitive filesystem a paste may
     * live under a shard whose case differs from its identifier; the inode comparison keeps it listed.
     */
    private static function sameDirectory(string $expected, string $actual): bool
    {
        if ($expected === $actual) {
            return true;
        }
        clearstatcache();
        $a = @stat($expected);
        $b = @stat($actual);

        return $a !== false && $b !== false && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
    }

    /**
     * Staging directories older than $minAge seconds (orphans of interrupted creations).
     *
     * @return list<string>
     */
    public function orphanStagingDirectories(int $minAge): array
    {
        $orphans = [];
        $limit = $this->clock->now() - $minAge;
        foreach (self::entries($this->layout->rootDir, '/^[A-Za-z0-9_-]{2}$/D') as $s1) {
            foreach (self::entries($s1, '/^[A-Za-z0-9_-]{2}$/D') as $s2) {
                foreach (self::entries($s2, '/^\.[A-Za-z0-9_-]{32}\.tmp-[0-9a-f]{16}$/D') as $dir) {
                    $mtime = @filemtime($dir);
                    if ($mtime !== false && $mtime <= $limit) {
                        $orphans[] = $dir;
                    }
                }
            }
        }

        return $orphans;
    }

    /**
     * Removes an orphan staging directory if nobody holds its lock (§6.3).
     */
    public function removeOrphan(string $staging): bool
    {
        $lockFile = $staging . '/state.lock';
        $lock = null;
        if (is_file($lockFile)) {
            try {
                $lock = FileLock::acquire($lockFile, true, false);
            } catch (StorageException) {
                return false;
            }
        }
        try {
            self::removeTree($staging);

            return true;
        } finally {
            $lock?->release();
        }
    }

    private function readRecord(PasteId $id): ?PasteRecord
    {
        $dir = $this->layout->pasteDir($id);
        $metaJson = AtomicFile::read($dir . '/meta.json');
        $stateJson = AtomicFile::read($dir . '/state.json');
        if ($metaJson === null || $stateJson === null) {
            return null;
        }
        $meta = RecordCodec::decodeMeta($metaJson);
        $state = RecordCodec::decodeState($stateJson);
        if ($meta === null || $state === null || $meta->id->bytes() !== $id->bytes() || $state->name === StateName::Deleted) {
            return null;
        }
        if ($state->name !== StateName::Consumed && !is_file($dir . '/payload.bin')) {
            return null;
        }

        return new PasteRecord($meta, $state);
    }

    private function unlinkPayload(string $dir): void
    {
        $path = $dir . '/payload.bin';
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size !== false && @unlink($path)) {
            $this->usage->release($size, 0);
        }
    }

    private function removeDirectory(string $dir): bool
    {
        $this->unlinkPayload($dir);
        foreach (['meta.json', 'state.json'] as $file) {
            @unlink($dir . '/' . $file);
        }
        $temps = glob($dir . '/.state.json.tmp-*');
        foreach ($temps === false ? [] : $temps as $tmp) {
            @unlink($tmp);
        }
        @unlink($dir . '/state.lock');
        if (!@rmdir($dir)) {
            return false;
        }
        AtomicFile::syncDirectory(dirname($dir));
        $this->usage->release(0, 1);

        return true;
    }

    /**
     * @return list<string>
     */
    private static function entries(string $dir, string $pattern): array
    {
        $names = @scandir($dir);
        if ($names === false) {
            return [];
        }
        $paths = [];
        foreach ($names as $name) {
            $path = $dir . '/' . $name;
            if (preg_match($pattern, $name) === 1 && !is_link($path) && is_dir($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private static function removeTree(string $dir): void
    {
        $names = @scandir($dir);
        foreach ($names === false ? [] : $names as $name) {
            if ($name !== '.' && $name !== '..') {
                @unlink($dir . '/' . $name);
            }
        }
        @rmdir($dir);
    }
}
