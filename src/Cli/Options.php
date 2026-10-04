<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Typed access to console options.
 */
final class Options
{
    public static function string(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }

    public static function flag(InputInterface $input, string $name): bool
    {
        return $input->getOption($name) === true;
    }
}
