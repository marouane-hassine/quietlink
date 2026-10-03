<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Encoding;

use InvalidArgumentException;

/**
 * Raised when an encoded value is malformed, non-canonical or of unexpected length.
 */
final class InvalidEncodingException extends InvalidArgumentException
{
}
