<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Phase 3 benchmark (§13, EXG-PERF-011): purge of N expired pastes on a disposable store.
// Usage: php tools/bench/purge.php [count=100000] [directory=/tmp/quietlink-bench]
// Dummy payloads only; the directory is deleted at the end.

declare(strict_types=1);

use QuietLink\Clock\Clock;
use QuietLink\Config\ConfigLoader;
use QuietLink\Maintenance\DiskProbe;
use QuietLink\Maintenance\Purger;
use QuietLink\Paste\PasteService;
use QuietLink\RateLimit\RateLimiter;
use QuietLink\Storage\FilesystemPasteStore;
use QuietLink\Storage\IdempotencyStore;
use QuietLink\Storage\PasteId;
use QuietLink\Storage\PasteMeta;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\StorageLayout;
use QuietLink\Storage\UsageCounter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$count = (int) ($argv[1] ?? 100000);
$base = $argv[2] ?? sys_get_temp_dir() . '/quietlink-bench';
@mkdir($base . '/config/themes', 0700, true);
file_put_contents($base . '/config/config.php', '<?php return ' . var_export([
    'app' => ['public_url' => 'https://bench.example.test'],
    'storage' => [
        'root_dir' => $base . '/data/pastes', 'idempotency_dir' => $base . '/data/idempotency',
        'ratelimit_dir' => $base . '/data/ratelimit', 'state_dir' => $base . '/data/state',
        'generated_assets_dir' => $base . '/generated', 'max_items' => max(100000, $count),
    ],
], true) . ';');
$config = ConfigLoader::load($base . '/config', ['QUIETLINK_APP_SECRET' => base64_encode(random_bytes(32))]);

$clock = new class () implements Clock {
    public int $now = 1790000000;

    public function now(): int
    {
        return $this->now;
    }
};
$layout = new StorageLayout($config->storage->rootDir, $config->storage->idempotencyDir, $config->storage->stateDir);
$layout->ensureDirectories();
$usage = new UsageCounter($layout, $config->storage->maxTotalBytes, $config->storage->maxItems);
$store = new FilesystemPasteStore($layout, $usage, $clock);
$limiter = new RateLimiter($config->storage->ratelimitDir, $config->http->rateLimits, $config->secret, $clock);
$limiter->ensureLockFiles();
$stateFiles = new StateFiles($layout);
$idempotency = new IdempotencyStore($layout, $clock);
$service = new PasteService($config, $store, $idempotency, $stateFiles, $clock, static fn (): int => PHP_INT_MAX);
$purger = new Purger($config, $layout, $store, $idempotency, $usage, $stateFiles, $limiter, $service, new DiskProbe(), $clock);
$purger->ensureLockFile();

$start = microtime(true);
$payload = str_repeat("\x00", 512);
for ($i = 0; $i < $count; ++$i) {
    $store->create(
        static fn (PasteId $id): PasteMeta => new PasteMeta($id, 'aad', $clock->now, $clock->now + 300, false, random_bytes(32), random_bytes(32)),
        static fn (): PasteId => PasteId::fromBytes(random_bytes(24)),
        $payload,
    );
}
printf("created %d pastes in %.1f s\n", $count, microtime(true) - $start);

$clock->now += 301;
$start = microtime(true);
$stats = $purger->run();
$elapsed = microtime(true) - $start;
$remaining = iterator_count($store->ids());
$pass = $elapsed < 300 && $remaining === 0;
printf("purged %d pastes in %.1f s, %d left (target: none left, < 300 s): %s\n", $stats['removed'] ?? 0, $elapsed, $remaining, $pass ? 'PASS' : 'FAIL');

exec('rm -rf ' . escapeshellarg($base));
exit($pass ? 0 : 1);
