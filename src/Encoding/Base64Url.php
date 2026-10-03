<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Encoding;

/**
 * Canonical base64url without padding (RFC 4648 §5), sp-proto/v1 §3.1.
 *
 * Decoding rejects padding, characters outside the alphabet and
 * non-canonical strings whose unused trailing bits are not zero.
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @param int|null $length exact expected number of decoded bytes, or null for any length
     *
     * @throws InvalidEncodingException
     */
    public static function decode(string $encoded, ?int $length = null): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/D', $encoded) !== 1 || strlen($encoded) % 4 === 1) {
            throw new InvalidEncodingException('Invalid base64url string.');
        }

        $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($bytes === false || self::encode($bytes) !== $encoded) {
            throw new InvalidEncodingException('Non-canonical base64url string.');
        }

        if ($length !== null && strlen($bytes) !== $length) {
            throw new InvalidEncodingException('Unexpected decoded length.');
        }

        return $bytes;
    }
}
