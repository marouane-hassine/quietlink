<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Maintenance;

use QuietLink\Config\InstanceConfig;

/**
 * Generates static assets derived from the configuration during app:boot.
 */
interface ThemeBuilder
{
    public function build(InstanceConfig $config): void;
}
