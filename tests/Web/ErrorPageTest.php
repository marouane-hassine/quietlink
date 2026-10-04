<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use QuietLink\Encoding\Base64Url;
use QuietLink\Tests\Support\KernelTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser navigations that fail server-side get a small HTML page, never raw problem+json (§12 journey C).
 */
#[CoversNothing]
final class ErrorPageTest extends KernelTestCase
{
    private function breakBootMarker(): void
    {
        $file = $this->tmp->path . '/config/config.php';
        file_put_contents($file, str_replace("'warning'", "'error'", (string) file_get_contents($file)));
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    private static function assertHardenedHtml(Response $response, string $lang, string $context): void
    {
        $html = (string) $response->getContent();
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'), $context);
        self::assertStringContainsString('<html lang="' . $lang . '" dir="ltr">', $html, $context);
        self::assertMatchesRegularExpression('#<title>[^<]+</title>#', $html, $context);
        self::assertStringNotContainsString('<script', $html, $context);
        self::assertStringNotContainsString('<style', $html, $context);
        self::assertStringNotContainsString(' style=', $html, $context);
        self::assertStringNotContainsString('about:blank', $html, $context);
        self::assertStringContainsString('noindex', $html, $context);
        self::assertStringStartsWith("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'), $context);
        self::assertStringNotContainsString('unsafe-inline', (string) $response->headers->get('Content-Security-Policy'), $context);
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $context);
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'), $context);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'), $context);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function navigations(): iterable
    {
        yield 'home' => ['/'];
        yield 'read' => ['/p/' . Base64Url::encode(str_repeat("\x01", 24))];
        yield 'manage' => ['/manage/' . Base64Url::encode(str_repeat("\x02", 24))];
        yield 'unknown' => ['/nope'];
    }

    #[DataProvider('navigations')]
    #[Group('EXG-UX-120')]
    #[Group('EXG-UX-009')]
    #[Group('EXG-CONF-021')]
    #[Group('EXG-SEC-046')]
    #[Group('EXG-SEC-056')]
    #[Group('EXG-SEC-069')]
    public function testNavigationWhileUnavailableShowsAnHtmlPage(string $path): void
    {
        $this->bootInstance();
        $this->breakBootMarker();

        $response = $this->request('GET', $path);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertHardenedHtml($response, 'en', $path);
        $html = (string) $response->getContent();
        self::assertStringContainsString('temporarily unavailable', $html);
        self::assertStringNotContainsString($path === '/' ? '/nope' : $path, $html, 'the request path is never reflected');
    }

    #[Group('EXG-UX-120')]
    #[Group('EXG-I18N-005')]
    public function testUnavailablePageFollowsTheBrowserLanguageWithoutConfiguration(): void
    {
        $this->bootInstance();
        file_put_contents($this->tmp->path . '/config/config.php', "<?php\n\nreturn [\n");
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->tmp->path . '/config/config.php', true);
        }

        $response = $this->request('GET', '/', null, ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.5']);

        self::assertSame(503, $response->getStatusCode());
        self::assertHardenedHtml($response, 'fr', 'fr');
        self::assertStringContainsString('temporairement indisponible', (string) $response->getContent());
    }

    #[Group('EXG-UX-120')]
    #[Group('EXG-UX-009')]
    #[Group('EXG-SEC-069')]
    public function testUnknownPageShowsAnHtmlNotFoundPageWithAHomeLink(): void
    {
        $this->bootInstance();

        $response = $this->request('GET', '/nope/<b>x</b>');

        self::assertSame(404, $response->getStatusCode());
        self::assertHardenedHtml($response, 'en', '404');
        $html = (string) $response->getContent();
        self::assertStringContainsString('<a href="/">', $html);
        self::assertStringNotContainsString('nope', $html);
        self::assertStringNotContainsString('<b>', $html);

        $fr = $this->request('GET', '/nope', null, ['Accept-Language' => 'fr']);
        self::assertSame(404, $fr->getStatusCode());
        self::assertHardenedHtml($fr, 'fr', '404 fr');

        self::assertSame(404, $this->request('HEAD', '/nope')->getStatusCode());
    }

    #[Group('EXG-API-050')]
    #[Group('EXG-SEC-069')]
    public function testApiAndHealthResponsesKeepTheirMachineFormat(): void
    {
        $this->bootInstance();

        $missing = $this->request('GET', '/api/v1/nope');
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('application/problem+json', $missing->headers->get('Content-Type'));
        self::assertSame(404, self::json($missing)['status']);

        $this->breakBootMarker();
        $api = $this->request('POST', '/api/v1/pastes', '{}', ['Idempotency-Key' => 'x']);
        self::assertSame(503, $api->getStatusCode());
        self::assertSame('application/problem+json', $api->headers->get('Content-Type'));
        $apiGet = $this->request('GET', '/api/v1/pastes');
        self::assertSame('application/problem+json', $apiGet->headers->get('Content-Type'));

        $health = $this->request('GET', '/healthz');
        self::assertSame(503, $health->getStatusCode());
        self::assertSame(['status' => 'unavailable'], self::json($health));

        // Non-navigation methods keep the problem document.
        self::assertSame('application/problem+json', $this->request('POST', '/', '{}')->headers->get('Content-Type'));
    }
}
