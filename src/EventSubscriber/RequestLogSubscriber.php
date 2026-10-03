<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * One access log line per request with the route name only (never the path, query, headers,
 * address or identifier), status, duration and sizes (ADR-0005).
 */
final class RequestLogSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $start = $request->server->get('REQUEST_TIME_FLOAT');
        $length = $request->headers->get('Content-Length');
        $this->logger->info('request', [
            'method' => $request->getMethod(),
            'route' => is_string($route) ? $route : 'unmatched',
            'status' => $event->getResponse()->getStatusCode(),
            'duration_ms' => is_float($start) ? (int) round((microtime(true) - $start) * 1000) : null,
            'request_bytes' => $length !== null && ctype_digit($length) ? (int) $length : 0,
        ]);
    }
}
