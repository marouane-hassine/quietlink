<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

use RuntimeException;

/**
 * Network failure or timeout (the request may or may not have reached the server).
 */
final class TransportException extends RuntimeException
{
}
