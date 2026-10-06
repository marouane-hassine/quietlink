# Changelog

All notable changes are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The `/api/v1` prefix is stable for the whole V1;
a removed API version is announced at least one minor version in advance.

## [Unreleased]

## [1.0.0-beta.2] - 2026-10-06

Evaluation pre-release: the `sp-proto/v1` protocol has not yet been independently reviewed (ADR-0011);
do not use it for real secrets.

### Added

- French passphrase word list (4096 words derived from Lexique 3.83, CC BY-SA 4.0): no
  homophones, plain letters, rebuilt by `tools/wordlists/build-fr.mjs`; English stays the list
  for the other languages.
- `quietlink-reload` in the application image: `app:boot`, then a PHP-FPM reload only if it
  succeeded (§9.5), so a configuration change no longer needs a container restart.
- Wi-Fi template: a "Hidden network" field adds `H:true` to the QR code, and a WPA3-only
  network uses `T:SAE` (WPA2/WPA3 transition networks keep `T:WPA`).

### Fixed

- Configuration values that are not valid UTF-8 are refused with their key instead of making
  every request fail with an internal error.
- `--format=json` output is written verbatim and always parses (backslashes, console markup and
  invalid UTF-8 in messages); text output shows messages literally.
- `app:boot`: state files that cannot be written are a reported error; `--dry-run` reports a
  file in place of a storage directory; `post_max_size` is read as PHP reads it.
- `app:secret:generate --output` refuses a symbolic link.
- A busy `usage.lock` holds a decrement for about 10 s at most, within the request timeout; a
  creation whose commit could not be recorded keeps its marker; a purge removal lost to a
  concurrent deletion is no longer counted as a failure.
- CLI: a missing PHP extension exits with 1; the echo error points to `--passphrase-file`.
- The router's "Matched route" line is logged at debug level only.
- Idempotency-Key conflicts (422) count against the replay rate limit, like replays.
- A full disk no longer takes the API down: rate limiting fails open when its counter cannot be
  written, and deleting a paste removes its payload first when the deletion marker cannot be
  written, so deletions and the purge free space.
- A read-once confirmation arriving after a slow download is accepted for one more reservation
  lifetime while no other reader took the paste; the purge releases reservations after it.
- The CLI parses large texts with many escapes in linear time (it reported them as invalid) and
  takes `--max-bytes` for instances allowing more than 1 MiB.
- The application container's `/tmp` (where PHP-FPM spools request bodies) is 64 MiB.
- **Changed:** `app:boot` refuses a storage filesystem whose type cannot be determined, and
  Btrfs, like any type other than ext4 and XFS (§9.4.1), unless `storage.allow_unsupported_fs`.
- **Changed:** `paste.max_retention` applies to existing pastes: lowering it shortens them.
- The reading view's auto-hide delay is a choice (1, 2 or 5 minutes, or never), remembered per
  browser.
- `symfony/validator` removed (unused).
- `app:boot` checks that the PHP-FPM pool passes the secret variable this instance uses (an
  inline secret with a pool passing only the file variable booted "ok" and answered 503).
- Paths ending with "/" get the uniform 404 instead of a redirect built from the Host header
  (open redirect, http downgrade of a share link behind a misconfigured proxy).
- nginx answers its own 500/502/503/504 with the problem+json body and security headers.
- `APP_ENV` with a trailing newline is refused instead of enabling debug; Accept-Language uses
  two-letter primary subtags and reads q in any parameter.
- The Wi-Fi QR code encodes UTF-8 (accented and Arabic network names and passwords were
  corrupted); the PHAR build sorts entries so its hash does not depend on the filesystem.

### Changed

- The CLI PHAR is built by `tools/release/build-phar.sh` inside a pinned container, in CI, in
  the release workflow and for verification, so its SHA-256 is reproducible on any machine.
- `tools/docker/e2e.sh` runs the Playwright campaign inside Docker (official Playwright image of
  the project's version, plus PHP 8.3), like `qa.sh` and `smoke.sh` for the other validations.
- Images carry OCI labels (source, licence, version, revision), set in the last layer.
- PHP assertions are disabled in the application image (`zend.assertions = -1`).
- Documentation: source tags are verified against the release provenance, the cosign 3.x flag
  for SBOM attestations, the current CSP, pre-releases in the release checklist.

## [1.0.0-beta.1] - 2026-10-04

First public beta: every V1 Must is implemented; the external security review, the
reader tests and the native review of the Spanish, Italian and Arabic catalogues are still
pending (docs/release-checklist.md).

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

[Unreleased]: https://github.com/marouane-hassine/quietlink/compare/v1.0.0-beta.2...develop
[1.0.0-beta.2]: https://github.com/marouane-hassine/quietlink/compare/v1.0.0-beta.1...v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/marouane-hassine/quietlink/releases/tag/v1.0.0-beta.1
