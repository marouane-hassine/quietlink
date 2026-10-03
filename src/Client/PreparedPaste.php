<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Client;

use SensitiveParameter;

/**
 * Client-side result of encrypting a paste: the creation body and the local secrets.
 * The URL key, seeds and deletion token never leave the client.
 */
final readonly class PreparedPaste
{
    /**
     * @param array{aad: string, nonce: string, ciphertext: string, deletion_hash: string} $body
     */
    public function __construct(
        public array $body,
        public string $idempotencyKey,
        #[SensitiveParameter] public string $urlKey,
        #[SensitiveParameter] public string $deletionToken,
        #[SensitiveParameter] public string $accessSeed,
        #[SensitiveParameter] public ?string $consumeSeed,
    ) {
    }

    public function json(): string
    {
        return json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
