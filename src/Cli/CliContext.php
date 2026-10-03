<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

/**
 * I/O shared by the CLI commands (replaceable in tests).
 */
final class CliContext
{
    /**
     * @param resource $stdin
     */
    public function __construct(
        public readonly ApiClient $api,
        public readonly Prompt $prompt,
        public $stdin,
    ) {
    }

    /**
     * Reads the single value allowed on stdin.
     */
    public function readStdin(int $maxBytes): string
    {
        $data = stream_get_contents($this->stdin, $maxBytes + 1);
        if ($data === false) {
            throw new CliException('Unable to read the standard input.');
        }
        if (strlen($data) > $maxBytes) {
            throw new CliException('The standard input is too large.');
        }

        return $data;
    }

    public function readLink(): ShareLink
    {
        $line = trim($this->readStdin(4096));
        if ($line === '') {
            throw new CliException('No link was provided on the standard input.');
        }

        return ShareLink::parse($line);
    }
}
