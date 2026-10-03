<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * "theme" section.
 */
final readonly class ThemeSettings
{
    public function __construct(
        public string $name,
        public ?string $customTokensFile,
    ) {
    }
}
