<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * Persistent paste states (docs/storage-format.md §7).
 */
enum StateName: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Deleted = 'deleted';
}
