<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Controller;

use QuietLink\Clock\Clock;
use QuietLink\Config\InstanceConfig;
use QuietLink\Crypto\Challenge;
use QuietLink\Http\ClientAddress;
use QuietLink\Http\RequestBody;
use QuietLink\Paste\IdempotencyConflictException;
use QuietLink\Paste\PasteService;
use QuietLink\RateLimit\RateLimiter;
use QuietLink\Storage\PasteId;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON API v1 (spec §10). Business rules live in PasteService.
 */
final class ApiController
{
    public function __construct(
        private readonly PasteService $pastes,
        private readonly RateLimiter $limiter,
        private readonly InstanceConfig $config,
        private readonly Clock $clock,
    ) {
    }

    #[Route('/api/v1/pastes', name: 'api_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $body = $this->body($request);
        $subject = $this->subject($request);
        try {
            $result = $this->pastes->create(
                $body,
                $request->headers->get('Idempotency-Key'),
                fn () => $this->limit('create', $subject),
            );
        } catch (IdempotencyConflictException $e) {
            // A conflict counts like a replay (§10 step 2): keys cannot be probed at will.
            $this->limit('create_replay', $subject);
            throw $e;
        }
        if (!$result['created']) {
            // Replays have their own, wider limit per address (§10, creation step 2).
            $this->limit('create_replay', $subject);
        }
        $id = $result['id']->encoded();

        return new JsonResponse([
            'id' => $id,
            'expires_at' => $this->time($result['expires_at']),
            'server_time' => $this->time($this->clock->now()),
            'public_url' => $this->config->app->publicUrl,
            'share_path' => '/p/' . $id,
            'manage_path' => '/manage/' . $id,
        ], $result['created'] ? 201 : 200);
    }

    #[Route('/api/v1/pastes/{id}/challenge', name: 'api_challenge', methods: ['POST'])]
    public function challenge(Request $request, string $id): Response
    {
        $body = $this->body($request);
        $this->limit('challenge', $this->subject($request));

        return new JsonResponse([
            'challenge' => $this->pastes->challenge($id, $body),
            'expires_in' => Challenge::LIFETIME_SECONDS,
        ]);
    }

    #[Route('/api/v1/pastes/{id}/status', name: 'api_status', methods: ['POST'])]
    public function status(Request $request, string $id): Response
    {
        $body = $this->body($request);
        $this->limit('status', $this->subject($request));
        $status = $this->pastes->status($id, $body, fn (PasteId $paste) => $this->limit('status_per_paste', $paste->encoded()));

        return new JsonResponse([
            'aad' => $status['aad'],
            'expires_at' => $this->time($status['expires_at']),
            'server_time' => $this->time($this->clock->now()),
            'read_once' => $status['read_once'],
        ] + ($status['read_once'] ? [
            'state' => $status['state'],
            'retry_after' => $status['retry_after'],
            'unconfirmed_opens' => $status['unconfirmed_opens'],
        ] : []));
    }

    #[Route('/api/v1/pastes/{id}/open', name: 'api_open', methods: ['POST'])]
    public function open(Request $request, string $id): Response
    {
        $body = $this->body($request);
        $this->limit('open', $this->subject($request));
        $opened = $this->pastes->open($id, $body, fn (PasteId $paste) => $this->limit('open_per_paste', $paste->encoded()));

        return new JsonResponse([
            'aad' => $opened['aad'],
            'nonce' => $opened['nonce'],
            'ciphertext' => $opened['ciphertext'],
            'expires_at' => $this->time($opened['expires_at']),
            'server_time' => $this->time($this->clock->now()),
            'read_once' => $opened['read_once'],
        ] + ($opened['read_once'] ? [
            'consume_challenge' => $opened['consume_challenge'],
            'unconfirmed_opens' => $opened['unconfirmed_opens'],
            'retry_after' => $opened['retry_after'],
        ] : []));
    }

    #[Route('/api/v1/pastes/{id}/consume', name: 'api_consume', methods: ['POST'])]
    public function consume(Request $request, string $id): Response
    {
        $body = $this->body($request);
        $this->limit('consume', $this->subject($request));
        $this->pastes->consume($id, $body);

        return new JsonResponse(['consumed' => true]);
    }

    #[Route('/api/v1/pastes/{id}', name: 'api_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $id): Response
    {
        $this->limit('delete', $this->subject($request));
        $this->pastes->delete($id, $request->headers->get('X-Deletion-Token'));

        return new Response(null, 204);
    }

    private function body(Request $request): string
    {
        if (!RequestBody::isJson($request)) {
            throw new HttpException(415);
        }
        $body = RequestBody::read($request, $this->config->http->maxRequestBytes);
        if ($body === null) {
            throw new HttpException(413);
        }

        return $body;
    }

    private function subject(Request $request): string
    {
        return ClientAddress::normalize($request->getClientIp(), $this->config->http->ratelimitIpv6Prefix);
    }

    private function limit(string $bucket, string $subject): void
    {
        $limit = $this->limiter->consume($bucket, $subject);
        if (!$limit->isAccepted()) {
            throw new RateLimitedException(max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()));
        }
    }

    private function time(?int $timestamp): ?string
    {
        return $timestamp === null ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
