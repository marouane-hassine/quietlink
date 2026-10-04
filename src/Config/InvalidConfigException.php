<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

use RuntimeException;

/**
 * Raised when the instance configuration is missing or invalid. Messages never contain secrets.
 */
final class InvalidConfigException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("Invalid instance configuration:\n- " . implode("\n- ", $errors));
    }
}
