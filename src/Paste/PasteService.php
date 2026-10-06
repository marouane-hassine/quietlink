<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Paste;

use Closure;
use QuietLink\Clock\Clock;
use QuietLink\Config\Duration;
use QuietLink\Config\InstanceConfig;
use QuietLink\Config\PasteSettings;
use QuietLink\Crypto\Aad;
use QuietLink\Crypto\AccessProof;
use QuietLink\Crypto\Challenge;
use QuietLink\Crypto\DeletionToken;
use QuietLink\Crypto\Ed25519;
use QuietLink\Crypto\Identifier;
use QuietLink\Crypto\InvalidAadException;
use QuietLink\Crypto\Protocol;
use QuietLink\Encoding\Base64Url;
use QuietLink\Encoding\InvalidEncodingException;
use QuietLink\Storage\IdempotencyRecord;
use QuietLink\Storage\IdempotencyStore;
use QuietLink\Storage\PasteId;
use QuietLink\Storage\PasteMeta;
use QuietLink\Storage\PasteRecord;
use QuietLink\Storage\PasteState;
use QuietLink\Storage\PasteStore;
use QuietLink\Storage\QuotaExceededException;
use QuietLink\Storage\Reservation;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\StateName;
use QuietLink\Storage\StorageException;

/**
 * Server side of the paste lifecycle (spec §6.3.1 and §10).
 *
 * Every proof is verified before any storage access; every failure that could reveal the
 * existence or state of a paste is reported as PasteUnavailableException (uniform 404).
 */
final class PasteService
{
    public const CONSUMED_RETENTION = 600;

    private readonly string $challengeKey;

    /**
     * @param Closure(string): (int|float|false) $freeSpace disk_free_space() of the storage root
     */
    public function __construct(
        private readonly InstanceConfig $config,
        private readonly PasteStore $store,
        private readonly IdempotencyStore $idempotency,
        private readonly StateFiles $stateFiles,
        private readonly Clock $clock,
        private readonly Closure $freeSpace,
    ) {
        $this->challengeKey = $config->secret->derive(Protocol::INFO_CHALLENGE);
    }

    /**
     * Idempotent creation (§10). $beforeCreate runs after the replay check and before any write
     * (creation rate limiting).
     *
     * @param Closure(): void|null $beforeCreate
     *
     * @return array{created: bool, id: PasteId, expires_at: int|null}
     *
     * @throws InvalidRequestException|IdempotencyConflictException|QuotaExceededException|StorageException
     */
    public function create(string $body, ?string $idempotencyKey, ?Closure $beforeCreate = null): array
    {
        // Step 1: syntax, independent of the configuration, so that a replay is answered even
        // after the limits were lowered (§10, steps 1-2). Only absolute ceilings apply here: the
        // largest AAD any configuration accepts; the ciphertext is already bounded by the body
        // size checked before parsing.
        $fields = RequestFields::parse($body, ['aad', 'nonce', 'ciphertext', 'deletion_hash']);
        $aadBytes = $fields->binary('aad', null, PasteSettings::MAX_METADATA_BYTES);
        $nonce = $fields->binary('nonce', Protocol::NONCE_BYTES);
        $ciphertext = $fields->binary('ciphertext');
        $deletionHash = $fields->binary('deletion_hash', 32);
        if (strlen($ciphertext) < Protocol::TAG_BYTES) {
            throw new InvalidRequestException('Ciphertext is too short.');
        }
        try {
            $aad = Aad::fromBytes($aadBytes);
        } catch (InvalidAadException) {
            throw new InvalidRequestException('Invalid AAD.');
        }
        if (!Ed25519::isAcceptablePublicKey($aad->accessPk)
            || ($aad->consumePk !== null && !Ed25519::isAcceptablePublicKey($aad->consumePk))) {
            throw new InvalidRequestException('Invalid public key.');
        }
        try {
            $key = Base64Url::decode($idempotencyKey ?? '', Protocol::IDEMPOTENCY_KEY_BYTES);
        } catch (InvalidEncodingException) {
            throw new InvalidRequestException('A valid Idempotency-Key header is required.');
        }
        $keyHash = hash('sha256', $key, true);
        $requestHash = hash('sha256', $body, true);

        // Step 2: replay.
        $existing = $this->idempotency->find($keyHash);
        if ($existing !== null) {
            return $this->replay($existing, $requestHash);
        }

        // Step 3: configuration-dependent checks, rate limiting, quotas, creation.
        $paste = $this->config->paste;
        if (strlen($ciphertext) > $paste->maxCiphertextBytes) {
            throw new PayloadTooLargeException('Payload larger than this instance allows.');
        }
        if (strlen($aadBytes) > $paste->maxMetadataBytes) {
            throw new InvalidRequestException('Metadata larger than this instance allows.');
        }
        if (!in_array($aad->expiration, $paste->acceptedExpirationCodes(), true)
            || ($aad->readOnce && !$paste->allowReadOnce)
            || ($aad->kdf !== null && !$paste->allowPassphrase)) {
            throw new InvalidRequestException('Option not allowed by this instance.');
        }
        if ($beforeCreate !== null) {
            $beforeCreate();
        }
        $now = $this->clock->now();
        $free = ($this->freeSpace)($this->config->storage->rootDir);
        if ($free === false || $free < $this->config->storage->minFreeBytes
            || !$this->stateFiles->healthAllowsCreation($now, $this->config->storage->minFreeInodesPercent)) {
            throw new QuotaExceededException('Storage threshold reached.');
        }
        $seconds = Duration::expirationSeconds($aad->expiration);
        $expiresAt = $seconds === null ? null : $now + $seconds;

        $id = $this->store->create(
            static fn (PasteId $id): PasteMeta => new PasteMeta($id, $aadBytes, $now, $expiresAt, $aad->readOnce, $deletionHash, $keyHash),
            static fn (): PasteId => PasteId::fromBytes(Identifier::generate($aad->accessPk, $deletionHash)),
            $nonce . $ciphertext,
        );

        // Step 4: write-once publication of the idempotency record.
        $retain = $now + $paste->idempotencyMaxTtlSeconds;
        $record = new IdempotencyRecord($keyHash, $requestHash, $id, $expiresAt, $expiresAt === null ? $retain : min($retain, $expiresAt));
        try {
            $published = $this->idempotency->publish($record);
        } catch (StorageException $e) {
            $this->store->remove($id);
            throw $e;
        }
        if (!$published) {
            // Step 5: lost race; the identifier was never disclosed.
            $this->store->remove($id);
            $winner = $this->idempotency->find($keyHash) ?? throw new StorageException('Idempotency record vanished.');

            return $this->replay($winner, $requestHash);
        }

        return ['created' => true, 'id' => $id, 'expires_at' => $expiresAt];
    }

    /**
     * Stateless challenge (no storage access, identical for existing and unknown ids).
     *
     * @throws InvalidRequestException
     */
    public function challenge(string $encodedId, string $body): string
    {
        $id = $this->parseId($encodedId, InvalidRequestException::class);
        $usage = RequestFields::parse($body, ['usage'])->string('usage');
        $code = match ($usage) {
            'open' => Challenge::USAGE_OPEN,
            'status' => Challenge::USAGE_STATUS,
            default => throw new InvalidRequestException('Unknown challenge usage.'),
        };

        return Base64Url::encode(Challenge::issue($this->challengeKey, $code, $id->bytes(), $this->clock->now()));
    }

    /**
     * Issues a challenge for a path id already validated by the caller (HTML page meta tag).
     */
    public function issueChallenge(PasteId $id, int $usage): string
    {
        return Base64Url::encode(Challenge::issue($this->challengeKey, $usage, $id->bytes(), $this->clock->now()));
    }

    /**
     * @return array{aad: string, expires_at: int|null, read_once: bool, state: string|null, retry_after: int|null, unconfirmed_opens: int|null}
     *
     * @param (Closure(PasteId): void)|null $onValidProof per-paste rate limiting, after a valid proof only
     *
     * @throws PasteUnavailableException
     */
    public function status(string $encodedId, string $body, ?Closure $onValidProof = null): array
    {
        [$id, $accessPk] = $this->verifyAccessProof($encodedId, $body, Challenge::USAGE_STATUS, []);
        if ($onValidProof !== null) {
            $onValidProof($id);
        }
        $record = $this->loadVerified($id, $accessPk);
        if ($record->meta->readOnce) {
            $record = $this->store->mutate($id, fn (PasteRecord $r): array => [$this->releaseIfExpired($r), $this->current($r)]);
            if ($record === null || $record->state->name !== StateName::Available && $record->state->name !== StateName::Reserved) {
                throw new PasteUnavailableException();
            }
        }
        $reservation = $record->state->name === StateName::Reserved ? $record->state->reservation : null;

        return [
            'aad' => Base64Url::encode($record->meta->aad),
            'expires_at' => $record->meta->effectiveExpiresAt($this->config->paste->maxRetentionSeconds),
            'read_once' => $record->meta->readOnce,
            'state' => $record->meta->readOnce ? $record->state->name->value : null,
            'retry_after' => $reservation === null ? null : max(0, $reservation->expiresAt - $this->clock->now()),
            'unconfirmed_opens' => $record->meta->readOnce ? $record->state->unconfirmedOpens : null,
        ];
    }

    /**
     * @return array{aad: string, nonce: string, ciphertext: string, expires_at: int|null, read_once: bool, consume_challenge: string|null, unconfirmed_opens: int|null, retry_after: int|null}
     *
     * @param (Closure(PasteId): void)|null $onValidProof per-paste rate limiting, after a valid proof only
     *
     * @throws PasteUnavailableException|ReservationConflictException|InvalidRequestException
     */
    public function open(string $encodedId, string $body, ?Closure $onValidProof = null): array
    {
        [$id, $accessPk, $fields] = $this->verifyAccessProof($encodedId, $body, Challenge::USAGE_OPEN, ['reservation_id']);
        if ($onValidProof !== null) {
            $onValidProof($id);
        }
        $record = $this->loadVerified($id, $accessPk);

        if (!$record->meta->readOnce) {
            $payload = $this->store->readPayload($id) ?? throw new PasteUnavailableException();

            return $this->openResponse($record, $payload, null, null, null);
        }

        if (!$fields->has('reservation_id')) {
            throw new InvalidRequestException('A reservation identifier is required for read-once content.');
        }
        $reservationHash = hash('sha256', $fields->binary('reservation_id', Protocol::RESERVATION_ID_BYTES), true);

        $result = $this->store->mutate($id, function (PasteRecord $r) use ($id, $reservationHash): array {
            $now = $this->clock->now();
            if ($r->meta->isExpired($now, $this->config->paste->maxRetentionSeconds)) {
                return [null, null];
            }
            $state = $this->releaseIfExpired($r) ?? $r->state;
            if ($state->name === StateName::Available) {
                $challenge = Challenge::issue($this->challengeKey, Challenge::USAGE_CONSUME, $id->bytes(), $now);
                $reserved = $state->withReservation(new Reservation($reservationHash, $challenge, $now + $this->config->paste->readOnceReservationTtl));
                $payload = $this->store->readPayload($id);

                return $payload === null ? [null, null] : [$reserved, ['payload' => $payload, 'state' => $reserved]];
            }
            if ($state->name === StateName::Reserved && $state->reservation !== null) {
                if (!hash_equals($state->reservation->idHash, $reservationHash)) {
                    return [$state === $r->state ? null : $state, ['conflict' => max(1, $state->reservation->expiresAt - $now)]];
                }
                $payload = $this->store->readPayload($id);

                return [null, $payload === null ? null : ['payload' => $payload, 'state' => $state]];
            }

            return [$state === $r->state ? null : $state, null];
        });

        if (!is_array($result)) {
            throw new PasteUnavailableException();
        }
        if (isset($result['conflict'])) {
            throw new ReservationConflictException($result['conflict']);
        }
        $state = $result['state'];
        $reservation = $state->reservation ?? throw new PasteUnavailableException();

        return $this->openResponse(
            $record,
            $result['payload'],
            Base64Url::encode($reservation->consumeChallenge),
            $state->unconfirmedOpens,
            max(0, $reservation->expiresAt - $this->clock->now()),
        );
    }

    /**
     * Confirms a read-once reading; an exact replay of the consuming proof succeeds again.
     *
     * @throws PasteUnavailableException|InvalidRequestException
     */
    public function consume(string $encodedId, string $body): void
    {
        $id = $this->parseId($encodedId, PasteUnavailableException::class);
        try {
            $fields = RequestFields::parse($body, ['access_pk', 'reservation_id', 'challenge', 'signature']);
            $accessPk = $fields->binary('access_pk', Protocol::PUBLIC_KEY_BYTES);
            $reservationHash = hash('sha256', $fields->binary('reservation_id', Protocol::RESERVATION_ID_BYTES), true);
            $challenge = $fields->binary('challenge', Challenge::LENGTH);
            $signature = $fields->binary('signature', Protocol::SIGNATURE_BYTES);
        } catch (InvalidRequestException) {
            // Same uniform answer as any invalid proof (§10).
            throw new PasteUnavailableException();
        }
        if (!Identifier::matchesAccessKey($id->bytes(), $accessPk)) {
            throw new PasteUnavailableException();
        }

        // Pre-checks without the exclusive lock (§10).
        $record = $this->loadVerified($id, $accessPk, allowConsumed: true);
        if ($record->state->reservation === null || !hash_equals($record->state->reservation->idHash, $reservationHash)) {
            throw new PasteUnavailableException();
        }

        $signatureHash = hash('sha256', $signature, true);
        $ok = $this->store->mutate($id, function (PasteRecord $r) use ($reservationHash, $challenge, $signature, $signatureHash): array {
            $now = $this->clock->now();
            $reservation = $r->state->reservation;
            if ($reservation === null || !hash_equals($reservation->idHash, $reservationHash)
                || !hash_equals($reservation->consumeChallenge, $challenge)) {
                return [null, false];
            }
            if ($r->state->name === StateName::Consumed) {
                $replay = $r->state->consumedSignatureHash !== null
                    && hash_equals($r->state->consumedSignatureHash, $signatureHash)
                    && $r->state->terminalAt !== null && $now < $r->state->terminalAt + self::CONSUMED_RETENTION;

                return [null, $replay];
            }
            if ($r->state->name !== StateName::Reserved) {
                return [null, false];
            }
            // The reservation clock also runs while a large response downloads: a lapsed
            // reservation nobody else took still accepts its confirmation for one more lifetime
            // (only the reader who decrypted can sign the consume challenge).
            if ($reservation->expiresAt + $this->config->paste->readOnceReservationTtl <= $now) {
                return [$this->releaseIfExpired($r), false];
            }
            $aad = Aad::fromBytes($r->meta->aad);
            if ($r->meta->isExpired($now, $this->config->paste->maxRetentionSeconds) || $aad->consumePk === null || !AccessProof::verify($aad->consumePk, $challenge, $signature)) {
                return [null, false];
            }

            return [$r->state->consumed($now, $signatureHash), true];
        });

        if ($ok !== true) {
            throw new PasteUnavailableException();
        }
    }

    /**
     * Deletion with the token bound to the identifier (§10). Returns normally on success.
     *
     * @throws PasteUnavailableException
     */
    public function delete(string $encodedId, ?string $token): void
    {
        $id = $this->parseId($encodedId, PasteUnavailableException::class);
        if ($token === null || !DeletionToken::matchesIdentifier($token, $id->bytes())) {
            throw new PasteUnavailableException();
        }
        $tokenHash = DeletionToken::hash(Base64Url::decode($token, DeletionToken::BYTES));

        $record = $this->store->find($id);
        if ($record === null || !hash_equals($record->meta->deletionTokenHash, $tokenHash)) {
            throw new PasteUnavailableException();
        }
        $now = $this->clock->now();
        if ($record->state->name !== StateName::Consumed && $record->meta->isExpired($now, $this->config->paste->maxRetentionSeconds)) {
            $this->store->remove($id);
            throw new PasteUnavailableException();
        }

        // A valid token deletes in any state, consumed included (spec §10, ADR-0008). A removal
        // lost to a concurrent deletion or purge answers like any unavailable paste.
        if (!$this->store->remove($id, static fn (PasteRecord $r): bool => hash_equals($r->meta->deletionTokenHash, $tokenHash))) {
            throw new PasteUnavailableException();
        }
    }

    /**
     * Applies the release of an expired reservation (T5) or the threshold destruction (T6).
     */
    public function releaseIfExpired(PasteRecord $record, int $grace = 0): ?PasteState
    {
        $state = $record->state;
        if ($state->name !== StateName::Reserved || $state->reservation === null || $state->reservation->expiresAt + $grace > $this->clock->now()) {
            return null;
        }
        if ($state->unconfirmedOpens + 1 >= $this->config->paste->maxUnconfirmedOpens) {
            return $state->consumed($this->clock->now(), null, true);
        }

        return $state->released();
    }

    /**
     * @return array{created: bool, id: PasteId, expires_at: int|null}
     */
    private function replay(IdempotencyRecord $record, string $requestHash): array
    {
        if (!hash_equals($record->requestSha256, $requestHash)) {
            throw new IdempotencyConflictException('Idempotency-Key already used with another body.');
        }

        return ['created' => false, 'id' => $record->pasteId, 'expires_at' => $record->expiresAt];
    }

    /**
     * Verifies an open/status proof without any storage access (§6.3.1).
     *
     * @param list<string> $optional
     *
     * @return array{0: PasteId, 1: string, 2: RequestFields}
     */
    private function verifyAccessProof(string $encodedId, string $body, int $usage, array $optional): array
    {
        $id = $this->parseId($encodedId, PasteUnavailableException::class);
        try {
            $fields = RequestFields::parse($body, ['challenge', 'access_pk', 'signature'], $optional);
            $challenge = $fields->binary('challenge', Challenge::LENGTH);
            $accessPk = $fields->binary('access_pk', Protocol::PUBLIC_KEY_BYTES);
            $signature = $fields->binary('signature', Protocol::SIGNATURE_BYTES);
        } catch (InvalidRequestException) {
            throw new PasteUnavailableException();
        }
        if (!Challenge::verify($this->challengeKey, $challenge, $usage, $id->bytes(), $this->clock->now())
            || !Identifier::matchesAccessKey($id->bytes(), $accessPk)
            || !AccessProof::verify($accessPk, $challenge, $signature)) {
            throw new PasteUnavailableException();
        }

        return [$id, $accessPk, $fields];
    }

    private function loadVerified(PasteId $id, string $accessPk, bool $allowConsumed = false): PasteRecord
    {
        $record = $this->store->find($id) ?? throw new PasteUnavailableException();
        $now = $this->clock->now();
        $consumed = $record->state->name === StateName::Consumed;
        if (($consumed && !$allowConsumed) || (!$consumed && $record->meta->isExpired($now, $this->config->paste->maxRetentionSeconds))) {
            throw new PasteUnavailableException();
        }
        try {
            $aad = Aad::fromBytes($record->meta->aad);
        } catch (InvalidAadException) {
            throw new PasteUnavailableException();
        }
        if (!hash_equals($aad->accessPk, $accessPk)) {
            throw new PasteUnavailableException();
        }

        return $record;
    }

    private function current(PasteRecord $record): ?PasteRecord
    {
        if ($record->meta->isExpired($this->clock->now(), $this->config->paste->maxRetentionSeconds)) {
            return null;
        }
        $state = $this->releaseIfExpired($record) ?? $record->state;

        return new PasteRecord($record->meta, $state);
    }

    /**
     * @param class-string<InvalidRequestException|PasteUnavailableException> $exception
     */
    private function parseId(string $encodedId, string $exception): PasteId
    {
        try {
            return PasteId::fromEncoded($encodedId);
        } catch (InvalidEncodingException) {
            throw new $exception('Invalid identifier.');
        }
    }

    /**
     * @return array{aad: string, nonce: string, ciphertext: string, expires_at: int|null, read_once: bool, consume_challenge: string|null, unconfirmed_opens: int|null, retry_after: int|null}
     */
    private function openResponse(PasteRecord $record, string $payload, ?string $consumeChallenge, ?int $unconfirmedOpens, ?int $retryAfter): array
    {
        return [
            'aad' => Base64Url::encode($record->meta->aad),
            'nonce' => Base64Url::encode(substr($payload, 0, Protocol::NONCE_BYTES)),
            'ciphertext' => Base64Url::encode(substr($payload, Protocol::NONCE_BYTES)),
            'expires_at' => $record->meta->effectiveExpiresAt($this->config->paste->maxRetentionSeconds),
            'read_once' => $record->meta->readOnce,
            'consume_challenge' => $consumeChallenge,
            'unconfirmed_opens' => $unconfirmedOpens,
            'retry_after' => $retryAfter,
        ];
    }
}
