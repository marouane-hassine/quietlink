<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'delete', description: 'Permanently delete a paste with its management link.')]
final class DeleteCommand extends Command
{
    public function __construct(private readonly CliContext $context)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url-stdin', null, InputOption::VALUE_NONE, 'Read the management link from stdin (required)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!Options::flag($input, 'url-stdin')) {
            throw new CliException('Pass the management link on stdin with --url-stdin.');
        }
        $link = $this->context->readLink();
        if (!$link->management) {
            throw new CliException('Deletion needs the management link, not the share link.');
        }
        if (!Options::flag($input, 'yes')) {
            if (!$this->context->prompt->isAvailable()) {
                throw new CliException('Refusing to delete without a terminal; pass --yes to confirm.');
            }
            if (!$this->context->prompt->confirm('Delete this content permanently?')) {
                return Command::FAILURE;
            }
        }
        $this->context->api->delete($link);
        $output->writeln('Deleted.');

        return Command::SUCCESS;
    }
}
