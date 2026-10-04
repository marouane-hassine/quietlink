# ADR-0003: Theme variable format

- Status: Accepted
- Date: 2026-10-03
- Spec reference: `docs/cahier-des-charges.md` §19.2

## Context

Custom themes and tokens (§6.5) are a Should item. The CSP forbids `unsafe-inline` (§7.5) and no third-party resource may be loaded.

## Decision

- Themes are expressed as semantic CSS custom properties prefixed `--ql-*` (colors, radii, spacing, typography scale).
- A custom theme is a JSON file validated against an allowlist of token names and value types (color, length, number). Values such as `url()`, `@import`, `expression` or any free-form CSS are rejected.
- The validated theme is compiled into a static, hashed CSS asset served by the instance; nothing is injected inline.
- The exact token list is defined during the theme work package and documented in English.

## Consequences

- Custom themes cannot exfiltrate data or weaken the CSP.
- Administrators cannot supply arbitrary CSS; advanced customisation requires a code change.
