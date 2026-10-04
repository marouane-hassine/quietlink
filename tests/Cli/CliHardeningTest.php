<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Cli\CliException;
use QuietLink\Cli\OutputFile;
use QuietLink\Cli\StreamTransport;
use QuietLink\Cli\TransportException;
use QuietLink\Cli\TtyPrompt;
use QuietLink\Tests\Support\TempDirectory;

#[CoversClass(TtyPrompt::class)]
#[CoversClass(OutputFile::class)]
#[CoversClass(StreamTransport::class)]
final class CliHardeningTest extends TestCase
{
    private TempDirectory $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    /**
     * When echo cannot be disabled (stty missing or failing), the passphrase is not read: it
     * would appear on screen while typed.
     */
    #[Group('EXG-CLI-010')]
    public function testSecretIsNotReadWhenEchoCannotBeDisabled(): void
    {
        $tty = $this->tmp->path . '/tty';
        file_put_contents($tty, "typed secret\n");
        $prompt = new TtyPrompt($tty, 'false');

        try {
            $prompt->secret('Passphrase: ');
            self::fail('The passphrase was read with echo on.');
        } catch (CliException $e) {
            self::assertStringContainsString('--passphrase-file', $e->getMessage());
        }
    }

    /**
     * The decrypted output file never exists with permissions wider than 0600, even briefly.
     */
    #[Group('EXG-CLI-008')]
    public function testOutputFileIsCreatedPrivateWhateverTheUmask(): void
    {
        $previous = umask(0000);
        try {
            $path = $this->tmp->path . '/out.txt';
            OutputFile::write($path, 'dummy');
            self::assertSame(0600, fileperms($path) & 0777);
        } finally {
            umask($previous);
        }
    }

    #[Group('EXG-CLI-001')]
    public function testDisabledUrlStreamsGiveAnActionableMessage(): void
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d allow_url_fopen=0 -r ' . escapeshellarg(
            'require "' . dirname(__DIR__, 2) . '/vendor/autoload.php";'
            . 'try { (new QuietLink\Cli\StreamTransport())->send("GET", "http://127.0.0.1:9/x", [], null); }'
            . 'catch (QuietLink\Cli\TransportException $e) { echo $e->getMessage(); }',
        ), $output);

        self::assertStringContainsString('allow_url_fopen', implode("\n", $output));
        self::assertTrue(class_exists(TransportException::class));
    }
}
