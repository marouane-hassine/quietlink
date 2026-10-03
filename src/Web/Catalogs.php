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
