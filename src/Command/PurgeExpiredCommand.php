<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Command;

use Closure;
use QuietLink\Maintenance\Purger;
use QuietLink\Runtime\RuntimeStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

#[AsCommand(name: 'app:purge-expired', description: 'Remove expired and consumed pastes, release stale reservations, purge idempotency and rate limiting entries.')]
final class PurgeExpiredCommand extends Command
{
    /**
     * @param Closure(): Purger $purger
     */
    public function __construct(
        private readonly RuntimeStatus $status,
        #[AutowireServiceClosure(Purger::class)] private readonly Closure $purger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->status->isReady()) {
            $output->writeln('<error>The boot marker is missing or does not match the configuration; run app:boot first.</error>');

            return Command::FAILURE;
        }
        $stats = ($this->purger)()->run();
        if ($stats === null) {
            $output->writeln('purge: another run is in progress');

            return Command::SUCCESS;
        }
        $output->writeln('purge: ' . (string) json_encode($stats));

        return Command::SUCCESS;
    }
}
