<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\EventSubscriber;

use QuietLink\Clock\Clock;
use QuietLink\Http\Problem;
use QuietLink\Log\OperationsLog;
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

    public function __construct(
        private readonly RuntimeStatus $status,
        private readonly ErrorPage $errorPage,
        private readonly OperationsLog $operations,
        private readonly Clock $clock,
    ) {
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
            // Only request lines with status 503 would show otherwise.
            $this->operations->warnOnce('boot_marker_mismatch', 'Configuration invalid or different from the boot marker: run app:boot, then reload PHP-FPM.', $this->clock->now());
            $request = $event->getRequest();
            $event->setResponse(match (true) {
                $request->getPathInfo() === '/healthz' => new JsonResponse(['status' => 'unavailable'], 503),
                ErrorPage::appliesTo($request) => $this->errorPage->response($request, 503, self::RETRY_AFTER),
                default => Problem::response(503, [], self::RETRY_AFTER),
            });

            return;
        }
        // Always applied (an empty list resets any previous value of this static setting).
        // Only the client address and the scheme: links use app.public_url, never the Host header,
        // and a client-sent Forwarded header must not conflict with X-Forwarded-For.
        Request::setTrustedProxies($this->status->config()->http->trustedProxies, Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        // "/path/" would be redirected by the router to a Location built from the Host header and
        // the detected scheme (open redirect, http downgrade of a key URL): uniform 404 instead.
        $path = $event->getRequest()->getPathInfo();
        if ($path !== '/' && str_ends_with($path, '/')) {
            $request = $event->getRequest();
            $event->setResponse(ErrorPage::appliesTo($request) ? $this->errorPage->response($request, 404) : Problem::response(404));
        }
    }
}
