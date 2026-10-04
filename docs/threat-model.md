# QuietLink threat analysis

- Document version: 1.0
- Review date: 2026-10-03
- Protocol: `sp-proto/v1` (`docs/protocol/sp-proto-v1.md`)
- Specification reference: `docs/cahier-des-charges.md` v0.18, §7.1–§7.4
- Requirements: EXG-SEC-041 (each covered threat mapped to mitigation and test), EXG-SEC-080
  (threat analysis kept with the code), EXG-DOC-008, EXG-DOC-009, EXG-DOC-016
- Status: pre-audit. Every row below must be re-checked by the external security review (§7.5).

This document is kept in the repository next to the code it describes. Any change to the
protocol, the storage format, the logging policy or the deployment templates must update it in
the same pull request.

## 1. Scope and assets

In scope: the browser frontend (`frontend/src/`), the Symfony API and pages (`src/`), the PHP CLI
(`src/Cli/`), the file store (`src/Storage/`), maintenance commands (`src/Maintenance/`) and the
reference deployment (`docker/`).

Assets, by decreasing sensitivity:

| Asset | Where it exists | Never present on |
|---|---|---|
| Plaintext text (envelope) | Creator and reader browser/CLI memory | Server, storage, logs |
| Link key `K_url` (URL fragment) and derived keys (`K_enc`, access and consume seeds) | Share link, client memory | Server, storage, logs |
| Passphrase and `K_pass` | Client memory (Argon2id worker) | Server, storage, logs |
| Raw deletion token (management link) | Creator, management link | Server (only `SHA-256(token)` is sent and stored), logs |
| Reservation identifier (read-once) | Reader `sessionStorage` | Storage (only its hash), `localStorage`, cookies, URL, logs |
| Ciphertext, nonce, AAD | Client, server in transit, `payload.bin` / `meta.json` | Logs |
| Metadata: identifier, `created_at`, `expires_at`, size, read-once flag, state, `unconfirmed_opens` | Storage, API responses | Logs (route template only, ADR-0005) |
| Instance secret `QUIETLINK_APP_SECRET` (challenge HMAC key) | Server environment or secret file | Storage, logs, error output |
| Service availability | Instance | — (availability is not covered, §7.3) |

## 2. Security property (§7.1)

The server must not be able to decrypt stored content, even with full access to the file store
holding the payloads. Encryption (AES-256-GCM), key derivation (HKDF-SHA-256, Argon2id) and the
access/consume proofs (Ed25519) all run on the client; the server only stores a ciphertext and
verifies proofs of possession of the link key without learning it.

This property does **not** hold against an instance that serves malicious JavaScript (§5). The
user does not have to trust the instance to keep the plaintext secret from it, but browser users
do trust the instance to serve honest code (§7.4).

## 3. Trust boundaries

| Boundary | What crosses it | Trust placed | Controls |
|---|---|---|---|
| **Browser / device** | Plaintext, keys, passphrase are created and used here | Fully trusted (§7.4); compromise is not covered | Web Crypto first, `@noble/ed25519` fallback only if Web Crypto fails the RFC 8032 self-test (`frontend/src/crypto/ed25519.ts`); Argon2id in a dedicated worker; reservation id in `sessionStorage` only (`frontend/src/reservation.ts`) |
| **Link fragment** | `#` part of the share link carries `K_url` | Never sent by browsers in HTTP requests; leaks through history, screenshots, previews (partly covered: the fragment is removed from the address bar and current history entry once the paste is destroyed, deleted or unavailable, ADR-0009) | Identifier binds `A = tronc64(SHA-256(... access_pk))` and `D` (deletion hash), so a truncated or altered link is detected locally without a network call (`src/Crypto/Identifier.php`, `frontend/src/crypto/protocol.ts`); `Referrer-Policy: no-referrer` |
| **Server (PHP-FPM / Symfony)** | Ciphertext, AAD, public keys, signatures, hashes | Trusted to store and serve without undetectable modification; **not** trusted with plaintext | Stateless challenges verified before any storage access (`src/Crypto/Challenge.php`, `PasteService::verifyAccessProof`); uniform `404`; strict CSP (`src/EventSubscriber/SecurityHeadersSubscriber.php`); rate limits (`src/RateLimit/RateLimiter.php`) |
| **Storage (file volume)** | `meta.json`, `state.json`, `payload.bin`, idempotency records, usage and health files | Untrusted for confidentiality; trusted for availability | Only ciphertext and verifiers stored (`src/Storage/FilesystemPasteStore.php`, `RecordCodec`); `0600`/`0700` permissions, sharding; `flock()` + temp file + `rename()`/`link()` (`src/Storage/FileLock.php`, `AtomicFile.php`); fail-closed on corrupted or unknown versions |
| **Reverse proxy (Nginx/Apache)** | TLS termination, client address, request line | Trusted for transport security; only listed proxies may set `X-Forwarded-*` | `access_log off`, dotfiles denied, security headers duplicated on static assets (`docker/nginx/default.conf`); `http.trusted_proxies` applied by `src/EventSubscriber/RuntimeGuardSubscriber.php`; address normalisation in `src/Http/ClientAddress.php` |
| **Operator** | Configuration, instance secret, volumes, backups, logs | Trusted to deploy honest code; not trusted with plaintext | No backoffice or admin endpoint; no secret in config output (`ConfigCheckCommand`, `AppSecret`); logs restricted by ADR-0005; backups contain ciphertext only |

## 4. Covered threats (§7.2)

Test references use `file::test` for PHPUnit and Vitest, and the Playwright journey title for
end-to-end tests. "Group" requirement identifiers are those carried by the cited tests.

| # | Threat (CDC §7.2) | Mitigation (file / class) | Verified by | Residual risk |
|---|---|---|---|---|
| T1 | An attacker reads the storage directory | Client-side AES-256-GCM; server stores only ciphertext, nonce, canonical AAD, public keys and SHA-256 verifiers (`src/Storage/FilesystemPasteStore.php`, `src/Storage/RecordCodec.php`); private file modes and sharding; `K_url`, passphrase, raw deletion token and raw reservation id never reach the server (`frontend/src/crypto/envelope.ts`, `frontend/src/api.ts`) | `tests/Storage/FilesystemPasteStoreTest.php::testStoredFilesContainNoForbiddenField`, `::testFilesAreShardedAndPrivate`; `tests/Crypto/SpProtoV1VectorsTest.php::testContentEncryptionRoundTrip`; e2e "creates and reads a paste; the server never receives the text or the key" | Metadata (times, size, read-once flag, state) is readable. A reader of the store who also obtains a full link can decrypt. Weak passphrases remain guessable offline (§6). |
| T2 | Leak of encrypted backups | Same as T1: backups only hold ciphertext and verifiers; the instance secret is not stored in the volume (`src/Config/AppSecret.php`); deletion via `unlink()` documented (`docs/README-admin.md` §7.5, §17) | Same tests as T1 | A restored backup can resurrect a consumed or deleted paste (read-once is server-enforced); deleted data may persist in snapshots. Documented in `docs/README-admin.md` §17. |
| T3 | Abusive access to a content identifier without the key | Ed25519 access proof over a stateless HMAC challenge; verification order MAC → freshness → id/usage → `A` binding → signature, all before storage access (`src/Crypto/Challenge.php`, `src/Crypto/AccessProof.php`, `PasteService::verifyAccessProof`); stored `access_pk` compared with `hash_equals`; challenge issued identically for unknown identifiers | `tests/Paste/PasteServiceTest.php::testInvalidProofNeverTouchesStorage`, `::testChallengeIsIdenticalInShapeForUnknownIdentifiers`; `tests/Crypto/SpProtoV1VectorsTest.php::testChallengeVerificationChecksMacUsageIdentifierAndFreshness`, `::testEd25519ProofOverChallenge`, `::testSmallOrderOrNonCanonicalPublicKeyIsRejected`; `tests/Http/ApiTest.php::testUnavailabilityIsUniform`; `tests/Web/PageTest.php::testReadPageEmbedsStatelessChallengesAndNoInlineScript` | Response timing between "invalid proof" and "valid proof, unknown content" is not measured by tests. The proof shows possession of the link, not of the passphrase (by design, protocol §10.4). |
| T4 | Detectable payload tampering | AES-256-GCM with canonical AAD binding identifier, options and `access_pk`/`consume_pk` (`src/Crypto/Aad.php`, `src/Crypto/ContentCipher.php`, `frontend/src/crypto/aad.ts`); client checks AAD byte equality between `status` and `open` and `access_pk` against the derived key (`frontend/src/pages/read.ts`); link integrity via `A`/`D` binding | `tests/Crypto/SpProtoV1VectorsTest.php::testTamperedContentFailsToDecrypt`, `::testNonCanonicalOrInvalidAadIsRejected`, `::testAadCanonicalisation`, `::testIdentifierBindsAccessKeyAndDeletionHash`; `frontend/tests/vectors.test.ts` (`aes-256-gcm` "rejects …", `aad`); `tests/Cli/CliTest.php::testMetadataFromAnotherPasteIsRejected`, `::testAlteredLinkIsRejectedBeforeAnyRequest`; e2e "an incomplete link is reported without contacting the API" | `created_at`/`expires_at` are not in the AAD and can be altered by the server or storage; a link holder with write access to storage can substitute content (`K_enc` derives from `K_url`). Both are listed in §7.3. |
| T5 | Replay attacks on endpoints | `open`/`status` challenges valid 60 s, usage-bound, HMAC-authenticated (ADR-0007 OQ-7); `consume` challenge bound to one reservation and compared in constant time; only the exact same consume proof is replayed idempotently, any other reuse is `404`; creation replays governed by `Idempotency-Key` + body SHA-256 (`src/Storage/IdempotencyStore.php`); deletion token bound to the identifier (`src/Crypto/DeletionToken.php`) | `tests/Paste/PasteServiceTest.php::testExpiredChallengeIsRefused`, `::testReadOnceReservationConsumptionAndReplay`, `::testConsumeWithAnotherKeyOrReservationFails`, `::testIdempotentReplayReturnsTheSameIdentifier`, `::testDeletionRequiresTheBoundToken`; `tests/Crypto/CryptoPrimitivesTest.php::testChallengeIssuedInTheFutureIsRefused`; `tests/Crypto/SpProtoV1VectorsTest.php::testDeletionTokenIsBoundToIdentifier`; `tests/Storage/IdempotencyRaceTest.php::testOnlyOnePublisherReplacesAnExpiredRecord`; `tests/Cli/ApiClientRetryTest.php::testRetriesReuseTheSameKeyAndBody` | A valid `open`/`status` proof can be replayed within 60 s (no replay cache, by design); it only yields what the link holder already has, and read-once stays governed by reservations. |
| T6 | Massive brute force on identifiers | Identifier `A ‖ D ‖ R` (24 bytes) where `A` binds the access key: guessing an identifier gives nothing without a valid Ed25519 proof (`src/Crypto/Identifier.php`); per-address and per-paste rate limits, per-paste limits counting only valid proofs (`src/RateLimit/RateLimiter.php`, ADR-0007 defaults); uniform `404` | `tests/Crypto/CryptoPrimitivesTest.php::testGeneratedIdentifiersShareFingerprintsButDifferInRandomPart`; `tests/Storage/FilesystemPasteStoreTest.php::testIdentifierCollisionDrawsANewIdentifier`; `tests/Http/ApiTest.php::testPerPasteLimitCountsOnlyValidProofs`, `::testUnavailabilityIsUniform`; `tests/Paste/PasteServiceTest.php::testInvalidProofNeverTouchesStorage` | Per-address buckets for `challenge`, `status`, `open`, `consume`, `delete` have no dedicated test (see Gaps). Distributed attackers bypass per-address limits; the cryptographic binding remains the primary defence. |
| T7 | A third party knowing only the identifier obtains the ciphertext, reserves or blocks a read-once paste | `open`, `status` and `consume` require proofs of possession of `K_url` (access key) and, for `consume`, of `consume_sk`; `409` is returned only after a valid proof; per-paste limits count only valid proofs | `tests/Paste/PasteServiceTest.php::testInvalidProofNeverTouchesStorage`, `::testConsumeWithAnotherKeyOrReservationFails`; `tests/Http/ApiTest.php::testPerPasteLimitCountsOnlyValidProofs`, `::testUnavailabilityIsUniform` | Anyone holding the full link can reserve (and, without passphrase, consume) the paste; this is the link-holder case, not the identifier-only case. |
| T8 | Unconfirmed read of a read-once paste, signalled to the next reader (§6.3.1) | Reservation with timeout; each expiry increments `unconfirmed_opens`; at `paste.max_unconfirmed_opens` the paste becomes `consumed`; lazy release under lock and by the purge (`PasteService::releaseIfExpired`, `src/Maintenance/Purger.php`); client warning `read.priorOpens_one` / `read.priorOpens_other` (`frontend/src/pages/read.ts`) | `tests/Paste/PasteServiceTest.php::testUnconfirmedOpensAreCountedThenDestroyThePaste`, `::testReadOnceReservationConsumptionAndReplay`; `tests/Maintenance/PurgerTest.php::testStaleReservationsAreReleasedWithoutARequest` | Silent reading by a link holder is **signalled, not prevented** (§6). The client-side warning display has no automated test (see Gaps). |
| T9 | Storage saturation through mass creation | Count and byte quotas (`src/Storage/UsageCounter.php`, `QuotaExceededException`); creation refused when free space/inodes are low or `health.json` is missing or stale (ADR-0007 OQ-08); body size limit before parsing; creation rate limit; orphan and expiry purge; 80 % quota alert | `tests/Storage/FilesystemPasteStoreTest.php::testQuotaRefusesCreationAndRollsBackNothing`, `::testByteQuotaIsEnforced`; `tests/Paste/PasteServiceTest.php::testLowDiskSpaceRefusesCreation`, `::testStaleHealthRefusesCreation`; `tests/Storage/IdempotencyAndStateFilesTest.php::testStaleOrMissingHealthBlocksCreation`; `tests/Http/ApiTest.php::testOversizedBodyIsRejectedBeforeParsing`, `::testCreationIsRateLimited`; `tests/Maintenance/PurgerTest.php::testExpiredPastesAreRemovedAndPurgeIsIdempotent`, `::testOrphanWithoutIdempotencyRecordIsRemovedAfterFifteenMinutes`, `::testHourlyRecomputationCorrectsDrift`, `::testQuotaAboveEightyPercentRaisesAnOperationalAlert` | A distributed attacker can still fill the quota and deny creation to others (availability is not covered, §7.3). |
| T10 | Scraping and automated abuse | Rate limits per address and per paste (`src/RateLimit/RateLimiter.php`); pages `noindex` and `no-store`; no listing or search endpoint; content only reachable with a valid proof; JSON-only bodies, no `multipart/form-data` | `tests/Http/ApiTest.php::testCreationIsRateLimited`, `::testPerPasteLimitCountsOnlyValidProofs`, `::testOnlyJsonBodiesAreAccepted`; `tests/Web/PageTest.php::testPagesAreNotCacheableAndNotIndexed`; e2e "no file input and the interface switches to French" | No CAPTCHA or proof of work. Client address depends on correct `http.trusted_proxies`, which has no automated test (see Gaps). |
| T11 | Accidental exposure of content in application logs | JSON logger with route template only, no IP, User-Agent, identifiers, headers, bodies or exception messages (`src/Log/JsonLogger.php`, `src/EventSubscriber/RequestLogSubscriber.php`, ADR-0005); generic `problem+json` errors (`src/EventSubscriber/ExceptionSubscriber.php`); `Cache-Control: no-store`; Nginx `access_log off` (`docker/nginx/default.conf`); secrets not echoed by configuration checks | `tests/Log/JsonLoggerTest.php::testSecretsIdentifiersAndUrlsNeverReachTheLog`; `tests/Config/ConfigLoaderTest.php::testInvalidSecretIsRejectedWithoutBeingDisclosed`, `::testSecretCheckDoesNotRevealTheSecret`; `tests/Http/ApiTest.php::testSecurityHeadersAreSetOnEveryResponse`, `::testUnavailabilityIsUniform`; `tests/Web/PageTest.php::testPagesAreNotCacheableAndNotIndexed` | The reference Nginx configuration (`access_log off`) is not tested automatically; operator-added proxies, CDNs or PHP-FPM slow logs can log URLs and addresses (see Gaps; §7.3 IP correlation). |
| T12 | Concurrency on read-once | Exclusive `flock()` on the paste lock with bounded non-blocking retries (ADR-0007 OQ-06, `src/Storage/FileLock.php`); state written by temp file + `rename()`; re-check under lock before reserving and consuming; `409` for a competing reservation; idempotent creation race handled with `link()` | `tests/Paste/PasteConcurrencyTest.php::testOnlyOneConcurrentReaderObtainsTheReservation`; `tests/Storage/FilesystemPasteStoreTest.php::testMutationIsAppliedAtomicallyUnderLock`, `::testConsumedPasteLosesItsPayloadAndBytes`; `tests/Storage/IdempotencyRaceTest.php::testOnlyOnePublisherReplacesAnExpiredRecord`; `tests/Maintenance/PurgerTest.php::testConcurrentPurgeExitsImmediately` | `flock()` semantics on network filesystems are not guaranteed; only local filesystems are supported (`docs/README-admin.md` §7.3). |
| T13 | Dangerous HTML or Markdown content | markdown-it with `html: false`, http(s)-only links with `rel="noopener noreferrer"`, images rendered as text, DOMPurify allow-list without `style` (`frontend/src/render/markdown.ts`); syntax highlighting with classes only (`frontend/src/render/highlight.ts`); strict CSP without `unsafe-inline`/`unsafe-eval`, `frame-ancestors 'none'` (`SecurityHeadersSubscriber`, `docker/nginx/default.conf`) | `frontend/tests/markdown.test.ts::markdown sanitisation › neutralises %s` (script, `onerror`, iframe, `javascript:`, `data:`, `file:`, `onclick`, `style`, SVG script), `::keeps http(s) links with rel noopener noreferrer and an indicator`, `::shows images as alt text and URL, never loads them`, `::highlights code with classes only`; `tests/Http/ApiTest.php::testSecurityHeadersAreSetOnEveryResponse`; `tests/Web/PageTest.php::testReadPageEmbedsStatelessChallengesAndNoInlineScript` | Depends on DOMPurify and markdown-it updates (dependency review). Links to malicious sites remain clickable by the reader (indicator shown). |

All 13 threats of CDC §7.2 are listed.

## 5. Not covered (§7.3)

| Threat (CDC §7.3) | User-facing documentation |
|---|---|
| A compromised server or CDN modifies the JavaScript sent to the browser | `docs/README-admin.md` §17 and §16 (incident response); in-app notice `how.limits` on the "How it works" page (`frontend/src/pages/how.ts`); `SECURITY.md` "Scope" |
| Compromised device or malicious browser | `docs/README-admin.md` §17; `SECURITY.md` "Scope" |
| Content substitution by a link holder with write access to storage (`K_enc` derives from `K_url`); modification of `created_at` / `expires_at` by the server or storage (dates not authenticated by the AAD) | This document (T4 residual risk); `docs/protocol/sp-proto-v1.md` §8 (AAD fields); `docs/README-admin.md` §17 (metadata visible to the server) |
| Local timing side channel against the JavaScript Ed25519 fallback (not guaranteed constant time); Web Crypto preferred | This document (§3, browser boundary); `frontend/src/crypto/ed25519.ts` selection comment |
| Screenshot or copy made by the recipient | `docs/README-admin.md` §17; `SECURITY.md` "Scope" |
| Leak of the full link through history, a screenshot or a preview tool | `docs/README-admin.md` §17 |
| Weak passphrase that has been disclosed | `docs/README-admin.md` §17; passphrase strength estimate and generator in the UI (`frontend/src/ui/passphrase.ts`) |
| Correlation by IP address and timestamp if the instance keeps network logs | `docs/README-admin.md` §11 (logging policy) and §9 (reverse proxy); ADR-0005 |
| Instance availability under failure or denial of service | `docs/README-admin.md` §10 (rate limiting) and §13 (health check) |

## 6. Server-compromise limitation (modified JavaScript)

The browser frontend is served by the instance itself. An attacker who controls the server, the
reverse proxy, the build pipeline or a CDN placed in front of the instance can serve modified
JavaScript that reads the plaintext before encryption, the fragment key `K_url` or the
passphrase, and sends them elsewhere. No client-side control can prevent this, because the
modified code runs with the same privileges as the honest code. The CSP limits exfiltration by
injected third-party content, but not by a modified first-party bundle.

Reductions in place or required by the specification: hashed static assets served by the
instance, no third-party resource, strict CSP, release integrity and dependency review
(`SECURITY.md`), a read-only container filesystem and non-root user (`docker/`). The PHP CLI,
installed separately from a release, encrypts locally and does not depend on the served
frontend. After a suspected compromise, every paste created or opened during the exposure
window must be considered disclosed (`docs/README-admin.md` §16).

## 7. Read-once limits

- **Silent reading by a link holder is signalled, not prevented.** A holder of the full link can
  call `open`, decrypt locally and never send `consume`. The reservation then expires, the paste
  returns to `available` and `unconfirmed_opens` is incremented; the next reader sees the
  `read.priorOpens_one` / `read.priorOpens_other` warning. After `paste.max_unconfirmed_opens` (default 3) unconfirmed
  reservations, the paste becomes `consumed`. Read-once therefore bounds the number of silent
  reads; it does not make them impossible.
- **Read-once is enforced by the server.** A malicious server or a restored backup can serve a
  consumed paste again (T2).
- **The access proof does not cover the passphrase.** A link holder without the passphrase can
  still reserve the paste. The passphrase is checked locally against `consume_pk` before `open`,
  so a wrong passphrase costs no reservation, but a link holder can obtain the ciphertext.
- **Offline passphrase guessing is bounded by Argon2id only.** Anyone with the ciphertext and
  `K_url` can guess passphrases offline; the cost per guess is set by the Argon2id parameters of
  protocol §2.1, not by server rate limits. Strength depends on the passphrase; the UI offers a
  six-word generator (≥ 66 bits).

## 8. Provisional decisions to confirm in the external audit (ADR-0007)

ADR-0007 answers the protocol and storage open questions provisionally. The external security
review (§7.5) must confirm or overturn each item; a change to an on-wire or stored format is a
breaking change.

1. Default rule: every open question in `docs/protocol/sp-proto-v1.md` §16 and
   `docs/storage-format.md` §13 is answered by its "Proposed" text.
2. Protocol OQ-5: Ed25519 verification follows libsodium semantics; `@noble/ed25519` with
   `zip215: false`; public keys rejected at creation when the curve25519 conversion fails or the
   encoding is non-canonical (`y < p`).
3. Protocol OQ-7: challenge freshness is `issued_at <= now <= issued_at + 60`.
4. Protocol OQ-14: JSON member names fixed by `docs/openapi.yaml` (snake_case).
5. Protocol OQ-20: wire timestamps use `YYYY-MM-DDTHH:MM:SSZ`.
6. Storage OQ-01: stored verifiers are unkeyed SHA-256, base64url without padding.
7. Storage OQ-02: idempotency keys are global; a collision with a different body yields `422`.
8. Storage OQ-06: request paths retry `LOCK_EX|LOCK_NB` for at most 2 s, then answer `503`.
9. Storage OQ-08: creation refused on low free inodes and when `health.json` is missing or older
   than 10 minutes.
10. Rate-limit default values (create 30 / 10 min, challenge 120 / min, open, status and consume
    60 / min, delete 30 / 10 min, health 60 / min per address; per paste open 20 / min and
    status 30 / min, valid proofs only).
11. Storage OQ-10: v1 JSON Schemas for `usage.json`, `health.json` and `boot.json`.

The audit should also confirm the absence of a replay cache for `open`/`status` (T5) and the
acceptability of the unauthenticated dates (T4).

## 9. Gaps

Covered threats, or parts of their mitigation, without an automated test. No test is claimed
where none exists.

1. **T8 — client warning for unconfirmed opens.** The server counter is tested, but no Vitest or
   Playwright test asserts that `read.priorOpens_one` / `read.priorOpens_other` is displayed when `unconfirmed_opens > 0`
   (`frontend/src/pages/read.ts`).
2. **T6 / T10 — per-address rate-limit buckets.** Only the `create` bucket and the per-paste
   `status` bucket are tested; the `challenge`, `open`, `consume`, `delete` and `health`
   per-address buckets and the per-paste `open` bucket have no dedicated test, and
   `src/RateLimit/RateLimiter.php` has no unit test.
3. **T10 / reverse-proxy boundary — client address resolution.** No test covers
   `http.trusted_proxies` and `X-Forwarded-*` handling (`src/EventSubscriber/RuntimeGuardSubscriber.php`) or address normalisation (`src/Http/ClientAddress.php`); a
   misconfiguration would merge or spoof rate-limit subjects.
4. **T11 — reference web server configuration.** `access_log off` and the duplicated security
   headers in `docker/nginx/default.conf` are not checked by any automated test.
5. **T3 — timing uniformity.** No test measures that invalid proofs and unknown identifiers are
   indistinguishable by response time (only by status and body).
6. **T13 — CSP on generated themes and the Argon2id worker.** The worker-specific CSP
   (`'wasm-unsafe-eval'`) is defined only in `docker/nginx/default.conf` and is not tested.
