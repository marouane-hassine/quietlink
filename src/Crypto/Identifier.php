<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

use InvalidArgumentException;

/**
 * Public identifier id = A ‖ D ‖ R (sp-proto/v1 §5.4).
 */
final class Identifier
{
    public static function accessPrefix(string $accessPk): string
    {
        return substr(hash('sha256', Protocol::ID_ACCESS_PREFIX . $accessPk, true), 0, 8);
    }

    public static function deletionPrefix(string $deletionHash): string
    {
        return substr(hash('sha256', Protocol::ID_DELETE_PREFIX . $deletionHash, true), 0, 8);
    }

    public static function compose(string $accessPk, string $deletionHash, string $random): string
    {
        if (strlen($accessPk) !== Protocol::PUBLIC_KEY_BYTES || strlen($deletionHash) !== 32 || strlen($random) !== 8) {
            throw new InvalidArgumentException('Invalid identifier components.');
        }

        return self::accessPrefix($accessPk) . self::deletionPrefix($deletionHash) . $random;
    }

    public static function generate(string $accessPk, string $deletionHash): string
    {
        return self::compose($accessPk, $deletionHash, random_bytes(8));
    }

    public static function matchesAccessKey(string $id, string $accessPk): bool
    {
        return strlen($id) === Protocol::ID_BYTES && hash_equals(substr($id, 0, 8), self::accessPrefix($accessPk));
    }

    public static function matchesDeletionHash(string $id, string $deletionHash): bool
    {
        return strlen($id) === Protocol::ID_BYTES && hash_equals(substr($id, 8, 8), self::deletionPrefix($deletionHash));
    }
}
