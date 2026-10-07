<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\EventSubscriber;

use Closure;
use QuietLink\Clock\Clock;
use QuietLink\Log\OperationsLog;
use QuietLink\Maintenance\Purger;
use QuietLink\Runtime\RuntimeStatus;
use QuietLink\Storage\StateFiles;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Purge for hosts without a CLI cron (storage.web_purge): when the last purge (health.json) is
 * older than INTERVAL, the current request runs it once its response has been sent (PHP-FPM
 * finishes the request first). Any page triggers it, so an HTTP cron calling /healthz is
 * enough; there is no dedicated endpoint nor token. purge.lock keeps concurrent requests from
 * running it twice.
 */
final class WebPurgeSubscriber implements EventSubscriberInterface
{
    public const INTERVAL = 300;
    /** Seconds spent on pastes at most: the rest waits for the next run. */
    public const BUDGET = 15;

    /**
     * @param Closure(): StateFiles $stateFiles
     * @param Closure(): Purger     $purger
     */
    public function __construct(
        private readonly RuntimeStatus $status,
        #[AutowireServiceClosure(StateFiles::class)] private readonly Closure $stateFiles,
        #[AutowireServiceClosure(Purger::class)] private readonly Closure $purger,
        private readonly OperationsLog $operations,
        private readonly Clock $clock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    /**
     * Whether the client already has the whole response: PHP-FPM and LiteSpeed end the request
     * before the terminate event (Response::send()). Elsewhere (mod_php, CGI) the client would
     * wait for the purge, so it is not run there; the CLI has no client waiting.
     */
    private static function responseIsFinished(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request') || PHP_SAPI === 'cli';
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest() || !self::responseIsFinished() || !$this->status->isReady() || !$this->status->config()->storage->webPurge) {
            return;
        }
        $now = $this->clock->now();
        $last = ($this->stateFiles)()->health()['measured_at'] ?? null;
        if ($last !== null && $now - $last < self::INTERVAL && $last <= $now + 60) {
            return;
        }
        ignore_user_abort(true);
        try {
            ($this->purger)()->run(false, $now + self::BUDGET);
        } catch (\Throwable) {
            // The next request retries; the CLI purge reports the details.
            $this->operations->warnOnce('web_purge_failed', 'Purge triggered by a web request failed: run app:purge-expired to see why.', $now);
        }
    }
}
