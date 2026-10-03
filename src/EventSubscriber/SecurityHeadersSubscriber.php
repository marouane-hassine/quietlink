<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\EventSubscriber;

use QuietLink\Config\InvalidConfigException;
use QuietLink\Runtime\RuntimeStatus;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * HTTP hardening headers on every application response (§7.5).
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly RuntimeStatus $status)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -128]];
    }

    /**
     * Reference page policy of §7.5. manifest-src only when the manifest is enabled; img-src
     * keeps data: out because the QR code is an SVG built through the DOM.
     */
    public static function contentSecurityPolicy(bool $manifest, bool $secure): string
    {
        $directives = [
            "default-src 'none'",
            "script-src 'self'",
            "worker-src 'self'",
            "style-src 'self'",
            "img-src 'self'",
            "font-src 'self'",
            "connect-src 'self'",
        ];
        if ($manifest) {
            $directives[] = "manifest-src 'self'";
        }
        array_push($directives, "form-action 'none'", "base-uri 'none'", "frame-ancestors 'none'", "object-src 'none'");
        if ($secure) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;
        $manifest = false;
        $hsts = 31536000;
        try {
            $config = $this->status->config();
            $manifest = $config->ui->enableManifest;
            $hsts = $config->http->hstsMaxAge;
        } catch (InvalidConfigException) {
        }

        $headers->set('Content-Security-Policy', self::contentSecurityPolicy($manifest, $request->isSecure()));
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), clipboard-read=(self), clipboard-write=(self)');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set('Cache-Control', 'no-store');
        $headers->remove('X-Powered-By');
        if ($request->isSecure() && $hsts > 0) {
            $headers->set('Strict-Transport-Security', 'max-age=' . $hsts . '; includeSubDomains');
        }
        $headers->remove('Set-Cookie');
    }
}
