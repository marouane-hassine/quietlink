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
use QuietLink\Storage\PasteId;
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
    /** Delay before retrying a recomputation deferred by concurrent changes. */
    public const RECOMPUTE_RETRY = 600;

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
     * @param bool     $measureInodes false for a purge triggered by a web request: `df` runs in
     *                                CLI only (§7.5), so the last inode measurement is kept
     * @param int|null $deadline      Unix time after which the remaining pastes are left to the
     *                                next run (web-triggered purge); the rest of the run happens
     *
     * @return array<string, int>|null statistics, or null when another purge is running
     */
    public function run(bool $measureInodes = true, ?int $deadline = null): ?array
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
            $stats = ['removed' => 0, 'released' => 0, 'orphans' => 0, 'failed' => 0, 'idempotency' => 0, 'ratelimit' => 0];
            $cursor = $deadline === null ? null : $this->readCursor();
            $stopped = null;
            foreach ($this->orderedIds($cursor) as $id) {
                if ($deadline !== null && $this->clock->now() > $deadline) {
                    $stopped = $id;
                    break;
                }
                // A failure on one paste (busy lock, unwritable directory, full disk) never
                // stops the run: the paste is retried by the next purge (§9.7).
                try {
                    if ($this->store->isIncomplete($id)) {
                        $stats[$this->outcome($this->store->removeIncomplete($id), $id, 'removed')] += 1;
                    } elseif ($this->store->isPendingDeletion($id)) {
                        $stats[$this->outcome($this->store->remove($id), $id, 'removed')] += 1;
                    } else {
                        $action = $this->store->mutate($id, fn (PasteRecord $r): array => $this->decide($r));
                        if ($action === 'remove' || $action === 'orphan') {
                            $stats[$this->outcome($this->store->remove($id), $id, $action === 'orphan' ? 'orphans' : 'removed')] += 1;
                        } elseif ($action === 'released') {
                            ++$stats['released'];
                        }
                    }
                } catch (StorageException) {
                    // Left for the next run, and counted so that a persistent problem shows.
                    ++$stats['failed'];
                }
            }

            $this->writeCursor($stopped);
            // A run cut short by its deadline leaves the full scans below to a later run.
            $late = $deadline !== null && $this->clock->now() > $deadline;
            foreach ($late ? [] : $this->store->orphanStagingDirectories(self::TEMP_MIN_AGE) as $staging) {
                $this->store->removeOrphan($staging);
            }
            $this->usage->purgeCreationMarkers($this->clock->now() - self::TEMP_MIN_AGE);
            $this->removeStaleTemporaryStateFiles($this->clock->now() - self::TEMP_MIN_AGE);
            $stats['idempotency'] = $this->idempotency->purgeExpired(self::TEMP_MIN_AGE);
            $stats['ratelimit'] = $this->limiter->purgeExpired();

            $now = $this->clock->now();
            $inodes = $measureInodes ? $this->disk->freeInodesPercent($this->layout->rootDir) : ($this->stateFiles->health()['free_inodes_percent'] ?? null);
            $this->stateFiles->writeHealth($now, $this->disk->freeBytes($this->layout->rootDir), $inodes);

            $recomputedAt = $this->usage->recomputedAt();
            if (!$late && ($recomputedAt === null || $now - $recomputedAt >= self::RECOMPUTE_INTERVAL)) {
                $this->recomputeUsage($now);
            }

            $this->reportUsage();
            if ($stats['failed'] > 0) {
                $this->logger->warning('Purge left items for the next run: check storage permissions', ['event' => 'purge_failures', 'count' => $stats['failed']]);
            }
            // One line per run: shows in the log that the purge (cron or web request) really runs.
            $this->logger->info($measureInodes ? 'Purge completed' : ($late ? 'Purge completed by a web request (budget reached, resumes next run)' : 'Purge completed by a web request'), ['event' => 'purge', 'count' => $stats['removed'] + $stats['orphans']]);

            return $stats;
        } finally {
            $lock->release();
        }
    }

    /**
     * Identifiers starting after the cursor (where the previous budget-limited run stopped),
     * then from the beginning up to it: every paste is eventually reached.
     *
     * @return \Generator<PasteId>
     */
    private function orderedIds(?string $cursor): \Generator
    {
        if ($cursor === null) {
            yield from $this->store->ids();

            return;
        }
        foreach ($this->store->ids() as $id) {
            if (strcmp($id->encoded(), $cursor) >= 0) {
                yield $id;
            }
        }
        foreach ($this->store->ids() as $id) {
            if (strcmp($id->encoded(), $cursor) < 0) {
                yield $id;
            }
        }
    }

    private function cursorPath(): string
    {
        return $this->layout->stateDir . '/purge.cursor';
    }

    private function readCursor(): ?string
    {
        $cursor = AtomicFile::read($this->cursorPath());

        return $cursor !== null && preg_match('/^[A-Za-z0-9_-]{32}$/D', $cursor) === 1 ? $cursor : null;
    }

    /**
     * Remembers the first paste a cut-short run did not handle; a complete run clears it.
     */
    private function writeCursor(?PasteId $stopped): void
    {
        try {
            if ($stopped !== null) {
                AtomicFile::write($this->cursorPath(), $stopped->encoded());
            } elseif (is_file($this->cursorPath())) {
                @unlink($this->cursorPath());
            }
        } catch (StorageException) {
            // Only an optimisation: the next run starts from the beginning.
        }
    }

    /**
     * Statistic for one removal: a removal another process completed first (an API deletion
     * racing the purge) is not a failure; only a directory still present is.
     */
    private function outcome(bool $removed, PasteId $id, string $success): string
    {
        if ($removed) {
            return $success;
        }
        clearstatcache();

        return is_dir($this->layout->pasteDir($id)) ? 'failed' : $success;
    }

    /** Temporary files of interrupted atomic writes of usage, health, boot state and the cursor. */
    private function removeStaleTemporaryStateFiles(int $before): void
    {
        $names = @scandir($this->layout->stateDir);
        foreach ($names === false ? [] : $names as $name) {
            $path = $this->layout->stateDir . '/' . $name;
            $mtime = preg_match('/^\.((usage|health|boot)\.json|purge\.cursor)\.tmp-[0-9a-f]{16}$/D', $name) === 1 ? @filemtime($path) : false;
            if ($mtime !== false && $mtime < $before) {
                @unlink($path);
            }
        }
    }

    /**
     * Hourly correction of usage.json drift (crashes between a filesystem operation and the
     * counter update, §9.7): a read-only scan, applied only if no reservation, release or
     * completed creation happened meanwhile; otherwise the next purge retries.
     */
    private function recomputeUsage(int $now): void
    {
        $start = $this->usage->snapshot();
        $observed = ['bytes' => 0, 'items' => 0];
        foreach ($this->store->ids() as $id) {
            $dir = $this->layout->pasteDir($id);
            clearstatcache();
            if (is_dir($dir)) {
                $observed['bytes'] += self::payloadSize($dir);
                $observed['items']++;
            }
        }
        if (!$this->usage->applyRecomputation($start['generation'], $observed['bytes'], $observed['items'], $now, self::TEMP_MIN_AGE)) {
            // Changed meanwhile: retried after a pause, not at every run (a full scan each minute
            // on a busy instance).
            $this->usage->postponeRecomputation($now, self::RECOMPUTE_RETRY, self::RECOMPUTE_INTERVAL);
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
        if ($record->meta->isExpired($now, $this->config->paste->maxRetentionSeconds)) {
            return [null, 'remove'];
        }
        $age = $now - $record->meta->createdAt;
        if ($age > self::ORPHAN_MIN_AGE && $age < self::ORPHAN_MAX_AGE
            && !$this->idempotency->designates($record->meta->idempotencyKeyHash, $record->meta->id)) {
            return [null, 'orphan'];
        }
        // After the late-confirmation grace (one more reservation lifetime, §6.3.1).
        $released = $this->pastes->releaseIfExpired($record, $this->config->paste->readOnceReservationTtl);
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
