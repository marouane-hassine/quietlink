# QuietLink administrator guide

This guide explains how to install, configure and operate a QuietLink instance. QuietLink has
**no backoffice, no administration screen and no administration endpoint**: everything is done
through configuration files, environment variables and console commands.

All examples use dummy values (`quietlink.example.test`, documentation IP ranges). Never paste a
real secret, a share link or a management link into a ticket, a log or this documentation.

- Specification: `docs/cahier-des-charges.md` (French)
- Storage format: `docs/storage-format.md`
- Protocol: `docs/protocol/sp-proto-v1.md`
- Decisions: `docs/decisions/ADR-*.md`
- Security policy: `SECURITY.md`

---

## 1. Overview

QuietLink stores only ciphertext. The browser (or the `quietlink` CLI) encrypts the text with
AES-256-GCM using a key that lives in the URL fragment (`#...`), which browsers never send to the
server. The server never receives the plaintext, the fragment key, the passphrase or the raw
deletion token.

Runtime components:

| Component | Role |
|---|---|
| PHP-FPM application (`public/index.php`, Symfony micro-kernel) | HTML pages, JSON API `/api/v1`, `/healthz` |
| Static web server (Nginx or Apache) | serves `public/build/` (hashed, immutable assets) and the generated theme CSS, forwards everything else to PHP-FPM |
| `app:boot` | mandatory pre-start checks; writes the boot marker |
| `app:purge-expired` | periodic cleanup, every minute |
| Local filesystem | the only persistence: no SQL, no SQLite, no Redis/Valkey |

Public routes: `/`, `/p/{id}`, `/manage/{id}`, `/how-it-works`, `/healthz`, `/api/v1/pastes`
and its sub-resources. There is nothing else to protect behind authentication.

## 2. Requirements

- PHP **≥ 8.3** with extensions `openssl` (with `aes-256-gcm`), `sodium` (with Argon2id),
  `intl`, `hash`, `json`, `mbstring`. `app:boot` refuses to continue if any of them, or any of
  `sodium_crypto_sign_seed_keypair`, `sodium_crypto_sign_verify_detached`, `sodium_crypto_pwhash`,
  `hash_hkdf`, `random_bytes`, `openssl_encrypt`, is missing.
- PHP-FPM, plus Nginx or Apache in front of it.
- A local Linux filesystem: **ext4, XFS or Btrfs** (see §7.3).
- `df` available in the PHP container (free inode measurement).
- A TLS-terminating reverse proxy (production instances must be served over HTTPS).
- For building from source: Composer 2 and Node.js ≥ 24.7 (frontend build only; Node is not
  needed at runtime).

## 3. Installation with Docker Compose (recommended)

The repository ships a demonstration deployment: `compose.yaml` and `docker/`
(`docker/php/`, `docker/nginx/`, `docker/cli/`).

Deployment model:

- **`app`**: PHP-FPM image (`docker/php/Dockerfile`), runs as the unprivileged user `10001`,
  read-only root filesystem, all capabilities dropped, `no-new-privileges`, a small `/tmp`
  tmpfs. Its entrypoint runs `php bin/console app:boot` **before** starting PHP-FPM; any boot
  failure stops the container. The Symfony container is prewarmed at build time
  (`APP_ENV=prod`).
- **`purge`**: same image, runs `app:purge-expired` every 60 seconds. A host cron job running
  the same command every minute is an equivalent alternative.
- **`web`**: unprivileged Nginx image (`docker/nginx/Dockerfile`) serving `public/` and the
  generated theme volume (read-only), forwarding other requests to `app:9000`. It listens on
  port 8080, published on `127.0.0.1` only: an HTTPS reverse proxy must sit in front of it.
- Volumes: **`/var/lib/quietlink`** (data: pastes, idempotency, rate limiting, state) and
  **`/var/lib/quietlink-generated`** (generated theme assets, shared read-only with `web`).
- Secret: `QUIETLINK_APP_SECRET_FILE=/run/secrets/app_secret`, provided as a Docker secret.

First start:

```sh
# Create the configuration first: Compose would otherwise create a directory at its place.
cp config/config.php.example config/config.php
# edit config/config.php: set app.public_url to https://quietlink.example.test
mkdir -p secrets
docker compose build
docker compose run --rm --no-deps app php bin/console app:secret:generate > secrets/app_secret
# The containers run as uid/gid 10001 and Compose secrets are plain bind mounts (owner and mode
# kept): give group 10001 read access, and nobody else (on Linux hosts; Docker Desktop maps them).
sudo chgrp 10001 secrets/app_secret config/config.php
chmod 640 secrets/app_secret config/config.php
docker compose up -d
docker compose exec app php bin/console app:config:check
```

Base images should be pinned by digest for release builds.

## 4. Installation without Docker (PHP-FPM + Nginx or Apache)

1. Build the release artefacts (or use a published release):

   ```sh
   composer install --no-dev --classmap-authoritative
   npm ci && npm run build          # produces public/build/
   APP_ENV=prod php bin/console cache:warmup --no-debug
   ```

2. Create a dedicated system account (e.g. `quietlink`) owning the data directories; the code
   itself should be owned by root and not writable by that account.
3. Create the directories (`app:boot` creates missing ones, but the parent must be writable):

   ```sh
   install -d -o quietlink -g quietlink -m 0700 /var/lib/quietlink
   install -d -o quietlink -g quietlink -m 0755 /var/lib/quietlink-generated
   ```

   The data directories must be **outside the web root**, owned by the application account,
   and must not be symbolic links. Only `generated_assets_dir` must be readable by the web
   server.
4. Configure the PHP-FPM pool. PHP-FPM clears the environment (`clear_env = yes`), so pass the
   variables explicitly:

   ```ini
   [quietlink]
   user = quietlink
   group = quietlink
   listen = /run/php/quietlink.sock
   clear_env = yes
   env[APP_ENV] = prod
   env[QUIETLINK_CONFIG_DIR] = /srv/quietlink/config
   env[QUIETLINK_APP_SECRET_FILE] = /etc/quietlink/app_secret
   ```

   Set `QUIETLINK_FPM_POOL_FILE` to the pool file path when running `app:boot`: boot then
   verifies that the pool passes `QUIETLINK_APP_SECRET` or `QUIETLINK_APP_SECRET_FILE` to the
   workers (otherwise it only prints a warning that the check was skipped).
5. Configure the web server. The reference is `docker/nginx/default.conf`. Required points:
   - document root `public/`; `/build/` served statically with
     `Cache-Control: public, max-age=31536000, immutable`;
   - the Argon2id worker (`/build/assets/argon2.worker-*.js`) must be served with
     `Content-Security-Policy: default-src 'none'; script-src 'self' 'wasm-unsafe-eval'`
     (it is the only script allowed to compile WebAssembly; without this header passphrase
     protection fails in the browser);
   - `/themes/generated/tokens.<16 hex>.css` mapped to `generated_assets_dir`;
   - everything else to `public/index.php` through FastCGI;
   - `client_max_body_size` ≥ `http.max_request_bytes` (default 1,441,792 bytes = 1408k);
   - no access log (URLs, addresses and user agents must not be collected), `server_tokens off`.
   - With Apache, reproduce the same rules (`mod_proxy_fcgi`, `mod_headers`), disable
     `.htaccess` overrides and access logging for the virtual host.
6. Copy and edit the configuration (§5), generate the secret (§6), run
   `php bin/console app:boot` as the application account, then start or reload PHP-FPM.
7. Schedule the purge (§8.2).

## 5. Configuration

### 5.1 Files and loading order

1. Versioned defaults (`QuietLink\Config\ConfigLoader::defaults()`).
2. `config/config.php` (**required**; copy `config/config.php.example`).
3. `config/config.local.php` (optional, installation-specific overrides, never committed; see
   `config/config.local.php.example`).

The directory is `config/` by default and can be changed with `QUIETLINK_CONFIG_DIR`. Each file
returns a PHP array. Arrays are merged key by key, except list values (marked "list" below),
which replace the default entirely.

Validation is strict: **unknown keys and wrong types are errors** (list values may only contain
strings). Configuration files must
never contain secrets.

After **any** change, run `php bin/console app:boot` (and reload PHP-FPM). Until the boot marker
matches the loaded configuration, every request is answered with a generic `503` and
`/healthz` returns `{"status":"unavailable"}`.

### 5.2 Reference

Durations use the format `<integer><m|h|d>` (e.g. `30m`, `24h`, `7d`). Expiration codes are
`5m`, `1h`, `1d`, `7d`, `30d`.

#### `app`

| Key | Default | Rule |
|---|---|---|
| `app.name` | `'QuietLink'` | Non-empty string; instance name displayed in pages. |
| `app.public_url` | `null` (must be set) | Required. `https://` origin without path, query, fragment or credentials. `http://` is accepted only for `localhost`, `127.0.0.1`, `[::1]`. |
| `app.source_url` | `'https://github.com/marouane-hassine/quietlink'` | `https://` URL of the deployed source code, linked in the footer (AGPL-3.0 section 13). Point it to your fork if you modify the code. |
| `app.enabled_locales` | `['en', 'fr']` | List. Must contain `en` (mandatory fallback); only available locales (`en`, `fr`); no duplicates. |

#### `theme`

| Key | Default | Rule |
|---|---|---|
| `theme.name` | `'default'` | Active theme. Only the built-in `default` theme is available; adapt it with `theme.custom_tokens_file`. |
| `theme.custom_tokens_file` | `null` | `null` or a relative `.json` path inside `config/themes/` (`[A-Za-z0-9_-]` segments, no `..`); the file must exist. See §12. |

#### `storage`

| Key | Default | Rule |
|---|---|---|
| `storage.driver` | `'filesystem'` | Only `filesystem` is supported. |
| `storage.root_dir` | `'/var/lib/quietlink/pastes'` | Absolute path without `..`; outside the web root; mode 0700. |
| `storage.idempotency_dir` | `'/var/lib/quietlink/idempotency'` | Same rules. |
| `storage.ratelimit_dir` | `'/var/lib/quietlink/ratelimit'` | Same rules. |
| `storage.state_dir` | `'/var/lib/quietlink/state'` | Same rules. |
| `storage.generated_assets_dir` | `'/var/lib/quietlink-generated'` | Same rules; mode 0755, readable by the web server. |
| `storage.max_total_bytes` | `10737418240` (10 GiB) | Integer ≥ 1. Total ciphertext quota. |
| `storage.max_items` | `100000` | Integer ≥ 1. Maximum number of stored pastes. |
| `storage.min_free_bytes` | `1073741824` (1 GiB) | Integer ≥ 1. Creation is refused below this free space. |
| `storage.min_free_inodes_percent` | `10` | Integer 0–50. Creation is refused below this free inode percentage. |
| `storage.allow_unsupported_fs` | `false` | Boolean. Turns the unsupported-filesystem error into a warning. **Development only.** |

#### `paste`

| Key | Default | Rule |
|---|---|---|
| `paste.default_expiration` | `'1d'` | Must belong to `paste.allowed_expirations`. |
| `paste.allowed_expirations` | `['5m', '1h', '1d', '7d', '30d']` | List, non-empty, only the codes above. `never` is not listed here: it is offered by `allow_forever`. Each code must not exceed `max_retention`. |
| `paste.allow_forever` | `false` | Boolean. Offers `never`; requires `paste.max_retention = null`. |
| `paste.allow_read_once` | `true` | Boolean. |
| `paste.allow_passphrase` | `true` | Boolean. Automatically disabled when libsodium lacks Argon2id (no silent downgrade). |
| `paste.max_envelope_bytes` | `1048576` (1 MiB) | Integer 1024–16777216 (16 MiB). Maximum plaintext envelope size; the ciphertext limit is this value + 16. |
| `paste.max_metadata_bytes` | `4096` | Integer 512–4096. Maximum AAD size. |
| `paste.max_retention` | `'30d'` | Duration or `null` (no upper bound, required by `allow_forever`). |
| `paste.max_unconfirmed_opens` | `3` | Integer 1–10. Reservations of a read-once paste that may expire without confirmation before it is destroyed. |
| `paste.read_once_reservation_ttl` | `60` | Integer 30–300 (seconds). Lifetime of a read-once reservation. |
| `paste.idempotency_max_ttl` | `'24h'` | Duration between `1h` and `7d`. Retention of `Idempotency-Key` records. |

#### `http`

| Key | Default | Rule |
|---|---|---|
| `http.max_request_bytes` | `1441792` | Integer; must be ≥ `ceil((max_envelope_bytes + 16) × 4/3) + ceil(max_metadata_bytes × 4/3) + 16384` (1,419,969 with the defaults). Keep the web server body limit aligned. |
| `http.ratelimit_ipv6_prefix` | `64` | Integer 48–64. IPv6 clients are rate limited per prefix. |
| `http.trusted_proxies` | `[]` | List of IPv4 or IPv6 addresses or CIDR ranges (prefix 0–32 for IPv4, 0–128 for IPv6) allowed to set `X-Forwarded-*` / `Forwarded`. See §9. |
| `http.cors_allowed_origins` | `[]` | List of `https://` origins. Empty = CORS disabled. |
| `http.hsts_max_age` | `31536000` | Integer ≥ 0 (seconds). `0` disables the HSTS header. |
| `http.rate_limits` | see §10 | Map of known buckets to `['limit' => int ≥ 1, 'interval' => int 1–86400]`, exactly these two keys: `http.rate_limits.<bucket>.limit` (requests allowed) and `http.rate_limits.<bucket>.interval` (window in seconds). |
| `http.rate_limits.create` | `30 / 600 s` | Per client address. |
| `http.rate_limits.create_replay` | `120 / 600 s` | Per client address: retries of an already-answered creation (same `Idempotency-Key`), counted separately so a client can recover its link. |
| `http.rate_limits.challenge` | `120 / 60 s` | Per client address. |
| `http.rate_limits.open` | `60 / 60 s` | Per client address. |
| `http.rate_limits.status` | `60 / 60 s` | Per client address. |
| `http.rate_limits.consume` | `60 / 60 s` | Per client address. |
| `http.rate_limits.delete` | `30 / 600 s` | Per client address. |
| `http.rate_limits.health` | `60 / 60 s` | Per client address. |
| `http.rate_limits.open_per_paste` | `20 / 60 s` | Per paste, counting only requests with a valid access proof. |
| `http.rate_limits.status_per_paste` | `30 / 60 s` | Per paste, counting only requests with a valid access proof. |

#### `ui`

| Key | Default | Rule |
|---|---|---|
| `ui.dark_mode` | `'auto'` | `auto` (system preference), `light` or `dark`. |
| `ui.templates` | `['credentials', 'api-token', 'wifi', 'ssh-key', 'database', 'env-vars', 'temporary-access', 'incident']` | List; subset of these Markdown templates, without duplicates. |
| `ui.enable_qr_code` | `true` | Boolean. QR code of the share link (generated locally). |
| `ui.allow_print` | `false` | Boolean. Adds a *Print* button to the reading screen, preceded by a warning (Could, §6.8). |
| `ui.allow_export` | `false` | Boolean. Adds an *Export* button to the reading screen: the text is saved as a local file generated in the browser, nothing is sent (Could, §6.8). |
| `ui.enable_manifest` | `false` | Boolean. Adds `<link rel="manifest" href="/manifest.json">` and `manifest-src 'self'` to the CSP. `/manifest.json` is a minimal manifest (no Service Worker, no secret data). |

#### `log`

| Key | Default | Rule |
|---|---|---|
| `log.level` | `'info'` | PSR-3 level: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. |
| `log.retention` | `'14d'` | Duration. Documents the retention expected from your log collector; QuietLink itself does not store logs (§11). |

#### `metrics`

| Key | Default | Rule |
|---|---|---|
| `metrics.enabled` | `false` | Boolean. Validated but **no metrics endpoint exists** in the current release (no effect). |

### 5.3 Environment variables

| Variable | Used by | Purpose |
|---|---|---|
| `QUIETLINK_APP_SECRET` | app, console | Instance secret, standard base64 of ≥ 32 bytes. |
| `QUIETLINK_APP_SECRET_FILE` | app, console | Path of a file containing the secret (preferred: Docker secret under `/run/secrets/`). Setting both variables is an error. |
| `QUIETLINK_CONFIG_DIR` | app, console | Configuration directory (default `<project>/config`). |
| `QUIETLINK_FPM_POOL_FILE` | `app:boot` | PHP-FPM pool file checked for the secret variable. |
| `APP_ENV` | app, console | `prod` by default. Debug mode can never be enabled in `prod`. |
| `QUIETLINK_SERVER` | `quietlink` CLI | Default instance URL for `create`. |

The shipped `docker/php/pool.conf` forwards only `QUIETLINK_APP_SECRET_FILE`. If you use the
inline `QUIETLINK_APP_SECRET` instead, adapt the pool, otherwise `app:boot` fails.

## 6. Instance secret

The secret keys the challenges (`open`, `status`, `consume`) and the rate limiting keys. It is
never written to configuration files, never printed (`app:config:check` shows only
`secret: present (not shown)`) and never logged.

Generate it:

```sh
php bin/console app:secret:generate > /etc/quietlink/app_secret   # 32 random bytes, base64
chmod 600 /etc/quietlink/app_secret
```

### Rotation

1. Generate a new secret and replace the secret file (or Docker secret).
2. Run `php bin/console app:boot` (the boot marker stores a check value of the secret; until it
   is rerun, requests receive `503`).
3. Reload PHP-FPM (or restart the `app` and `purge` containers; the Docker entrypoint runs
   `app:boot` automatically).

Effects: pending `open` and `status` challenges become invalid and clients transparently request
a new one (one retry); ongoing read-once reservations are **not** affected (their `consume`
challenge is compared with the stored value); rate limiting counters are reset (keys are derived
from the secret).

## 7. Storage

### 7.1 Layout

```text
/var/lib/quietlink/                 # data volume
├── pastes/<s1>/<s2>/<id>/          # storage.root_dir, sharded by id prefix
│   ├── payload.bin                 # nonce ‖ ciphertext (removed on consume/delete)
│   ├── meta.json                   # immutable
│   ├── state.json                  # mutable state
│   └── state.lock                  # flock() target
├── idempotency/                    # storage.idempotency_dir
├── ratelimit/                      # storage.ratelimit_dir
└── state/                          # storage.state_dir
    ├── usage.json  health.json  boot.json
    └── usage.lock  purge.lock
/var/lib/quietlink-generated/       # storage.generated_assets_dir
├── tokens.json                     # theme manifest
└── tokens.<hash>.css
```

Directories are `0700`, files `0600` (except the generated assets). All writes use a temporary
file followed by an atomic `rename()`/`link()`; concurrency is handled with `flock()`. Every
JSON file carries a `schema_version` (schemas in `docs/schemas/`). Details:
`docs/storage-format.md`.

Never edit these files by hand while the instance runs. Never remove `*.lock` files.

### 7.2 Locks

Requests retry a busy paste lock for at most 2 seconds, then answer `503` with `Retry-After`.
The purge uses a global non-blocking `purge.lock`: a second concurrent run exits immediately
(`purge: another run is in progress`). `app:boot` creates the lock files; a missing lock file
makes the purge fail with a message asking to run `app:boot`.

### 7.3 Supported filesystems

`app:boot` reads `/proc/self/mounts` and accepts **ext4, XFS and Btrfs** for every storage
directory, and verifies that `rename()` and `link()` work atomically in each of them.

Network and overlay filesystems (NFS, SMB/CIFS, FUSE, overlayfs without a volume, etc.) are not
supported: `flock()` and hard links are unreliable there. `storage.allow_unsupported_fs = true`
turns the error into a warning for development only. If the type cannot be determined (non-Linux
hosts), boot emits a warning.

### 7.4 Quotas and capacity alerts

Creation is refused with `503` (`Retry-After: 300`) when `max_total_bytes` or `max_items` would
be exceeded, and also when free space is below `min_free_bytes`, free inodes are below
`min_free_inodes_percent`, or `state/health.json` is missing or older than 10 minutes (it is
refreshed by `app:boot` and every purge). Reading existing pastes keeps working.

Recommendations:

- Alert at **80 %** of `max_total_bytes` and `max_items` (`state/usage.json` contains `bytes`
  and `items`; it is recomputed by the purge at most once per hour) and at 80 % disk / inode
  usage of the data volume.
- Alert when `/healthz` returns `503` (`degraded`): usually the purge is not running or disk
  space/inodes are low.
- Keep the data volume dedicated to QuietLink so other workloads cannot exhaust it.

### 7.5 Backup and restore

Ciphertexts are useless without the keys contained in the share links, but backups still contain
confidential material and metadata: encrypt them and restrict access.

- Back up `/var/lib/quietlink/pastes` and `/var/lib/quietlink/idempotency` with a
  filesystem-consistent method (snapshot of the volume, or stop `app`/`purge` before copying).
  `ratelimit/` and `state/` do not need to be backed up (rebuilt by `app:boot` and the purge).
- Backups weaken read-once guarantees: a restored paste that was already consumed can be read
  again. Keep backup retention short (expired content is deleted at the next purge after a
  restore anyway) or do not back up pastes at all if this is unacceptable.
- Restore: stop the instance, restore files with the same owner and permissions (`0700`/`0600`,
  application account), keep `*.lock` files in place, run `app:boot`, start the instance. The
  next purges correct `usage.json`.
- Back up `config/config.php`, `config/config.local.php`, `config/themes/` and the secret
  separately (the secret in your secret manager).

## 8. Console commands

All commands run as the application account with the same environment as PHP-FPM.

| Command | Purpose |
|---|---|
| `php bin/console app:boot` | Validates the configuration, PHP runtime, directories (creation, ownership, outside web root, no symlink, atomic `rename()`/`link()`, filesystem type), creates lock files, checks the PHP-FPM pool when `QUIETLINK_FPM_POOL_FILE` is set, compiles the theme tokens, writes `health.json` and the boot marker `boot.json`. Prints warnings (low disk space or inodes, empty `trusted_proxies` behind HTTPS, unknown filesystem type). Non-zero exit on error. |
| `php bin/console app:config:check` | Validates and prints the effective configuration as JSON, then `secret: present (not shown)`, the configuration fingerprint and whether the boot marker matches. |
| `php bin/console app:secret:generate` | Prints a new secret (32 random bytes, standard base64). |
| `php bin/console app:purge-expired` | See §8.2. |
| `php bin/console app:theme:preview --output=<dir>` | Writes `index.html` (light) and `dark.html` (dark) showing every component and state with the configured theme tokens, dummy content only, no script. Open them locally before activating a theme. |
| `php bin/console app:cache:purge` | Controlled purge of non-sensitive caches: removes generated theme stylesheets no longer referenced after a theme change and `app:boot`. Hashed frontend assets change name with each release; the Symfony container is compiled per release under `var/cache/<env>/<version>/`, so an upgrade never boots on the previous one (remove older version directories after a non-Docker upgrade). Pastes, keys and secrets are never cached. |

### 8.1 Boot

`app:boot` must succeed before PHP-FPM starts and after every change of configuration, secret,
theme file or release. The Docker entrypoint does it automatically.

### 8.2 Purge

`app:purge-expired` must run **every minute**. It refuses to run when the boot marker is missing
or outdated. Each run (idempotent):

- removes expired pastes, consumed pastes older than 10 minutes, incomplete pastes, orphan
  creations (no matching idempotency record, detected between 15 and 60 minutes of age, so the
  purge must run at least every 45 minutes) and staging directories older than one hour;
- releases expired read-once reservations;
- removes expired idempotency records and rate limiting entries;
- refreshes `health.json`;
- recomputes `usage.json` at most once per hour.

It prints `purge: {"removed":…,"released":…,"orphans":…,"idempotency":…,"ratelimit":…}`
(counts only). Expired pastes are refused by requests as soon as they expire, even if the purge
is late.

Cron example (host installation):

```cron
* * * * * quietlink QUIETLINK_APP_SECRET_FILE=/etc/quietlink/app_secret QUIETLINK_CONFIG_DIR=/srv/quietlink/config /usr/bin/php /srv/quietlink/bin/console app:purge-expired --no-interaction >/dev/null
```

## 9. HTTPS, HSTS and reverse proxy

- Serve the instance **only over HTTPS**; `app.public_url` must be an `https://` origin.
- Every application response carries a strict CSP (`default-src 'none'; script-src 'self';
  worker-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self';
  form-action 'none'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'`, plus
  `upgrade-insecure-requests` over HTTPS), `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`, `Permissions-Policy`,
  `Cross-Origin-Opener-Policy` and `Cross-Origin-Resource-Policy: same-origin`,
  `Cache-Control: no-store, private`, and never sets cookies. Do not weaken or override these
  headers in the proxy.
- `Strict-Transport-Security: max-age=<http.hsts_max_age>; includeSubDomains` is sent only when
  the request is seen as HTTPS. Behind a TLS-terminating proxy this requires the proxy to send
  `X-Forwarded-Proto: https` and its address to be listed in `http.trusted_proxies`. The shipped
  Nginx configuration never trusts these headers itself: it forwards them (appending the
  connecting address to `X-Forwarded-For`) and the application decides from
  `http.trusted_proxies`.
  `includeSubDomains` applies to every subdomain of the instance host: use a dedicated host.
- **`http.trusted_proxies`**: list the address(es) of the proxy that connects to PHP-FPM's web
  server, as seen by the application. In the Compose setup PHP-FPM sees the `web` container,
  which itself sees the HTTPS proxy through the Docker bridge: list the Compose network range
  (e.g. `172.16.0.0/12`) **and** the outer proxy address. Only then are
  `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Port` and
  `Forwarded` honoured. With an empty list behind a proxy, **all clients share one rate limiting
  key** (`app:boot` warns about it). Never list ranges that untrusted clients can connect from.
- The proxy must strip incoming `X-Forwarded-*` headers from clients and set its own, must not
  log full URLs, request bodies, `X-Deletion-Token` or `Idempotency-Key` headers, and must not
  cache API responses. Share links carry their key in the fragment, which is never sent, but
  paths contain public identifiers: disable or minimise proxy access logs.
- The Compose `web` service is bound to `127.0.0.1:8080` (override the host port with
  `QUIETLINK_HTTP_PORT`): it speaks plain HTTP and must only be reached through the TLS proxy.

Example (Nginx as the TLS proxy, dummy host):

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name quietlink.example.test;
    ssl_certificate     /etc/ssl/quietlink.example.test.pem;
    ssl_certificate_key /etc/ssl/quietlink.example.test.key;
    access_log off;
    client_max_body_size 1408k;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Forwarded "";
    }
}
```

## 10. Rate limiting and abuse limits

Rate limiting is file based (`storage.ratelimit_dir`). Client keys are HMACs derived daily from
the instance secret: raw addresses are never stored. IPv4-mapped IPv6 is treated as IPv4; native
IPv6 is grouped by `http.ratelimit_ipv6_prefix`.

| Bucket | Default | Scope |
|---|---|---|
| `create` | 30 per 10 min | client |
| `challenge` | 120 per min | client |
| `open` | 60 per min | client |
| `status` | 60 per min | client |
| `consume` | 60 per min | client |
| `delete` | 30 per 10 min | client |
| `health` | 60 per min | client |
| `open_per_paste` | 20 per min | paste, valid proofs only |
| `status_per_paste` | 30 per min | paste, valid proofs only |

Exceeded limits return `429` with `Retry-After`. Override any bucket in `http.rate_limits`; the
other buckets keep their defaults. Other limits: request body ≤ `http.max_request_bytes`
(`413` beyond), quotas (§7.4), `max_unconfirmed_opens` for read-once pastes. Uploads and
`multipart/form-data` are not supported.

## 11. Logging policy and retention

- Logs are JSON lines on **stderr** (PSR-3), collected by the container runtime or the PHP-FPM
  error log. Format: `{"ts":"…Z","level":"info","message":"request","method":"POST","route":"api_create","status":201,"duration_ms":12,"request_bytes":2048}`.
- Logged fields are restricted to an allowlist: `method`, `route` (route **name**, never the
  path), `status`, `duration_ms`, `request_bytes`, `response_bytes`, `exception` (class),
  `event`, `count`, `percent`. Messages are sanitized (URLs and long tokens are redacted).
- **Never logged**: IP addresses, user agents, paste identifiers, paths, query strings, headers
  (including `X-Deletion-Token` and `Idempotency-Key`), bodies, fragments, secrets.
- The web server access logs are disabled in the shipped Nginx configuration and in the PHP-FPM
  pool (`access.log = /dev/null`); keep it that way in custom setups.
- `log.level` controls verbosity (`info` by default; avoid `debug` in production).
- Retention: QuietLink stores no logs itself. Configure your collector to keep them no longer
  than `log.retention` (default **14 days**), e.g. Docker `--log-opt max-size=10m --log-opt max-file=3`
  or the equivalent retention in your log platform.

## 12. Themes and languages

### 12.1 Theme tokens

Custom themes are JSON token files in `config/themes/`, referenced by
`theme.custom_tokens_file` (e.g. `'custom-tokens.json'`). `app:boot` validates and compiles them
into a static hashed stylesheet `tokens.<hash>.css` in `storage.generated_assets_dir`, served at
`/themes/generated/`. No inline CSS is ever injected and free-form CSS is rejected (ADR-0003).

Format (see `config/themes/custom-tokens.json.example`):

```json
{
  "light": { "color-primary": "#c94f26", "radius": "0.5rem" },
  "dark":  { "color-primary-text": "#f0a07f" }
}
```

Only the `light` and `dark` sections are allowed. Allowed tokens (emitted as `--ql-<name>`):

| Token | Value |
|---|---|
| `color-background`, `color-surface`, `color-surface-accent`, `color-text`, `color-text-muted`, `color-border`, `color-border-control`, `color-primary`, `color-primary-hover`, `color-primary-contrast`, `color-primary-text`, `color-focus` | `#rrggbb` |
| `radius` | length 0–2 rem |
| `radius-small` | length 0–1 rem |
| `space` | length 0.5–2 rem |
| `font-size` | length 1–1.5 rem |
| `content-width` | length 30–80 rem |

Lengths are written in `rem` or `px` (px ÷ 16 must fit the range). Any other token or value makes
`app:boot` fail. Check text contrast (WCAG AA) of your colours in both modes. `ui.dark_mode`
selects the default mode; users can still switch.

### 12.2 Languages

`app.enabled_locales` selects the offered languages among those shipped (`en`, `fr`). English is
mandatory. The language is negotiated from `Accept-Language`, and the user's explicit choice is
remembered in the browser only. Adding a language requires a code change
(`docs/README-developer.md`).

## 13. Health check and diagnostics

- `GET /healthz` returns `{"status":"ok"}` (`200`), `{"status":"degraded"}` (`503`: health data
  missing/stale or inodes low, creation refused) or `{"status":"unavailable"}` (`503`: invalid
  configuration or boot marker mismatch). It exposes no version or configuration detail and is
  rate limited (`health` bucket).
- `app:config:check` shows the effective configuration and whether the boot marker matches.
- A generic `503` on every page usually means `app:boot` was not rerun after a change.
- Errors are logged without identifiers; reproduce problems with dummy content only.

## 14. Docker and PHP-FPM hardening

The shipped images already apply: non-root users (`10001` app, `10002` CLI), read-only root
filesystem, `cap_drop: ALL`, `no-new-privileges`, small `/tmp` tmpfs, prewarmed read-only
Symfony cache, `expose_php = Off`, `display_errors = Off`, `file_uploads = Off`,
`allow_url_fopen = Off`, `post_max_size = 2M`, `zend.exception_ignore_args = On`, OPcache without
timestamp validation, `clear_env = yes`. Keep the data volume mounted with `nodev,nosuid,noexec`
where possible, pin base images by digest, rebuild regularly for security updates, and never
mount the Docker socket.

## 15. Upgrade and rollback

1. Read the changelog. Breaking changes (`!` in commit history, `BREAKING CHANGE` notes) concern
   the storage format, the encrypted format, the AAD or `/api/v1`.
2. Back up the data volume and configuration (§7.5).
3. Deploy the new images/code. Any new configuration key has a default; removed keys must be
   removed from your files (unknown keys are errors).
4. Run `app:boot` (automatic with Docker) and check `app:config:check` and `/healthz`.

Storage files are versioned with `schema_version`; readers fail closed on unknown versions
(uniform `404`). A format change ships with an explicit migration. Rolling back to an older
release is safe only if no newer `schema_version` has been written since the upgrade; otherwise
restore the pre-upgrade backup. The frontend assets are hashed, so mixed old/new pages do not
collide in caches.

Verify release artefacts (signatures and checksums published with each release) before
deploying, and prefer image digests over tags. Signatures are keyless (Sigstore, GitHub OIDC),
so the identity to check is the release workflow of the official repository:

```sh
id='^https://github.com/marouane-hassine/quietlink/\.github/workflows/release\.yml@refs/tags/v'
issuer=https://token.actions.githubusercontent.com
# Images (repeat for quietlink-web and quietlink-cli); use the digest printed in the release notes.
cosign verify --certificate-identity-regexp "$id" --certificate-oidc-issuer "$issuer" \
  ghcr.io/marouane-hassine/quietlink-app@sha256:<digest>
cosign verify-attestation --type spdxjson --certificate-identity-regexp "$id" \
  --certificate-oidc-issuer "$issuer" ghcr.io/marouane-hassine/quietlink-app@sha256:<digest>
# PHAR and frontend hashes, downloaded from the GitHub release.
for f in quietlink.phar frontend-sha256sums.txt; do
  cosign verify-blob --bundle "$f.sigstore.json" --certificate-identity-regexp "$id" \
    --certificate-oidc-issuer "$issuer" "$f"
done
sha256sum -c quietlink.phar.sha256
gh attestation verify quietlink.phar --repo marouane-hassine/quietlink
# Served assets: compare with the published list.
(cd public/build && find . -type f -exec sha256sum {} + | sort) | diff - frontend-sha256sums.txt
```

## 16. Incident response

- Suspected server compromise: take the instance offline, preserve the volumes for analysis,
  rotate the secret, rebuild images from verified sources, and inform users that links created
  or opened during the exposure window must be considered compromised (a compromised server can
  have served malicious JavaScript, §17).
- Disk full / quota reached: creation is refused automatically; free space, check that the purge
  runs, adjust quotas.
- Abuse: tighten `http.rate_limits`, block at the proxy. There is no moderation interface:
  content cannot be decrypted by the operator. A specific paste can be removed from disk by its
  identifier if legally required (stop writes to it, delete its directory, run the purge).
- Report vulnerabilities as described in `SECURITY.md`; never include secrets, keys, passphrases,
  full links or user content.

## 17. Threat model limits

QuietLink protects confidentiality against the server operator and the storage only under these
assumptions:

- **A compromised or malicious server can serve modified JavaScript** that exfiltrates the
  plaintext, the fragment key or the passphrase. Browser users must trust the instance serving
  the frontend at the moment they create or open a paste. The CLI, which encrypts locally from a
  separately installed release, does not depend on the served frontend.
- Anyone holding a full share link (with its fragment) can read the content until it expires or
  is consumed. Links leak through chat history, screenshots, browser history and synchronisation,
  link previews, or malware on the endpoint.
- The passphrase adds a second factor, but weak passphrases can be brute-forced offline by
  someone who has the ciphertext and the link key.
- Read-once is enforced by the server; a malicious server or a restored backup can serve a
  consumed paste again.
- Metadata visible to the server: creation time, expiration, size, read-once flag, access times
  (not logged), and network-level information seen by the proxy.
- Endpoint compromise (browser extensions, malware, shoulder surfing) is out of scope.
- Deletion removes files with `unlink()`; data may remain on the underlying storage medium,
  snapshots and backups.

## Argon2id calibration

Default passphrase parameters are `m = 64 MiB, t = 3, p = 1` (ADR-0008). To measure them on target
devices, run `npm ci && npm run calibration` on a workstation of the local network and open the printed
network URL on each phone or computer, then press *Run measurements*. The page uses the production
worker and a dummy passphrase; record the median durations in the calibration report. Lower the defaults
only if an entry-level device exceeds about 5 seconds.

## Capacity benchmarks

Two scripts check the §13 targets on a candidate machine. Run them on a disposable instance or
directory, never on production data. Both use dummy content only.

- **Purge** (`EXG-PERF-011`): `npm run bench:purge -- 100000 /path/to/scratch` creates 100,000
  expired pastes with the real storage code in a temporary store. It then times one purge run and
  checks that no paste is left after under 5 minutes. The directory is deleted at the end. Use a
  path on the filesystem type that production uses.
- **Throughput and latency** (`EXG-PERF-009`, `EXG-PERF-010`): start the disposable instance
  `tools/bench/server.sh` (port 8095). It uses temporary storage and lifts every rate limit bucket,
  because all requests come from one address. Then run
  `npm run bench:load -- --creates 2000 --opens 4000 --concurrency 16`. The script builds real
  sp-proto/v1 pastes locally, then measures creates per second, opens per second (challenge plus
  open) and the p50/p95 latency of each endpoint. The targets are at least 50 creates/s, at least
  200 opens/s and a p95 under 200 ms. To test a production-like stack (PHP-FPM behind Nginx),
  pass `--url` and raise every `http.rate_limits` bucket of that test instance. Latency includes
  the local network, so run the client on the same host or LAN.

Each script exits with a non-zero status when a target is missed.
