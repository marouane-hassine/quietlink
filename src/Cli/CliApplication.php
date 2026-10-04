<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

use QuietLink\Maintenance\Booter;
use QuietLink\Version;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The `quietlink` command line client (§6.7). Independent from the server kernel.
 */
final class CliApplication extends Application
{
    public const VERSION = Version::APP;

    public function __construct(private readonly CliContext $context)
    {
        parent::__construct('quietlink', self::VERSION);
        $this->setCatchExceptions(false);
        $this->addCommands([
            new CreateCommand($context),
            new MetadataCommand($context),
            new DecryptCommand($context),
            new DeleteCommand($context),
        ]);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if (array_key_exists('QUIETLINK_PASSPHRASE', $_SERVER) || getenv('QUIETLINK_PASSPHRASE') !== false) {
            $errors->writeln('<error>QUIETLINK_PASSPHRASE is forbidden; use the terminal, --passphrase-file or --passphrase-stdin.</error>');

            return 2;
        }
        $argv = $_SERVER['argv'] ?? [];
        foreach (is_array($argv) ? $argv : [] as $argument) {
            if (is_string($argument) && str_starts_with($argument, '--passphrase=')) {
                $errors->writeln('<error>Passing a passphrase on the command line is forbidden.</error>');

                return 2;
            }
        }
        $missing = Booter::checkRuntime();
        if ($missing !== []) {
            foreach ($missing as $error) {
                $errors->writeln('<error>' . $error . '</error>');
            }

            return 2;
        }

        try {
            return parent::doRun($input, $output);
        } catch (CliException $e) {
            $errors->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        } catch (ExceptionInterface $e) {
            // Console parsing messages quote argv values verbatim (links, keys, passphrases typed
            // by mistake): only a generic, value-free usage message is ever printed.
            $errors->writeln('<error>' . self::usageError($e) . '</error>');

            return 2;
        } catch (Throwable $e) {
            $errors->writeln('<error>Unexpected error.</error>');
            if ($output->isVerbose()) {
                // Class and location only: messages of third-party exceptions may contain input data.
                $errors->writeln(sprintf('%s at %s:%d', $e::class, basename($e->getFile()), $e->getLine()));
            }

            return 1;
        }
    }

    /**
     * Maps a Symfony Console exception to a message that never contains user input.
     */
    private static function usageError(ExceptionInterface $e): string
    {
        $message = $e->getMessage();
        if ($e instanceof CommandNotFoundException) {
            return 'Unknown command; see --help for the available commands.';
        }
        if (str_starts_with($message, 'No arguments expected') || str_starts_with($message, 'Too many arguments')) {
            return 'Unexpected argument: pass links on standard input with --url-stdin, never as arguments.';
        }

        return 'Invalid option or argument; see --help.';
    }
}
