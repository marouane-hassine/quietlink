<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

/**
 * Argon2id parameters recorded in the AAD.
 */
final readonly class KdfParameters
{
    public function __construct(
        public int $memoryKib,
        public int $passes,
        public string $salt,
    ) {
    }
}
