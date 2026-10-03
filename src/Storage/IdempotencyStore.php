<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

use QuietLink\Clock\Clock;
use QuietLink\Encoding\Base64Url;
use QuietLink\Encoding\InvalidEncodingException;

/**
 * Idempotency records published once with link() (docs/storage-format.md §8).
 */
final class IdempotencyStore
{
    public function __construct(
        private readonly StorageLayout $layout,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Returns the live record for a key hash, or null. Corrupted records fail closed as absent
     * only for reading; publication still refuses to overwrite them.
     */
    public function find(string $keyHash): ?IdempotencyRecord
    {
        $json = AtomicFile::read($this->path($keyHash));
        $record = $json === null ? null : self::decode($json);
        if ($record === null || $record->keyHash !== $keyHash || $record->retainUntil <= $this->clock->now()) {
            return null;
        }

        return $record;
    }

    /**
     * Publishes the record; false when another record already holds the key (lost race).
     *
     * @throws StorageException when the record could not be written
     */
    public function publish(IdempotencyRecord $record): bool
    {
        $path = $this->path($record->keyHash);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new StorageException('Unable to create an idempotency shard.');
        }
        $tmp = $dir . '/.' . bin2hex(random_bytes(8)) . '.tmp';
        AtomicFile::createExclusive($tmp, self::encode($record), true);
        try {
            if (@link($tmp, $path)) {
                AtomicFile::syncDirectory($dir);

                return true;
            }
            clearstatcache(true, $path);
            if (is_file($path)) {
                $existing = AtomicFile::read($path);
                $decoded = $existing === null ? null : self::decode($existing);
                if ($decoded !== null && $decoded->retainUntil <= $this->clock->now()) {
                    // An expired record not yet purged must not block a new creation.
                    @unlink($path);
                    if (@link($tmp, $path)) {
                        return true;
                    }
                }

                return false;
            }
            throw new StorageException('Unable to publish the idempotency record.');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Whether a live record designates the paste (orphan detection by the purge).
     */
    public function designates(string $keyHash, PasteId $id): bool
    {
        $record = $this->find($keyHash);

        return $record !== null && hash_equals($record->pasteId->bytes(), $id->bytes());
    }

    /**
     * Removes expired records and stale temporary files; returns the number removed.
     */
    public function purgeExpired(int $tempMinAge): int
    {
        $removed = 0;
        $now = $this->clock->now();
        $shards = @scandir($this->layout->idempotencyDir);
        foreach ($shards === false ? [] : $shards as $shard) {
            $dir = $this->layout->idempotencyDir . '/' . $shard;
            if (preg_match('/^[A-Za-z0-9_-]{2}$/D', $shard) !== 1 || is_link($dir) || !is_dir($dir)) {
                continue;
            }
            $names = @scandir($dir);
            foreach ($names === false ? [] : $names as $name) {
                $path = $dir . '/' . $name;
                if (preg_match('/^[A-Za-z0-9_-]{43}\.json$/D', $name) === 1) {
                    $json = AtomicFile::read($path);
                    $record = $json === null ? null : self::decode($json);
                    if ($record === null || $record->retainUntil <= $now) {
                        $removed += @unlink($path) ? 1 : 0;
                    }
                } elseif (preg_match('/^\.[0-9a-f]{16}\.tmp$/D', $name) === 1) {
                    $mtime = @filemtime($path);
                    if ($mtime !== false && $mtime <= $now - $tempMinAge) {
                        @unlink($path);
                    }
                }
            }
        }

        return $removed;
    }

    private function path(string $keyHash): string
    {
        $encoded = Base64Url::encode($keyHash);

        return $this->layout->idempotencyDir . '/' . substr($encoded, 0, 2) . '/' . $encoded . '.json';
    }

    private static function encode(IdempotencyRecord $record): string
    {
        return RecordCodec::json([
            'schema_version' => RecordCodec::SCHEMA_VERSION,
            'key_hash' => Base64Url::encode($record->keyHash),
            'request_sha256' => Base64Url::encode($record->requestSha256),
            'paste_id' => $record->pasteId->encoded(),
            'expires_at' => $record->expiresAt,
            'retain_until' => $record->retainUntil,
        ]);
    }

    private static function decode(string $json): ?IdempotencyRecord
    {
        $data = RecordCodec::object($json, ['schema_version', 'key_hash', 'request_sha256', 'paste_id', 'expires_at', 'retain_until']);
        if ($data === null || !is_string($data['key_hash']) || !is_string($data['request_sha256']) || !is_string($data['paste_id'])
            || !($data['expires_at'] === null || is_int($data['expires_at'])) || !is_int($data['retain_until'])) {
            return null;
        }
        try {
            return new IdempotencyRecord(
                Base64Url::decode($data['key_hash'], 32),
                Base64Url::decode($data['request_sha256'], 32),
                PasteId::fromEncoded($data['paste_id']),
                $data['expires_at'],
                $data['retain_until'],
            );
        } catch (InvalidEncodingException) {
            return null;
        }
    }
}
