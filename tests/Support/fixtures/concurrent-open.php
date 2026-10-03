<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Child process of PasteConcurrencyTest: waits for a start barrier, then tries to reserve.
// Arguments: <storage-json> <id> <request-body-file> <barrier-file>. Prints "reserved",
// "conflict" or "unavailable".

declare(strict_types=1);

use QuietLink\Clock\SystemClock;
use QuietLink\Paste\PasteService;
use QuietLink\Paste\PasteUnavailableException;
use QuietLink\Paste\ReservationConflictException;
use QuietLink\Storage\FilesystemPasteStore;
use QuietLink\Storage\IdempotencyStore;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\UsageCounter;
use QuietLink\Tests\Support\TempDirectory;
use QuietLink\Tests\Support\TestInstance;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $base, $id, $bodyFile, $barrier] = $argv;
$tmp = (new ReflectionClass(TempDirectory::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(TempDirectory::class, 'path'))->setValue($tmp, $base);
$config = TestInstance::config($tmp);
$layout = TestInstance::layout($config);
$clock = new SystemClock();
$service = new PasteService(
    $config,
    new FilesystemPasteStore($layout, new UsageCounter($layout, $config->storage->maxTotalBytes, $config->storage->maxItems), $clock),
    new IdempotencyStore($layout, $clock),
    new StateFiles($layout),
    $clock,
    static fn (): int => PHP_INT_MAX,
);
$body = (string) file_get_contents($bodyFile);

while (!file_exists($barrier)) {
    usleep(1000);
}
try {
    $service->open($id, $body);
    echo 'reserved';
} catch (ReservationConflictException) {
    echo 'conflict';
} catch (PasteUnavailableException) {
    echo 'unavailable';
}
