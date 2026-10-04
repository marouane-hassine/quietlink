<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * "http" section.
 */
final readonly class HttpSettings
{
    /**
     * @param list<string>                     $trustedProxies
     * @param array<string, RateLimitSettings> $rateLimits
     * @param list<string>                     $corsAllowedOrigins
     */
    public function __construct(
        public int $maxRequestBytes,
        public int $ratelimitIpv6Prefix,
        public array $trustedProxies,
        public array $rateLimits,
        public array $corsAllowedOrigins,
        public int $hstsMaxAge,
    ) {
    }
}
