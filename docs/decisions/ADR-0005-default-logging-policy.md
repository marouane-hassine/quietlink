# ADR-0005: Default logging policy

- Status: Accepted
- Date: 2026-10-03
- Spec reference: `docs/cahier-des-charges.md` §19.2

## Context

§14 allows request duration, status, error rate, resource usage and encrypted payload size; it requires avoiding durable IPs, full URLs, fragments, detailed User-Agent and correlatable identifiers. Retention must be configurable and documented.

## Decision

- Logs are JSON lines written to stderr (PSR-3).
- Default fields: timestamp, level, HTTP method, **route template** (never the concrete path or paste id), status code, duration, encrypted payload size.
- Never logged: IP address, User-Agent, paste identifiers, query string, headers (including `X-Deletion-Token` and `Idempotency-Key`), request or response bodies, exception messages that may contain user input.
- Log level is configurable; retention is delegated to the container runtime and documented in `docs/README-admin.md`.

## Consequences

- Diagnostics remain possible at aggregate level without any correlation between requests.
- A test suite asserts that forbidden fields never appear in log output.
