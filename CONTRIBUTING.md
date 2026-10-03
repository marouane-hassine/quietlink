# Contributing to QuietLink

Thank you for helping. Please read [SECURITY.md](SECURITY.md) first: never report a
vulnerability in a public issue.

## Ground rules

- Development happens on `develop` or on branches created from it; `main` only receives
  reviewed merges.
- Test-driven development is mandatory (Red → Green → Refactor). Write the failing test first
  and reference the requirement identifier (`EXG-<domain>-<n>`, see
  [docs/traceability.md](docs/traceability.md)) in the test.
- Any change of the cryptographic format, the AAD, the API `/api/v1` or the storage format
  requires new test vectors, a security review and is a breaking change.
- Tests never use real secrets or personal data; use dummy values and `example.test` domains.
- The server must never receive plaintext, the link key, the passphrase or the raw deletion
  token; nothing sensitive may reach logs, caches, metrics or error messages.
- User-facing texts go through `translations/*.json`; code comments and technical
  documentation are in English.

## Checks

```sh
composer install && npm ci
composer qa          # PHP-CS-Fixer (PSR-12), PHPStan level max, PHPUnit
npm run qa           # TypeScript, Vitest, Vite build
composer vectors:check
tools/ci/forbidden-patterns.sh
```

## Commits

[Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/): imperative,
lower-case description of at most 72 characters, one intention per commit, requirement
identifiers in footers (`Refs: EXG-SEC-012`). Never include secrets, share links or personal
data in a commit message.
