# QuietLink protocol `sp-proto/v1`

Status: **Draft for Phase 0 freeze.** This document restates, in normative English, the cryptographic protocol defined by the French specification `docs/cahier-des-charges.md` (v0.18, hereafter "CDC"). It adds no normative value of its own. Every value carries its CDC source as `§section, L<line>`. Where the CDC is silent or ambiguous, the gap is recorded in [§16 Open questions](#16-open-questions) as `OQ-n`; any "Proposed:" text there is **non-normative** until the CDC is amended.

Values marked *(derived)* are arithmetic consequences of normative CDC values (for example a base64url character count computed from a byte length); they introduce no new decision.

---

## 1. Scope and notation

### 1.1 Scope

This document covers the data formats and computations that the browser frontend (TypeScript), the backend (PHP) and the CLI (PHP) MUST implement identically:

- key material and HKDF derivations;
- the passphrase KDF (Argon2id);
- the encrypted envelope and the canonical AAD;
- public identifier construction, URL format and deletion token binding;
- the access and consumption proofs (Ed25519 challenge/response);
- the read and burn-after-reading flow at protocol level;
- versioning and test vector requirements.

Storage layout, rate limiting, quotas and UI behaviour are out of scope, except where they affect the wire protocol.

The protocol identifier is `sp-proto/v1`. It is frozen in Phase 0 and does not depend on the product name; renaming the product does not change the protocol (§0.4, L49).

### 1.2 Requirement keywords

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD", "SHOULD NOT", "RECOMMENDED", "MAY" and "OPTIONAL" are to be interpreted as described in RFC 2119 and RFC 8174 when, and only when, they appear in all capitals.

### 1.3 Notation and byte conventions

| Notation | Meaning |
|---|---|
| `‖` or `\|\|` | Byte-string concatenation (CDC uses both: §6.3.1, L442–455; §8.2, L997). |
| `"text"` | The ASCII bytes of the string, **with no length prefix and no terminating NUL**. CDC: "octets ASCII" (§8.2, L992; §6.3.1, L455). See OQ-1 for the absence of an explicit "no terminator" statement. |
| `0x00` | A single byte with value zero (separator) (§6.3.1, L455; §8.2, L972). |
| `tronc64(x)` | The first 8 bytes of byte string `x` (§8.2, L972). |
| `SHA-256(x)` | SHA-256 digest, 32 bytes. |
| `HMAC-SHA-256(k, m)` | HMAC with SHA-256, 32-byte output (§6.3.1, L447). |
| `HKDF-Extract`, `HKDF-Expand` | RFC 5869 with SHA-256 (§8.2, L994–1001). |
| Integers | `issued_at` is an unsigned 64-bit big-endian integer (§6.3.1, L445). No other multi-byte integer is serialized in binary form by this protocol. |
| Byte index | Unless stated otherwise, this document uses **0-based** indices `x[a..b)` (start inclusive, end exclusive). The CDC uses 1-based wording ("octets 9 à 16", §10, L1600), equivalent to `id[8..16)`. |
| Text | All text encodings are UTF-8. |
| `base64url` | See §3. |

---

## 2. Primitives and parameters

| Purpose | Primitive | Parameters | PHP (backend, CLI) | Browser | Source |
|---|---|---|---|---|---|
| Content encryption | AES-256-GCM | 256-bit key, 96-bit nonce, 128-bit tag appended to ciphertext | `ext-openssl` (`openssl_encrypt`/`openssl_decrypt`, `aes-256-gcm`) | Web Crypto `AES-GCM` | §8.2, L981–982; CLAUDE.md stack |
| Key derivation | HKDF-SHA-256 (RFC 5869) | salt `"sp-proto/v1/hkdf"`, L = 32 | `hash_hkdf('sha256', IKM, 32, info, salt)` | Web Crypto `HKDF` `deriveBits` | §8.2, L992–1007 |
| Challenge MAC | HMAC-SHA-256 | key `K_challenge` (32 bytes) | `hash_hmac('sha256', …, true)` | not used (server only) | §6.3.1, L447–449 |
| Hash | SHA-256 | — | `hash('sha256', …, true)` | Web Crypto `SHA-256` | §8.2, L972, L984 |
| Signatures | Ed25519, pure (RFC 8032, no pre-hash) | 32-byte seed, 32-byte public key, 64-byte signature *(derived from RFC 8032)* | `ext-sodium` (`sodium_crypto_sign_seed_keypair`, `sodium_crypto_sign_detached`, `sodium_crypto_sign_verify_detached`) | Web Crypto Ed25519 when present and conformant to vectors, otherwise `@noble/ed25519` (pure JavaScript) | §8.4, L1116; §6.3.1, L452 |
| Passphrase KDF | Argon2id v1.3 (`argon2id13`) | `m` KiB, `t` passes, `p = 1`, 16-byte salt, 32-byte output | `ext-sodium` `sodium_crypto_pwhash(32, pw, salt, t, m*1024, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13)` | `hash-wasm` in a dedicated worker | §8.3, L1085–1093 |
| Unicode normalisation (passphrase) | NFC | — | `Normalizer::normalize($p, Normalizer::FORM_C)` (`ext-intl`, CLI) | `String.prototype.normalize('NFC')` | §8.3, L1091 |
| Randomness | CSPRNG | — | `random_bytes` | `crypto.getRandomValues` | §8.1, L960; §8.2, L972 |

### 2.1 Argon2id parameters

| Parameter | Default | Protocol bounds (not configurable) | Source |
|---|---|---|---|
| `m` (KiB) | 65 536 (64 MiB) | 19 456 ≤ m ≤ 262 144 | §8.3, L1094, L1100 |
| `t` (passes) | 3 | 2 ≤ t ≤ 10 | §8.3, L1094, L1100 |
| `p` | 1 | MUST be 1 | §8.3, L1092 |
| salt | 16 random bytes | exactly 16 bytes | §8.3, L1090 |
| output length | 32 bytes | — | §8.3, L1091 |

The defaults are subject to confirmation by the Phase 0 calibration on supported mobile devices (§8.3, L1100). libsodium mapping: `memlimit = m × 1024` bytes, `opslimit = t` (§8.3, L1093).

### 2.2 Rules applying to all primitives

- Implementations MUST use primitives from a recognised library or Web Crypto and MUST NOT implement AES, HKDF, HMAC or a random generator themselves (§8.1, L958–959).
- `sodium_crypto_aead_aes256gcm_*` MUST NOT be used (CLAUDE.md, stack rules).
- Silent downgrade of algorithm or version MUST be refused (§8.1, L963). If a validated primitive is missing, the feature MUST be disabled; no weaker mechanism replaces Ed25519 (§8.4, L1116) and no fallback to PBKDF2 or another weaker KDF is allowed (§8.3, L1089).
- Browser Ed25519 with Web Crypto: the 32-byte seed MUST be wrapped as PKCS#8 with the fixed 16-byte DER prefix `302e020100300506032b657004220420` followed by the seed; the key MUST be imported `extractable: true` solely to export the JWK `x` member (public key); references MUST be dropped after use (§8.4, L1116).
- Algorithm choice between Web Crypto and `@noble/ed25519` is made at load time, without user action; `@noble/ed25519` MUST never be loaded remotely (§8.4, L1116).

---

## 3. Encodings

### 3.1 base64url

- Every binary value in API JSON (`ciphertext`, `nonce`, `salt`, public keys, signatures, hashes) MUST be encoded as **base64url without padding** (RFC 4648 §5) (§8.2.3, L1061; §10, L1527).
- The encoding MUST be **canonical**: the unused low-order bits of the last character MUST be zero. Any non-canonical string, any character outside the alphabet `A–Z a–z 0–9 - _`, and any `=` padding MUST be rejected by the frontend, the backend and the CLI (§8.2.3, L1061).
- The same rule applies to the identifier, the fragment key and the deletion token in URLs (§8.2.3, L1061).
- The AAD is transmitted as the base64url string of its bytes (§8.2.3, L1061).
- The instance secret `QUIETLINK_APP_SECRET` is an exception: it is **standard** base64 (RFC 4648 §4) and decodes to at least 32 bytes; the decoded bytes are the HKDF IKM (§9.5, L1426).

### 3.2 Lengths

| Value | Bytes | base64url characters | Source |
|---|---|---|---|
| `K_url` (fragment `<key>`) | 32 | 43 *(derived)* | §8.2, L971; §8.5, L1143 |
| Public identifier `id` | 24 | 32 | §8.2, L972; §8.5, L1142 |
| Deletion token | 32 | 43 *(derived)* | §8.2, L984; §10, L1600 |
| `deletion_hash` = SHA-256(token) | 32 | 43 *(derived)* | §8.2, L984 |
| `access_pk`, `consume_pk` | 32 | 43 *(derived)* | §8.2.2, L1053 |
| Argon2id `salt` | 16 | 22 *(derived)* | §8.3, L1090; §8.2.2, L1053 |
| AES-GCM `nonce` | 12 | 16 *(derived)* | §8.2, L981; §8.2.2, L1053 |
| `ciphertext` (incl. 16-byte tag) | envelope length + 16 | ⌈4n/3⌉ *(derived)* | §8.2, L982; §8.2.3, L1067 |
| Ed25519 signature | 64 *(RFC 8032)* | 86 *(derived)* | §8.4, L1116 |
| Challenge | 82 | 110 *(derived)* | §6.3.1, L441–447, L455 |
| Reservation identifier | 16 (128 bits) | 22 *(derived)* | §6.3.1, L489; §10, L1572 |
| `Idempotency-Key` | 16 (128 bits) | 22 *(derived)* | §10, L1550 |
| AAD | ≤ 4 096 | ≤ 5 462 *(derived)* | §8.2.3, L1068 |

Implementations MUST reject any decoded value whose length differs from the exact length above where an exact length is specified (§8.2.2, L1053; §10, L1600).

### 3.3 JSON

- The encrypted envelope is JSON encoded as UTF-8 (§8.2.1, L1012) (see §7).
- The AAD is RFC 8785 (JCS) JSON encoded as UTF-8 (§8.2.2, L1030) (see §8).
- Timestamps in API responses (`expires_at`, `server_time`) are ISO 8601 UTC strings; `expires_at` is `null` for a "never" content (§10, L1542–1543). Exact profile: OQ-20.

---

## 4. Size limits

| Level | Option | Default | Enforced by | Source |
|---|---|---|---|---|
| Serialized envelope, UTF-8 bytes | `paste.max_envelope_bytes` | 1 048 576 (1 MiB) | client (gauge and block); the server never sees plaintext | §8.2.3, L1066 |
| Decoded binary ciphertext, tag included | `paste.max_ciphertext_bytes` | `max_envelope_bytes + 16` = 1 048 592 | server, after decoding | §8.2.3, L1067 |
| Canonical AAD, bytes | `paste.max_metadata_bytes` | 4 096 | server | §8.2.3, L1068 |
| Total HTTP body | `http.max_request_bytes` | 1 441 792 (1 408 KiB) | web server and application, before parsing | §8.2.3, L1069 |

Rules:

- `paste.max_ciphertext_bytes` is not independently configurable; it MUST always equal `max_envelope_bytes + 16` (§8.2.3, L1071).
- At startup the server MUST check `max_request_bytes ≥ ⌈max_ciphertext_bytes × 4/3⌉ + max_metadata_bytes × 4/3 + 16 KiB` (§8.2.3, L1073). With defaults: 1 398 123 + 5 461.3 + 16 384 ≈ 1 419 969 ≤ 1 441 792 *(derived)*.
- The body MUST be refused as soon as `Content-Length` or the bytes read exceed the limit, before any JSON decoding (§8.2.3, L1075); response `413` (§10, L1633).
- The user-facing size limit applies to the serialized envelope, not the raw text, because JSON escaping can expand a character up to 6 bytes (§8.2.1, L1026).

---

## 5. Key material and HKDF derivations

### 5.1 Random material generated by the client

| Value | Size | Generated by | Leaves the client? | Source |
|---|---|---|---|---|
| `K_url` | 32 bytes | creator, CSPRNG | Only in the URL fragment; never sent to the server | §8.2, L971; §8.5, L1146 |
| Argon2id `salt` | 16 bytes | creator, only if a passphrase is used | Yes, inside the AAD | §8.2, L978; §8.3, L1096 |
| AES-GCM `nonce` | 12 bytes | creator, CSPRNG | Yes | §8.2, L981 |
| Deletion token | 32 bytes | creator, CSPRNG | Never; only `SHA-256(token)` is sent at creation, the raw token only in `X-Deletion-Token` on `DELETE` | §8.2, L984; §8.5, L1147–1148; §10, L1596 |
| Reservation identifier | 16 bytes | reader, CSPRNG | Yes (request body) | §6.3.1, L489 |
| `Idempotency-Key` | 16 bytes | creator | Yes (header) | §10, L1550 |

### 5.2 Random material generated by the server

| Value | Size | Source |
|---|---|---|
| `R` (identifier suffix) | 8 bytes, `random_bytes`, unique among existing contents | §8.2, L972 |
| Challenge `nonce` | 16 bytes | §6.3.1, L446 |

### 5.3 Derivations

Common salt: `salt_hkdf = "sp-proto/v1/hkdf"` (16 ASCII bytes *(derived)*) (§8.2, L992).

| Output | IKM | Salt | Info (ASCII) | L | Purpose | Source |
|---|---|---|---|---|---|---|
| `PRK_url` | `K_url` (32 B) | `salt_hkdf` | — (Extract) | 32 | Intermediate | §8.2, L994 |
| `K_access_seed` | from `PRK_url` (Expand) | — | `sp-proto/v1/access/ed25519` | 32 | Ed25519 seed of the access key pair; proves possession of the link | §8.2, L995, L1004–1005 |
| `IKM_content` | `K_url ‖ K_pass` (64 B) with passphrase; `K_url` (32 B) without | — | — | — | Input to content extraction | §8.2, L997–998 |
| `PRK_content` | `IKM_content` | `salt_hkdf` | — (Extract) | 32 | Intermediate | §8.2, L999 |
| `K_enc` | from `PRK_content` (Expand) | — | `sp-proto/v1/content/aes-256-gcm` | 32 | AES-256-GCM key; single encryption per content | §8.2, L1000, L981 |
| `K_consume_seed` | from `PRK_content` (Expand) | — | `sp-proto/v1/read-once/consume/ed25519` | 32 | Ed25519 seed of the consumption key pair; proves ability to decrypt | §8.2, L1001, L1006 |
| `K_challenge` (server only) | decoded bytes of `QUIETLINK_APP_SECRET` | `salt_hkdf` | `sp-proto/v1/server/challenge` | 32 | HMAC key for stateless challenges | §6.3.1, L449; CDC L1426 |

Derived key pairs (§8.2, L1004; §8.4, L1116):

- `(access_sk, access_pk) = Ed25519-KeyGen(K_access_seed)` per RFC 8032 §5.1.5.
- `(consume_sk, consume_pk) = Ed25519-KeyGen(K_consume_seed)`; computed and published only for read-once content (§8.2, L983; §8.2.2, L1042).

Rules:

- Each key has a single purpose; no key is used both to encrypt and to sign, nor as input to any derivation outside this table (§8.2, L989).
- Private keys are never stored; the reader re-derives them (§8.2, L1004).
- In PHP each Extract + Expand pair is `hash_hkdf('sha256', IKM, 32, info, salt_hkdf)`; in Web Crypto, `deriveBits` with HKDF (§8.2, L1007). Because `hash_hkdf` does not expose the PRK, test tooling that must output `PRK_*` computes `HMAC-SHA-256(salt_hkdf, IKM)` (RFC 5869 Extract) *(derived from RFC 5869)*.
- Without a passphrase `PRK_content = PRK_url` *(derived)*; domain separation then rests on the distinct info strings.
- The server MUST NEVER receive a private key, `K_url`, `K_pass` or the passphrase (§6.3.1, L516).

### 5.4 Public identifier

The identifier is assigned by the server at creation and is never chosen by the client (§8.2, L972; §10, L1541):

```text
A  = tronc64(SHA-256("sp-proto/v1/id"        ‖ 0x00 ‖ access_pk))       8 bytes
D  = tronc64(SHA-256("sp-proto/v1/id-delete" ‖ 0x00 ‖ deletion_hash))   8 bytes
R  = random_bytes(8), unique among existing contents                    8 bytes
id = A ‖ D ‖ R                                                          24 bytes
```

Source: §8.2, L972. `access_pk` and `deletion_hash` are the raw 32-byte values.

- The server computes `A` from the `access_pk` of the received AAD and `D` from the received `deletion_hash` (§8.2, L972).
- Each new creation, including a retry with identical bytes, MUST get a fresh `R`; no mechanism reassigns an identifier, including an idempotent retry (§8.2, L975; §10, L1562). An idempotent replay returns the *recorded* identifier, it does not draw a new one (§10, L1552, L1557).
- On receiving the identifier, the client (browser or CLI) MUST verify `A` and `D` before building links. When opening a share link the reader MUST verify `A`; when opening a management link the page MUST verify `D`; both before any network call, reporting a tampered link otherwise (§8.2, L976).
- At creation the server MUST reject non-canonical or small-order `access_pk` and `consume_pk` with an explicit check (§8.2, L977). Exact check: OQ-13.

---

## 6. Passphrase combination (Argon2id)

When the creator sets a passphrase:

1. Normalise the passphrase to Unicode NFC, then encode it as UTF-8 (§8.3, L1091).
2. Generate `salt`: 16 random bytes (§8.2, L978; §8.3, L1090).
3. `K_pass = Argon2id v1.3(passphrase, salt, m, t, p = 1, L = 32)` (§8.3, L1091).
4. `IKM_content = K_url ‖ K_pass` (§8.2, L997), then derive `K_enc` and `K_consume_seed` per §5.3 (§8.3, L1095).
5. Record in the AAD: `kdf = {"alg":"argon2id13","m":m,"t":t,"p":1,"salt":base64url(salt)}` (§8.2.2, L1040; §8.3, L1096). Neither the passphrase nor `K_pass` is ever stored (§8.3, L1096).

Without a passphrase, `kdf` is `null` and `IKM_content = K_url` (§8.2, L998; §8.2.2, L1040).

Validation:

- Server and reader MUST enforce `19 456 ≤ m ≤ 262 144` and `2 ≤ t ≤ 10` (§8.3, L1094); `p` MUST be 1 (§8.3, L1092).
- `kdf` MUST be non-null only if `allow_passphrase = true` on the instance (§8.2.2, L1053).
- The passphrase MUST NOT appear in the URL (§8.5, L1145).
- The random `K_url` MUST NOT be replaced by a key derived only from a passphrase (§8.3, L1097).
- A browser lacking the validated Argon2id implementation MUST disable passphrase creation and, on reading protected content, MUST show the explicit unsupported-browser message without calling `open` or reserving (§8.3, L1088).
- Browser Argon2id runs only inside a dedicated worker (§8.3, L1086); CSP MAY add `wasm-unsafe-eval`, never `unsafe-eval` (§8.3, L1087).

Local passphrase check (read-once): the reader derives `K_pass`, then `K_consume_seed`, then `consume_pk'`, and compares `consume_pk'` with the AAD `consume_pk`. On mismatch it reports a wrong passphrase **without contacting the server or reserving** (§6.3.1, L488). For non-read-once content the passphrase is checked by AES-GCM decryption (*derived*: there is no `consume_pk`; see OQ-27).

---

## 7. Encrypted payload envelope

### 7.1 Plaintext envelope

The plaintext encrypted is a JSON object encoded in UTF-8, not the raw text (§8.2.1, L1012):

```json
{"format":"markdown","language":null,"template":"credentials","text":"…","v":1}
```

| Field | Type | Values | Source |
|---|---|---|---|
| `format` | string | `plain`, `markdown`, `code` | §8.2.1, L1018 |
| `language` | string or `null` | syntax-highlighting identifier | §8.2.1, L1019 |
| `template` | string or `null` | built-in template identifier | §8.2.1, L1020 |
| `text` | string | user text; line endings normalised to `\n` only | §8.2.1, L1021 |
| `v` | integer | envelope version (example shows `1`) | §8.2.1, L1015, L1022 |

Rules:

- The envelope need not be canonical and is never compared byte by byte; any strictly valid JSON is accepted: valid UTF-8, no duplicate key, no unknown key (§8.2.1, L1012).
- Before serialisation the browser MUST replace lone UTF-16 surrogates with U+FFFD (`String.prototype.toWellFormed()`) (§8.2.1, L1021).
- `format`, `language` and `template` never appear in clear on the server (§8.2.1, L1024).
- Size limit: §4.

Gaps: required-ness of each key, exact `v` value, identifier sets, line-ending rule details: OQ-15, OQ-16, OQ-17.

### 7.2 Encryption

```text
nonce      = 12 random bytes                                       (§8.2, L981)
ciphertext = AES-256-GCM-Encrypt(key = K_enc, iv = nonce,
                                 aad = AAD bytes (§8),
                                 plaintext = UTF-8 envelope bytes) ‖ tag(16 bytes)
                                                                   (§8.2, L982)
```

- `K_enc` is unique per content and used for exactly one encryption, which guarantees key/nonce uniqueness (§8.2, L981).
- The 128-bit tag is appended to the ciphertext (§8.2, L982). In PHP, `openssl_encrypt` returns the tag separately; implementations MUST concatenate it *(derived)*.
- A retry of creation with the same `Idempotency-Key` MUST reuse exactly the same body (same ciphertext, nonce, AAD, deletion hash). The client MUST NOT re-encrypt under the same key; re-encryption requires a new `Idempotency-Key` **and** a new `K_url` (§10, L1553).

### 7.3 Creation request

`POST /api/v1/pastes`, body fields exactly: `aad`, `nonce`, `ciphertext`, `deletion_hash`; no other field is accepted (§10, L1537); header `Idempotency-Key` required (§10, L1550). Response: identifier, `expires_at`, `server_time` and link construction information (§10, L1541–1544). Status `201`, or `200` for an idempotent replay (§10, L1614).

Minimum content of the stored format: protocol version, algorithm, nonce, ciphertext, KDF identifier, salt and parameters when a passphrase is used, access and consumption public keys, canonical AAD (§8.4, L1104–1112).

### 7.4 Decryption

The reader MUST use the received AAD bytes directly as AES-GCM associated data and MUST NOT rebuild them (§8.2.2, L1030). Any modification of the payload MUST produce an explicit decryption error (§8.4, L1114).

---

## 8. AAD canonicalisation

### 8.1 Fields

All fields are REQUIRED; no other field is allowed (§8.2.2, L1032–1042).

| Field | JSON type | Content | Source |
|---|---|---|---|
| `v` | integer | protocol version, `1` | L1036 |
| `alg` | string | `"A256GCM"` | L1037 |
| `read_once` | boolean | read-once mode | L1038 |
| `expiration` | string | one of `"5m"`, `"1h"`, `"1d"`, `"7d"`, `"30d"`, `"never"` | L1039 |
| `kdf` | object or `null` | `{"alg":"argon2id13","m":<KiB>,"t":<passes>,"p":1,"salt":"<base64url>"}` | L1040 |
| `access_pk` | string | Ed25519 access public key, base64url | L1041 |
| `consume_pk` | string or `null` | Ed25519 consumption public key, base64url; `null` unless read-once | L1042 |

### 8.2 Byte-level construction

The AAD bytes are the RFC 8785 (JCS) serialisation of this object, encoded as UTF-8 (§8.2.2, L1030). Constraints that make the construction exact (§8.2.2, L1046–1052):

1. Object members sorted by key in UTF-16 code-unit order at every nesting level; no whitespace anywhere (L1046).
2. Every string is printable ASCII and contains neither `"` nor `\`; no escaping is ever produced (L1047, L1052).
3. Numbers are integers in `[0, 2^53 − 1]`, serialised in shortest decimal form without sign, exponent or fraction; floats, exponents, `-0`, `NaN`, `Infinity` are forbidden (L1048).
4. Arrays are forbidden (L1049).
5. `true`, `false`, `null` are the JSON literals *(RFC 8785)*.

Resulting member order *(derived from rule 1; all keys are ASCII, so UTF-16 order equals byte order)*:

- top level: `access_pk`, `alg`, `consume_pk`, `expiration`, `kdf`, `read_once`, `v`;
- inside `kdf`: `alg`, `m`, `p`, `salt`, `t`.

Template (line breaks for readability only; the real bytes contain none):

```text
{"access_pk":"<43 chars>","alg":"A256GCM","consume_pk":<"43 chars"|null>,
 "expiration":"<code>","kdf":<{"alg":"argon2id13","m":<int>,"p":1,"salt":"<22 chars>","t":<int>}|null>,
 "read_once":<true|false>,"v":1}
```

PHP equivalent: recursive `ksort($a, SORT_STRING)` then `json_encode($a, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)`; a full RFC 8785 library is not required (§8.2.2, L1052).

The round-trip comparison alone is **not** sufficient: with associative decoding, `[]` and `{}` decode to the same PHP value and re-encode identically, so `"kdf":[]` would pass. Implementations MUST decode while keeping objects distinct from arrays (e.g. `json_decode($bytes, false, …)` producing `stdClass`) and MUST apply the type checks of §8.1 to every member in addition to the byte-for-byte comparison *(derived from L1049–L1051)*.

### 8.3 Validation (server and client)

- Duplicate, unknown or missing keys and unexpected types MUST cause rejection on both server and client (L1050).
- The server MUST verify that the received bytes are exactly the canonical serialisation of the decoded object, else reject; this also detects duplicate keys that PHP `json_decode` silently overwrites (L1051).
- The server MUST check consistency with the request and configuration (L1053):
  - `access_pk` and `consume_pk` decode to 32 bytes; `salt` to 16 bytes; request `nonce` to 12 bytes;
  - `expiration` is allowed by the instance;
  - `read_once` allowed by `allow_read_once`;
  - `read_once = true` **if and only if** `consume_pk` is non-null;
  - `kdf` non-null only if `allow_passphrase = true`;
  - KDF parameters within bounds (§2.1);
  - `alg` and `v` supported.
- The identifier and server-managed fields (`created_at`, `expires_at`, state, counters, reservation) are outside the AAD and not authenticated by the client; `created_at` is the server reception time and `expires_at = created_at + duration` (L1054).
- The reader MUST check that the AAD `access_pk` equals the `access_pk` derived from `K_url`, at the `status` response and again at `open`; and MUST require the AAD returned by `open` to be byte-identical to the one returned by `status`. Any mismatch is an integrity failure (L1055).
- Any server or storage modification of the AAD (disabling read-once, changing KDF parameters, …) causes decryption failure; substitution with content of another `K_url` is detected because `K_enc` and `access_pk` differ (L1057).

### 8.4 Annotated example (dummy values)

Dummy inputs (not derived from any real key; `access_pk` and `consume_pk` are arbitrary byte patterns and not necessarily valid curve points):

| Input | Bytes (hex) | base64url |
|---|---|---|
| `access_pk` | `0102…1f20` (bytes 0x01 to 0x20) | `AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA` |
| `consume_pk` | `2122…3f40` (bytes 0x21 to 0x40) | `ISIjJCUmJygpKissLS4vMDEyMzQ1Njc4OTo7PD0-P0A` |
| `salt` | `a5` × 16 | `paWlpaWlpaWlpaWlpaWlpQ` |

Object: `v = 1`, `alg = "A256GCM"`, `read_once = true`, `expiration = "1d"`, `kdf = {argon2id13, m = 65536, t = 3, p = 1, salt}`.

Canonical AAD (one line, 256 bytes):

```text
{"access_pk":"AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA","alg":"A256GCM","consume_pk":"ISIjJCUmJygpKissLS4vMDEyMzQ1Njc4OTo7PD0-P0A","expiration":"1d","kdf":{"alg":"argon2id13","m":65536,"p":1,"salt":"paWlpaWlpaWlpaWlpaWlpQ","t":3},"read_once":true,"v":1}
```

Annotations:

- `access_pk` sorts before `alg` because `c` (0x63) < `l` (0x6C).
- Inside `kdf`, `p` precedes `salt` precedes `t`; `m` precedes `p`.
- No whitespace; integers without quotes; `-` and `_` from base64url need no escaping.
- On the wire the `aad` body field carries base64url of these 256 bytes (342 characters *(derived)*).

Second example, not read-once, no passphrase (146 bytes):

```text
{"access_pk":"AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA","alg":"A256GCM","consume_pk":null,"expiration":"7d","kdf":null,"read_once":false,"v":1}
```

---

## 9. URL format

```text
Share link:       https://<instance>/p/<id>#<key>
Management link:  https://<instance>/manage/<id>#<deletion-token>
```

Source: §8.5, L1123, L1129.

| Part | Content | Encoding | Source |
|---|---|---|---|
| `<id>` | 24-byte public identifier (§5.4) | canonical base64url, no padding, 32 characters | §8.5, L1142 |
| `<key>` | `K_url` (32 bytes) | base64url, no padding (43 characters *(derived)*) | §8.5, L1143 |
| `<deletion-token>` | client-generated 32-byte random token | base64url, no padding (43 characters *(derived)*) | §8.5, L1144; §8.2, L984 |

Rules:

- The passphrase is never in the URL (§8.5, L1145); the server never receives the fragment (§8.5, L1146).
- The deletion token is sent to the API only in the `X-Deletion-Token` header, never in path, query string, log or error message (§8.5, L1148; §10, L1596).
- The management link MUST NOT be put in a QR code or a native share action (§8.5, L1149).
- The full URL and fragment MUST NOT be written to logs, caches, analytics, errors, traces or third-party URLs (§8.5, L1150–1151; §8.2.3, L1077).
- The management page MUST report a truncated link (token missing or of invalid length) without contacting the server (§8.5, L1138), and MUST verify `D` before any network call (§8.2, L976).
- The canonical base64url rule (§3.1) applies to `<id>`, `<key>` and `<deletion-token>` (§8.2.3, L1061).
- Read pages MUST NOT contain plaintext, MUST carry `noindex`, `nofollow`, `noarchive`, and `Referrer-Policy: no-referrer` (§8.6, L1158–1160).

---

## 10. Access proof (Ed25519 challenge/response)

The server never returns a ciphertext or any metadata on knowledge of the identifier alone; every opening requires proof of possession of `K_url` (§6.3.1, L432).

### 10.1 Challenge layout (82 bytes)

| Offset | Length | Field | Value | Source |
|---|---|---|---|---|
| 0 | 1 | `version` | `0x01` | L442 |
| 1 | 1 | `usage` | `0x01` open, `0x02` status, `0x03` consume | L443 |
| 2 | 24 | `id` | raw public identifier | L444 |
| 26 | 8 | `issued_at` | Unix seconds, unsigned big-endian | L445 |
| 34 | 16 | `nonce` | random bytes | L446 |
| 50 | 32 | `mac` | `HMAC-SHA-256(K_challenge, bytes[0..50))` | L447 |

Offsets and total *(derived)*; total 82 bytes is confirmed by L455. Transported as base64url (L439).

- The challenge is stateless and computed without any storage read or write, identically for existing and non-existing identifiers (L435; §10, L1568).
- Issuance: `POST /api/v1/pastes/{id}/challenge`, body indicates usage `open` or `status` (§10, L1568); response `200` with `challenge` and `expires_in` (seconds) (§10, L1615; §6.3.1, L461). The `/p/<id>` HTML page MAY embed one `open` and one `status` challenge in a `<meta>` element (§6.3.1, L434; §10, L1568).
- The client treats the challenge as opaque and signs it without interpreting it (L463).

### 10.2 Signed message (100 bytes)

```text
message = "sp-proto/v1/proof" ‖ 0x00 ‖ challenge
          (17 ASCII bytes)      (1)     (82 raw bytes)
signature = Ed25519-Sign(sk, message)       ; pure Ed25519, no pre-hash
```

Source: §6.3.1, L452–455. Length 100 bytes *(derived)*.

| Usage | Signing key | Verified with | Source |
|---|---|---|---|
| `open` (0x01) | `access_sk` | `access_pk` sent in the request | L436, L458, L464 |
| `status` (0x02) | `access_sk` | `access_pk` sent in the request | L458, L464 |
| `consume` (0x03) | `consume_sk` | `consume_pk` from the stored AAD | L458, L492–493 |

A challenge of one usage MUST NEVER be accepted for another (L458).

### 10.3 Server verification order for `open` and `status`

Before any storage access (L437, L464):

1. Verify `mac` (HMAC under `K_challenge`).
2. Verify freshness: `open`/`status` challenges are valid 60 seconds; clock tolerance is zero since `issued_at` is set by the server itself (L459, L462). Exact boundary: OQ-7.
3. Verify the challenge `id` equals the path identifier and `usage` matches the endpoint (L437).
4. Verify `id[0..8) = tronc64(SHA-256("sp-proto/v1/id" ‖ 0x00 ‖ access_pk))` (L464).
5. Verify the Ed25519 signature with the transmitted `access_pk` (L464).

Only then read storage, and verify that `access_pk` equals the one in the stored AAD (L437, L464). Any failure returns the generic unavailability response (`404`) (L437). Tests MUST verify that an invalid proof causes no storage access (L464).

Request bodies: `open` — challenge, `access_pk`, signature and, for read-once, the reservation identifier (§10, L1572); `status` — challenge, `access_pk`, signature (§10, L1580). JSON member names: OQ-14.

### 10.4 Lifetime and anti-replay

- `open`/`status` challenges: stateless, valid 60 s (L459). Replaying a valid proof within its 60 s window yields nothing beyond what the link holder already has; read-once remains governed by the reservation rules (L472). There is no server-side replay cache for these usages *(derived from "sans état", L435, L459)*.
- The client SHOULD renew proactively before expiry (L461), and MUST transparently request a new challenge when one has expired, e.g. during passphrase entry (L459).
- `consume` challenge: issued by `open` at reservation time and bound to it; stored as-is in `state.json` with the reservation; same binary format; verified by constant-time comparison with the stored value, **not** by HMAC, so a rotation of `QUIETLINK_APP_SECRET` does not affect running reservations; valid only for that reservation and its remaining duration (L460). It is not a secret (L460).
- Rotation of `QUIETLINK_APP_SECRET` invalidates outstanding `open`/`status` challenges; the single-retry rule (§12.3) covers it (L461).
- The proof does not depend on the passphrase: it proves possession of the link, not ability to decrypt (L470).

---

## 11. Deletion token binding

- Token: 32 random bytes generated by the creator; only `deletion_hash = SHA-256(token)` is sent at creation (§8.2, L984; §10, L1546).
- Binding: `D = id[8..16) = tronc64(SHA-256("sp-proto/v1/id-delete" ‖ 0x00 ‖ deletion_hash))` (§8.2, L972).

`DELETE /api/v1/pastes/{id}` with header `X-Deletion-Token: <base64url token>` (§10, L1596). Processing order (§10, L1598–1602):

1. Without any storage access: reject (`404`) if the header is missing or its canonical base64url decoding is not 32 bytes; compute `SHA-256(token)`; verify `id[8..16) = tronc64(SHA-256("sp-proto/v1/id-delete" ‖ 0x00 ‖ SHA-256(token)))`; otherwise `404` without reading disk.
2. Read `meta.json` and compare `SHA-256(token)` with the stored hash in constant time (`hash_equals`).
3. Delete under lock.

Success returns `204`; an invalid token or unavailable content returns the generic `404` (§10, L1604). A valid token deletes the content whatever its state (`available`, `reserved`, `consumed`); a running reservation is cancelled and a later `consume` fails with `404` (§10, L1622). The raw token is never stored (§10, L1604) and the header MUST be absent from logs, traces, metrics and error responses (§10, L1596).

---

## 12. Read and consume flow

### 12.1 States (read-once)

```text
available → reserved → consumed
              ↘ timeout → available (unconfirmed opens counter +1)
              ↘ timeout with counter ≥ threshold → consumed
```

Source: §6.3.1, L480–484. `confirmed` is an internal atomic step, not a persistent state (L486).

| Parameter | Default | Source |
|---|---|---|
| Reservation duration | 60 s, capped by configuration | L495 |
| `paste.max_unconfirmed_opens` | 3 (range 1–10) | L496; CDC L941 |
| Retention of `consumed` state | ≥ 10 min (L497); 10 min after `terminal_at` (L514) — see OQ-24 | L497, L514 |

### 12.2 Steps

0. **Preparation (no reservation).** Client calls `status` with an access proof; receives AAD (incl. `kdf`, `consume_pk`), `expires_at`, `server_time` and, for read-once, state (`available`/`reserved`), remaining reservation delay and `unconfirmed_opens` (§6.3.1, L488; §10, L1582). If a passphrase is required, the user enters it; client derives `K_pass` → `K_consume_seed` → `consume_pk'` and compares locally (§6). All costly derivations finish before step 1 (L488). `status` never returns the ciphertext and never reserves (§10, L1582).
1. **Open.** After "Reveal", the client generates a 128-bit reservation identifier, writes it to `sessionStorage` **before** sending `open` (L502), and sends it with a valid access proof (L489). If `available`, the server, under exclusive lock, sets `reserved` and stores the hash of the reservation identifier; it returns ciphertext, nonce, AAD, `expires_at`, `server_time`, a consume challenge and `unconfirmed_opens` (L489; §10, L1574–1576). A retry with the same reservation identifier resumes the reservation instead of `409` (L489).
2. **Concurrent open.** A request with a valid proof but another reservation identifier gets `409` with the remaining delay, without payload; a request without valid proof gets `404` (L490; §10, L1576, L1632).
3. **Decrypt.** Client decrypts and verifies locally; checks AAD byte-equality with `status` (§8.3); warns if `unconfirmed_opens > 0` (L491).
4. **Sign.** Client signs the consume challenge with `consume_sk` (derived at step 0) (L492), using the message of §10.2 (see OQ-9). The client keeps the signature for idempotent resend instead of re-signing (§8.4, L1116).
5. **Consume request.** Body: `access_pk`, reservation identifier, consume challenge, signature (§10, L1588).
6. **Server consume processing** (§10, L1590):
   1. without storage access: verify `id[0..8)` against `access_pk`;
   2. read `meta.json` and `state.json` without exclusive lock; compare `access_pk` with stored AAD; compare reservation-identifier hash with the active reservation **or** the consuming reservation (for idempotent replay);
   3. take exclusive lock; re-read state; re-check reservation identifier, reservation expiry and challenge equality (constant time, L460);
   4. verify signature with stored `consume_pk` (L493);
   5. atomically write `consumed` (temp file + rename), record hash of the consuming signature, then delete `payload.bin` under the same lock (L494); or return the idempotent success.
7. **Timeout.** An unconfirmed reservation is released after the reservation duration; each release increments `unconfirmed_opens` (L495). When the counter reaches `max_unconfirmed_opens`, the content becomes `consumed` instead of `available` (L496). Releases are applied lazily under lock at the next request on the content, or by scheduled purge (L498).
8. **Expiry wins** over reservation: content expiring during a reservation becomes unavailable and confirmation is refused (L514).

### 12.3 Retry, resumption and idempotency

- **Challenge retry.** On any `404` from `open` or `status`, the client MUST retry **exactly once** with a fresh challenge before reporting unavailability, regardless of the original challenge's age (L461).
- **Lost `open` response.** Resend `open` with the same reservation identifier; the server resumes (L489).
- **Reload.** After a tab reload, the client resends `open` with a fresh access proof and the stored reservation identifier; if the reservation is still active the server returns the payload and the **same** consume challenge read from `state.json`, without incrementing `unconfirmed_opens`; resumption does not extend the reservation (L503–504). For passphrase content the passphrase is asked again and checked locally (L505).
- The reservation identifier MUST NOT be written to `localStorage`, a cookie, the URL or a log, and is erased on consumption or reservation expiry (L502, L506).
- **Idempotent consume.** Resending exactly the same proof (same reservation, same challenge, same signature) returns the same `200` while the `consumed` state is retained; any other reuse is rejected with `404` (L497; §10, L1592).
- **Idempotent creation.** Governed by `Idempotency-Key`; request fingerprint is SHA-256 of the raw HTTP body; same key + same fingerprint returns the recorded response (`200`); same key + different fingerprint returns `422` (§10, L1550–1557).

### 12.4 Sequence diagram

```mermaid
sequenceDiagram
    autonumber
    participant C as Client (browser/CLI)
    participant S as Server
    participant FS as Storage

    Note over C: Parse #key (K_url), verify A = id[0..8)
    Note over C: Derive K_access_seed, access_pk
    alt challenge embedded in /p/<id> page
        S-->>C: open + status challenges (meta)
    else
        C->>S: POST /challenge {usage: status}
        S-->>C: 200 {challenge, expires_in}
    end
    C->>S: POST /status {challenge, access_pk, sig}
    Note over S: HMAC, freshness, id/usage, A check, Ed25519 verify (no storage access)
    S->>FS: read meta/state
    S-->>C: 200 {aad, expires_at, server_time, state, unconfirmed_opens}
    Note over C: Check aad.access_pk; if kdf: Argon2id, derive consume_pk', compare locally
    Note over C: Generate reservation_id, store in sessionStorage
    C->>S: POST /open {challenge(open), access_pk, sig, reservation_id}
    Note over S: Same stateless checks
    S->>FS: lock, available -> reserved, store H(reservation_id) + consume challenge
    S-->>C: 200 {ciphertext, nonce, aad, expires_at, server_time, consume challenge, unconfirmed_opens}
    Note over C: AAD byte-equal to status; AES-GCM decrypt; warn if unconfirmed_opens > 0
    Note over C: Sign consume challenge with consume_sk, keep signature
    C->>S: POST /consume {access_pk, reservation_id, challenge, sig}
    Note over S: A check (no storage access)
    S->>FS: read meta/state (no lock), compare access_pk, H(reservation_id)
    S->>FS: exclusive lock, re-check, verify sig with consume_pk
    S->>FS: write consumed (tmp + rename), H(sig), delete payload.bin
    S-->>C: 200
    opt response lost
        C->>S: POST /consume (identical proof)
        S-->>C: 200 (idempotent replay)
    end
```

---

## 13. Error handling

### 13.1 Uniform 404

The same generic `404` MUST be returned for: a non-existent identifier, an expired content, a consumed content, a content made unavailable, and an invalid access proof (§10, L1631; CLAUDE.md). In particular:

| Situation | Response | Source |
|---|---|---|
| Invalid HMAC, stale challenge, wrong usage, wrong id, `A` mismatch, bad signature (`open`/`status`) | `404`, no storage access | §6.3.1, L437, L464 |
| `access_pk` differs from the stored AAD | `404` | §6.3.1, L437 |
| Content expired, consumed, deleted | `404` | §10, L1576, L1631 |
| `consume` with invalid, expired or reused proof (other than exact replay) | `404` | §10, L1592 |
| `consume` after deletion | `404` | §10, L1622 |
| `DELETE` with missing/invalid token or `D` mismatch | `404`, no storage access | §10, L1600 |
| `DELETE` on unavailable content, or `reserved`/`consumed` with invalid token | `404` | §10, L1604, L1619 |

### 13.2 Other statuses

- `409` only after a valid access proof (reservation held by another reservation identifier), with remaining delay and no payload (§10, L1576, L1632).
- `400` (malformed body, AAD or `Idempotency-Key`), `413`, `415`, `422`, `429`, `503` with `Retry-After` per §10, L1614–1620, L1633.
- Errors use `application/problem+json` (RFC 9457) with generic `type` and `title`, no internal detail and no content identifier (§10, L1627).
- All API responses carry `Cache-Control: no-store` (§10, L1635).
- No secret, payload, full URL, fragment, `X-Deletion-Token` or `Idempotency-Key` in logs, caches, metrics, error messages or page titles (CLAUDE.md; §8.5, L1151; §10, L1596).

### 13.3 Client-side failures

- AAD `access_pk` ≠ derived `access_pk`, or AAD from `open` ≠ AAD from `status`: integrity failure (§8.2.2, L1055).
- AES-GCM tag failure: explicit decryption error (§8.4, L1114).
- `A` or `D` mismatch on a link: "tampered link", no network call (§8.2, L976).
- Local `consume_pk'` mismatch: wrong passphrase, no network call (§6.3.1, L488).

---

## 14. Versioning rules

- The encrypted format is explicitly versioned (§8.1, L961); silent downgrade is refused (§8.1, L963).
- Version fields in v1:
  - AAD `v = 1` and `alg = "A256GCM"` (§8.2.2, L1036–1037);
  - envelope `v` (§8.2.1, L1022);
  - challenge `version = 0x01` (§6.3.1, L442);
  - context strings prefixed `sp-proto/v1` (§0.4, L49);
  - KDF identifier `argon2id13` (§8.2.2, L1040).
- Readers and the server MUST reject unsupported `v` or `alg` (§8.2.2, L1053).
- Any change of Argon2id defaults MUST either bump the format version or remain compatible with parameters recorded with the content (§8.3, L1100).
- The format MUST be frozen in Phase 0 with test vectors before any implementation (§8.2, L967).
- Any protocol change requires new test vectors and a security review (CLAUDE.md). Any incompatible change to the encrypted format, the AAD, the `/api/v1` API or the storage format is a breaking change (CLAUDE.md); an incompatible API change requires `/api/v2`; adding an optional response field is not incompatible (§10, L1529).

---

## 15. Test vector requirements

Vectors MUST be public and deterministic (§8.1, L962) and shared by frontend, backend and CLI (CLAUDE.md). They MUST cover each intermediate value (`PRK_*`, seeds, public keys) (§8.2, L1008), `A` and `D` (§8.2, L972), the PKCS#8 encoding (§8.4, L1116), the signed message construction (§6.3.1, L463), the HMAC challenge (§6.3.1, L516) and non-canonical base64url strings to reject (§8.2.3, L1061). Ed25519 conformance is checked on public keys, signatures and cross-verification (§8.4, L1116).

All binary values in a vector file SHOULD be given as lowercase hex, and additionally as base64url where the value appears on the wire *(Proposed, non-normative; see OQ-29)*. Required vector groups and fields:

| Group | Inputs | Expected outputs |
|---|---|---|
| `hkdf_no_passphrase` | `K_url` | `PRK_url`, `K_access_seed`, `access_pk`, `IKM_content`, `PRK_content`, `K_enc`, `K_consume_seed`, `consume_pk` |
| `argon2id` | passphrase (UTF-8 input), NFC-normalised bytes, `salt`, `m`, `t`, `p` | `K_pass` |
| `hkdf_with_passphrase` | `K_url`, `K_pass` | `IKM_content` (64 B), `PRK_content`, `K_enc`, `K_consume_seed`, `consume_pk` (plus access values, unchanged) |
| `nfc` | non-NFC passphrase (e.g. decomposed accents, dummy) | NFC bytes, `K_pass` equal to the precomposed input's |
| `pkcs8` | seed | 48-byte PKCS#8 DER *(derived: 16 + 32)*, JWK `x` |
| `identifier` | `access_pk`, deletion token, `deletion_hash`, `R` | `A`, `D`, `id` (hex and 32-char base64url), share URL path and management URL path with dummy `<instance>` |
| `aad` | object fields | canonical AAD bytes (UTF-8 text and hex), length, base64url |
| `aad_reject` | non-canonical AADs: whitespace, wrong key order, duplicate key, unknown key, missing key, float, exponent, `-0`, array, wrong type, `read_once`/`consume_pk` mismatch, out-of-bounds `m`/`t`, `p ≠ 1`, wrong lengths | expected: reject |
| `envelope_encrypt` | envelope JSON bytes, `K_enc`, `nonce`, AAD bytes | ciphertext ‖ tag (hex/base64url), tag alone |
| `envelope_reject` | tampered ciphertext, tag, nonce, AAD byte | expected: decryption error |
| `challenge` | `K_challenge` IKM (dummy app secret, standard base64 and decoded), usage, `id`, `issued_at`, nonce | `K_challenge`, challenge bytes (hex, base64url), `mac` |
| `proof` | challenge, signing seed | signed message (100 bytes, hex), signature, public key, verify = true; and negative cases (other usage, flipped byte) |
| `consume_proof` | consume challenge, `K_consume_seed` | signed message, signature |
| `delete_check` | deletion token (base64url), `id` | decoded length, `SHA-256(token)`, expected `D`, accept/reject |
| `base64url_reject` | strings with padding, non-zero trailing bits, `+`/`/`, wrong length for each typed field | expected: reject |
| `full_create` *(deferred to Phase 1, needs OQ-14 field names)* | `K_url`, optional passphrase/salt/params, nonce, deletion token, envelope, `R` | every intermediate above plus the complete creation request body and resulting URLs |
| `challenge_verify` | `K_challenge`, challenge, endpoint usage, path `id`, `now` | accept/reject (usage mismatch, id mismatch, bad MAC, tampered `issued_at`, expired); boundary of OQ-7 not asserted |
| `public_key_reject` | 32-byte candidate public keys (small-order, non-canonical, wrong length) | expected: reject at creation |

Vectors MUST use only dummy values, never real secrets or personal data (CLAUDE.md). Each vector SHOULD reference its `EXG-<domain>-<n>` requirement identifier once assigned (CLAUDE.md).

---

## 16. Open questions

Each item cites the CDC location. "Proposed:" text is **non-normative**.

### Blocking for test vectors

- **OQ-1 — String encoding of context strings.** §8.2, L992 and §6.3.1, L455 say "octets ASCII" but do not state explicitly that no length prefix or NUL terminator is used for HKDF `info`/`salt` and hash prefixes (L972). Proposed: raw ASCII bytes, no prefix, no terminator (as written in §1.3).
- **OQ-2 — Envelope serialisation for vectors.** §8.2.1, L1012 states the envelope need not be canonical. Encryption vectors need fixed bytes; the CDC does not say which serialisation (key order, escaping of `/`, non-ASCII as raw UTF-8 or `\u` escapes) the reference implementations emit. Proposed: vectors fix the plaintext bytes as an opaque input; implementations emit keys in the example's order (L1015) without whitespace, raw UTF-8.
- **OQ-3 — `consume` challenge MAC.** §6.3.1, L460 says the consume challenge "keeps the same binary format" but is verified by comparison, not HMAC. The CDC does not say how its `mac` field is computed (HMAC under `K_challenge`, zeros, random). Proposed: computed with `K_challenge` like other challenges, never verified.
- **OQ-4 — `consume` challenge `issued_at` and lifetime.** L460 says it is valid "for the remaining reservation duration", but not whether `issued_at` equals the reservation start, nor whether a resumed `open` (L503) returns the original bytes (implied "same challenge") with the original `issued_at`. Proposed: `issued_at` = reservation creation time; bytes reused unchanged on resumption.
- **OQ-5 — Ed25519 verification strictness across libraries.** §8.2, L977 and §8.4, L1116 require cross-verification but do not specify the acceptance rules for non-canonical `S`, non-canonical or small-order `A`/`R` points. libsodium is strict; `@noble/ed25519` defaults may differ (ZIP-215 mode). Proposed: all implementations follow libsodium semantics (strict RFC 8032 + small-order rejection); noble configured with `zip215: false`; vectors include edge cases.

### Wire / API gaps

- **OQ-6 — Order of checks for `consume` vs §6.3.1 step 4.** §6.3.1, L437 states generically that the signature is verified *before* storage is read; §10, L1590 (consume) reads `meta.json`/`state.json` first and verifies the signature under lock, which is necessary since `consume_pk` comes from storage. Also L437 lists "HMAC" verification for all proofs, while L460 excludes HMAC for `consume`. **Contradiction in scope**; Proposed: L437 applies to `open`/`status` only.
- **OQ-7 — Freshness boundary.** L459 "valid 60 seconds" and L462 "zero clock tolerance" do not define inclusive/exclusive bounds nor handling of `issued_at > now` (e.g. multiple servers with skewed clocks). Proposed: accept iff `0 ≤ now − issued_at ≤ 60`; reject future `issued_at`.
- **OQ-8 — `expires_in` value.** §10, L1615: always 60 or computed remaining? Proposed: 60 at issuance.
- **OQ-9 — Signed message for `consume`.** L455 defines the message for "the client" generally; L492 says the client "signs the consume challenge". Not explicitly stated that the same `"sp-proto/v1/proof" ‖ 0x00` prefix applies. Proposed: identical construction; the usage byte provides domain separation.
- **OQ-10 — Challenge `id` check.** L437 lists checking "the identifier" of the challenge; L464 omits it. Explicit rule needed: challenge `id` MUST equal path `{id}` bytes. Proposed: yes, before the `A` check.
- **OQ-11 — Challenge endpoint request body.** §10, L1568: "the body specifies the usage" — member name and values (`"open"`/`"status"` strings?) unspecified; behaviour for a malformed path id (400 vs 404) unspecified (§10, L1615 lists `400`).
- **OQ-12 — HTML `<meta>` challenge format.** L434, §10, L1568: element name/attributes, encoding, and whether `expires_in` is also embedded are unspecified.
- **OQ-13 — Creation-time key validation.** §8.2, L977 requires an "explicit check" for non-canonical/small-order `access_pk`/`consume_pk` but names no function. Note: PHP's sodium extension does **not** expose `crypto_core_ed25519_is_valid_point`. `sodium_crypto_sign_ed25519_pk_to_curve25519()` throws on the small-order and non-canonical vectors of the `public_key_reject` group, but whether it rejects every non-canonical encoding must be confirmed in the security review. Proposed: use it plus an explicit canonical-encoding check (`y < p`); no proof of possession at creation (none is specified).
- **OQ-14 — JSON member names.** §10, L1572, L1580, L1582, L1588 describe fields in prose: reservation identifier, signature, consume challenge, state, remaining reservation delay (name and unit), `409` body. Only `aad`, `nonce`, `ciphertext`, `deletion_hash`, `challenge`, `expires_in`, `access_pk`, `expires_at`, `server_time`, `unconfirmed_opens` are named. Required for OpenAPI and CLI interop.
- **OQ-19 — Open request for non-read-once content.** §10, L1572: the reservation identifier is sent "for read-once". Behaviour when it is present for normal content, or absent for read-once content (client did not call `status` first), is unspecified. Proposed: ignored for normal content; read-once without it → `400`.
- **OQ-20 — ISO 8601 profile.** §10, L1542–1543: precision (seconds vs fractions) and suffix (`Z` vs `+00:00`) unspecified. Proposed: `YYYY-MM-DDTHH:MM:SSZ`.

### Envelope and passphrase gaps

- **OQ-15 — Envelope key presence.** §8.2.1, L1012 forbids unknown keys but does not state that all five keys are REQUIRED, nor allowed combinations (e.g. `language` non-null only when `format = code`).
- **OQ-16 — Envelope `v` value and identifier sets.** L1022 defines `v` as "envelope version" without a value (the example L1015 shows `1`); the sets of `language` and `template` identifiers are not in the read range. Relationship between envelope `v`, AAD `v` and challenge `version` is unstated.
- **OQ-17 — Line ending normalisation.** L1021 "normalised only for line endings (`\n`)": treatment of lone `\r` and of U+2028/U+0085 is unspecified. Proposed: `\r\n` → `\n`, then lone `\r` → `\n`.
- **OQ-18 — Passphrase constraints.** §8.3 gives no minimum/maximum length, no rule for an empty passphrase, nor whether NFC is applied before or after trimming. Proposed: non-empty, no trimming, a documented maximum byte length.
- **OQ-27 — Wrong passphrase on non-read-once content.** §6.3.1, L488 defines local checking via `consume_pk` only for read-once; for normal content the only signal is an AES-GCM failure, indistinguishable from tampering. Clarify UI/error semantics.

### Encoding and storage gaps

- **OQ-21 — Canonical base64url inside the AAD.** §8.2.3, L1061 applies to "API JSON"; the AAD is itself JSON but transmitted as base64url. It is not explicit that `access_pk`, `consume_pk`, `salt` inside the AAD MUST also be canonical base64url (a non-canonical value would pass the JCS check of L1051). Proposed: yes, reject.
- **OQ-22 — Server-side hashes.** Hash algorithm and encoding of the stored reservation-identifier hash (L489), consuming-signature hash (L494) and `Idempotency-Key` hash (§10, L1558) are unspecified. Server-internal but part of the storage format. Proposed: SHA-256.
- **OQ-23 — Constant-time comparisons.** Constant time is required for the consume challenge (L460) and the deletion hash (§10, L1601) but not stated for the challenge `mac` or `A`/`D` checks. Proposed: constant time (`hash_equals`) for all MAC/hash comparisons.

### Contradictions and inconsistencies

- **OQ-24 — `consumed` retention.** §6.3.1, L497 says "at least 10 minutes before purge"; L514 says kept "for 10 minutes after `terminal_at`". Minimum vs exact duration.
- **OQ-25 — Threshold comparison.** L482–483 ("timeout with counter ≥ threshold → consumed") does not state whether the counter is compared before or after the +1 of that release; L496 ("when it reaches the threshold") and L471 ("repeating `max_unconfirmed_opens` times destroys it") suggest after. Proposed: increment, then `consumed` if counter ≥ threshold.
- **OQ-26 — "Generate" vs "derive" key pairs.** §8.2 step 8, L983 says "generate the Ed25519 pairs", while L995–1004 derive them deterministically from `K_url`/`PRK_content`. Proposed: read "generate" as "derive".
- **OQ-28 — "Payload" vs AAD.** §8.3, L1100 refers to parameters "recorded in the payload", while L1096 and §8.2.2 put them in the AAD. Terminology only.

### Vector file format

- **OQ-29 — Vector file schema.** The CDC requires vectors but no file format, encoding (hex/base64url) or location. Proposed: a single `tests/vectors/sp-proto-v1.json`, hex for raw bytes, base64url for wire values, one object per group of §15.
