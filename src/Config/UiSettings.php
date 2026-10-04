<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * "ui" section.
 */
final readonly class UiSettings
{
    /**
     * @param list<string> $templates
     */
    public function __construct(
        public string $darkMode,
        public array $templates,
        public bool $enableQrCode,
        public bool $allowPrint,
        public bool $allowExport,
        public bool $enableManifest,
    ) {
    }
}
