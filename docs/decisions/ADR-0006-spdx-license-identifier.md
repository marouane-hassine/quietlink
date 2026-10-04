# ADR-0006: SPDX license identifier

- Status: Accepted
- Date: 2026-10-03
- Spec reference: `docs/cahier-des-charges.md` §19.2

## Context

The license is AGPL-3.0 (§19.1); the SPDX variant had to be chosen.

## Decision

Use `AGPL-3.0-or-later` in `composer.json`, `package.json` and every source file header (`SPDX-License-Identifier: AGPL-3.0-or-later`).

## Consequences

- Follows the FSF recommendation and allows adopting a future AGPL version without relicensing consent from every contributor.
