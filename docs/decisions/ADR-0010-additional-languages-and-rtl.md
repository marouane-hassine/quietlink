# ADR-0010: Additional languages and right-to-left support

- Status: Accepted
- Date: 2026-10-04

## Context

The product owner asked for Arabic, Italian and Spanish in addition to English and French.
§6.6.1 requires a multilingual V1, English as fallback, French provided, new languages added by a
catalogue without code change, and compatibility with left-to-right (LTR) languages. Arabic is
written right to left (RTL), which goes beyond the V1 requirement without contradicting it
(catalogues must declare their direction).

## Decision

1. **Catalogues are discovered**, not listed in code: `QuietLink\Web\Catalogs::available()` returns
   every valid `translations/<code>.json` (`_meta.locale` matching the file name, `_meta.dir` =
   `ltr` or `rtl`), English first. All of them are enabled by default; `app.enabled_locales`
   restricts the list. The frontend loads catalogues other than `en` and `fr` on demand
   (`import.meta.glob`), so adding a file needs no code change and does not grow the initial
   bundle.
2. **RTL is supported**: the document gets `dir` from the catalogue (server page, error pages,
   language switch); CSS already uses logical properties (enforced by tests), the select arrow is
   mirrored, user content uses `dir="auto"`, links and code stay LTR.
3. **Shipped languages**: `en`, `fr`, `es`, `it`, `ar`. The `es`, `it` and `ar` catalogues were
   produced by machine translation and checked for key and placeholder parity; **a native speaker
   review is required before the public release** (release checklist). Passphrase generation uses
   the English EFF list for every language until per-language lists exist (as for French,
   ADR-0008).
4. Template field labels in every shipped language are recognised (sensitive fields masked,
   Wi-Fi QR code), so a template written in one language reads correctly in another.

## Consequences

- The template module loads every catalogue (to recognise field labels): about 60 KB more,
  fetched as separate chunks with the creation and reading pages — the creation page being the
  home page, they are part of the first visit, not of the initial bundle (budget-checked).
- A catalogue that cannot be fetched (offline, stale page after a redeploy) never blocks the
  page: the stored choice falls back to English, and a language switch keeps the current
  language with a message.
- Plural forms are not supported by the catalogue format; messages avoid them ("(n
  unconfirmed opening(s))") — a plural mechanism may be added later.
