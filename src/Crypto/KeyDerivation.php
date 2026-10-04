<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

use InvalidArgumentException;

/**
 * HKDF-SHA-256 derivations of sp-proto/v1 §5.3.
 */
final class KeyDerivation
{
    public static function accessSeed(string $kUrl): string
    {
        self::assertKey($kUrl, 'K_url');

        return hash_hkdf('sha256', $kUrl, Protocol::KEY_BYTES, Protocol::INFO_ACCESS, Protocol::HKDF_SALT);
    }

    public static function contentKey(string $kUrl, ?string $kPass): string
    {
        return hash_hkdf('sha256', self::contentIkm($kUrl, $kPass), Protocol::KEY_BYTES, Protocol::INFO_CONTENT, Protocol::HKDF_SALT);
    }

    public static function consumeSeed(string $kUrl, ?string $kPass): string
    {
        return hash_hkdf('sha256', self::contentIkm($kUrl, $kPass), Protocol::KEY_BYTES, Protocol::INFO_CONSUME, Protocol::HKDF_SALT);
    }

    private static function contentIkm(string $kUrl, ?string $kPass): string
    {
        self::assertKey($kUrl, 'K_url');
        if ($kPass === null) {
            return $kUrl;
        }
        self::assertKey($kPass, 'K_pass');

        return $kUrl . $kPass;
    }

    private static function assertKey(string $key, string $name): void
    {
        if (strlen($key) !== Protocol::KEY_BYTES) {
            throw new InvalidArgumentException(sprintf('%s must be %d bytes.', $name, Protocol::KEY_BYTES));
        }
    }
}
