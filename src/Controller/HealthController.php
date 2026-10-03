<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Controller;

use QuietLink\Clock\Clock;
use QuietLink\Config\InstanceConfig;
use QuietLink\Http\ClientAddress;
use QuietLink\RateLimit\RateLimiter;
use QuietLink\Storage\StateFiles;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Minimal technical health (§10): no version, configuration or infrastructure detail.
 */
final class HealthController
{
    public function __construct(
        private readonly StateFiles $stateFiles,
        private readonly InstanceConfig $config,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
    ) {
    }

    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $limit = $this->limiter->consume('health', ClientAddress::normalize($request->getClientIp(), $this->config->http->ratelimitIpv6Prefix));
        if (!$limit->isAccepted()) {
            throw new RateLimitedException(max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()));
        }
        $healthy = $this->stateFiles->healthAllowsCreation($this->clock->now(), $this->config->storage->minFreeInodesPercent);

        return new JsonResponse(['status' => $healthy ? 'ok' : 'degraded'], $healthy ? 200 : 503);
    }
}
