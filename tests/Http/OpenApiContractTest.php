<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Http;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use QuietLink\Encoding\Base64Url;
use QuietLink\Tests\Support\KernelTestCase;
use ReflectionClass;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lightweight contract test between docs/openapi.yaml and the code.
 *
 * No YAML library is installed (and none is added): the document is read line by line, relying
 * on its fixed two-space indentation. Checked: every API route is documented with its methods
 * and nothing else is; the documented status codes of each operation are exactly those the code
 * can emit; real responses use documented codes; every local $ref resolves.
 */
#[CoversNothing]
final class OpenApiContractTest extends KernelTestCase
{
    private const DOCUMENT = __DIR__ . '/../../docs/openapi.yaml';

    /**
     * Status codes each operation can emit, read from the controllers, PasteService and the
     * exception mapping (src/EventSubscriber/ExceptionSubscriber.php). 500 (unexpected error)
     * and 503 (invalid configuration, storage unavailable) are possible everywhere; 405 and the
     * 404 of an unknown path are global failure modes documented in the description.
     */
    private const PRODUCIBLE = [
        'POST /api/v1/pastes' => [200, 201, 400, 413, 415, 422, 429, 500, 503],
        'POST /api/v1/pastes/{id}/challenge' => [200, 400, 413, 415, 429, 500, 503],
        'POST /api/v1/pastes/{id}/status' => [200, 404, 413, 415, 429, 500, 503],
        'POST /api/v1/pastes/{id}/open' => [200, 400, 404, 409, 413, 415, 429, 500, 503],
        'POST /api/v1/pastes/{id}/consume' => [200, 404, 413, 415, 429, 500, 503],
        'DELETE /api/v1/pastes/{id}' => [204, 404, 429, 500, 503],
        'GET /healthz' => [200, 429, 500, 503],
    ];

    /**
     * @return array<string, list<int>> "METHOD /path" => documented status codes
     */
    private static function documentedOperations(): array
    {
        $operations = [];
        $inPaths = false;
        $path = null;
        $operation = null;
        foreach (self::lines() as $line) {
            if (preg_match('/^(\S+):/', $line, $match) === 1) {
                $inPaths = $match[1] === 'paths';
                continue;
            }
            if (!$inPaths) {
                continue;
            }
            if (preg_match('#^  (/\S*):\s*$#', $line, $match) === 1) {
                $path = $match[1];
                $operation = null;
            } elseif (preg_match('/^    (get|put|post|delete|patch|head|options|trace):\s*$/', $line, $match) === 1 && $path !== null) {
                $operation = strtoupper($match[1]) . ' ' . $path;
                $operations[$operation] = [];
            } elseif (preg_match("/^        '(\\d{3})':\\s*$/", $line, $match) === 1 && $operation !== null) {
                $operations[$operation][] = (int) $match[1];
            }
        }

        return $operations;
    }

    /**
     * @return list<string>
     */
    private static function lines(): array
    {
        $content = file_get_contents(self::DOCUMENT);
        self::assertIsString($content);

        return explode("\n", $content);
    }

    /**
     * @return list<string> "METHOD /path" of every API route declared by the controllers
     */
    private static function apiRoutes(): array
    {
        $routes = [];
        $files = glob(__DIR__ . '/../../src/Controller/*Controller.php');
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $name = 'QuietLink\\Controller\\' . basename($file, '.php');
            if (!class_exists($name)) {
                self::fail($name . ' cannot be loaded');
            }
            $class = new ReflectionClass($name);
            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(Route::class) as $attribute) {
                    $route = $attribute->newInstance();
                    $path = (string) $route->getPath();
                    if (!str_starts_with($path, '/api/') && $path !== '/healthz') {
                        continue; // HTML pages are not part of the API contract.
                    }
                    self::assertNotSame([], $route->getMethods(), $path . ' must declare its methods');
                    foreach ($route->getMethods() as $verb) {
                        self::assertIsString($verb);
                        $routes[] = strtoupper($verb) . ' ' . $path;
                    }
                }
            }
        }
        sort($routes);

        return $routes;
    }

    #[Group('EXG-API-001')]
    #[Group('EXG-API-007')]
    #[Group('EXG-API-010')]
    public function testEveryApiRouteIsDocumentedWithItsMethods(): void
    {
        $documented = array_keys(self::documentedOperations());
        sort($documented);

        $producible = array_keys(self::PRODUCIBLE);
        sort($producible);

        self::assertSame(self::apiRoutes(), $documented);
        self::assertSame($documented, $producible, 'PRODUCIBLE must list exactly the documented operations');
    }

    #[Group('EXG-API-007')]
    #[Group('EXG-API-042')]
    public function testDocumentedStatusCodesAreExactlyTheProducibleOnes(): void
    {
        foreach (self::documentedOperations() as $operation => $codes) {
            sort($codes);
            self::assertArrayHasKey($operation, self::PRODUCIBLE);
            self::assertSame(self::PRODUCIBLE[$operation], $codes, $operation);
        }
    }

    #[Group('EXG-API-042')]
    #[Group('EXG-API-046')]
    public function testActualResponsesUseDocumentedStatusCodes(): void
    {
        $this->bootInstance();
        $id = Base64Url::encode(random_bytes(24));
        $key = Base64Url::encode(random_bytes(16));
        $probes = [
            ['POST /api/v1/pastes', 'POST', '/api/v1/pastes', '{}', ['Idempotency-Key' => $key], 400],
            ['POST /api/v1/pastes', 'POST', '/api/v1/pastes', 'x', ['Content-Type' => 'text/plain', 'Idempotency-Key' => $key], 415],
            ['POST /api/v1/pastes/{id}/challenge', 'POST', '/api/v1/pastes/x/challenge', '{"usage":"open"}', [], 400],
            ['POST /api/v1/pastes/{id}/challenge', 'POST', "/api/v1/pastes/$id/challenge", '{"usage":"open"}', [], 200],
            ['POST /api/v1/pastes/{id}/status', 'POST', "/api/v1/pastes/$id/status", '{}', [], 404],
            ['POST /api/v1/pastes/{id}/open', 'POST', "/api/v1/pastes/$id/open", '{}', [], 404],
            ['POST /api/v1/pastes/{id}/consume', 'POST', "/api/v1/pastes/$id/consume", '{}', [], 404],
            ['POST /api/v1/pastes/{id}/consume', 'POST', "/api/v1/pastes/$id/consume", '[1]', [], 404],
            ['DELETE /api/v1/pastes/{id}', 'DELETE', "/api/v1/pastes/$id", null, [], 404],
            ['GET /healthz', 'GET', '/healthz', null, [], 200],
        ];
        $documented = self::documentedOperations();
        foreach ($probes as [$operation, $method, $uri, $body, $headers, $expected]) {
            $status = $this->request($method, $uri, $body, $headers)->getStatusCode();
            self::assertSame($expected, $status, "$method $uri");
            self::assertContains($status, $documented[$operation] ?? [], $operation);
        }
    }

    #[Group('EXG-API-007')]
    #[Group('EXG-API-048')]
    public function testCacheControlConstantMatchesTheSentHeader(): void
    {
        $this->bootInstance();
        $content = implode("\n", self::lines());
        self::assertSame(1, preg_match('/^    CacheControl:\n(?:      .*\n)*?        const: (.+)$/m', $content, $match));

        self::assertSame(trim($match[1]), $this->request('GET', '/healthz')->headers->get('Cache-Control'));
    }

    #[Group('EXG-API-007')]
    public function testEveryLocalReferenceResolves(): void
    {
        $defined = [];
        $section = null;
        $inComponents = false;
        foreach (self::lines() as $line) {
            if (preg_match('/^(\S+):/', $line, $match) === 1) {
                $inComponents = $match[1] === 'components';
            } elseif ($inComponents && preg_match('/^  (\w+):\s*$/', $line, $match) === 1) {
                $section = $match[1];
            } elseif ($inComponents && $section !== null && preg_match('/^    (\w+):\s*$/', $line, $match) === 1) {
                $defined[$section . '/' . $match[1]] = true;
            }
        }

        preg_match_all("~\\\$ref: '#/components/(\\w+/\\w+)'~", implode("\n", self::lines()), $matches);
        self::assertNotSame([], $matches[1]);
        foreach (array_unique($matches[1]) as $reference) {
            self::assertArrayHasKey($reference, $defined, $reference);
        }
    }
}
