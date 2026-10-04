<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

use InvalidArgumentException;

/**
 * Stateless 82-byte challenge of sp-proto/v1 §10.1:
 * version(1) ‖ usage(1) ‖ id(24) ‖ issued_at(8, big-endian) ‖ nonce(16) ‖ HMAC-SHA-256(32).
 */
final class Challenge
{
    public const VERSION = 0x01;
    public const USAGE_OPEN = 0x01;
    public const USAGE_STATUS = 0x02;
    public const USAGE_CONSUME = 0x03;
    public const LENGTH = 82;
    public const LIFETIME_SECONDS = 60;

    private const BODY_LENGTH = 50;

    /**
     * Derives K_challenge from QUIETLINK_APP_SECRET (standard base64, ≥ 32 decoded bytes).
     */
    public static function keyFromAppSecret(string $appSecretBase64): string
    {
        $secret = base64_decode($appSecretBase64, true);
        if ($secret === false || strlen($secret) < 32) {
            throw new InvalidArgumentException('The application secret must be standard base64 of at least 32 bytes.');
        }

        return hash_hkdf('sha256', $secret, Protocol::KEY_BYTES, Protocol::INFO_CHALLENGE, Protocol::HKDF_SALT);
    }

    public static function issue(string $key, int $usage, string $id, int $issuedAt, ?string $nonce = null): string
    {
        if (!in_array($usage, [self::USAGE_OPEN, self::USAGE_STATUS, self::USAGE_CONSUME], true)
            || strlen($id) !== Protocol::ID_BYTES || $issuedAt < 0) {
            throw new InvalidArgumentException('Invalid challenge parameters.');
        }
        $nonce ??= random_bytes(16);
        if (strlen($nonce) !== 16) {
            throw new InvalidArgumentException('The challenge nonce must be 16 bytes.');
        }

        $body = pack('CC', self::VERSION, $usage) . $id . pack('J', $issuedAt) . $nonce;

        return $body . hash_hmac('sha256', $body, $key, true);
    }

    /**
     * Server verification of an open/status challenge: MAC, freshness, identifier and usage.
     * Freshness is issued_at <= now <= issued_at + 60 (ADR-0007, OQ-7).
     */
    public static function verify(string $key, string $challenge, int $usage, string $id, int $now): bool
    {
        if (strlen($challenge) !== self::LENGTH) {
            return false;
        }

        $body = substr($challenge, 0, self::BODY_LENGTH);
        if (!hash_equals(hash_hmac('sha256', $body, $key, true), substr($challenge, self::BODY_LENGTH))) {
            return false;
        }

        $issuedAt = self::issuedAt($challenge);

        return ord($challenge[0]) === self::VERSION
            && ord($challenge[1]) === $usage
            && hash_equals(substr($challenge, 2, Protocol::ID_BYTES), $id)
            && $issuedAt <= $now
            && $now <= $issuedAt + self::LIFETIME_SECONDS;
    }

    public static function issuedAt(string $challenge): int
    {
        $unpacked = unpack('J', substr($challenge, 26, 8));
        if ($unpacked === false || !is_int($unpacked[1])) {
            throw new InvalidArgumentException('Malformed challenge.');
        }

        return $unpacked[1];
    }
}
