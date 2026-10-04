<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * Mutable paste state (state.json, schema state.v1). Transitions return new instances.
 */
final readonly class PasteState
{
    public function __construct(
        public StateName $name,
        public ?int $terminalAt,
        public int $unconfirmedOpens,
        public ?Reservation $reservation,
        public ?string $consumedSignatureHash,
    ) {
    }

    public static function initial(): self
    {
        return new self(StateName::Available, null, 0, null, null);
    }

    public function withReservation(Reservation $reservation): self
    {
        return new self(StateName::Reserved, null, $this->unconfirmedOpens, $reservation, null);
    }

    /**
     * Expired reservation released without confirmation (T5).
     */
    public function released(): self
    {
        return new self(StateName::Available, null, $this->unconfirmedOpens + 1, null, null);
    }

    /**
     * Consumed by a valid proof (T7, signature hash set) or by reaching the threshold (T6).
     */
    public function consumed(int $now, ?string $signatureHash, bool $countUnconfirmed = false): self
    {
        return new self(
            StateName::Consumed,
            $now,
            $this->unconfirmedOpens + ($countUnconfirmed ? 1 : 0),
            $this->reservation,
            $signatureHash,
        );
    }

    public function deleted(int $now): self
    {
        return new self(StateName::Deleted, $now, $this->unconfirmedOpens, $this->reservation, $this->consumedSignatureHash);
    }
}
