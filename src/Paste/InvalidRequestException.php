<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Paste;

use RuntimeException;

/**
 * Malformed or unacceptable request. Mapped to HTTP 400. Messages are generic.
 */
final class InvalidRequestException extends RuntimeException
{
}
