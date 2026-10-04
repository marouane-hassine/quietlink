<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use QuietLink\Encoding\Base64Url;
use QuietLink\Tests\Support\KernelTestCase;

#[CoversNothing]
final class PageTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $this->bootInstance();
    }

    #[Group('EXG-I18N-005')]
    #[Group('EXG-I18N-017')]
    #[Group('EXG-TEST-026')]
    public function testShellIsServedInTheNegotiatedLanguage(): void
    {
        $fr = $this->request('GET', '/', null, ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.5']);
        self::assertSame(200, $fr->getStatusCode());
        self::assertStringContainsString('<html lang="fr" dir="ltr">', (string) $fr->getContent());
        self::assertStringContainsString('Nouveau texte confidentiel', (string) $fr->getContent());

        $other = $this->request('GET', '/', null, ['Accept-Language' => 'de-DE']);
        self::assertStringContainsString('<html lang="en" dir="ltr">', (string) $other->getContent());
    }

    /**
     * A catalogue declaring a right-to-left direction is served with dir="rtl"; the page gives the
     * language selector the name and direction of every enabled catalogue (§6.6.1).
     */
    #[Group('EXG-I18N-007')]
    #[Group('EXG-I18N-008')]
    public function testRightToLeftCatalogueSetsTheDocumentDirection(): void
    {
        $ar = (string) $this->request('GET', '/', null, ['Accept-Language' => 'ar'])->getContent();
        self::assertStringContainsString('<html lang="ar" dir="rtl">', $ar);

        $it = (string) $this->request('GET', '/', null, ['Accept-Language' => 'it-IT'])->getContent();
        self::assertStringContainsString('<html lang="it" dir="ltr">', $it);
        self::assertMatchesRegularExpression('/&quot;locales&quot;:\[.*&quot;code&quot;:&quot;ar&quot;,&quot;name&quot;:&quot;[^&]+&quot;,&quot;dir&quot;:&quot;rtl&quot;/', $it);
    }

    #[Group('EXG-READ-014')]
    #[Group('EXG-SEC-061')]
    #[Group('EXG-API-030')]
    #[Group('EXG-SEC-088')]
    #[Group('EXG-SEC-089')]
    #[Group('EXG-SEC-090')]
    public function testReadPageEmbedsStatelessChallengesAndNoInlineScript(): void
    {
        $id = Base64Url::encode(random_bytes(24));
        $html = (string) $this->request('GET', '/p/' . $id)->getContent();

        self::assertMatchesRegularExpression('/&quot;challenges&quot;:\{&quot;open&quot;:&quot;[A-Za-z0-9_-]{110}&quot;,&quot;status&quot;:&quot;[A-Za-z0-9_-]{110}&quot;\}/', $html);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/', $html, 'inline scripts are forbidden');
        self::assertStringNotContainsString(' style=', $html);
        self::assertStringContainsString('noindex', $html);
    }

    #[Group('EXG-SEC-056')]
    #[Group('EXG-SEC-089')]
    public function testPagesAreNotCacheableAndNotIndexed(): void
    {
        foreach (['/', '/how-it-works', '/manage/' . Base64Url::encode(random_bytes(24))] as $path) {
            $response = $this->request('GET', $path);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
        }
    }

    #[Group('EXG-PWA-002')]
    #[Group('EXG-PWA-004')]
    #[Group('EXG-PWA-007')]
    #[Group('EXG-TEST-081')]
    public function testManifestIsServedOnlyWhenEnabled(): void
    {
        self::assertSame(404, $this->request('GET', '/manifest.json')->getStatusCode());

        $this->tearDown();
        $this->bootInstance(['ui' => ['enable_manifest' => true]]);
        $response = $this->request('GET', '/manifest.json');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('browser', self::json($response)['display']);
        self::assertStringContainsString("manifest-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
    }

    #[Group('EXG-GEN-012')]
    #[Group('EXG-SEC-103')]
    #[Group('EXG-SEC-098')]
    public function testFooterLinksToSourcesAndNoAdministrationRouteExists(): void
    {
        $html = (string) $this->request('GET', '/')->getContent();
        self::assertStringContainsString('href="https://github.com/marouane-hassine/quietlink"', $html);

        $kernel = new \QuietLink\Kernel('test', true);
        $kernel->boot();
        $router = $kernel->getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);
        $paths = array_map(static fn (\Symfony\Component\Routing\Route $route): string => $route->getPath(), $router->getRouteCollection()->all());
        sort($paths);
        self::assertSame(['/', '/api/v1/pastes', '/api/v1/pastes/{id}', '/api/v1/pastes/{id}/challenge', '/api/v1/pastes/{id}/consume', '/api/v1/pastes/{id}/open', '/api/v1/pastes/{id}/status', '/favicon.svg', '/healthz', '/how-it-works', '/manage/{id}', '/manifest.json', '/p/{id}'], array_values(array_unique($paths)));
        $kernel->shutdown();
        self::assertSame(404, $this->request('GET', '/config/config.php')->getStatusCode());
    }

    /**
     * The logo mark is inline SVG coloured by theme tokens (no colour in the markup, so the dark
     * theme and operator themes apply) and decorative: the link name stays the instance name. The
     * wordmark is one flex item so the gap does not split it and RTL does not reorder it.
     */
    #[Group('EXG-THEME-021')]
    public function testHeaderShowsTheDecorativeLogoMarkColouredByTokens(): void
    {
        $html = (string) $this->request('GET', '/')->getContent();
        self::assertStringContainsString('<a class="brand" href="/"><svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true" focusable="false">', $html);
        $mark = substr($html, (int) strpos($html, '<svg class="brand-mark"'));
        $mark = substr($mark, 0, (int) strpos($mark, '</svg>'));
        self::assertStringNotContainsString('#', $mark);
        self::assertStringNotContainsString('style=', $mark);
        self::assertStringContainsString('</svg><span class="brand-name">Quiet<span class="brand-accent">Link</span></span></a>', $html);
        self::assertStringContainsString('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', $html);
    }

    #[Group('EXG-THEME-021')]
    public function testFaviconIsTheStaticMarkWithoutScript(): void
    {
        $response = $this->request('GET', '/favicon.svg');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $svg = (string) $response->getContent();
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">', $svg);
        self::assertDoesNotMatchRegularExpression('/<script|\son\w+=|href=/i', $svg);
    }
}
