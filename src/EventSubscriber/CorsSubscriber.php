<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\EventSubscriber;

use QuietLink\Runtime\RuntimeStatus;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * CORS for the JSON API only (§9.5: disabled by default).
 *
 * An origin listed in http.cors_allowed_origins (exact string match) receives
 * Access-Control-Allow-Origin on /api/v1 responses and an answer to its preflight requests.
 * Credentials are never allowed (the API uses none). Without a match nothing is added, and a
 * preflight falls through to the usual 405.
 */
final class CorsSubscriber implements EventSubscriberInterface
{
    public const ALLOW_HEADERS = 'Content-Type, Idempotency-Key, X-Deletion-Token';
    public const EXPOSE_HEADERS = 'Retry-After';
    public const MAX_AGE = 600;

    public function __construct(
        private readonly RuntimeStatus $status,
        private readonly RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After RuntimeGuardSubscriber (256), before routing (32).
            KernelEvents::REQUEST => ['onRequest', 64],
            KernelEvents::RESPONSE => ['onResponse', -64],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->getMethod() !== 'OPTIONS' || $this->allowedOrigin($request) === null) {
            return;
        }
        $requested = $request->headers->get('Access-Control-Request-Method');
        if ($requested === null) {
            return;
        }
        $methods = $this->routeMethods($request);
        if (!in_array($requested, $methods, true)) {
            return;
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $methods));
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
        $response->headers->set('Access-Control-Max-Age', (string) self::MAX_AGE);
        $event->setResponse($response);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isApi($request) || $this->origins() === []) {
            return;
        }
        $response = $event->getResponse();
        // Responses differ by Origin as soon as CORS is enabled.
        $response->setVary('Origin', false);
        $origin = $this->allowedOrigin($request);
        if ($origin === null) {
            return;
        }
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        if ($request->getMethod() !== 'OPTIONS') {
            $response->headers->set('Access-Control-Expose-Headers', self::EXPOSE_HEADERS);
        }
    }

    private static function isApi(Request $request): bool
    {
        $path = $request->getPathInfo();

        return $path === '/api/v1' || str_starts_with($path, '/api/v1/');
    }

    private function allowedOrigin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if ($origin === null || !self::isApi($request)) {
            return null;
        }

        return in_array($origin, $this->origins(), true) ? $origin : null;
    }

    /**
     * Configured origins, only once the instance is ready (valid configuration matching boot.json).
     *
     * @return list<string>
     */
    private function origins(): array
    {
        return $this->status->isReady() ? $this->status->config()->http->corsAllowedOrigins : [];
    }

    /**
     * Methods of the route matching the request path; empty for an unknown path.
     *
     * @return list<string>
     */
    private function routeMethods(Request $request): array
    {
        if (!$this->router instanceof RequestMatcherInterface) {
            return [];
        }
        try {
            $this->router->matchRequest($request);
        } catch (MethodNotAllowedException $e) {
            return array_values($e->getAllowedMethods());
        } catch (RoutingException) {
        }

        return [];
    }
}
