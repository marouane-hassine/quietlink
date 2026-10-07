<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PSR-3 logger writing JSON lines to stderr, or to a file (log.file, shared hosting) rotated
 * by size with one archive (ADR-0005).
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

    public const MAX_FILE_BYTES = 5_000_000;

    /** @var resource|null */
    private $stream;

    /** @var resource|null */
    private $fileHandle = null;

    private bool $fileFailed = false;

    /**
     * @param resource|null $stream   defaults to php://stderr; also the fallback of an unusable file
     * @param string|null   $file     absolute log file path (log.file), or null for the stream
     * @param int           $maxBytes size beyond which the file is renamed to "<file>.1"
     */
    public function __construct(
        private readonly string $minLevel = LogLevel::INFO,
        $stream = null,
        private readonly ?string $file = null,
        private readonly int $maxBytes = self::MAX_FILE_BYTES,
    ) {
        $this->stream = $stream;
    }

    /**
     * @param mixed[] $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = is_string($level) && isset(self::LEVELS[$level]) ? $level : LogLevel::ERROR;
        // Symfony's router reports every match at info: debug noise next to the request line.
        if ((string) $message === 'Matched route "{route}".') {
            $level = LogLevel::DEBUG;
        }
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
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        $file = $this->fileHandle();
        if ($file !== null && @fwrite($file, $line) !== false) {
            return;
        }
        if ($this->stream === null) {
            $stream = fopen('php://stderr', 'w');
            $this->stream = $stream === false ? null : $stream;
        }
        if ($this->stream !== null) {
            fwrite($this->stream, $line);
        }
    }

    /**
     * The open log file, rotated first when it grew beyond maxBytes; null when no file is
     * configured or it cannot be written (the stream is used instead).
     *
     * @return resource|null
     */
    private function fileHandle()
    {
        if ($this->file === null || $this->fileFailed) {
            return null;
        }
        clearstatcache(true, $this->file);
        $stat = @stat($this->file);
        if ($this->fileHandle !== null && ($stat === false || $stat['ino'] !== (fstat($this->fileHandle)['ino'] ?? null))) {
            // Rotated (or removed) by another worker: write to the new file, never the archive.
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
        $size = $stat === false ? false : $stat['size'];
        if ($size !== false && $size >= $this->maxBytes) {
            if ($this->fileHandle !== null) {
                fclose($this->fileHandle);
                $this->fileHandle = null;
            }
            // Concurrent workers may both rotate: the loser's rename fails and it reopens the new file.
            @rename($this->file, $this->file . '.1');
        }
        if ($this->fileHandle === null) {
            $dir = dirname($this->file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $created = !is_file($this->file);
            $handle = @fopen($this->file, 'ab');
            if ($handle === false) {
                $this->fileFailed = true;

                return null;
            }
            if ($created) {
                @chmod($this->file, 0640);
            }
            $this->fileHandle = $handle;
        }

        return $this->fileHandle;
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
