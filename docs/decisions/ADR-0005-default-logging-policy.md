# ADR-0005: Default logging policy

- Status: Accepted
- Date: 2026-10-03
- Spec reference: `docs/cahier-des-charges.md` §19.2

## Context

§14 allows request duration, status, error rate, resource usage and encrypted payload size; it requires avoiding durable IPs, full URLs, fragments, detailed User-Agent and correlatable identifiers. Retention must be configurable and documented.

## Decision

- Logs are JSON lines written to stderr (PSR-3), or to the file `log.file` (default `var/log/quietlink.log`, outside `public/`, rotated at 5 MB with one archive) for shared hosting, where stderr is not collected (amendment, spec v0.24). The content rules are the same for both outputs.
- Default fields: timestamp, level, HTTP method, **route template** (never the concrete path or paste id), status code, duration, encrypted payload size.
- Never logged: IP address, User-Agent, paste identifiers, query string, headers (including `X-Deletion-Token` and `Idempotency-Key`), request or response bodies, exception messages that may contain user input.
- Log level is configurable; retention is delegated to the container runtime and documented in `docs/README-admin.md`.

## Consequences

- Diagnostics remain possible at aggregate level without any correlation between requests.
- A test suite asserts that forbidden fields never appear in log output.

## Amendment (2026-10-04): operations events and web server error log

### Context

Some operating problems produced no log line at all: a stopped purge (stale `health.json`, creation silently refused), a configuration changed without `app:boot` (every request answered `503`), or purge items failing run after run. Separately, the Nginx error log of the shipped image was set to `crit`, and Nginx entries at that level include the client address and the request line, which this policy forbids.

### Decision

- Operations events are logged at level `warning` with an `event` context field and nothing that identifies a paste, a path, a client or a request: `boot_marker_mismatch` (configuration invalid or different from the boot marker), `health_stale` (`health.json` missing or older than 10 minutes, noticed by `/healthz`), `purge_failures` (with a `count`), and the existing `quota_alert` (with a `percent`).
- Request-time events (`boot_marker_mismatch`, `health_stale`) are throttled to at most one line per minute per PHP worker, so that a broken instance under load cannot flood the log.
- When `metrics.enabled` is `true`, each purge run writes one aggregated `info` line (`event` = `storage`, with `count` and `percent`); no metrics endpoint is exposed.
- The shipped Nginx configuration keeps `access_log off` and sets `error_log /dev/stderr emerg`: only startup and configuration failures are logged by the web server. Custom web server setups must follow the same rule (README-admin §11).

### Consequences

- Administrators can alert on a small, fixed set of event names (README-admin §11.1) without the logs ever allowing correlation between requests.
- Client-side errors that Nginx answers itself (400, 408, 413, …) leave no web server log line; they remain visible as status codes to the client and through the application's own request lines when PHP handles the request.
- Tests assert the event names, the throttling and the absence of identifiers (`EXG-OPS-005`), and the Nginx error log level (`EXG-OPS-007`).
