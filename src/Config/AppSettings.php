<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * "app" section.
 */
final readonly class AppSettings
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        public string $name,
        public string $publicUrl,
        public array $enabledLocales,
        public string $sourceUrl,
    ) {
    }
}
