<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Cli\ApiClient;
use QuietLink\Cli\CliException;
use QuietLink\Cli\HttpResponse;
use QuietLink\Cli\Transport;
use QuietLink\Cli\TransportException;
use QuietLink\Client\ClientCrypto;
use QuietLink\Crypto\DeletionToken;
use QuietLink\Crypto\Identifier;
use QuietLink\Encoding\Base64Url;

#[CoversClass(ApiClient::class)]
final class ApiClientRetryTest extends TestCase
{
    /**
     * @param list<HttpResponse|TransportException> $script
     *
     * @return Transport&object{calls: list<array{array<string, string>, string|null}>}
     */
    private static function transport(array $script): Transport
    {
        return new class ($script) implements Transport {
            /** @var list<array{array<string, string>, string|null}> */
            public array $calls = [];

            /**
             * @param list<HttpResponse|TransportException> $script
             */
            public function __construct(private array $script)
            {
            }

            public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
            {
                $this->calls[] = [$headers, $body];
                $next = array_shift($this->script);
                if ($next instanceof TransportException || $next === null) {
                    throw $next ?? new TransportException('exhausted');
                }

                return $next;
            }
        };
    }

    #[Group('EXG-CLI-017')]
    #[Group('EXG-CLI-019')]
    #[Group('EXG-TEST-055')]
    #[Group('EXG-TEST-056')]
    public function testRetriesReuseTheSameKeyAndBody(): void
    {
        $prepared = ClientCrypto::prepare('{"format":"plain","language":null,"template":null,"text":"x","v":1}', '1h', false);
        $id = Identifier::generate(Base64Url::decode(ClientCrypto::accessPublicKey($prepared->urlKey)), DeletionToken::hash($prepared->deletionToken));
        $transport = self::transport([
            new TransportException('timeout'),
            new TransportException('timeout'),
            new HttpResponse(200, [], (string) json_encode(['id' => Base64Url::encode($id), 'expires_at' => null])),
        ]);

        $result = (new ApiClient($transport))->create('https://paste.example.test', $prepared);

        self::assertSame($id, $result['id']->bytes());
        self::assertCount(3, $transport->calls);
        self::assertCount(1, array_unique(array_map(static fn (array $call): string => $call[0]['Idempotency-Key'] . '|' . $call[1], $transport->calls)));
    }

    #[Group('EXG-CLI-018')]
    public function testNoRetryOn422(): void
    {
        $prepared = ClientCrypto::prepare('{"format":"plain","language":null,"template":null,"text":"x","v":1}', '1h', false);
        $transport = self::transport([new HttpResponse(422, [], '{}')]);

        try {
            (new ApiClient($transport))->create('https://paste.example.test', $prepared);
            self::fail('422 must not be retried.');
        } catch (CliException $e) {
            self::assertStringContainsString('Internal error', $e->getMessage());
        }
        self::assertCount(1, $transport->calls);
    }

    #[Group('EXG-CLI-019')]
    public function testIdentifierNotMatchingTheContentIsRejected(): void
    {
        $prepared = ClientCrypto::prepare('{"format":"plain","language":null,"template":null,"text":"x","v":1}', '1h', false);
        $transport = self::transport([new HttpResponse(201, [], (string) json_encode(['id' => Base64Url::encode(random_bytes(24))]))]);

        $this->expectException(CliException::class);
        (new ApiClient($transport))->create('https://paste.example.test', $prepared);
    }
}
