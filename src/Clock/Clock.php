<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Clock;

/**
 * Source of the current Unix time in seconds.
 */
interface Clock
{
    public function now(): int;
}
