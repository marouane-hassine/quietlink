<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * "theme" section.
 */
final readonly class ThemeSettings
{
    /**
     * @param string|null $customTokensSha256 SHA-256 of the tokens file content, part of the boot fingerprint
     */
    public function __construct(
        public string $name,
        public ?string $customTokensFile,
        public ?string $customTokensSha256 = null,
    ) {
    }
}
