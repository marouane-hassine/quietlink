<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Web;

use QuietLink\Config\ConfigLoader;
use QuietLink\Config\InvalidConfigException;
use QuietLink\Runtime\RuntimeStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minimal HTML error page for browser navigations (§12 journey C: understandable, no
 * technical detail). It must work while the configuration is invalid, so it is built in
 * plain PHP without Twig: the texts come from the translation catalogs (read straight
 * from translations/, independent of the configuration) with a constant English fallback.
 * No style or script (strict CSP of §7.5), no reflection of the request path.
 */
final class ErrorPage
{
    /** English fallback used when a catalog cannot be read. */
    private const FALLBACK = [
        'page.unavailable.title' => 'Service temporarily unavailable',
        'page.unavailable.text' => 'The service is temporarily unavailable. Try again in a moment.',
        'page.notFound.title' => 'Page not found',
        'page.notFound.text' => 'This page does not exist.',
        'page.notFound.home' => 'Go to the home page',
    ];

    public function __construct(private readonly Catalogs $catalogs, private readonly RuntimeStatus $status)
    {
    }

    /**
     * True for a browser navigation: GET or HEAD outside the API and the health endpoint,
     * which keep their machine-readable formats.
     */
    public static function appliesTo(Request $request): bool
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }
        $path = $request->getPathInfo();

        return $path !== '/healthz' && $path !== '/api' && !str_starts_with($path, '/api/');
    }

    /**
     * HTML page for a 404 or 503; security and cache headers are added by SecurityHeadersSubscriber.
     */
    public function response(Request $request, int $status, ?int $retryAfter = null): Response
    {
        $locale = $this->catalogs->negotiate($request->headers->get('Accept-Language'), $this->enabledLocales());
        $prefix = $status === 503 ? 'page.unavailable' : 'page.notFound';
        $title = $this->text($locale, $prefix . '.title');
        $body = '<h1>' . self::escape($title) . '</h1>' . "\n" . '<p>' . self::escape($this->text($locale, $prefix . '.text')) . '</p>';
        if ($status !== 503) {
            $body .= "\n" . '<p><a href="' . self::escape($request->getBasePath() . '/') . '">' . self::escape($this->text($locale, 'page.notFound.home')) . '</a></p>';
        }
        $html = '<!doctype html>' . "\n"
            . '<html lang="' . self::escape($locale) . '" dir="ltr">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="robots" content="noindex, nofollow, noarchive">' . "\n"
            . '<title>' . self::escape($title) . '</title>' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n" . '<main>' . "\n" . $body . "\n" . '</main>' . "\n" . '</body>' . "\n"
            . '</html>' . "\n";

        $response = new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) max(1, $retryAfter));
        }

        return $response;
    }

    /**
     * @return list<string>
     */
    private function enabledLocales(): array
    {
        try {
            return $this->status->config()->app->enabledLocales;
        } catch (InvalidConfigException) {
            return ConfigLoader::AVAILABLE_LOCALES;
        }
    }

    /**
     * @param key-of<self::FALLBACK> $message
     */
    private function text(string $locale, string $message): string
    {
        $text = $this->catalogs->translate($locale, $message);

        return $text === $message ? self::FALLBACK[$message] : $text;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
