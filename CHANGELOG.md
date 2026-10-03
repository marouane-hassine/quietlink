# Changelog

All notable changes are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The `/api/v1` prefix is stable for the whole V1;
a removed API version is announced at least one minor version in advance.

## [Unreleased]

### Added

- Protocol `sp-proto/v1` with shared test vectors (PHP, TypeScript, CLI).
- Client-side encryption (AES-256-GCM), HKDF key derivations, Ed25519 access proofs with a
  bundled fallback, optional Argon2id passphrase in a dedicated worker.
- Read-once mode with reservation, resumption, unconfirmed-open counter and idempotent
  consumption.
- File storage with `flock()`, atomic writes, quotas, idempotency records, purge and boot
  marker; no database.
- JSON API v1 with strict validation, uniform `404`, rate limiting and hardening headers.
- `quietlink` command line client (create, metadata, decrypt, delete).
- Web interface in English and French, light and dark themes, Markdown, code highlighting,
  templates, QR code, accessible states.
- Docker images (application, web server, CLI) and a demonstration Compose file.
- Templates edited as forms and read field by field, with per-field copy, masked sensitive values,
  Wi-Fi QR code and a suggestion of the Secret preset.
- Markdown preview in the editor, rendered/source views, plain display above 200 KiB with opt-in
  formatting, line wrap toggle and per-block copy.
- Inline confirmations instead of blocking dialogs, connection loss banner, retry on recoverable
  errors, action bar above the virtual keyboard.
- Trusted Types enforcement, threat model and generated requirement coverage report.
- Optional local export (`ui.allow_export`) and controlled printing (`ui.allow_print`), both off by
  default; `app:theme:preview` and `app:cache:purge` commands.
- Pages, Markdown, code highlighting and the QR code are loaded on demand; the first screen loads
  about 33 KB of JavaScript, enforced by a bundle budget in `npm run qa`.
- Capacity benchmarks (`npm run bench:purge`, `npm run bench:load`), a release checklist and a
  release verification procedure; release notes list the signed image digests.
