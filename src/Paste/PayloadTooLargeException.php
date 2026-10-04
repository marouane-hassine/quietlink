<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Paste;

use RuntimeException;

/**
 * Ciphertext larger than this instance accepts (paste.max_ciphertext_bytes). Mapped to HTTP 413.
 */
final class PayloadTooLargeException extends RuntimeException
{
}
