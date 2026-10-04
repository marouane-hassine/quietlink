<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

use Closure;

/**
 * Paste persistence used by the domain services (implemented by FilesystemPasteStore).
 */
interface PasteStore
{
    /**
     * @param Closure(PasteId): PasteMeta $meta
     * @param Closure(): PasteId          $drawId
     */
    public function create(Closure $meta, Closure $drawId, string $payload): PasteId;

    public function find(PasteId $id): ?PasteRecord;

    public function readPayload(PasteId $id): ?string;

    /**
     * @template T
     *
     * @param Closure(PasteRecord): array{0: PasteState|null, 1: T} $transition
     *
     * @return T|null
     */
    public function mutate(PasteId $id, Closure $transition): mixed;

    /**
     * @param (Closure(PasteRecord): bool)|null $guard
     */
    public function remove(PasteId $id, ?Closure $guard = null): bool;
}
