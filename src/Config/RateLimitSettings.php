<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * One rate limiting bucket: at most $limit hits per $intervalSeconds.
 */
final readonly class RateLimitSettings
{
    public function __construct(
        public int $limit,
        public int $intervalSeconds,
    ) {
    }
}
