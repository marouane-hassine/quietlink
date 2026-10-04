# QuietLink

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/brand/logo-dark.svg">
    <img src="docs/brand/logo.svg" alt="QuietLink" width="320">
  </picture>
</p>

Self-hostable sharing of confidential text, encrypted in the browser.

QuietLink encrypts text on the sender's device with a random key placed after the `#` of the
link, a part browsers never send to servers. The server stores only ciphertext and minimal
technical metadata; it cannot read the content. The built-in **How it works** page explains, in
every language, what the server receives (ciphertext, expiry, read-once flag, passphrase use,
encrypted size, a keyed hash of the client address) and what it never receives (plaintext, link
key, passphrase, deletion token).

- Read-once mode, optional passphrase (Argon2id), expiration from 5 minutes to 30 days, deletion
  link.
- Plain text, Markdown with local preview, code with syntax highlighting; templates (credentials,
  API token, Wi-Fi with QR code, SSH key, database, environment variables, temporary access,
  incident) edited as forms.
- Share link QR code, copy with a ready-made message.
- Interface in English, French, Spanish, Italian and Arabic (right to left); more languages by
  adding a catalogue in `translations/`, no code change.
- System, light and dark themes; keyboard and screen reader accessible (WCAG 2.2 AA target).
- No database, no account, no tracking, no third-party resource; strict CSP and Trusted Types.
- Logo and favicon as SVG coloured by the theme tokens, with light and dark variants
  ([docs/brand/](docs/brand/)).

> QuietLink makes verifiable promises — local encryption, no plaintext on the server,
> authenticated integrity, minimal logs — not absolute security. A compromised server can
> serve modified JavaScript and capture keys at decryption time: use an instance you trust.

## Quick start (Docker)

```sh
cp config/config.php.example config/config.php       # set app.public_url
mkdir -p secrets
docker compose build
docker compose run --rm --no-deps app php bin/console app:secret:generate > secrets/app_secret
sudo chgrp 10001 secrets/app_secret config/config.php   # containers run as uid/gid 10001
chmod 640 secrets/app_secret config/config.php
docker compose up -d
```

Then put an HTTPS reverse proxy in front of `127.0.0.1:8080`. No database is used: storage is
local files only, in `datas/` at the project root by default (`storage.data_dir` in
`config/config.php`; with Docker, the `data` volume mounted on `/app/datas`). The empty
`datas/` directory is part of the repository; whatever is stored in it is git-ignored. See
[docs/README-admin.md](docs/README-admin.md).

## Configuration at a glance

All settings live in `config/config.php` (copied from `config/config.php.example`, validated by
`app:boot`). The most common ones:

| Key | Default | Purpose |
|---|---|---|
| `app.public_url` | — (required) | Public `https://` origin of the instance |
| `app.enabled_locales` | every shipped language | Languages offered (`en` mandatory) |
| `storage.data_dir` | `datas` | Data directory, relative to the project root or absolute; refused inside `public/` (symbolic links and letter case included) or at the project root |
| `paste.default_expiration` | `1d` | Expiration preselected in the form |
| `ui.dark_mode` | `auto` | Default theme (`auto`, `light`, `dark`) |
| `http.cors_allowed_origins` | `[]` | Optional CORS for `/api/v1` (exact origins) |

The full reference is in [docs/README-admin.md](docs/README-admin.md) §5.

## Command line client

```sh
printf 'dummy text' | quietlink create --server https://paste.example.test --expires 1h
printf '%s' "$LINK" | quietlink metadata --url-stdin
printf '%s' "$LINK" | quietlink decrypt --url-stdin
```

Available as a signed PHAR attached to each GitHub release (requires PHP 8.3 with intl,
sodium, openssl, mbstring) or the `ghcr.io/marouane-hassine/quietlink-cli:vX.Y.Z` Docker image
(linux/amd64; prefer the digest listed in the release notes; `docker run -it` for interactive
passphrase prompts; build it locally with `docker build -f docker/cli/Dockerfile -t quietlink/cli .`).
Published server images (immutable `vX.Y.Z` tags, deploy by digest) and their verification are
described in [docs/README-admin.md](docs/README-admin.md) (§3 "Published images", §15).

## Development

```sh
composer install && npm ci
composer qa        # PHP: coding style, PHPStan, PHPUnit
npm run qa         # frontend: typecheck, Vitest, build, bundle budget, coverage report
npm run e2e        # Playwright end-to-end tests (npx playwright install first)
```

Test-driven development, Conventional Commits and work on `develop`: see
[docs/README-developer.md](docs/README-developer.md) and [CONTRIBUTING.md](CONTRIBUTING.md).

## Documentation

- [Administrator guide](docs/README-admin.md) — installation, configuration, backups, upgrades
- [Developer guide](docs/README-developer.md) — architecture, TDD, tests, conventions
- [Protocol `sp-proto/v1`](docs/protocol/sp-proto-v1.md) and [test vectors](tests/vectors/sp-proto-v1.json)
- [Storage format](docs/storage-format.md) and [JSON schemas](docs/schemas/)
- [API (OpenAPI 3.1)](docs/openapi.yaml)
- [Architecture decisions](docs/decisions/) · [Threat model](docs/threat-model.md) ·
  [Release checklist](docs/release-checklist.md)
- [Security policy](SECURITY.md) · [Code of conduct](CODE_OF_CONDUCT.md) · [Changelog](CHANGELOG.md)

## License

[AGPL-3.0-or-later](LICENSE). The English passphrase word list is the EFF Large Wordlist
(CC BY 3.0 US).
