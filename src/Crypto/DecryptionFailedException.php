<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

use RuntimeException;

/**
 * Raised when AES-256-GCM authentication fails (tampered or wrong key).
 */
final class DecryptionFailedException extends RuntimeException
{
}
