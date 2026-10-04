<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * Persistent quota counters in usage.json, updated under usage.lock (§9.4.1, §7.5).
 */
final class UsageCounter
{
    public function __construct(
        private readonly StorageLayout $layout,
        private readonly int $maxTotalBytes,
        private readonly int $maxItems,
    ) {
    }

    /**
     * @return array{bytes: int, items: int}
     */
    public function read(): array
    {
        $lock = $this->lock(false);
        try {
            return $this->load();
        } finally {
            $lock->release();
        }
    }

    /**
     * Checks the quotas and reserves one item of $bytes atomically.
     *
     * @throws QuotaExceededException
     */
    public function reserve(int $bytes): void
    {
        $lock = $this->lock(true);
        try {
            $usage = $this->load();
            if ($usage['bytes'] + $bytes > $this->maxTotalBytes || $usage['items'] + 1 > $this->maxItems) {
                throw new QuotaExceededException('Storage quota reached.');
            }
            $this->save($usage['bytes'] + $bytes, $usage['items'] + 1);
        } finally {
            $lock->release();
        }
    }

    /**
     * Applies decrements after the corresponding filesystem operations succeeded (never below zero).
     */
    public function release(int $bytes, int $items): void
    {
        $this->adjust(-$bytes, -$items);
    }

    public function adjust(int $bytes, int $items): void
    {
        if ($bytes === 0 && $items === 0) {
            return;
        }
        $lock = $this->lock(true);
        try {
            $usage = $this->load();
            $this->save(max(0, $usage['bytes'] + $bytes), max(0, $usage['items'] + $items));
        } finally {
            $lock->release();
        }
    }

    /**
     * Counters with their generation, which every change and every completed creation bumps.
     *
     * @return array{bytes: int, items: int, generation: int}
     */
    public function snapshot(): array
    {
        $lock = $this->lock(false);
        try {
            $data = $this->decode();

            return ['bytes' => $data['bytes'] ?? 0, 'items' => $data['items'] ?? 0, 'generation' => $data['generation'] ?? 0];
        } finally {
            $lock->release();
        }
    }

    /**
     * Records that a creation became visible on disk (after its rename), without changing the
     * totals reserved earlier: a recomputation scanning meanwhile must not be applied.
     */
    public function committed(): void
    {
        $lock = $this->lock(true);
        try {
            $usage = $this->load();
            $this->save($usage['bytes'], $usage['items']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Replaces the counters with the totals observed by a scan started at $generation, under the
     * lock, only if nothing changed since: no reservation, release or completed creation. Any
     * change means the scan may have seen an inconsistent state; the next purge retries
     * (§9.7, storage-format OQ-09).
     *
     * @return bool whether the recomputation was applied
     */
    public function applyRecomputation(int $generation, int $observedBytes, int $observedItems, int $now): bool
    {
        $lock = $this->lock(true);
        try {
            if (($this->decode()['generation'] ?? 0) !== $generation) {
                return false;
            }
            $this->save($observedBytes, $observedItems, $now);

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Records a deferred recomputation so that the next attempt happens in $retryAfter seconds
     * instead of at the next purge run ($interval is the normal recomputation interval).
     */
    public function postponeRecomputation(int $now, int $retryAfter, int $interval): void
    {
        $lock = $this->lock(true);
        try {
            $usage = $this->load();
            $this->save($usage['bytes'], $usage['items'], $now + $retryAfter - $interval);
        } finally {
            $lock->release();
        }
    }

    public function recomputedAt(): ?int
    {
        $data = $this->decode();

        return $data === null ? null : $data['recomputed_at'];
    }

    public function maxTotalBytes(): int
    {
        return $this->maxTotalBytes;
    }

    public function maxItems(): int
    {
        return $this->maxItems;
    }

    private function lock(bool $exclusive): FileLock
    {
        return FileLock::acquire($this->layout->usageLock(), $exclusive)
            ?? throw new StorageException('usage.lock is missing; run app:boot.');
    }

    /**
     * @return array{bytes: int, items: int}
     */
    private function load(): array
    {
        $data = $this->decode();

        return $data === null ? ['bytes' => 0, 'items' => 0] : ['bytes' => $data['bytes'], 'items' => $data['items']];
    }

    /**
     * @return array{bytes: int, items: int, recomputed_at: int|null}|null
     */
    /**
     * @return array{bytes: int, items: int, recomputed_at: int|null, generation: int}|null
     */
    private function decode(): ?array
    {
        $json = AtomicFile::read($this->layout->usageFile());
        if ($json === null) {
            return null;
        }
        // Files written before the generation field was added stay readable (generation 0).
        $data = RecordCodec::object($json, ['schema_version', 'bytes', 'items', 'recomputed_at', 'generation'])
            ?? RecordCodec::object($json, ['schema_version', 'bytes', 'items', 'recomputed_at']);
        $generation = $data['generation'] ?? 0;
        if ($data === null || !is_int($data['bytes']) || !is_int($data['items']) || !is_int($generation)
            || !($data['recomputed_at'] === null || is_int($data['recomputed_at']))) {
            throw new StorageException('usage.json is corrupted.');
        }

        return ['bytes' => $data['bytes'], 'items' => $data['items'], 'recomputed_at' => $data['recomputed_at'], 'generation' => $generation];
    }

    private function save(int $bytes, int $items, ?int $recomputedAt = null): void
    {
        $previous = $this->decode();
        $recomputedAt ??= $previous['recomputed_at'] ?? null;
        AtomicFile::write($this->layout->usageFile(), RecordCodec::json([
            'schema_version' => RecordCodec::SCHEMA_VERSION,
            'bytes' => $bytes,
            'items' => $items,
            'recomputed_at' => $recomputedAt,
            'generation' => ($previous['generation'] ?? 0) + 1,
        ]));
    }
}
