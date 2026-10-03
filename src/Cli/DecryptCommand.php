<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

use QuietLink\Client\ClientCrypto;
use QuietLink\Crypto\Aad;
use QuietLink\Crypto\DecryptionFailedException;
use QuietLink\Crypto\Ed25519;
use QuietLink\Crypto\InvalidAadException;
use QuietLink\Crypto\KeyDerivation;
use QuietLink\Encoding\Base64Url;
use QuietLink\Encoding\InvalidEncodingException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'decrypt', description: 'Decrypt a paste locally. A read-once paste is consumed after a successful decryption.')]
final class DecryptCommand extends Command
{
    public function __construct(private readonly CliContext $context)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url-stdin', null, InputOption::VALUE_NONE, 'Read the share link from stdin (required)')
            ->addOption('passphrase-file', null, InputOption::VALUE_REQUIRED, 'Read the passphrase from a file readable by its owner only')
            ->addOption('passphrase-stdin', null, InputOption::VALUE_NONE, 'Refused: stdin already carries the link')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the text to this new file (mode 600) instead of stdout')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Consume a read-once paste without asking');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!Options::flag($input, 'url-stdin')) {
            throw new CliException('Pass the link on stdin with --url-stdin so that it stays out of the shell history.');
        }
        if (Options::flag($input, 'passphrase-stdin')) {
            throw new CliException('--passphrase-stdin cannot be combined with --url-stdin; use the terminal or --passphrase-file.');
        }
        $target = Options::string($input, 'output');
        if (is_string($target) && file_exists($target)) {
            throw new CliException('The output file already exists.');
        }
        $link = $this->context->readLink();
        if ($link->management) {
            throw new CliException('This is a management link; decrypt needs the share link.');
        }
        $accessSeed = KeyDerivation::accessSeed($link->secret);
        $status = $this->context->api->status($link, $accessSeed);
        $aad = self::aad($status);
        // The AAD must belong to this link (sp-proto/v1 §8.3): a server cannot substitute another paste.
        if (!hash_equals(Ed25519::publicKeyFromSeed($accessSeed), $aad->accessPk)) {
            throw new CliException('Integrity error: the server returned metadata of another content.');
        }

        // Passphrase and consume key are derived and checked before any reservation (§6.3.1).
        $kPass = null;
        if ($aad->kdf !== null) {
            $passphrase = (new PassphraseSource($this->context->prompt))->read(Options::string($input, 'passphrase-file'), false, $this->context->stdin, false);
            $kPass = ClientCrypto::passphraseKey($aad, $passphrase);
            sodium_memzero($passphrase);
        }
        try {
            $consumeSeed = ClientCrypto::consumeSeed($aad, $link->secret, $kPass);
        } catch (DecryptionFailedException) {
            throw new CliException('Incorrect passphrase. The content was not opened.');
        }

        $reservationId = null;
        if ($aad->readOnce) {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errors->writeln('This content can be read only once: it will be destroyed after decryption.');
            if (!Options::flag($input, 'yes')) {
                if (!$this->context->prompt->isAvailable()) {
                    throw new CliException('Refusing to consume a read-once paste without a terminal; pass --yes to confirm.');
                }
                if (!$this->context->prompt->confirm('Open and destroy it now?')) {
                    return Command::FAILURE;
                }
            }
            $reservationId = Base64Url::encode(random_bytes(16));
        }

        $opened = $this->context->api->open($link, $accessSeed, $reservationId);
        if (!hash_equals($aad->bytes(), self::aad($opened)->bytes())) {
            throw new CliException('Integrity error: the metadata changed between status and open.');
        }
        try {
            $plaintext = ClientCrypto::decrypt(
                $link->secret,
                $kPass,
                $aad,
                Base64Url::decode(is_string($opened['nonce'] ?? null) ? $opened['nonce'] : '', 12),
                Base64Url::decode(is_string($opened['ciphertext'] ?? null) ? $opened['ciphertext'] : ''),
            );
        } catch (DecryptionFailedException|InvalidEncodingException) {
            throw new CliException('Decryption failed: the content or the link has been altered.');
        }
        $envelope = json_decode($plaintext, true);
        sodium_memzero($plaintext);
        if (!is_array($envelope) || !is_string($envelope['text'] ?? null)) {
            throw new CliException('Decryption failed: invalid content.');
        }

        if (is_string($target)) {
            $handle = @fopen($target, 'x');
            if ($handle === false) {
                throw new CliException('The output file cannot be created.');
            }
            chmod($target, 0600);
            fwrite($handle, $envelope['text']);
            fclose($handle);
        } else {
            $output->write($envelope['text'], false, OutputInterface::OUTPUT_RAW);
        }

        if ($reservationId !== null && $consumeSeed !== null) {
            $challenge = is_string($opened['consume_challenge'] ?? null) ? $opened['consume_challenge'] : '';
            $this->context->api->consume($link, ClientCrypto::accessPublicKey($link->secret), $reservationId, $challenge, ClientCrypto::prove($consumeSeed, $challenge));
            sodium_memzero($consumeSeed);
        }
        if ($kPass !== null) {
            sodium_memzero($kPass);
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function aad(array $response): Aad
    {
        try {
            return Aad::fromBytes(Base64Url::decode(is_string($response['aad'] ?? null) ? $response['aad'] : ''));
        } catch (InvalidAadException|InvalidEncodingException) {
            throw new CliException('The server returned invalid metadata.');
        }
    }
}
