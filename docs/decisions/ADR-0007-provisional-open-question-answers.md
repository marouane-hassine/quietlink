# ADR-0007: Provisional answers to protocol and storage open questions

- Status: Accepted (provisional — to be confirmed by the security review, §7.5)
- Date: 2026-10-03
- Spec reference: `docs/protocol/sp-proto-v1.md` §16, `docs/storage-format.md` §13

## Context

Phase 0 left 29 protocol and 20 storage open questions. The product owner asked to continue
development up to V1 without blocking on them. Implementations need definite answers so that
PHP, TypeScript and the CLI agree byte for byte.

## Decision

Unless stated otherwise below, every open question is answered by the **"Proposed"** text of
the document that raised it. Additional choices:

- Protocol OQ-5: Ed25519 verification follows libsodium semantics; `@noble/ed25519` is used with
  `zip215: false`; public keys are rejected at creation when
  `sodium_crypto_sign_ed25519_pk_to_curve25519()` fails or the encoding is not canonical (`y < p`).
- Protocol OQ-7: a challenge is fresh iff `issued_at <= now <= issued_at + 60`.
- Protocol OQ-14: JSON member names are fixed by `docs/openapi.yaml` (snake_case):
  `challenge`, `usage`, `access_pk`, `signature`, `reservation_id`, `consume_challenge`,
  `state`, `retry_after` (seconds), `unconfirmed_opens`, `expires_at`, `server_time`.
- Protocol OQ-20: timestamps on the wire are `YYYY-MM-DDTHH:MM:SSZ`.
- Storage OQ-01: every stored verifier is an unkeyed SHA-256, base64url without padding.
- Storage OQ-02: idempotency keys are global; a collision with a different body yields `422`.
- Storage OQ-06: request paths retry `LOCK_EX|LOCK_NB` for at most 2 s, then answer `503`.
- Storage OQ-08: creation is refused when `health.json` reports free inodes below the threshold,
  and also when it is missing or older than 10 minutes (§7.5, "Anti-abus et disponibilité").
- Rate limits (§7.5 lists the buckets but no values): defaults per client address are create 30 /
  10 min, challenge 120 / min, open 60 / min, status 60 / min, consume 60 / min, delete 30 / 10 min,
  health 60 / min; per paste (valid proofs only) open 20 / min and status 30 / min. All are
  configurable under `http.rate_limits`.
- Storage OQ-10: `usage.json`, `health.json` and `boot.json` get v1 JSON Schemas written with
  their implementation.

## Consequences

- Each provisional answer is marked in code by a reference to this ADR.
- The security review may overturn any of them; a change to an on-wire or stored format is
  then a breaking change handled by protocol or schema versioning.
