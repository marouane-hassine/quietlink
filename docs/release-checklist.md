# V1 release checklist

This list goes from a green `develop` to a signed `v1.0.0`. It follows the acceptance criteria of
the specification (§16.4) and the product owner decisions (ADR-0008). Tick every item in the
release pull request (`develop` → `main`). Each item must have evidence: a CI run, a report or a
signed-off review.

## 1. Automated gates (CI on the release commit)

- [ ] `CI` workflow green on the release commit: `php` (`composer qa`, `composer audit`,
      vectors), `frontend` (`npm run qa` with the bundle budget, `npm audit`), `security`
      (gitleaks on the full history), `phar` (build and smoke test) and `docker` (images and
      compose smoke test).
- [ ] Playwright campaign green on the five projects (`npm run e2e`, run locally: not part of
      CI), twice in a row with no flaky retry.
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
- [ ] French word list built from Lexique replaces the interim EFF list for `fr`
      (ADR-0008 decision 5), or the interim list is accepted for V1 in writing.

## 3. Documentation

- [ ] `CHANGELOG.md`: `Unreleased` section renamed to the version, with the date.
- [ ] `docs/README-admin.md` and `docs/README-developer.md` followed from scratch on a clean
      machine: Docker installation, installation without Docker, developer setup and test run
      (§16.4, reproducible installations).
- [ ] `SECURITY.md` contact channel live before the first public release (§17).
- [ ] `docs/openapi.yaml`, `docs/protocol/sp-proto-v1.md` and `docs/storage-format.md` match
      the code (no change since the external audit, or the change was re-reviewed).

## 4. Release

1. Open the pull request `develop` → `main` with this checklist; merge only when every box is
   ticked. Never push to `main` directly.
2. Tag the merge commit on `main`: `git tag -s v1.0.0 -m "v1.0.0"` and push the tag. The tag
   triggers `.github/workflows/release.yml`.
3. Check the workflow output: three signed images on GHCR (`quietlink-app`, `quietlink-web`,
   `quietlink-cli`) with SBOM attestations, the signed PHAR, the signed frontend hash list, and
   SLSA provenance.
4. Run the verification procedure of README-admin ("Upgrade and rollback") against the
   published artefacts from a machine that did not build them.
5. Deploy a staging instance by digest, run `/healthz`, one create/read/delete journey with
   dummy text, and check the response headers (CSP, HSTS, `Referrer-Policy`, no third-party
   request).
6. Merge `main` back into `develop` if the release pull request added commits.
