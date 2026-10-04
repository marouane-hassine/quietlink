# ADR-0004: Local secret detection patterns

- Status: Accepted
- Date: 2026-10-03
- Spec reference: `docs/cahier-des-charges.md` §19.2

## Context

Local secret detection that suggests the "Secret" preset is a **Could** item (§0.3). Project rules forbid implementing a Could item before the related Must items are done.

## Decision

Defer the definition of detection patterns to **V1.1**. No pattern list is frozen in Phase 0. When implemented, detection must run client-side only and patterns must be written from scratch (no third-party rule set copied).

## Consequences

- No code or tests for secret detection in V1 unless all Must items are complete.
- This ADR will be superseded by the ADR that fixes the pattern list.
- Update 2026-10-03: the Secret preset is suggested (never applied silently) when a built-in template
  with sensitive fields is chosen; only pattern-based detection of pasted secrets remains deferred.
