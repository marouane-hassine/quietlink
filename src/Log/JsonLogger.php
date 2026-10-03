<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PSR-3 logger writing JSON lines to stderr (ADR-0005).
 *
 * Messages are written as templates: placeholders are never interpolated and the context
 * is dropped, except for allowlisted scalar fields, so that identifiers, headers, URLs and
 * payloads passed by third-party code cannot reach the logs.
 */
final class JsonLogger extends AbstractLogger
{
    public const ALLOWED_CONTEXT = ['method', 'route', 'status', 'duration_ms', 'request_bytes', 'response_bytes', 'exception', 'event', 'count', 'percent'];

    private const LEVELS = [
        LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3,
        LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7,
    ];

    /** @var resource|null */
    private $stream;

    /**
     * @param resource|null $stream defaults to php://stderr
     */
    public function __construct(private readonly string $minLevel = LogLevel::INFO, $stream = null)
    {
        $this->stream = $stream;
    }

    /**
     * @param mixed[] $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = is_string($level) && isset(self::LEVELS[$level]) ? $level : LogLevel::ERROR;
        if (self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
            return;
        }
        $record = ['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $level, 'message' => self::sanitize((string) $message)];
        foreach (self::ALLOWED_CONTEXT as $key) {
            $value = $context[$key] ?? null;
            if (is_int($value) || is_float($value) || is_bool($value) || (is_string($value) && strlen($value) <= 128)) {
                $record[$key] = is_string($value) ? self::sanitize($value) : $value;
            }
        }
        if ($this->stream === null) {
            $stream = fopen('php://stderr', 'w');
            $this->stream = $stream === false ? null : $stream;
        }
        if ($this->stream !== null) {
            fwrite($this->stream, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        }
    }

    /**
     * Drops anything that looks like a URL, base64url token or long hex value.
     */
    private static function sanitize(string $value): string
    {
        $value = (string) preg_replace('#[a-z][a-z0-9+.-]*://\S+#i', '[url]', $value);
        $value = (string) preg_replace('#[A-Za-z0-9_-]{22,}#', '[redacted]', $value);

        return mb_substr($value, 0, 256);
    }
}
