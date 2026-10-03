<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

/**
 * Prompt reading and writing /dev/tty; secrets are read without echo (stty -echo).
 */
final class TtyPrompt implements Prompt
{
    private const TTY = '/dev/tty';

    public function isAvailable(): bool
    {
        $tty = @fopen(self::TTY, 'r+');
        if ($tty === false) {
            return false;
        }
        fclose($tty);

        return true;
    }

    public function secret(string $question): string
    {
        $tty = $this->open();
        fwrite($tty, $question);
        $this->stty('-echo');
        try {
            $line = fgets($tty);
        } finally {
            $this->stty('echo');
            fwrite($tty, "\n");
            fclose($tty);
        }

        return rtrim($line === false ? '' : $line, "\r\n");
    }

    public function confirm(string $question): bool
    {
        $tty = $this->open();
        fwrite($tty, $question . ' [y/N] ');
        $line = fgets($tty);
        fclose($tty);

        return in_array(strtolower(trim($line === false ? '' : $line)), ['y', 'yes'], true);
    }

    /**
     * @return resource
     */
    private function open()
    {
        $tty = @fopen(self::TTY, 'r+');

        return $tty === false ? throw new CliException('No controlling terminal is available.') : $tty;
    }

    private function stty(string $mode): void
    {
        $process = @proc_open(['stty', $mode], [0 => ['file', self::TTY, 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (is_resource($process)) {
            proc_close($process);
        }
    }
}
