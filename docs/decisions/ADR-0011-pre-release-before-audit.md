# ADR-0011: Pre-release published before the cryptographic review (waiver)

- Status: Accepted
- Date: 2026-10-05

## Context

§7.5 of the specification (EXG-CRYPTO-015, EXG-SEC-084) requires an independent review of the
cryptographic protocol before any release tag, and `docs/PROGRESS.md` states "no tag before the
audit". The product owner nevertheless published `v1.0.0-beta.1` on 2026-10-04 as a GitHub
pre-release with signed images, to let operators try the deployment and to gather feedback. The
conformance audit of 2026-10-05 reported the contradiction.

## Decision

The pre-release is kept and covered by this written waiver:

1. Every pre-release (`vX.Y.Z-beta.N`) before the review is published as a GitHub pre-release,
   never marked as the latest release, and states in its release notes and in the README that
   the protocol has **not** yet been independently reviewed and that it must not be used for
   real secrets.
2. The independent cryptographic review remains mandatory before `v1.0.0` (release checklist,
   section 1); no stable tag is created before it.
3. Any finding of the review that changes the protocol (`sp-proto/v1`) produces a new protocol
   version with new vectors; data of pre-releases is not migrated.

## Consequences

- Operators who installed `v1.0.0-beta.1` are warned by the release notes; nothing is withdrawn
  (images and signatures stay in public logs anyway).
- `docs/PROGRESS.md` and the release checklist reference this waiver; it lapses at `v1.0.0`.

## Alternatives considered

- Withdrawing the pre-release and its tag: matches the specification literally, but signed
  images and transparency log entries cannot be erased.
- Making it non-public (draft release, private packages): hides it without informing those who
  already pulled it.
