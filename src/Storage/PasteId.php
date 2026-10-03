<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

use InvalidArgumentException;
use QuietLink\Crypto\Protocol;
use QuietLink\Encoding\Base64Url;
use QuietLink\Encoding\InvalidEncodingException;

/**
 * Validated 24-byte public identifier; the only source of storage path segments (§7.5).
 */
final readonly class PasteId
{
    private function __construct(private string $bytes)
    {
    }

    public static function fromBytes(string $bytes): self
    {
        if (strlen($bytes) !== Protocol::ID_BYTES) {
            throw new InvalidArgumentException('An identifier is exactly 24 bytes.');
        }

        return new self($bytes);
    }

    /**
     * @throws InvalidEncodingException when not 32 canonical base64url characters
     */
    public static function fromEncoded(string $encoded): self
    {
        if (strlen($encoded) !== 32) {
            throw new InvalidEncodingException('An identifier is exactly 32 characters.');
        }

        return new self(Base64Url::decode($encoded, Protocol::ID_BYTES));
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function encoded(): string
    {
        return Base64Url::encode($this->bytes);
    }
}
