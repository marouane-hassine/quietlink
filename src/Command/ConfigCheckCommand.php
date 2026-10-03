<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Command;

use QuietLink\Config\InvalidConfigException;
use QuietLink\Runtime\RuntimeStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:config:check', description: 'Validate and print the effective configuration (secrets are never shown).')]
final class ConfigCheckCommand extends Command
{
    public function __construct(private readonly RuntimeStatus $status)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->status->config();
        } catch (InvalidConfigException $e) {
            foreach ($e->errors as $error) {
                $output->writeln('<error>' . $error . '</error>');
            }

            return Command::FAILURE;
        }
        $output->writeln((string) json_encode($config->describe(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $output->writeln('secret: present (not shown)');
        $output->writeln('fingerprint: ' . $config->fingerprint());
        $output->writeln('boot marker: ' . ($this->status->isReady() ? 'matches' : 'missing or outdated, run app:boot'));

        return Command::SUCCESS;
    }
}
