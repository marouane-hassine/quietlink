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
    public function testShellIsServedInTheNegotiatedLanguage(): void
    {
        $fr = $this->request('GET', '/', null, ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.5']);
        self::assertSame(200, $fr->getStatusCode());
        self::assertStringContainsString('<html lang="fr" dir="ltr">', (string) $fr->getContent());
        self::assertStringContainsString('Nouveau texte confidentiel', (string) $fr->getContent());

        $other = $this->request('GET', '/', null, ['Accept-Language' => 'de-DE']);
        self::assertStringContainsString('<html lang="en" dir="ltr">', (string) $other->getContent());
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
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
        }
    }

    #[Group('EXG-PWA-002')]
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
        self::assertSame(['/', '/api/v1/pastes', '/api/v1/pastes/{id}', '/api/v1/pastes/{id}/challenge', '/api/v1/pastes/{id}/consume', '/api/v1/pastes/{id}/open', '/api/v1/pastes/{id}/status', '/healthz', '/how-it-works', '/manage/{id}', '/manifest.json', '/p/{id}'], array_values(array_unique($paths)));
        $kernel->shutdown();
        self::assertSame(404, $this->request('GET', '/config/config.php')->getStatusCode());
    }
}
