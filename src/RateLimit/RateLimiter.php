<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\RateLimit;

use DateTimeImmutable;
use QuietLink\Clock\Clock;
use QuietLink\Config\AppSecret;
use QuietLink\Config\RateLimitSettings;
use QuietLink\Storage\AtomicFile;
use QuietLink\Storage\FileLock;
use QuietLink\Storage\RecordCodec;
use QuietLink\Storage\StorageException;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * Fixed-window rate limiting stored in files (§9.2).
 *
 * Keys are HMAC-SHA-256 of the bucket and normalised client address under a daily key
 * HKDF(secret, info = "sp-proto/v1/server/ratelimit/<YYYY-MM-DD>") (UTC); no address is
 * written to disk. Windows overlapping midnight are evaluated on both days' counters.
 * Updates are serialised by flock() on one of 256 lock files created by app:boot.
 */
final class RateLimiter
{
    /**
     * @param array<string, RateLimitSettings> $buckets
     */
    public function __construct(
        private readonly string $directory,
        private readonly array $buckets,
        private readonly AppSecret $secret,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Creates the 256 lock files (app:boot).
     */
    public function ensureLockFiles(): void
    {
        $locks = $this->directory . '/locks';
        if (!is_dir($locks) && !@mkdir($locks, 0700, true) && !is_dir($locks)) {
            throw new StorageException('Unable to create the rate limiting lock directory.');
        }
        for ($i = 0; $i < 256; ++$i) {
            $path = sprintf('%s/%02x.lock', $locks, $i);
            if (!is_file($path)) {
                AtomicFile::createExclusive($path, '', false);
            }
        }
    }

    /**
     * Counts one hit; returns the resulting limit state (accepted or not).
     */
    public function consume(string $bucket, string $subject): RateLimit
    {
        $settings = $this->buckets[$bucket] ?? throw new \InvalidArgumentException('Unknown rate limiting bucket.');
        $now = $this->clock->now();
        $windowStart = intdiv($now, $settings->intervalSeconds) * $settings->intervalSeconds;
        $windowEnd = $windowStart + $settings->intervalSeconds;
        $today = gmdate('Y-m-d', $now);
        $startDay = gmdate('Y-m-d', $windowStart);

        $key = $this->key($today, $bucket, $subject);
        $lock = FileLock::acquire(sprintf('%s/locks/%02x.lock', $this->directory, ord($key[0])), true)
            ?? throw new StorageException('Rate limiting locks are missing; run app:boot.');
        try {
            $count = $this->count($key, $windowStart);
            if ($startDay !== $today) {
                $count += $this->count($this->key($startDay, $bucket, $subject), $windowStart);
            }
            $retry = new DateTimeImmutable('@' . $windowEnd);
            if ($count >= $settings->limit) {
                return new RateLimit(0, $retry, false, $settings->limit);
            }
            try {
                $this->store($key, $windowStart, $windowEnd, $this->count($key, $windowStart) + 1);
            } catch (StorageException) {
                // Disk full or unwritable: fail open rather than answer 503 to every route,
                // reads and deletions included (they are what frees space, §7.5).
            }

            return new RateLimit($settings->limit - $count - 1, $retry, true, $settings->limit);
        } finally {
            $lock->release();
        }
    }

    /**
     * Removes expired entries (purge); returns the number of files removed.
     */
    public function purgeExpired(): int
    {
        $removed = 0;
        $now = $this->clock->now();
        $shards = @scandir($this->directory);
        foreach ($shards === false ? [] : $shards as $shard) {
            $dir = $this->directory . '/' . $shard;
            if (preg_match('/^[0-9a-f]{2}$/D', $shard) !== 1 || is_link($dir) || !is_dir($dir)) {
                continue;
            }
            // Same shard lock as consume(), so a counter rewritten for a new window is never
            // unlinked; a shard busy with requests is left to the next purge.
            try {
                $lock = FileLock::acquire(sprintf('%s/locks/%s.lock', $this->directory, $shard), true, false);
            } catch (StorageException) {
                continue;
            }
            if ($lock === null) {
                continue;
            }
            try {
                $names = @scandir($dir);
                foreach ($names === false ? [] : $names as $name) {
                    // Temporary file of an interrupted write (AtomicFile), older than an hour.
                    if (preg_match('/^\.[0-9a-f]{64}\.json\.tmp-[0-9a-f]{16}$/D', $name) === 1) {
                        $mtime = @filemtime($dir . '/' . $name);
                        if ($mtime !== false && $mtime < $now - 3600) {
                            $removed += @unlink($dir . '/' . $name) ? 1 : 0;
                        }
                        continue;
                    }
                    if (preg_match('/^[0-9a-f]{64}\.json$/D', $name) !== 1) {
                        continue;
                    }
                    $entry = $this->read($dir . '/' . $name);
                    if ($entry === null || $entry['expires_at'] <= $now) {
                        $removed += @unlink($dir . '/' . $name) ? 1 : 0;
                    }
                }
            } finally {
                $lock->release();
            }
        }

        return $removed;
    }

    private function key(string $day, string $bucket, string $subject): string
    {
        return hash_hmac('sha256', $bucket . "\x00" . $subject, $this->secret->derive('sp-proto/v1/server/ratelimit/' . $day), true);
    }

    private function path(string $key): string
    {
        $hex = bin2hex($key);

        return $this->directory . '/' . substr($hex, 0, 2) . '/' . $hex . '.json';
    }

    private function count(string $key, int $windowStart): int
    {
        $entry = $this->read($this->path($key));

        return $entry !== null && $entry['window_start'] === $windowStart ? $entry['count'] : 0;
    }

    private function store(string $key, int $windowStart, int $expiresAt, int $count): void
    {
        $path = $this->path($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new StorageException('Unable to create a rate limiting shard.');
        }
        AtomicFile::write($path, RecordCodec::json([
            'schema_version' => RecordCodec::SCHEMA_VERSION,
            'window_start' => $windowStart,
            'expires_at' => $expiresAt,
            'count' => $count,
        ]));
    }

    /**
     * @return array{window_start: int, expires_at: int, count: int}|null
     */
    private function read(string $path): ?array
    {
        $json = AtomicFile::read($path);
        $data = $json === null ? null : RecordCodec::object($json, ['schema_version', 'window_start', 'expires_at', 'count']);
        if ($data === null || !is_int($data['window_start']) || !is_int($data['expires_at']) || !is_int($data['count'])) {
            return null;
        }

        return ['window_start' => $data['window_start'], 'expires_at' => $data['expires_at'], 'count' => $data['count']];
    }
}
