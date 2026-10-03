<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use QuietLink\Encoding\Base64Url;
use QuietLink\EventSubscriber\CorsSubscriber;
use QuietLink\Tests\Support\KernelTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS for /api/v1 only, limited to http.cors_allowed_origins (§9.5, disabled by default).
 */
#[CoversClass(CorsSubscriber::class)]
final class CorsTest extends KernelTestCase
{
    private const ALLOWED = 'https://client.example.test';

    private function bootWithCors(): void
    {
        $this->bootInstance(['http' => ['cors_allowed_origins' => [self::ALLOWED, 'http://localhost:5173']]]);
    }

    private static function assertNoCorsHeaders(Response $response): void
    {
        foreach (array_keys($response->headers->all()) as $name) {
            self::assertStringStartsNotWith('access-control-', $name);
        }
    }

    private static function challengeUri(): string
    {
        return '/api/v1/pastes/' . Base64Url::encode(random_bytes(24)) . '/challenge';
    }

    /**
     * @param array<string, string> $headers
     */
    private function preflight(string $uri, string $origin, string $method = 'POST', array $headers = []): Response
    {
        return $this->request('OPTIONS', $uri, null, ['Origin' => $origin, 'Access-Control-Request-Method' => $method] + $headers);
    }

    #[Group('EXG-CONF-019')]
    public function testAllowedOriginReceivesExactOriginAndExposedRetryAfter(): void
    {
        $this->bootWithCors();
        $response = $this->request('POST', self::challengeUri(), '{"usage":"open"}', ['Origin' => self::ALLOWED]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::ALLOWED, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertContains('Origin', $response->getVary());
        self::assertSame('Retry-After', $response->headers->get('Access-Control-Expose-Headers'));
        self::assertNull($response->headers->get('Access-Control-Allow-Credentials'));
        self::assertNull($response->headers->get('Access-Control-Allow-Methods'));
    }

    #[Group('EXG-CONF-019')]
    public function testErrorResponsesCarryCorsHeadersForAllowedOrigins(): void
    {
        $this->bootWithCors();
        $response = $this->request('DELETE', '/api/v1/pastes/' . Base64Url::encode(random_bytes(24)), null, ['Origin' => 'http://localhost:5173']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('http://localhost:5173', $response->headers->get('Access-Control-Allow-Origin'));
    }

    #[Group('EXG-CONF-019')]
    public function testOtherOriginsGetNoCorsHeaders(): void
    {
        $this->bootWithCors();
        foreach (['https://other.example.test', self::ALLOWED . '/', 'https://CLIENT.example.test', 'null'] as $origin) {
            $response = $this->request('POST', self::challengeUri(), '{"usage":"open"}', ['Origin' => $origin]);
            self::assertSame(200, $response->getStatusCode());
            self::assertNoCorsHeaders($response);
            self::assertContains('Origin', $response->getVary(), $origin);
        }
    }

    #[Group('EXG-CONF-019')]
    public function testPreflightIsAnsweredForAllowedOrigins(): void
    {
        $this->bootWithCors();
        $response = $this->preflight('/api/v1/pastes', self::ALLOWED, 'POST', ['Access-Control-Request-Headers' => 'content-type,idempotency-key']);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        self::assertSame(self::ALLOWED, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('POST', $response->headers->get('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Idempotency-Key, X-Deletion-Token', $response->headers->get('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->headers->get('Access-Control-Max-Age'));
        self::assertContains('Origin', $response->getVary());
        self::assertNull($response->headers->get('Access-Control-Allow-Credentials'));
        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));

        $delete = $this->preflight('/api/v1/pastes/' . Base64Url::encode(random_bytes(24)), self::ALLOWED, 'DELETE');
        self::assertSame(204, $delete->getStatusCode());
        self::assertSame('DELETE', $delete->headers->get('Access-Control-Allow-Methods'));
    }

    #[Group('EXG-CONF-019')]
    public function testPreflightIsRefusedOtherwise(): void
    {
        $this->bootWithCors();
        $cases = [
            'other origin' => $this->preflight('/api/v1/pastes', 'https://other.example.test'),
            'method not offered' => $this->preflight('/api/v1/pastes', self::ALLOWED, 'PUT'),
            'no request method' => $this->request('OPTIONS', '/api/v1/pastes', null, ['Origin' => self::ALLOWED]),
            'no origin' => $this->request('OPTIONS', '/api/v1/pastes', null, ['Access-Control-Request-Method' => 'POST']),
        ];
        foreach ($cases as $case => $response) {
            self::assertSame(405, $response->getStatusCode(), $case);
            self::assertSame('POST', $response->headers->get('Allow'), $case);
            self::assertNull($response->headers->get('Access-Control-Allow-Methods'), $case);
        }
        self::assertSame(404, $this->preflight('/api/v1/unknown', self::ALLOWED)->getStatusCode());
    }

    #[Group('EXG-CONF-019')]
    public function testNonApiPathsNeverGetCorsHeaders(): void
    {
        $this->bootWithCors();
        $health = $this->request('GET', '/healthz', null, ['Origin' => self::ALLOWED]);
        self::assertSame(200, $health->getStatusCode());
        self::assertNoCorsHeaders($health);

        $preflight = $this->preflight('/healthz', self::ALLOWED, 'GET');
        self::assertSame(405, $preflight->getStatusCode());
        self::assertNoCorsHeaders($preflight);

        self::assertNoCorsHeaders($this->request('GET', '/', null, ['Origin' => self::ALLOWED]));
    }

    #[Group('EXG-CONF-019')]
    public function testCorsIsDisabledByDefault(): void
    {
        $this->bootInstance();
        $preflight = $this->preflight('/api/v1/pastes', self::ALLOWED);
        self::assertSame(405, $preflight->getStatusCode());
        self::assertNoCorsHeaders($preflight);

        $response = $this->request('POST', self::challengeUri(), '{"usage":"open"}', ['Origin' => self::ALLOWED]);
        self::assertNoCorsHeaders($response);
        self::assertNotContains('Origin', $response->getVary());
    }
}
