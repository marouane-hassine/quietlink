# ADR-0009: Secret fields, ambiguous decryption errors and the key fragment

- Status: Accepted
- Date: 2026-10-04

## Context

Security and accessibility reviews before the external audit raised three points the specification
leaves open or that needed a product decision.

## Decision

1. **Secret fields use real password inputs.** Passphrase, confirmation, reading passphrase and
   sensitive template fields are `<input type="password">` (text only while the user chose
   "Show"). The former CSS masking (`-webkit-text-security`) hid the value visually but left it
   readable by screen readers (§5.1, §6.6). Browsers may offer to save the value despite
   `autocomplete="off"`; this is accepted as the lesser risk.
2. **Ambiguous decryption errors say so.** For passphrase-protected multi-read content there is no
   local passphrase check (no `consume_pk`): an AES-GCM failure cannot tell a wrong passphrase
   from altered content. The reader shows "Incorrect passphrase, or the content was altered"
   (`error.wrongPassphraseOrAltered`). A read-once wrong passphrase is still detected locally
   against `consume_pk` before any request and keeps the plain "Incorrect passphrase" message. A
   decrypted plaintext that is not a valid envelope is reported as an integrity error. This
   answers protocol OQ-27 and the explicit-error requirement of §8.4.
3. **The key fragment is removed once it has no further use.** `history.replaceState` removes
   `#…` from the address bar and the current history entry after a successful deletion on
   `/manage`, once a read-once paste is destroyed, and when the content is unavailable. Multi-read
   links keep their fragment so they can be reloaded or read again; a read-once paste keeps it
   until destruction so a reload can resume the reservation (§6.3.1).

## Consequences

- Earlier history entries and screenshots taken before removal still contain the key: the
  limitation stays documented in the threat model.
- Tests: `frontend/tests/passphrase-fields.test.ts`, `read-page.test.ts` (combined message,
  fragment removal), `manage-page.test.ts`.
