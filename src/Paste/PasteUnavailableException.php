<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Paste;

use RuntimeException;

/**
 * Generic unavailability: unknown, expired, consumed paste or invalid proof. Mapped to HTTP 404. Messages are generic.
 */
final class PasteUnavailableException extends RuntimeException
{
}
