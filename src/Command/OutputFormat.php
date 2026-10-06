<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The --format option of the operations commands: "text" for people, "json" (one document on
 * stdout) for scripts. Exit codes are documented in docs/README-admin.md.
 */
final class OutputFormat
{
    public const INVALID = Command::INVALID;

    public static function configure(Command $command): void
    {
        $command->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    /** "text" or "json"; null after printing a usage error for any other value. */
    public static function read(InputInterface $input, OutputInterface $output): ?string
    {
        $format = $input->getOption('format');
        if ($format === 'text' || $format === 'json') {
            return $format;
        }
        $output->writeln('<error>--format must be "text" or "json".</error>');

        return null;
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function json(OutputInterface $output, array $document): void
    {
        // Raw: the console formatter would rewrite backslashes and <tags> inside the document.
        $output->writeln(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }

    /** Text line with a style: the message is shown literally, never read as console markup. */
    public static function line(OutputInterface $output, string $style, string $message): void
    {
        $output->writeln(sprintf('<%s>%s</%1$s>', $style, OutputFormatter::escape(mb_scrub($message, 'UTF-8'))));
    }
}
