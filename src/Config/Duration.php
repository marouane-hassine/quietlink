<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * Expiration codes of the AAD (sp-proto/v1 §8.1) and "<int><m|h|d>" durations (§9.5).
 */
final class Duration
{
    public const EXPIRATION_SECONDS = [
        '5m' => 300,
        '1h' => 3600,
        '1d' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ];

    public const NEVER = 'never';

    public static function parse(string $value): ?int
    {
        if (preg_match('/^([1-9][0-9]{0,5})([mhd])$/D', $value, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * ['m' => 60, 'h' => 3600, 'd' => 86400][$match[2]];
    }

    public static function expirationSeconds(string $code): ?int
    {
        return self::EXPIRATION_SECONDS[$code] ?? null;
    }
}
