<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * "paste" section.
 */
final readonly class PasteSettings
{
    /**
     * @param list<string> $allowedExpirations
     */
    public function __construct(
        public string $defaultExpiration,
        public array $allowedExpirations,
        public bool $allowForever,
        public bool $allowReadOnce,
        public bool $allowPassphrase,
        public int $maxEnvelopeBytes,
        public int $maxCiphertextBytes,
        public int $maxMetadataBytes,
        public ?int $maxRetentionSeconds,
        public int $maxUnconfirmedOpens,
        public int $readOnceReservationTtl,
        public int $idempotencyMaxTtlSeconds,
    ) {
    }

    /**
     * Expiration codes accepted in an AAD at creation.
     *
     * @return list<string>
     */
    public function acceptedExpirationCodes(): array
    {
        return $this->allowForever ? [...$this->allowedExpirations, Duration::NEVER] : $this->allowedExpirations;
    }
}
