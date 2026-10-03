<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * Active read-once reservation. Only the SHA-256 of the client reservation id is kept.
 */
final readonly class Reservation
{
    public function __construct(
        public string $idHash,
        public string $consumeChallenge,
        public int $expiresAt,
    ) {
    }
}
