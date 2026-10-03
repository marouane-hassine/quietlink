<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Web;

/**
 * Hashed frontend assets from the Vite manifest (public/build/.vite/manifest.json).
 */
final class Assets
{
    /** @var array{js: string|null, css: list<string>}|null */
    private ?array $entry = null;

    public function __construct(private readonly string $buildDir)
    {
    }

    /**
     * @return array{js: string|null, css: list<string>}
     */
    public function entry(): array
    {
        if ($this->entry !== null) {
            return $this->entry;
        }
        $json = @file_get_contents($this->buildDir . '/.vite/manifest.json');
        $manifest = $json === false ? null : json_decode($json, true);
        $app = is_array($manifest) ? ($manifest['src/main.ts'] ?? null) : null;
        $js = is_array($app) && is_string($app['file'] ?? null) ? '/build/' . $app['file'] : null;
        $css = [];
        foreach (is_array($app) && is_array($app['css'] ?? null) ? $app['css'] : [] as $file) {
            if (is_string($file)) {
                $css[] = '/build/' . $file;
            }
        }

        return $this->entry = ['js' => $js, 'css' => $css];
    }
}
