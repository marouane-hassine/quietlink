<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

/**
 * Passphrase input rules of §6.7: /dev/tty without echo, --passphrase-file (owner-only or
 * under /run/secrets/) or --passphrase-stdin; never the command line or the environment.
 */
final class PassphraseSource
{
    public function __construct(private readonly Prompt $prompt)
    {
    }

    /**
     * @param resource $stdin
     * @param bool     $stdinAllowed false when stdin already carries the link (decrypt): the
     *                               error message then only suggests --passphrase-file
     */
    public function read(?string $file, bool $fromStdin, $stdin, bool $confirm, bool $stdinAllowed = true): string
    {
        if ($file !== null) {
            return self::fromFile($file);
        }
        if ($fromStdin) {
            $line = fgets($stdin);

            return self::validate(rtrim($line === false ? '' : $line, "\r\n"));
        }
        if (!$this->prompt->isAvailable()) {
            throw new CliException($stdinAllowed
                ? 'A passphrase is required but no terminal is available; use --passphrase-file or --passphrase-stdin.'
                : 'A passphrase is required but no terminal is available; use --passphrase-file.');
        }
        $passphrase = self::validate($this->prompt->secret('Passphrase: '));
        if ($confirm && !hash_equals($passphrase, $this->prompt->secret('Confirm passphrase: '))) {
            throw new CliException('The passphrases do not match.');
        }

        return $passphrase;
    }

    public static function fromFile(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new CliException('The passphrase file cannot be read.');
        }
        $mode = fileperms($real);
        if (!str_starts_with($real, '/run/secrets/') && ($mode === false || ($mode & 0077) !== 0)) {
            throw new CliException('The passphrase file must be readable by its owner only (chmod 600).');
        }
        $content = file_get_contents($real);

        return self::validate(rtrim($content === false ? '' : $content, "\r\n"));
    }

    private static function validate(string $passphrase): string
    {
        if ($passphrase === '' || !mb_check_encoding($passphrase, 'UTF-8')) {
            throw new CliException('The passphrase must be a non-empty UTF-8 text.');
        }

        return $passphrase;
    }
}
