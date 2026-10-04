<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * Write-once idempotency record (schema idempotency.v1). Holds no secret.
 */
final readonly class IdempotencyRecord
{
    public function __construct(
        public string $keyHash,
        public string $requestSha256,
        public PasteId $pasteId,
        public ?int $expiresAt,
        public int $retainUntil,
    ) {
    }
}
