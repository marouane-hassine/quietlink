# QuietLink developer guide

This guide lets a developer understand, test and modify QuietLink without implicit knowledge.
Operators should read `docs/README-admin.md`; the reference specification is
`docs/cahier-des-charges.md` (French). When the code and the specification disagree, raise the
point instead of deciding alone.

---

## 1. Requirements

| Tool | Version |
|---|---|
| PHP | ≥ 8.3 with `openssl`, `sodium`, `intl`, `hash`, `json`, `mbstring` |
| Composer | 2 |
| Symfony | 7.x components in micro-kernel mode (installed by Composer) |
| Node.js | ≥ 24.7 (frontend build, Vitest, vector generator using `crypto.argon2Sync`) |
| TypeScript / Vite / Vitest | pinned in `package.json` |

No database is needed or allowed: persistence is local files only.

## 2. Local setup

```sh
composer install
npm ci
npm run build                      # writes public/build/ (hashed assets + manifest)
```

Create a local configuration **without real secrets**, outside the repository:

```sh
mkdir -p /tmp/ql-dev/config/themes
cat > /tmp/ql-dev/config/config.php <<'PHP'
<?php
declare(strict_types=1);
return [
    'app' => ['public_url' => 'http://localhost:8080'],
    'storage' => [
        'root_dir' => '/tmp/ql-dev/data/pastes',
        'idempotency_dir' => '/tmp/ql-dev/data/idempotency',
        'ratelimit_dir' => '/tmp/ql-dev/data/ratelimit',
        'state_dir' => '/tmp/ql-dev/data/state',
        'generated_assets_dir' => '/tmp/ql-dev/generated',
        'allow_unsupported_fs' => true,   // development only (tmpfs, APFS, ...)
    ],
];
PHP
export QUIETLINK_CONFIG_DIR=/tmp/ql-dev/config
export QUIETLINK_APP_SECRET="$(php bin/console app:secret:generate)"
export APP_ENV=dev
php bin/console app:boot
QUIETLINK_GENERATED_DIR=/tmp/ql-dev/generated php -S localhost:8080 -t public tools/dev/router.php
```

`tools/dev/router.php` emulates the production web server for `php -S`: it serves `public/build/`
(with the dedicated CSP of the Argon2id worker) and the generated theme CSS. Run
`php bin/console app:purge-expired` periodically if you keep the server running for more than 10
minutes (creation is refused when `health.json` is stale). Rerun `app:boot` after every
configuration change.

`npm run dev` rebuilds the frontend on change (`vite build --watch --mode development`); there is
no dev server with inline scripts, so the CSP is the same as in production.

## 3. Repository layout

```text
bin/console            Symfony console (server commands app:*)
bin/quietlink          standalone CLI client
config/                PHP-only configuration (no YAML, no .env): services.php, routes.php,
                       config.php.example, config.local.php.example, themes/
docs/                  specification, protocol, storage format, schemas, ADRs, READMEs
frontend/src/          TypeScript application (no UI framework)
frontend/tests/        Vitest tests
public/                index.php front controller, build/ (generated assets)
src/                   PHP sources, namespace QuietLink\ (PSR-4)
templates/             Twig page template (HTML shell only)
tests/                 PHPUnit tests, tests/vectors/ shared protocol vectors
tools/                 vector generator, word list builder, dev router
translations/          en.json, fr.json (shared by server templates and frontend)
docker/, compose.yaml  container images and demonstration deployment
```

### 3.1 PHP (`src/`)

| Directory | Responsibility |
|---|---|
| `Kernel.php` | Symfony `MicroKernelTrait` kernel. |
| `Config/` | `ConfigLoader` (defaults, merge of `config.php` + `config.local.php`, strict validation), typed settings objects, `AppSecret`, `Duration`. |
| `Crypto/` | `sp-proto/v1` primitives: HKDF derivations (`KeyDerivation`), AES-256-GCM (`ContentCipher`, ext-openssl), Ed25519 and Argon2id (ext-sodium), AAD canonicalisation (`Aad`), identifiers, challenges, access proofs, deletion token verifiers. No primitive is reimplemented. |
| `Encoding/` | Strict base64url. |
| `Storage/` | File store: `AtomicFile` (temp + `rename()`/`link()`), `FileLock` (`flock()`), `FilesystemPasteStore`, `IdempotencyStore`, `UsageCounter`, `StateFiles` (`usage.json`, `health.json`, `boot.json`), record codecs. |
| `Paste/` | Domain service `PasteService`: creation with idempotency, status/open/consume state machine, read-once reservations, deletion. |
| `RateLimit/` | File-based rate limiter keyed by HMAC of the client address. |
| `Http/` | Client address normalisation, request body parsing, problem responses. |
| `Controller/` | `ApiController` (`/api/v1/pastes…`), `PageController` (`/`, `/p/{id}`, `/manage/{id}`, `/how-it-works`), `HealthController` (`/healthz`). |
| `EventSubscriber/` | Runtime guard (boot marker, trusted proxies), security headers/CSP, exception mapping to uniform problems, request log. |
| `Log/` | `JsonLogger`: PSR-3 JSON lines on stderr with a context allowlist and sanitisation. |
| `Maintenance/` | `Booter` (app:boot checks), `Purger`, `DiskProbe`, `ThemeBuilder` interface. |
| `Theme/` | `TokenThemeBuilder`: validates theme tokens, compiles hashed CSS. |
| `Command/` | `app:boot`, `app:config:check`, `app:secret:generate`, `app:purge-expired`. |
| `Runtime/` | `Environment` (APP_ENV/APP_DEBUG, never debug in prod), `RuntimeStatus` (config + boot marker), `ServiceFactory`. |
| `Web/` | Vite manifest reader (`Assets`), translation catalogs (`Catalogs`). |
| `Client/`, `Cli/` | CLI client: client-side crypto (`ClientCrypto`), API client, share link parsing, passphrase input, commands. Independent from the server kernel. |

### 3.2 Frontend (`frontend/src/`)

| Path | Responsibility |
|---|---|
| `main.ts`, `config.ts` | Entry point; reads the page settings JSON embedded by the server (no inline script). |
| `crypto/` | Web Crypto (HKDF, AES-GCM, Ed25519), `@noble/ed25519` fallback, AAD, envelope, protocol flows, Argon2id via `hash-wasm` **only inside `argon2.worker.ts`**. |
| `api.ts` | `/api/v1` client. |
| `pages/` | create, read, manage, how-it-works pages. |
| `render/` | Markdown (markdown-it with `html: false` + DOMPurify), syntax highlighting. |
| `ui/` | DOM helpers, clipboard, countdown, QR code (SVG built through the DOM), passphrase generator, live announcer. |
| `templates.ts` | Markdown templates (`ui.templates`). |
| `i18n.ts` | Catalog loading and locale selection. |
| `wordlists/` | Generated passphrase word list (`tools/wordlists/build-en.mjs`, do not edit). |
| `styles/app.css` | Styles using `--ql-*` tokens. |

The key never leaves the browser: it is generated locally, placed in the URL fragment, and only
derived public values (access public key, proofs, deletion verifier) are sent.

## 4. Protocol, API and storage formats

- Protocol `sp-proto/v1`: `docs/protocol/sp-proto-v1.md` (primitives, HKDF contexts, AAD byte
  layout, URL format `/p/<id>#<key>` and `/manage/<id>#<deletion token>`, size limits).
  Context strings use `sp-proto/v1`, independent of the product name.
- API field names: ADR-0007 (snake_case). Errors are uniform: a missing, expired, consumed paste
  or an invalid proof is always `404`.
- Storage format, locks, state machine and purge: `docs/storage-format.md`; JSON Schemas in
  `docs/schemas/`; versioning rules in ADR-0002.

## 5. Test-driven development (mandatory)

Every change follows **Red → Green → Refactor**:

1. **Red**: write a failing test that expresses the requirement (reference the requirement
   identifier `EXG-<domain>-<n>` in the test name or docblock).
2. **Green**: write the minimum production code to make it pass.
3. **Refactor**: clean up with the suite green.

Rules:

- No production code without a test written first.
- Tests never use real secrets or personal data: fixed dummy values only.
- Cryptographic code is developed from the shared test vectors (§6).
- Must items come before Should, Should before Could (§0.3 of the specification).
- `docs/traceability.md` maps requirements to tests; keep it up to date.

## 6. Commands

```sh
composer test            # PHPUnit (tests/)
composer stan            # PHPStan level max, strict rules, phpunit extension
composer cs              # PHP-CS-Fixer (PSR-12) check only
composer cs-fix          # PHP-CS-Fixer fix
composer qa              # cs + stan + test: must be green before every commit
composer vectors:check   # committed vectors match the independent generator
composer vectors         # regenerate tests/vectors/sp-proto-v1.json

npm run typecheck        # tsc --noEmit
npm test                 # Vitest (frontend/tests/)
npm run build            # Vite production build into public/build/
npm run qa               # typecheck + test + build: must be green before every commit
```

CI runs the same commands (`composer qa`, `composer vectors:check`, `npm run qa`). End-to-end
tests use Playwright (ADR-0001: Chromium, Firefox, WebKit, mobile emulation).

## 7. Test vectors

`tests/vectors/sp-proto-v1.json` is the single source of truth shared by the PHP backend
(`tests/Crypto/SpProtoV1VectorsTest.php`), the CLI and the frontend
(`frontend/tests/vectors.test.ts`).

- It is produced by `tools/vectors/generate-sp-proto-v1.mjs`, which uses only `node:crypto`, so
  it is independent from the three implementations it checks. Inputs are fixed dummy values;
  output is byte-for-byte reproducible.
- Never edit the JSON by hand. Change the generator, run `composer vectors`, then
  `composer vectors:check`, `composer test` and `npm test`.

### Protocol changes

Any change to the cryptographic format (primitives, HKDF contexts, AAD, envelope, URL format,
proofs) **requires**:

1. new test vectors (and, for an incompatible change, a new protocol version/context string,
   never a silent change of `sp-proto/v1`);
2. updated `docs/protocol/sp-proto-v1.md` (or a new protocol document);
3. a security review before merging;
4. a breaking-change commit (`!` / `BREAKING CHANGE:`).

The same applies to incompatible changes of the storage format (new `schema_version`, migration
with a fixture per old version) and of `/api/v1`.

## 8. Security rules for code

- The server never receives or stores the plaintext, the fragment key, the passphrase or the raw
  deletion token.
- Never put a secret, payload, full URL, fragment, paste identifier, `X-Deletion-Token` or
  `Idempotency-Key` in logs, caches, metrics, exception messages or page titles. Log only through
  the PSR-3 logger with allowlisted context keys (`JsonLogger::ALLOWED_CONTEXT`); internal
  exception messages must not contain user input.
- No silent cryptographic downgrade: if a primitive is missing, disable the option.
- PHP crypto: `ext-openssl` for AES-256-GCM, `ext-sodium` for Argon2id and Ed25519, `hash_hkdf`,
  `random_bytes`. Never `sodium_crypto_aead_aes256gcm_*`.
- No third-party resources (CDN, fonts, analytics); CSP without `unsafe-inline`/`unsafe-eval`.
  Pass data to the frontend through the embedded settings JSON, never inline scripts or styles.
- No upload, file field or `multipart/form-data`; no backoffice or administration endpoint.
- Storage writes go through `AtomicFile` and `FileLock`; paths are built only from validated
  `PasteId` values.

## 9. Coding conventions

- Every PHP file starts with `// SPDX-License-Identifier: AGPL-3.0-or-later` and
  `declare(strict_types=1);`; TypeScript files carry the SPDX header too.
- PSR-4 (`QuietLink\` → `src/`, `QuietLink\Tests\` → `tests/`), PSR-12 (enforced by
  PHP-CS-Fixer), PSR-3 logging. Classes `final` by default, readonly properties where possible.
- PHPStan level max must pass without baseline additions.
- Code comments, docblocks, exception messages, READMEs, OpenAPI and protocol documents are in
  English. The specification stays in French.
- User-facing text goes through the translation catalogs, never hard-coded.
- New Symfony components or npm/Composer dependencies need a justification.

## 10. Commit conventions

Commits follow [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):

```text
<type>[(scope)][!]: <description>

[body]

[footers]
```

- Types: `feat`, `fix`, `docs`, `test`, `refactor`, `perf`, `build`, `ci`, `chore`, `style`,
  `revert`.
- Scopes: `api`, `cli`, `crypto`, `storage`, `frontend`, `ui`, `i18n`, `theme`, `config`,
  `docker`, `security`, `spec`.
- Description in the imperative, lower case, no final period, ≤ 72 characters
  (e.g. `feat(crypto): derive access key from url key`).
- Breaking changes: `!` and/or a `BREAKING CHANGE:` footer. Any incompatible change of the
  encrypted format, the AAD, `/api/v1` or the storage format is breaking.
- Footers use `Token: value`, e.g. `Refs: EXG-SEC-012`.
- One commit, one intent. Never include secrets, share links or personal data.
- Commit messages and pull request descriptions contain only functional content: no
  `Co-authored-by` line or any other tool attribution.
- Work on `develop` (or a branch from `develop` merged back into it); never commit directly to
  `main`.

## 11. Translations

Catalogs live in `translations/<locale>.json` and are shared by the Twig page (through
`QuietLink\Web\Catalogs`) and the frontend (imported at build time by `frontend/src/i18n.ts`).
Each catalog has a `_meta` object (`locale`, `name`, `dir`) and flat dotted keys
(`"footer.how": "How it works"`). Placeholders use `{name}` and are replaced as text, never as
HTML. English is the reference and the fallback for missing keys.

### Adding a language

1. Copy `translations/en.json` to `translations/<code>.json` (two-letter code), translate every
   value and fill `_meta` (`dir` is `ltr`; V1 supports left-to-right languages only).
2. Import it in `frontend/src/i18n.ts` and add it to `CATALOGS`.
3. Add the code to `ConfigLoader::AVAILABLE_LOCALES`.
4. Write the tests first: catalog key parity with English (frontend) and configuration
   acceptance (PHPUnit).
5. Optionally provide a passphrase word list in `frontend/src/wordlists/`.
6. Run `composer qa` and `npm run qa`, then document the language in `docs/README-admin.md`.

## 12. Themes and Markdown templates

- Themes: CSS custom properties `--ql-*` in `frontend/src/styles/app.css`; operator overrides are
  compiled by `TokenThemeBuilder` from an allowlist (ADR-0003). To make a new token customisable,
  add it to the allowlist with its value type and range, test it in
  `tests/Theme/TokenThemeBuilderTest.php`, and document it in `docs/README-admin.md`.
- Markdown templates: defined in `frontend/src/templates.ts` with their texts in the catalogs;
  a new template must also be added to `ConfigLoader::TEMPLATES` so it can be enabled in
  `ui.templates`.

## 13. Adding an endpoint or changing storage

- Endpoint: write the API test first (`tests/Http/ApiTest.php`), add the route attribute in a
  controller, map errors through domain exceptions (uniform `404` for unavailable content), add
  a rate limit bucket if needed (`ConfigLoader::RATE_LIMIT_BUCKETS` and defaults), log nothing
  identifying, update the OpenAPI specification.
- Storage: update `docs/storage-format.md` and the JSON Schema, bump `schema_version` for an
  incompatible change, write the migration with fixtures, keep readers failing closed.

## 14. Frontend build

`npm run build` runs Vite with `frontend/` as root and writes hashed assets and
`.vite/manifest.json` to `public/build/` (base `/build/`, no source maps, no inline assets, ES
module workers). The server reads the manifest (`QuietLink\Web\Assets`) to emit `<script>` and
`<link>` tags. The Argon2id worker is a separate asset that must be served with
`script-src 'self' 'wasm-unsafe-eval'` (see `docker/nginx/default.conf`). The Docker images build
the assets in a dedicated Node stage.

## 15. CLI usage

`bin/quietlink` (also packaged as the `docker/cli` image) encrypts and decrypts locally. Links
are always read from **stdin** so they stay out of the shell history and the process list;
passphrases are never accepted on the command line or in the environment (`--passphrase=…` and
`QUIETLINK_PASSPHRASE` are refused).

```sh
# Create (text from stdin); prints the share link on stdout and the management link on stderr
printf 'dummy text\n' | bin/quietlink create --server https://quietlink.example.test --expires 1h --read-once

# Text from a file, passphrase asked on the terminal (twice)
bin/quietlink create --server https://quietlink.example.test --input notes.txt --passphrase

# Passphrase from an owner-only file (chmod 600, or under /run/secrets/)
bin/quietlink create --input notes.txt --passphrase-file ./pass.txt --format markdown

# Metadata without decrypting or reserving
bin/quietlink metadata --url-stdin < share-link.txt

# Decrypt (a read-once paste asks for confirmation; --yes skips it)
bin/quietlink decrypt --url-stdin -o out.txt < share-link.txt

# Delete with the management link
bin/quietlink delete --url-stdin --yes < manage-link.txt

# Docker (local build; releases publish ghcr.io/marouane-hassine/quietlink-cli:vX.Y.Z)
docker build -f docker/cli/Dockerfile -t quietlink/cli .
docker run --rm -i quietlink/cli metadata --url-stdin < share-link.txt
```

Options: `create` accepts `--server` (or `QUIETLINK_SERVER`), `--expires` (`5m`, `1h`, `1d`,
`7d`, `30d`, `never`; default `1d`), `--read-once`, `--format` (`plain`, `markdown`, `code`),
`--language` (with `--format code`), `--passphrase`, `--passphrase-file`, `--passphrase-stdin`
(requires `--input`), `--input`. `decrypt` accepts `--url-stdin` (required),
`--passphrase-file`, `-o/--output` (new file, mode 600), `-y/--yes`. The text must be UTF-8; the
1 MiB limit applies to the serialized envelope (sp-proto §4), JSON escaping included, and is
checked before any prompt or request. Usage errors (unknown command or option, unexpected
argument) exit with code `2` and never repeat the offending value, which may be a link or a
passphrase typed by mistake.

## 16. Reviews and releases

- Every pull request targets `develop`, passes `composer qa`, `composer vectors:check` and
  `npm run qa`, and is reviewed. Changes touching `src/Crypto`, `frontend/src/crypto`, the
  storage format, headers/CSP or logging require a security-focused review.
- Dependency audits (`composer audit`, `npm audit`), SBOM generation, reproducible builds and
  release signing are part of the release procedure (`docs/release-checklist.md`); image base
  layers and GitHub Actions are pinned (see below).
- Security issues are reported privately (`SECURITY.md`).

### Updating pinned dependencies

Base images and GitHub Actions are pinned so that a build is reproducible and a moved tag
cannot change what CI runs (`EXG-DEPLOY-006`).

- **Base images** (`docker/*/Dockerfile`) use `FROM image:tag@sha256:<digest>`, where the digest
  is the **multi-arch index** digest (not the digest of one platform). To update one, resolve
  the current digest and replace it in every Dockerfile using that image:

  ```sh
  docker buildx imagetools inspect php:8.3-fpm-alpine --format '{{json .Manifest.Digest}}'
  ```

  Images in use: `composer:2`, `node:24-alpine`, `php:8.3-fpm-alpine`, `php:8.3-cli-alpine`,
  `nginxinc/nginx-unprivileged:1.29-alpine`. Rebuild and run the `docker` CI job (images and
  Compose smoke test) before merging; update at least monthly for security fixes.
- **GitHub Actions** (`.github/workflows/*.yml`) use the full commit SHA with the tag in a
  trailing comment (`uses: owner/repo@<sha> # vX.Y.Z`). Resolve a tag with
  `git ls-remote https://github.com/<owner>/<repo> refs/tags/<tag> 'refs/tags/<tag>^{}'`; for
  an annotated tag, use the peeled `^{}` SHA (the commit), not the tag object.
- **Box** (PHAR builder) is pinned in the workflows (`tools: box:4.7.0`); changing its version
  changes the PHAR bytes, so do it in a dedicated commit.
- Renovate or Dependabot may automate these updates (both understand digest and SHA pins); they
  are optional and not configured in the repository.

### Reproducible PHAR

`box.json` sets a fixed `alias`; the release workflow adds the commit date of the tagged commit
as Box `timestamp`, so two builds of the same commit with Box 4.7.0 give the same SHA-256 (the
`phar` CI job builds twice and compares). To reproduce a published PHAR from a checkout of the
tag:

```sh
composer install --no-dev --classmap-authoritative --no-interaction
jq --arg ts "$(git log -1 --format=%cI)" '.timestamp = $ts' box.json > box.release.json
box compile --config=box.release.json --no-interaction   # Box 4.7.0, phar.readonly=0
sha256sum -c quietlink.phar.sha256
```
