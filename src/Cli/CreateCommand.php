<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

use QuietLink\Client\ClientCrypto;
use QuietLink\Config\Duration;
use QuietLink\Crypto\Argon2id;
use QuietLink\Encoding\Base64Url;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'create', description: 'Encrypt text locally and create a paste. Reads the text from stdin unless --input is given.')]
final class CreateCommand extends Command
{
    /** Default "paste.max_envelope_bytes": the limit applies to the serialized envelope (sp-proto §4). */
    public const MAX_ENVELOPE_BYTES = 1048576;

    /** Largest raw input that can still fit once line endings are normalized ("\r\n" to "\n"). */
    private const MAX_INPUT_BYTES = 2 * self::MAX_ENVELOPE_BYTES;

    private const TOO_LARGE = 'The serialized envelope (text and its JSON escaping) exceeds the 1 MiB limit.';

    public function __construct(private readonly CliContext $context)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Instance URL (or QUIETLINK_SERVER)')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Expiration: 5m, 1h, 1d, 7d, 30d or never', '1d')
            ->addOption('read-once', null, InputOption::VALUE_NONE, 'Destroy the paste after its first confirmed reading')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'plain, markdown or code', 'plain')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Syntax highlighting language for --format code')
            ->addOption('passphrase', null, InputOption::VALUE_NONE, 'Protect with a passphrase asked on the terminal')
            ->addOption('passphrase-file', null, InputOption::VALUE_REQUIRED, 'Read the passphrase from a file readable by its owner only')
            ->addOption('passphrase-stdin', null, InputOption::VALUE_NONE, 'Read the passphrase from stdin (requires --input)')
            ->addOption('input', null, InputOption::VALUE_REQUIRED, 'Read the text from this file instead of stdin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = getenv('QUIETLINK_SERVER');
        $server = Options::string($input, 'server') ?? (is_string($env) && $env !== '' ? $env : null);
        if (!is_string($server) || preg_match('#^https://[^/?\#@]+$|^http://(localhost|127\.0\.0\.1)(:\d+)?$#D', rtrim($server, '/')) !== 1) {
            throw new CliException('Use --server https://your-instance (or QUIETLINK_SERVER).');
        }
        $expires = Options::string($input, 'expires') ?? '1d';
        if ($expires !== Duration::NEVER && Duration::expirationSeconds($expires) === null) {
            throw new CliException('--expires must be 5m, 1h, 1d, 7d, 30d or never.');
        }
        $format = Options::string($input, 'format') ?? 'plain';
        if (!in_array($format, ['plain', 'markdown', 'code'], true)) {
            throw new CliException('--format must be plain, markdown or code.');
        }
        $language = Options::string($input, 'language');
        if ($language !== null && ($format !== 'code' || preg_match('/^[a-z0-9+#-]{1,32}$/D', $language) !== 1)) {
            throw new CliException('--language is only allowed with --format code and must be a short identifier.');
        }

        $file = Options::string($input, 'input');
        $passphraseFile = Options::string($input, 'passphrase-file');
        $passphraseStdin = Options::flag($input, 'passphrase-stdin');
        if ($passphraseStdin && $file === null) {
            throw new CliException('--passphrase-stdin requires --input: only one value can be read from stdin.');
        }
        if ($file !== null) {
            $text = @file_get_contents($file, false, null, 0, self::MAX_INPUT_BYTES + 1);
            if ($text === false) {
                throw new CliException('The input file cannot be read.');
            }
        } else {
            $text = $this->context->readStdin(self::MAX_INPUT_BYTES);
        }
        if (strlen($text) > self::MAX_INPUT_BYTES) {
            throw new CliException(self::TOO_LARGE);
        }
        if ($text === '' || !mb_check_encoding($text, 'UTF-8')) {
            throw new CliException('The text must be non-empty UTF-8.');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // The size limit applies to the serialized envelope, checked before any prompt.
        $envelope = json_encode([
            'format' => $format,
            'language' => $language,
            'template' => null,
            'text' => $text,
            'v' => 1,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($envelope) > self::MAX_ENVELOPE_BYTES) {
            sodium_memzero($envelope);
            throw new CliException(self::TOO_LARGE);
        }

        $passphrase = null;
        if (Options::flag($input, 'passphrase') || $passphraseFile !== null || $passphraseStdin) {
            $passphrase = (new PassphraseSource($this->context->prompt))->read(
                $passphraseFile,
                $passphraseStdin,
                $this->context->stdin,
                true,
            );
        }

        $prepared = ClientCrypto::prepare($envelope, $expires, Options::flag($input, 'read-once'), $passphrase, Argon2id::DEFAULT_MEMORY_KIB, Argon2id::DEFAULT_PASSES);
        if ($passphrase !== null) {
            sodium_memzero($passphrase);
        }
        sodium_memzero($envelope);

        $result = $this->context->api->create(rtrim($server, '/'), $prepared);
        $id = $result['id']->encoded();
        $origin = rtrim($server, '/');

        $output->writeln($origin . '/p/' . $id . '#' . Base64Url::encode($prepared->urlKey));
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errors->writeln('');
        $errors->writeln('Management link (permanently deletes the content; keep it private, never share it):');
        $errors->writeln($origin . '/manage/' . $id . '#' . Base64Url::encode($prepared->deletionToken));
        if ($result['expires_at'] !== null) {
            $errors->writeln('Expires at: ' . $result['expires_at']);
        }

        return Command::SUCCESS;
    }
}
