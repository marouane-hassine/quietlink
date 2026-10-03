<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

use QuietLink\Crypto\Protocol;
use SensitiveParameter;

/**
 * Instance secret (decoded bytes of QUIETLINK_APP_SECRET). Never serialised or printed.
 */
final class AppSecret
{
    public const CHECK_LABEL = 'sp-proto/v1/server/secret-check';
    public const CHECK_INFO = 'sp-proto/v1/server/secret-check';

    public function __construct(#[SensitiveParameter] private readonly string $bytes)
    {
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * Non-reversible fingerprint stored in boot.json: HMAC-SHA-256 of a fixed label under a key
     * derived from the secret (§9.5), hex encoded.
     */
    public function check(): string
    {
        $key = hash_hkdf('sha256', $this->bytes, 32, self::CHECK_INFO, Protocol::HKDF_SALT);

        return hash_hmac('sha256', self::CHECK_LABEL, $key);
    }

    public function derive(string $info): string
    {
        return hash_hkdf('sha256', $this->bytes, 32, $info, Protocol::HKDF_SALT);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['bytes' => '[redacted]'];
    }

    /**
     * @return array<never>
     */
    public function __serialize(): array
    {
        throw new \LogicException('The application secret cannot be serialised.');
    }
}
