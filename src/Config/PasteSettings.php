<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * "paste" section.
 */
final readonly class PasteSettings
{
    /** Largest value "paste.max_metadata_bytes" may take (§8.2.3): the absolute AAD ceiling. */
    public const MAX_METADATA_BYTES = 4096;

    /** Smallest value "paste.max_metadata_bytes" may take. */
    public const MIN_METADATA_BYTES = 512;

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
