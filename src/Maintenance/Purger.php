<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Maintenance;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use QuietLink\Clock\Clock;
use QuietLink\Config\InstanceConfig;
use QuietLink\Paste\PasteService;
use QuietLink\RateLimit\RateLimiter;
use QuietLink\Storage\AtomicFile;
use QuietLink\Storage\FileLock;
use QuietLink\Storage\FilesystemPasteStore;
use QuietLink\Storage\IdempotencyStore;
use QuietLink\Storage\PasteRecord;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\StateName;
use QuietLink\Storage\StorageException;
use QuietLink\Storage\StorageLayout;
use QuietLink\Storage\UsageCounter;

/**
 * Idempotent purge (`app:purge-expired`, §9.7, docs/storage-format.md §10).
 */
final class Purger
{
    public const ORPHAN_MIN_AGE = 900;
    /**
     * Upper bound of orphan detection: the smallest allowed paste.idempotency_max_ttl (1h).
     * A record's retention is fixed at creation, so the current TTL cannot be used: after it
     * is raised, published pastes whose record already expired would be taken for orphans.
     */
    public const ORPHAN_MAX_AGE = 3600;
    public const TEMP_MIN_AGE = 3600;
    public const RECOMPUTE_INTERVAL = 3600;

    public function __construct(
        private readonly InstanceConfig $config,
        private readonly StorageLayout $layout,
        private readonly FilesystemPasteStore $store,
        private readonly IdempotencyStore $idempotency,
        private readonly UsageCounter $usage,
        private readonly StateFiles $stateFiles,
        private readonly RateLimiter $limiter,
        private readonly PasteService $pastes,
        private readonly DiskProbe $disk,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function lockPath(StorageLayout $layout): string
    {
        return $layout->stateDir . '/purge.lock';
    }

    /**
     * @return array<string, int>|null statistics, or null when another purge is running
     */
    public function run(): ?array
    {
        try {
            $lock = FileLock::acquire(self::lockPath($this->layout), true, false)
                ?? throw new StorageException('purge.lock is missing; run app:boot.');
        } catch (StorageException $e) {
            if (!is_file(self::lockPath($this->layout))) {
                throw $e;
            }

            return null;
        }

        try {
            $stats = ['removed' => 0, 'released' => 0, 'orphans' => 0, 'idempotency' => 0, 'ratelimit' => 0];
            $usageAtStart = $this->usage->read();
            $observed = ['bytes' => 0, 'items' => 0];
            $removed = ['bytes' => 0, 'items' => 0];

            foreach ($this->store->ids() as $id) {
                $dir = $this->layout->pasteDir($id);
                $sizeBefore = self::payloadSize($dir);
                if ($this->store->isIncomplete($id)) {
                    $stats['removed'] += $this->store->removeIncomplete($id) ? 1 : 0;
                } else {
                    try {
                        $action = $this->store->mutate($id, fn (PasteRecord $r): array => $this->decide($r));
                    } catch (StorageException) {
                        $action = 'busy';
                    }
                    if (($action === 'remove' || $action === 'orphan') && $this->store->remove($id)) {
                        ++$stats[$action === 'orphan' ? 'orphans' : 'removed'];
                    } elseif ($action === 'released') {
                        ++$stats['released'];
                    }
                }
                clearstatcache();
                if (!is_dir($dir)) {
                    $removed['items']++;
                    $removed['bytes'] += $sizeBefore;
                    continue;
                }
                $sizeAfter = self::payloadSize($dir);
                $removed['bytes'] += $sizeBefore - $sizeAfter;
                $observed['bytes'] += $sizeAfter;
                $observed['items']++;
            }

            foreach ($this->store->orphanStagingDirectories(self::TEMP_MIN_AGE) as $staging) {
                $this->store->removeOrphan($staging);
            }
            $stats['idempotency'] = $this->idempotency->purgeExpired(self::TEMP_MIN_AGE);
            $stats['ratelimit'] = $this->limiter->purgeExpired();

            $now = $this->clock->now();
            $this->stateFiles->writeHealth($now, $this->disk->freeBytes($this->layout->rootDir), $this->disk->freeInodesPercent($this->layout->rootDir));

            $recomputedAt = $this->usage->recomputedAt();
            if ($recomputedAt === null || $now - $recomputedAt >= self::RECOMPUTE_INTERVAL) {
                // Apply the observed gap under the lock, keeping concurrent changes (§9.7).
                $this->usage->applyRecomputation(
                    $observed['bytes'] + $removed['bytes'] - $usageAtStart['bytes'],
                    $observed['items'] + $removed['items'] - $usageAtStart['items'],
                    $now,
                );
            }

            $this->reportUsage();

            return $stats;
        } finally {
            $lock->release();
        }
    }

    /**
     * Decides, under the paste lock, what the purge does with one paste.
     *
     * @return array{0: \QuietLink\Storage\PasteState|null, 1: string}
     */
    private function decide(PasteRecord $record): array
    {
        $now = $this->clock->now();
        $state = $record->state;
        if ($state->name === StateName::Consumed) {
            if ($state->terminalAt === null || $now >= $state->terminalAt + PasteService::CONSUMED_RETENTION) {
                return [null, 'remove'];
            }
            if (is_file($this->layout->pasteDir($record->meta->id) . '/payload.bin')) {
                return [$state, 'keep'];
            }

            return [null, 'keep'];
        }
        if ($record->meta->isExpired($now)) {
            return [null, 'remove'];
        }
        $age = $now - $record->meta->createdAt;
        if ($age > self::ORPHAN_MIN_AGE && $age < self::ORPHAN_MAX_AGE
            && !$this->idempotency->designates($record->meta->idempotencyKeyHash, $record->meta->id)) {
            return [null, 'orphan'];
        }
        $released = $this->pastes->releaseIfExpired($record);
        if ($released !== null) {
            return [$released, 'released'];
        }

        return [null, 'keep'];
    }

    /**
     * Operational alert at 80 % of a quota (§7.5) and, when metrics.enabled, one aggregated,
     * non-identifying metrics line per run (§14). No endpoint is exposed.
     */
    private function reportUsage(): void
    {
        $usage = $this->usage->read();
        $percent = (int) floor(max(
            $usage['bytes'] / max(1, $this->config->storage->maxTotalBytes),
            $usage['items'] / max(1, $this->config->storage->maxItems),
        ) * 100);
        if ($percent >= 80) {
            $this->logger->warning('Storage quota above 80%', ['event' => 'quota_alert', 'percent' => $percent]);
        }
        if ($this->config->observability->metricsEnabled) {
            $this->logger->info('metrics', ['event' => 'storage', 'count' => $usage['items'], 'percent' => $percent]);
        }
    }

    private static function payloadSize(string $dir): int
    {
        $size = @filesize($dir . '/payload.bin');

        return $size === false ? 0 : $size;
    }

    /**
     * Creates the purge lock (app:boot).
     */
    public function ensureLockFile(): void
    {
        if (!is_file(self::lockPath($this->layout))) {
            AtomicFile::createExclusive(self::lockPath($this->layout), '', false);
        }
    }
}
