<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Paste;

use RuntimeException;

/**
 * Read-once paste reserved by another reader (409, only after a valid proof).
 */
final class ReservationConflictException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Paste temporarily reserved.');
    }
}
