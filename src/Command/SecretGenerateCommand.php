<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a new secret, or writes it to a new file (mode 0600, or 0640 with --group-readable)
 * without printing it, so that it never reaches a terminal scrollback or a CI log. An existing
 * file is replaced, atomically, only with --force: rotation invalidates pending challenges and
 * resets rate limiting counters, and every worker must be rebooted with the new secret;
 * pastes stay readable (README-admin, secret rotation).
 * Exit codes: 0 done, 1 file exists or cannot be written, 2 usage error.
 */
#[AsCommand(name: 'app:secret:generate', description: 'Generate a new instance secret (32 random bytes, standard base64) for QUIETLINK_APP_SECRET or QUIETLINK_APP_SECRET_FILE.')]
final class SecretGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write the secret to this new file instead of printing it');
        $this->addOption('group-readable', null, InputOption::VALUE_NONE, 'With --output: mode 0640 instead of 0600 (PHP runs under the file group)');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'With --output: replace an existing secret file (rotation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $secret = base64_encode(random_bytes(32));
        $file = $input->getOption('output');
        if (!is_string($file) || $file === '') {
            $output->writeln($secret);

            return Command::SUCCESS;
        }
        if (is_link($file)) {
            $output->writeln('<error>The secret file path is a symbolic link: give the real path.</error>');

            return Command::FAILURE;
        }
        if (file_exists($file) && $input->getOption('force') !== true) {
            $output->writeln('<error>The secret file already exists; rotating the secret has consequences (README-admin): pass --force to replace it.</error>');

            return Command::FAILURE;
        }
        $mode = $input->getOption('group-readable') === true ? 0640 : 0600;
        if (!self::write($file, $secret . "\n", $mode)) {
            $output->writeln('<error>The secret file cannot be written (missing directory or permission denied).</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('secret written to the file (not shown), mode %04o', $mode));

        return Command::SUCCESS;
    }

    /** Temporary file in the same directory, created with no access for others, then renamed. */
    private static function write(string $file, string $content, int $mode): bool
    {
        $temp = dirname($file) . '/.' . basename($file) . '.' . bin2hex(random_bytes(6));
        $previous = umask(0077);
        try {
            $handle = @fopen($temp, 'x');
            if ($handle === false) {
                return false;
            }
            $ok = fwrite($handle, $content) === strlen($content) && fflush($handle) && fsync($handle);
            fclose($handle);
            if (!$ok || !@chmod($temp, $mode) || !@rename($temp, $file)) {
                @unlink($temp);

                return false;
            }

            return true;
        } finally {
            umask($previous);
        }
    }
}
