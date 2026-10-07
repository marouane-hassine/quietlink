<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * "log" and "metrics" sections.
 */
final readonly class ObservabilitySettings
{
    public function __construct(
        public string $logLevel,
        public string $logRetention,
        public bool $metricsEnabled,
        /** Absolute log file path (log.file), or null for stderr. */
        public ?string $logFile = null,
    ) {
    }
}
