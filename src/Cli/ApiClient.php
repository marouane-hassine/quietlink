<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

use QuietLink\Client\ClientCrypto;
use QuietLink\Client\PreparedPaste;
use QuietLink\Crypto\DeletionToken;
use QuietLink\Crypto\Identifier;
use QuietLink\Encoding\Base64Url;
use QuietLink\Encoding\InvalidEncodingException;
use QuietLink\Storage\PasteId;
use SensitiveParameter;

/**
 * HTTP client of the QuietLink API v1 used by the CLI.
 */
final class ApiClient
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Creates a paste; retries after network errors with the same key and body (§10).
     *
     * @return array{id: PasteId, expires_at: string|null}
     */
    public function create(string $server, PreparedPaste $prepared): array
    {
        $body = $prepared->json();
        $response = $this->withRetries(fn (): HttpResponse => $this->transport->send('POST', $server . '/api/v1/pastes', [
            'Content-Type' => 'application/json',
            'Idempotency-Key' => $prepared->idempotencyKey,
        ], $body));
        if ($response->status === 422) {
            throw new CliException('Internal error: the idempotency key was reused with another body.');
        }
        $this->expect($response, [200, 201]);

        try {
            $id = PasteId::fromEncoded($response->string('id'));
        } catch (InvalidEncodingException) {
            throw new CliException('The server returned an invalid identifier.');
        }
        $accessPk = Base64Url::decode(ClientCrypto::accessPublicKey($prepared->urlKey));
        if (!Identifier::matchesAccessKey($id->bytes(), $accessPk)
            || !Identifier::matchesDeletionHash($id->bytes(), DeletionToken::hash($prepared->deletionToken))) {
            throw new CliException('The server returned an identifier that does not match this content.');
        }
        $expires = $response->json()['expires_at'] ?? null;

        return ['id' => $id, 'expires_at' => is_string($expires) ? $expires : null];
    }

    /**
     * status with an access proof; one retry with a fresh challenge on 404 (§6.3.1).
     *
     * @return array<string, mixed>
     */
    public function status(ShareLink $link, #[SensitiveParameter] string $accessSeed): array
    {
        return $this->proved($link, $accessSeed, 'status', []);
    }

    /**
     * open with an access proof (and a reservation id for read-once content).
     *
     * @return array<string, mixed>
     */
    public function open(ShareLink $link, #[SensitiveParameter] string $accessSeed, ?string $reservationId): array
    {
        return $this->proved($link, $accessSeed, 'open', $reservationId === null ? [] : ['reservation_id' => $reservationId]);
    }

    public function consume(ShareLink $link, string $accessPk, string $reservationId, string $challenge, string $signature): void
    {
        $body = (string) json_encode([
            'access_pk' => $accessPk,
            'reservation_id' => $reservationId,
            'challenge' => $challenge,
            'signature' => $signature,
        ], JSON_UNESCAPED_SLASHES);
        $response = $this->withRetries(fn (): HttpResponse => $this->transport->send('POST', $this->pasteUrl($link) . '/consume', ['Content-Type' => 'application/json'], $body));
        $this->expect($response, [200]);
    }

    public function delete(ShareLink $link): void
    {
        $token = Base64Url::encode($link->secret);
        $response = $this->withRetries(fn (): HttpResponse => $this->transport->send('DELETE', $this->pasteUrl($link), ['X-Deletion-Token' => $token], null));
        $this->expect($response, [204]);
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, mixed>
     */
    private function proved(ShareLink $link, #[SensitiveParameter] string $accessSeed, string $usage, array $extra): array
    {
        $accessPk = ClientCrypto::accessPublicKey($link->secret);
        for ($attempt = 0; ; ++$attempt) {
            $challenge = $this->withRetries(fn (): HttpResponse => $this->transport->send(
                'POST',
                $this->pasteUrl($link) . '/challenge',
                ['Content-Type' => 'application/json'],
                (string) json_encode(['usage' => $usage]),
            ));
            $this->expect($challenge, [200]);
            $value = $challenge->string('challenge');
            $body = (string) json_encode([
                'challenge' => $value,
                'access_pk' => $accessPk,
                'signature' => ClientCrypto::prove($accessSeed, $value),
            ] + $extra, JSON_UNESCAPED_SLASHES);
            $response = $this->withRetries(fn (): HttpResponse => $this->transport->send('POST', $this->pasteUrl($link) . '/' . $usage, ['Content-Type' => 'application/json'], $body));
            if ($response->status === 404 && $attempt === 0) {
                continue;
            }
            $this->expect($response, [200]);

            return $response->json();
        }
    }

    /**
     * @param callable(): HttpResponse $send
     */
    private function withRetries(callable $send): HttpResponse
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $send();
            } catch (TransportException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new CliException($e->getMessage());
                }
                usleep(200_000 * $attempt);
            }
        }
    }

    /**
     * @param list<int> $expected
     */
    private function expect(HttpResponse $response, array $expected): void
    {
        if (in_array($response->status, $expected, true)) {
            return;
        }
        $retry = $response->headers['retry-after'] ?? null;
        throw new CliException(match ($response->status) {
            404 => 'This content is unavailable: it may have expired, been deleted or already been read, or the link is invalid.',
            409 => sprintf('This content is being opened elsewhere; try again in %s seconds.', is_string($retry) && ctype_digit($retry) ? $retry : 'a few'),
            413 => 'The content is too large for this instance.',
            429 => 'Too many requests; try again later.',
            503 => 'The service is temporarily unavailable; try again later.',
            400 => 'The request was refused by the server (option not allowed or invalid data).',
            default => sprintf('Unexpected server response (HTTP %d).', $response->status),
        });
    }

    private function pasteUrl(ShareLink $link): string
    {
        return $link->origin . '/api/v1/pastes/' . $link->id->encoded();
    }
}
