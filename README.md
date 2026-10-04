# QuietLink

Self-hostable sharing of confidential text, encrypted in the browser.

QuietLink encrypts text on the sender's device with a random key placed after the `#` of the
link, a part browsers never send to servers. The server stores only ciphertext and minimal
technical metadata; it cannot read the content. Optional read-once mode, passphrase
(Argon2id), expiration, deletion link, Markdown and code highlighting, templates, QR code,
English and French interfaces, light and dark themes.

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
local files only. See [docs/README-admin.md](docs/README-admin.md).

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

## Documentation

- [Administrator guide](docs/README-admin.md) — installation, configuration, backups, upgrades
- [Developer guide](docs/README-developer.md) — architecture, TDD, tests, conventions
- [Protocol `sp-proto/v1`](docs/protocol/sp-proto-v1.md) and [test vectors](tests/vectors/sp-proto-v1.json)
- [Storage format](docs/storage-format.md) and [JSON schemas](docs/schemas/)
- [API (OpenAPI 3.1)](docs/openapi.yaml)
- [Security policy](SECURITY.md) · [Code of conduct](CODE_OF_CONDUCT.md) · [Changelog](CHANGELOG.md)

## License

[AGPL-3.0-or-later](LICENSE). The English passphrase word list is the EFF Large Wordlist
(CC BY 3.0 US).
