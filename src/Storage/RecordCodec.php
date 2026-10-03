<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Storage;

use JsonException;
use QuietLink\Encoding\Base64Url;
use QuietLink\Encoding\InvalidEncodingException;

/**
 * JSON (de)serialisation of meta.json and state.json, schema version 1 (ADR-0002).
 * Decoding fails closed: any deviation from the schema returns null.
 */
final class RecordCodec
{
    public const SCHEMA_VERSION = 1;

    public static function encodeMeta(PasteMeta $meta): string
    {
        return self::json([
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $meta->id->encoded(),
            'aad' => Base64Url::encode($meta->aad),
            'created_at' => $meta->createdAt,
            'expires_at' => $meta->expiresAt,
            'read_once' => $meta->readOnce,
            'deletion_token_hash' => Base64Url::encode($meta->deletionTokenHash),
        ]);
    }

    public static function encodeState(PasteState $state): string
    {
        return self::json([
            'schema_version' => self::SCHEMA_VERSION,
            'state' => $state->name->value,
            'terminal_at' => $state->terminalAt,
            'unconfirmed_opens' => $state->unconfirmedOpens,
            'reservation' => $state->reservation === null ? null : [
                'id_hash' => Base64Url::encode($state->reservation->idHash),
                'consume_challenge' => Base64Url::encode($state->reservation->consumeChallenge),
                'expires_at' => $state->reservation->expiresAt,
            ],
            'consumed_signature_hash' => $state->consumedSignatureHash === null ? null : Base64Url::encode($state->consumedSignatureHash),
        ]);
    }

    public static function decodeMeta(string $json): ?PasteMeta
    {
        $data = self::object($json, ['schema_version', 'id', 'aad', 'created_at', 'expires_at', 'read_once', 'deletion_token_hash']);
        if ($data === null
            || !is_string($data['id']) || !is_string($data['aad']) || !is_string($data['deletion_token_hash'])
            || !is_int($data['created_at']) || !self::nullableInt($data['expires_at']) || !is_bool($data['read_once'])) {
            return null;
        }
        try {
            return new PasteMeta(
                PasteId::fromEncoded($data['id']),
                Base64Url::decode($data['aad']),
                $data['created_at'],
                $data['expires_at'],
                $data['read_once'],
                Base64Url::decode($data['deletion_token_hash'], 32),
            );
        } catch (InvalidEncodingException) {
            return null;
        }
    }

    public static function decodeState(string $json): ?PasteState
    {
        $data = self::object($json, ['schema_version', 'state', 'terminal_at', 'unconfirmed_opens', 'reservation', 'consumed_signature_hash']);
        if ($data === null || !is_string($data['state']) || !self::nullableInt($data['terminal_at'])
            || !is_int($data['unconfirmed_opens']) || $data['unconfirmed_opens'] < 0) {
            return null;
        }
        $name = StateName::tryFrom($data['state']);
        if ($name === null) {
            return null;
        }

        try {
            $reservation = null;
            if ($data['reservation'] !== null) {
                $r = $data['reservation'];
                if (!is_array($r) || array_keys($r) !== ['id_hash', 'consume_challenge', 'expires_at']
                    || !is_string($r['id_hash']) || !is_string($r['consume_challenge']) || !is_int($r['expires_at'])) {
                    return null;
                }
                $reservation = new Reservation(Base64Url::decode($r['id_hash'], 32), Base64Url::decode($r['consume_challenge'], 82), $r['expires_at']);
            }
            $signatureHash = $data['consumed_signature_hash'];
            if ($signatureHash !== null && !is_string($signatureHash)) {
                return null;
            }

            return new PasteState(
                $name,
                $data['terminal_at'],
                $data['unconfirmed_opens'],
                $reservation,
                $signatureHash === null ? null : Base64Url::decode($signatureHash, 32),
            );
        } catch (InvalidEncodingException) {
            return null;
        }
    }

    /**
     * @param list<string> $keys exact member list, in order
     *
     * @return array<string, mixed>|null
     */
    public static function object(string $json, array $keys): ?array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data) || array_keys($data) !== $keys || ($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return null;
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @phpstan-assert-if-true int<0, max>|null $value
     */
    private static function nullableInt(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value >= 0);
    }
}
