<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

/**
 * Metadata and current state of a stored paste.
 */
final readonly class PasteRecord
{
    public function __construct(
        public PasteMeta $meta,
        public PasteState $state,
    ) {
    }
}
