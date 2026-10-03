<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\EventSubscriber;

use QuietLink\Http\Problem;
use QuietLink\Runtime\RuntimeStatus;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses every request with a generic 503 when the configuration is invalid or differs
 * from the boot marker (§9.5), and applies the trusted proxy list.
 */
final class RuntimeGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly RuntimeStatus $status)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 256]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (!$this->status->isReady()) {
            $event->setResponse($event->getRequest()->getPathInfo() === '/healthz'
                ? new JsonResponse(['status' => 'unavailable'], 503)
                : Problem::response(503, [], 30));

            return;
        }
        // Always applied (an empty list resets any previous value of this static setting).
        Request::setTrustedProxies($this->status->config()->http->trustedProxies, Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_FORWARDED);
    }
}
