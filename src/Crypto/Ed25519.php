<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

use InvalidArgumentException;
use SodiumException;

/**
 * Pure Ed25519 (RFC 8032) through ext-sodium.
 */
final class Ed25519
{
    /**
     * Field prime p = 2^255 - 19, little-endian, with the sign bit cleared.
     */
    private const FIELD_PRIME_LE = "\xed\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff"
        . "\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\x7f";

    public static function publicKeyFromSeed(string $seed): string
    {
        $keyPair = sodium_crypto_sign_seed_keypair(self::seed($seed));
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        sodium_memzero($keyPair);

        return $publicKey;
    }

    public static function sign(string $seed, string $message): string
    {
        $keyPair = sodium_crypto_sign_seed_keypair(self::seed($seed));
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $signature = sodium_crypto_sign_detached($message, $secretKey);
        sodium_memzero($keyPair);
        sodium_memzero($secretKey);

        return $signature;
    }

    public static function verify(string $publicKey, string $message, string $signature): bool
    {
        if (strlen($publicKey) !== Protocol::PUBLIC_KEY_BYTES || strlen($signature) !== Protocol::SIGNATURE_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (SodiumException) {
            return false;
        }
    }

    /**
     * Creation-time check of a public key: canonical encoding, on the curve, not of small order
     * (EXG-CRYPTO-028; ADR-0007 for the provisional choice of primitive).
     */
    public static function isAcceptablePublicKey(string $publicKey): bool
    {
        if (strlen($publicKey) !== Protocol::PUBLIC_KEY_BYTES || !self::isCanonicalY($publicKey)) {
            return false;
        }

        try {
            sodium_crypto_sign_ed25519_pk_to_curve25519($publicKey);
        } catch (SodiumException) {
            return false;
        }

        return true;
    }

    /**
     * True when the encoded y coordinate (sign bit cleared) is strictly below p.
     */
    private static function isCanonicalY(string $publicKey): bool
    {
        $y = substr($publicKey, 0, 31) . chr(ord($publicKey[31]) & 0x7f);
        for ($i = 31; $i >= 0; --$i) {
            $a = ord($y[$i]);
            $b = ord(self::FIELD_PRIME_LE[$i]);
            if ($a !== $b) {
                return $a < $b;
            }
        }

        return false;
    }

    /**
     * @return non-empty-string
     */
    private static function seed(string $seed): string
    {
        if ($seed === '' || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new InvalidArgumentException('An Ed25519 seed must be 32 bytes.');
        }

        return $seed;
    }
}
