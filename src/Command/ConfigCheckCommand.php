<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Command;

use QuietLink\Clock\Clock;
use QuietLink\Config\InstanceConfig;
use QuietLink\Config\InvalidConfigException;
use QuietLink\Runtime\RuntimeStatus;
use QuietLink\Storage\StateFiles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only status: never writes, never shows the secret. Exit codes: 0 valid and booted,
 * 1 invalid configuration, 2 valid but app:boot has not run for this configuration (or usage
 * error, as for every console command).
 */
#[AsCommand(name: 'app:config:check', description: 'Validate and print the effective configuration and readiness (secrets are never shown).')]
final class ConfigCheckCommand extends Command
{
    public const NOT_READY = 2;

    public function __construct(private readonly RuntimeStatus $status, private readonly Clock $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        OutputFormat::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = OutputFormat::read($input, $output);
        if ($format === null) {
            return OutputFormat::INVALID;
        }
        try {
            $config = $this->status->config();
        } catch (InvalidConfigException $e) {
            if ($format === 'json') {
                OutputFormat::json($output, ['status' => 'invalid', 'errors' => $e->errors]);
            } else {
                foreach ($e->errors as $error) {
                    OutputFormat::line($output, 'error', $error);
                }
            }

            return Command::FAILURE;
        }
        $ready = $this->status->isReady();
        $health = $this->health($config);
        if ($format === 'json') {
            OutputFormat::json($output, [
                'status' => $ready ? 'ready' : 'not_ready',
                'ready' => $ready,
                'boot_marker' => $ready ? 'matches' : 'missing_or_outdated',
                'not_ready_reason' => $ready ? null : $this->status->notReadyReason(),
                'project_root' => RuntimeStatus::projectRoot(),
                'secret' => 'present (not shown)',
                'fingerprint' => $config->fingerprint(),
                'health' => $health,
                'config' => $config->describe(),
            ]);
        } else {
            $output->writeln((string) json_encode($config->describe(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
            $output->writeln('secret: present (not shown)');
            $output->writeln('fingerprint: ' . $config->fingerprint());
            $output->writeln('boot marker: ' . ($ready ? 'matches' : 'missing or outdated, run app:boot'));
            if (!$ready) {
                $output->writeln('reason: ' . $this->status->notReadyReason());
            }
            $output->writeln('project root: ' . RuntimeStatus::projectRoot());
            if ($health !== null) {
                $output->writeln(sprintf(
                    'health: measured %d s ago, %d free bytes, free inodes %s, creation %s',
                    $health['age_seconds'],
                    $health['free_bytes'],
                    $health['free_inodes_percent'] === null ? 'not measured' : $health['free_inodes_percent'] . '%',
                    $health['creation_allowed'] ? 'allowed' : 'refused',
                ));
            }
        }

        return $ready ? Command::SUCCESS : self::NOT_READY;
    }

    /**
     * Last disk measurement written by app:boot and the purge (health.json), or null.
     *
     * @return array{age_seconds: int, free_bytes: int, free_inodes_percent: int|null, creation_allowed: bool}|null
     */
    private function health(InstanceConfig $config): ?array
    {
        $files = new StateFiles(RuntimeStatus::layout($config));
        $health = $files->health();
        if ($health === null) {
            return null;
        }
        $now = $this->clock->now();

        return [
            'age_seconds' => max(0, $now - $health['measured_at']),
            'free_bytes' => $health['free_bytes'],
            'free_inodes_percent' => $health['free_inodes_percent'],
            'creation_allowed' => $health['free_bytes'] >= $config->storage->minFreeBytes && $files->inodesAllowCreation($config->storage->minFreeInodesPercent),
        ];
    }
}
