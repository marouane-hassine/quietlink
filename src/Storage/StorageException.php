<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

use RuntimeException;

/**
 * Storage temporarily unavailable (I/O failure, lock timeout). Mapped to a generic 503.
 * Messages never contain identifiers or secrets.
 */
class StorageException extends RuntimeException
{
}
