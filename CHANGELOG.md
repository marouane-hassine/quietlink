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
- Web interface (English, French, Spanish, Italian and Arabic), light and dark themes, Markdown,
  code highlighting, templates, QR code, accessible states.
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
- Arabic (right to left), Spanish and Italian interfaces next to English and French; languages
  are discovered from `translations/*.json` and loaded on demand (ADR-0010).
- Theme choice shown as three icon buttons (system, light, dark).
- Optional CORS for `/api/v1` (`http.cors_allowed_origins`, exact origins, no credentials) and an
  OpenAPI contract test.
- Browser navigations to an unknown page or to an unavailable instance (invalid configuration,
  boot marker mismatch) show a plain HTML page in the visitor's language (English fallback)
  instead of a JSON problem document; the API and `/healthz` keep their formats.
- `app:boot --dry-run`: runs every check without creating, writing or deleting anything
  (missing directories become warnings, checked through the nearest existing parent; the atomic
  `rename()`/`link()` probe is skipped).
- `--format=json` for `app:boot` (`status`, `dry_run`, `errors`, `warnings`) and
  `app:config:check` (`status`, `ready`, `boot_marker`, `fingerprint`, `health`, `config`);
  documented exit codes `0`/`1`/`2` for the operations commands.
- `app:config:check` shows the last disk measurement (age, free bytes, free inodes, whether
  creation is allowed).
- `app:secret:generate --output=<file>` writes the secret to a new file (mode 0600, or 0640 with
  `--group-readable`) without printing it; an existing file is replaced atomically only with
  `--force`.
- New `app:boot` warnings: secret file or `config.php` readable by every account, free inodes
  not measurable, PHP `post_max_size` below `http.max_request_bytes`.
- Operations log events without identifiers, at most once a minute per worker:
  `health_stale`, `boot_marker_mismatch`, `purge_failures`; the purge output reports `failed`
  items left for the next run.
- Local validation in Docker (`tools/docker/qa.sh`, validation image `docker/qa/Dockerfile`) and
  a Docker smoke test of the Compose stack (`tools/docker/smoke.sh`).
- Administrator guide: systemd units, backup and restore of the Docker volume with a restore
  test routine, permission table, disk space and inode checks, post-deployment verification,
  troubleshooting table, ordered security incident procedure, Caddy and Apache proxy examples,
  exit codes and JSON formats.

### Changed

- `app:config:check` exits `2` when the configuration is valid but `app:boot` has not run for
  it (it used to exit `0`); `1` still means an invalid configuration.
- `app:boot` refuses storage directories (root, idempotency, rate limiting, state) that grant
  any access to other accounts: mode `0700` is required.
- `app.enabled_locales` defaults to `null`, resolved at load time to every shipped language, so
  languages added by later releases are enabled without a configuration change;
  `config.php.example` uses `null`.
- The web root check of the storage paths normalises `.` segments, doubled slashes, symbolic
  links and letter case; `storage.data_dir` must not be empty, `/` or the project root.
- The Compose `app` healthcheck also requires `app:config:check` to succeed; the `purge`
  service stops on `SIGTERM`; the `web` `/tmp` tmpfs is 128 MiB for spooled request bodies and
  large responses.
- The Docker build context excludes `datas/`, test reports, coverage output, `quietlink.phar`
  and local tool directories.
- CI: gitleaks is pinned by digest; stronger check that no database service is declared.
- In-progress creations leave an empty, randomly named marker in `state/creating/` (removed by
  the purge after one hour); no existing file format changes.

### Fixed

- The hourly usage recomputation could erase creations in progress, and quota decrements could
  be lost when `usage.lock` was busy; decrements now wait for the lock.
- `app:boot` accepted storage directories with mode `0755`.
- Free inode parsing of `df` output on macOS and other non-GNU formats.
- Nginx could run out of space spooling large request bodies and responses to `/tmp`.
- CLI: the passphrase prompt no longer falls back to a visible echo when `stty` fails (it points
  to `--passphrase-file` or `--passphrase-stdin`); `--server http://[::1]:<port>` is accepted;
  `-o` files are created with mode 0600 from the first instant; clear error when
  `allow_url_fopen` is disabled; `delete` without a terminal requires `--yes`.
- Release workflow: dependency installation no longer runs Composer or npm scripts
  (`--no-scripts`, `--ignore-scripts`).
- Administrator guide: `metrics.enabled` does write an aggregated metrics line from the purge;
  the secret generation example left a root-owned file unreadable by the PHP workers.

### Security

- The `X-HTTP-Method-Override` header is ignored (a `POST` can never run as `DELETE`).
- Only `X-Forwarded-For` and `X-Forwarded-Proto` are read from trusted proxies; `Forwarded`,
  `X-Forwarded-Host` and `X-Forwarded-Port` are ignored.
- The Symfony secrets vault is disabled (no `secrets:*` commands); the instance secret comes
  only from `QUIETLINK_APP_SECRET` or `QUIETLINK_APP_SECRET_FILE`.
- The shipped Nginx error log keeps only `emerg` entries: lower levels recorded the client
  address and the request line.
