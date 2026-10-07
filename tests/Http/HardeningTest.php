<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Http;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use QuietLink\Client\ClientCrypto;
use QuietLink\Encoding\Base64Url;
use QuietLink\Tests\Support\KernelTestCase;
use QuietLink\Theme\TokenThemeBuilder;
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
    #[Group('EXG-SEC-097')]
    #[Group('EXG-MD-011')]
    #[Group('EXG-MD-012')]
    #[Group('EXG-TEST-012')]
    #[Group('EXG-TEST-101')]
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

    /**
     * A method-override header cannot turn a POST into a DELETE (or any other method):
     * proxy rules filtering by method and the access log must see the real method.
     */
    #[Group('EXG-SEC-058')]
    public function testMethodOverrideHeaderIsIgnored(): void
    {
        $this->boot();
        $prepared = $this->createPaste('{"format":"plain","language":null,"template":null,"text":"x","v":1}', true);
        $id = self::json($this->request('POST', '/api/v1/pastes', $prepared->json(), ['Idempotency-Key' => $prepared->idempotencyKey]))['id'];
        self::assertIsString($id);

        $response = $this->request('POST', "/api/v1/pastes/$id", '', [
            'X-HTTP-Method-Override' => 'DELETE',
            'X-Deletion-Token' => \QuietLink\Encoding\Base64Url::encode($prepared->deletionToken),
            'Content-Type' => 'text/plain',
        ]);
        self::assertSame(405, $response->getStatusCode());
        self::assertNotSame(404, $this->request('POST', "/api/v1/pastes/$id/challenge", '{"usage":"status"}')->getStatusCode());
    }

    #[Group('EXG-API-016')]
    #[Group('EXG-TEST-054')]
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
    #[Group('EXG-TEST-050')]
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

    #[Group('EXG-CONF-009')]
    #[Group('EXG-CONF-022')]
    #[Group('EXG-THEME-012')]
    public function testThemeTokensChangeIsRefusedUntilBootRunsAgain(): void
    {
        $this->bootInstance(['theme' => ['custom_tokens_file' => 'brand.json']], true, ['themes/brand.json' => '{"light":{"radius":"0.5rem"}}']);
        self::assertSame(200, $this->request('GET', '/healthz')->getStatusCode());

        file_put_contents($this->tmp->path . '/config/themes/brand.json', '{"light":{"radius":"1rem"}}');

        self::assertSame(503, $this->request('GET', '/healthz')->getStatusCode());
    }

    /**
     * Without the Nginx alias (Apache, shared hosting), the generated theme stylesheet is served
     * by the application from storage.generated_assets_dir; only that file name pattern.
     */
    #[Group('EXG-THEME-012')]
    #[Group('EXG-DEPLOY-001')]
    public function testGeneratedThemeStylesheetIsServedByTheApplication(): void
    {
        $this->bootInstance();
        // As written by app:boot (TokenThemeBuilder).
        $dir = $this->config->storage->generatedAssetsDir;
        @mkdir($dir, 0755, true);
        file_put_contents($dir . '/tokens.0123456789abcdef.css', ":root {\n  --ql-radius: 0.5rem;\n}\n");
        file_put_contents($dir . '/' . TokenThemeBuilder::MANIFEST, '{"file":"tokens.0123456789abcdef.css"}');
        $page = (string) $this->request('GET', '/', null, ['Accept' => 'text/html'])->getContent();
        self::assertSame(1, preg_match('#/themes/generated/(tokens\.[0-9a-f]{16}\.css)#', $page, $m));

        $response = $this->request('GET', '/themes/generated/' . $m[1]);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/css', (string) $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('0.5rem', (string) $response->getContent());

        self::assertSame(404, $this->request('GET', '/themes/generated/tokens.0000000000000000.css')->getStatusCode());
        self::assertSame(404, $this->request('GET', '/themes/generated/..%2Fconfig.php')->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenConfigurations(): iterable
    {
        yield 'throwing file' => ["<?php\n\nthrow new \\RuntimeException('boom');\n"];
        yield 'syntax error' => ["<?php\n\nreturn [\n"];
        yield 'non-string list item' => ["<?php\n\nreturn ['app' => ['public_url' => 'https://paste.example.test'], 'http' => ['trusted_proxies' => [1]]];\n"];
    }

    #[DataProvider('brokenConfigurations')]
    #[Group('EXG-CONF-009')]
    #[Group('EXG-CONF-021')]
    #[Group('EXG-API-050')]
    public function testBrokenConfigurationYieldsGeneric503WithSecurityHeaders(string $content): void
    {
        $this->boot();
        $file = $this->tmp->path . '/config/config.php';
        file_put_contents($file, $content);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        $health = $this->request('GET', '/healthz');
        self::assertSame(503, $health->getStatusCode());
        self::assertSame(['status' => 'unavailable'], self::json($health));
        foreach (['/', '/api/v1/pastes'] as $uri) {
            $response = $uri === '/' ? $this->request('GET', $uri) : $this->request('POST', $uri, '{}', ['Idempotency-Key' => 'x']);
            self::assertSame(503, $response->getStatusCode(), $uri);
            self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'), $uri);
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $uri);
            self::assertStringNotContainsString('boom', (string) $response->getContent());
        }
    }

    #[Group('EXG-SEC-044')]
    #[Group('EXG-SEC-045')]
    #[Group('EXG-SEC-074')]
    #[Group('EXG-SEC-092')]
    #[Group('EXG-TEST-043')]
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

    /**
     * Only X-Forwarded-For and X-Forwarded-Proto are read from trusted proxies: a client-sent
     * Forwarded header that contradicts them is ignored instead of failing the request.
     */
    #[Group('EXG-SEC-044')]
    public function testClientForwardedHeaderCannotBreakRequestsBehindAProxy(): void
    {
        $this->boot(['http' => ['trusted_proxies' => ['10.0.0.0/8']]]);
        $response = $this->request('GET', '/healthz', null, ['X-Forwarded-For' => '198.51.100.1', 'Forwarded' => 'for=192.0.2.99', 'X-Forwarded-Host' => 'evil.example'], ['HTTPS' => '', 'REMOTE_ADDR' => '10.1.2.3']);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Symfony redirects "/path/" to "/path" with a Location built from the Host header and the
     * detected scheme: an open redirect, and an http downgrade of "/p/<id>/#key" behind a
     * misconfigured proxy. Such paths get the uniform 404 instead.
     */
    #[Group('EXG-SEC-058')]
    #[Group('EXG-URL-015')]
    public function testTrailingSlashIsNotRedirectedFromTheHostHeader(): void
    {
        $this->boot();
        foreach (['/how-it-works/', '/p/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA/', '/healthz/', '/api/v1/pastes/x/status/'] as $path) {
            $response = $this->request('GET', $path, null, [], ['HTTP_HOST' => 'evil.example']);
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertNull($response->headers->get('Location'), $path);
        }
        self::assertSame(200, $this->request('GET', '/')->getStatusCode());
    }

    /**
     * §10 step 2, §7.5: Idempotency-Key conflicts (422) count against the replay budget, like
     * replays, so keys cannot be probed at will.
     */
    #[Group('EXG-API-018')]
    public function testIdempotencyConflictsAreRateLimited(): void
    {
        $this->boot(['http' => ['rate_limits' => ['create_replay' => ['limit' => 1, 'interval' => 600]]]]);
        $first = $this->createPaste('{"format":"plain","language":null,"template":null,"text":"a","v":1}');
        $other = ClientCrypto::prepare('{"format":"plain","language":null,"template":null,"text":"b","v":1}', '1h', false, null, 19456, 2);

        self::assertSame(422, $this->request('POST', '/api/v1/pastes', $other->json(), ['Idempotency-Key' => $first->idempotencyKey])->getStatusCode());
        self::assertSame(429, $this->request('POST', '/api/v1/pastes', $other->json(), ['Idempotency-Key' => $first->idempotencyKey])->getStatusCode());
    }

    /** The uniform 404 of a trailing slash keeps HSTS behind a trusted TLS proxy. */
    #[Group('EXG-SEC-044')]
    public function testTrailingSlashNotFoundKeepsHstsBehindATrustedProxy(): void
    {
        $this->boot(['http' => ['trusted_proxies' => ['10.0.0.0/8']]]);
        // Static setting left by earlier requests of this process would hide the ordering.
        \Symfony\Component\HttpFoundation\Request::setTrustedProxies([], 0);
        $response = $this->request('GET', '/how-it-works/', null, ['X-Forwarded-Proto' => 'https'], ['HTTPS' => '', 'REMOTE_ADDR' => '10.1.2.3']);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('max-age=', (string) $response->headers->get('Strict-Transport-Security'));
    }
}
