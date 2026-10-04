<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Support;

use QuietLink\Clock\Clock;

/**
 * Controllable clock for tests.
 */
final class FrozenClock implements Clock
{
    public function __construct(private int $now = 1790000000)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
