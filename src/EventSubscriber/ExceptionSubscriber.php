<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\EventSubscriber;

use Psr\Log\LoggerInterface;
use QuietLink\Controller\RateLimitedException;
use QuietLink\Http\Problem;
use QuietLink\Paste\IdempotencyConflictException;
use QuietLink\Paste\InvalidRequestException;
use QuietLink\Paste\PasteUnavailableException;
use QuietLink\Paste\PayloadTooLargeException;
use QuietLink\Paste\ReservationConflictException;
use QuietLink\Storage\QuotaExceededException;
use QuietLink\Storage\StorageException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converts every exception into a generic problem response (§7.5); logs only the class name.
 * Propagation is stopped so that the framework never logs exception messages.
 */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $response = match (true) {
            $exception instanceof PasteUnavailableException, $exception instanceof NotFoundHttpException => Problem::response(404),
            $exception instanceof InvalidRequestException => Problem::response(400),
            $exception instanceof PayloadTooLargeException => Problem::response(413),
            $exception instanceof ReservationConflictException => Problem::response(409, ['retry_after' => $exception->retryAfter], $exception->retryAfter),
            $exception instanceof IdempotencyConflictException => Problem::response(422),
            $exception instanceof QuotaExceededException => Problem::response(503, [], 300),
            $exception instanceof StorageException => Problem::response(503, [], 5),
            $exception instanceof RateLimitedException => Problem::response(429, [], $exception->retryAfterSeconds),
            $exception instanceof MethodNotAllowedHttpException => self::methodNotAllowed($exception),
            $exception instanceof HttpExceptionInterface && in_array($exception->getStatusCode(), [400, 413, 415, 429], true) => Problem::response($exception->getStatusCode()),
            default => null,
        };
        if ($response === null) {
            $this->logger->error('Unhandled exception', ['exception' => $exception::class]);
            $response = Problem::response(500);
        } elseif ($exception instanceof StorageException) {
            $this->logger->warning('Storage unavailable', ['exception' => $exception::class]);
        }

        $event->setResponse($response);
        $event->allowCustomResponseCode();
        $event->stopPropagation();
    }

    /**
     * A 405 response must list the supported methods in Allow (RFC 9110 §15.5.6).
     */
    private static function methodNotAllowed(MethodNotAllowedHttpException $exception): Response
    {
        $response = Problem::response(405);
        $allow = $exception->getHeaders()['Allow'] ?? null;
        if (is_string($allow) && $allow !== '') {
            $response->headers->set('Allow', $allow);
        }

        return $response;
    }
}
