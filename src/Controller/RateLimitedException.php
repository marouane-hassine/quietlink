<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Controller;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Rate limit reached (429 with Retry-After).
 */
final class RateLimitedException extends TooManyRequestsHttpException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct($retryAfterSeconds);
    }
}
