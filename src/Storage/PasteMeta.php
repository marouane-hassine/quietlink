<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * Immutable paste metadata (meta.json, schema meta.v1).
 */
final readonly class PasteMeta
{
    public function __construct(
        public PasteId $id,
        public string $aad,
        public int $createdAt,
        public ?int $expiresAt,
        public bool $readOnce,
        public string $deletionTokenHash,
        public string $idempotencyKeyHash,
    ) {
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }
}
