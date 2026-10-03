<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Http;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use QuietLink\Client\ClientCrypto;
use QuietLink\Encoding\Base64Url;
use QuietLink\Tests\Support\KernelTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
final class HardeningTest extends KernelTestCase
{
    private const MARKER = 'DUMMY-PLAINTEXT-MARKER-7f3a';

    /**
     * @param array<string, array<string, mixed>> $overrides
     */
    private function boot(array $overrides = []): void
    {
        $this->bootInstance($overrides);
    }

    private function createPaste(string $envelope, bool $readOnce = false, ?string $passphrase = null): \QuietLink\Client\PreparedPaste
    {
        $prepared = ClientCrypto::prepare($envelope, '1h', $readOnce, $passphrase, 19456, 2);
        $response = $this->request('POST', '/api/v1/pastes', $prepared->json(), ['Idempotency-Key' => $prepared->idempotencyKey]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $prepared;
    }

    /**
     * @return list<string> contents of every file below $dir
     */
    private static function contents(string $dir): array
    {
        $contents = [];
        if (!is_dir($dir)) {
            return $contents;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $contents[] = (string) file_get_contents($file->getPathname());
            }
        }

        return $contents;
    }

    #[Group('EXG-CRYPTO-041')]
    #[Group('EXG-CRYPTO-054')]
    #[Group('EXG-URL-013')]
    #[Group('EXG-API-028')]
    #[Group('EXG-SEC-027')]
    #[Group('EXG-OBS-007')]
    #[Group('EXG-CACHE-001')]
    #[Group('EXG-CACHE-003')]
    #[Group('EXG-CACHE-006')]
    public function testStorageAndCachesNeverHoldSecretsOrClientAddresses(): void
    {
        $this->boot();
        $envelope = '{"format":"markdown","language":null,"template":"credentials","text":"' . self::MARKER . '","v":1}';
        $prepared = $this->createPaste($envelope, true, 'dummy-audit-passphrase');
        $id = self::json($this->request('POST', '/api/v1/pastes', $prepared->json(), ['Idempotency-Key' => $prepared->idempotencyKey]))['id'];
        self::assertIsString($id);
        $this->request('POST', "/api/v1/pastes/$id/challenge", '{"usage":"status"}');

        $forbidden = [
            self::MARKER,
            'dummy-audit-passphrase',
            Base64Url::encode($prepared->urlKey),
            Base64Url::encode($prepared->deletionToken),
            $prepared->idempotencyKey,
            'credentials',
            'markdown',
            '192.0.2.10',
        ];
        $files = [...self::contents($this->tmp->path . '/data'), ...self::contents(dirname(__DIR__, 2) . '/var/cache/test')];
        self::assertNotEmpty($files);
        foreach ($files as $content) {
            foreach ($forbidden as $value) {
                self::assertStringNotContainsString($value, $content);
            }
        }
        foreach (self::contents($this->tmp->path . '/data/idempotency') as $record) {
            self::assertStringNotContainsString($prepared->body['ciphertext'], $record);
            self::assertStringNotContainsString($prepared->body['deletion_hash'], $record);
        }
    }

    #[Group('EXG-CONF-019')]
    public function testNoCorsHeadersByDefault(): void
    {
        $this->boot();
        $response = $this->request('POST', '/api/v1/pastes/' . Base64Url::encode(random_bytes(24)) . '/challenge', '{"usage":"open"}', ['Origin' => 'https://other.example.test']);

        self::assertSame(200, $response->getStatusCode());
        foreach (array_keys($response->headers->all()) as $name) {
            self::assertStringStartsNotWith('access-control-', $name);
        }
    }

    #[Group('EXG-API-049')]
    public function testNoGetMethodChangesState(): void
    {
        $this->boot();
        $prepared = $this->createPaste('{"format":"plain","language":null,"template":null,"text":"x","v":1}', true);
        $id = self::json($this->request('POST', '/api/v1/pastes', $prepared->json(), ['Idempotency-Key' => $prepared->idempotencyKey]))['id'];
        self::assertIsString($id);

        foreach (['', '/open', '/consume', '/status', '/challenge'] as $suffix) {
            self::assertContains($this->request('GET', "/api/v1/pastes/$id$suffix")->getStatusCode(), [404, 405], $suffix);
        }
        $challenge = self::json($this->request('POST', "/api/v1/pastes/$id/challenge", '{"usage":"status"}'))['challenge'];
        self::assertIsString($challenge);
        $status = self::json($this->request('POST', "/api/v1/pastes/$id/status", (string) json_encode([
            'challenge' => $challenge,
            'access_pk' => ClientCrypto::accessPublicKey($prepared->urlKey),
            'signature' => ClientCrypto::prove($prepared->accessSeed, $challenge),
        ])));
        self::assertSame('available', $status['state']);
    }

    #[Group('EXG-API-016')]
    public function testReplaySucceedsWhenCreationLimitsWouldRefuse(): void
    {
        $this->boot(['http' => ['rate_limits' => ['create' => ['limit' => 1, 'interval' => 600]]], 'storage' => ['max_items' => 1]]);
        $prepared = $this->createPaste('{"format":"plain","language":null,"template":null,"text":"x","v":1}');

        $replay = $this->request('POST', '/api/v1/pastes', $prepared->json(), ['Idempotency-Key' => $prepared->idempotencyKey]);
        self::assertSame(200, $replay->getStatusCode());

        $other = ClientCrypto::prepare('{"format":"plain","language":null,"template":null,"text":"y","v":1}', '1h', false);
        self::assertSame(429, $this->request('POST', '/api/v1/pastes', $other->json(), ['Idempotency-Key' => $other->idempotencyKey])->getStatusCode());
    }

    #[Group('EXG-CONF-010')]
    #[Group('EXG-CONF-022')]
    #[Group('EXG-CACHE-014')]
    #[Group('EXG-CACHE-015')]
    public function testConfigurationChangeIsRefusedUntilBootRunsAgain(): void
    {
        $this->boot();
        self::assertSame(200, $this->request('GET', '/healthz')->getStatusCode());

        $file = $this->tmp->path . '/config/config.php';
        file_put_contents($file, str_replace("'warning'", "'error'", (string) file_get_contents($file)));
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        self::assertSame(503, $this->request('GET', '/healthz')->getStatusCode());
        self::assertSame(503, $this->request('POST', '/api/v1/pastes', '{}', ['Idempotency-Key' => 'x'])->getStatusCode());
    }

    #[Group('EXG-SEC-044')]
    #[Group('EXG-SEC-045')]
    #[Group('EXG-SEC-074')]
    public function testForwardedHeadersAreHonouredOnlyFromTrustedProxies(): void
    {
        $this->boot(['http' => ['trusted_proxies' => ['10.0.0.0/8'], 'rate_limits' => ['health' => ['limit' => 1, 'interval' => 600]]]]);
        $plain = ['HTTPS' => '', 'REMOTE_ADDR' => '10.1.2.3'];

        $trusted = $this->request('GET', '/healthz', null, ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.1'], $plain);
        self::assertStringStartsWith('max-age=', (string) $trusted->headers->get('Strict-Transport-Security'));
        // Each forwarded client has its own rate limiting key.
        self::assertSame(200, $this->request('GET', '/healthz', null, ['X-Forwarded-For' => '198.51.100.2'], $plain)->getStatusCode());
        self::assertSame(429, $this->request('GET', '/healthz', null, ['X-Forwarded-For' => '198.51.100.1'], $plain)->getStatusCode());

        $spoofed = $this->request('GET', '/healthz', null, ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.9'], ['HTTPS' => '', 'REMOTE_ADDR' => '203.0.113.5']);
        self::assertNull($spoofed->headers->get('Strict-Transport-Security'));
        self::assertStringNotContainsString('upgrade-insecure-requests', (string) $spoofed->headers->get('Content-Security-Policy'));
    }
}
