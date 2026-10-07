<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Log;

use Psr\Log\LoggerInterface;

/**
 * Operations events that would otherwise stay invisible (stale disk health, boot marker
 * mismatch). Each event is logged at most once a minute per worker, with no context besides
 * its name: never an identifier, path or address (ADR-0005).
 */
final class OperationsLog
{
    private const INTERVAL = 60;

    /** @var array<string, int> last time each event was logged by this worker */
    private static array $last = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param string $variant distinguishes occurrences of one event throttled separately (one
     *                        line per configuration error)
     */
    public function warnOnce(string $event, string $message, int $now, string $variant = ''): void
    {
        $key = $event . "\0" . $variant;
        if (isset(self::$last[$key]) && $now - self::$last[$key] < self::INTERVAL) {
            return;
        }
        self::$last[$key] = $now;
        $this->logger->warning($message, ['event' => $event]);
    }

    /** Test helper: forgets the throttling state. */
    public static function reset(): void
    {
        self::$last = [];
    }
}
