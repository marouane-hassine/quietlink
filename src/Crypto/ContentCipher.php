<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

use InvalidArgumentException;
use RuntimeException;

/**
 * AES-256-GCM with the 128-bit tag appended to the ciphertext (sp-proto/v1 §7.2), via ext-openssl.
 */
final class ContentCipher
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $key, string $nonce, string $aad, string $plaintext): string
    {
        self::assertParameters($key, $nonce);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, Protocol::TAG_BYTES);
        if ($ciphertext === false || strlen($tag) !== Protocol::TAG_BYTES) {
            throw new RuntimeException('AES-256-GCM encryption failed.');
        }

        return $ciphertext . $tag;
    }

    /**
     * @throws DecryptionFailedException
     */
    public static function decrypt(string $key, string $nonce, string $aad, string $ciphertextWithTag): string
    {
        self::assertParameters($key, $nonce);
        if (strlen($ciphertextWithTag) < Protocol::TAG_BYTES) {
            throw new DecryptionFailedException('Ciphertext is too short.');
        }

        $plaintext = openssl_decrypt(
            substr($ciphertextWithTag, 0, -Protocol::TAG_BYTES),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            substr($ciphertextWithTag, -Protocol::TAG_BYTES),
            $aad,
        );
        if ($plaintext === false) {
            throw new DecryptionFailedException('Authentication failed.');
        }

        return $plaintext;
    }

    private static function assertParameters(string $key, string $nonce): void
    {
        if (strlen($key) !== Protocol::KEY_BYTES || strlen($nonce) !== Protocol::NONCE_BYTES) {
            throw new InvalidArgumentException('Invalid AES-256-GCM key or nonce length.');
        }
    }
}
