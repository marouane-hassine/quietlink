<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use QuietLink\Cli\ApiClient;
use QuietLink\Cli\CliApplication;
use QuietLink\Cli\CliContext;
use QuietLink\Cli\HttpResponse;
use QuietLink\Cli\Prompt;
use QuietLink\Cli\ShareLink;
use QuietLink\Cli\Transport;
use QuietLink\Tests\Support\BufferedConsoleOutput;
use QuietLink\Tests\Support\FakePrompt;
use QuietLink\Tests\Support\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;

#[CoversClass(CliApplication::class)]
#[CoversClass(ApiClient::class)]
#[CoversClass(ShareLink::class)]
final class CliTest extends KernelTestCase
{
    public int $requests = 0;

    /** AAD (base64url) substituted in status responses to simulate a malicious server. */
    public ?string $substituteAad = null;

    protected function setUp(): void
    {
        $this->bootInstance();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function cli(array $arguments, string $stdin, ?Prompt $prompt = null): array
    {
        $test = $this;
        $transport = new class ($test) implements Transport {
            public function __construct(private readonly CliTest $test)
            {
            }

            public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
            {
                return $this->test->forward($method, $url, $headers, $body);
            }
        };
        $input = fopen('php://memory', 'w+');
        self::assertIsResource($input);
        fwrite($input, $stdin);
        rewind($input);

        $application = new CliApplication(new CliContext(new ApiClient($transport), $prompt ?? new FakePrompt(), $input));
        $application->setAutoExit(false);
        $output = new BufferedConsoleOutput();
        $code = $application->doRun(new ArrayInput(['command' => array_shift($arguments)] + self::options($arguments)), $output);

        return [$code, $output->fetch(), $output->errors()];
    }

    /**
     * @param list<string> $arguments
     *
     * @return array<string, string|bool>
     */
    private static function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            $parts = explode('=', $argument, 2);
            $options[$parts[0]] = $parts[1] ?? true;
        }

        return $options;
    }

    /**
     * @param array<string, string> $headers
     */
    public function forward(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        ++$this->requests;
        $path = parse_url($url, PHP_URL_PATH);
        self::assertIsString($path);
        $response = $this->request($method, $path, $body, $headers);
        $responseHeaders = [];
        foreach ($response->headers->all() as $name => $values) {
            $responseHeaders[strtolower($name)] = $values[0] ?? '';
        }

        $content = (string) $response->getContent();
        if ($this->substituteAad !== null && str_ends_with($path, '/status')) {
            $data = json_decode($content, true);
            self::assertIsArray($data);
            $data['aad'] = $this->substituteAad;
            $content = (string) json_encode($data);
        }

        return new HttpResponse($response->getStatusCode(), $responseHeaders, $content);
    }

    /**
     * @param list<string> $options
     *
     * @return array{string, string}
     */
    private function createPaste(string $text, array $options = [], ?Prompt $prompt = null): array
    {
        [$code, $out, $err] = $this->cli(['create', '--server=https://paste.example.test', ...$options], $text, $prompt);
        self::assertSame(0, $code, $err);
        preg_match('#https://paste\.example\.test/manage/\S+#', $err, $match);

        return [trim($out), $match[0] ?? ''];
    }

    #[Group('EXG-CLI-001')]
    #[Group('EXG-CLI-002')]
    #[Group('EXG-CLI-003')]
    #[Group('EXG-CLI-013')]
    #[Group('EXG-CLI-014')]
    #[Group('EXG-CLI-004')]
    #[Group('EXG-CLI-016')]
    #[Group('EXG-TEST-008')]
    #[Group('EXG-TEST-066')]
    public function testCreateMetadataAndDecrypt(): void
    {
        [$share, $manage] = $this->createPaste("line one\r\nline two");

        self::assertMatchesRegularExpression('#^https://paste\.example\.test/p/[A-Za-z0-9_-]{32}\#[A-Za-z0-9_-]{43}$#', $share);
        self::assertStringNotContainsString($manage, $share);
        self::assertNotSame('', $manage);

        [$code, $metadata] = $this->cli(['metadata', '--url-stdin'], $share);
        self::assertSame(0, $code);
        self::assertStringContainsString('read_once: no', $metadata);
        self::assertStringNotContainsString('line one', $metadata);

        [$code, $plaintext] = $this->cli(['decrypt', '--url-stdin'], $share);
        self::assertSame(0, $code);
        self::assertSame("line one\nline two", $plaintext);
    }

    #[Group('EXG-CLI-005')]
    #[Group('EXG-CLI-011')]
    #[Group('EXG-TEST-065')]
    #[Group('EXG-TEST-066')]
    public function testReadOnceDecryptConsumesOnlyWithConfirmation(): void
    {
        [$share] = $this->createPaste('dummy secret', ['--read-once']);

        [$code, , $err] = $this->cli(['decrypt', '--url-stdin'], $share);
        self::assertSame(1, $code);
        self::assertStringContainsString('--yes', $err);

        [$code, $plaintext] = $this->cli(['decrypt', '--url-stdin', '--yes'], $share);
        self::assertSame(0, $code);
        self::assertSame('dummy secret', $plaintext);

        [$code, , $err] = $this->cli(['decrypt', '--url-stdin', '--yes'], $share);
        self::assertSame(1, $code);
        self::assertStringContainsString('unavailable', $err);
    }

    /**
     * Credentials in the server URL would be embedded in every share link (and such links are
     * rejected by decrypt); the URL is refused before anything is sent.
     */
    #[Group('EXG-CLI-013')]
    public function testServerUrlWithCredentialsIsRefused(): void
    {
        $this->requests = 0;
        [$code, $out, $err] = $this->cli(['create', '--server=https://user:dummy@paste.example.test'], 'x');

        self::assertNotSame(0, $code);
        self::assertStringNotContainsString('dummy@', $out . $err);
        self::assertSame(0, $this->requests);
    }

    #[Group('EXG-CLI-009')]
    #[Group('EXG-TEST-065')]
    public function testPassphraseFromProtectedFileAndLocalCheck(): void
    {
        $file = $this->tmp->path . '/pass';
        file_put_contents($file, "dummy passphrase\n");
        chmod($file, 0644);
        [$code, , $err] = $this->cli(['create', '--server=https://paste.example.test', '--passphrase-file=' . $file], 'x');
        self::assertSame(1, $code);
        self::assertStringContainsString('owner only', $err);

        chmod($file, 0600);
        [$share] = $this->createPaste('protected text', ['--read-once', '--passphrase-file=' . $file]);

        $wrong = $this->tmp->path . '/wrong';
        file_put_contents($wrong, 'not it');
        chmod($wrong, 0600);
        [$code, , $err] = $this->cli(['decrypt', '--url-stdin', '--yes', '--passphrase-file=' . $wrong], $share);
        self::assertSame(1, $code);
        self::assertStringContainsString('Incorrect passphrase', $err);

        [, $metadata] = $this->cli(['metadata', '--url-stdin'], $share);
        self::assertStringContainsString('state: available', $metadata, 'a wrong passphrase must not reserve the paste');

        [$code, $plaintext] = $this->cli(['decrypt', '--url-stdin', '--yes', '--passphrase-file=' . $file], $share);
        self::assertSame(0, $code);
        self::assertSame('protected text', $plaintext);
    }

    #[Group('EXG-CLI-007')]
    public function testPassphraseFromTerminalIsConfirmedAtCreation(): void
    {
        [$code, , $err] = $this->cli(['create', '--server=https://paste.example.test', '--passphrase'], 'x', new FakePrompt(true, ['one', 'two']));
        self::assertSame(1, $code);
        self::assertStringContainsString('do not match', $err);
    }

    #[Group('EXG-CRYPTO-042')]
    #[Group('EXG-CLI-001')]
    public function testSizeLimitAppliesToTheSerializedEnvelope(): void
    {
        // 200 000 control characters: far below 1 MiB of text, but each one is escaped to six
        // bytes in the JSON envelope, which then exceeds 1 MiB.
        [$code, $out, $err] = $this->cli(['create', '--server=https://paste.example.test'], str_repeat("\x01", 200000));

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('envelope', $err);
        self::assertStringContainsString('1 MiB', $err);
        self::assertSame(0, $this->requests);

        [$code, , $err] = $this->cli(['create', '--server=https://paste.example.test'], str_repeat('a', 1048577));
        self::assertSame(1, $code);
        self::assertStringContainsString('envelope', $err);
        self::assertSame(0, $this->requests);
    }

    #[Group('EXG-CLI-008')]
    #[Group('EXG-TEST-065')]
    public function testStdinCarriesOnlyOneValue(): void
    {
        [$code, , $err] = $this->cli(['decrypt', '--url-stdin', '--passphrase-stdin'], 'x');
        self::assertSame(1, $code);
        self::assertStringContainsString('cannot be combined', $err);

        [$code, , $err] = $this->cli(['create', '--server=https://paste.example.test', '--passphrase-stdin'], 'x');
        self::assertSame(1, $code);
        self::assertStringContainsString('--input', $err);
    }

    public function testDeleteWithManagementLink(): void
    {
        [$share, $manage] = $this->createPaste('to delete');

        [$code] = $this->cli(['delete', '--url-stdin', '--yes'], $manage);
        self::assertSame(0, $code);
        [$code, , $err] = $this->cli(['decrypt', '--url-stdin'], $share);
        self::assertSame(1, $code);
        self::assertStringContainsString('unavailable', $err);
    }

    #[Group('EXG-CRYPTO-027')]
    public function testAlteredLinkIsRejectedBeforeAnyRequest(): void
    {
        [$share] = $this->createPaste('x');
        $offset = strpos($share, '/p/');
        self::assertIsInt($offset);
        $altered = (string) preg_replace('#/p/.#', '/p/' . ($share[$offset + 3] === 'A' ? 'B' : 'A'), $share);
        $this->requests = 0;

        [$code, , $err] = $this->cli(['decrypt', '--url-stdin'], $altered);
        self::assertSame(1, $code);
        self::assertStringContainsString('altered', $err);
        self::assertSame(0, $this->requests);

        [$code, , $err] = $this->cli(['decrypt', '--url-stdin'], substr($share, 0, -5));
        self::assertSame(1, $code);
        self::assertStringContainsString('incomplete', $err);
    }

    #[Group('EXG-CRYPTO-051')]
    #[Group('EXG-TEST-058')]
    public function testMetadataFromAnotherPasteIsRejected(): void
    {
        [$other] = $this->createPaste('other content', ['--read-once']);
        [, $otherStatus] = $this->cli(['metadata', '--url-stdin'], $other);
        self::assertStringContainsString('read_once: yes', $otherStatus);
        $prepared = \QuietLink\Client\ClientCrypto::prepare('{"format":"plain","language":null,"template":null,"text":"x","v":1}', '1h', true);
        [$share] = $this->createPaste('target content');

        $this->substituteAad = $prepared->body['aad'];
        foreach ([['metadata', '--url-stdin'], ['decrypt', '--url-stdin', '--yes']] as $arguments) {
            $command = $arguments[0];
            [$code, $out, $err] = $this->cli($arguments, $share);
            self::assertSame(1, $code, $command);
            self::assertStringContainsString('Integrity error', $err, $command);
            self::assertStringNotContainsString('target content', $out);
        }
    }

    #[Group('EXG-CRYPTO-038')]
    #[Group('EXG-CLI-016')]
    public function testLineTerminatorsStayUnescapedLikeTheBrowserSerialization(): void
    {
        // JSON.stringify keeps U+2028/U+2029 raw (3 bytes each): 300 000 of them fit in a 1 MiB
        // envelope, whereas the \u2028 escape (6 bytes) would exceed it.
        $text = 'a' . str_repeat("\u{2028}\u{2029}", 150000);
        [$share] = $this->createPaste($text);

        [$code, $plaintext] = $this->cli(['decrypt', '--url-stdin'], $share);
        self::assertSame(0, $code);
        self::assertSame($text, $plaintext);
    }

    #[Group('EXG-CLI-011')]
    #[Group('EXG-CLI-008')]
    public function testDecryptWithoutTerminalSuggestsOnlyThePassphraseFile(): void
    {
        $file = $this->tmp->path . '/pass';
        file_put_contents($file, "dummy passphrase\n");
        chmod($file, 0600);
        [$share] = $this->createPaste('protected text', ['--passphrase-file=' . $file]);

        [$code, , $err] = $this->cli(['decrypt', '--url-stdin'], $share);
        self::assertSame(1, $code);
        self::assertStringContainsString('--passphrase-file', $err);
        self::assertStringNotContainsString('--passphrase-stdin', $err);
    }
}
