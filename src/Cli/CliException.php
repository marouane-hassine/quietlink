<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

use RuntimeException;

/**
 * User-facing CLI error; messages never contain links, keys or passphrases.
 */
final class CliException extends RuntimeException
{
}
