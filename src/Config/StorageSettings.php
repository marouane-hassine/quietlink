<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * "storage" section.
 */
final readonly class StorageSettings
{
    public function __construct(
        public string $rootDir,
        public string $idempotencyDir,
        public string $ratelimitDir,
        public string $stateDir,
        public string $generatedAssetsDir,
        public int $maxTotalBytes,
        public int $maxItems,
        public int $minFreeBytes,
        public int $minFreeInodesPercent,
        public bool $allowUnsupportedFs,
    ) {
    }
}
