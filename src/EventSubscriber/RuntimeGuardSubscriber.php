<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\EventSubscriber;

use QuietLink\Http\Problem;
use QuietLink\Runtime\RuntimeStatus;
use QuietLink\Web\ErrorPage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses every request with a generic 503 when the configuration is invalid or differs
 * from the boot marker (§9.5), and applies the trusted proxy list. Browser navigations get
 * a plain HTML page instead of the problem document (§12 journey C).
 */
final class RuntimeGuardSubscriber implements EventSubscriberInterface
{
    private const RETRY_AFTER = 30;

    public function __construct(private readonly RuntimeStatus $status, private readonly ErrorPage $errorPage)
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
            $request = $event->getRequest();
            $event->setResponse(match (true) {
                $request->getPathInfo() === '/healthz' => new JsonResponse(['status' => 'unavailable'], 503),
                ErrorPage::appliesTo($request) => $this->errorPage->response($request, 503, self::RETRY_AFTER),
                default => Problem::response(503, [], self::RETRY_AFTER),
            });

            return;
        }
        // Always applied (an empty list resets any previous value of this static setting).
        Request::setTrustedProxies($this->status->config()->http->trustedProxies, Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_FORWARDED);
    }
}
