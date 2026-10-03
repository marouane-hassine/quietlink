<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * Global storage quota or free space threshold reached (§7.5): generic 503 with Retry-After.
 */
final class QuotaExceededException extends StorageException
{
}
