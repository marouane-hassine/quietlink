<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Child process of IdempotencyRaceTest: publishes a record for a shared key after a barrier.
// Arguments: <base-dir> <barrier-file> <paste-id-byte>. Prints "won" or "lost".

declare(strict_types=1);

use QuietLink\Storage\IdempotencyRecord;
use QuietLink\Storage\IdempotencyStore;
use QuietLink\Storage\PasteId;
use QuietLink\Storage\StorageLayout;
use QuietLink\Tests\Support\FrozenClock;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $base, $barrier, $byte] = $argv;
$clock = new FrozenClock(1790000000 + 100);
$store = new IdempotencyStore(new StorageLayout($base . '/pastes', $base . '/idempotency', $base . '/state'), $clock);
$record = new IdempotencyRecord(hash('sha256', 'shared-key', true), hash('sha256', $byte, true), PasteId::fromBytes(str_repeat(chr((int) $byte), 24)), null, $clock->now() + 3600);

while (!file_exists($barrier)) {
    usleep(200);
}
echo $store->publish($record) ? 'won' : 'lost';
