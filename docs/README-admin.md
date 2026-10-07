# QuietLink administrator guide

This guide explains how to install, configure and operate a QuietLink instance. It is written so
that an administrator who has never run QuietLink can follow it step by step.

QuietLink has **no database, no backoffice, no administration screen and no administration
endpoint**. Everything is done through configuration files, environment variables, the console
commands of `bin/console` and ordinary system tools (Docker, systemd, `tar`, `df`). There is
nothing to log in to and nothing to protect behind an administrator password.

All examples use dummy values (`quietlink.example.test`, documentation IP ranges, `<digest>`
placeholders). Never paste a real secret, a share link or a management link into a ticket, a log,
a shell history you share, or this documentation.

- Specification: `docs/cahier-des-charges.md` (French)
- Storage format: `docs/storage-format.md`
- Protocol: `docs/protocol/sp-proto-v1.md`
- Decisions: `docs/decisions/ADR-*.md`
- Security policy: `SECURITY.md`

**Quick map.** First installation: §3 (Docker) or §4 (without Docker), then §5 (configuration),
§6 (secret), §8 (boot and checks), §9 (HTTPS) and §13.1 (post-deployment verification). Daily
operations: §7.4 (disk space), §7.5 (backups), §8.2 (purge), §11 (logs). Changes: §15 (upgrade
and rollback). Problems: §18 (troubleshooting) and §16 (security incident).

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
| Local filesystem | the only persistence: no SQL, no SQLite, no Redis/Valkey, no database service of any kind |

Public routes: `/`, `/p/{id}`, `/manage/{id}`, `/how-it-works`, `/healthz`, `/api/v1/pastes`
and its sub-resources. There is nothing else to protect behind authentication.

Administration happens only through:

- `config/config.php` (and optionally `config/config.local.php`);
- the environment (`QUIETLINK_APP_SECRET_FILE`, `QUIETLINK_CONFIG_DIR`, …);
- the console commands (§8): `app:boot`, `app:config:check`, `app:secret:generate`,
  `app:purge-expired`, `app:theme:preview`, `app:cache:purge`;
- system tools: Docker Compose or systemd, file permissions, backups of the data directory.

## 2. Requirements

- PHP **≥ 8.3** with extensions `openssl` (with `aes-256-gcm`), `sodium` (with Argon2id),
  `intl`, `hash`, `json`, `mbstring`. `app:boot` refuses to continue if any of them, or any of
  `sodium_crypto_sign_seed_keypair`, `sodium_crypto_sign_verify_detached`, `sodium_crypto_pwhash`,
  `hash_hkdf`, `random_bytes`, `openssl_encrypt`, is missing.
- PHP-FPM, plus Nginx or Apache in front of it.
- A local Linux filesystem for the data: **ext4 or XFS** (see §7.3).
- A clock synchronised by NTP that never steps backwards (chrony or systemd-timesyncd with
  slewing). Access challenges carry the server's issuing time and are refused if they appear to
  come from the future (zero tolerance, `sp-proto/v1`): a backward step makes valid links answer
  `404` for a moment (the page retries once) and a large jump can make `health.json` look stale
  (creation refused until the next purge, at most a minute).
- `df` available to PHP (free inode measurement).
- A TLS-terminating reverse proxy, or a web server terminating TLS itself (production instances
  must be served over HTTPS).
- For building from source: Composer 2 and Node.js ≥ 24.7 (frontend build only; Node is not
  needed at runtime).
- With Docker: Docker Engine with the Compose plugin (`docker compose`, with `--wait` support for
  the smoke test).

## 3. Installation with Docker Compose (recommended)

The repository ships a demonstration deployment: `compose.yaml` and `docker/`
(`docker/php/`, `docker/nginx/`, `docker/cli/`). There is no database service in it, on purpose.

Deployment model:

- **`app`**: PHP-FPM image (`docker/php/Dockerfile`), runs as the unprivileged user `10001`,
  read-only root filesystem, all capabilities dropped, `no-new-privileges`, a small `/tmp`
  tmpfs. Its entrypoint runs `php bin/console app:boot` **before** starting PHP-FPM; any boot
  failure stops the container. The Symfony container is prewarmed at build time
  (`APP_ENV=prod`). Its healthcheck succeeds only when PHP-FPM listens on port 9000 **and**
  `app:config:check --format=json` exits `0` (configuration valid and booted, §8.3).
  PHP-FPM listens on TCP 9000 of the Compose network without a client allowlist (the `web`
  container's address is not fixed): attach only QuietLink's own containers to that network,
  never the outer reverse proxy or other services, since anything able to reach port 9000 can
  run PHP code in the container that holds the secret.
- **`purge`**: same image, runs `app:purge-expired` every 60 seconds in a loop that stops cleanly
  on `SIGTERM` (`stop_signal: SIGTERM`). A host cron job or systemd timer running the same
  command every minute is an equivalent alternative.
- **`web`**: unprivileged Nginx image (`docker/nginx/Dockerfile`, uid `101`) serving `public/`
  and the generated theme volume (read-only), forwarding other requests to `app:9000`. It
  listens on port 8080, published on `127.0.0.1` only: an HTTPS reverse proxy must sit in front
  of it (§9). Its `/tmp` tmpfs (128 MiB) holds request bodies larger than the in-memory buffer
  (`/tmp/client_temp`) and large responses (`/tmp/fastcgi_temp`).
- Volumes: **`data`** mounted on `/app/datas` (pastes, idempotency, rate limiting, state; the
  default `storage.data_dir`) and **`generated`** mounted on `/app/var/generated` (the default
  `storage.generated_assets_dir`) and on `/var/lib/quietlink-generated` (former default, kept for
  existing configurations); generated theme assets, shared read-only with `web`. Docker names them
  `<project>_data` and `<project>_generated`, where `<project>` is the Compose project name
  (by default the name of the directory holding `compose.yaml`; `docker volume ls` shows them).
- Secret: `QUIETLINK_APP_SECRET_FILE=/run/secrets/app_secret`, provided as a Docker secret from
  `secrets/app_secret`.

### 3.1 First start

Run these commands from the repository root (a checkout of a release tag):

```sh
# 1. Configuration first: Compose would otherwise create a *directory* named config.php.
cp config/config.php.example config/config.php
# Edit config/config.php: set app.public_url to your https origin, e.g.
# https://quietlink.example.test (and http.trusted_proxies, see §9). The purge service runs
# every minute: set storage.health_max_age to '10m' so that a stopped purge shows quickly.
# Logs go to the container output: set log.file to null.
# The data volume is a local disk: set storage.allow_unsupported_fs to false (§7.3).

# 2. Build the images (or use the published ones, §3.3).
docker compose build

# 3. Generate the instance secret. umask 077: the file is never readable by others, even briefly.
mkdir -p secrets
(umask 077 && docker compose run --rm --no-deps -T app php bin/console app:secret:generate > secrets/app_secret)

# 4. The containers run as uid/gid 10001 and Compose secrets are plain bind mounts (owner and
#    mode kept): give group 10001 read access, and nobody else (Linux hosts; Docker Desktop
#    maps ownership itself).
sudo chgrp 10001 secrets/app_secret config/config.php
chmod 640 secrets/app_secret config/config.php

# 5. Check everything without writing anything, then start.
docker compose run --rm --no-deps -T app php bin/console app:boot --dry-run
docker compose up -d --wait

# 6. Confirm the instance is ready (exit code 0, "status": "ready").
docker compose exec -T app php bin/console app:config:check --format=json
```

Then configure the HTTPS reverse proxy (§9) and run the post-deployment verification (§13.1).

The dry run (§8.1) reports missing directories as warnings (`app:boot will create it`) on a fresh
volume: that is expected. Any error must be fixed before `up`.

The Dockerfiles pin their base images by digest, so a local build of a given commit uses the
same base layers.

### 3.2 Configuration overrides with Docker

Compose mounts only `config/config.php` and `config/themes/` into the containers.
**`config/config.local.php` is not mounted**: with Docker, put your settings in
`config/config.php`, or add the mount yourself in a `compose.override.yaml` (for both `app` and
`purge`, which must see the same configuration):

```yaml
services:
  app:
    volumes:
      - ./config/config.local.php:/app/config/config.local.php:ro
  purge:
    volumes:
      - ./config/config.local.php:/app/config/config.local.php:ro
```

Give that file the same owner and mode as `config.php` (`chgrp 10001`, `chmod 640`). The
image itself never contains `config.php`, `config.local.php` or the secret (they are excluded
from the build context by `.dockerignore`, together with `datas/`, test reports, coverage
output, `quietlink.phar` and local tool directories).

After a configuration change, reload without restarting (§9.5): `docker compose exec app
quietlink-reload` runs `app:boot` and, **only if it succeeds**, reloads PHP-FPM (`USR2`); on
failure it prints the errors and the instance keeps serving with the previous configuration.
Then restart the purge loop, which reads the configuration at each run anyway:
`docker compose restart purge`. Caveat: Compose mounts `config/config.php` as a single file, and
a bind-mounted file keeps pointing to the original inode: editors that save by replacing the
file (write to a temporary file, then rename) are not seen by the container. Edit in place
(`vim` with `:set backupcopy=yes`, `nano`), or recreate the containers instead
(`docker compose up -d --force-recreate app purge`; the entrypoint reruns `app:boot`, and a
failing boot then stops the container).

### 3.3 Published images

Each release publishes three signed images on GHCR, for **linux/amd64 only**, with one tag per
version (`vX.Y.Z`); there is no `latest` or floating `X.Y` tag. GHCR does not make tags
immutable: always deploy by the digest printed in the release notes (§15). Pre-releases
(`vX.Y.Z-beta.N`) are published the same way and marked as such on GitHub:

- `ghcr.io/marouane-hassine/quietlink-app:vX.Y.Z` (PHP-FPM application)
- `ghcr.io/marouane-hassine/quietlink-web:vX.Y.Z` (Nginx, static assets)
- `ghcr.io/marouane-hassine/quietlink-cli:vX.Y.Z` (command line client)

The release notes list the digest of each image. Deploy **by digest**
(`ghcr.io/marouane-hassine/quietlink-app@sha256:<digest>`) after verifying it (§15.3): a digest
cannot be moved, a tag could be re-pushed by whoever controls the registry. To use them with
the shipped Compose file, replace each `build:` block by the image reference, for example in a
`compose.override.yaml`:

```yaml
services:
  app:
    image: ghcr.io/marouane-hassine/quietlink-app@sha256:<digest>
  purge:
    image: ghcr.io/marouane-hassine/quietlink-app@sha256:<digest>
  web:
    image: ghcr.io/marouane-hassine/quietlink-web@sha256:<digest>
```

Then run `docker compose pull` and `docker compose up -d --no-build --wait` (the secret
generation command of the first start works the same way). Keep a note of the digests you
deploy: you need the previous ones to roll back (§15). Other architectures (for example arm64)
are not published: build the images locally from the tagged source as shown above.

## 4. Installation without Docker (PHP-FPM + Nginx or Apache)

No prebuilt archive is published for this mode: build from a checkout of a release tag,
verified against the release's signed provenance (step 1). The examples use `/srv/quietlink` for the code,
`/srv/quietlink/datas` for the data (the default `storage.data_dir`), `/etc/quietlink` for the
secret and the PHP-FPM files, and Debian-style binary names (`/usr/bin/php`,
`/usr/sbin/php-fpm8.3`); adapt them to your distribution.

1. Build the artefacts from the tagged source, as root (the code must not be writable by the
   application account):

   ```sh
   git clone https://github.com/marouane-hassine/quietlink /srv/quietlink
   cd /srv/quietlink
   git checkout vX.Y.Z
   # Release tags are not GPG-signed: check that the tag is the commit the signed release was
   # built from (its provenance attestation names the commit; requires gh and jq).
   gh release download vX.Y.Z --repo marouane-hassine/quietlink -p quietlink.phar -D /tmp/ql-verify
   gh attestation verify /tmp/ql-verify/quietlink.phar --repo marouane-hassine/quietlink \
     --signer-workflow marouane-hassine/quietlink/.github/workflows/release.yml \
     --source-ref refs/tags/vX.Y.Z --format json \
     | jq -r '.[0].verificationResult.statement.predicate.buildDefinition.resolvedDependencies[0].digest.gitCommit'
   git rev-parse HEAD   # must print the same commit
   composer install --no-dev --classmap-authoritative
   npm ci && npm run build          # produces public/build/
   APP_ENV=prod php bin/console cache:warmup --no-debug
   ```

2. Create a dedicated system account that owns the data, and nothing else:

   ```sh
   sudo useradd --system --home-dir /nonexistent --shell /usr/sbin/nologin quietlink
   ```

3. Create the directories (`app:boot` creates missing data sub-directories, but the parent
   must exist and be writable by the account):

   ```sh
   sudo install -d -o quietlink -g quietlink -m 0700 /srv/quietlink/datas
   sudo install -d -o quietlink -g quietlink -m 0755 /var/lib/quietlink-generated
   sudo install -d -o root -g quietlink -m 0750 /etc/quietlink
   ```

   The data directories must be **outside the web root**, owned by the application account,
   **mode 0700** (boot refuses group or other access) and must not be symbolic links. Only
   `generated_assets_dir` must be readable by the web server. The full permission table is in
   §7.6.
4. Copy and edit the configuration (§5), then restrict it:

   ```sh
   sudo cp config/config.php.example config/config.php
   # set app.public_url, storage.health_max_age = '10m', storage.allow_unsupported_fs = false
   # (local ext4/XFS disk) and, because the code tree is read-only for the service,
   # storage.generated_assets_dir = '/var/lib/quietlink-generated'
   sudo chown root:quietlink config/config.php && sudo chmod 640 config/config.php
   ```

5. Generate the secret (§6.1).
6. Set up PHP-FPM, `app:boot` and the purge with systemd (§4.1), or with your own init system
   following the same sequence: `app:boot` as the application account, then PHP-FPM; reload =
   `app:boot` then `USR2` to the PHP-FPM master; purge every minute.
7. Configure the web server (§4.2) and the HTTPS front (§9).
8. Run the post-deployment verification (§13.1).

### 4.1 systemd units

The specification requires a **dedicated PHP-FPM instance and unit** for QuietLink (not a pool
grafted onto the host's shared `php-fpm` service), `app:boot` run as `ExecStartPre=` with the
same account as the workers, a reload that runs `app:boot` and only then sends `USR2` to the
PHP-FPM master, and a purge timer every 60 seconds.

PHP settings: copy `docker/php/php.ini` to `/etc/quietlink/php.ini` and **delete its
`error_log` line** (under systemd, errors then go to stderr and the journal). Every QuietLink
process below uses this file with `-c`, so that `app:boot` checks the same limits (for example
`post_max_size`) as the workers.

`/etc/quietlink/php-fpm.conf` (the master runs as `quietlink`, so the pool needs no `user` or
`group` line):

```ini
[global]
error_log = syslog
syslog.ident = quietlink-fpm
daemonize = no
include = /etc/quietlink/php-fpm.d/*.conf
```

`/etc/quietlink/php-fpm.d/quietlink.conf` (adapted from `docker/php/pool.conf`):

```ini
[quietlink]
; Loopback only: the web server on the same host connects here.
listen = 127.0.0.1:9000
pm = dynamic
pm.max_children = 16
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 4
pm.max_requests = 1000
request_terminate_timeout = 30s
catch_workers_output = yes
decorate_workers_output = no
access.log = /dev/null
; PHP-FPM clears the environment: pass the variables explicitly.
clear_env = yes
env[APP_ENV] = prod
env[QUIETLINK_CONFIG_DIR] = /srv/quietlink/config
env[QUIETLINK_APP_SECRET_FILE] = /etc/quietlink/app_secret
```

`/etc/systemd/system/quietlink-fpm.service`:

```ini
[Unit]
Description=QuietLink PHP-FPM
After=network.target

[Service]
Type=simple
User=quietlink
Group=quietlink
UMask=0077
Environment=APP_ENV=prod
Environment=QUIETLINK_CONFIG_DIR=/srv/quietlink/config
Environment=QUIETLINK_APP_SECRET_FILE=/etc/quietlink/app_secret
Environment=QUIETLINK_FPM_POOL_FILE=/etc/quietlink/php-fpm.d/quietlink.conf
# Mandatory checks; PHP-FPM does not start if they fail.
ExecStartPre=/usr/bin/php -c /etc/quietlink/php.ini /srv/quietlink/bin/console app:boot --no-interaction
ExecStart=/usr/sbin/php-fpm8.3 --nodaemonize --fpm-config /etc/quietlink/php-fpm.conf -c /etc/quietlink/php.ini
# systemctl reload: app:boot first, USR2 (graceful reload) only if it succeeded.
ExecReload=/bin/sh -c '/usr/bin/php -c /etc/quietlink/php.ini /srv/quietlink/bin/console app:boot --no-interaction && /bin/kill -USR2 "$MAINPID"'
KillSignal=SIGQUIT
TimeoutStopSec=30
Restart=on-failure
# Hardening: the code is read-only, only the data and generated assets are writable.
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ReadWritePaths=/srv/quietlink/datas /var/lib/quietlink-generated

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/quietlink-purge.service`:

```ini
[Unit]
Description=QuietLink purge of expired content
After=quietlink-fpm.service

[Service]
Type=oneshot
User=quietlink
Group=quietlink
UMask=0077
Environment=APP_ENV=prod
Environment=QUIETLINK_CONFIG_DIR=/srv/quietlink/config
Environment=QUIETLINK_APP_SECRET_FILE=/etc/quietlink/app_secret
ExecStart=/usr/bin/php -c /etc/quietlink/php.ini /srv/quietlink/bin/console app:purge-expired --no-interaction
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths=/srv/quietlink/datas
```

`/etc/systemd/system/quietlink-purge.timer`:

```ini
[Unit]
Description=Run the QuietLink purge every minute

[Timer]
OnBootSec=60s
OnUnitActiveSec=60s
AccuracySec=1s

[Install]
WantedBy=timers.target
```

Enable and check:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now quietlink-fpm.service quietlink-purge.timer
systemctl status quietlink-fpm.service       # "active (running)"; boot errors appear here
systemctl list-timers quietlink-purge.timer  # next run within a minute
journalctl -u quietlink-purge.service -n 5   # "purge: {...}" lines (counts only)
```

After a configuration, secret or theme change: `sudo systemctl reload quietlink-fpm` (if
`app:boot` fails, the reload fails and the running workers are kept; fix the error, then reload
again). After a code upgrade: `sudo systemctl restart quietlink-fpm` (§15.2).

The `UMask=0077` line keeps data files private; the generated theme directory stays readable by
the web server because it is created in step 3 with mode 0755 and its files are written 0644.
The purge also refreshes `health.json`; if the timer stops, creation is refused within 10
minutes (§7.4). A cron entry is an acceptable alternative to the timer:

```cron
* * * * * quietlink APP_ENV=prod QUIETLINK_APP_SECRET_FILE=/etc/quietlink/app_secret QUIETLINK_CONFIG_DIR=/srv/quietlink/config /usr/bin/php -c /etc/quietlink/php.ini /srv/quietlink/bin/console app:purge-expired --no-interaction >/dev/null
```

### 4.2 Web server

The reference is `docker/nginx/default.conf`. Required points:

- document root `public/`; `/build/` served statically with
  `Cache-Control: public, max-age=31536000, immutable`;
- the Argon2id worker (`/build/assets/argon2.worker-*.js`) must be served with the dedicated
  `Content-Security-Policy` of the reference file, which adds `'wasm-unsafe-eval'` to
  `script-src` (it is the only script allowed to compile WebAssembly; without this header
  passphrase protection fails in the browser);
- `/themes/generated/tokens.<16 hex>.css` mapped to `generated_assets_dir`;
- everything else to `public/index.php` through FastCGI (`SCRIPT_FILENAME`
  `/srv/quietlink/public/index.php`, `fastcgi_pass 127.0.0.1:9000`);
- `client_max_body_size` ≥ `http.max_request_bytes` (default 1,441,792 bytes = 1408k), and PHP
  `post_max_size` at least as large (§18);
- no access log (URLs, addresses and user agents must not be collected), `error_log … emerg`
  (lower levels record the client address and request line, §11), `server_tokens off`;
- writable temporary paths with enough room for request bodies above the buffer
  (`client_body_temp_path`) and large responses (`fastcgi_temp_path`).

When Nginx on the same host terminates TLS itself and talks FastCGI directly to PHP-FPM, PHP
sees the real client address and `HTTPS=on` (from `fastcgi_params`): leave
`http.trusted_proxies` empty in that topology. `app:boot` then prints the warning
`http.trusted_proxies is empty…`, which is harmless here because no proxy sits in between.

With Apache, reproduce the same rules (`mod_proxy_fcgi`, `mod_headers`), disable `.htaccess`
overrides and make sure no `CustomLog` applies to the virtual host (§9.3).

### 4.3 Shared hosting (Apache, no root)

The default configuration targets shared hosting (lessons from a staging installation on OVH).
Install from the production archive (vendor/ and the frontend already built):

1. Upload the archive contents, then make the site's **document root the `public/` directory**
   (never the project directory: it holds `config/`, `.env` and `datas/`). `public/.htaccess`
   is shipped: HTTPS redirect (except for `localhost`), front controller, asset headers, the
   Argon2id worker policy, hidden files refused (`404`; Apache itself usually answers `403` for
   `.ht*` files), no compression. It needs `mod_rewrite`, `mod_headers` and `AllowOverride`
   permitting `Options`, `FileInfo`, `Indexes` and `Limit` (otherwise the site answers `500`).
2. Select PHP ≥ 8.3 for the website (OVH: `app.engine.version=8.3` in `.ovhconfig`), with the
   `intl`, `mbstring`, `openssl` and `sodium` extensions (`app:boot` names a missing one).
3. Write the secret to `.env` at the project root (outside `public/`), without printing it:
   `php bin/console app:secret:generate --output .env --dotenv` (mode 0600). Every process
   finds it there: the console, the website and the scheduled purge; no `SetEnv`,
   `.user.ini` or wrapper script is needed. A `QUIETLINK_APP_SECRET(_FILE)` variable, when
   set, takes precedence (Docker, systemd).
4. Edit `config/config.php` (`app.public_url`; the defaults suit shared hosting: data in
   `datas/`, theme files in `var/generated/`), then `chmod 600 config/config.php .env`. Mode
   600 assumes PHP runs as the account owning the files (OVH and most shared hosts); if the web
   server runs PHP under another account, give that account read access instead.
5. `php bin/console app:boot --dry-run`, then **`php bin/console app:boot`** (the dry run
   writes nothing: the site answers `503` until `app:boot` itself has run, and again after
   every configuration change), then `php bin/console app:config:check --format=json`.
6. Schedule `php /path/to/quietlink/bin/console app:purge-expired` as often as the host allows
   (hourly is enough with the default `storage.health_max_age` of `2h`). Without a CLI cron,
   `storage.web_purge` (default `true`) lets web requests run an overdue purge: an HTTP cron
   calling `https://<host>/healthz` every 5 minutes keeps it regular even without visitors.
7. Check HSTS: `curl -sI https://<host>/ | grep -i strict-transport-security` must print one
   line. The header comes from the application, which must see the request as HTTPS; if it is
   missing behind the host's TLS proxy, set `http.trusted_proxies` (§9).

Defaults chosen for shared hosting, and what they cost:

- **Network filesystems (NFS) are accepted** (`storage.allow_unsupported_fs = true`), with a
  warning at each boot. Over NFS, `flock()` and atomic `link()`/`rename()` may not be reliable:
  under concurrent requests a read-once text could be read twice, quota counters could drift
  and an interrupted creation could leave a duplicate. On a dedicated server with a local ext4
  or XFS disk, set `storage.allow_unsupported_fs = false` to refuse other filesystems.
- **Hourly purge tolerated.** `health.json` (refreshed by the purge) counts as stale after
  `storage.health_max_age` (default `2h`), so an hourly cron keeps `/healthz` at `ok`; it turns
  `degraded` only when the purge has not run for two hours. Creation always measures the free
  disk space itself; the free inode threshold, which only the CLI purge can measure (`df`),
  applies to its last measurement. Expired pastes are removed only when a purge runs (CLI cron,
  or a web request when the last one is overdue, `storage.web_purge`).
- `app:boot` cannot check a PHP-FPM pool there (`QUIETLINK_FPM_POOL_FILE` warning) nor measure
  inodes without `df` (warning): both are expected on shared hosting.

**Diagnostics.** Everything QuietLink logs goes to `var/log/quietlink.log` (`tail -f
var/log/quietlink.log`): why the site answers `503` (`boot_marker_mismatch` with its reason,
one `config_invalid` line per configuration error), the outcome of each `app:boot`, each purge
run (cron or web request). To try debug mode briefly, `APP_ENV=dev` can be set in `.env`
(`app:boot` warns while it is there); remove it afterwards: it is never meant for a public site,
and QuietLink shows no error details in the browser anyway.

When the site answers `503` (`/healthz`: `unavailable`), `app:config:check` prints the reason
(`config_invalid`, `marker_missing`, `fingerprint_differs`, `secret_differs`) and the project
root it sees; the web side logs the same reason with the `boot_marker_mismatch` event.

## 5. Configuration

### 5.1 Files and loading order

1. Versioned defaults (`QuietLink\Config\ConfigLoader::defaults()`).
2. `config/config.php` (**required**; copy `config/config.php.example`).
3. `config/config.local.php` (optional, installation-specific overrides, never committed; see
   `config/config.local.php.example`; not mounted by the shipped Compose file, §3.2).

The directory is `config/` by default and can be changed with `QUIETLINK_CONFIG_DIR`. Each file
returns a PHP array. Arrays are merged key by key, except list values (marked "list" below),
which replace the default entirely. You only need to write the keys you change; the example
file shows every key with its default.

Validation is strict: **unknown keys and wrong types are errors** (list values may only contain
strings). Configuration files must never contain secrets.

After **any** change, including an edit of the theme tokens file referenced by
`theme.custom_tokens_file` (its content is part of the fingerprint), run `app:boot` and reload
PHP-FPM (Docker: recreate `app` and `purge`; systemd: `systemctl reload quietlink-fpm`). Until
the boot marker matches the loaded configuration, every request is answered with a generic
`503`, `/healthz` returns `{"status":"unavailable"}` and the log shows the
`boot_marker_mismatch` event (§11). To validate an edit before applying it, run
`app:boot --dry-run` (§8.1).

### 5.2 Reference

Durations use the format `<integer><m|h|d>` (e.g. `30m`, `24h`, `7d`). Expiration codes are
`5m`, `1h`, `1d`, `7d`, `30d`.

#### `app`

| Key | Default | Rule |
|---|---|---|
| `app.name` | `'QuietLink'` | Non-empty string; instance name displayed in pages. |
| `app.public_url` | `null` (must be set) | Required. `https://` origin without path, query, fragment or credentials. `http://` is accepted only for `localhost`, `127.0.0.1`, `[::1]`. |
| `app.source_url` | `'https://github.com/marouane-hassine/quietlink'` | `https://` URL of the deployed source code, linked in the footer (AGPL-3.0 section 13). Point it to your fork if you modify the code. |
| `app.enabled_locales` | `null` = every shipped catalogue (currently `en`, `ar`, `es`, `fr`, `it`) | `null` or a list. `null` is resolved when the configuration is loaded, so languages added by a later release are enabled automatically. A list must contain `en` (mandatory fallback), only locales with a valid catalogue in `translations/`, no duplicates. |

#### `theme`

| Key | Default | Rule |
|---|---|---|
| `theme.name` | `'default'` | Active theme. Only the built-in `default` theme is available; adapt it with `theme.custom_tokens_file`. |
| `theme.custom_tokens_file` | `null` | `null` or a relative `.json` path inside `config/themes/` (`[A-Za-z0-9_-]` segments, no `..`); the file must exist. See §12. |

#### `storage`

| Key | Default | Rule |
|---|---|---|
| `storage.driver` | `'filesystem'` | Only `filesystem` is supported. |
| `storage.data_dir` | `'datas'` | Data directory: absolute, or relative to the project root; without `..`; outside `public/` (checked after normalisation of `.` segments and doubled slashes, through symbolic links and ignoring letter case); not empty, `/` or the project root itself. The four directories below derive from it. |
| `storage.root_dir` | `null` (`<data_dir>/pastes`) | Pastes. `null` derives it from `data_dir`; a path (absolute or relative to the project root) places it elsewhere. Same rules; mode 0700. |
| `storage.idempotency_dir` | `null` (`<data_dir>/idempotency`) | Same rules; mode 0700. |
| `storage.ratelimit_dir` | `null` (`<data_dir>/ratelimit`) | Same rules; mode 0700. |
| `storage.state_dir` | `null` (`<data_dir>/state`) | Same rules; mode 0700. |
| `storage.generated_assets_dir` | `'var/generated'` | Generated theme CSS. Same path rules; mode 0755, readable by the web server (the Docker image mounts its volume on `/app/var/generated`). |
| `storage.max_total_bytes` | `10737418240` (10 GiB) | Integer ≥ 1. Total ciphertext quota. |
| `storage.max_items` | `100000` | Integer ≥ 1. Maximum number of stored pastes. |
| `storage.min_free_bytes` | `1073741824` (1 GiB) | Integer ≥ 1. Creation is refused below this free space. |
| `storage.min_free_inodes_percent` | `10` | Integer 0–50. Creation is refused below this free inode percentage. |
| `storage.health_max_age` | `'2h'` | Duration between `10m` and `24h`. Age after which `health.json` (written by `app:boot` and every purge run) counts as stale: `/healthz` answers `degraded` (log event `health_stale`). `2h` (default) tolerates the hourly cron of shared hosting; set `'10m'` when the purge runs every minute (Docker Compose, systemd timer) so that a stopped purge is detected quickly. |
| `storage.web_purge` | `true` | Boolean. When the last purge (`health.json`) is more than 5 minutes old, a web request runs it once its response has been sent (no dedicated endpoint, no token). For hosts without a CLI cron: an HTTP cron (the host's, or an external one) calling `/healthz` every few minutes is enough. Such a purge cannot run `df`: it keeps the last inode measurement; it spends at most 15 seconds on pastes (the next run resumes where it stopped, `state/purge.cursor`; the hourly usage recomputation waits for a run that finishes in time) and runs only under PHP-FPM or LiteSpeed, which end the response first (never under mod_php or CGI, where the visitor would wait). Never triggers with a per-minute purge. `false` disables it. |
| `storage.allow_unsupported_fs` | `true` | Boolean. `true` (default, shared hosting): filesystems other than ext4/XFS (NFS…) are accepted with a boot warning, at the cost of locking guarantees (§4.3). `false`: refused, as recommended on a dedicated server. |

#### `paste`

| Key | Default | Rule |
|---|---|---|
| `paste.default_expiration` | `'1d'` | Must belong to `paste.allowed_expirations`. |
| `paste.allowed_expirations` | `['5m', '1h', '1d', '7d', '30d']` | List, non-empty, only the codes above. `never` is not listed here: it is offered by `allow_forever`. Each code must not exceed `max_retention`. |
| `paste.allow_forever` | `false` | Boolean. Offers `never`; requires `paste.max_retention = null`. |
| `paste.allow_read_once` | `true` | Boolean. |
| `paste.allow_passphrase` | `true` | Boolean. Automatically disabled when libsodium lacks Argon2id (no silent downgrade). |
| `paste.max_envelope_bytes` | `1048576` (1 MiB) | Integer 1024–16777216 (16 MiB). Maximum plaintext envelope size; the ciphertext limit is this value + 16. Raise `http.max_request_bytes`, the web server body limit and PHP `post_max_size` with it. |
| `paste.max_metadata_bytes` | `4096` | Integer 512–4096. Maximum AAD size. |
| `paste.max_retention` | `'30d'` | Duration or `null` (no upper bound, required by `allow_forever`). Applies to everything stored: lowering it also shortens pastes created before (they expire at creation + new maximum, the expiry shown to readers included); raising it never lengthens them. |
| `paste.max_unconfirmed_opens` | `3` | Integer 1–10. Reservations of a read-once paste that may expire without confirmation before it is destroyed. |
| `paste.read_once_reservation_ttl` | `60` | Integer 30–300 (seconds). Lifetime of a read-once reservation. |
| `paste.idempotency_max_ttl` | `'24h'` | Duration between `1h` and `7d`. Retention of `Idempotency-Key` records. |

#### `http`

| Key | Default | Rule |
|---|---|---|
| `http.max_request_bytes` | `1441792` | Integer; must be ≥ `ceil((max_envelope_bytes + 16) × 4/3) + ceil(max_metadata_bytes × 4/3) + 16384` (1,419,969 with the defaults). Keep the web server body limit and PHP `post_max_size` at least as large (`app:boot` warns about `post_max_size`), and size the application container's `/tmp` tmpfs (where PHP-FPM spools request bodies, 64 MiB by default) for several bodies at once: about `max_request_bytes` × the expected concurrent creations (at the 16 MiB maximum, use 256m or more). The CLI refuses envelopes above 1 MiB unless given `--max-bytes`. |
| `http.ratelimit_ipv6_prefix` | `64` | Integer 48–64. IPv6 clients are rate limited per prefix. |
| `http.trusted_proxies` | `[]` | List of IPv4 or IPv6 addresses or CIDR ranges (prefix 0–32 for IPv4, 0–128 for IPv6) whose `X-Forwarded-For` and `X-Forwarded-Proto` headers are honoured. `Forwarded`, `X-Forwarded-Host` and `X-Forwarded-Port` are always ignored. See §9. |
| `http.cors_allowed_origins` | `[]` | List of exact origins allowed to call `/api/v1` from a browser on another site, written as the browser sends them: lowercase `https://host[:port]`, no path, no trailing slash, no wildcard, no default port (`http://` only for `localhost`, `127.0.0.1`, `[::1]`). Empty = CORS disabled. See §9. |
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
| `log.level` | `'info'` | PSR-3 level: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. The operations events of §11 are `warning`, the metrics line is `info`. |
| `log.file` | `'var/log/quietlink.log'` | `null` or a file path, relative to the project root or absolute, outside `public/`, without `..`. JSON lines rotated at 5 MB (one archive `<file>.1`); falls back to stderr when it cannot be written. `null` (stderr) for Docker and systemd. |
| `log.retention` | `'14d'` | Duration. Documents the retention expected from your log collector; QuietLink itself does not store logs (§11). |

#### `metrics`

| Key | Default | Rule |
|---|---|---|
| `metrics.enabled` | `false` | Boolean. When `true`, each purge run writes one aggregated, non-identifying log line `{"message":"metrics","event":"storage","count":<items>,"percent":<quota use>}` at level `info` (§11). No metrics endpoint is exposed. |

### 5.3 Environment variables

| Variable | Used by | Purpose |
|---|---|---|
| `QUIETLINK_APP_SECRET` | app, console | Instance secret, standard base64 of ≥ 32 bytes. |
| `QUIETLINK_APP_SECRET_FILE` | app, console | Path of a file containing the secret (preferred: Docker secret under `/run/secrets/`, or `/etc/quietlink/app_secret`). Setting both variables is an error. |
| `QUIETLINK_CONFIG_DIR` | app, console | Configuration directory (default `<project>/config`). |
| `QUIETLINK_FPM_POOL_FILE` | `app:boot` | PHP-FPM pool file checked for the secret variable (set in the Docker image). |
| `APP_ENV` | app, console | `prod` by default. Debug mode can never be enabled in `prod`. Also read, with `APP_DEBUG`, from the `.env` file at the project root when the process does not set it (shared hosting); `app:boot` warns while it is `dev`, which must never stay on a public site. |
| `QUIETLINK_HTTP_PORT` | Compose | Host port of the `web` service on `127.0.0.1` (default `8080`). |
| `QUIETLINK_SERVER` | `quietlink` CLI | Default instance URL for `create`. |

The shipped `docker/php/pool.conf` forwards only `QUIETLINK_APP_SECRET_FILE`. If you use the
inline `QUIETLINK_APP_SECRET` instead, adapt the pool, otherwise `app:boot` fails.

QuietLink reads its secret only from these variables: the Symfony secrets vault is disabled
(there are no `secrets:*` commands).

## 6. Instance secret

The secret keys the challenges (`open`, `status`, `consume`) and the rate limiting keys. It is
never written to configuration files, never printed by the operations commands
(`app:config:check` shows only `secret: present (not shown)`) and never logged. It is **not**
used to encrypt pastes: the content keys live only in the share links.

### 6.1 Generating it

On shared hosting, write it to `.env` at the project root: `php bin/console app:secret:generate
--output .env --dotenv` (a `QUIETLINK_APP_SECRET=…` line, mode 0600, never printed). QuietLink
reads `.env` only for `QUIETLINK_APP_SECRET` and `QUIETLINK_APP_SECRET_FILE` (a relative file
path is resolved from the project root), and only when the environment sets neither: Docker and
systemd keep using the variable. `.env` must stay outside `public/` (the shipped `.htaccess` and
the Nginx configuration refuse hidden files anyway), mode 0600; `app:boot` warns otherwise.

The file must be readable by the account running PHP (and `app:boot`), and by nobody else.
`app:boot` warns when the secret file is readable by every account.

Without Docker (file owned by root, readable by the `quietlink` group):

```sh
# Written directly to a new file (mode 0640), never printed.
sudo php /srv/quietlink/bin/console app:secret:generate --output /etc/quietlink/app_secret --group-readable
sudo chgrp quietlink /etc/quietlink/app_secret
```

`--output` refuses to overwrite an existing file (exit code `1`). Without `--group-readable`
the file is created with mode 0600, which is right when the command runs as the application
account itself and the file stays owned by it. An equivalent without `--output`, which creates
the empty file with the right owner and mode first, then fills it:

```sh
sudo install -m 0640 -o root -g quietlink /dev/null /etc/quietlink/app_secret
sudo sh -c 'php /srv/quietlink/bin/console app:secret:generate > /etc/quietlink/app_secret'
```

Do not use `app:secret:generate > file; chmod 600 file` as root: the file would be owned by
root with mode 0600, and the `quietlink` workers could not read it (`app:boot` then fails with
`QUIETLINK_APP_SECRET_FILE does not point to a readable file.`).

With Docker, see §3.1 step 3 and 4 (`umask 077`, then `chgrp 10001` and `chmod 640`).

Keep a copy of the secret in your password or secret manager. Losing it is not a disaster (see
the consequences below): generate a new one.

### 6.2 Rotation

When: suspected disclosure of the secret, an administrator leaving, or a periodic policy.

Without Docker:

```sh
sudo php /srv/quietlink/bin/console app:secret:generate --output /etc/quietlink/app_secret --group-readable --force
sudo chgrp quietlink /etc/quietlink/app_secret
sudo systemctl reload quietlink-fpm     # runs app:boot, then reloads PHP-FPM
```

`--force` replaces the file atomically (temporary file then `rename()`). The new file is owned
by the user who ran the command, hence the `chgrp`.

With Docker:

```sh
(umask 077 && docker compose run --rm --no-deps -T app php bin/console app:secret:generate > secrets/app_secret.new)
sudo chgrp 10001 secrets/app_secret.new && chmod 640 secrets/app_secret.new
mv secrets/app_secret.new secrets/app_secret
docker compose up -d --force-recreate --wait app purge
```

Until `app:boot` has run with the new secret, requests receive `503` (the boot marker stores a
check value of the secret).

Consequences of a rotation:

- existing pastes stay readable and deletable: their keys are in the links, not derived from
  the secret;
- pending `open` and `status` challenges become invalid; clients transparently request a new
  one (one retry);
- ongoing read-once reservations are **not** affected (their `consume` challenge is compared
  with the stored value);
- rate limiting counters start again from zero (their keys are derived from the secret);
- a secret disclosed before the rotation only allowed forging challenges and computing rate
  limiting keys; it never allowed decrypting content.

## 7. Storage

### 7.1 Layout

```text
datas/                              # storage.data_dir (project root by default; Docker volume)
├── pastes/<s1>/<s2>/<id>/          # storage.root_dir, sharded by id prefix
│   ├── payload.bin                 # nonce ‖ ciphertext (removed on consume/delete)
│   ├── meta.json                   # immutable
│   ├── state.json                  # mutable state
│   └── state.lock                  # flock() target
├── idempotency/                    # storage.idempotency_dir
├── ratelimit/                      # storage.ratelimit_dir
└── state/                          # storage.state_dir
    ├── usage.json  health.json  boot.json
    ├── usage.lock  purge.lock  purge.cursor (optional, web purge)
    └── creating/                   # empty, randomly named markers of creations in progress
var/generated/                      # storage.generated_assets_dir (default)
├── tokens.json                     # theme manifest
└── tokens.<hash>.css
```

`<s1>` and `<s2>` are the first two and the next two characters of the paste identifier (the
part after `/p/` in a link). Directories are `0700`, files `0600` (except the generated
assets). All writes use a temporary file followed by an atomic `rename()`/`link()`;
concurrency is handled with `flock()`. Every JSON file carries a `schema_version` (schemas in
`docs/schemas/`). Details: `docs/storage-format.md`.

`state/creating/` holds one empty marker per creation in progress so that the hourly usage
recomputation never erases a creation that is not on disk yet; markers older than one hour
(left by a crash) are removed by the purge. They contain nothing and are named randomly.

Never edit these files by hand while the instance runs. Never remove `*.lock` files.

### 7.2 Locks

Requests retry a busy paste lock for at most 2 seconds, then answer `503` with `Retry-After`.
Quota decrements wait longer for `usage.lock` (several bounded attempts) rather than being lost.
The purge uses a global non-blocking `purge.lock`: a second concurrent run exits immediately
(`purge: another run is in progress`). `app:boot` creates the lock files; a missing lock file
makes the purge fail with a message asking to run `app:boot`.

### 7.3 Supported filesystems

`app:boot` reads `/proc/self/mounts` and accepts **ext4 and XFS** (§9.4.1) for every storage
directory, and verifies that `rename()` and `link()` work atomically in each of them.

Network and overlay filesystems (NFS, SMB/CIFS, FUSE, overlayfs without a volume, etc.) are not
supported: `flock()` and hard links are unreliable there. `storage.allow_unsupported_fs = true`
turns the error into a warning and is the **default** (shared hosting, §4.3); set it to `false` on
a dedicated server so that an undetermined type, Btrfs or a network filesystem stops boot.

### 7.4 Quotas, disk space and inodes

Creation is refused with `503` (`Retry-After: 300`) when `max_total_bytes` or `max_items` would
be exceeded, and also when free space (measured at each creation) is below `min_free_bytes`
(default 1 GiB), or the last measurement of free inodes (however old) is below
`min_free_inodes_percent` (default 10 %). Reading and deleting existing pastes keeps working.

`health.json` holds the last measurement of free bytes and free inode percentage of the data
volume (PHP cannot read inode counts itself: they come from `df -P -i`). It is written by
`app:boot` and by every purge run. Older than `storage.health_max_age` (default `2h`; `10m`
recommended with a per-minute purge), it is stale and
`/healthz` turns `degraded` (log event `health_stale`, §11). `app:config:check` shows its age
and content (§8.3).

Check the data volume by hand:

```sh
# Without Docker
df -h /srv/quietlink/datas          # free space
df -i /srv/quietlink/datas          # free inodes ("IUse%" must stay below 90 %)
# With Docker (inside the app container, on the mounted volume)
docker compose exec -T app df -h /app/datas
docker compose exec -T app df -i /app/datas
docker compose exec -T app php bin/console app:config:check --format=json   # "health" block
```

Each paste uses about 5 inodes (its directory and four files) plus one per idempotency record;
a filesystem with few inodes (small ext4 volumes, some cloud images) can run out of inodes long
before it runs out of space. If inodes cannot be measured (no `df`, or a filesystem that does
not report them), `app:boot` warns and the inode threshold is not enforced.

Recommendations:

- Alert at **80 %** of `max_total_bytes` and `max_items`: the purge logs the `quota_alert`
  event (§11) above that level; `state/usage.json` contains `bytes` and `items` (it is
  recomputed by the purge at most once per hour).
- Alert at 80 % disk or inode usage of the data volume.
- Alert when `/healthz` returns `503` (`degraded`): usually the purge is not running or disk
  space/inodes are low.
- Keep the data volume dedicated to QuietLink so other workloads cannot exhaust it.

### 7.5 Backup and restore

There is **no backup command on purpose**: backups are made with ordinary system tools on the
data directory, so that you choose where they go and how they are encrypted.

What a backup contains: ciphertexts, which are useless without the keys contained in the share
links, but also metadata (creation and expiry times, sizes, read-once flags). Treat backups as
confidential: **encrypt them** and restrict access.

**Read-once caveat.** Backups weaken read-once guarantees: a restored paste that was consumed
after the backup can be read again, and a deleted paste comes back until it expires. Keep backup
retention short (expired content is deleted by the first purge after a restore anyway), or do not
back up pastes at all if this is unacceptable for your users.

What to back up:

- the whole data directory (`datas/`, or the configured directories): `pastes/` and
  `idempotency/` matter; `ratelimit/` and `state/` are rebuilt by `app:boot` and the purge but
  are small, and backing up the whole directory keeps the procedure simple;
- separately: `config/config.php`, `config/config.local.php`, `config/themes/`, and the secret
  (in your secret manager, never next to the data backups).

A copy of a running data directory is **not** a consistent backup (the specification forbids it):
stop `app` and `purge` first, or use a filesystem snapshot (LVM, Btrfs, cloud volume snapshot).

#### Docker (named volume)

```sh
VOL=quietlink_data                   # <project>_data, see "docker volume ls"
IMAGE=quietlink/app:dev              # or ghcr.io/marouane-hassine/quietlink-app@sha256:<digest>
mkdir -p backups
docker compose stop purge app        # web keeps answering 502 meanwhile; stop it too if you prefer
# tar runs as uid 10001, which owns the files; nothing is written to the volume.
docker run --rm --entrypoint tar -v "$VOL":/app/datas:ro "$IMAGE" -C /app/datas -cf - . \
  | gpg --symmetric --cipher-algo AES256 -o "backups/quietlink-data-$(date +%Y%m%d-%H%M).tar.gpg"
docker compose start app purge
```

Any encryption tool works instead of `gpg --symmetric` (for example `age -r <recipient>`).
Copy the encrypted archive off the host.

Restore (replaces the current data; keep the current volume until the restore is verified):

```sh
docker compose down                       # stops and removes the containers, keeps the volumes
docker volume rm "$VOL"                   # or keep it and restore into a new project/volume
docker compose up --no-start              # recreates an empty volume with Compose's labels
gpg --decrypt backups/quietlink-data-<date>.tar.gpg \
  | docker run --rm -i --user 0 --entrypoint sh -v "$VOL":/app/datas "$IMAGE" \
      -c 'tar -C /app/datas -xf - && chown -R 10001:10001 /app/datas && chmod 700 /app/datas'
docker compose up -d --wait               # the entrypoint runs app:boot
docker compose exec -T app php bin/console app:config:check --format=json
```

The restore container runs as root only to set the owner (`10001`) and modes back; the files
keep `0700`/`0600` from the archive. `app:boot` refuses storage directories that are accessible
to other accounts (§18).

#### Without Docker

```sh
sudo systemctl stop quietlink-purge.timer quietlink-fpm
sudo tar -C /srv/quietlink -cpf - datas \
  | gpg --symmetric --cipher-algo AES256 -o /root/quietlink-data-$(date +%Y%m%d-%H%M).tar.gpg
sudo systemctl start quietlink-fpm quietlink-purge.timer
```

Restore: stop both units, move the current `datas/` aside, extract the archive as root with
`tar -xpf` (it keeps owners and modes), check `stat -c '%U %a' /srv/quietlink/datas/*`
(`quietlink 700`), keep `*.lock` files in place, then start `quietlink-fpm` (its
`ExecStartPre` runs `app:boot`) and the timer. The next purges correct `usage.json`.

#### Restore test (regularly, at least quarterly)

The specification requires restores to be tested regularly. A routine that never touches
production data:

1. Take the latest encrypted backup.
2. On a test machine with a checkout of the deployed version, its own `config/config.php`
   and its own throwaway secret (the secret does not protect stored content, so the production
   secret is not needed), restore it into a separate Compose project with its own volume and
   port: `docker compose -p quietlink-restoretest up --no-start`, then the restore command
   above with `VOL=quietlink-restoretest_data`, then
   `QUIETLINK_HTTP_PORT=18081 docker compose -p quietlink-restoretest up -d --wait`.
3. Check `docker compose -p quietlink-restoretest exec -T app php bin/console app:config:check --format=json`
   (exit `0`, `"ready": true`) and count restored pastes:
   `docker compose -p quietlink-restoretest exec -T app sh -c 'find /app/datas/pastes -name meta.json | wc -l'`.
4. Note the date, the archive and the result in your operations log.
5. Remove everything: `docker compose -p quietlink-restoretest down -v`.

### 7.6 Permissions

| Path | Owner:group | Mode | Notes |
|---|---|---|---|
| Code (`/srv/quietlink`, including `vendor/`, `public/`, prewarmed `var/cache/`) | `root:root` | dirs `0755`, files `0644` | Never writable by the application account. |
| `config/config.php`, `config/config.local.php` | `root:quietlink` (Docker host: `<you>:10001`) | `0640` | `app:boot` warns when readable by every account. |
| `config/themes/` and token files | `root:quietlink` | `0750` / `0640` | Readable by the application. |
| Secret file (`/etc/quietlink/app_secret`, `secrets/app_secret`) | `root:quietlink` (Docker host: `<you>:10001`) | `0640` (or `0600` owned by the application account) | `app:boot` warns when readable by every account. |
| `/etc/quietlink/` | `root:quietlink` | `0750` | PHP-FPM files and the secret. |
| `datas/` and its `pastes/`, `idempotency/`, `ratelimit/`, `state/` | `quietlink:quietlink` (Docker: `10001:10001`) | `0700`; files `0600` | `app:boot` **refuses** the four storage directories if they belong to another account or grant any group/other access (for example `0755`). |
| `generated_assets_dir` (`/var/lib/quietlink-generated`) | `quietlink:quietlink` | `0755`; files `0644` | Read by the web server. |

## 8. Console commands

All commands run as the application account with the same environment as PHP-FPM
(Docker: `docker compose exec -T app php bin/console <command>`, or
`docker compose run --rm --no-deps -T app php bin/console <command>` when `app` is not
running).

| Command | Purpose |
|---|---|
| `app:boot [--dry-run] [--format=text\|json]` | Validates the configuration, PHP runtime, directories (creation, ownership, mode 0700, outside web root, no symlink, atomic `rename()`/`link()`, filesystem type), creates lock files, checks the PHP-FPM pool when `QUIETLINK_FPM_POOL_FILE` is set, compiles the theme tokens, writes `health.json` and the boot marker `boot.json`. See §8.1. |
| `app:config:check [--format=text\|json]` | Read-only: validates and prints the effective configuration, `secret: present (not shown)`, the configuration fingerprint, whether the boot marker matches, and the last disk measurement. See §8.3. |
| `app:secret:generate [--output=<file> [--group-readable] [--force]]` | Prints a new secret (32 random bytes, standard base64), or writes it to a new file without printing it. See §6. |
| `app:purge-expired` | See §8.2. |
| `app:theme:preview --output=<dir>` | Writes `index.html` (light) and `dark.html` (dark) showing every component and state with the configured theme tokens, dummy content only, no script. Open them locally before activating a theme. |
| `app:cache:purge` | Controlled purge of non-sensitive caches: removes generated theme stylesheets no longer referenced after a theme change and `app:boot`. Hashed frontend assets change name with each release; the Symfony container is compiled per release under `var/cache/<env>/<version>/` (`<version>-<commit>` for a production archive, which carries its commit in `BUILD`), so an upgrade never boots on the previous one (remove older directories after a non-Docker upgrade). Pastes, keys and secrets are never cached. |

Built-in Symfony commands that write to `var/` (`cache:clear`, `cache:warmup`, `assets:install`)
do **not** apply to a running instance: the code and its prewarmed cache are read-only (§14).
The cache is built once per release (Docker build, or step 1 of §4). `secrets:*` commands do not
exist (the vault is disabled).

### 8.1 Boot (`app:boot`)

`app:boot` must succeed before PHP-FPM starts and after every change of configuration, secret,
theme file or release. The Docker entrypoint and the systemd unit (§4.1) run it automatically.

Errors (exit code `1`, nothing started) include: invalid configuration, missing PHP extension,
storage directory not owned by the application account or accessible to other accounts,
unsupported filesystem, failing atomic `rename()`/`link()`, PHP-FPM pool not passing the
secret variable, invalid theme tokens.

Warnings (exit code `0`, printed as `warning: …`) include: free disk space or inodes below the
thresholds (creation refused), inodes not measurable, secret file or `config.php` readable by
every account, PHP `post_max_size` below `http.max_request_bytes`, empty `trusted_proxies` with
an `https` public URL, unknown filesystem type, pool file not checked.

**`--dry-run`** runs every check but creates, writes and deletes nothing: no directory, no lock
file, no theme stylesheet, no `health.json`, no boot marker. Missing directories become
warnings and are checked through their nearest existing parent (which must be writable); the
atomic `rename()`/`link()` probe, which needs test files, is skipped. Use it:

- before the first start (§3.1), on the target machine;
- to validate a configuration edit before applying it;
- before an upgrade, with the new image or code and the current configuration (§15).

A dry run never makes the instance ready: run `app:boot` itself afterwards (Docker: recreate
`app`; systemd: reload).

### 8.2 Purge (`app:purge-expired`)

`app:purge-expired` must run **every minute** (Compose `purge` service, systemd timer or cron,
§4.1). It refuses to run when the boot marker is missing or outdated. Each run (idempotent):

- removes expired pastes, consumed pastes older than 10 minutes, incomplete paste directories
  (left by a crash), orphan creations (no matching idempotency record, detected between 15 and
  60 minutes of age, so the purge must run at least every 45 minutes) and staging directories
  older than one hour;
- releases expired read-once reservations;
- removes expired idempotency records and rate limiting entries;
- removes creation markers (`state/creating/`) older than one hour;
- refreshes `health.json`;
- recomputes `usage.json` at most once per hour;
- logs `quota_alert` above 80 % of a quota, and the `storage` metrics line when
  `metrics.enabled` is `true`.

It prints `purge: {"removed":…,"released":…,"orphans":…,"failed":…,"idempotency":…,"ratelimit":…}`
(counts only). `failed` counts items that could not be handled in this run (busy lock,
permission problem, full disk): they are retried by the next run, and a `purge_failures`
warning is logged with the count. A `failed` value that stays above zero run after run points
to a permission or disk problem (§18). Expired pastes are refused by requests as soon as they
expire, even if the purge is late.

### 8.3 Configuration check (`app:config:check`)

`app:config:check` never writes anything and never shows the secret. Use it after `app:boot`,
in monitoring, and in the Compose healthcheck. Text output: the effective configuration as JSON,
then `secret: present (not shown)`, `fingerprint: …`, `boot marker: matches` (or
`missing or outdated, run app:boot`) and a `health:` line.

With `--format=json`, a single JSON document on stdout:

```json
{
    "status": "ready",
    "ready": true,
    "boot_marker": "matches",
    "secret": "present (not shown)",
    "fingerprint": "<hex fingerprint>",
    "health": {
        "age_seconds": 42,
        "free_bytes": 53687091200,
        "free_inodes_percent": 97,
        "creation_allowed": true
    },
    "config": { "app": { "name": "QuietLink", "...": "..." } }
}
```

- `status` is `ready` or `not_ready`; `boot_marker` is `matches` or `missing_or_outdated`.
- `health` is `null` until `app:boot` or the purge has written `health.json`;
  `free_inodes_percent` is `null` when inodes cannot be measured.
- With an invalid configuration the document is `{"status": "invalid", "errors": ["…"]}`.

### 8.4 Exit codes and JSON formats

| Command | `0` | `1` | `2` | JSON (`--format=json`) |
|---|---|---|---|---|
| `app:boot` | ready (with `--dry-run`: would be ready; nothing written) | a blocking check failed | invalid option value (e.g. `--format`) | `{"status": "ok"\|"failed", "dry_run": true\|false, "errors": [], "warnings": []}` |
| `app:config:check` | configuration valid and booted | configuration invalid | configuration valid but not booted (run `app:boot`), or invalid option value | see §8.3 |
| `app:secret:generate` | secret printed or written | output file exists (without `--force`) or cannot be written | — | — |
| `app:purge-expired` | run completed (including `another run is in progress`) | boot marker missing or outdated, missing `purge.lock` | — | — (prints `purge: {…}`) |
| `app:cache:purge` | done | configuration invalid | — | — |

`app:config:check` exits `2` when the instance is not booted, so scripts and healthchecks can
rely on the exit code. JSON documents are written verbatim (messages may contain any character)
and invalid UTF-8 is replaced, so the output always parses. Unknown options or commands
are reported by the Symfony console itself with a non-zero code (`1`). Messages never contain
the secret or stored content.

For scripts, prefer the JSON format and the exit code; for example:

```sh
docker compose exec -T app php bin/console app:boot --dry-run --format=json > /tmp/boot.json; echo "exit $?"
```

The `quietlink` CLI (§13.2) uses `0` for success, `1` for an error (network, server, decryption,
missing PHP extension) and `2` for a usage error (unknown command or option, unexpected
argument), without ever repeating the offending value.

## 9. HTTPS, HSTS and reverse proxy

- Serve the instance **only over HTTPS**; `app.public_url` must be an `https://` origin.
- Every application response carries a strict CSP (`default-src 'none'; script-src 'self';
  worker-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self';
  form-action 'none'; base-uri 'none'; frame-ancestors 'none'; object-src 'none';
  require-trusted-types-for 'script'; trusted-types dompurify quietlink-worker`, plus
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
  Check it after deployment: `curl -sI https://quietlink.example.test/ | grep -i strict-transport`
  must print the header; if not, `http.trusted_proxies` does not match the proxy address.
- The shipped Nginx configuration logs only `emerg` errors (startup and configuration
  failures): entries down to `crit` record the client address and request line. Errors Nginx
  answers itself (400, 404, 405, 408, 413, 414) use the application's `problem+json` body and
  security headers.
- **`http.trusted_proxies`**: list the address(es) of the proxies between the client and PHP-FPM,
  as seen by the application. In the Compose setup PHP-FPM sees the `web` container, which
  itself sees the HTTPS proxy through the Docker bridge: list the Compose network range
  (e.g. `172.16.0.0/12`; check it with `docker network inspect <project>_default`) **and** the
  outer proxy address if it is not on that network. Only `X-Forwarded-For` (client address) and
  `X-Forwarded-Proto` (HTTPS) are read from trusted proxies; `Forwarded`, `X-Forwarded-Host`
  and `X-Forwarded-Port` are always ignored, and the method override header
  (`X-HTTP-Method-Override`) is ignored too. With an empty list behind a proxy, **all clients
  share one rate limiting key** and quickly receive `429` (`app:boot` warns about it). Never
  list ranges that untrusted clients can connect from.
- **CORS** is disabled by default and only concerns `/api/v1` (never pages, assets or
  `/healthz`). For an origin listed in `http.cors_allowed_origins` (exact match), API
  responses carry `Access-Control-Allow-Origin: <origin>`, `Vary: Origin` and
  `Access-Control-Expose-Headers: Retry-After`, and preflight requests are answered `204`
  with the path's methods, `Access-Control-Allow-Headers: Content-Type, Idempotency-Key,
  X-Deletion-Token` and `Access-Control-Max-Age: 600`. Credentials are never allowed. List
  only origins you trust to run API clients; do not add CORS headers in the proxy.
- The proxy must replace incoming `X-Forwarded-*` headers from clients with its own, must not
  log full URLs, request bodies, `X-Deletion-Token` or `Idempotency-Key` headers, and must not
  cache API responses. Share links carry their key in the fragment, which is never sent, but
  paths contain public identifiers: disable or minimise proxy access logs.
- The proxy's request body limit must be at least `http.max_request_bytes` (1408 KiB by
  default), otherwise large pastes fail with `413` at the proxy.
- The Compose `web` service is bound to `127.0.0.1:8080` (override the host port with
  `QUIETLINK_HTTP_PORT`): it speaks plain HTTP and must only be reached through the TLS proxy.

### 9.1 Nginx as the TLS proxy

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

### 9.2 Caddy as the TLS proxy

Caddy obtains the certificate itself, replaces client-supplied `X-Forwarded-For` and sets
`X-Forwarded-Proto` by default, and writes no access log unless a `log` directive is present
(do not add one):

```caddyfile
quietlink.example.test {
    request_body {
        max_size 1408KiB
    }
    reverse_proxy 127.0.0.1:8080
}
```

### 9.3 Apache as the TLS proxy

Requires `mod_ssl`, `mod_proxy`, `mod_proxy_http` and `mod_headers`. `mod_proxy` appends the
client address to `X-Forwarded-For` after the `unset` below:

```apache
<VirtualHost *:443>
    ServerName quietlink.example.test
    SSLEngine on
    SSLCertificateFile    /etc/ssl/quietlink.example.test.pem
    SSLCertificateKeyFile /etc/ssl/quietlink.example.test.key
    LimitRequestBody 1441792
    ProxyPreserveHost On
    RequestHeader unset X-Forwarded-For
    RequestHeader unset Forwarded
    RequestHeader set X-Forwarded-Proto "https"
    ProxyPass        / http://127.0.0.1:8080/
    ProxyPassReverse / http://127.0.0.1:8080/
    # No CustomLog here, and make sure no global CustomLog is inherited by this host.
</VirtualHost>
```

In all three cases, with the Compose setup, `http.trusted_proxies` must contain the Docker
network range (the proxy reaches `web` through the published port, and `web` reaches PHP-FPM on
the Compose network).

## 10. Rate limiting and abuse limits

Rate limiting is file based (`storage.ratelimit_dir`). Client keys are HMACs derived daily from
the instance secret: raw addresses are never stored. IPv4-mapped IPv6 is treated as IPv4; native
IPv6 is grouped by `http.ratelimit_ipv6_prefix`.

| Bucket | Default | Scope |
|---|---|---|
| `create` | 30 per 10 min | client |
| `create_replay` | 120 per 10 min | client, retries of an already-answered creation (same `Idempotency-Key`) |
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
and decoded ciphertext ≤ `paste.max_envelope_bytes` + 16 (`413` beyond), quotas (§7.4),
`max_unconfirmed_opens` for read-once pastes. Uploads and `multipart/form-data` are not
supported.

## 11. Logging policy and retention

- Logs are JSON lines (PSR-3) written to the file `log.file` (default `var/log/quietlink.log`
  in the project, rotated at 5 MB with one archive `quietlink.log.1`, mode 0640), or to
  **stderr** when `log.file` is `null` or the file cannot be written (read-only container).
  With Docker and systemd, set `log.file` to `null`: the container runtime (`docker compose
  logs app purge`) or the journal (`journalctl -u quietlink-fpm`) collects them. On shared
  hosting read the file over SSH: `tail -f var/log/quietlink.log`. Format:
  `{"ts":"…Z","level":"info","message":"request","method":"POST","route":"api_create","status":201,"duration_ms":12,"request_bytes":2048}`.
- Logged fields are restricted to an allowlist: `method`, `route` (route **name**, never the
  path), `status`, `duration_ms`, `request_bytes`, `exception` (class),
  `event`, `count`, `percent`. Messages are sanitized (URLs and long tokens are redacted).
- **Never logged**: IP addresses, user agents, paste identifiers, paths, query strings, headers
  (including `X-Deletion-Token` and `Idempotency-Key`), bodies, fragments, secrets.
- The web server access logs are disabled in the shipped Nginx configuration and in the PHP-FPM
  pool (`access.log = /dev/null`); keep it that way in custom setups. The shipped Nginx error log
  keeps only `emerg` (startup and configuration failures), because lower levels (`crit` and
  below) include the client address and the request line.
- `log.level` controls verbosity (`info` by default; avoid `debug` in production).
- Retention: on stderr QuietLink stores no logs itself; the log file holds at most about 10 MB
  (current file and one archive). Configure your collector to keep them no longer
  than `log.retention` (default **14 days**), e.g. Docker `--log-opt max-size=10m --log-opt max-file=3`
  or the equivalent retention in your log platform.

### 11.1 Operations events

Situations that would otherwise stay invisible are logged as warnings with an `event` field.
Request-time events are logged **at most once a minute per PHP worker**, and none of them
carries an identifier, a path or an address. Alert on them:

| `event` | Level | Emitted by | Meaning and action |
|---|---|---|---|
| `boot_marker_mismatch` | warning | any request | Configuration invalid or different from `boot.json`: every request answers `503`. The message carries the reason (`config_invalid`, `marker_missing`, `fingerprint_differs`, `secret_differs`). Run `app:boot` and reload PHP-FPM (§18). |
| `config_invalid` | warning | any request | One line per configuration error (key and rule, never the value): fix `config/config.php`, then run `app:boot`. |
| `boot_ok` / `boot_warning` / `boot_failed` | info / notice / error (`count`) | `app:boot` | Outcome of each `app:boot`, one line per warning or error. |
| `purge` | info (`count` removed) | purge (cron or web request) | One line per run: shows that the purge really runs; says when a web purge reached its budget. |
| `purge_refused` | error | `app:purge-expired` | The purge refused to run (reason given): run `app:boot`. Cron output is usually discarded, so this is the only trace. |
| `health_stale` | warning | `/healthz` | `health.json` missing or older than `storage.health_max_age`. Check that the purge runs (§8.2). |
| `purge_failures` | warning (`count`) | purge | Items left for the next run. Check storage ownership, modes and free space. |
| `quota_alert` | warning (`percent`) | purge | Storage use above 80 % of `max_total_bytes` or `max_items`. Raise quotas, add space or shorten expirations. |
| `storage` | info (`count`, `percent`) | purge, only with `metrics.enabled` | Aggregated metrics line: number of pastes and quota use. |

Example: `{"ts":"…Z","level":"warning","message":"Disk health measurement is missing or stale: is the purge running?","event":"health_stale"}`.

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
selects the default mode; users can still switch. After a theme change and `app:boot`,
`app:cache:purge` removes the stylesheets no longer referenced.

### 12.2 Languages

`app.enabled_locales` selects the offered languages among those shipped: English (`en`, the
mandatory fallback), Arabic (`ar`, right to left), Spanish (`es`), French (`fr`) and Italian (`it`).
With the default `null`, every shipped language is offered. A language is added by dropping a
catalogue `translations/<code>.json` (same keys as `en.json`, with `_meta.locale`,
`_meta.name` and `_meta.dir` set to `ltr` or `rtl`), rebuilding the frontend (the Docker images
do it) and running `app:boot`; no code change is needed. The browser chooses the language from
the user's explicit choice, then the browser preference, then English.

## 13. Health check and diagnostics

- `GET /healthz` returns `{"status":"ok"}` (`200`), `{"status":"degraded"}` (`503`: health data
  missing or older than `storage.health_max_age`, or inodes low) or `{"status":"unavailable"}` (`503`: invalid
  configuration or boot marker mismatch). It exposes no version or configuration detail and is
  rate limited (`health` bucket).
- `app:config:check` shows the effective configuration, whether the boot marker matches and the
  last disk measurement (§8.3).
- A generic `503` on every page usually means `app:boot` was not rerun after a change (log
  event `boot_marker_mismatch`).
- Errors are logged without identifiers; reproduce problems with dummy content only.

### 13.1 Post-deployment verification

**Local smoke test (Docker).** From a checkout, `tools/docker/smoke.sh` builds the images and
runs a throwaway Compose project (`quietlink-smoke`, its own configuration, secret and volumes;
your `config/` and `secrets/` are never touched). It checks the image users (`10001`, `101`,
`10002`) and the absence of any database service, runs `app:boot --dry-run`, starts the stack
with `up --wait` (healthchecks), checks `app:config:check` (`ready`), `/healthz` (`200`), the
CSP header and the read-only root filesystem, creates, reads and deletes a paste of dummy text
with the CLI image, runs a purge (`"failed":0`), then removes everything. The port defaults to
`18096` (`QUIETLINK_SMOKE_PORT`). Run it before deploying a new version.

**Manual checklist on the deployed instance** (replace the host by yours; dummy text only):

1. Readiness: `docker compose exec -T app php bin/console app:config:check --format=json`
   (or `sudo -u quietlink env APP_ENV=prod QUIETLINK_CONFIG_DIR=/srv/quietlink/config QUIETLINK_APP_SECRET_FILE=/etc/quietlink/app_secret php -c /etc/quietlink/php.ini /srv/quietlink/bin/console app:config:check --format=json`):
   exit `0`, `"status": "ready"`, `health.creation_allowed` `true`.
2. Health: `curl -s https://quietlink.example.test/healthz` prints `{"status":"ok"}`.
3. Headers: `curl -sI https://quietlink.example.test/` shows `content-security-policy` with
   `script-src 'self'`, `strict-transport-security`, `referrer-policy: no-referrer`,
   `x-content-type-options: nosniff`, and no `set-cookie` or `x-powered-by`.
4. HTTP is redirected or refused by the proxy: `curl -sI http://quietlink.example.test/`.
5. CLI round trip with dummy text (from any machine with the CLI, §13.2):

   ```sh
   printf 'post-deployment dummy text' \
     | quietlink create --server https://quietlink.example.test --expires 5m \
       > share-link.txt 2> manage-link.txt
   quietlink decrypt --url-stdin < share-link.txt              # prints the dummy text
   grep /manage/ manage-link.txt | quietlink delete --url-stdin --yes
   rm share-link.txt manage-link.txt
   ```

   `create` prints the share link on stdout and the management link (with its warning and the
   expiry time) on stderr. Remove the files afterwards: they contain working links.
6. Logs: `docker compose logs --since 10m app purge` shows request lines without paths or
   addresses, `purge: {…}` lines every minute and no `boot_marker_mismatch` or `health_stale`.
7. Browser: open the home page, create and read a dummy paste, check that the browser console
   shows no CSP violation.

### 13.2 Command line client notes

- Links are read only from stdin (`--url-stdin`), never as arguments; passphrases come from the
  terminal, `--passphrase-file` or (for `create` only) `--passphrase-stdin`, never from the command line or the
  environment. If the terminal cannot hide the passphrase (`stty` unavailable), the CLI refuses
  to prompt and points to `--passphrase-file`.
- `delete` without a terminal (scripts, pipes) requires `--yes`.
- `--server` accepts IPv6 literals, e.g. `http://[::1]:8080` for a local test instance.
- Output files (`decrypt -o`) are created with mode 0600 from the first instant and must not
  exist yet.
- The PHAR needs `allow_url_fopen` enabled; otherwise it says so (`php -d allow_url_fopen=1`).
- In the CLI Docker image the working directory `/app` is read-only for the CLI user: mount a
  writable directory for `decrypt -o`, for example
  `docker run --rm -i -v "$PWD/out:/out" ghcr.io/marouane-hassine/quietlink-cli@sha256:<digest> decrypt --url-stdin -o /out/file.txt < share-link.txt`
  (the `out/` directory must be writable by uid `10002`). Use `docker run -it` for
  interactive passphrase prompts.

## 14. Docker and PHP-FPM hardening

The shipped images already apply: non-root users (`10001` app, `101` web, `10002` CLI),
read-only root filesystem, `cap_drop: ALL`, `no-new-privileges`, small `/tmp` tmpfs (16 MiB for
`app` and `purge`, 128 MiB for `web`), prewarmed read-only Symfony cache, `expose_php = Off`,
`display_errors = Off`, `file_uploads = Off`, `allow_url_fopen = Off`, `post_max_size = 2M`,
`zend.exception_ignore_args = On`, OPcache without timestamp validation, `clear_env = yes`.
Keep the data volume mounted with `nodev,nosuid,noexec` where possible, deploy published images
by digest (base images are pinned by digest in the Dockerfiles), rebuild or upgrade regularly
for security updates, and never mount the Docker socket.

### 14.1 Operating with a read-only root filesystem

- Writable paths: the data volume (`/app/datas`), the generated assets volume
  (`/app/var/generated`, also mounted on `/var/lib/quietlink-generated`; read-only for `web`), and the tmpfs mounts (`/tmp`; for
  `web` also `/var/cache/nginx`). Everything else, including `/app` and `/app/var/cache`, is
  read-only.
- The Symfony container is compiled at image build time (`cache:warmup`); it is never rebuilt at
  runtime. `cache:clear`, `cache:warmup` and other commands writing to `var/` fail by design:
  change the configuration (read at runtime, not compiled), run `app:boot`, and rebuild the
  image only for a new release.
- Configuration and the secret are bind-mounted read-only; edit them on the host, then
  recreate `app` and `purge`.
- `web` spools request bodies above its buffer and large responses to `/tmp`; the 128 MiB tmpfs
  leaves room for many concurrent maximum-size requests. If you raise `paste.max_envelope_bytes`
  a lot, raise that tmpfs too.
- Without Docker, the systemd units of §4.1 apply the same idea with `ProtectSystem=strict`
  and `ReadWritePaths=`.

## 15. Upgrade and rollback

Breaking changes (`!` in the commit history, `BREAKING CHANGE` notes in the changelog) concern
the storage format, the encrypted format, the AAD or `/api/v1`. Storage files are versioned with
`schema_version`; readers **fail closed** on an unknown version (uniform `404`, never a guess).
A format change ships with an explicit migration. Rolling back to an older release is safe only
if no newer `schema_version` has been written since the upgrade; otherwise restore the
pre-upgrade backup. The frontend assets are hashed, so mixed old/new pages do not collide in
caches.

### 15.1 With Docker

1. Read the changelog of every version between yours and the target.
2. Record what runs now, to be able to roll back:

   ```sh
   docker compose images                                   # current images
   docker inspect --format '{{index .RepoDigests 0}}' "$(docker compose images -q app)"
   ```

   With local builds, tag the current images first, e.g.
   `docker tag quietlink/app:dev quietlink/app:previous` (same for `web`).
3. Back up the data volume and the configuration (§7.5).
4. Verify the new image digests (§15.3) and put them in `compose.override.yaml` (§3.3), or
   check out the new tag and `docker compose build`.
5. Validate the current configuration with the new image without writing anything (any new
   key has a default; removed keys must be removed from your files, unknown keys are errors):

   ```sh
   docker compose run --rm --no-deps -T app php bin/console app:boot --dry-run
   ```

6. Deploy: `docker compose up -d --wait` (the entrypoint runs `app:boot`; the `app`
   healthcheck waits for `app:config:check` to succeed).
7. Verify (§13.1), then `docker compose exec -T app php bin/console app:cache:purge`.

Rollback: put the previous digests back in `compose.override.yaml` (or retag
`quietlink/app:previous` as `quietlink/app:dev`), then `docker compose up -d --wait`. If the new
release wrote a newer `schema_version` (the changelog says so), restore the pre-upgrade backup
instead (§7.5).

### 15.2 Without Docker

The upgrade below happens in place in the git checkout of §4 (your `config.php`, `config.local.php`
and the contents of `datas/` are ignored by git and stay where they are). It needs a short
downtime.

1. Read the changelog; back up `datas/` and the configuration (§7.5).
2. Note the current version, to be able to roll back: `git -C /srv/quietlink describe --tags`.
3. Stop the instance: `sudo systemctl stop quietlink-purge.timer quietlink-fpm`.
4. Fetch, verify and check out the new tag, then rebuild (as root, §4 step 1):

   ```sh
   cd /srv/quietlink
   git fetch --tags
   git checkout vX.Y.Z
   # Release tags are not GPG-signed: check that the tag is the commit the signed release was
   # built from (its provenance attestation names the commit; requires gh and jq).
   gh release download vX.Y.Z --repo marouane-hassine/quietlink -p quietlink.phar -D /tmp/ql-verify
   gh attestation verify /tmp/ql-verify/quietlink.phar --repo marouane-hassine/quietlink \
     --signer-workflow marouane-hassine/quietlink/.github/workflows/release.yml \
     --source-ref refs/tags/vX.Y.Z --format json \
     | jq -r '.[0].verificationResult.statement.predicate.buildDefinition.resolvedDependencies[0].digest.gitCommit'
   git rev-parse HEAD   # must print the same commit
   composer install --no-dev --classmap-authoritative
   npm ci && npm run build
   APP_ENV=prod php bin/console cache:warmup --no-debug
   ```

5. Validate the current configuration with the new code without writing anything (any new key
   has a default; removed keys must be removed from your files, unknown keys are errors):

   ```sh
   sudo -u quietlink env APP_ENV=prod QUIETLINK_CONFIG_DIR=/srv/quietlink/config \
     QUIETLINK_APP_SECRET_FILE=/etc/quietlink/app_secret \
     php -c /etc/quietlink/php.ini /srv/quietlink/bin/console app:boot --dry-run
   ```

6. Start: `sudo systemctl start quietlink-fpm quietlink-purge.timer` (`ExecStartPre` runs
   `app:boot`; a start, not a reload, so that OPcache only holds the new code).
7. Verify (§13.1); remove the `var/cache/prod/<version>/` (or `<version>-<commit>/`) directories of older versions and run
   `app:cache:purge`.

Rollback: stop both units, `git checkout <previous tag>`, rebuild as in step 4, start again,
provided no newer `schema_version` was written; otherwise restore the pre-upgrade backup.

### 15.3 Verifying release artefacts

Verify release artefacts (signatures and checksums published with each release) before
deploying, and prefer image digests over tags. Signatures are keyless (Sigstore, GitHub OIDC),
so the identity to check is the release workflow of the official repository:

```sh
id='^https://github.com/marouane-hassine/quietlink/\.github/workflows/release\.yml@refs/tags/v'
issuer=https://token.actions.githubusercontent.com
# Images (repeat for quietlink-web and quietlink-cli); use the digest printed in the release notes.
cosign verify --certificate-identity-regexp "$id" --certificate-oidc-issuer "$issuer" \
  ghcr.io/marouane-hassine/quietlink-app@sha256:<digest>
# SBOM attestation, stored in the classic cosign format. Command for cosign 3.x; with cosign
# 2.x remove --new-bundle-format=false (older 2.x releases reject that flag).
cosign verify-attestation --type spdxjson --new-bundle-format=false --certificate-identity-regexp "$id" \
  --certificate-oidc-issuer "$issuer" ghcr.io/marouane-hassine/quietlink-app@sha256:<digest>
# PHAR and frontend hashes, downloaded from the GitHub release.
for f in quietlink.phar frontend-sha256sums.txt; do
  cosign verify-blob --bundle "$f.sigstore.json" --certificate-identity-regexp "$id" \
    --certificate-oidc-issuer "$issuer" "$f"
done
sha256sum -c quietlink.phar.sha256
# SLSA provenance of the PHAR and of each image (built by the release workflow from the tag).
gh attestation verify quietlink.phar --repo marouane-hassine/quietlink
gh attestation verify oci://ghcr.io/marouane-hassine/quietlink-app@sha256:<digest> \
  --repo marouane-hassine/quietlink
# Served assets (installation without Docker): compare with the published list.
(cd public/build && find . -type f -exec sha256sum {} + | sort) | diff - frontend-sha256sums.txt
```

With Docker, the assets are inside the web image; compare the image you deploy (by digest)
with the published list:

```sh
docker run --rm --entrypoint sh ghcr.io/marouane-hassine/quietlink-web@sha256:<digest> \
  -c 'cd /usr/share/quietlink/public/build && find . -type f -exec sha256sum {} + | sort' \
  | diff - frontend-sha256sums.txt
```

The list includes `.vite/manifest.json`, which is used at build time and is not served over
HTTP (Nginx refuses hidden paths); to check what a running instance actually serves,
download each listed file under `/build/` and compare its hash.

## 16. Security incident procedure

Follow these steps in order. Keep a timestamped log of what you do; never copy secrets, links or
content into it.

1. **Isolate.** Take the instance offline at the proxy (or `docker compose stop web`, or stop the
   web server), so that no further JavaScript is served and no further links are opened.
2. **Preserve.** Stop `app` and `purge` (Docker: `docker compose stop app purge`; systemd:
   `systemctl stop quietlink-purge.timer quietlink-fpm`) so the purge does not erase evidence.
   Snapshot or copy the data volume, the configuration, the deployed image digests or code
   directory, and the collected logs, to read-only storage.
3. **Rotate the secret** (§6.2). Also rotate the TLS key if the host was compromised.
4. **Rebuild from verified sources.** Deploy images by verified digest (§15.3) or rebuild the
   code from a verified signed tag on a clean host; never reuse a possibly modified image or
   code tree. Check `public/build/` against the published hash list.
5. **Remove specific pastes if required** (legal request, abuse). There is no removal command
   and no moderation interface: the operator cannot decrypt content. The safe manual way:
   1. obtain the paste identifier, which is the part after `/p/` (or `/manage/`) in the link,
      **without** the `#…` fragment (never ask for or store the fragment);
   2. stop `app` and `purge` first, so that no request or purge holds the paste lock;
   3. delete the directory `pastes/<first 2 characters>/<next 2 characters>/<identifier>/` in
      the data directory, for example with Docker:
      `docker compose run --rm --no-deps --entrypoint rm app -rf /app/datas/pastes/<s1>/<s2>/<id>`
      or without Docker: `sudo -u quietlink rm -rf /srv/quietlink/datas/pastes/<s1>/<s2>/<id>`;
   4. start the instance again (`app:boot` runs automatically); the quota counters are
      corrected by the hourly recomputation of the purge.

   Remember that copies may remain in backups and snapshots.
6. **Restore service** once the cause is understood and fixed; run the post-deployment
   verification (§13.1).
7. **Communicate.** Inform users that links created or opened during the exposure window must
   be considered compromised (a compromised server can have served malicious JavaScript, §17),
   and that they should ask senders for new links and change any credential shared that way.
   Report vulnerabilities in QuietLink itself as described in `SECURITY.md`; never include
   secrets, keys, passphrases, full links or user content.

Other incidents:

- Disk full / quota reached: creation is refused automatically; free space, check that the purge
  runs, adjust quotas (§7.4).
- Abuse: tighten `http.rate_limits`, block at the proxy, check `http.trusted_proxies` (§9).

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

## 18. Troubleshooting

| Symptom or message | Cause | Fix |
|---|---|---|
| `/healthz` answers `{"status":"unavailable"}` | The web side finds the configuration invalid (often: the secret does not reach PHP, `config_invalid`) or different from the last `app:boot` (`fingerprint_differs`: configuration changed, or only `--dry-run` was run; `marker_missing`). | Run `app:boot` (not only `--dry-run`) after every change; `app:config:check` shows `reason`; on shared hosting see §4.3. |
| `storage.… is on an unsupported filesystem (nfs)` (error) | `storage.allow_unsupported_fs = false` on a network filesystem. | Use a local ext4/XFS disk, or accept the risks of §4.3 with `true` (the default). |
| Creation refused ("temporarily unavailable") | Not enough free disk space (`storage.min_free_bytes`), quota reached, or the last inode measurement below `storage.min_free_inodes_percent`. | Free space, raise the quotas, or wait for the purge; `app:config:check` shows the health block. |
| Valid links answer `404` for a few seconds, or creation is refused, right after a clock change | The server clock stepped backwards (manual change, VM resume, NTP step) or jumped forwards. | Keep NTP slewing (no steps); the condition clears by itself within a minute. |
| `QUIETLINK_APP_SECRET_FILE does not point to a readable file.` | Secret file missing, or not readable by the PHP account (e.g. `root:root 0600`). | Docker: `sudo chgrp 10001 secrets/app_secret && chmod 640 secrets/app_secret`. Without Docker: `chgrp quietlink` + `chmod 640` (§6.1). |
| `QUIETLINK_APP_SECRET (or QUIETLINK_APP_SECRET_FILE) is required…` / `…must be standard base64 decoding to at least 32 bytes.` | Variable not set for this process, or file content wrong (empty, truncated). | Pass the variable (pool, unit, Compose); regenerate the secret (§6). |
| `config/config.php is missing; copy config/config.php.example.`, and `config/config.php` is a **directory** on the host | Compose was started before `config.php` existed and created a directory at its place. | `docker compose down`, `rmdir config/config.php`, then follow §3.1 step 1 and 4, and start again. |
| `Unknown configuration key "…"` / `"…" has an invalid type.` | Typo, removed key after an upgrade, or wrong type (e.g. `'60'` instead of `60`). | Fix `config.php`; check with `app:boot --dry-run`. |
| `storage.… is on an unsupported filesystem (overlay)` (or `nfs`, `fuseblk`, …) | Data not on a supported local filesystem; with Docker, usually the data volume is not mounted where `storage.data_dir` points. | Mount a volume on `/app/datas` (default) or point `storage.data_dir` at a mounted ext4 or XFS path. Or accept it with `storage.allow_unsupported_fs = true` (the default; locking guarantees lost, §4.3). |
| `storage.… does not support atomic rename() and link().` | Network or FUSE mount, or read-only volume. | Use a local filesystem; make the data volume writable. |
| `storage.… is accessible to other accounts (mode 0755): restore mode 0700 (chmod 700).` | Directory created by hand, restored, or copied with a permissive mode. | `chmod 700` on the four storage directories (Docker: `docker compose run --rm --no-deps --entrypoint chmod app 700 /app/datas/pastes /app/datas/idempotency /app/datas/ratelimit /app/datas/state`). |
| `storage.… must belong to the application account.` | Files restored or created by another user (often root). | `chown -R quietlink:quietlink` the data directory (Docker: `10001:10001`, from a container run with `--user 0`). |
| `storage.… cannot be created.` | Parent directory missing or not writable by the account. | Create the parent as in §4 step 3. |
| `The PHP-FPM pool does not pass QUIETLINK_APP_SECRET(_FILE) to the workers (this instance uses …).` | The pool file named by `QUIETLINK_FPM_POOL_FILE` does not pass the variable this instance uses: `QUIETLINK_APP_SECRET_FILE` with a secret file, `QUIETLINK_APP_SECRET` with an inline secret. Without it the workers would answer `503` while boot succeeded. | Add the matching `env[…]` line (§4.1); the shipped pool passes `QUIETLINK_APP_SECRET_FILE` only. |
| Every page and API call answers `503`; `/healthz` says `unavailable`; log event `boot_marker_mismatch` | Configuration changed (or invalid) since the last `app:boot`, or the secret changed. | Run `app:boot` and reload PHP-FPM (Docker: `docker compose exec app quietlink-reload`; systemd: `systemctl reload quietlink-fpm`). `app:config:check` shows the errors. |
| `/healthz` answers `503` `degraded`; log event `health_stale` | `health.json` older than `storage.health_max_age` (default `2h`): the purge is not running (or failing, or scheduled less often than that), or inodes are low. | `docker compose ps purge` / `docker compose logs purge`, or `systemctl list-timers quietlink-purge.timer` / `journalctl -u quietlink-purge`. Check `df -i` (§7.4). |
| Purge prints `The boot marker is missing or does not match the configuration; run app:boot first.` | Purge started before `app:boot`, or configuration changed. | Run `app:boot` (it is in the `app` entrypoint and the systemd `ExecStartPre`). |
| `purge.lock is missing; run app:boot.` | Data directory restored without lock files, or a lock file removed. | Run `app:boot` (it recreates missing lock files). |
| Purge output shows `"failed":N` with N > 0 on every run; log event `purge_failures` | Permission problem or full disk on some paste directories. | Check ownership and modes (§7.6) and free space. |
| `413` on large pastes | A body limit below `http.max_request_bytes`: the TLS proxy (`client_max_body_size`, `max_size`, `LimitRequestBody`) or the `web` Nginx. | Raise the proxy limit to at least `http.max_request_bytes` (1408 KiB by default). |
| `400` on large pastes; `app:boot` warned `PHP post_max_size (…) is below http.max_request_bytes` | PHP discards bodies above `post_max_size`. | Raise `post_max_size` (Docker image: 2M) together with the web server limits. |
| Many `429` responses behind a proxy; `app:boot` warned `http.trusted_proxies is empty…` | All clients share one rate limiting key. | List the proxy and Docker network ranges in `http.trusted_proxies` (§9). |
| No `Strict-Transport-Security` header | Proxy address not in `http.trusted_proxies`, or no `X-Forwarded-Proto: https`. | Fix the proxy headers and `trusted_proxies` (§9). |
| `Free inodes are below storage.min_free_inodes_percent: creation is refused.` | Inode exhaustion of the data volume. | `df -i`; check the purge runs; grow the volume or reformat with more inodes; lower `paste.idempotency_max_ttl`. |
| `Free inodes could not be measured…` | `df` missing or the filesystem does not report inodes. | Install `df` (coreutils/busybox) for PHP, or accept that the inode threshold is not enforced. |
| `Free disk space is below storage.min_free_bytes: creation is refused.` | Data volume almost full. | Free space or grow the volume; check the purge runs. |
| CLI: `Cannot hide the passphrase while typing (stty failed)…` | No usable terminal (e.g. `docker run -i` without `-t`). | Use `docker run -it`, or `--passphrase-file` (`--passphrase-stdin` with `create` only). |
| CLI: `PHP allow_url_fopen is disabled…` | `allow_url_fopen = Off` in the PHP used by the CLI. | `php -d allow_url_fopen=1 quietlink.phar …`. |
| CLI Docker: `decrypt -o` fails to write | `/app` is read-only for the CLI user. | Mount a writable directory and write into it (§13.2). |
| `curl: (52) Empty reply` or `502` from the proxy right after `up` | `app` not healthy yet, or failed to boot. | `docker compose ps`; `docker compose logs app` shows the `app:boot` errors. |

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
