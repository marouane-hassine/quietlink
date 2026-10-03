<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Command;

use QuietLink\Config\InvalidConfigException;
use QuietLink\Runtime\RuntimeStatus;
use QuietLink\Theme\TokenThemeBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Controlled purge of non-sensitive caches (§9.6): generated theme stylesheets that the
 * current manifest no longer references. Pastes and secrets are never cached.
 */
#[AsCommand(name: 'app:cache:purge', description: 'Remove generated theme stylesheets that are no longer referenced (run after app:boot).')]
final class CachePurgeCommand extends Command
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
        $dir = $config->storage->generatedAssetsDir;
        $current = TokenThemeBuilder::stylesheet($config);
        $keep = $current === null ? null : basename($current);
        $removed = 0;
        $files = glob($dir . '/tokens.*.css');
        foreach ($files === false ? [] : $files as $file) {
            if (!is_link($file) && preg_match('/^tokens\.[0-9a-f]{16}\.css$/D', basename($file)) === 1 && basename($file) !== $keep) {
                $removed += @unlink($file) ? 1 : 0;
            }
        }
        $output->writeln(sprintf('cache purge: %d stale theme stylesheet(s) removed', $removed));

        return Command::SUCCESS;
    }
}
