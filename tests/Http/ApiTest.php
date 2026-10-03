<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Http;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use QuietLink\Client\ClientCrypto;
use QuietLink\Client\PreparedPaste;
use QuietLink\Crypto\Aad;
use QuietLink\Encoding\Base64Url;
use QuietLink\Tests\Support\KernelTestCase;

#[CoversNothing]
final class ApiTest extends KernelTestCase
{
    private const ENVELOPE = '{"format":"plain","language":null,"template":null,"text":"dummy","v":1}';

    protected function setUp(): void
    {
        $this->bootInstance();
    }

    /**
     * @return array{PreparedPaste, string}
     */
    private function create(bool $readOnce = false): array
    {
        $prepared = ClientCrypto::prepare(self::ENVELOPE, '1h', $readOnce);
        $response = $this->request('POST', '/api/v1/pastes', $prepared->json(), ['Idempotency-Key' => $prepared->idempotencyKey]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = self::json($response)['id'];
        self::assertIsString($id);

        return [$prepared, $id];
    }

    /**
     * @return array<string, string>
     */
    private function proof(PreparedPaste $prepared, string $id, string $usage): array
    {
        $challenge = self::json($this->request('POST', "/api/v1/pastes/$id/challenge", json_encode(['usage' => $usage], JSON_THROW_ON_ERROR)))['challenge'];
        self::assertIsString($challenge);

        return [
            'challenge' => $challenge,
            'access_pk' => ClientCrypto::accessPublicKey($prepared->urlKey),
            'signature' => ClientCrypto::prove($prepared->accessSeed, $challenge),
        ];
    }

    #[Group('EXG-API-012')]
    #[Group('EXG-API-002')]
    #[Group('EXG-API-003')]
    #[Group('EXG-CRYPTO-018')]
    #[Group('EXG-CRYPTO-050')]
    public function testCreateReturnsServerAssignedIdentifierAndTimes(): void
    {
        $prepared = ClientCrypto::prepare(self::ENVELOPE, '1h', false);
        $response = $this->request('POST', '/api/v1/pastes', $prepared->json(), ['Idempotency-Key' => $prepared->idempotencyKey]);
        $data = self::json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', self::str($data, 'id'));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', self::str($data, 'expires_at'));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', self::str($data, 'server_time'));
        self::assertSame('/p/' . self::str($data, 'id'), $data['share_path']);

        $replay = $this->request('POST', '/api/v1/pastes', $prepared->json(), ['Idempotency-Key' => $prepared->idempotencyKey]);
        self::assertSame(200, $replay->getStatusCode());
        self::assertSame($data['id'], self::json($replay)['id']);
    }

    #[Group('EXG-SEC-046')]
    #[Group('EXG-SEC-054')]
    #[Group('EXG-SEC-055')]
    #[Group('EXG-SEC-056')]
    #[Group('EXG-SEC-057')]
    #[Group('EXG-SEC-058')]
    #[Group('EXG-SEC-045')]
    #[Group('EXG-SEC-060')]
    #[Group('EXG-API-048')]
    #[Group('EXG-API-050')]
    #[Group('EXG-CACHE-002')]
    #[Group('EXG-CACHE-016')]
    #[Group('EXG-SEC-105')]
    #[Group('EXG-SEC-087')]
    #[Group('EXG-SEC-051')]
    #[Group('EXG-SEC-052')]
    #[Group('EXG-TEST-062')]
    #[Group('EXG-TEST-104')]
    public function testSecurityHeadersAreSetOnEveryResponse(): void
    {
        foreach ([$this->request('GET', '/healthz'), $this->request('POST', '/api/v1/pastes/x/open', '{}')] as $response) {
            $csp = (string) $response->headers->get('Content-Security-Policy');
            self::assertStringContainsString("default-src 'none'", $csp);
            self::assertStringContainsString("frame-ancestors 'none'", $csp);
            self::assertStringContainsString("connect-src 'self'", $csp);
            self::assertStringNotContainsString('unsafe-inline', $csp);
            self::assertStringNotContainsString('unsafe-eval', $csp);
            self::assertStringNotContainsString('manifest-src', $csp);
            self::assertStringContainsString("require-trusted-types-for 'script'", $csp);
            self::assertStringContainsString('trusted-types dompurify quietlink-worker', $csp);
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
            self::assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
            self::assertSame('same-origin', $response->headers->get('Cross-Origin-Opener-Policy'));
            self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
            self::assertStringStartsWith('max-age=31536000', (string) $response->headers->get('Strict-Transport-Security'));
            self::assertFalse($response->headers->has('Set-Cookie'));
        }
    }

    #[Group('EXG-SEC-025')]
    #[Group('EXG-SEC-078')]
    #[Group('EXG-API-044')]
    #[Group('EXG-API-046')]
    #[Group('EXG-SEC-108')]
    #[Group('EXG-SEC-022')]
    #[Group('EXG-TEST-039')]
    #[Group('EXG-TEST-099')]
    public function testUnavailabilityIsUniform(): void
    {
        [$prepared, $id] = $this->create();
        $unknown = Base64Url::encode(random_bytes(24));
        $bodies = [];
        foreach (['/api/v1/pastes/' . $unknown . '/open', '/api/v1/pastes/' . $id . '/open', '/api/v1/pastes/bad/open'] as $uri) {
            $foreign = ClientCrypto::prepare(self::ENVELOPE, '1h', false);
            $response = $this->request('POST', $uri, json_encode($this->proof($foreign, $id, 'open'), JSON_THROW_ON_ERROR));
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
            $bodies[] = $response->getContent();
        }
        self::assertCount(1, array_unique($bodies));
        self::assertStringNotContainsString($id, (string) $bodies[0]);
    }

    #[Group('EXG-API-042')]
    #[Group('EXG-API-044')]
    public function testUnsupportedMethodAnswers405WithAllowHeader(): void
    {
        $id = Base64Url::encode(random_bytes(24));
        foreach (['/api/v1/pastes' => 'POST', "/api/v1/pastes/$id" => 'DELETE', "/api/v1/pastes/$id/open" => 'POST', '/healthz' => 'GET'] as $path => $allowed) {
            $response = $this->request('PUT', $path, '{}');

            self::assertSame(405, $response->getStatusCode(), $path);
            self::assertSame($allowed, $response->headers->get('Allow'), $path);
            self::assertSame('application/problem+json', $response->headers->get('Content-Type'), $path);
        }
    }

    #[Group('EXG-SEC-075')]
    #[Group('EXG-SEC-086')]
    #[Group('EXG-SEC-107')]
    public function testOversizedBodyIsRejectedBeforeParsing(): void
    {
        $response = $this->request('POST', '/api/v1/pastes', str_repeat('a', 1441793), ['Idempotency-Key' => 'x']);

        self::assertSame(413, $response->getStatusCode());
    }

    #[Group('EXG-SEC-077')]
    #[Group('EXG-SEC-002')]
    #[Group('EXG-API-045')]
    #[Group('EXG-TEST-031')]
    public function testOnlyJsonBodiesAreAccepted(): void
    {
        foreach (['multipart/form-data; boundary=x', 'text/plain', 'application/x-www-form-urlencoded'] as $type) {
            $response = $this->request('POST', '/api/v1/pastes', 'x', ['Content-Type' => $type, 'Idempotency-Key' => 'x']);
            self::assertSame(415, $response->getStatusCode(), $type);
        }
    }

    #[Group('EXG-API-004')]
    #[Group('EXG-API-006')]
    #[Group('EXG-API-031')]
    #[Group('EXG-API-032')]
    #[Group('EXG-API-033')]
    #[Group('EXG-API-034')]
    #[Group('EXG-API-035')]
    #[Group('EXG-API-037')]
    #[Group('EXG-API-047')]
    #[Group('EXG-LIFE-007')]
    #[Group('EXG-LIFE-011')]
    public function testFullReadOnceFlowOverHttp(): void
    {
        [$prepared, $id] = $this->create(readOnce: true);
        $rid = Base64Url::encode(random_bytes(16));

        $status = self::json($this->request('POST', "/api/v1/pastes/$id/status", json_encode($this->proof($prepared, $id, 'status'), JSON_THROW_ON_ERROR)));
        self::assertSame('available', $status['state']);

        $open = $this->request('POST', "/api/v1/pastes/$id/open", json_encode($this->proof($prepared, $id, 'open') + ['reservation_id' => $rid], JSON_THROW_ON_ERROR));
        self::assertSame(200, $open->getStatusCode());
        $opened = self::json($open);
        $aad = Aad::fromBytes(Base64Url::decode(self::str($opened, 'aad')));
        self::assertSame(self::ENVELOPE, ClientCrypto::decrypt($prepared->urlKey, null, $aad, Base64Url::decode(self::str($opened, 'nonce')), Base64Url::decode(self::str($opened, 'ciphertext'))));

        $conflict = $this->request('POST', "/api/v1/pastes/$id/open", json_encode($this->proof($prepared, $id, 'open') + ['reservation_id' => Base64Url::encode(random_bytes(16))], JSON_THROW_ON_ERROR));
        self::assertSame(409, $conflict->getStatusCode());
        self::assertNotNull($conflict->headers->get('Retry-After'));

        $consume = json_encode([
            'access_pk' => ClientCrypto::accessPublicKey($prepared->urlKey),
            'reservation_id' => $rid,
            'challenge' => $opened['consume_challenge'],
            'signature' => ClientCrypto::prove((string) $prepared->consumeSeed, self::str($opened, 'consume_challenge')),
        ], JSON_THROW_ON_ERROR);
        self::assertSame(200, $this->request('POST', "/api/v1/pastes/$id/consume", $consume)->getStatusCode());
        self::assertSame(200, $this->request('POST', "/api/v1/pastes/$id/consume", $consume)->getStatusCode());
        self::assertSame(404, $this->request('POST', "/api/v1/pastes/$id/open", json_encode($this->proof($prepared, $id, 'open') + ['reservation_id' => $rid], JSON_THROW_ON_ERROR))->getStatusCode());
    }

    #[Group('EXG-LIFE-009')]
    #[Group('EXG-API-005')]
    #[Group('EXG-URL-014')]
    #[Group('EXG-TEST-068')]
    public function testDeletionOverHttp(): void
    {
        [$prepared, $id] = $this->create();

        self::assertSame(404, $this->request('DELETE', "/api/v1/pastes/$id", null, ['X-Deletion-Token' => Base64Url::encode(random_bytes(32))])->getStatusCode());
        self::assertSame(204, $this->request('DELETE', "/api/v1/pastes/$id", null, ['X-Deletion-Token' => Base64Url::encode($prepared->deletionToken)])->getStatusCode());
        self::assertSame(404, $this->request('POST', "/api/v1/pastes/$id/open", json_encode($this->proof($prepared, $id, 'open'), JSON_THROW_ON_ERROR))->getStatusCode());
    }

    #[Group('EXG-SEC-074')]
    public function testCreationIsRateLimited(): void
    {
        $this->tearDown();
        $this->bootInstance(['http' => ['rate_limits' => ['create' => ['limit' => 2, 'interval' => 600]]]]);
        $this->create();
        $this->create();
        $prepared = ClientCrypto::prepare(self::ENVELOPE, '1h', false);
        $response = $this->request('POST', '/api/v1/pastes', $prepared->json(), ['Idempotency-Key' => $prepared->idempotencyKey]);

        self::assertSame(429, $response->getStatusCode());
        self::assertNotNull($response->headers->get('Retry-After'));
    }

    #[Group('EXG-SEC-074')]
    #[Group('EXG-TEST-043')]
    #[Group('EXG-TEST-099')]
    public function testPerPasteLimitCountsOnlyValidProofs(): void
    {
        $this->tearDown();
        $this->bootInstance(['http' => ['rate_limits' => ['status_per_paste' => ['limit' => 2, 'interval' => 60]]]]);
        [$prepared, $id] = $this->create();
        $foreign = ClientCrypto::prepare(self::ENVELOPE, '1h', false);
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(404, $this->request('POST', "/api/v1/pastes/$id/status", json_encode($this->proof($foreign, $id, 'status'), JSON_THROW_ON_ERROR))->getStatusCode());
        }
        for ($i = 0; $i < 2; ++$i) {
            self::assertSame(200, $this->request('POST', "/api/v1/pastes/$id/status", json_encode($this->proof($prepared, $id, 'status'), JSON_THROW_ON_ERROR))->getStatusCode());
        }
        self::assertSame(429, $this->request('POST', "/api/v1/pastes/$id/status", json_encode($this->proof($prepared, $id, 'status'), JSON_THROW_ON_ERROR))->getStatusCode());
    }

    #[Group('EXG-CONF-009')]
    #[Group('EXG-CONF-017')]
    #[Group('EXG-TEST-050')]
    public function testMissingBootMarkerAnswers503(): void
    {
        $this->tearDown();
        $this->bootInstance([], false);

        self::assertSame(503, $this->request('GET', '/healthz')->getStatusCode());
        self::assertSame(503, $this->request('POST', '/api/v1/pastes', '{}', ['Idempotency-Key' => 'x'])->getStatusCode());
    }

    #[Group('EXG-API-041')]
    #[Group('EXG-DEPLOY-008')]
    public function testHealthIsMinimal(): void
    {
        $response = $this->request('GET', '/healthz');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok'], self::json($response));
    }
}
