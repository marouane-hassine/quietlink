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
