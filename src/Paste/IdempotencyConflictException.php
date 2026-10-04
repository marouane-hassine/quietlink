<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Paste;

use RuntimeException;

/**
 * Idempotency-Key reused with a different body. Mapped to HTTP 422. Messages are generic.
 */
final class IdempotencyConflictException extends RuntimeException
{
}
