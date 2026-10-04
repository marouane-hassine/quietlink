<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Web;

/**
 * Translation catalogs shared with the frontend (translations/<locale>.json).
 */
final class Catalogs
{
    /** @var array<string, array<string, string>> */
    private array $loaded = [];

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Best enabled locale for an Accept-Language header; English otherwise (§6.6.1).
     *
     * @param list<string> $enabled
     */
    public function negotiate(?string $acceptLanguage, array $enabled): string
    {
        $candidates = [];
        foreach (explode(',', (string) $acceptLanguage) as $index => $part) {
            $pieces = explode(';', trim($part));
            $code = strtolower(substr(trim($pieces[0]), 0, 2));
            $quality = 1.0;
            if (isset($pieces[1]) && preg_match('/q=([0-9.]+)/', $pieces[1], $match) === 1) {
                $quality = (float) $match[1];
            }
            if ($code !== '' && $quality > 0) {
                $candidates[] = [$code, $quality, $index];
            }
        }
        usort($candidates, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
        foreach ($candidates as [$code]) {
            if (in_array($code, $enabled, true) && is_file($this->path($code))) {
                return $code;
            }
        }

        return 'en';
    }

    /**
     * Locales of the valid catalogues in $directory, English first (§6.6.1: a new language is a
     * new catalogue, no code change). A catalogue is valid when its `_meta` names its own locale
     * and declares a direction.
     *
     * @return list<string>
     */
    public static function available(string $directory): array
    {
        $files = glob($directory . '/*.json');
        $locales = [];
        foreach ($files === false ? [] : $files as $file) {
            $code = basename($file, '.json');
            if (preg_match('/^[a-z]{2}$/D', $code) === 1 && self::metaOf($file, $code) !== null) {
                $locales[] = $code;
            }
        }
        sort($locales);
        usort($locales, static fn (string $a, string $b): int => ($b === 'en') <=> ($a === 'en'));

        return $locales;
    }

    /** Writing direction declared by the catalogue (ltr when unknown). */
    public function direction(string $locale): string
    {
        return $this->meta($locale)['dir'] ?? 'ltr';
    }

    /**
     * Name and direction of each enabled locale, for the language selector.
     *
     * @param list<string> $enabled
     *
     * @return list<array{code: string, name: string, dir: string}>
     */
    public function locales(array $enabled): array
    {
        $locales = [];
        foreach ($enabled as $code) {
            $meta = $this->meta($code);
            if ($meta !== null) {
                $locales[] = ['code' => $code, 'name' => $meta['name'], 'dir' => $meta['dir']];
            }
        }

        return $locales;
    }

    /**
     * @return array{name: string, dir: string}|null
     */
    private function meta(string $locale): ?array
    {
        return preg_match('/^[a-z]{2}$/D', $locale) === 1 ? self::metaOf($this->path($locale), $locale) : null;
    }

    /**
     * @return array{name: string, dir: string}|null
     */
    private static function metaOf(string $file, string $code): ?array
    {
        $json = @file_get_contents($file);
        $data = $json === false ? null : json_decode($json, true);
        $meta = is_array($data) ? ($data['_meta'] ?? null) : null;
        if (!is_array($meta) || ($meta['locale'] ?? null) !== $code || !is_string($meta['name'] ?? null)
            || !in_array($meta['dir'] ?? null, ['ltr', 'rtl'], true)) {
            return null;
        }

        return ['name' => $meta['name'], 'dir' => $meta['dir']];
    }

    public function translate(string $locale, string $key): string
    {
        return $this->catalog($locale)[$key] ?? $this->catalog('en')[$key] ?? $key;
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        if (!isset($this->loaded[$locale])) {
            $json = preg_match('/^[a-z]{2}$/D', $locale) === 1 ? @file_get_contents($this->path($locale)) : false;
            $data = $json === false ? null : json_decode($json, true);
            $strings = [];
            foreach (is_array($data) ? $data : [] as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $strings[$key] = $value;
                }
            }
            $this->loaded[$locale] = $strings;
        }

        return $this->loaded[$locale];
    }

    private function path(string $locale): string
    {
        return $this->directory . '/' . $locale . '.json';
    }
}
