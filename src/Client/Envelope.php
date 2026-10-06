<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Client;

/**
 * Strict parser of the plaintext envelope (sp-proto/v1 §7.1, spec §8.2.1), with the rules of
 * frontend/src/crypto/envelope.ts parseEnvelope(): a JSON object with exactly the members
 * format, language, template, text and v (the integer 1), in any order, plus no duplicate
 * member names (§8.2.1).
 */
final class Envelope
{
    private const KEYS = ['format', 'language', 'template', 'text', 'v'];
    private const FORMATS = ['plain', 'markdown', 'code'];

    /**
     * @return array{format: string, language: string|null, template: string|null, text: string}
     *
     * @throws InvalidEnvelopeException
     */
    public static function parse(string $json): array
    {
        // Depth 2: an object whose members are all scalars; invalid UTF-8 and lone surrogates fail.
        $value = json_decode($json, true, 2);
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidEnvelopeException('Invalid envelope.');
        }
        $keys = array_map('strval', array_keys($value));
        sort($keys);
        if ($keys !== self::KEYS || self::hasDuplicateKeys($json)) {
            throw new InvalidEnvelopeException('Invalid envelope.');
        }
        $format = $value['format'];
        $language = $value['language'];
        $template = $value['template'];
        $text = $value['text'];
        if ($value['v'] !== 1 || !is_string($text) || !is_string($format) || !in_array($format, self::FORMATS, true)
            || !($language === null || (is_string($language) && preg_match('/^[a-z0-9+#-]{1,32}$/D', $language) === 1))
            || !($template === null || (is_string($template) && preg_match('/^[a-z-]{1,32}$/D', $template) === 1))) {
            throw new InvalidEnvelopeException('Invalid envelope.');
        }

        return ['format' => $format, 'language' => $language, 'template' => $template, 'text' => $text];
    }

    /**
     * json_decode() silently keeps the last of duplicate members, so the member names of the
     * (already validated, flat) document are collected by a linear scan: every string literal
     * followed by ':' is a member name. A regular expression reached PCRE's backtrack limit on
     * large texts with many escapes.
     */
    private static function hasDuplicateKeys(string $json): bool
    {
        $names = [];
        $length = strlen($json);
        for ($i = 0; $i < $length; ++$i) {
            if ($json[$i] !== '"') {
                continue;
            }
            $start = $i;
            for (++$i; $i < $length && $json[$i] !== '"'; ++$i) {
                if ($json[$i] === '\\') {
                    ++$i;
                }
            }
            $end = $i;
            $next = $i + 1;
            while ($next < $length && strpos(" \t\r\n", $json[$next]) !== false) {
                ++$next;
            }
            if ($next >= $length || $json[$next] !== ':') {
                continue;
            }
            $name = json_decode(substr($json, $start, $end - $start + 1));
            if (!is_string($name) || isset($names[$name])) {
                return true;
            }
            $names[$name] = true;
        }

        return false;
    }
}
