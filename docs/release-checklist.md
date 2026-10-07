# V1 release checklist

This list goes from a green `develop` to a signed `v1.0.0`. It follows the acceptance criteria of
the specification (§16.4) and the product owner decisions (ADR-0008). Tick every item in the
release pull request (`develop` → `main`). Each item must have evidence: a CI run, a report or a
signed-off review.

## 1. Automated gates (CI on the release commit)

- [ ] `CI` workflow green on the release commit: `php` (`composer validate`, `composer audit`,
      `composer qa`), `frontend` (`npm run qa` with the bundle budget, `npm audit`, requirement
      coverage check, shared vectors check, reproducible frontend build), `security`
      (forbidden patterns, no database service, gitleaks on the full history), `phar`
      (two identical builds and a smoke test) and `docker` (images, non-root users, Compose
      smoke test up to `/healthz` `200`).
- [ ] The release workflow repeats `composer qa`, `npm run qa`, the coverage and vectors checks
      on the tagged commit, and publishes nothing if the tag differs from `v` + `Version::APP`
      or the commit is not on `main`.
- [ ] Local Docker validation green on the release commit: `tools/docker/qa.sh all` (PHP,
      frontend and pattern checks with the production PHP and Node versions) and
      `tools/docker/smoke.sh` (images, users, no database, `app:boot --dry-run`, healthchecks,
      headers, read-only root, CLI round trip with dummy text, purge).
- [ ] Playwright campaign green on the five projects in Docker (`tools/docker/e2e.sh`, run
      locally: not part of CI), twice in a row with no flaky retry.
- [ ] `npm run coverage:requirements` regenerated; every Must requirement is either cited by a
      green automated test or has its manual evidence listed in section 2 (§16.4, "all Must
      requirements covered").
- [ ] No `TODO`/`FIXME` left in `src/`, `frontend/src/` or `bin/` that concerns a Must
      requirement.

## 2. Manual evidence (Phase 3)

- [ ] Capacity on the reference machine, results recorded in the release notes
      (README-admin, "Capacity benchmarks"):
      `npm run bench:purge -- 100000` (`EXG-PERF-011`) and `npm run bench:load` on a
      PHP-FPM + Nginx stack (`EXG-PERF-009`, `EXG-PERF-010`).
- [ ] Argon2id calibration on the device matrix (`npm run calibration`); defaults
      `m = 64 MiB, t = 3` kept or changed by a new ADR (ADR-0008 decision 6).
- [ ] Real-device browser matrix (§16.2): create, multi-read, read-once, passphrase, delete,
      including a browser without native Ed25519.
- [ ] WCAG 2.2 AA audit of the main journeys (keyboard, screen reader, contrast, zoom 200 %,
      reduced motion).
- [ ] Phase 3 user tests done and their blocking issues fixed (§16.4).
- [ ] Formal internal security review of `src/Crypto`, `frontend/src/crypto`, storage, headers,
      CSP and logging, with ADR-0007 confirmed (§16.4).
- [ ] Targeted external audit of the protocol and its implementation done, before the first
      public version (§7.5). Findings fixed or accepted in writing.
- [ ] Native speaker review of the `ar`, `es` and `it` catalogues, including the Arabic
      right-to-left layout on the main screens (ADR-0010).
- [ ] Native speaker review of the French word list built from Lexique
      (`tools/wordlists/build-fr.mjs`; offensive or confusing words go to
      `tools/wordlists/fr-exclude.txt`, then rebuild).

## 3. Documentation

- [ ] `CHANGELOG.md`: `Unreleased` section renamed to the version, with the date.
- [ ] `docs/README-admin.md` and `docs/README-developer.md` followed from scratch on a clean
      machine: Docker installation, installation without Docker (systemd units), backup and
      restore test, developer setup and test run (§16.4, reproducible installations;
      `EXG-OPS-004`).
- [ ] `SECURITY.md` contact channel live before the first public release (§17).
- [ ] `docs/openapi.yaml`, `docs/protocol/sp-proto-v1.md` and `docs/storage-format.md` match
      the code (no change since the external audit, or the change was re-reviewed).

## 4. Release

1. In the release pull request, bump `Version::APP` in `src/Version.php` to the release version
   without suffix (for example `1.0.0`) and rename the `CHANGELOG.md` `Unreleased` section
   (section 3). A pre-release uses a suffix (`1.0.0-beta.2`, tag `v1.0.0-beta.2`): the workflow
   publishes it as a GitHub pre-release, never marked as the latest release, and the items of
   sections 1–3 still open are listed in its CHANGELOG entry. The release workflow fails unless the tag is `v` + `Version::APP`.
2. Open the pull request `develop` → `main` with this checklist; merge only when every box is
   ticked. Never push to `main` directly.
3. Repository settings (once): protect `main` (required CI checks, no direct push) and add a
   tag ruleset for `v*` so that only maintainers can create, move or delete release tags. The
   release workflow also refuses a tag whose commit is not on `main`.
4. Tag the merge commit on `main`: `git tag -s v1.0.0 -m "v1.0.0"` when a signing key is
   configured (otherwise `git tag -a`; administrators then verify the tag against the release's
   provenance, README-admin §4) and push the tag. The tag triggers
   `.github/workflows/release.yml`.
5. Check the workflow output: three signed linux/amd64 images on GHCR (`quietlink-app`,
   `quietlink-web`, `quietlink-cli`, tag `v1.0.0` only) with SBOM and SLSA provenance
   attestations, the signed PHAR with its provenance, the signed frontend hash list, and the
   image digests in the release notes.
6. Run the verification procedure of README-admin ("Upgrade and rollback") against the
   published artefacts from a machine that did not build them, including a rebuild of the PHAR
   from the tag with `tools/release/build-phar.sh` and the same SHA-256 (README-developer,
   "Reproducible PHAR").
7. For installations without Docker, build the archive from the tagged commit with
   `tools/release/build-archive.sh vX.Y.Z X.Y.Z` and attach it with its `.sha256`.
8. Deploy a staging instance by digest: first run `app:boot --dry-run` on the target with its
   real configuration (no error), then start it, check `app:config:check --format=json`
   (exit `0`, `ready`), `/healthz`, one create/read/delete journey with dummy text, and the
   response headers (CSP, HSTS, `Referrer-Policy`, no third-party request), following
   README-admin "Post-deployment verification".
8. Merge `main` back into `develop` if the release pull request added commits, then set
   `Version::APP` on `develop` to the next development version (for example `1.1.0-dev`).
