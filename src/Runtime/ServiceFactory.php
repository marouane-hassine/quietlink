<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Runtime;

use Psr\Log\LoggerInterface;
use QuietLink\Clock\Clock;
use QuietLink\Config\InstanceConfig;
use QuietLink\Config\InvalidConfigException;
use QuietLink\Log\JsonLogger;
use QuietLink\Paste\PasteService;
use QuietLink\RateLimit\RateLimiter;
use QuietLink\Storage\FilesystemPasteStore;
use QuietLink\Storage\IdempotencyStore;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\StorageLayout;
use QuietLink\Storage\UsageCounter;

/**
 * Builds runtime services from the configuration loaded at request time.
 */
final class ServiceFactory
{
    public static function layout(InstanceConfig $config): StorageLayout
    {
        return RuntimeStatus::layout($config);
    }

    public static function usage(InstanceConfig $config, StorageLayout $layout): UsageCounter
    {
        return new UsageCounter($layout, $config->storage->maxTotalBytes, $config->storage->maxItems);
    }

    public static function pasteService(InstanceConfig $config, FilesystemPasteStore $store, IdempotencyStore $idempotency, StateFiles $stateFiles, Clock $clock): PasteService
    {
        return new PasteService($config, $store, $idempotency, $stateFiles, $clock, static fn (string $dir): float|false => @disk_free_space($dir));
    }

    public static function rateLimiter(InstanceConfig $config, Clock $clock): RateLimiter
    {
        return new RateLimiter($config->storage->ratelimitDir, $config->http->rateLimits, $config->secret, $clock);
    }

    public static function logger(RuntimeStatus $status): LoggerInterface
    {
        try {
            $level = $status->config()->observability->logLevel;
        } catch (InvalidConfigException) {
            $level = 'warning';
        }

        return new JsonLogger($level);
    }
}
