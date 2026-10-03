<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * health.json and boot.json in the state directory (schemas health.v1 and boot.v1).
 */
final class StateFiles
{
    public const HEALTH_MAX_AGE = 600;

    public function __construct(private readonly StorageLayout $layout)
    {
    }

    public function writeHealth(int $measuredAt, int $freeBytes, ?int $freeInodesPercent): void
    {
        AtomicFile::write($this->layout->stateDir . '/health.json', RecordCodec::json([
            'schema_version' => RecordCodec::SCHEMA_VERSION,
            'measured_at' => $measuredAt,
            'free_bytes' => $freeBytes,
            'free_inodes_percent' => $freeInodesPercent,
        ]));
    }

    /**
     * @return array{measured_at: int, free_bytes: int, free_inodes_percent: int|null}|null
     */
    public function health(): ?array
    {
        $json = AtomicFile::read($this->layout->stateDir . '/health.json');
        $data = $json === null ? null : RecordCodec::object($json, ['schema_version', 'measured_at', 'free_bytes', 'free_inodes_percent']);
        if ($data === null || !is_int($data['measured_at']) || !is_int($data['free_bytes'])
            || !($data['free_inodes_percent'] === null || is_int($data['free_inodes_percent']))) {
            return null;
        }

        return ['measured_at' => $data['measured_at'], 'free_bytes' => $data['free_bytes'], 'free_inodes_percent' => $data['free_inodes_percent']];
    }

    /**
     * True when health.json is recent and reports enough free inodes (§7.5).
     */
    public function healthAllowsCreation(int $now, int $minFreeInodesPercent): bool
    {
        $health = $this->health();

        return $health !== null
            && $now - $health['measured_at'] <= self::HEALTH_MAX_AGE
            && $health['measured_at'] <= $now + 60
            && ($health['free_inodes_percent'] === null || $health['free_inodes_percent'] >= $minFreeInodesPercent);
    }

    public function writeBoot(int $bootedAt, string $configFingerprint, string $secretCheck): void
    {
        AtomicFile::write($this->layout->stateDir . '/boot.json', RecordCodec::json([
            'schema_version' => RecordCodec::SCHEMA_VERSION,
            'booted_at' => $bootedAt,
            'config_fingerprint' => $configFingerprint,
            'secret_check' => $secretCheck,
        ]));
    }

    /**
     * @return array{booted_at: int, config_fingerprint: string, secret_check: string}|null
     */
    public function boot(): ?array
    {
        $json = AtomicFile::read($this->layout->stateDir . '/boot.json');
        $data = $json === null ? null : RecordCodec::object($json, ['schema_version', 'booted_at', 'config_fingerprint', 'secret_check']);
        if ($data === null || !is_int($data['booted_at']) || !is_string($data['config_fingerprint']) || !is_string($data['secret_check'])) {
            return null;
        }

        return ['booted_at' => $data['booted_at'], 'config_fingerprint' => $data['config_fingerprint'], 'secret_check' => $data['secret_check']];
    }

    /**
     * Request-time check of the boot marker (§9.5): missing or different marker means 503.
     */
    public function bootMatches(string $configFingerprint, string $secretCheck): bool
    {
        $boot = $this->boot();

        return $boot !== null
            && hash_equals($boot['config_fingerprint'], $configFingerprint)
            && hash_equals($boot['secret_check'], $secretCheck);
    }
}
