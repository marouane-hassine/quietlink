<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Generic RFC 9457 problem responses: no internal detail, no paste identifier.
 */
final class Problem
{
    private const TITLES = [
        400 => 'Bad request',
        404 => 'Not available',
        405 => 'Method not allowed',
        409 => 'Temporarily reserved',
        413 => 'Payload too large',
        415 => 'Unsupported media type',
        422 => 'Idempotency key reused',
        429 => 'Too many requests',
        500 => 'Internal error',
        503 => 'Service unavailable',
    ];

    /**
     * @param array<string, int|string> $extra additional public members (e.g. retry_after)
     */
    public static function response(int $status, array $extra = [], ?int $retryAfter = null): JsonResponse
    {
        $response = new JsonResponse(
            ['type' => 'about:blank', 'title' => self::TITLES[$status] ?? 'Error', 'status' => $status] + $extra,
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) max(1, $retryAfter));
        }

        return $response;
    }
}
