<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Support;

use Closure;
use QuietLink\Storage\PasteId;
use QuietLink\Storage\PasteRecord;
use QuietLink\Storage\PasteStore;

/**
 * Decorator counting storage accesses.
 */
final class SpyPasteStore implements PasteStore
{
    public int $accesses = 0;

    public function __construct(private readonly PasteStore $inner)
    {
    }

    public function create(Closure $meta, Closure $drawId, string $payload): PasteId
    {
        ++$this->accesses;

        return $this->inner->create($meta, $drawId, $payload);
    }

    public function find(PasteId $id): ?PasteRecord
    {
        ++$this->accesses;

        return $this->inner->find($id);
    }

    public function readPayload(PasteId $id): ?string
    {
        ++$this->accesses;

        return $this->inner->readPayload($id);
    }

    public function mutate(PasteId $id, Closure $transition): mixed
    {
        ++$this->accesses;

        return $this->inner->mutate($id, $transition);
    }

    public function remove(PasteId $id, ?Closure $guard = null): bool
    {
        ++$this->accesses;

        return $this->inner->remove($id, $guard);
    }
}
