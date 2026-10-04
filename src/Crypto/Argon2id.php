<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

use InvalidArgumentException;
use Normalizer;

/**
 * Passphrase key K_pass = Argon2id v1.3 (sp-proto/v1 §6), used by the CLI.
 */
final class Argon2id
{
    public const MIN_MEMORY_KIB = 19456;
    public const MAX_MEMORY_KIB = 262144;
    public const MIN_PASSES = 2;
    public const MAX_PASSES = 10;
    public const DEFAULT_MEMORY_KIB = 65536;
    public const DEFAULT_PASSES = 3;

    public static function derive(string $passphrase, string $salt, int $memoryKib, int $passes): string
    {
        if (strlen($salt) !== Protocol::SALT_BYTES) {
            throw new InvalidArgumentException('The Argon2id salt must be 16 bytes.');
        }
        if (!self::parametersAreValid($memoryKib, $passes, 1)) {
            throw new InvalidArgumentException('Argon2id parameters are out of bounds.');
        }

        $normalized = Normalizer::normalize($passphrase, Normalizer::FORM_C);
        if (!is_string($normalized) || $normalized === '') {
            throw new InvalidArgumentException('The passphrase must be a non-empty valid UTF-8 string.');
        }

        $key = sodium_crypto_pwhash(
            Protocol::KEY_BYTES,
            $normalized,
            $salt,
            $passes,
            $memoryKib * 1024,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
        sodium_memzero($normalized);

        return $key;
    }

    public static function parametersAreValid(int $memoryKib, int $passes, int $parallelism): bool
    {
        return $memoryKib >= self::MIN_MEMORY_KIB && $memoryKib <= self::MAX_MEMORY_KIB
            && $passes >= self::MIN_PASSES && $passes <= self::MAX_PASSES
            && $parallelism === 1;
    }
}
