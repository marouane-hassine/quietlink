<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Controller;

use QuietLink\Config\InstanceConfig;
use QuietLink\Crypto\Challenge;
use QuietLink\Encoding\InvalidEncodingException;
use QuietLink\Paste\PasteService;
use QuietLink\Storage\PasteId;
use QuietLink\Theme\TokenThemeBuilder;
use QuietLink\Web\Assets;
use QuietLink\Web\Catalogs;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * HTML shells of the application. They never contain user content: the page is built by the
 * static script, which encrypts and decrypts locally. No storage access happens here.
 */
final class PageController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly InstanceConfig $config,
        private readonly Catalogs $catalogs,
        private readonly Assets $assets,
        private readonly PasteService $pastes,
    ) {
    }

    #[Route('/', name: 'page_create', methods: ['GET'])]
    public function create(Request $request): Response
    {
        return $this->page($request, 'create', 'page.create.title');
    }

    #[Route('/p/{id}', name: 'page_read', methods: ['GET'], requirements: ['id' => '[A-Za-z0-9_-]{1,64}'])]
    public function read(Request $request, string $id): Response
    {
        $challenges = null;
        try {
            // Stateless challenges, identical for existing and unknown identifiers (§6.3.1).
            $paste = PasteId::fromEncoded($id);
            $challenges = [
                'open' => $this->pastes->issueChallenge($paste, Challenge::USAGE_OPEN),
                'status' => $this->pastes->issueChallenge($paste, Challenge::USAGE_STATUS),
            ];
        } catch (InvalidEncodingException) {
        }

        return $this->page($request, 'read', 'page.read.title', $challenges);
    }

    #[Route('/manage/{id}', name: 'page_manage', methods: ['GET'], requirements: ['id' => '[A-Za-z0-9_-]{1,64}'])]
    public function manage(Request $request): Response
    {
        return $this->page($request, 'manage', 'page.manage.title');
    }

    #[Route('/how-it-works', name: 'page_how', methods: ['GET'])]
    public function how(Request $request): Response
    {
        return $this->page($request, 'how', 'page.how.title');
    }

    /**
     * Minimal manifest (§6.10): no Service Worker, browser display mode, no secret data.
     */
    #[Route('/manifest.json', name: 'manifest', methods: ['GET'])]
    public function manifest(): Response
    {
        if (!$this->config->ui->enableManifest) {
            throw new NotFoundHttpException();
        }

        return new JsonResponse([
            'name' => $this->config->app->name,
            'short_name' => $this->config->app->name,
            'start_url' => '/',
            'scope' => '/',
            'display' => 'browser',
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * @param array{open: string, status: string}|null $challenges
     */
    private function page(Request $request, string $page, string $titleKey, ?array $challenges = null): Response
    {
        $locale = $this->catalogs->negotiate($request->headers->get('Accept-Language'), $this->config->app->enabledLocales);
        $paste = $this->config->paste;
        $settings = [
            'page' => $page,
            'enabledLocales' => $this->config->app->enabledLocales,
            'defaultExpiration' => $paste->defaultExpiration,
            'expirations' => $paste->acceptedExpirationCodes(),
            'allowReadOnce' => $paste->allowReadOnce,
            'allowPassphrase' => $paste->allowPassphrase,
            'maxEnvelopeBytes' => $paste->maxEnvelopeBytes,
            'kdf' => ['m' => 65536, 't' => 3],
            'enableQrCode' => $this->config->ui->enableQrCode,
            'allowPrint' => $this->config->ui->allowPrint,
            'allowExport' => $this->config->ui->allowExport,
            'darkMode' => $this->config->ui->darkMode,
            'templates' => $this->config->ui->templates,
        ] + ($challenges === null ? [] : ['challenges' => $challenges]);
        $t = fn (string $key): string => $this->catalogs->translate($locale, $key);

        return new Response($this->twig->render('page.html.twig', [
            'locale' => $locale,
            'name' => $this->config->app->name,
            'title' => $t($titleKey),
            'noscript' => $t('app.noscript'),
            'skip' => $t('app.skip'),
            'how' => $t('footer.how'),
            'source' => $t('footer.source'),
            'source_url' => $this->config->app->sourceUrl,
            'settings' => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'assets' => $this->assets->entry(),
            'tokens' => TokenThemeBuilder::stylesheet($this->config),
            'manifest' => $this->config->ui->enableManifest,
        ]), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex, nofollow, noarchive']);
    }
}
