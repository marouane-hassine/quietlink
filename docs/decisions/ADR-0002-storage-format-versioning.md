# ADR-0002: File storage format and versioning

- Status: Accepted
- Date: 2026-10-03
- Spec reference: `docs/cahier-des-charges.md` §19.2

## Context

Persistence is local files only (§9.4): `meta.json`, `state.json` and idempotency records. Their exact format and versioning strategy had to be fixed in Phase 0.

## Decision

- Every persisted JSON file carries an integer `schema_version` field.
- Each file type has a JSON Schema versioned in `docs/schemas/`.
- Readers **fail closed**: an unknown or missing `schema_version` is treated as unreadable (public response stays a uniform `404`, an internal error is logged without identifiers).
- Format changes are made only through explicit, tested migrations; an incompatible change is a breaking change (Conventional Commits `!`).
- The detailed layout, locks and atomic writes are specified in `docs/storage-format.md`.

## Consequences

- Per-file versioning allows gradual, per-paste migration instead of an all-or-nothing store upgrade.
- Schemas double as test fixtures for storage tests.
