<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

use QuietLink\Crypto\Aad;
use QuietLink\Crypto\InvalidAadException;
use QuietLink\Crypto\KeyDerivation;
use QuietLink\Encoding\Base64Url;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'metadata', description: 'Show expiration and read-once state of a paste without decrypting or reserving it.')]
final class MetadataCommand extends Command
{
    public function __construct(private readonly CliContext $context)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('url-stdin', null, InputOption::VALUE_NONE, 'Read the share link from stdin (required)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!Options::flag($input, 'url-stdin')) {
            throw new CliException('Pass the link on stdin with --url-stdin so that it stays out of the shell history.');
        }
        $link = $this->context->readLink();
        if ($link->management) {
            throw new CliException('This is a management link; metadata needs the share link.');
        }
        $status = $this->context->api->status($link, KeyDerivation::accessSeed($link->secret));
        try {
            $aad = Aad::fromBytes(Base64Url::decode(is_string($status['aad'] ?? null) ? $status['aad'] : ''));
        } catch (InvalidAadException) {
            throw new CliException('The server returned invalid metadata.');
        }

        $output->writeln('expires_at: ' . (is_string($status['expires_at'] ?? null) ? $status['expires_at'] : 'never'));
        $output->writeln('read_once: ' . ($aad->readOnce ? 'yes' : 'no'));
        $output->writeln('passphrase: ' . ($aad->kdf !== null ? 'required' : 'no'));
        if ($aad->readOnce) {
            $output->writeln('state: ' . (is_string($status['state'] ?? null) ? $status['state'] : 'unknown'));
            $output->writeln('unconfirmed_opens: ' . (is_int($status['unconfirmed_opens'] ?? null) ? $status['unconfirmed_opens'] : 0));
        }

        return Command::SUCCESS;
    }
}
