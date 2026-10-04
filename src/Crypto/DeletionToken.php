<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

use QuietLink\Encoding\Base64Url;
use QuietLink\Encoding\InvalidEncodingException;

/**
 * Deletion token bound to the identifier through D = id[8..16) (sp-proto/v1 §11).
 */
final class DeletionToken
{
    public const BYTES = 32;

    public static function hash(string $token): string
    {
        return hash('sha256', $token, true);
    }

    /**
     * Storage-free check of a base64url token against the identifier.
     */
    public static function matchesIdentifier(string $tokenB64u, string $id): bool
    {
        try {
            $token = Base64Url::decode($tokenB64u, self::BYTES);
        } catch (InvalidEncodingException) {
            return false;
        }

        return Identifier::matchesDeletionHash($id, self::hash($token));
    }
}
