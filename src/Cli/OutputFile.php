<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

/**
 * Writes decrypted text to a new owner-only file (`decrypt -o`). Every write is checked: a full
 * disk or a quota must fail before a read-once paste is consumed, and never leave a truncated
 * file behind (§6.7).
 */
final class OutputFile
{
    public static function write(string $path, string $text): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new CliException('The output file cannot be created.');
        }
        @chmod($path, 0600);
        $written = 0;
        while ($written < strlen($text)) {
            $chunk = @fwrite($handle, substr($text, $written));
            if ($chunk === false || $chunk === 0) {
                break;
            }
            $written += $chunk;
        }
        $closed = @fclose($handle);
        if ($written !== strlen($text) || !$closed) {
            @unlink($path);
            throw new CliException('The output file could not be written completely; nothing was consumed.');
        }
    }
}
