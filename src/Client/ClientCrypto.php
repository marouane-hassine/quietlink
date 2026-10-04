<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Client;

use InvalidArgumentException;
use QuietLink\Crypto\Aad;
use QuietLink\Crypto\AccessProof;
use QuietLink\Crypto\Argon2id;
use QuietLink\Crypto\ContentCipher;
use QuietLink\Crypto\DecryptionFailedException;
use QuietLink\Crypto\DeletionToken;
use QuietLink\Crypto\Ed25519;
use QuietLink\Crypto\KeyDerivation;
use QuietLink\Crypto\Protocol;
use QuietLink\Encoding\Base64Url;
use SensitiveParameter;

/**
 * Client side of sp-proto/v1 in PHP (CLI and tests): encryption, proofs and decryption.
 */
final class ClientCrypto
{
    public static function prepare(
        #[SensitiveParameter] string $envelopeJson,
        string $expiration,
        bool $readOnce,
        #[SensitiveParameter] ?string $passphrase = null,
        int $memoryKib = Argon2id::DEFAULT_MEMORY_KIB,
        int $passes = Argon2id::DEFAULT_PASSES,
    ): PreparedPaste {
        $urlKey = random_bytes(Protocol::KEY_BYTES);
        $kdf = null;
        $kPass = null;
        if ($passphrase !== null) {
            $salt = random_bytes(Protocol::SALT_BYTES);
            $kPass = Argon2id::derive($passphrase, $salt, $memoryKib, $passes);
            $kdf = ['alg' => Aad::KDF_ALG, 'm' => $memoryKib, 't' => $passes, 'p' => 1, 'salt' => Base64Url::encode($salt)];
        }

        $accessSeed = KeyDerivation::accessSeed($urlKey);
        $consumeSeed = $readOnce ? KeyDerivation::consumeSeed($urlKey, $kPass) : null;
        $aad = Aad::canonicalize([
            'v' => Protocol::VERSION,
            'alg' => Aad::ALG,
            'read_once' => $readOnce,
            'expiration' => $expiration,
            'kdf' => $kdf,
            'access_pk' => Base64Url::encode(Ed25519::publicKeyFromSeed($accessSeed)),
            'consume_pk' => $consumeSeed === null ? null : Base64Url::encode(Ed25519::publicKeyFromSeed($consumeSeed)),
        ]);

        $nonce = random_bytes(Protocol::NONCE_BYTES);
        $key = KeyDerivation::contentKey($urlKey, $kPass);
        $ciphertext = ContentCipher::encrypt($key, $nonce, $aad, $envelopeJson);
        sodium_memzero($key);
        if ($kPass !== null) {
            sodium_memzero($kPass);
        }
        $deletionToken = random_bytes(DeletionToken::BYTES);

        return new PreparedPaste(
            [
                'aad' => Base64Url::encode($aad),
                'nonce' => Base64Url::encode($nonce),
                'ciphertext' => Base64Url::encode($ciphertext),
                'deletion_hash' => Base64Url::encode(DeletionToken::hash($deletionToken)),
            ],
            Base64Url::encode(random_bytes(Protocol::IDEMPOTENCY_KEY_BYTES)),
            $urlKey,
            $deletionToken,
            $accessSeed,
            $consumeSeed,
        );
    }

    /**
     * Signs an opaque challenge (base64url) with an Ed25519 seed; returns the base64url signature.
     */
    public static function prove(#[SensitiveParameter] string $seed, string $challengeB64u): string
    {
        return Base64Url::encode(Ed25519::sign($seed, AccessProof::message(Base64Url::decode($challengeB64u))));
    }

    public static function accessPublicKey(#[SensitiveParameter] string $urlKey): string
    {
        return Base64Url::encode(Ed25519::publicKeyFromSeed(KeyDerivation::accessSeed($urlKey)));
    }

    /**
     * Derives K_pass when the AAD requires a passphrase.
     */
    public static function passphraseKey(Aad $aad, #[SensitiveParameter] ?string $passphrase): ?string
    {
        if ($aad->kdf === null) {
            return null;
        }
        if ($passphrase === null) {
            throw new InvalidArgumentException('This paste requires a passphrase.');
        }

        return Argon2id::derive($passphrase, $aad->kdf->salt, $aad->kdf->memoryKib, $aad->kdf->passes);
    }

    /**
     * Local passphrase check against consume_pk for read-once content (§6.3.1 step 0).
     */
    public static function consumeSeed(Aad $aad, #[SensitiveParameter] string $urlKey, #[SensitiveParameter] ?string $kPass): ?string
    {
        if ($aad->consumePk === null) {
            return null;
        }
        $seed = KeyDerivation::consumeSeed($urlKey, $kPass);
        if (!hash_equals($aad->consumePk, Ed25519::publicKeyFromSeed($seed))) {
            throw new DecryptionFailedException('Incorrect passphrase.');
        }

        return $seed;
    }

    /**
     * @throws DecryptionFailedException
     */
    public static function decrypt(#[SensitiveParameter] string $urlKey, #[SensitiveParameter] ?string $kPass, Aad $aad, string $nonce, string $ciphertext): string
    {
        $key = KeyDerivation::contentKey($urlKey, $kPass);
        try {
            return ContentCipher::decrypt($key, $nonce, $aad->bytes(), $ciphertext);
        } finally {
            sodium_memzero($key);
        }
    }
}
