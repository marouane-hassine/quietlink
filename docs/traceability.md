# QuietLink — Requirements traceability matrix

Source: `docs/cahier-des-charges.md` (v0.18). Generated in Phase 0.

## Purpose

This matrix assigns a stable identifier `EXG-<DOMAIN>-<n>` to every normative requirement of the QuietLink specification (§5 to §17) and links each one to at least one planned acceptance test, as required by §0.2 and §16.0. Identifiers are numbered sequentially per domain in document order and must never be renumbered or reused: a withdrawn requirement keeps its identifier and is marked as withdrawn. Tests reference these identifiers (for example in a test name, docblock or `@group` annotation), and the V1 release gate (§16.4) requires every Must row to be covered by green tests.

Decisions already recorded in §19.1 are applied: Playwright for E2E tests, JSON storage files with `schema_version`, `--ql-*` CSS theme tokens, local secret detection deferred to V1.1, minimal JSON logging to stderr, and `AGPL-3.0-or-later` licensing.

## Legend

- **ID**: stable requirement identifier. Domains: GEN (general), UX, A11Y (accessibility), I18N, THEME, MD (Markdown and templates), LIFE (expiry, deletion, burn after reading), READ (read protocol, §6.3.1), CRYPTO (§8), URL, SEC (§7, §11, HTTP headers, CSP), STORE (§9.4, §9.7), CONF (§9.5), CACHE (§9.6), API (§10), CLI, OBS (§14 logging), PERF (§13), DEPLOY (§15), OPS (§15.1 operations tooling), TEST (§16), DOC (§17), PWA.
- **§**: section of the specification where the requirement is stated.
- **Summary**: short English paraphrase of the requirement. The specification is the reference text.
- **Priority**: V1 priority from §0.3. **Must** blocks the release; **Should** blocks the release unless a written waiver exists; **Could** does not block and may be deferred to V1.1. Requirements not listed in §0.3 inherit the priority of their feature area (security, crypto, storage, API, CLI, i18n, a11y, Docker and docs are Must).
- **Level**: normative level from §0.1. **MUST** = « doit », « obligatoire », « interdit »; **SHOULD** = « devrait », « recommandé »; **MAY** = « peut », « optionnel ». Lists of user capabilities (« l’utilisateur peut… ») describe features the product must provide and are recorded as MUST. A MUST rule inside a Could feature applies only if that feature ships.
- **Planned test**: test type and short target. *Shared vectors* means the common frontend/backend/CLI test vectors. Types: PHPUnit unit, PHPUnit integration, Vitest, Playwright E2E, CI check, Manual review.

## Matrix

### 5. Product principles and UX rules (§5)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-GEN-001 | 5 | Server receives only the minimum data necessary (privacy by design) | Must | MUST | Manual review: data flow audit |
| EXG-GEN-002 | 5 | Dangerous settings are never defaults (secure by default) | Must | MUST | PHPUnit unit: config defaults |
| EXG-GEN-003 | 5 | Creating and reading content requires no account | Must | MUST | Playwright E2E: anonymous create/read |
| EXG-UX-001 | 5 | Main flows usable one-handed on mobile (mobile first) | Must | MUST | Playwright E2E: mobile viewport |
| EXG-DOC-001 | 5 | Threats, limits and crypto operation explained clearly | Must | MUST | Manual review: docs and UI copy |
| EXG-DEPLOY-001 | 5 | Instance installable and backed up by a competent person | Must | MUST | Manual review: install/backup procedure |
| EXG-GEN-004 | 5 | Instance options managed via config, env, Docker, CLI; no backoffice | Must | MUST | Manual review: route inventory |
| EXG-CRYPTO-001 | 5 | Content never sent when encryption fails (fail safe) | Must | MUST | Vitest: encryption failure blocks request |
| EXG-API-001 | 5 | API and CLI remain simple, stable and documented | Must | MUST | CI check: OpenAPI contract tests |
| EXG-TEST-001 | 5 | Every feature specified by tests before implementation | Must | MUST | Manual review: commit history (TDD) |
| EXG-UX-002 | 5.1 | UI makes clear what to do, what is sent, what is protected | Must | MUST | Manual review: usability review |
| EXG-UX-003 | 5.1 | Home screen shows the editor directly | Must | MUST | Playwright E2E: home shows editor |
| EXG-UX-004 | 5.1 | No signup, login or setup required to create content | Must | MUST | Playwright E2E: create without account |
| EXG-UX-005 | 5.1 | Single primary action highlighted per screen | Must | MUST | Manual review: UI review |
| EXG-UX-006 | 5.1 | Secondary actions visible but discreet | Must | MUST | Manual review: UI review |
| EXG-UX-007 | 5.1 | Create, read, delete flows reachable in few steps | Must | MUST | Playwright E2E: step count per flow |
| EXG-UX-008 | 5.1 | No mandatory onboarding tunnel | Must | MUST | Playwright E2E: first visit flow |
| EXG-UX-009 | 5.1 | No empty screen without explanation or possible action | Must | MUST | Manual review: UI states |
| EXG-UX-010 | 5.1 | No dark patterns, ads or account incentives | Must | MUST | Manual review: UI review |
| EXG-UX-011 | 5.1 | Editor usable immediately after load | Must | MUST | Playwright E2E: editor ready on load |
| EXG-UX-012 | 5.1 | V1 accepts only typed or pasted text, Markdown, code | Must | MUST | Playwright E2E: text-only input |
| EXG-SEC-001 | 5.1 | No file can be attached, uploaded or sent to server | Must | MUST | Playwright E2E: no file input |
| EXG-SEC-002 | 5.1 | No multipart form, file field or upload endpoint in V1 | Must | MUST | PHPUnit integration: multipart rejected; CI check |
| EXG-UX-013 | 5.1 | File drag-and-drop blocked with text-only message | Must | MUST | Playwright E2E: drop file blocked |
| EXG-UX-014 | 5.1 | Cursor placed in editor when not hindering mobile keyboard | Must | MUST | Playwright E2E: autofocus desktop |
| EXG-SEC-003 | 5.1 | Editor and passphrase fields disable spellcheck/autocorrect/autocapitalize/autocomplete | Must | MUST | Vitest: input attributes |
| EXG-SEC-004 | 5.1 | Passphrase field masked via CSS text-security, fallback type=password | Must | MUST | Vitest: CSS.supports branch |
| EXG-A11Y-001 | 5.1 | Masked field has aria-describedby; screen readers not voicing value | Must | MUST | Manual review: screen reader test |
| EXG-DOC-002 | 5.1 | Password-manager save prompt residual limit documented | Must | MUST | Manual review: user docs |
| EXG-UX-015 | 5.1 | Input font size at least 16 px (no iOS zoom) | Must | MUST | Playwright E2E: computed font-size |
| EXG-UX-016 | 5.1 | Pasting text preserves line breaks | Must | MUST | Playwright E2E: paste multiline |
| EXG-UX-017 | 5.1 | Paste-from-clipboard button may appear in empty editor | Should | MAY | Playwright E2E: empty editor button |
| EXG-SEC-005 | 5.1 | clipboard.readText only after explicit action, secure context, permission | Must | MUST | Vitest: clipboard gated by user action |
| EXG-SEC-006 | 5.1 | Clipboard never auto-read, sent, stored locally or logged | Must | MUST | Vitest: no clipboard read on load |
| EXG-UX-018 | 5.1 | Clipboard API unavailable/denied keeps manual paste, non-blocking message | Must | MUST | Vitest: clipboard fallback |
| EXG-UX-019 | 5.1 | Selected format permanently visible | Should | MUST | Playwright E2E: format indicator |
| EXG-UX-020 | 5.1 | Advanced options grouped without hiding primary action | Must | MUST | Manual review: UI review |
| EXG-UX-021 | 5.1 | Editor clearly indicates empty content | Must | MUST | Playwright E2E: empty state |
| EXG-UX-022 | 5.1 | Size limits displayed before publishing | Must | MUST | Playwright E2E: limit shown |
| EXG-UX-023 | 5.1 | Size computed locally on serialised envelope bytes, equals server limit | Must | MUST | Vitest: envelope size equals server check |
| EXG-UX-024 | 5.1 | Size gauge appears near limit, with textual value, never sole indicator | Must | MUST | Vitest: gauge thresholds and text |
| EXG-A11Y-002 | 5.1 | Gauge uses documented thresholds, colours, accessible label, limit announcement | Must | MUST | Vitest + Manual review: gauge a11y |
| EXG-SEC-007 | 5.1 | Plaintext drafts never auto-saved in V1 | Must | MUST | Vitest: no storage writes |
| EXG-UX-025 | 5.1 | Leaving unpublished input shows warning when browser allows | Must | MUST | Playwright E2E: beforeunload warning |
| EXG-SEC-008 | 5.1 | Leave warning never writes draft to storage, cookie, URL or external service | Must | MUST | Vitest: storage spies empty |
| EXG-UX-026 | 5.1 | Leave warning active only for non-empty unpublished content | Must | MUST | Vitest: beforeunload conditional |
| EXG-UX-027 | 5.1 | Active settings summary line above primary action, editable in one action | Should | MUST | Playwright E2E: settings summary line |
| EXG-UX-028 | 5.1 | Presets Secret and Share, clamped; Secret hidden if burn disabled | Should | MUST | Vitest: preset clamping |
| EXG-UX-029 | 5.1 | Local pre-encryption validation; primary action disabled with reason, no request | Must | MUST | Vitest: validation blocks submit |
| EXG-UX-030 | 5.1 | Suggest Secret preset for sensitive template or detected secret pattern (deferred V1.1) | Could | MAY | Vitest: secret pattern suggestion |
| EXG-UX-031 | 5.1 | Format and language may be suggested locally without silent change | Should | MAY | Vitest: format detection |
| EXG-UX-032 | 5.1 | Keyboard shortcuts Ctrl/Cmd+Enter and Escape, documented and accessible | Must | MUST | Playwright E2E: keyboard shortcuts |
| EXG-UX-033 | 5.1 | "What is sent to the server" panel before publishing | Must | MUST | Playwright E2E: sent-data panel |
| EXG-UX-034 | 5.1 | Three-step "How it works" diagram reachable from footer | Must | MUST | Playwright E2E: footer link |
| EXG-UX-035 | 5.1 | Primary button labelled "Encrypt and create link" or equivalent | Must | MUST | Vitest: i18n key used |
| EXG-CRYPTO-002 | 5.1 | No action sends content before encryption | Must | MUST | Playwright E2E: network inspection |
| EXG-UX-036 | 5.1 | Passphrase entry states it must be shared via another channel | Must | MUST | Playwright E2E: passphrase hint |
| EXG-UX-037 | 5.1 | Passphrase field offers "Generate strong passphrase" action | Must | MUST | Playwright E2E: generate passphrase |
| EXG-CRYPTO-003 | 5.1 | Passphrase generation uses CSPRNG, no preset value, not persisted | Must | MUST | Vitest: generator uses getRandomValues |
| EXG-CRYPTO-004 | 5.1 | Generated passphrase: ≥6 hyphenated words from ≥2048-word locale list, ≥66 bits | Must | MUST | Vitest: word count and entropy |
| EXG-UX-038 | 5.1 | Lightweight local strength indicator for manual passphrases, non-blocking | Must | MUST | Vitest: strength estimator |
| EXG-PERF-001 | 5.1 | Argon2id module preloaded on passphrase field focus | Must | MUST | Vitest: preload on focus |
| EXG-UX-039 | 5.1 | Generated passphrase copyable with feedback not revealing value | Must | MUST | Playwright E2E: copy passphrase |
| EXG-UX-040 | 5.1 | Show/Hide action; masked manual passphrase requires confirmation entry | Must | MUST | Vitest: confirmation rules |
| EXG-SEC-009 | 5.1 | No error, state or validation message contains the passphrase | Must | MUST | Vitest: messages scrubbed |
| EXG-UX-041 | 5.1 | Generation never silently replaces existing passphrase; confirm or undo | Must | MUST | Vitest: replace confirmation |
| EXG-UX-042 | 5.1 | Sensitive template fields can be masked and revealed | Should | MUST | Vitest: template field masking |
| EXG-UX-043 | 5.1 | Revealing a secret is an explicit action | Should | MUST | Playwright E2E: explicit reveal |
| EXG-UX-044 | 5.1 | Copying a secret shows temporary confirmation without revealing it | Should | MUST | Playwright E2E: copy secret feedback |
| EXG-UX-045 | 5.1 | Decrypted content can be hidden quickly on mobile | Must | MUST | Playwright E2E: hide content mobile |
| EXG-SEC-010 | 5.1 | Content hidden when app goes to background if browser allows | Must | MUST | Vitest: visibilitychange hides content |
| EXG-SEC-011 | 5.1 | No local file sent or synced automatically | Must | MUST | Manual review: network audit |
| EXG-SEC-012 | 5.1 | No plaintext in notifications, titles, URLs, logs, error messages | Must | MUST | Playwright E2E: title/URL scan |
| EXG-UX-046 | 5.1 | Deletion asks clear confirmation explaining irreversibility | Must | MUST | Playwright E2E: delete confirmation |
| EXG-UX-047 | 5.1 | Each network/crypto action shows ready/processing/success/recoverable/final state | Must | MUST | Vitest: state machine |
| EXG-UX-048 | 5.1 | Explicit textual states during Argon2id, encryption, decryption | Must | MUST | Vitest: state labels |
| EXG-PERF-002 | 5.1 | Costly operations in Web Worker; Argon2id in dedicated worker | Must | MUST | Vitest: worker invocation |
| EXG-SEC-013 | 5.1 | No fake progress; states never reveal passphrase, key or payload | Must | MUST | Vitest: state messages |
| EXG-A11Y-003 | 5.1 | State changes announced via appropriate aria-live region | Must | MUST | Playwright E2E: aria-live content |
| EXG-UX-049 | 5.1 | Publish action disabled during encryption and upload | Must | MUST | Vitest: button disabled |
| EXG-UX-050 | 5.1 | Prevent double clicks and accidental double creation | Must | MUST | Playwright E2E: double click single POST |
| EXG-UX-051 | 5.1 | Feedback after copy, creation, deletion, language change | Must | MUST | Playwright E2E: feedback messages |
| EXG-UX-052 | 5.1 | Recovery action provided for recoverable errors | Must | MUST | Playwright E2E: retry action |
| EXG-UX-053 | 5.1 | Plain language errors, no stack traces | Must | MUST | Playwright E2E: error page content |
| EXG-UX-054 | 5.1 | Indicative Argon2id duration from calibration, no fake progress bar | Must | MUST | Vitest: duration message |
| EXG-UX-055 | 5.1 | Detect connection loss and offer explicit retry | Must | MUST | Vitest: online/offline events |
| EXG-A11Y-004 | 5.1 | Focus moves to new screen title; skip link available | Must | MUST | Playwright E2E: focus management |
| EXG-A11Y-005 | 5.1 | Success confirmations announced without moving focus; no unexpected focus moves | Must | MUST | Playwright E2E: focus stability |
| EXG-UX-056 | 5.1 | Send failure shows message with Retry (same idempotency key) and Cancel | Must | MUST | Playwright E2E: retry same Idempotency-Key |
| EXG-A11Y-006 | 5.1 | Colour never sole state indicator | Must | MUST | Manual review: colour audit |
| EXG-UX-057 | 5.1 | Text kept in editor after network error while page open | Must | MUST | Playwright E2E: text retained |
| EXG-UX-058 | 5.1 | Sensitive operation never auto-retried without notice | Must | MUST | Vitest: no silent retry |
| EXG-UX-059 | 5.1 | Result screen confirms successful encryption | Must | MUST | Playwright E2E: result screen |
| EXG-UX-060 | 5.1 | Share link in easily copyable field with always-visible Copy | Must | MUST | Playwright E2E: copy share link |
| EXG-UX-061 | 5.1 | Optional QR code button on result screen | Should | MUST | Playwright E2E: QR button |
| EXG-UX-062 | 5.1 | Result shows expiry and burn-after-reading mode | Must | MUST | Playwright E2E: result metadata |
| EXG-UX-063 | 5.1 | Warning to share full link with care | Must | MUST | Playwright E2E: share warning |
| EXG-LIFE-001 | 5.1 | Burn mode: warning not to test link; link not clickable on result | Must | MUST | Playwright E2E: non-clickable link |
| EXG-UX-064 | 5.1 | "Copy with message": localised, absolute expiry with timezone, no passphrase/manage link | Should | MUST | Vitest: share message builder |
| EXG-UX-065 | 5.1 | Native Web Share action limited to share link | Should | MUST | Vitest: share payload |
| EXG-UX-066 | 5.1 | Reminder to send passphrase via another channel when set | Must | MUST | Playwright E2E: passphrase reminder |
| EXG-UX-067 | 5.1 | Expiry countdown on result screen | Must | MUST | Playwright E2E: countdown |
| EXG-UX-068 | 5.1 | New content action confirms loss of uncopied manage link (also on unload) | Must | MUST | Playwright E2E: manage link loss warning |
| EXG-UX-069 | 5.1 | Delete action in separate danger zone while token available | Must | MUST | Playwright E2E: danger zone |
| EXG-SEC-014 | 5.1 | Result screen clears plaintext and key material from memory references | Must | MUST | Vitest: state cleared after create |
| EXG-UX-070 | 5.1 | Share link is the only link in main area | Must | MUST | Playwright E2E: single link displayed |
| EXG-SEC-015 | 5.1 | Manage link behind explicit action with warning and confirmation | Must | MUST | Playwright E2E: manage panel |
| EXG-UX-071 | 5.1 | Share and manage links never in similar side-by-side fields | Must | MUST | Manual review: result layout |
| EXG-SEC-016 | 5.1 | Deletion token never presented as shareable, in QR or native share | Must | MUST | Vitest: QR/share exclude token |
| EXG-SEC-017 | 5.1 | QR code generated locally from share link only, no external service | Should | MUST | Vitest: QR input is share link |
| EXG-UX-072 | 5.1 | QR white opaque background, high contrast, quiet zone | Should | MUST | Vitest: QR render options |
| EXG-UX-073 | 5.1 | QR hidden by default; display and fullscreen need explicit action | Should | MUST | Playwright E2E: QR hidden by default |
| EXG-READ-001 | 5.1 | Burn: "Reveal" screen first; no open request before explicit Reveal | Must | MUST | Playwright E2E: no open before reveal |
| EXG-READ-002 | 5.1 | Burn with passphrase: verified locally on reveal screen before reservation | Must | MUST | Playwright E2E: passphrase before reserve |
| EXG-UX-074 | 5.1 | Explain content is decrypted in the browser | Must | MUST | Playwright E2E: reading explanation |
| EXG-SEC-018 | 5.1 | Never show fake content or preview before crypto verification | Must | MUST | Vitest: render after verify only |
| EXG-UX-075 | 5.1 | Loading state distinct from decrypted content | Must | MUST | Playwright E2E: loading state |
| EXG-UX-076 | 5.1 | Content shown in readable container, suitable width and contrast | Must | MUST | Manual review: reading layout |
| EXG-UX-077 | 5.1 | Copy, Hide, New actions; Hide always reachable; Escape hides content | Must | MUST | Playwright E2E: Escape hides content |
| EXG-PERF-003 | 5.1 | Above 200 KiB decrypted, highlighting/Markdown off by default, opt-in | Should | MUST | Vitest: size threshold rendering |
| EXG-READ-003 | 5.1 | Consume 404 after decrypt keeps content shown with notice | Must | MUST | Vitest: consume 404 handling |
| EXG-LIFE-002 | 5.1 | Burn content shown: banner and warning before close/reload/navigation | Must | MUST | Playwright E2E: burn banner |
| EXG-SEC-019 | 5.1 | Auto-hide content after inactivity (2 min default, user adjustable) | Must | MUST | Vitest: inactivity timer |
| EXG-UX-078 | 5.1 | After copy, non-blocking advice to clear clipboard | Must | MUST | Playwright E2E: clipboard advice |
| EXG-UX-079 | 5.1 | Per-code-block Copy button, keyboard and screen-reader usable | Should | MUST | Playwright E2E: code block copy |
| EXG-SEC-020 | 5.1 | Copied content never in logs, metrics, URL, titles, attributes, errors | Must | MUST | Vitest: copy handler side effects |
| EXG-UX-080 | 5.1 | Relative dynamic expiry with local date, based on server timestamp | Must | MUST | Vitest: expiry display |
| EXG-API-002 | 5.1 | expires_at ISO 8601 UTC authoritative; null for unlimited | Must | MUST | PHPUnit unit: expires_at serialisation |
| EXG-UX-081 | 5.1 | Expiry recomputed locally; handle expired, near, inaccurate clock states | Must | MUST | Vitest: expiry states |
| EXG-UX-082 | 5.1 | Clock skew correction algorithm using server_time and monotonic clock | Must | MUST | Vitest: skew algorithm with fake timers |
| EXG-UX-083 | 5.1 | Resync countdown on visibilitychange/resume | Must | MUST | Vitest: visibility resync |
| EXG-UX-084 | 5.1 | Round trip above 5 s shows expiry as approximate | Must | MUST | Vitest: approximate flag |
| EXG-A11Y-007 | 5.1 | Relative update per minute/second; announce only 5 min, 1 min, expiry thresholds | Must | MUST | Vitest: announcement thresholds |
| EXG-LIFE-003 | 5.1 | Server always enforces expiry; client countdown authorises nothing | Must | MUST | PHPUnit integration: expired returns 404 |
| EXG-SEC-021 | 5.1 | No marketing or external resources around a secret | Must | MUST | Manual review: reading page |
| EXG-SEC-022 | 5.1 | Unavailable content message without revealing exact cause | Must | MUST | Playwright E2E: uniform unavailable message |
| EXG-READ-004 | 5.1 | Distinct "temporarily reserved, retry later" message after key proof | Must | MUST | Playwright E2E: reserved message |
| EXG-READ-005 | 5.1 | Warn before content on prior unconfirmed opens, recommend renewal | Must | MUST | Playwright E2E: prior open warning |
| EXG-READ-006 | 5.1 | Wrong passphrase shows retry message without consuming | Must | MUST | Playwright E2E: wrong passphrase |
| EXG-URL-001 | 5.1 | Missing/invalid fragment key shows incomplete-link message, no server call | Must | MUST | Vitest: fragment validation |
| EXG-UX-085 | 5.1 | Touch targets at least 44×44 px | Must | MUST | Playwright E2E: target size audit |
| EXG-UX-086 | 5.1 | Important actions reachable one-handed | Must | MUST | Manual review: mobile ergonomics |
| EXG-UX-087 | 5.1 | Respect mobile safe areas | Must | MUST | Manual review: device test |
| EXG-UX-088 | 5.1 | Avoid modals blocking back; prefer dismissible bottom sheets | Must | SHOULD | Playwright E2E: back closes sheet |
| EXG-UX-089 | 5.1 | Bottom fixed action bar following keyboard; scroll-padding keeps focus visible | Must | MUST | Playwright E2E: focus not obscured at zoom |
| EXG-UX-090 | 5.1 | No reliance on mouse hover | Must | MUST | Manual review: hover audit |
| EXG-UX-091 | 5.1 | Support virtual keyboards and screen rotation | Must | MUST | Playwright E2E: orientation change |
| EXG-UX-092 | 5.1 | Avoid layout shifts during input | Must | SHOULD | Playwright E2E: CLS during typing |
| EXG-UX-093 | 5.1 | Preserve cursor position after error or format change | Must | MUST | Vitest: caret preservation |
| EXG-UX-094 | 5.1 | overscroll-behavior only on editor/reader containers, not global | Must | MUST | Vitest: CSS rules check |
| EXG-TEST-002 | 5.1 | Test flows on small, large and touch screens | Must | MUST | Playwright E2E: device matrix |
| EXG-THEME-001 | 5.1 | Token-based design system | Should | MUST | CI check: stylelint tokens only |
| EXG-UX-095 | 5.1 | Same components, spacing, labels and states everywhere | Must | MUST | Manual review: design review |
| EXG-THEME-002 | 5.1 | Custom theme cannot alter security alert hierarchy | Should | MUST | Vitest: alert tokens locked |
| EXG-I18N-001 | 5.1 | Interface readable in all enabled LTR languages | Must | MUST | Playwright E2E: locale screenshots |
| EXG-THEME-003 | 5.1 | Light, dark, system modes with three-state selector | Should | MUST | Playwright E2E: theme selector |
| EXG-A11Y-008 | 5.1 | Respect prefers-reduced-motion | Must | MUST | Playwright E2E: reduced motion |
| EXG-UX-096 | 5.1 | No animation delaying a critical action | Must | MUST | Manual review: animation audit |

### 6. V1 features (§6)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-UX-097 | 6.1 | User can type text in an editor | Must | MUST | Playwright E2E: type and create |
| EXG-UX-098 | 6.1 | User can paste text from clipboard | Must | MUST | Playwright E2E: paste and create |
| EXG-MD-001 | 6.1 | User can select syntax-highlighting language | Should | MUST | Playwright E2E: language selector |
| EXG-MD-002 | 6.1 | User can choose plain text, Markdown or code format | Should | MUST | Playwright E2E: format selector |
| EXG-MD-003 | 6.1 | User can choose a ready-to-fill Markdown template | Should | MUST | Playwright E2E: template selector |
| EXG-LIFE-004 | 6.1 | User can set an expiry | Must | MUST | Playwright E2E: expiry selector |
| EXG-LIFE-005 | 6.1 | User can enable burn-after-reading | Must | MUST | Playwright E2E: burn toggle |
| EXG-CRYPTO-005 | 6.1 | User can set an optional passphrase | Must | MUST | Playwright E2E: passphrase create/read |
| EXG-UX-099 | 6.1 | User can generate share link and copy it or show QR | Must | MUST | Playwright E2E: create flow |
| EXG-UX-100 | 6.1 | Publish disabled if local crypto unavailable or validation error | Must | MUST | Vitest: capability check disables publish |
| EXG-MD-004 | 6.1.1 | Templates generated and filled entirely in browser, nothing sent before encryption | Should | MUST | Vitest: template module offline |
| EXG-MD-005 | 6.1.1 | Eight built-in V1 templates with listed fields | Should | MUST | Vitest: template catalogue snapshot |
| EXG-MD-006 | 6.1.1 | Applying template to non-empty editor requires confirmation and undo | Should | MUST | Vitest: template apply confirmation |
| EXG-MD-007 | 6.1.1 | Instant Markdown preview of template | Should | MUST | Playwright E2E: template preview |
| EXG-MD-008 | 6.1.1 | All template fields editable; reset to blank text possible | Should | MUST | Playwright E2E: template edit/reset |
| EXG-MD-009 | 6.1.1 | Warning before leaving unencrypted template input | Should | MUST | Playwright E2E: leave warning template |
| EXG-MD-010 | 6.1.1 | Button to mask/reveal sensitive fields in editor | Should | MUST | Vitest: editor field masking |
| EXG-MD-011 | 6.1.1 | No automatic plaintext save on server | Must | MUST | PHPUnit integration: no draft endpoint |
| EXG-MD-012 | 6.1.1 | Template name sent only inside encrypted payload | Should | MUST | PHPUnit unit: create request has no template field |
| EXG-MD-013 | 6.1.1 | Local custom templates possible in later version | Could | MAY | Manual review: deferred |
| EXG-SEC-023 | 6.1.2 | No honeypot field; abuse handled by rate limit, size limits, quotas | Must | MUST | Manual review: form markup |
| EXG-READ-007 | 6.2 | Recipient opens link without account | Must | MUST | Playwright E2E: anonymous read |
| EXG-READ-008 | 6.2 | Recipient enters passphrase when present | Must | MUST | Playwright E2E: passphrase prompt |
| EXG-READ-009 | 6.2 | Content displayed after local decryption | Must | MUST | Playwright E2E: decrypt and display |
| EXG-MD-014 | 6.2 | Toggle between Markdown render and source | Should | MUST | Playwright E2E: render/source toggle |
| EXG-MD-015 | 6.2 | Built-in templates: per-value Copy button copying only value | Should | MUST | Vitest: field value copy |
| EXG-MD-016 | 6.2 | Built-in templates: sensitive values masked by default with per-field Show | Should | MUST | Vitest: sensitive field masking |
| EXG-MD-017 | 6.2 | Enriched structured template rendering (field cards) without altering content | Could | MAY | Vitest: structured render |
| EXG-MD-018 | 6.2 | Wi-Fi QR on explicit action with escaped WIFI: format, never automatic | Could | MAY | Vitest: WIFI string escaping |
| EXG-READ-010 | 6.2 | Recipient can copy content | Must | MUST | Playwright E2E: copy content |
| EXG-UX-101 | 6.2 | Local-only export when ui.allow_export enabled, no network | Could | MAY | Playwright E2E: export no request |
| EXG-READ-011 | 6.2 | Before decryption show only expiry, burn flag, passphrase presence | Must | MUST | Playwright E2E: pre-decrypt metadata |
| EXG-READ-012 | 6.2 | Format, language, template shown only after decryption | Must | MUST | Vitest: metadata from envelope |
| EXG-MD-019 | 6.2 | Per-block copy and optional line wrap without altering content | Should | MUST | Playwright E2E: wrap toggle |
| EXG-I18N-002 | 6.2 | Expiry localised in active language and browser timezone | Must | MUST | Vitest: Intl formatting |
| EXG-SEC-024 | 6.2 | Content never shown before decryption and integrity checks succeed | Must | MUST | Vitest: tampered envelope not rendered |
| EXG-LIFE-006 | 6.3 | Default durations 5m/1h/1d/7d/30d; never only if instance allows | Must | MUST | PHPUnit unit: allowed durations |
| EXG-LIFE-007 | 6.3 | Delete after first successful read option | Must | MUST | PHPUnit integration: burn lifecycle |
| EXG-LIFE-008 | 6.3 | Automatic expiry | Must | MUST | PHPUnit integration: expired content 404 |
| EXG-LIFE-009 | 6.3 | Manual deletion via secret in management link | Must | MUST | PHPUnit integration: delete with token |
| EXG-SEC-025 | 6.3 | Uniform HTTP response for missing, expired, consumed content | Must | MUST | PHPUnit integration: uniform 404 |
| EXG-LIFE-010 | 6.3 | Burn-after-reading consumed atomically server-side | Must | MUST | PHPUnit integration: concurrent consume |
| EXG-READ-013 | 6.3.1 | No payload or metadata served on identifier alone; proof of K_url required | Must | MUST | PHPUnit integration: open without proof 404 |
| EXG-READ-014 | 6.3.1 | Challenge via challenge endpoint or meta tag in /p/<id> page | Must | MAY | PHPUnit integration: meta challenge present |
| EXG-READ-015 | 6.3.1 | Challenge stateless, no storage access, identical for existing or unknown id | Must | MUST | PHPUnit unit: challenge without storage |
| EXG-READ-016 | 6.3.1 | Client signs access message with Ed25519 key derived from K_url | Must | MUST | Vitest: proof vectors |
| EXG-READ-017 | 6.3.1 | Server verifies HMAC, freshness, id, usage, id/access_pk binding, signature before storage | Must | MUST | PHPUnit unit: proof verification order |
| EXG-READ-018 | 6.3.1 | After proof, access_pk compared with stored AAD; any failure gives generic 404 | Must | MUST | PHPUnit integration: mismatched access_pk |
| EXG-CRYPTO-006 | 6.3.1 | Challenge binary format (version, usage, id, issued_at, nonce, HMAC) | Must | MUST | PHPUnit unit: challenge vectors |
| EXG-CRYPTO-007 | 6.3.1 | K_challenge derived via HKDF from app secret with sp-proto/v1 labels | Must | MUST | PHPUnit unit: K_challenge vector |
| EXG-CRYPTO-008 | 6.3.1 | Signed message = "sp-proto/v1/proof" ‖ 0x00 ‖ challenge, pure Ed25519 | Must | MUST | Shared vectors: PHPUnit/Vitest/CLI |
| EXG-READ-019 | 6.3.1 | consume usage needs consume key; open/status need access key | Must | MUST | PHPUnit unit: usage mismatch rejected |
| EXG-READ-020 | 6.3.1 | open/status challenges valid 60 s; client transparently renews expired ones | Must | MUST | Vitest: transparent challenge renewal |
| EXG-READ-021 | 6.3.1 | consume challenge bound to reservation, stored in state.json, constant-time compare | Must | MUST | PHPUnit integration: consume challenge binding |
| EXG-READ-022 | 6.3.1 | consume challenge unaffected by app secret rotation; valid only for reservation lifetime | Must | MUST | PHPUnit integration: rotation during reservation |
| EXG-READ-023 | 6.3.1 | Challenge response includes expires_in; proactive renewal; single retry on 404 | Must | MUST | Vitest: one retry on 404 |
| EXG-READ-024 | 6.3.1 | Zero clock tolerance on server-issued issued_at | Must | MUST | PHPUnit unit: expired challenge rejected |
| EXG-TEST-003 | 6.3.1 | Test vectors cover signed message; challenge opaque to client | Must | MUST | Shared vectors: proof message |
| EXG-READ-025 | 6.3.1 | open/status send access_pk; id prefix checked vs truncated hash before storage | Must | MUST | PHPUnit integration: no storage access on invalid proof |
| EXG-READ-026 | 6.3.1 | Id-only third party cannot get ciphertext, reserve, block or probe existence | Must | MUST | PHPUnit integration: enumeration resistance |
| EXG-READ-027 | 6.3.1 | New creation always gets new identifier; no recreation under existing link | Must | MUST | PHPUnit integration: id collision refused |
| EXG-DOC-003 | 6.3.1 | Document that holder without passphrase can destroy burn content via unconfirmed opens | Must | MUST | Manual review: security docs |
| EXG-LIFE-011 | 6.3.1 | Burn content not deleted on delivery; states available/reserved/consumed | Must | MUST | PHPUnit unit: state machine transitions |
| EXG-READ-028 | 6.3.1 | Step 0: status returns AAD/state/unconfirmed_opens; passphrase checked vs consume_pk locally | Must | MUST | Vitest: local passphrase check |
| EXG-READ-029 | 6.3.1 | Costly derivations finished before reservation | Must | MUST | Vitest: derivation before open |
| EXG-READ-030 | 6.3.1 | open with random 128-bit reservation id reserves under exclusive lock, stores hash | Must | MUST | PHPUnit integration: reserve under lock |
| EXG-READ-031 | 6.3.1 | open returns payload, consume challenge, unconfirmed_opens; same id resumes (no 409) | Must | MUST | PHPUnit integration: reservation resume |
| EXG-READ-032 | 6.3.1 | Concurrent valid open gets "reserved" with remaining delay, no payload | Must | MUST | PHPUnit integration: concurrent open 409 |
| EXG-READ-033 | 6.3.1 | Client shows prior unconfirmed opens warning when count > 0 | Must | MUST | Vitest: warning when count > 0 |
| EXG-READ-034 | 6.3.1 | Client signs consume challenge with K_consume; server verifies vs stored consume_pk | Must | MUST | PHPUnit integration: consume signature |
| EXG-LIFE-012 | 6.3.1 | Consume: reservation checked under lock, atomic reserved→consumed, payload deleted | Must | MUST | PHPUnit integration: payload removed on consume |
| EXG-LIFE-013 | 6.3.1 | Unconfirmed reservation released after 60 s default (capped); increments counter | Must | MUST | PHPUnit integration: reservation timeout |
| EXG-LIFE-014 | 6.3.1 | At max_unconfirmed_opens (default 3) content becomes consumed | Must | MUST | PHPUnit integration: threshold consumes |
| EXG-LIFE-015 | 6.3.1 | Consume idempotent for exact replay; consumed kept ≥10 min; other reuse rejected | Must | MUST | PHPUnit integration: idempotent consume |
| EXG-LIFE-016 | 6.3.1 | Expired reservations released on next request or scheduled purge | Must | MUST | PHPUnit integration: lazy release |
| EXG-READ-035 | 6.3.1 | Reservation id stored in sessionStorage before open, cleared after consume/expiry | Must | MUST | Vitest: sessionStorage lifecycle |
| EXG-READ-036 | 6.3.1 | Reload resumes active reservation, same challenge, no counter increment, no extension | Must | MUST | PHPUnit integration: resume after reload |
| EXG-READ-037 | 6.3.1 | Resume with passphrase re-prompts and re-verifies locally; expiry counts as unconfirmed | Must | MUST | Playwright E2E: reload during reservation |
| EXG-SEC-026 | 6.3.1 | Reservation id never in localStorage, cookie, URL or log | Must | MUST | Vitest: storage spies |
| EXG-DOC-004 | 6.3.1 | Document burn limits: silent interception, consume_pk offline guessing, no human proof | Must | MUST | Manual review: security docs |
| EXG-LIFE-017 | 6.3.1 | Expiry beats reservation; consumed kept 10 min after terminal_at, never served | Must | MUST | PHPUnit integration: expiry during reservation |
| EXG-SEC-027 | 6.3.1 | Server never receives private keys, K_url, K_pass or passphrase | Must | MUST | PHPUnit integration: request schema audit |
| EXG-TEST-004 | 6.3.1 | Ed25519, HMAC, reservation, counter, concurrency, transitions covered by vectors/integration/review | Must | MUST | CI check: coverage of read protocol |
| EXG-UX-102 | 6.4 | Responsive from 320 px width | Must | MUST | Playwright E2E: 320 px viewport |
| EXG-UX-103 | 6.4 | Supports touch screens and mobile keyboards | Must | MUST | Playwright E2E: mobile emulation |
| EXG-UX-104 | 6.4 | Click targets at least 44 px | Must | MUST | Playwright E2E: target size audit |
| EXG-UX-105 | 6.4 | Avoid modals hard to close by finger | Must | MUST | Manual review: mobile test |
| EXG-UX-106 | 6.4 | Main actions reachable without excessive scroll | Must | MUST | Playwright E2E: action in viewport |
| EXG-UX-107 | 6.4 | Editor suited to small screens | Must | MUST | Manual review: mobile test |
| EXG-UX-108 | 6.4 | Paste and copy with clear visual feedback | Must | MUST | Playwright E2E: copy feedback |
| EXG-A11Y-009 | 6.4 | Respect prefers-reduced-motion and prefers-color-scheme | Must | MUST | Playwright E2E: media emulation |
| EXG-THEME-004 | 6.4 | Usable in dark and light mode | Should | MUST | Playwright E2E: both colour schemes |
| EXG-THEME-005 | 6.5 | Operator can change theme without business code change or full rebuild | Should | MUST | PHPUnit integration: tokens regenerated at boot |
| EXG-THEME-006 | 6.5 | Default light theme, dark theme, system theme detection | Should | MUST | Playwright E2E: theme modes |
| EXG-THEME-007 | 6.5 | Centralised --ql-* CSS variables (§19.1) for all visual properties | Should | MUST | CI check: token inventory |
| EXG-THEME-008 | 6.5 | Theme files separate from application code; selectable by instance config | Should | MUST | PHPUnit unit: theme config |
| EXG-THEME-009 | 6.5 | Local visual tokens file mountable in Docker | Should | MUST | PHPUnit integration: mounted tokens file |
| EXG-THEME-010 | 6.5 | app:theme:preview generates static demo page without real content | Could | MAY | PHPUnit integration: preview command output |
| EXG-DOC-005 | 6.5 | Documentation lists available theme variables | Must | MUST | CI check: docs list matches allowlist |
| EXG-THEME-011 | 6.5 | Mobile compatibility and accessibility preserved for any theme | Should | MUST | Playwright E2E: a11y scan per theme |
| EXG-SEC-028 | 6.5 | public/ holds only static files; no PHP config/tokens/examples | Must | MUST | CI check: public/ content scan |
| EXG-SEC-029 | 6.5 | Operator tokens only in config/themes/, outside web root | Must | MUST | CI check: path layout |
| EXG-THEME-012 | 6.5 | app:boot validates tokens vs allowlist, generates tokens.<hash>.css in generated dir | Should | MUST | PHPUnit integration: boot token generation |
| EXG-SEC-030 | 6.5 | Generated dir only writable asset dir, served read-only; no inline style tokens | Must | MUST | PHPUnit integration: no inline style; Manual review |
| EXG-SEC-031 | 6.5 | Customisation via validated token list only; no arbitrary CSS | Must | MUST | PHPUnit unit: unknown token rejected |
| EXG-THEME-013 | 6.5 | Reference palette inspiration only; no third-party name, logo or assets reused | Should | MUST | Manual review: brand assets |
| EXG-A11Y-010 | 6.5 | Final palette validated by automated and manual contrast tests | Must | MUST | CI check: contrast test suite |
| EXG-A11Y-011 | 6.5 | Primary colour never used as text; per-colour usage restrictions respected | Must | MUST | CI check: contrast test suite |
| EXG-A11Y-012 | 6.5 | Focus ring drawn with outline-offset ≥ 2 px | Must | MUST | Vitest: CSS rule check |
| EXG-A11Y-013 | 6.5 | Changes to primary colour or button text size re-verified by contrast tests | Must | MUST | CI check: contrast test suite |
| EXG-THEME-014 | 6.5 | Primary for main action background; primary-text for links; never sole alert signal | Should | MUST | Manual review: design review |
| EXG-THEME-015 | 6.5 | Black/charcoal for priority text; light surfaces structure without reducing legibility | Should | SHOULD | Manual review: design review |
| EXG-THEME-016 | 6.5 | Distinct semantic colours for success, warning, danger, security alerts | Should | MUST | CI check: token inventory |
| EXG-A11Y-014 | 6.5 | WCAG 2.2 AA contrast for text, controls, borders, focus | Must | MUST | CI check: contrast test suite |
| EXG-A11Y-015 | 6.5 | Each colour combination verified in light, dark and custom themes | Must | MUST | CI check: contrast per theme |
| EXG-SEC-032 | 6.5 | No gradient, decoration or animation conveys security information | Must | MUST | Manual review: design review |
| EXG-THEME-017 | 6.5 | Dark theme: adapted terracotta, charcoal background, distinct surfaces, tested separately, no inversion | Should | MUST | CI check: dark theme contrast |
| EXG-SEC-033 | 6.5 | Local system font stacks only; no remote font | Must | MUST | CI check: no remote font in build |
| EXG-THEME-018 | 6.5 | Sans-serif UI font; monospace for code, structured secrets, logs | Should | MUST | Manual review: typography |
| EXG-THEME-019 | 6.5 | Palette never overrides action hierarchy, legibility or alert visibility | Should | MUST | Manual review: design review |
| EXG-SEC-034 | 6.5 | Custom themes served only from instance; no remote stylesheet | Must | MUST | PHPUnit integration: CSP style-src self |
| EXG-SEC-035 | 6.5 | Only allowed tokens customisable; no arbitrary file path in config | Must | MUST | PHPUnit unit: path rejected |
| EXG-SEC-036 | 6.5 | Themes cannot inject JavaScript; external url() forbidden or controlled | Must | MUST | PHPUnit unit: token value sanitiser |
| EXG-SEC-037 | 6.5 | Themes never alter, hide or blur security alerts | Must | MUST | Vitest: alert tokens locked |
| EXG-SEC-038 | 6.5 | Active theme not stored in encrypted content; theme switch exposes no plaintext | Must | MUST | Vitest: envelope has no theme |
| EXG-THEME-020 | 6.5 | UI separates instance theme, user light/dark choice, reserved security colours | Should | MUST | Manual review: design review |
| EXG-THEME-021 | 6.5 | QuietLink logo: vector mark served by the instance, coloured by theme tokens (light and dark), decorative next to the instance name | Should | MUST | PHPUnit integration: page shell and favicon |
| EXG-A11Y-016 | 6.6 | WCAG 2.2 AA for main flows | Must | MUST | Playwright E2E: axe scan + Manual audit |
| EXG-A11Y-017 | 6.6 | Full keyboard navigation | Must | MUST | Playwright E2E: keyboard-only flows |
| EXG-A11Y-018 | 6.6 | Accessible labels on controls | Must | MUST | Playwright E2E: axe scan |
| EXG-A11Y-019 | 6.6 | Compliant contrast and visible focus | Must | MUST | CI check: contrast; Playwright E2E: focus visible |
| EXG-A11Y-020 | 6.6 | Error messages associated with their field | Must | MUST | Playwright E2E: aria-describedby errors |
| EXG-A11Y-021 | 6.6 | No information conveyed by colour alone | Must | MUST | Manual review: colour audit |
| EXG-A11Y-022 | 6.6 | Compatible with common screen readers | Must | MUST | Manual review: NVDA/VoiceOver test |
| EXG-I18N-003 | 6.6 | Interface texts in French and English at minimum | Must | MUST | CI check: catalogue completeness |
| EXG-I18N-004 | 6.6.1 | Application multilingual from V1 | Must | MUST | CI check: two catalogues load |
| EXG-I18N-005 | 6.6.1 | Language selection: explicit choice, browser preference, supported, else English | Must | MUST | Vitest: locale negotiation |
| EXG-I18N-006 | 6.6.1 | English default and mandatory fallback; French provided | Must | MUST | PHPUnit unit: fallback to en |
| EXG-I18N-007 | 6.6.1 | New language added via catalogue only, no business code change | Must | MUST | PHPUnit integration: extra catalogue |
| EXG-I18N-008 | 6.6.1 | Catalogues declare direction (ltr); applied to document and components | Must | MUST | Vitest: dir attribute |
| EXG-I18N-009 | 6.6.1 | Translate all UI texts, errors, loading states, security messages | Must | MUST | CI check: no hardcoded strings lint |
| EXG-I18N-010 | 6.6.1 | Versioned translation catalogues | Must | MUST | CI check: catalogues in VCS |
| EXG-I18N-011 | 6.6.1 | No sentence fragment concatenation in code | Must | MUST | Manual review: code review; CI lint |
| EXG-I18N-012 | 6.6.1 | Correct accents, case, dates and local formats | Must | MUST | Vitest: Intl formatting fr/en |
| EXG-I18N-013 | 6.6.1 | Never translate secrets or decrypted content | Must | MUST | Vitest: content not passed to i18n |
| EXG-I18N-014 | 6.6.1 | Paste content never sent to determine language | Must | MUST | Manual review: network audit |
| EXG-I18N-015 | 6.6.1 | User can change language from UI | Must | MUST | Playwright E2E: language switch |
| EXG-I18N-016 | 6.6.1 | Only language preference remembered, never plaintext | Must | MUST | Vitest: storage contents |
| EXG-I18N-017 | 6.6.1 | Correct dir="ltr" and lang on HTML document | Must | MUST | PHPUnit integration: html attributes |
| EXG-I18N-018 | 6.6.1 | CSS logical properties; no hardcoded left/right in reusable components | Must | MUST | CI check: stylelint physical properties |
| EXG-I18N-019 | 6.6.1 | Alignment, buttons, shortcuts consistent with LTR UI | Must | MUST | Manual review: UI review |
| EXG-TEST-005 | 6.6.1 | Test UI with a long language and non-Latin characters | Must | MUST | Playwright E2E: pseudo-locale |
| EXG-API-003 | 6.7 | API creates already-encrypted content | Must | MUST | PHPUnit integration: POST /pastes |
| EXG-API-004 | 6.7 | API returns ciphertext after proof of link key possession | Must | MUST | PHPUnit integration: open |
| EXG-API-005 | 6.7 | API deletes content with deletion token | Must | MUST | PHPUnit integration: DELETE |
| EXG-API-006 | 6.7 | API status after proof, without payload or reservation | Must | MUST | PHPUnit integration: status |
| EXG-CLI-001 | 6.7 | CLI provides separate create, metadata and decrypt flows | Must | MUST | PHPUnit integration: CLI commands |
| EXG-CLI-002 | 6.7 | Commands accept URL via stdin | Must | MUST | PHPUnit integration: --url-stdin |
| EXG-CLI-003 | 6.7 | metadata never decrypts, displays content or reserves burn content | Must | MUST | PHPUnit integration: metadata no open |
| EXG-CLI-004 | 6.7 | decrypt writes text only to stdout or explicit file | Must | MUST | PHPUnit integration: decrypt output |
| EXG-CLI-005 | 6.7 | decrypt follows burn protocol, consumes, warns, confirms on TTY unless --yes | Must | MUST | PHPUnit integration: burn decrypt |
| EXG-CLI-006 | 6.7 | No option to read burn content without consuming | Must | MUST | PHPUnit unit: option inventory |
| EXG-CLI-007 | 6.7 | Interactive no-echo passphrase prompt via /dev/tty with confirmation on create | Must | MUST | PHPUnit integration: tty prompt |
| EXG-CLI-008 | 6.7 | --passphrase-stdin explicit; one stdin datum; allowed combinations only, else refused | Must | MUST | PHPUnit unit: stdin combination matrix |
| EXG-CLI-009 | 6.7 | --passphrase-file accepted only if owner-only or under /run/secrets/ | Must | MUST | PHPUnit integration: file permission check |
| EXG-CLI-010 | 6.7 | No command-line option or env var carrying plaintext passphrase | Must | MUST | PHPUnit unit: option inventory |
| EXG-CLI-011 | 6.7 | Without TTY or explicit option, passphrase commands and burn decrypt fail clearly | Must | MUST | PHPUnit integration: no tty failure |
| EXG-DOC-006 | 6.7 | CLI Docker image documents docker run -it for prompts | Must | MUST | Manual review: CLI docs |
| EXG-CLI-012 | 6.7 | Passphrase and derived keys wiped with sodium_memzero when unused | Must | MUST | Manual review: code review |
| EXG-CLI-013 | 6.7 | Share and manage links shown separately, warned, never joined or logged | Must | MUST | PHPUnit integration: create output |
| EXG-CLI-014 | 6.7 | CLI in PHP, encrypts locally, never sends plaintext | Must | MUST | PHPUnit integration: request body is ciphertext |
| EXG-CLI-015 | 6.7 | Distributed as versioned PHAR and ephemeral Docker image | Must | MUST | CI check: release artifacts |
| EXG-CLI-016 | 6.7 | Same test vectors and crypto format as frontend | Must | MUST | Shared vectors: CLI suite |
| EXG-CLI-017 | 6.7 | Retry after timeout reuses same Idempotency-Key and identical body | Must | MUST | PHPUnit unit: retry body equality |
| EXG-CLI-018 | 6.7 | No retry on 422; reports internal error | Must | MUST | PHPUnit unit: 422 handling |
| EXG-CLI-019 | 6.7 | Verifies id fingerprints A and D before displaying links | Must | MUST | PHPUnit unit: id fingerprint check |
| EXG-DOC-007 | 6.7 | PHAR never presented as a standalone binary | Must | MUST | Manual review: docs wording |
| EXG-UX-109 | 6.8 | Printing disabled by default (ui.allow_print false); export ui.allow_export false | Could | MUST | PHPUnit unit: config defaults |
| EXG-UX-110 | 6.8 | When printing disabled, no print action and print CSS hides content | Could | MUST | Playwright E2E: print media emulation |
| EXG-UX-111 | 6.8 | Print only on explicit user action, with out-of-scope warning | Could | MUST | Playwright E2E: print warning |
| EXG-UX-112 | 6.8 | No automatic print, dialog opening or PDF generation | Could | MUST | Vitest: no window.print on load |
| EXG-UX-113 | 6.8 | Print CSS hides controls, QR, manage links, headers, decorations | Could | MUST | Playwright E2E: print snapshot |
| EXG-UX-114 | 6.8 | Only requested decrypted content printable, no sensitive metadata or token | Could | MUST | Playwright E2E: print snapshot |
| EXG-UX-115 | 6.8 | Print never presented as risk-free | Could | MUST | Manual review: copy review |
| EXG-MD-020 | 6.9 | Only https and http link schemes allowed | Should | MUST | Vitest: link scheme filter |
| EXG-MD-021 | 6.9 | javascript:, data:, file: and custom schemes blocked | Should | MUST | Vitest: link scheme filter |
| EXG-MD-022 | 6.9 | External links show accessible exit indicator and target domain when possible | Should | MUST | Vitest: link decoration |
| EXG-MD-023 | 6.9 | Indicator never presented as trust validation | Should | MUST | Manual review: copy review |
| EXG-MD-024 | 6.9 | noopener and noreferrer on new-tab links | Should | MUST | Vitest: rel attributes |
| EXG-SEC-039 | 6.9 | No preview, DNS resolution, HEAD or auto-fetch of link targets | Must | MUST | Playwright E2E: no outbound requests |
| EXG-MD-025 | 6.9 | Optional confirmation for http links or policy, not on each HTTPS click | Should | MAY | Vitest: confirmation policy |
| EXG-PWA-001 | 6.10 | No offline mode or Service Worker in V1 | Must | MUST | CI check: no service worker in build |
| EXG-PWA-002 | 6.10 | Minimal manifest optional via ui.enable_manifest (default false) | Could | MAY | PHPUnit integration: manifest toggle |
| EXG-PWA-003 | 6.10 | No payload, plaintext, key, passphrase or API response cached for installability | Must | MUST | Manual review: cache audit |
| EXG-PWA-004 | 6.10 | No installation screen imposed | Could | MUST | Playwright E2E: no install prompt |
| EXG-PWA-005 | 6.10 | Origin stays visible; display: standalone forbidden | Could | MUST | CI check: manifest display value |
| EXG-PWA-006 | 6.10 | Manifest and icons contain no secret or sensitive instance data | Could | MUST | Manual review: manifest content |
| EXG-PWA-007 | 6.10 | Home screen install does not change Cache-Control, CSP or non-persistence | Could | MUST | PHPUnit integration: headers unchanged |

### 7. Security model (§7)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-SEC-040 | 7.1 | Server cannot decrypt stored content even with storage access | Must | MUST | Manual review: crypto design review |
| EXG-DOC-008 | 7.1 | Malicious-JavaScript-serving instance risk clearly documented | Must | MUST | Manual review: threat model docs |
| EXG-SEC-041 | 7.2 | Each covered threat mapped to mitigation and test in threat analysis | Must | MUST | Manual review: threat analysis |
| EXG-DOC-009 | 7.3 | Non-covered threats documented (compromised server, device, unauthenticated dates, etc.) | Must | MUST | Manual review: threat model docs |
| EXG-SEC-042 | 7.4 | User need not trust instance to know plaintext | Must | MUST | Manual review: crypto design review |
| EXG-SEC-043 | 7.5 | Production-grade security target; no claim of absolute security or unearned certification | Must | MUST | Manual review: docs wording |
| EXG-SEC-044 | 7.5 | HTTPS mandatory in production | Must | MUST | PHPUnit integration: HTTPS enforcement |
| EXG-SEC-045 | 7.5 | HSTS with documented duration | Must | MUST | PHPUnit integration: HSTS header |
| EXG-SEC-046 | 7.5 | Strict page CSP matching reference policy, no unsafe-inline/eval | Must | MUST | PHPUnit integration: CSP header exact |
| EXG-SEC-047 | 7.5 | Argon2id WASM only in dedicated worker; wasm-unsafe-eval only on worker script CSP | Must | MUST | PHPUnit integration: worker CSP header |
| EXG-SEC-048 | 7.5 | Argon2id worker served permanently even if allow_passphrase disabled | Must | MUST | PHPUnit integration: worker route always on |
| EXG-SEC-049 | 7.5 | Workers loaded only from same-origin files (no blob:/data:) | Must | MUST | Vitest: worker URL origin |
| EXG-SEC-050 | 7.5 | No style attributes; highlighter, sanitiser, QR use classes; sanitiser strips style | Must | MUST | Vitest: rendered HTML has no style attr |
| EXG-SEC-051 | 7.5 | img-src data: only for local QR; manifest-src only when manifest enabled | Must | MUST | PHPUnit integration: CSP variants |
| EXG-SEC-052 | 7.5 | Trusted Types with dedicated Markdown policy recommended | Should | SHOULD | PHPUnit integration: require-trusted-types-for |
| EXG-SEC-053 | 7.5 | Any CSP change requires security review | Must | MUST | Manual review: CODEOWNERS on CSP |
| EXG-SEC-054 | 7.5 | connect-src limited to instance origin; frame-ancestors none, no iframe embedding | Must | MUST | PHPUnit integration: CSP header |
| EXG-SEC-055 | 7.5 | X-Content-Type-Options nosniff and Referrer-Policy no-referrer | Must | MUST | PHPUnit integration: security headers |
| EXG-SEC-056 | 7.5 | Cache-Control no-store on content pages and responses | Must | MUST | PHPUnit integration: cache headers |
| EXG-SEC-057 | 7.5 | Permissions-Policy disabling unneeded features with listed minimum | Must | MUST | PHPUnit integration: Permissions-Policy |
| EXG-SEC-058 | 7.5 | COOP and CORP configured | Must | MUST | PHPUnit integration: COOP/CORP headers |
| EXG-SEC-059 | 7.5 | No third-party resources, remote fonts, analytics or ads | Must | MUST | CI check: build output scan |
| EXG-SEC-060 | 7.5 | Cookies disabled by default | Must | MUST | PHPUnit integration: no Set-Cookie |
| EXG-SEC-061 | 7.5 | Static, versioned JS from controlled build; inline scripts avoided or nonce/hash | Must | MUST | CI check: no inline script in templates |
| EXG-SEC-062 | 7.5 | Reproducible builds as far as possible | Must | MUST | CI check: double build diff |
| EXG-SEC-063 | 7.5 | Frontend file hashes published with each release | Must | MUST | CI check: release hash manifest |
| EXG-SEC-064 | 7.5 | Release artefacts signed with Sigstore cosign plus SLSA provenance | Must | MUST | CI check: cosign verify |
| EXG-SEC-065 | 7.5 | SBOM generated | Must | MUST | CI check: SBOM artefact |
| EXG-SEC-066 | 7.5 | Frontend and PHP dependencies checked in CI | Must | MUST | CI check: dependency audit |
| EXG-SEC-067 | 7.5 | No dependency loaded dynamically from remote URL | Must | MUST | CI check: build output scan |
| EXG-DOC-010 | 7.5 | Documented release integrity verification procedure | Must | MUST | Manual review: docs |
| EXG-DOC-011 | 7.5 | Compromised-JS key theft limit visible in user and admin docs | Must | MUST | Manual review: docs |
| EXG-CRYPTO-009 | 7.5 | No home-made cryptographic primitive | Must | MUST | Manual review: crypto review |
| EXG-CRYPTO-010 | 7.5 | Randomness from cryptographically secure source | Must | MUST | CI check: forbid Math.random/rand |
| EXG-CRYPTO-011 | 7.5 | Unique nonces per key and encryption | Must | MUST | Vitest + PHPUnit unit: nonce uniqueness |
| EXG-CRYPTO-012 | 7.5 | Constant-time comparison for PHP secret checks | Must | MUST | PHPUnit unit: hash_equals usage; PHPStan rule |
| EXG-CRYPTO-013 | 7.5 | Versioned crypto format specified before development | Must | MUST | Manual review: protocol doc |
| EXG-CRYPTO-014 | 7.5 | Shared test vectors across frontend, backend, CLI | Must | MUST | CI check: vector suites in all three |
| EXG-CRYPTO-015 | 7.5 | Mandatory cryptographic review before public beta | Must | MUST | Manual review: review report |
| EXG-CRYPTO-016 | 7.5 | No silent downgrade of algorithm or format | Must | MUST | PHPUnit unit: unknown version rejected |
| EXG-CRYPTO-017 | 7.5 | Clear secret references in memory where platform allows | Must | MUST | Manual review: code review |
| EXG-TEST-006 | 7.5 | No real data in test secrets or vectors | Must | MUST | Manual review: vector review |
| EXG-SEC-068 | 7.5 | Production mode mandatory, detailed errors disabled | Must | MUST | PHPUnit integration: error response generic |
| EXG-SEC-069 | 7.5 | Exceptions converted to generic responses, logged without secrets | Must | MUST | PHPUnit integration: exception handler |
| EXG-STORE-001 | 7.5 | Id strictly validated before path derivation; path confined to root_dir | Must | MUST | PHPUnit unit: path traversal |
| EXG-SEC-070 | 7.5 | Strict validation of types, sizes, encodings | Must | MUST | PHPUnit unit: request validators |
| EXG-SEC-071 | 7.5 | Time and memory limits on each request | Must | MUST | Manual review: PHP-FPM config |
| EXG-CRYPTO-018 | 7.5 | 192-bit server-assigned identifiers | Must | MUST | PHPUnit unit: id generation |
| EXG-STORE-002 | 7.5 | Exclusive locking and atomic writes for burn-after-reading | Must | MUST | PHPUnit integration: concurrent consume |
| EXG-DEPLOY-002 | 7.5 | Dedicated system account with minimal storage permissions | Must | MUST | CI check: container user and perms |
| EXG-STORE-003 | 7.5 | Payloads stored only encrypted | Must | MUST | PHPUnit integration: stored payload equals ciphertext |
| EXG-DOC-012 | 7.5 | Backups treated as sensitive data | Must | MUST | Manual review: admin docs |
| EXG-STORE-004 | 7.5 | Cleanup tasks idempotent and verifiable | Must | MUST | PHPUnit integration: purge twice |
| EXG-DEPLOY-003 | 7.5 | Web server has no direct access to config or secrets | Must | MUST | Manual review: Nginx config; CI check |
| EXG-DEPLOY-004 | 7.5 | Non-root container user, no exception | Must | MUST | CI check: image user |
| EXG-DEPLOY-005 | 7.5 | Read-only filesystem, dropped capabilities, seccomp, limited egress | Must | MUST | CI check: compose file lint |
| EXG-DEPLOY-006 | 7.5 | Minimal image pinned by digest; secrets at runtime, never in image | Must | MUST | CI check: image scan |
| EXG-DEPLOY-007 | 7.5 | Separate application and data volumes | Must | MUST | Manual review: compose file |
| EXG-DEPLOY-008 | 7.5 | Healthcheck reveals no sensitive information | Must | MUST | PHPUnit integration: /healthz body |
| EXG-MD-026 | 7.5 | markdown-it html:false, custom link validation, DOMPurify allowlist, Trusted Types | Should | MUST | Vitest: renderer configuration |
| EXG-MD-027 | 7.5 | Sanitiser allowlist explicit and tested; libraries versioned in SBOM | Should | MUST | Vitest: allowlist test; CI check SBOM |
| EXG-SEC-072 | 7.5 | Scripts, iframes, objects, event handlers forbidden in rendered output | Must | MUST | Vitest: XSS corpus |
| EXG-MD-028 | 7.5 | No images rendered from Markdown; alt text plus plain non-clickable URL | Should | MUST | Vitest: image rendering |
| EXG-SEC-073 | 7.5 | No automatic network preview | Must | MUST | Playwright E2E: no outbound requests |
| EXG-TEST-007 | 7.5 | Dedicated XSS tests on Markdown, templates, decryption errors | Must | MUST | Vitest: XSS suite |
| EXG-SEC-074 | 7.5 | Separate rate limits per endpoint; per-id limit counts only valid proofs | Must | MUST | PHPUnit integration: rate limiter |
| EXG-SEC-075 | 7.5 | Size limits applied before full body parsing | Must | MUST | PHPUnit integration: oversize body 413 |
| EXG-SEC-076 | 7.5 | Protection against slow requests and held connections | Must | MUST | Manual review: Nginx timeouts |
| EXG-SEC-077 | 7.5 | Unsupported formats and encodings refused | Must | MUST | PHPUnit integration: 415 responses |
| EXG-SEC-078 | 7.5 | Error responses do not allow content enumeration | Must | MUST | PHPUnit integration: uniform 404 |
| EXG-DOC-013 | 7.5 | Anti-DoS measures documented | Must | MUST | Manual review: admin docs |
| EXG-SEC-079 | 7.5 | No user quotas but mandatory technical anti-abuse limits | Must | MUST | PHPUnit integration: limits enforced |
| EXG-STORE-005 | 7.5 | Global max_total_bytes and max_items quotas, 503 + Retry-After via usage.json | Must | MUST | PHPUnit integration: quota exceeded 503 |
| EXG-STORE-006 | 7.5 | usage.json updated under lock, recalculated by purge; no storage scan per request | Must | MUST | PHPUnit integration: usage drift correction |
| EXG-STORE-007 | 7.5 | min_free_bytes checked with disk_free_space before each creation | Must | MUST | PHPUnit integration: low disk 503 |
| EXG-STORE-008 | 7.5 | Free inodes measured in CLI into health.json; stale file blocks creation | Must | MUST | PHPUnit integration: stale health.json |
| EXG-LIFE-018 | 7.5 | Global max_retention applies to all content, incompatible with never | Must | MUST | PHPUnit unit: config validation |
| EXG-CONF-001 | 7.5 | paste.max_unconfirmed_opens between 1 and 10 | Must | MUST | PHPUnit unit: config bounds |
| EXG-OBS-001 | 7.5 | Documented operational alert at 80% of a quota | Must | MUST | PHPUnit integration: quota warning log |
| EXG-SEC-080 | 7.5 | Threat analysis kept with code | Must | MUST | CI check: threat model file present |
| EXG-SEC-081 | 7.5 | Security review on each protocol or rendering change | Must | MUST | Manual review: PR checklist |
| EXG-SEC-082 | 7.5 | Automated dependency analysis on each change | Must | MUST | CI check: dependency scan |
| EXG-DOC-014 | 7.5 | Published vulnerability disclosure policy | Must | MUST | Manual review: SECURITY.md |
| EXG-DOC-015 | 7.5 | Incident response procedure | Must | MUST | Manual review: admin docs |
| EXG-SEC-083 | 7.5 | Security audit before production | Must | MUST | Manual review: audit report |
| EXG-SEC-084 | 7.5 | Targeted external audit before first public release (crypto, vectors, read protocol, client) | Must | MUST | Manual review: external audit report |
| EXG-SEC-085 | 7.5 | Rest covered by internal review, SAST/DAST; full pentest optional | Must | MUST | CI check: SAST/DAST jobs |

### 8. Cryptographic design (§8)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-CRYPTO-019 | 8.1 | Only primitives from recognised library or Web Crypto | Must | MUST | Manual review: dependency review |
| EXG-CRYPTO-020 | 8.1 | Never self-implement AES, HKDF, HMAC or RNG | Must | MUST | Manual review: code review |
| EXG-CRYPTO-021 | 8.1 | Explicitly versioned encrypted format | Must | MUST | PHPUnit unit: version field |
| EXG-CRYPTO-022 | 8.1 | Public deterministic test vectors | Must | MUST | CI check: vectors published |
| EXG-CRYPTO-023 | 8.1 | Refuse silent algorithm or version downgrade | Must | MUST | PHPUnit unit + Vitest: unsupported version rejected |
| EXG-CRYPTO-024 | 8.2 | Format frozen in Phase 0 with vectors before any implementation | Must | MUST | Manual review: Phase 0 gate |
| EXG-URL-002 | 8.2 | Random 256-bit K_url placed in fragment only | Must | MUST | Vitest: key length and placement |
| EXG-CRYPTO-025 | 8.2 | Server-assigned 192-bit id A‖D‖R (access hash, delete hash, random) | Must | MUST | Shared vectors: A and D computation |
| EXG-CRYPTO-026 | 8.2 | No id reassignment, even on idempotent retry; no existence oracle | Must | MUST | PHPUnit integration: resubmission new id |
| EXG-CRYPTO-027 | 8.2 | Clients verify id fingerprints A and D before any network call | Must | MUST | Vitest: tampered id detected |
| EXG-CRYPTO-028 | 8.2 | Server rejects non-canonical or low-order access_pk/consume_pk at creation | Must | MUST | PHPUnit unit: low-order key rejected |
| EXG-CRYPTO-029 | 8.2 | Passphrase: random 16-byte salt, K_pass via Argon2id | Must | MUST | Shared vectors: Argon2id |
| EXG-CRYPTO-030 | 8.2 | Random 96-bit nonce; K_enc single-use per content | Must | MUST | Vitest: nonce length |
| EXG-CRYPTO-031 | 8.2 | AES-256-GCM with canonical AAD bytes, 128-bit tag appended | Must | MUST | Shared vectors: AES-GCM |
| EXG-CRYPTO-032 | 8.2 | Ed25519 access (and consume for burn) pairs; only public keys sent | Must | MUST | PHPUnit integration: request schema |
| EXG-CRYPTO-033 | 8.2 | Random 256-bit deletion token; only SHA-256 sent to server | Must | MUST | Shared vectors: deletion hash |
| EXG-CRYPTO-034 | 8.2 | Only ciphertext, nonce, AAD, deletion hash, public metadata sent | Must | MUST | PHPUnit unit: request schema strict |
| EXG-CRYPTO-035 | 8.2 | Single-use keys; HKDF derivation schema with sp-proto/v1 labels | Must | MUST | Shared vectors: HKDF intermediates |
| EXG-CRYPTO-036 | 8.2 | Ed25519 seeds per RFC 8032; private keys never stored, re-derived | Must | MUST | Shared vectors: Ed25519 public keys |
| EXG-CRYPTO-037 | 8.2 | Vectors cover every intermediate (PRK, seeds, public keys) | Must | MUST | CI check: vector coverage |
| EXG-CRYPTO-038 | 8.2.1 | Plaintext is strict UTF-8 JSON envelope; no duplicate or unknown keys | Must | MUST | PHPUnit unit + Vitest: envelope parser |
| EXG-CRYPTO-039 | 8.2.1 | Envelope fields format, language, template, text, v with allowed values | Must | MUST | Vitest: envelope schema |
| EXG-CRYPTO-040 | 8.2.1 | text normalised to \n; lone surrogates replaced by U+FFFD | Must | MUST | Vitest: toWellFormed and newline |
| EXG-CRYPTO-041 | 8.2.1 | Format, language, template never in clear on server | Must | MUST | PHPUnit integration: stored metadata audit |
| EXG-CRYPTO-042 | 8.2.1 | Size limit applies to serialised envelope | Must | MUST | Vitest: envelope size measurement |
| EXG-CRYPTO-043 | 8.2.2 | AAD is RFC 8785 UTF-8 JSON, stored and returned verbatim | Must | MUST | PHPUnit integration: AAD roundtrip bytes |
| EXG-CRYPTO-044 | 8.2.2 | AAD fields v, alg, read_once, expiration, kdf, access_pk, consume_pk; no others | Must | MUST | PHPUnit unit: AAD schema |
| EXG-CRYPTO-045 | 8.2.2 | Keys sorted by UTF-16 code units, no whitespace; printable ASCII strings only | Must | MUST | PHPUnit unit: AAD canonicalisation |
| EXG-CRYPTO-046 | 8.2.2 | Integers only in [0, 2^53−1]; no floats, -0, NaN, arrays | Must | MUST | PHPUnit unit: AAD number rules |
| EXG-CRYPTO-047 | 8.2.2 | Duplicate, unknown, missing keys or wrong types rejected server and client | Must | MUST | PHPUnit unit + Vitest: AAD rejection cases |
| EXG-CRYPTO-048 | 8.2.2 | Server checks received bytes equal canonical serialisation of decoded object | Must | MUST | PHPUnit unit: non-canonical AAD rejected |
| EXG-CRYPTO-049 | 8.2.2 | Server checks AAD lengths, duration, read_once/consume_pk, kdf vs config, bounds | Must | MUST | PHPUnit unit: AAD consistency |
| EXG-CRYPTO-050 | 8.2.2 | id, created_at, expires_at outside AAD; expires_at = created_at + duration | Must | MUST | PHPUnit unit: expiry computation |
| EXG-DOC-016 | 8.2.2 | Unauthenticated server-managed dates documented in threat model | Must | MUST | Manual review: threat model |
| EXG-CRYPTO-051 | 8.2.2 | Reader checks access_pk matches K_url; open AAD byte-identical to status AAD | Must | MUST | Vitest: AAD mismatch integrity failure |
| EXG-CRYPTO-052 | 8.2.2 | Any AAD modification causes decryption failure | Must | MUST | Shared vectors: tampered AAD |
| EXG-CRYPTO-053 | 8.2.3 | Binary values canonical base64url without padding; non-canonical rejected everywhere | Must | MUST | Shared vectors: non-canonical base64url |
| EXG-CONF-002 | 8.2.3 | Four size levels with defaults (envelope, ciphertext, AAD, HTTP body) | Must | MUST | PHPUnit unit: size defaults |
| EXG-CONF-003 | 8.2.3 | max_ciphertext_bytes always max_envelope_bytes + 16, not configurable | Must | MUST | PHPUnit unit: derived limit |
| EXG-CONF-004 | 8.2.3 | max_request_bytes coherence checked at startup | Must | MUST | PHPUnit unit: boot coherence check |
| EXG-DEPLOY-009 | 8.2.3 | Reverse proxy body limit aligned with http.max_request_bytes | Must | MUST | CI check: Nginx config value |
| EXG-SEC-086 | 8.2.3 | Body refused when Content-Length or stream exceeds limit, before JSON decode | Must | MUST | PHPUnit integration: chunked oversize |
| EXG-URL-003 | 8.2.3 | Fragment treated as secret: never in logs, analytics, errors, traces, external URLs | Must | MUST | Vitest + PHPUnit integration: log scan |
| EXG-CRYPTO-054 | 8.3 | Passphrase never stored in clear | Must | MUST | PHPUnit integration: storage audit |
| EXG-CRYPTO-055 | 8.3 | Argon2id mandatory KDF for passphrase mode | Must | MUST | Shared vectors: Argon2id |
| EXG-CRYPTO-056 | 8.3 | Audited Argon2id (hash-wasm) in dedicated worker, versioned, in SBOM | Must | MUST | CI check: SBOM entry |
| EXG-SEC-087 | 8.3 | Only wasm-unsafe-eval allowed if needed, never unsafe-eval | Must | MUST | PHPUnit integration: CSP header |
| EXG-CRYPTO-057 | 8.3 | Passphrase mode disabled if unsupported; read shows explicit message without open | Must | MUST | Vitest: capability fallback |
| EXG-CRYPTO-058 | 8.3 | No silent fallback to PBKDF2 or weaker KDF | Must | MUST | Vitest: no fallback path |
| EXG-CRYPTO-059 | 8.3 | Salt exactly 16 bytes | Must | MUST | PHPUnit unit: salt length |
| EXG-CRYPTO-060 | 8.3 | K_pass = Argon2id v1.3, p=1, L=32, NFC-normalised UTF-8 passphrase | Must | MUST | Shared vectors: NFC passphrase |
| EXG-CRYPTO-061 | 8.3 | m in KiB; memlimit = m×1024, opslimit = t | Must | MUST | PHPUnit unit: libsodium params |
| EXG-CRYPTO-062 | 8.3 | Fixed Argon2id bounds on m and t enforced by server and reader | Must | MUST | PHPUnit unit + Vitest: KDF bounds |
| EXG-CRYPTO-063 | 8.3 | KDF id, salt, params stored in AAD; never passphrase or K_pass | Must | MUST | PHPUnit unit: AAD kdf object |
| EXG-CRYPTO-064 | 8.3 | Random key never replaced by password-derived key only | Must | MUST | Shared vectors: IKM includes K_url |
| EXG-UX-116 | 8.3 | Warning when passphrase obviously weak | Must | MUST | Vitest: weak passphrase warning |
| EXG-CRYPTO-065 | 8.3 | Defaults m=65536, t=3, p=1, calibrated in Phase 0 and documented | Must | MUST | Manual review: calibration report |
| EXG-CRYPTO-066 | 8.3 | Param changes bump format version or stay compatible with stored params | Must | MUST | Shared vectors: legacy params |
| EXG-CRYPTO-067 | 8.4 | Format contains version, alg, nonce, ciphertext, KDF params, public keys, AAD | Must | MUST | PHPUnit unit: stored record schema |
| EXG-CRYPTO-068 | 8.4 | Any payload modification yields explicit decryption error | Must | MUST | Vitest: tampered ciphertext error |
| EXG-CRYPTO-069 | 8.4 | Ed25519 pure; server verifies with sodium_crypto_sign_verify_detached | Must | MUST | PHPUnit unit: signature verification |
| EXG-CRYPTO-070 | 8.4 | Browser wraps seed in fixed PKCS#8 DER prefix, covered by vectors | Must | MUST | Vitest: PKCS#8 vector |
| EXG-CRYPTO-071 | 8.4 | Mandatory local audited pure-JS Ed25519 fallback, chosen at load, no remote load | Must | MUST | Vitest: fallback selection |
| EXG-CRYPTO-072 | 8.4 | Web Crypto key extractable only to derive public key; references cleared | Must | MUST | Manual review: code review |
| EXG-CRYPTO-073 | 8.4 | Conformance on public keys, signatures, cross-verification | Must | MUST | Shared vectors: Ed25519 cross-check |
| EXG-CRYPTO-074 | 8.4 | Client keeps consume signature for idempotent resend, no re-signing | Must | MUST | Vitest: retry reuses signature |
| EXG-URL-004 | 8.5 | Share URL format /p/<id>#<key> | Must | MUST | Shared vectors: URL parse/build |
| EXG-URL-005 | 8.5 | Distinct management URL /manage/<id>#<deletion-token> | Must | MUST | Vitest: manage URL builder |
| EXG-URL-006 | 8.5 | Manage page never shows or decrypts content | Must | MUST | Playwright E2E: manage page |
| EXG-URL-007 | 8.5 | Manage page reveals no existence/state before deletion action | Must | MUST | Playwright E2E: no request before action |
| EXG-URL-008 | 8.5 | Manage page single "Delete permanently" action with explicit confirmation | Must | MUST | Playwright E2E: delete confirmation |
| EXG-URL-009 | 8.5 | Single result message whether deleted, expired or invalid token | Must | MUST | Playwright E2E: uniform result |
| EXG-URL-010 | 8.5 | Truncated manage link detected without server contact | Must | MUST | Vitest: token validation |
| EXG-URL-011 | 8.5 | id 32-char canonical base64url; key and token base64url without padding | Must | MUST | Shared vectors: URL encoding |
| EXG-URL-012 | 8.5 | Passphrase never included in URL | Must | MUST | Vitest: URL builder |
| EXG-URL-013 | 8.5 | Server never receives fragment; stores only deletion token hash | Must | MUST | PHPUnit integration: request audit |
| EXG-URL-014 | 8.5 | Deletion token sent only in dedicated header, never path/query/log/error | Must | MUST | PHPUnit integration: DELETE header only |
| EXG-URL-015 | 8.5 | Manage link never in QR code or native share | Must | MUST | Vitest: QR/share input |
| EXG-URL-016 | 8.5 | Full URL never sent to third parties, logs, caches, analytics, errors | Must | MUST | PHPUnit integration: log scan |
| EXG-TEST-008 | 8.5 | URL format documented and covered by frontend/CLI/backend compatibility tests | Must | MUST | Shared vectors: URL suite |
| EXG-SEC-088 | 8.6 | Read page HTML contains no plaintext | Must | MUST | PHPUnit integration: /p/<id> body |
| EXG-SEC-089 | 8.6 | Read page has noindex, nofollow, noarchive | Must | MUST | PHPUnit integration: robots meta/header |
| EXG-SEC-090 | 8.6 | Read page Referrer-Policy no-referrer, no third-party resources, no auto-share | Must | MUST | PHPUnit integration: headers; CI check |

### 9. Target architecture (§9)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-GEN-005 | 9.1 | Components: static frontend, Symfony API, file storage, purge command, PHP CLI | Must | MUST | Manual review: architecture |
| EXG-LIFE-019 | 9.1 | Expiry cleanup by Cron/systemd command plus opportunistic request cleanup | Must | MUST | PHPUnit integration: purge command |
| EXG-GEN-006 | 9.2 | PHP mandatory server-side, 8.3+, strict types | Must | MUST | CI check: PHPStan strict_types rule |
| EXG-GEN-007 | 9.2 | Composer-only dependencies; PSR-4, PSR-12, PSR-3 | Must | MUST | CI check: php-cs-fixer, composer validate |
| EXG-GEN-008 | 9.2 | Symfony micro-kernel with targeted components; Twig templates never get user content | Must | MUST | Manual review: composer.json |
| EXG-STORE-009 | 9.2 | No Symfony Lock component; direct flock() on state.lock | Must | MUST | CI check: dependency deny list |
| EXG-SEC-091 | 9.2 | File-based rate limiting in dedicated dir, keyed by client IP HMAC | Must | MUST | PHPUnit integration: ratelimit keys |
| EXG-SEC-092 | 9.2 | Forwarded headers trusted only from http.trusted_proxies; app:boot warns if missing | Must | MUST | PHPUnit unit: trusted proxy resolution |
| EXG-SEC-093 | 9.2 | IPv4-mapped normalised; IPv6 aggregated by /48–/64 prefix | Must | MUST | PHPUnit unit: IP normalisation |
| EXG-SEC-094 | 9.2 | Daily HKDF rate-limit key (UTC); counts over current and previous day | Must | MUST | PHPUnit unit: midnight rollover |
| EXG-SEC-095 | 9.2 | Rate-limit counters use 256 fixed lock files; no plaintext IP stored | Must | MUST | PHPUnit integration: no IP in files |
| EXG-PERF-004 | 9.2 | Lock contention measured in Phase 3 load tests | Must | MUST | Manual review: load test report |
| EXG-DOC-017 | 9.2 | Technical docs, comments, READMEs, OpenAPI, protocol in English | Must | MUST | Manual review: docs language |
| EXG-GEN-009 | 9.2 | Strict JSON input and output validation | Must | MUST | PHPUnit unit: JSON validators |
| EXG-STORE-010 | 9.2 | Local file storage reference with documented layout, perms, locking, atomic writes | Must | MUST | Manual review: storage docs |
| EXG-SEC-096 | 9.2 | No secrets in source code or Docker images | Must | MUST | CI check: secret scanning |
| EXG-TEST-009 | 9.2 | PHPUnit mandatory for backend; PHPStan static analysis | Must | MUST | CI check: PHPUnit and PHPStan jobs |
| EXG-TEST-010 | 9.2 | Mandatory TDD Red-Green-Refactor | Must | MUST | Manual review: PR history |
| EXG-DEPLOY-010 | 9.2 | PHP-FPM with Nginx or Apache; dedicated non-root runtime image | Must | MUST | CI check: image user |
| EXG-DEPLOY-011 | 9.2 | Symfony cache prewarmed at build, read-only; only storage, generated assets, /tmp writable | Must | MUST | CI check: read-only container smoke test |
| EXG-CRYPTO-075 | 9.2 | CLI AES-256-GCM via ext-openssl; never sodium_crypto_aead_aes256gcm_* | Must | MUST | CI check: forbidden function scan |
| EXG-CRYPTO-076 | 9.2 | CLI Argon2id and Ed25519 via ext-sodium; hash_hkdf/hash_hmac/hash; random_bytes | Must | MUST | PHPUnit unit: CLI crypto adapters |
| EXG-CRYPTO-077 | 9.2 | CLI requires ext-intl Normalizer for NFC | Must | MUST | PHPUnit unit: extension check |
| EXG-CRYPTO-078 | 9.2 | CLI and backend refuse start if crypto extension missing | Must | MUST | PHPUnit unit: boot extension check |
| EXG-GEN-010 | 9.2 | No server-side JavaScript to process content | Must | MUST | Manual review: architecture |
| EXG-SEC-097 | 9.3 | Server never receives plaintext, key, passphrase, preview content, analytics with full link | Must | MUST | PHPUnit integration: request schema audit |
| EXG-STORE-011 | 9.4 | Storage record limited to the listed minimal fields | Must | MUST | PHPUnit unit: record schema |
| EXG-STORE-012 | 9.4 | Storage format version (JSON with schema_version per §19.1) | Must | MUST | PHPUnit unit: schema_version |
| EXG-STORE-013 | 9.4 | Idempotency index: write-once file per key with listed fields | Must | MUST | PHPUnit integration: idempotency file |
| EXG-STORE-014 | 9.4 | Burn transitions under exclusive state.lock, check under lock, temp write + rename | Must | MUST | PHPUnit integration: atomic transition |
| EXG-STORE-015 | 9.4 | No SQL, NoSQL or external database required | Must | MUST | CI check: dependency deny list |
| EXG-STORE-016 | 9.4.1 | Content stored in id-prefix partitioned directory tree | Must | MUST | PHPUnit unit: path builder |
| EXG-STORE-017 | 9.4.1 | payload.bin and meta.json write-once; transitions rewrite only state.json | Must | MUST | PHPUnit integration: immutability |
| EXG-STORE-018 | 9.4.1 | No file holds plaintext, key, passphrase or raw deletion token | Must | MUST | PHPUnit integration: storage scan |
| EXG-STORE-019 | 9.4.1 | Content exists only with four files; created via atomic dir rename | Must | MUST | PHPUnit integration: creation atomicity |
| EXG-STORE-020 | 9.4.1 | Check target absence before rename; redraw R on collision; no reassignment | Must | MUST | PHPUnit integration: collision retry |
| EXG-STORE-021 | 9.4.1 | payload.bin removed under lock right after consumed state written | Must | MUST | PHPUnit integration: consume removes payload |
| EXG-STORE-022 | 9.4.1 | Two-phase deletion via deleted state; purge completes interrupted deletions | Must | MUST | PHPUnit integration: crash recovery |
| EXG-STORE-023 | 9.4.1 | After lock, verify inode identity and re-read state; abort if deleted/consumed | Must | MUST | PHPUnit integration: stale lock inode |
| EXG-STORE-024 | 9.4.1 | No empty partial <id>/ dir; dirs missing state files removed by boot/purge | Must | MUST | PHPUnit integration: partial dir cleanup |
| EXG-STORE-025 | 9.4.1 | state.lock with exclusive flock for burn, deletion, reservation | Must | MUST | PHPUnit integration: lock usage |
| EXG-STORE-026 | 9.4.1 | state.lock created only at creation; opened r+ without create; absent means 404 | Must | MUST | PHPUnit unit: lock open mode |
| EXG-STORE-027 | 9.4.1 | state.json changes via temp file, fsync, atomic rename | Must | MUST | PHPUnit integration: atomic write |
| EXG-STORE-028 | 9.4.1 | Parent dir sync when possible; supported journaled FS documented | Must | MUST | Manual review: storage docs |
| EXG-STORE-029 | 9.4.1 | Boot refuses unsupported FS unless allow_unsupported_fs (logged warning) | Must | MUST | PHPUnit unit: mounts parser |
| EXG-STORE-030 | 9.4.1 | Files owned by dedicated PHP account, not reachable by static web server | Must | MUST | CI check: container perms |
| EXG-STORE-031 | 9.4.1 | Storage root outside public/, not indexable, minimal permissions | Must | MUST | CI check: layout |
| EXG-STORE-032 | 9.4.1 | Local FS with reliable flock/rename only; NFS/SMB unsupported | Must | MUST | Manual review: admin docs |
| EXG-DEPLOY-012 | 9.4.1 | Single app instance with local volume; no horizontal replication in V1 | Must | MUST | Manual review: admin docs |
| EXG-STORE-033 | 9.4.1 | No temp file in shared or web-exposed directory | Must | MUST | PHPUnit integration: temp paths |
| EXG-STORE-034 | 9.4.1 | Orphan temp files removed only after age check and no active lock | Must | MUST | PHPUnit integration: orphan cleanup |
| EXG-STORE-035 | 9.4.1 | Deletion via unlink with dir sync when possible; no SSD erasure promise | Must | MUST | Manual review: docs wording |
| EXG-STORE-036 | 9.4.1 | Idempotency index files without secrets; bounded retention | Must | MUST | PHPUnit integration: idempotency purge |
| EXG-STORE-037 | 9.4.1 | Purge scans expected dirs only, refuses outward symlinks, idempotent, non-blocking purge.lock | Must | MUST | PHPUnit integration: purge concurrency and symlinks |
| EXG-STORE-038 | 9.4.1 | Consumed kept ≥10 min without payload; a manual delete removes it at once (§10 l.1622 prevails, ADR-0008 decision 1) | Must | MUST | PHPUnit integration: consumed retention |
| EXG-STORE-039 | 9.4.1 | usage.json under usage.lock; quota check and reservation atomic; update rules | Must | MUST | PHPUnit integration: concurrent quota |
| EXG-DOC-018 | 9.4.1 | Backups from consistent snapshot or stopped service; no live tar; restore tested | Must | MUST | Manual review: backup procedure |
| EXG-DEPLOY-013 | 9.4.1 | Container root FS read-only; only storage and needed temp dirs writable | Must | MUST | CI check: compose read_only |
| EXG-STORE-040 | 9.4.1 | File storage is the only persistence; no database engine installed or required | Must | MUST | CI check: image package scan |
| EXG-CONF-005 | 9.5 | Instance configurable via dedicated PHP file without code change | Must | MUST | PHPUnit integration: config loading |
| EXG-CONF-006 | 9.5 | Recommended config/ layout with example files | Must | SHOULD | CI check: files present |
| EXG-CONF-007 | 9.5 | Mandatory app:boot validates config, extensions, paths, perms, FS, space, inodes | Must | MUST | PHPUnit integration: app:boot checks |
| EXG-CONF-008 | 9.5 | app:boot creates rate-limit locks and usage.lock, tests link/rename, generates tokens CSS | Must | MUST | PHPUnit integration: app:boot side effects |
| EXG-DEPLOY-014 | 9.5 | Boot failure exits non-zero; entrypoint and systemd ExecStartPre refuse PHP-FPM start | Must | MUST | CI check: container start with bad config |
| EXG-DEPLOY-015 | 9.5 | systemd: dedicated PHP-FPM instance/unit; app:boot under worker account | Must | MUST | Manual review: unit files |
| EXG-DEPLOY-016 | 9.5 | Reload runs app:boot then USR2 only on success; same in container | Must | MUST | Manual review: unit files; CI smoke test |
| EXG-DEPLOY-017 | 9.5 | OPcache validate_timestamps disabled in production; reload resets cache | Must | MUST | CI check: php.ini values |
| EXG-CONF-009 | 9.5 | boot.json config fingerprint; per-request mismatch or absence returns 503 | Must | MUST | PHPUnit integration: fingerprint mismatch 503 |
| EXG-CONF-010 | 9.5 | Config files read at runtime, never compiled into prewarmed container | Must | MUST | PHPUnit integration: config change after boot |
| EXG-CONF-011 | 9.5 | config.php returns typed documented config; config.local.php overrides, not versioned | Must | MUST | PHPUnit unit: config schema; CI check gitignore |
| EXG-CONF-012 | 9.5 | V1 configurable options as listed (locales, expiries, burn, passphrase, sizes, quotas, ui…) | Must | MUST | PHPUnit unit: config schema |
| EXG-CONF-013 | 9.5 | English fallback fixed, not configurable | Must | MUST | PHPUnit unit: locales validation |
| EXG-CONF-014 | 9.5 | read_once_reservation_ttl default 60 s, 30–300 s | Must | MUST | PHPUnit unit: config bounds |
| EXG-CONF-015 | 9.5 | App secret via QUIETLINK_APP_SECRET or _FILE; passed explicitly to FPM pool | Must | MUST | PHPUnit integration: secret sources |
| EXG-CONF-016 | 9.5 | app:boot checks pool passes secret; stores non-reversible HMAC fingerprint | Must | MUST | PHPUnit integration: secret fingerprint |
| EXG-CONF-017 | 9.5 | Worker with missing/mismatched secret returns 503; healthz flags it, never shows secret | Must | MUST | PHPUnit integration: secret mismatch |
| EXG-CONF-018 | 9.5 | idempotency_max_ttl default 24 h | Must | MUST | PHPUnit unit: defaults |
| EXG-CONF-019 | 9.5 | CORS disabled by default | Must | MUST | PHPUnit integration: no CORS headers |
| EXG-CONF-020 | 9.5 | UI option defaults: QR true, print/export/manifest false | Must | MUST | PHPUnit unit: defaults |
| EXG-LIFE-020 | 9.5 | Expired content refused at deadline, fully deleted by next purge at latest | Must | MUST | PHPUnit integration: expiry boundary |
| EXG-SEC-098 | 9.5 | Config file never served by web server | Must | MUST | PHPUnit integration: HTTP fetch of config 404 |
| EXG-SEC-099 | 9.5 | No secrets in config.php; secrets via env, Docker secrets or equivalent | Must | MUST | CI check: secret scanning |
| EXG-SEC-100 | 9.5 | config.php.example contains no real secret | Must | MUST | CI check: secret scanning |
| EXG-CONF-021 | 9.5 | Config validated at startup; invalid config blocks start or safely disables option | Must | MUST | PHPUnit unit: invalid config handling |
| EXG-SEC-101 | 9.5 | Config files readable only by PHP process user | Must | MUST | PHPUnit integration: perms check in boot |
| EXG-STORE-041 | 9.5 | Storage dirs outside web root, app-owned, no outward symlinks | Must | MUST | PHPUnit integration: path validation |
| EXG-STORE-046 | 9.4.1 | Data directory configurable (storage.data_dir), default datas/ at the project root; storage dirs derive from it and stay configurable (spec v0.19) | Must | MUST | PHPUnit unit: config defaults and derivation |
| EXG-STORE-042 | 9.5 | Storage paths validated at boot; auto-created with restrictive perms | Must | MUST | PHPUnit integration: dir creation mode |
| EXG-SEC-102 | 9.5 | Only token files under config/themes/ without absolute path, .. or outward symlink | Must | MUST | PHPUnit unit: token path validation |
| EXG-CONF-022 | 9.5 | Config change requires explicit restart or reload | Must | MUST | PHPUnit integration: fingerprint enforcement |
| EXG-CONF-023 | 9.5 | app:config:check verifies effective config without revealing secrets | Must | MUST | PHPUnit integration: config:check output |
| EXG-CONF-024 | 9.5 | Boot coherence: never only via allow_forever requiring max_retention null | Must | MUST | PHPUnit unit: coherence rules |
| EXG-CONF-025 | 9.5 | Duration codes from closed set; max_retention/idempotency_max_ttl <int><m/h/d> format | Must | MUST | PHPUnit unit: duration parser |
| EXG-CONF-026 | 9.5 | Allowed expiries ≤ max_retention; default in allowed list | Must | MUST | PHPUnit unit: coherence rules |
| EXG-CONF-027 | 9.5 | idempotency_max_ttl between 1 h and 7 d | Must | MUST | PHPUnit unit: config bounds |
| EXG-CONF-028 | 9.5 | App secret present, standard base64, ≥32 decoded bytes used as HKDF IKM | Must | MUST | PHPUnit unit: secret validation |
| EXG-CONF-029 | 9.5 | app:secret:generate produces 32 random bytes | Must | MUST | PHPUnit integration: secret generation |
| EXG-CONF-030 | 9.5 | Config source precedence: defaults, config.php, config.local.php, env for secrets | Must | MUST | PHPUnit unit: precedence |
| EXG-CONF-031 | 9.5 | Env var cannot silently disable a security protection | Must | MUST | PHPUnit unit: env override warning |
| EXG-SEC-103 | 9.5 | No web backoffice, admin account, dashboard or admin endpoint | Must | MUST | PHPUnit integration: route inventory |
| EXG-CACHE-001 | 9.6 | Caching never retains or reveals sensitive data | Must | MUST | PHPUnit integration: cache content scan |
| EXG-CACHE-002 | 9.6 | no-store for ciphertext, API responses, burn state, sensitive pages | Must | MUST | PHPUnit integration: Cache-Control per route |
| EXG-CACHE-003 | 9.6 | Plaintext, keys, passphrase, token, full URL never cached | Must | MUST | PHPUnit integration: cache content scan |
| EXG-CACHE-004 | 9.6 | URL fragments never in cache, log, metric or Referer | Must | MUST | PHPUnit integration: log/cache scan |
| EXG-CACHE-005 | 9.6 | Caching allowed only for non-sensitive technical artefacts and static assets | Must | MAY | Manual review: cache inventory |
| EXG-CACHE-006 | 9.6 | Symfony cache never contains plaintext, keys, passphrase, token or payload | Must | MUST | PHPUnit integration: var/cache scan |
| EXG-CACHE-007 | 9.6 | Assets use versioned names or content hashes | Must | MUST | CI check: build manifest |
| EXG-CACHE-008 | 9.6 | Immutable assets may use long public max-age immutable | Must | MAY | PHPUnit integration: asset headers |
| EXG-CACHE-009 | 9.6 | Theme and translation assets invalidated after change | Must | MUST | PHPUnit integration: hash changes on token edit |
| EXG-SEC-104 | 9.6 | No static resource from third-party CDN | Must | MUST | CI check: build output scan |
| EXG-CACHE-010 | 9.6 | Theme config change effective after purge or new cache version | Must | MUST | PHPUnit integration: theme regeneration |
| EXG-CACHE-011 | 9.6 | Updates avoid serving mixed frontend versions | Must | MUST | Manual review: release procedure |
| EXG-CACHE-012 | 9.6 | OPcache enabled in production with explicit timestamp validation settings | Must | MUST | CI check: php.ini values |
| EXG-CACHE-013 | 9.6 | PHP-FPM restart or controlled purge with each release | Must | MUST | Manual review: release procedure |
| EXG-CACHE-014 | 9.6 | Framework config and routes compiled at build; instance config read at runtime | Must | MUST | PHPUnit integration: runtime config |
| EXG-CACHE-015 | 9.6 | Invalid config never cached as valid | Must | MUST | PHPUnit integration: invalid config not marked |
| EXG-CACHE-016 | 9.6 | /api/v1/pastes excluded from shared caches; create/read pages private, non-cacheable | Must | MUST | PHPUnit integration: Cache-Control headers |
| EXG-TEST-011 | 9.6 | Anti-cache headers tested behind Nginx, Apache and reverse proxy | Must | MUST | CI check: header tests per web server |
| EXG-CACHE-017 | 9.6 | No Service Worker caching sensitive pages; browser not auto-caching plaintext | Must | MUST | CI check: no service worker |
| EXG-CACHE-018 | 9.6 | CLI command for controlled purge of non-sensitive caches | Must | MUST | PHPUnit integration: cache purge command |
| EXG-DOC-019 | 9.6 | Cache purge procedure on theme, translation, config change | Must | MUST | Manual review: admin docs |
| EXG-CACHE-019 | 9.6 | Cache keys separated by app version and instance | Must | MUST | PHPUnit unit: cache key namespace |
| EXG-TEST-012 | 9.6 | Tests prove no payload or secret enters any cache | Must | MUST | PHPUnit integration: cache tests |
| EXG-DOC-020 | 9.6 | Lifetime of each cache documented | Must | MUST | Manual review: admin docs |
| EXG-LIFE-021 | 9.7 | app:purge-expired command provided | Must | MUST | PHPUnit integration: purge command |
| EXG-LIFE-022 | 9.7 | Purge idempotent, limited to expired/consumed, stale reservations, idempotency, rate-limit entries | Must | MUST | PHPUnit integration: purge scope |
| EXG-LIFE-023 | 9.7 | Runnable by Cron/systemd, recommended every minute | Must | SHOULD | Manual review: deployment units |
| EXG-STORE-043 | 9.7 | Purge under purge.lock, decrements usage.json under lock, hourly full recalculation | Must | MUST | PHPUnit integration: usage recalculation |
| EXG-STORE-044 | 9.7 | Purge updates health.json, removes expired, completes deleted, removes consumed >10 min | Must | MUST | PHPUnit integration: purge effects |
| EXG-LIFE-024 | 9.7 | Purge refuses to run without valid boot.json marker | Must | MUST | PHPUnit integration: purge without marker |
| EXG-LIFE-025 | 9.7 | Opportunistic request cleanup is safety net, not replacement | Must | MUST | Manual review: deployment docs |

### 10. API V1 (§10)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-API-007 | 10 | OpenAPI 3.1 contract versioned with code, basis of contract tests | Must | MUST | CI check: OpenAPI contract tests |
| EXG-API-008 | 10 | Binary values base64url without padding | Must | MUST | PHPUnit unit: encoding validators |
| EXG-API-009 | 10 | /api/v1 stable for V1; breaking change requires /api/v2 | Must | MUST | CI check: OpenAPI breaking-change diff |
| EXG-DOC-021 | 10 | Retired API version announced one minor release ahead in changelog | Must | MUST | Manual review: changelog |
| EXG-API-010 | 10 | Create accepts only encrypted payload; no plaintext endpoint | Must | MUST | PHPUnit integration: route inventory |
| EXG-API-011 | 10 | Create body exactly aad, nonce, ciphertext, deletion_hash; no other field | Must | MUST | PHPUnit unit: unknown field rejected |
| EXG-API-012 | 10 | Create response: server id, expires_at (or null if allow_forever), server_time, link info | Must | MUST | PHPUnit integration: create response schema |
| EXG-API-013 | 10 | Raw deletion token never received or stored; only hash in request | Must | MUST | PHPUnit integration: request schema |
| EXG-API-014 | 10 | Idempotency-Key mandatory, 128-bit canonical base64url; missing gives 400 | Must | MUST | PHPUnit integration: missing key 400 |
| EXG-API-015 | 10 | Fingerprint = SHA-256 of raw body; same key+fingerprint returns same result | Must | MUST | PHPUnit integration: idempotent replay |
| EXG-API-016 | 10 | Replay succeeds even if quotas or create rate limit would now refuse | Must | MUST | PHPUnit integration: replay over quota |
| EXG-API-017 | 10 | Same key with different fingerprint returns 422 | Must | MUST | PHPUnit integration: 422 on mismatch |
| EXG-API-018 | 10 | Retry reuses identical body; re-encryption requires new key and new K_url | Must | MUST | Vitest: retry reuses bytes |
| EXG-API-019 | 10 | Idempotency record kept min(content expiry, idempotency_max_ttl ≤ 7 d), purged | Must | MUST | PHPUnit integration: record retention |
| EXG-API-020 | 10 | Record write-once, complete, after content creation, no intermediate state | Must | MUST | PHPUnit integration: record immutability |
| EXG-API-021 | 10 | Step 1: syntactic validation independent of config | Must | MUST | PHPUnit unit: validation order |
| EXG-API-022 | 10 | Step 2: existing record replayed (200) without writes, replay rate limit | Must | MUST | PHPUnit integration: replay rate limit |
| EXG-API-023 | 10 | Step 3: config checks, rate limit, quota under lock, then create | Must | MUST | PHPUnit integration: creation pipeline |
| EXG-API-024 | 10 | Step 4: record published via link(); write failure deletes content, 503 | Must | MUST | PHPUnit integration: link failure handling |
| EXG-API-025 | 10 | Step 5: link() race loser deleted; winner replayed or 422 | Must | MUST | PHPUnit integration: concurrent same key |
| EXG-API-026 | 10 | Step 6: orphan content without record purged after 15 min | Must | MUST | PHPUnit integration: orphan purge |
| EXG-API-027 | 10 | No recovery ever reuses an identifier | Must | MUST | PHPUnit integration: id never reused |
| EXG-API-028 | 10 | Idempotency record holds no plaintext, raw token or ciphertext | Must | MUST | PHPUnit integration: record content scan |
| EXG-API-029 | 10 | Challenge endpoint stateless, usage open/status, identical shape for any id | Must | MUST | PHPUnit integration: challenge uniformity |
| EXG-API-030 | 10 | /p/<id> page may embed open and status challenges | Must | MAY | PHPUnit integration: page meta challenges |
| EXG-API-031 | 10 | open body: challenge, access_pk, signature, reservation id for burn | Must | MUST | PHPUnit unit: open schema |
| EXG-API-032 | 10 | open returns ciphertext, nonce, AAD, expires_at, server_time; never plaintext | Must | MUST | PHPUnit integration: open response |
| EXG-LIFE-026 | 10 | Server sole authority on expiry | Must | MUST | PHPUnit integration: expiry enforcement |
| EXG-API-033 | 10 | open on burn content: reserve, resume same id, else 409 | Must | MUST | PHPUnit integration: open burn variants |
| EXG-API-034 | 10 | status verified before storage; returns metadata, never ciphertext or reservation | Must | MUST | PHPUnit integration: status response |
| EXG-API-035 | 10 | consume body: access_pk, reservation id, challenge, signature | Must | MUST | PHPUnit unit: consume schema |
| EXG-API-036 | 10 | consume checks A before storage, reads without lock, then locks and re-verifies | Must | MUST | PHPUnit integration: consume verification order |
| EXG-API-037 | 10 | consume atomic and idempotent for exact replay (200); other invalid proofs 404 | Must | MUST | PHPUnit integration: consume replay |
| EXG-OBS-002 | 10 | X-Deletion-Token absent from logs, traces, metrics, error responses | Must | MUST | PHPUnit integration: log scan |
| EXG-API-038 | 10 | DELETE checks token and D before storage, then hash_equals, lock | Must | MUST | PHPUnit integration: delete verification order |
| EXG-API-039 | 10 | DELETE returns 204 on success, generic 404 otherwise | Must | MUST | PHPUnit integration: delete status codes |
| EXG-API-040 | 10 | Valid DELETE removes content in any state, cancelling reservation | Must | MUST | PHPUnit integration: delete reserved content |
| EXG-API-041 | 10 | /healthz returns minimal state; no version, config, secrets, infrastructure | Must | MUST | PHPUnit integration: healthz body |
| EXG-API-042 | 10 | Status codes per endpoint as specified in response table | Must | MUST | CI check: OpenAPI contract tests |
| EXG-API-043 | 10 | Strictly typed JSON responses | Must | MUST | CI check: OpenAPI schema validation |
| EXG-API-044 | 10 | Errors as RFC 9457 problem+json with generic type/title, no internals or id | Must | MUST | PHPUnit integration: problem+json |
| EXG-API-045 | 10 | Only application/json bodies; multipart and uploads refused | Must | MUST | PHPUnit integration: 415 responses |
| EXG-API-046 | 10 | Uniform 404 for missing, expired, consumed, unavailable or invalid proof | Must | MUST | PHPUnit integration: uniform 404 matrix |
| EXG-API-047 | 10 | 409 only after valid proof; 413, 415, 429, 503 + Retry-After semantics | Must | MUST | PHPUnit integration: status codes |
| EXG-API-048 | 10 | All API responses Cache-Control no-store | Must | MUST | PHPUnit integration: headers |
| EXG-API-049 | 10 | No GET API method modifies content state | Must | MUST | PHPUnit integration: GET side effects |
| EXG-API-050 | 10 | Replay and excessive request protection; security headers on all responses | Must | MUST | PHPUnit integration: headers and rate limit |

### 11. Application security (§11)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-SEC-105 | 11 | No cookies; any future cookie Secure, HttpOnly, SameSite=Strict, CSRF-protected | Must | MUST | PHPUnit integration: no Set-Cookie |
| EXG-SEC-106 | 11 | Server-side validation of every metadata field | Must | MUST | PHPUnit unit: validators |
| EXG-SEC-107 | 11 | Size limits on body, headers and parameters | Must | MUST | PHPUnit integration: oversize headers |
| EXG-OBS-003 | 11 | Logs free of payload, key, passphrase or full URL | Must | MUST | PHPUnit integration: log scan |
| EXG-OBS-004 | 11 | Logs and traces exclude X-Deletion-Token, Idempotency-Key, Authorization, transport secrets | Must | MUST | PHPUnit integration: log scan |
| EXG-SEC-108 | 11 | Error messages reveal no internal data | Must | MUST | PHPUnit integration: error bodies |
| EXG-SEC-109 | 11 | Dependencies locked and regularly updated | Must | MUST | CI check: lockfiles and update bot |
| EXG-SEC-110 | 11 | SAST, dependency and secret scanning in CI | Must | MUST | CI check: security jobs |

### 12. UX and main journeys (§12)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-UX-117 | 12 | Journey A: no signup, local encryption notice, settings line review | Must | MUST | Playwright E2E: journey A |
| EXG-UX-118 | 12 | Journey A: validated publish sends only ciphertext; retry without re-encryption | Must | MUST | Playwright E2E: journey A network |
| EXG-UX-119 | 12 | Journey A: editor cleared; share actions shown; manage link hidden | Must | MUST | Playwright E2E: result actions |
| EXG-URL-017 | 12 | Key never displayed separately when already in link | Must | MUST | Playwright E2E: result screen content |
| EXG-READ-038 | 12 | Journey B: fragment check, proof, status, Reveal, open, decrypt, warning, render, consume | Must | MUST | Playwright E2E: journey B (multi and burn) |
| EXG-UX-120 | 12 | Journey C: understandable errors for all listed cases, nothing sensitive | Must | MUST | Playwright E2E: journey C error matrix |

### 13. Performance and compatibility (§13)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-PERF-005 | 13 | First usable UI under 2 s on decent mobile connection | Must | SHOULD | CI check: Lighthouse budget |
| EXG-SEC-111 | 13 | No framework or dependency from CDN in production | Must | MUST | CI check: build output scan |
| EXG-PERF-006 | 13 | Reasonable frontend bundle analysed on every build | Must | SHOULD | CI check: bundle size report |
| EXG-PERF-007 | 13 | Lazy-load Markdown, highlighter, QR generator, Argon2id | Must | SHOULD | CI check: chunk analysis |
| EXG-PERF-008 | 13 | Multi-read display in ≤2 round trips after page load | Must | SHOULD | Playwright E2E: request count |
| EXG-GEN-011 | 13 | Support last two major Chrome, Firefox, Safari, Edge and current iOS/Android browsers | Must | MUST | Playwright E2E: browser matrix |
| EXG-TEST-013 | 13 | Compatibility matrix verified for AES-GCM, HKDF, Ed25519, Argon2id WASM, clipboard | Must | MUST | Playwright E2E: capability matrix |
| EXG-CRYPTO-079 | 13 | Clean degradation if Web Crypto or features missing; never silent crypto downgrade | Must | MUST | Vitest: capability detection |
| EXG-PERF-009 | 13 | Capacity: 100k items, 10 GiB, 50 creates/s, 200 opens/s on reference machine | Must | SHOULD | Manual review: Phase 3 load test |
| EXG-PERF-010 | 13 | API p95 latency under 200 ms excluding network | Must | SHOULD | Manual review: Phase 3 load test |
| EXG-PERF-011 | 13 | Purge 100k expired items under 5 min without blocking | Must | SHOULD | PHPUnit integration + load test: purge benchmark |

### 14. Observability and privacy (§14)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-OBS-005 | 14 | Observability diagnoses availability without collecting content | Must | MUST | PHPUnit integration: log scan |
| EXG-OBS-006 | 14 | Allowed: request duration/status, error rate, CPU/memory, ciphertext size, aggregate metrics | Must | MAY | Manual review: log schema |
| EXG-OBS-007 | 14 | No durable IP; rate limiting uses short-lived IP HMAC | Must | SHOULD | PHPUnit integration: no IP in logs/files |
| EXG-OBS-008 | 14 | No full URL or fragment in observability data | Must | MUST | PHPUnit integration: log scan |
| EXG-OBS-009 | 14 | Avoid detailed User-Agent, correlatable ids, third-party analytics by default | Must | SHOULD | Manual review: log schema |
| EXG-OBS-010 | 14 | Log retention configurable and documented (minimal stderr JSON per §19.1) | Must | MUST | PHPUnit unit: log config; Manual review docs |

### 15. Deployment (§15)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-DEPLOY-018 | 15 | Official Docker image and demo Docker Compose file | Must | MUST | CI check: image build and compose up |
| EXG-DEPLOY-019 | 15 | Compose declares no database service | Must | MUST | CI check: compose service scan |
| EXG-DEPLOY-020 | 15 | PHP-FPM image and Nginx or Apache configuration | Must | MUST | CI check: smoke test |
| EXG-DEPLOY-021 | 15 | Customisable themes directory with example theme | Should | MUST | CI check: files present |
| EXG-DEPLOY-022 | 15 | config.php.example and full options documentation | Must | MUST | CI check: example config validates |
| EXG-DEPLOY-023 | 15 | Secrets via env/Docker Secrets; other options via config.php | Must | MUST | PHPUnit integration: config sources |
| EXG-DOC-022 | 15 | HTTPS behind reverse proxy documentation | Must | MUST | Manual review: admin docs |
| EXG-DOC-023 | 15 | Backup/restore and update/rollback procedures | Must | MUST | Manual review: admin docs |
| EXG-DEPLOY-024 | 15 | Reference storage configuration and permissions | Must | MUST | Manual review: deployment files |
| EXG-STORE-045 | 15 | Storage file format versioning and compatibility strategy | Must | MUST | PHPUnit unit: schema_version migration |
| EXG-DEPLOY-025 | 15 | SBOM and signed artefact verification procedure | Must | MUST | CI check: release job |
| EXG-DOC-024 | 15 | Documented HTTP hardening configuration | Must | MUST | Manual review: admin docs |
| EXG-DEPLOY-026 | 15 | Image runs as non-root; read-only FS where compatible | Must | MUST | CI check: container inspect |
| EXG-DEPLOY-027 | 15 | Healthcheck and graceful shutdown | Must | MUST | CI check: compose healthcheck and SIGTERM |
| EXG-DEPLOY-028 | 15 | Compose purge service every 60 s, hardened, no cron; systemd timer | Must | MUST | CI check: compose purge service |

### 15.1 Operations tooling (§15.1, spec v0.22)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-OPS-001 | 15.1 | `app:boot --dry-run` runs every check and creates, writes or deletes nothing (missing directories reported as warnings) | Should | MUST | PHPUnit integration: dry run on empty and existing stores leaves the filesystem unchanged |
| EXG-OPS-002 | 15.1 | `app:boot` reports a world-readable secret file or configuration, unmeasurable inodes and `post_max_size` below `http.max_request_bytes`, and refuses storage directories accessible to other accounts | Should | MUST | PHPUnit unit: Booter warnings and mode errors; DiskProbe inode parsing |
| EXG-OPS-003 | 15.1 | Console exit codes documented (0 success, 1 failure, 2 usage or not booted) and JSON output for `app:boot` and `app:config:check`, never showing the secret | Should | MUST | PHPUnit integration: CommandTester exit codes and JSON documents |
| EXG-OPS-004 | 15.1 | Operations procedures documented in README-admin (systemd, backup/restore and restore test, permissions, upgrade/rollback, verification, troubleshooting, incident procedure) | Should | MUST | Manual review: README-admin followed from scratch |
| EXG-OPS-005 | 15.1 | Operations log events (`health_stale`, `boot_marker_mismatch`, `purge_failures`) throttled and without identifiers, path or address | Should | MUST | PHPUnit integration: log scan of operations events |
| EXG-OPS-006 | 15.1 | `app:secret:generate --output` writes a new file 0600 (0640 with `--group-readable`) without printing the secret; existing file replaced only with `--force` | Should | MUST | PHPUnit integration: CommandTester output file mode and refusal |
| EXG-OPS-007 | 15.1 | Docker hygiene: app healthcheck on boot status, purge stop signal, build context exclusions, Nginx spool space and `emerg` error log | Should | MUST | PHPUnit unit: compose.yaml, .dockerignore and Nginx configuration checks |
| EXG-OPS-008 | 15.1 | Local validation in Docker (`tools/docker/qa.sh`) and Docker smoke test of the Compose stack (`tools/docker/smoke.sh`) | Should | MUST | CI check: tools/docker scripts run green before release |

### 16. Tests and validation (§16)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-TEST-014 | 16.0 | Red-Green-Refactor cycle followed | Must | MUST | Manual review: PR history |
| EXG-TEST-015 | 16.0 | No feature merged without associated tests | Must | MUST | CI check: required checks on PR |
| EXG-TEST-016 | 16.0 | Tests written before production code for new features | Must | MUST | Manual review: commit order |
| EXG-TEST-017 | 16.0 | Each functional requirement has at least one acceptance test (this matrix) | Must | MUST | CI check: traceability matrix lint |
| EXG-TEST-018 | 16.0 | Each security rule has an automated test when technically possible | Must | MUST | CI check: traceability matrix lint |
| EXG-TEST-019 | 16.0 | Crypto protocol developed from test vectors before implementation | Must | MUST | Manual review: Phase 0 gate |
| EXG-TEST-020 | 16.0 | Frontend, backend, CLI share contract tests on encrypted format | Must | MUST | CI check: shared vector jobs |
| EXG-TEST-021 | 16.0 | Tests deterministic and order-independent | Must | MUST | CI check: random-order runs |
| EXG-TEST-022 | 16.0 | Tests never use real secrets or personal data | Must | MUST | CI check: secret scanning on tests |
| EXG-TEST-023 | 16.0 | CI blocks merge on failing tests, static analysis or security checks | Must | MUST | CI check: branch protection |
| EXG-TEST-024 | 16.0 | Coverage is complementary, not a substitute for test quality | Must | MUST | Manual review: review guidelines |
| EXG-TEST-025 | 16.1 | Tests: create/read, config loading, invalid and incoherent config handling | Must | MUST | PHPUnit integration + Playwright E2E |
| EXG-TEST-026 | 16.1 | Tests: i18n default, detection, fallbacks, manual switch, ltr/lang attributes | Must | MUST | Vitest + Playwright E2E |
| EXG-TEST-027 | 16.1 | Tests: LTR languages display correctly on mobile and desktop | Must | MUST | Vitest + Playwright E2E |
| EXG-TEST-028 | 16.1 | Tests: no-account flow, double-click prevention, input kept on error | Must | MUST | Vitest + Playwright E2E |
| EXG-TEST-029 | 16.1 | Tests: leave warning, no draft storage, input attributes, envelope gauge | Must | MUST | Vitest + Playwright E2E |
| EXG-TEST-030 | 16.1 | Tests: clipboard user-triggered only, denied fallback, never persisted/logged | Must | MUST | Vitest + PHPUnit integration |
| EXG-TEST-031 | 16.1 | Tests: files, uploads, drag-drop and multipart refused | Must | MUST | Vitest + PHPUnit integration |
| EXG-TEST-032 | 16.1 | Tests: recoverable errors, touch targets, background hiding, text states | Must | MUST | Playwright E2E |
| EXG-TEST-033 | 16.1 | Tests: worker non-blocking, targeted pull-to-refresh, focus and aria-live | Must | MUST | Playwright E2E |
| EXG-TEST-034 | 16.1 | Tests: contextual mobile bar and Escape hides content | Must | MUST | Playwright E2E |
| EXG-TEST-035 | 16.1 | Tests: each expiry, manual deletion, DELETE reserved then consume 404 | Must | MUST | PHPUnit integration |
| EXG-TEST-036 | 16.1 | Tests: exact consume replay succeeds within 10 minutes | Must | MUST | PHPUnit integration |
| EXG-TEST-037 | 16.1 | Tests: concurrent burn opens, reservation timeout/release, counter, threshold | Must | MUST | PHPUnit integration + Playwright E2E |
| EXG-TEST-038 | 16.1 | Tests: reservation resume, local passphrase check, no open before Reveal | Must | MUST | PHPUnit integration + Playwright E2E |
| EXG-TEST-039 | 16.1 | Tests: uniform 404, 409 only after proof, id-only access blocked | Must | MUST | PHPUnit integration |
| EXG-TEST-040 | 16.1 | Tests: uniform challenge, no storage or timing leak on invalid proof | Must | MUST | PHPUnit integration |
| EXG-TEST-041 | 16.1 | Tests: single client retry on open/status 404 | Must | MUST | PHPUnit integration |
| EXG-TEST-042 | 16.1 | Tests: secret rotation during reservation; secret file visible to workers | Must | MUST | PHPUnit integration |
| EXG-TEST-043 | 16.1 | Tests: per-id limit counts valid proofs only; trusted proxy handling | Must | MUST | PHPUnit integration |
| EXG-TEST-044 | 16.1 | Tests: IPv4-mapped normalisation, IPv6 /64 grouping, UTC day rollover | Must | MUST | PHPUnit integration |
| EXG-TEST-045 | 16.1 | Tests: usage.json quotas without scan, purge recalculation, near-quota concurrency | Must | MUST | PHPUnit integration |
| EXG-TEST-046 | 16.1 | Tests: stale health.json blocks creation and degrades /healthz | Must | MUST | PHPUnit integration |
| EXG-TEST-047 | 16.1 | Tests: storage CRUD without DB, flock concurrency, inode re-check | Must | MUST | PHPUnit integration |
| EXG-TEST-048 | 16.1 | Tests: incomplete dirs removed, no per-request or recreated lock files | Must | MUST | PHPUnit integration |
| EXG-TEST-049 | 16.1 | Tests: payload removal, temp/rename recovery, path and symlink refusal | Must | MUST | PHPUnit integration |
| EXG-TEST-050 | 16.1 | Tests: app:boot blocks FPM; marker mismatch 503; config change applied | Must | MUST | PHPUnit integration |
| EXG-TEST-051 | 16.1 | Tests: purge refused without marker; concurrent purge exits immediately | Must | MUST | PHPUnit integration |
| EXG-TEST-052 | 16.1 | Tests: single link() publish; concurrent same key yields one id | Must | MUST | PHPUnit integration |
| EXG-TEST-053 | 16.1 | Tests: crash orphan purged after 15 min; 422 race; link failure 503 | Must | MUST | PHPUnit integration |
| EXG-TEST-054 | 16.1 | Tests: replay under quota/limit; no record for invalid request; TTL purge | Must | MUST | PHPUnit integration |
| EXG-TEST-055 | 16.1 | Tests: identical retry body; missing Idempotency-Key refused | Must | MUST | PHPUnit integration |
| EXG-TEST-056 | 16.1 | Tests: A‖D‖R layout, client A/D checks, resubmission gets new id | Must | MUST | PHPUnit integration + Vitest |
| EXG-TEST-057 | 16.1 | Tests: id in body 400; weak keys refused; no storage on mismatch | Must | MUST | PHPUnit integration + Vitest |
| EXG-TEST-058 | 16.1 | Tests: AAD access_pk and open/status mismatch; closed codes; canonical base64url | Must | MUST | Shared vectors + PHPUnit unit |
| EXG-TEST-059 | 16.1 | Functional tests: response codes per §10 table for every endpoint | Must | MUST | CI check: OpenAPI contract tests |
| EXG-TEST-060 | 16.1 | Tests: lone surrogates browser to CLI; localised relative expiry from server | Must | MUST | Vitest + Playwright E2E |
| EXG-TEST-061 | 16.1 | Tests: countdown RTT correction and resync; consume failure keeps content | Must | MUST | Vitest + Playwright E2E |
| EXG-TEST-062 | 16.1 | Tests: page CSP never wasm-unsafe-eval; worker CSP always has it | Must | MUST | PHPUnit integration + Playwright E2E |
| EXG-TEST-063 | 16.1 | Tests: passphrase correct/incorrect, CSPRNG generation, no silent replace | Must | MUST | Vitest + Playwright E2E |
| EXG-TEST-064 | 16.1 | Tests: word passphrase ≥66 bits, double entry, masked field, Argon2id unsupported | Must | MUST | Vitest + Playwright E2E |
| EXG-TEST-065 | 16.1 | Tests: CLI /dev/tty prompts, refused combinations, no-TTY refusal, /run/secrets | Must | MUST | PHPUnit integration |
| EXG-TEST-066 | 16.1 | Tests: CLI metadata/decrypt split, burn consume, no argv/env passphrase | Must | MUST | PHPUnit integration |
| EXG-TEST-067 | 16.1 | Tests: result screen clears text, settings line, presets, copy with message | Must | MUST | Playwright E2E |
| EXG-TEST-068 | 16.1 | Tests: native share, manage link separation, header-only token, new-content warning | Must | MUST | Playwright E2E |
| EXG-TEST-069 | 16.1 | Tests: burn test-link warning and send-failure retry/cancel | Must | MUST | Playwright E2E |
| EXG-TEST-070 | 16.1 | Tests: manage page uniform result; truncated link detection without request | Must | MUST | Playwright E2E |
| EXG-TEST-071 | 16.1 | Tests: burn close warning, inactivity hide, Reveal challenge renewal, passphrase resume | Must | MUST | Playwright E2E |
| EXG-TEST-072 | 16.1 | Tests: Markdown/code rendering, code block copy, no images rendered | Should | MUST | Vitest + Playwright E2E |
| EXG-TEST-073 | 16.1 | Tests: link scheme filter, external indicator, no link preview | Should | MUST | Vitest + Playwright E2E |
| EXG-TEST-074 | 16.1 | Tests: template confirmation, field copy/masking, Wi-Fi QR escaping | Should | MUST | Vitest + Playwright E2E |
| EXG-TEST-075 | 16.1 | Tests: near-1 MiB envelope responsive; highlighting off above threshold | Should | MUST | Playwright E2E |
| EXG-TEST-076 | 16.1 | Functional tests: accessibility at 200%/400% zoom, keyboard only, screen reader | Must | MUST | Playwright E2E + Manual review |
| EXG-TEST-077 | 16.1 | Tests: slow 3G and disconnects during send/status/open/consume, then recovery | Must | MUST | Playwright E2E: network throttling |
| EXG-TEST-078 | 16.1 | Functional tests: themes (change without code, light/dark/custom, contrast per theme) | Should | MUST | Playwright E2E + CI contrast check |
| EXG-TEST-079 | 16.1 | Tests: copy, paste, local QR (white, quiet zone, share link only) | Should | MUST | Vitest + Playwright E2E |
| EXG-TEST-080 | 16.1 | Tests: print off by default; warning and print CSS when enabled | Could | MUST | Playwright E2E |
| EXG-TEST-081 | 16.1 | Tests: manifest without Service Worker, offline mode or standalone display | Could | MUST | Playwright E2E |
| EXG-TEST-082 | 16.1 | Functional tests: backup from snapshot or stopped service restores consistently | Must | MUST | Manual review: restore drill |
| EXG-TEST-083 | 16.1 | Functional tests: mobile journey and absence of account | Must | MUST | Playwright E2E |
| EXG-TEST-084 | 16.2 | Server never receives plaintext; key never in any HTTP request | Must | MUST | Playwright E2E: network capture assertions |
| EXG-TEST-085 | 16.2 | Single-bit ciphertext and authenticated metadata tampering detected; no decryption without key | Must | MUST | Shared vectors: negative cases |
| EXG-TEST-086 | 16.2 | Argon2id vectors (p=1, 16-byte salt) identical browser vs sodium_crypto_pwhash; out-of-bounds refused | Must | MUST | Shared vectors: Argon2id |
| EXG-TEST-087 | 16.2 | HKDF vectors for PRK_url, PRK_content, K_enc, K_access_seed, K_consume_seed with/without passphrase | Must | MUST | Shared vectors: HKDF |
| EXG-TEST-088 | 16.2 | Ed25519 and PKCS#8 vectors; Web Crypto vs JS fallback cross-verified | Must | MUST | Shared vectors + Playwright E2E |
| EXG-TEST-089 | 16.2 | Signed message vectors; wrong usage, id, expiry or HMAC rejected | Must | MUST | PHPUnit unit: challenge negatives |
| EXG-TEST-090 | 16.2 | NFC normalisation identical browser vs CLI | Must | MUST | Shared vectors: NFC |
| EXG-TEST-091 | 16.2 | AAD canonicalisation identical PHP/JS; RFC 8785 vectors; rejection cases | Must | MUST | Shared vectors: AAD |
| EXG-TEST-092 | 16.2 | Detect K_url substitution and burn flag removal in AAD | Must | MUST | Shared vectors: substitution |
| EXG-TEST-093 | 16.2 | CLI AES-256-GCM via ext-openssl matches browser vectors; Web Crypto/local/CLI comparison | Must | MUST | Shared vectors: AES-GCM |
| EXG-TEST-094 | 16.2 | Passphrase mode explicitly disabled when validated KDF unavailable | Must | MUST | Vitest: capability fallback |
| EXG-TEST-095 | 16.2 | Shared vectors across frontend, backend, CLI; cross-version protocol compatibility tests | Must | MUST | CI check: vector jobs |
| EXG-TEST-096 | 16.3 | Dependency review, Composer/npm audit, SBOM generation | Must | MUST | CI check: audit jobs |
| EXG-TEST-097 | 16.3 | Secret scanning, SAST and DAST | Must | MUST | CI check: security jobs |
| EXG-TEST-098 | 16.3 | XSS and Markdown injection tests; no style attributes from renderer, highlighter, QR | Must | MUST | Vitest: XSS suite |
| EXG-TEST-099 | 16.3 | Rate limiting, id enumeration, replay, uniform public response tests | Must | MUST | PHPUnit integration: abuse suite |
| EXG-TEST-100 | 16.3 | Idempotency and reused-key rejection tests | Must | MUST | PHPUnit integration: idempotency suite |
| EXG-TEST-101 | 16.3 | Deletion token and plaintext IPs absent from logs, traces, metrics | Must | MUST | PHPUnit integration: log audit |
| EXG-TEST-102 | 16.3 | Storage permissions and no direct access from public/; no PHP/config in public/ | Must | MUST | CI check: layout and perms |
| EXG-TEST-103 | 16.3 | No database service required by deployment | Must | MUST | CI check: compose and image scan |
| EXG-TEST-104 | 16.3 | Header audit: reference CSP, worker CSP, no blob:, Permissions-Policy | Must | MUST | PHPUnit integration: header audit |
| EXG-TEST-105 | 16.3 | No third-party resources; Cache-Control verified behind proxy and browser | Must | MUST | CI check + PHPUnit integration |
| EXG-TEST-106 | 16.3 | Browser and crypto capability compatibility matrix | Must | MUST | Playwright E2E: browser matrix |
| EXG-TEST-107 | 16.3 | Docker image non-root (mandatory) and read-only filesystem verified | Must | MUST | CI check: container inspect |
| EXG-TEST-108 | 16.3 | Control borders ≥3:1 and offset focus ring visible on primary | Must | MUST | CI check: contrast suite |
| EXG-TEST-109 | 16.3 | Release signature and hash verification; reproducible build test | Must | MUST | CI check: release verification |
| EXG-TEST-110 | 16.4 | Acceptance: no plaintext received/stored; no file upload or multipart accepted | Must | MUST | Manual review: release gate |
| EXG-TEST-111 | 16.4 | Acceptance: crypto covered by automated tests; burn concurrency-safe with only available/reserved/consumed (+deleted) | Must | MUST | Manual review: release gate |
| EXG-TEST-112 | 16.4 | Acceptance: no recreation under existing link; expired links give no ciphertext | Must | MUST | Manual review: release gate |
| EXG-TEST-113 | 16.4 | Acceptance: no full URL, key or raw deletion token logged or stored | Must | MUST | Manual review: release gate |
| EXG-TEST-114 | 16.4 | Acceptance: idempotency without duplicates; public errors indistinguishable | Must | MUST | Manual review: release gate |
| EXG-TEST-115 | 16.4 | Acceptance: no database; local file volume outside web root, restrictive perms | Must | MUST | Manual review: release gate |
| EXG-TEST-116 | 16.4 | Acceptance: security headers in production; no third-party resource; non-root container | Must | MUST | Manual review: release gate |
| EXG-TEST-117 | 16.4 | Acceptance: verifiable dependencies and artefacts; reproducible admin, developer and Docker installs | Must | MUST | Manual review: release gate |
| EXG-TEST-118 | 16.4 | Acceptance: tests-first traceability; all Must rows have green tests | Must | MUST | CI check: traceability report |
| EXG-TEST-119 | 16.4 | Acceptance: works on mobile and desktop; WCAG 2.2 AA on main flows | Must | MUST | Manual review: release gate |
| EXG-TEST-120 | 16.4 | Acceptance: internal security review before production; external audit before first public release | Must | MUST | Manual review: audit reports |
| EXG-TEST-121 | 16.4 | Acceptance: no data without link proof; id-only party learns nothing | Must | MUST | Manual review: release gate |
| EXG-TEST-122 | 16.4 | Acceptance: unconfirmed opens signalled without false positives (wrong passphrase, reload, secret rotation) | Must | MUST | PHPUnit integration + Playwright E2E |
| EXG-TEST-123 | 16.4 | Acceptance: app:boot gate and config fingerprint enforced | Must | MUST | Manual review: release gate |
| EXG-TEST-124 | 16.4 | Acceptance: no reservation before Reveal and local passphrase check; inputs disable spellcheck/autocomplete | Must | MUST | Manual review: release gate |
| EXG-TEST-125 | 16.4 | Acceptance: Phase 3 user tests done and blocking issues fixed | Must | MUST | Manual review: user test report |
| EXG-TEST-126 | 16.4 | Acceptance: quotas and bounded idempotency retention prevent unbounded growth | Must | MUST | Manual review: release gate |
| EXG-TEST-127 | 16.4 | Acceptance: opens on all matrix browsers incl. without native Ed25519 | Must | MUST | Playwright E2E: browser matrix |
| EXG-TEST-128 | 16.4 | Acceptance: no concurrent operation serves or reserves deleted content | Must | MUST | Manual review: release gate |
| EXG-TEST-129 | 16.4 | Acceptance: clipboard explicit, crypto states accessible, expiry display uses server_time | Must | MUST | Manual review: release gate |
| EXG-TEST-130 | 16.4 | Acceptance: local QR, print off, dangerous links blocked, no SW | Must | MUST | Manual review: release gate |

### 17. Deliverables (§17)

| ID | § | Summary | Priority | Level | Planned test |
|---|---|---|---|---|---|
| EXG-DOC-025 | 17 | Deliver web frontend, backend API, CLI (versioned PHAR and Docker image) | Must | MUST | CI check: release artefacts |
| EXG-DOC-026 | 17 | Documented protocol format with public test vectors | Must | MUST | Manual review: protocol docs |
| EXG-DOC-027 | 17 | OpenAPI 3.1 specification | Must | MUST | CI check: spec lint |
| EXG-DOC-028 | 17 | Requirements-to-tests traceability matrix (this document) | Must | MUST | CI check: matrix lint |
| EXG-DOC-029 | 17 | Unit, integration, E2E (Playwright per §19.1), security tests; PHPUnit and static analysis | Must | MUST | CI check: test jobs |
| EXG-DOC-030 | 17 | English and French translation catalogues | Must | MUST | CI check: catalogue completeness |
| EXG-DOC-031 | 17 | Docker image and Compose | Must | MUST | CI check: image build |
| EXG-DOC-032 | 17 | Cache strategy documentation and controlled purge command | Must | MUST | Manual review: docs |
| EXG-DOC-033 | 17 | Commands app:boot, app:config:check, app:secret:generate, app:purge-expired; app:theme:preview (Could) | Must | MUST | PHPUnit integration: command list |
| EXG-DOC-034 | 17 | User documentation | Must | MUST | Manual review: docs |
| EXG-DOC-035 | 17 | Instance install and operations documentation without backoffice | Must | MUST | Manual review: README-admin |
| EXG-DOC-036 | 17 | Security documentation and threat model | Must | MUST | Manual review: docs |
| EXG-DOC-037 | 17 | Root SECURITY.md with vulnerability reporting and handling policy | Must | MUST | CI check: file present |
| EXG-DOC-038 | 17 | Root CODE_OF_CONDUCT.md | Must | MUST | CI check: file present |
| EXG-DOC-039 | 17 | Root LICENSE (AGPL-3.0) and SPDX header AGPL-3.0-or-later in each source file | Must | MUST | CI check: SPDX header lint |
| EXG-GEN-012 | 17 | UI links to source of deployed version (AGPL §13) | Must | MUST | Playwright E2E: source link present |
| EXG-DOC-040 | 17 | Vulnerability disclosure policy, contribution guide, changelog, versioning strategy | Must | MUST | CI check: files present |
| EXG-DOC-041 | 17.1 | READMEs written in English | Must | MUST | Manual review: docs language |
| EXG-DOC-042 | 17.1 | README-admin explains installing and operating an instance without backoffice | Must | MUST | Manual review: README-admin |
| EXG-DOC-043 | 17.1 | README-admin covers prerequisites, Docker/Compose, PHP-FPM, Nginx/Apache, dirs and perms, config files, env/secrets | Must | MUST | Manual review: README-admin checklist |
| EXG-DOC-044 | 17.1 | README-admin covers app secret generation and rotation consequences | Must | MUST | Manual review: README-admin checklist |
| EXG-DOC-045 | 17.1 | README-admin covers themes, languages, storage maintenance, HTTPS/HSTS/proxy, caches, purge, rate limits, logs | Must | MUST | Manual review: README-admin checklist |
| EXG-DOC-046 | 17.1 | README-admin covers backup/restore, update/format evolution/rollback, healthcheck, hardening, release verification, incidents, security limits | Must | MUST | Manual review: README-admin checklist |
| EXG-DOC-047 | 17.1 | README-admin documents no backoffice screen or endpoint | Must | MUST | Manual review: README-admin |
| EXG-DOC-048 | 17.2 | README-developer enables understanding, testing, modifying without implicit knowledge | Must | MUST | Manual review: README-developer |
| EXG-DOC-049 | 17.2 | README-developer covers setup, architecture, protocol, API, storage, local config | Must | MUST | Manual review: README-developer checklist |
| EXG-DOC-050 | 17.2 | README-developer covers themes, i18n, all test suites, PHPStan/linters/audits/SBOM, no-plaintext rules, builds, signing | Must | MUST | Manual review: README-developer checklist |
| EXG-DOC-051 | 17.2 | README-developer covers PSR conventions, adding endpoint/storage change/template/language/theme, security and code review | Must | MUST | Manual review: README-developer checklist |
| EXG-DOC-052 | 17.2 | Both READMEs regularly tested from a clean environment | Must | MUST | CI check: clean-environment doc run |
| EXG-DOC-053 | 17.2 | README-developer mandates Red-Green-Refactor and documents local and CI test commands | Must | MUST | Manual review: README-developer |
| EXG-DOC-054 | 17.3 | Root SECURITY.md visible, maintained each release, in English | Must | MUST | CI check: file present |
| EXG-DOC-055 | 17.3 | SECURITY.md: private channel, no public disclosure, supported versions, scope | Must | MUST | Manual review: SECURITY.md checklist |
| EXG-DOC-056 | 17.3 | SECURITY.md: threat limits, no secrets in reports, own-instance testing | Must | MUST | Manual review: SECURITY.md checklist |
| EXG-DOC-057 | 17.3 | SECURITY.md: response timelines, coordinated disclosure/CVE, credit, urgent incident procedure | Must | MUST | Manual review: SECURITY.md checklist |
| EXG-DOC-058 | 17.3 | SECURITY.md states no database; contains no secrets or personal data | Must | MUST | Manual review + CI secret scan |
| EXG-DOC-059 | 17.4 | Root CODE_OF_CONDUCT.md visible, applies to all project spaces | Must | MUST | CI check: file present |
| EXG-DOC-060 | 17.4 | CoC: expected and forbidden behaviours, private reporting with confidentiality | Must | MUST | Manual review: CoC checklist |
| EXG-DOC-061 | 17.4 | CoC: secret-free reports, handling, sanctions, anti-retaliation, credit, contact | Must | MUST | Manual review: CoC checklist |

## Summary

Total requirements: **913**.

### Count per domain and priority

| Domain | Must | Should | Could | Total |
|---|---|---|---|---|
| A11Y | 22 | 0 | 0 | 22 |
| API | 50 | 0 | 0 | 50 |
| CACHE | 19 | 0 | 0 | 19 |
| CLI | 19 | 0 | 0 | 19 |
| CONF | 31 | 0 | 0 | 31 |
| CRYPTO | 79 | 0 | 0 | 79 |
| DEPLOY | 27 | 1 | 0 | 28 |
| DOC | 61 | 0 | 0 | 61 |
| GEN | 12 | 0 | 0 | 12 |
| I18N | 19 | 0 | 0 | 19 |
| LIFE | 26 | 0 | 0 | 26 |
| MD | 1 | 24 | 3 | 28 |
| OBS | 10 | 0 | 0 | 10 |
| OPS | 0 | 8 | 0 | 8 |
| PERF | 10 | 1 | 0 | 11 |
| PWA | 2 | 0 | 5 | 7 |
| READ | 38 | 0 | 0 | 38 |
| SEC | 109 | 2 | 0 | 111 |
| STORE | 46 | 0 | 0 | 46 |
| TEST | 122 | 6 | 2 | 130 |
| THEME | 0 | 20 | 1 | 21 |
| URL | 17 | 0 | 0 | 17 |
| UX | 97 | 14 | 9 | 120 |
| **Total** | **817** | **76** | **20** | **913** |

## Ambiguities

These items have an unclear priority, level or testability. They are recorded here so the project owner can decide; this matrix does not resolve them.

1. **General UX rules missing from §0.3.** The ergonomic rules in §5.1 and §6.4 (for example EXG-UX-032 keyboard shortcuts, EXG-UX-033 "what is sent" panel, EXG-UX-034 "how it works" diagram, EXG-UX-054 Argon2id duration hint, EXG-UX-067 result countdown, EXG-UX-082 clock skew algorithm) are not listed in §0.3. Here they are recorded as Must because they belong to the main flows and to accessibility. Some of them could reasonably be Should instead.
2. **Spec text out of step with §19.1 decisions.** §6.5 still shows `--color-*` tokens, but §19.1 adopts `--ql-*` (EXG-THEME-007). §17 leaves the SPDX choice between `-or-later` and `-only` open, while §19.1 picks `AGPL-3.0-or-later` (EXG-DOC-039). §5.1 still describes secret detection as a V1 Could, while §19.1 defers it to V1.1 (EXG-UX-030). The CDC should be updated so the two stay consistent.
3. **Security defaults inside Could features.** The rule "printing off by default and print CSS hides decrypted content" (EXG-UX-109, EXG-UX-110) and the manifest rules (EXG-PWA-005) sit in Could sections, yet they protect plaintext. It is unclear whether the print-hiding stylesheet is mandatory when the print feature is not delivered. The "no Service Worker" rule is recorded as Must (EXG-PWA-001).
4. **Performance targets (§13).** "Objectifs" carries no explicit « doit »: EXG-PERF-005 and EXG-PERF-009 to EXG-PERF-011 are recorded as SHOULD. They can only be tested once the Phase 3 reference machine and network profile exist, and it is unclear whether missing them blocks the release.
5. **"As far as possible" requirements.** Under §0.1 these apply by default, with any deviation documented. Pass/fail criteria are not defined for EXG-SEC-062 (reproducible builds), EXG-DEPLOY-005 (seccomp, limited egress), EXG-SEC-010 (hiding content when the app goes to the background) and EXG-MD-022 (showing the target domain).
6. **Capability lists phrased with « peut ».** §6.1 and §6.2 ("l’utilisateur peut…", EXG-UX-097 onwards) use the MAY wording of §0.1 but describe mandatory features. They are recorded as MUST.
7. **Markdown versus security priority.** Markdown is Should (§0.3), but sanitisation is a security rule. Renderer configuration (EXG-MD-026) is Should, while forbidding scripts and iframes (EXG-SEC-072) is Must. If Markdown were waived, it is unclear which rows would still apply.
8. **Three audit and review gates.** EXG-CRYPTO-015 (crypto review before public beta), EXG-SEC-083 (security audit before production) and EXG-SEC-084 (targeted external audit before the first public release) may overlap or be one and the same gate. Their order and scope need to be confirmed.
9. **Process requirements that only allow manual verification.** TDD order (EXG-TEST-001, EXG-TEST-010, EXG-TEST-014), Phase 3 user tests (EXG-TEST-125) and lock contention measurement (EXG-PERF-004) cannot be fully automated. Their evidence format (commit history, reports) needs to be defined.
10. **Observability rules phrased « à éviter ».** EXG-OBS-007 and EXG-OBS-009 are SHOULD, but the same data (plaintext IP, URL) is forbidden elsewhere as MUST (§9.2, §11). The stronger rule is assumed to win.
11. **Accessibility scope.** WCAG 2.2 AA is Must for the "main flows" only (EXG-A11Y-016). The A11Y rows for secondary screens (manage page, theme preview, print) are recorded as Must, but §0.3 does not settle this.
12. **Rendering threshold.** The 200 KiB limit for highlighting and rich rendering (EXG-PERF-003) is called a "documented threshold". It is unclear whether this value is fixed or configurable, and it inherits Should from Markdown.

