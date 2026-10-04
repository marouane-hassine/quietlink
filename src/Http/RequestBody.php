<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads a request body while enforcing the size limit before parsing (§8.2.3).
 */
final class RequestBody
{
    /**
     * @return string|null null when the body exceeds $maxBytes
     */
    public static function read(Request $request, int $maxBytes): ?string
    {
        $declared = $request->headers->get('Content-Length');
        if ($declared !== null && (!ctype_digit($declared) || (int) $declared > $maxBytes)) {
            return null;
        }
        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, $maxBytes + 1);
        if ($body === false || strlen($body) > $maxBytes) {
            return null;
        }

        return $body;
    }

    /**
     * Only application/json (optionally with charset=utf-8) is accepted for bodies (§10).
     */
    public static function isJson(Request $request): bool
    {
        $type = strtolower(trim((string) $request->headers->get('Content-Type', '')));

        return preg_match('#^application/json(\s*;\s*charset=utf-8)?$#D', $type) === 1;
    }
}
