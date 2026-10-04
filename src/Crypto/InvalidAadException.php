<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

use InvalidArgumentException;

/**
 * Raised when AAD bytes are not the canonical serialisation of a valid sp-proto/v1 AAD object.
 */
final class InvalidAadException extends InvalidArgumentException
{
}
