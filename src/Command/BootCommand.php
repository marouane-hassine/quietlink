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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Exit codes: 0 ready (or would be, with --dry-run), 1 a blocking check failed, 2 usage error.
 */
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
        #[Autowire('%kernel.project_dir%/config')] private readonly string $projectConfigDir = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run every check without creating or writing anything');
        OutputFormat::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = OutputFormat::read($input, $output);
        if ($format === null) {
            return OutputFormat::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        try {
            $config = $this->status->config();
        } catch (InvalidConfigException $e) {
            return $this->report($output, $format, $dryRun, $e->errors, []);
        }

        $env = Environment::processVariables();
        $pool = $env['QUIETLINK_FPM_POOL_FILE'] ?? null;
        $configDir = $env['QUIETLINK_CONFIG_DIR'] ?? null;
        $secretFile = $env['QUIETLINK_APP_SECRET_FILE'] ?? null;
        $booter = new Booter($this->publicDir, $this->disk, $this->clock, array_values([...$this->themeBuilders]), is_string($configDir) ? $configDir : $this->projectConfigDir);
        $errors = $booter->boot($config, is_string($pool) && $pool !== '' ? $pool : null, $dryRun, is_string($secretFile) && $secretFile !== '' ? $secretFile : null);

        return $this->report($output, $format, $dryRun, $errors, $booter->warnings());
    }

    /**
     * @param list<string> $errors   messages never contain the secret or stored content
     * @param list<string> $warnings
     */
    private function report(OutputInterface $output, string $format, bool $dryRun, array $errors, array $warnings): int
    {
        if ($format === 'json') {
            OutputFormat::json($output, ['status' => $errors === [] ? 'ok' : 'failed', 'dry_run' => $dryRun, 'errors' => $errors, 'warnings' => $warnings]);
        } else {
            foreach ($warnings as $warning) {
                OutputFormat::line($output, 'comment', 'warning: ' . $warning);
            }
            foreach ($errors as $error) {
                OutputFormat::line($output, 'error', $error);
            }
            if ($errors === []) {
                $output->writeln($dryRun ? 'boot: ok (dry run: nothing was written)' : 'boot: ok');
            }
        }

        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
