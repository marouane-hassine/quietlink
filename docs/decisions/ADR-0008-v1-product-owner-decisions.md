# ADR-0008: Product owner decisions for V1

- Status: Accepted
- Date: 2026-10-03

## Context

Open points listed at the end of V1 development needed a product owner decision.

## Decision

1. **Deleting a consumed read-once paste** — the specification conflicts (§10, l. 1622: a valid token
   deletes in any state and a later consume gets `404`; §9.4.1, l. 1290: keep `consumed` for 10 minutes).
   §10 prevails: deletion is immediate in every state; an idempotent consume replay after deletion
   answers `404`. The reader already displays the text, so nothing is lost.
2. **ADR-0007** answers are frozen as the V1 baseline. Only the external audit can change them, through
   protocol or schema versioning.
3. **Publication** — `develop` is pushed to GitHub to run CI; `main` stays at the initial commit until
   release. **No tag** before the external audit and Phase 3.
4. **French word list** — the EFF English list is used for French until the Lexique-based list is built
   and reviewed (human action).
5. **Argon2id defaults** stay `m = 64 MiB, t = 3, p = 1`; a local measurement page
   (`npm run calibration`) supports the calibration on target devices; values change only if an
   entry-level phone exceeds about 5 s.
6. **Optional (MAY) items** — local format suggestion and confirmation before opening `http` links —
   are deferred to V1.1.
7. **Release registry** — GitHub Container Registry with keyless cosign signatures.

## Consequences

- docs/storage-format.md transition T11 and §6.4 updated; tests cover deletion of consumed pastes.
- The CDC §9.4.1 sentence at l. 1290 is superseded for manual deletion.
