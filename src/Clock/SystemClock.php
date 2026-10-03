<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Clock;

/**
 * Wall clock of the server (storage open question OQ-07: NTP-synchronised host assumed).
 */
final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
