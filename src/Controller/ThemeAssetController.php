<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Controller;

use QuietLink\Config\InstanceConfig;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Generated theme stylesheet (app:boot, §12) for web servers without a dedicated alias (Apache,
 * shared hosting): storage.generated_assets_dir lies outside public/. The Nginx image serves
 * the same URL from its volume before reaching PHP.
 */
final class ThemeAssetController
{
    public function __construct(private readonly InstanceConfig $config)
    {
    }

    #[Route('/themes/generated/{file}', name: 'theme_asset', methods: ['GET'], requirements: ['file' => 'tokens\.[0-9a-f]{16}\.css'])]
    public function __invoke(string $file): Response
    {
        $path = $this->config->storage->generatedAssetsDir . '/' . $file;
        $css = is_file($path) ? @file_get_contents($path) : false;
        if ($css === false) {
            throw new NotFoundHttpException();
        }

        return new Response($css, 200, ['Content-Type' => 'text/css; charset=utf-8']);
    }
}
