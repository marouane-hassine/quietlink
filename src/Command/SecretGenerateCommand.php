<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:secret:generate', description: 'Print a new instance secret (32 random bytes, standard base64) for QUIETLINK_APP_SECRET.')]
final class SecretGenerateCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(base64_encode(random_bytes(32)));

        return Command::SUCCESS;
    }
}
