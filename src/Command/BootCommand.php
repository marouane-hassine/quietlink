<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Command;

use QuietLink\Clock\Clock;
use QuietLink\Config\InvalidConfigException;
use QuietLink\Maintenance\Booter;
use QuietLink\Maintenance\DiskProbe;
use QuietLink\Maintenance\ThemeBuilder;
use QuietLink\Runtime\Environment;
use QuietLink\Runtime\RuntimeStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsCommand(name: 'app:boot', description: 'Validate configuration, runtime and storage, then write the boot marker. Must succeed before PHP-FPM starts.')]
final class BootCommand extends Command
{
    /**
     * @param iterable<ThemeBuilder> $themeBuilders
     */
    public function __construct(
        private readonly RuntimeStatus $status,
        private readonly DiskProbe $disk,
        private readonly Clock $clock,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicDir,
        #[AutowireIterator('quietlink.theme_builder')] private readonly iterable $themeBuilders = [],
    ) {
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

        $pool = Environment::processVariables()['QUIETLINK_FPM_POOL_FILE'] ?? null;
        $booter = new Booter($this->publicDir, $this->disk, $this->clock, array_values([...$this->themeBuilders]));
        $errors = $booter->boot($config, is_string($pool) && $pool !== '' ? $pool : null);
        foreach ($booter->warnings() as $warning) {
            $output->writeln('<comment>warning: ' . $warning . '</comment>');
        }
        foreach ($errors as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }
        if ($errors !== []) {
            return Command::FAILURE;
        }
        $output->writeln('boot: ok');

        return Command::SUCCESS;
    }
}
