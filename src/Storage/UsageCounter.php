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
     * Applies the gap found by a full recomputation in one critical section (purge, hourly),
     * so that changes made concurrently with the scan are kept.
     */
    public function applyRecomputation(int $deltaBytes, int $deltaItems, int $now): void
    {
        $lock = $this->lock(true);
        try {
            $usage = $this->load();
            $this->save(max(0, $usage['bytes'] + $deltaBytes), max(0, $usage['items'] + $deltaItems), $now);
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
    private function decode(): ?array
    {
        $json = AtomicFile::read($this->layout->usageFile());
        if ($json === null) {
            return null;
        }
        $data = RecordCodec::object($json, ['schema_version', 'bytes', 'items', 'recomputed_at']);
        if ($data === null || !is_int($data['bytes']) || !is_int($data['items'])
            || !($data['recomputed_at'] === null || is_int($data['recomputed_at']))) {
            throw new StorageException('usage.json is corrupted.');
        }

        return ['bytes' => $data['bytes'], 'items' => $data['items'], 'recomputed_at' => $data['recomputed_at']];
    }

    private function save(int $bytes, int $items, ?int $recomputedAt = null): void
    {
        $recomputedAt ??= $this->decode()['recomputed_at'] ?? null;
        AtomicFile::write($this->layout->usageFile(), RecordCodec::json([
            'schema_version' => RecordCodec::SCHEMA_VERSION,
            'bytes' => $bytes,
            'items' => $items,
            'recomputed_at' => $recomputedAt,
        ]));
    }
}
