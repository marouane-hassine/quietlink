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

    /**
     * Expiry under the current paste.max_retention (§7.5): lowering it also shortens pastes
     * created before, never lengthens them.
     */
    public function effectiveExpiresAt(?int $maxRetentionSeconds): ?int
    {
        $cap = $maxRetentionSeconds === null ? null : $this->createdAt + $maxRetentionSeconds;

        return match (true) {
            $cap === null => $this->expiresAt,
            $this->expiresAt === null => $cap,
            default => min($this->expiresAt, $cap),
        };
    }

    public function isExpired(int $now, ?int $maxRetentionSeconds = null): bool
    {
        $expiresAt = $this->effectiveExpiresAt($maxRetentionSeconds);

        return $expiresAt !== null && $now >= $expiresAt;
    }
}
