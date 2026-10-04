# QuietLink file storage format (v1)

- Status: Draft for Phase 0 (implementation in TDD)
- Source of truth: `docs/cahier-des-charges.md` v0.18 — §9.4 (l. 1220–1297), §9.5 (l. 1298–1438), §9.7 (l. 1513–1524), §6.3.1 (l. 428–517)
- Decision record: `docs/decisions/ADR-0002-storage-format-versioning.md`
- Schemas: [`schemas/meta.v1.schema.json`](schemas/meta.v1.schema.json), [`schemas/state.v1.schema.json`](schemas/state.v1.schema.json), [`schemas/idempotency.v1.schema.json`](schemas/idempotency.v1.schema.json)

Wording: **MUST / MUST NOT** restate a requirement of the spec (with its line reference). Anything labelled **Proposed (non-normative)** is a suggestion of this document that the spec does not fix; it must be confirmed (see [Open questions](#13-open-questions)) before it becomes normative. All values in examples are dummies.

## 1. Scope and invariants

Persistence is **local files only** (§9.4.1, l. 1297): no SQL, SQLite, NoSQL, Redis/Valkey or any database service is installed, configured or required. Concurrency control uses `flock()` directly on lock files (no Symfony Lock component); durability uses a temporary file in the same directory, `fsync()`, then an atomic `rename()` (or `link()` for write-once publication).

Confidentiality invariant (§9.4.1, l. 1270; §9.4, l. 1233, 1235; §6.3.1, l. 517). The store MUST NEVER contain:

- the plaintext, the URL-fragment key (`K_url`) or any key derived from it, any private key, `K_pass` or the passphrase;
- the raw deletion token (only a hash/verifier, `meta.json` → `deletion_token_hash`);
- the raw reservation identifier (only its hash, `state.json` → `reservation.id_hash`);
- the raw `Idempotency-Key` (only its hash, idempotency record → `key_hash`);
- the full share URL or the fragment.

What the store MAY contain: the ciphertext and its nonce (`payload.bin`), the canonical AAD (public data: protocol version, public KDF parameters, access and consume public keys), dates, flags, counters, the consume challenge (not a secret, §6.3.1, l. 456), and hashes listed above. §9.4 (l. 1222) *limits* the record to its enumerated fields: adding a field requires a spec change.

Deployment assumptions (§9.4.1, l. 1280–1284, 1296; §9.5, l. 1410): single application instance, local journaled filesystem (ext4 `data=ordered`/`journal`, XFS), root filesystem read-only, only the data volume (and explicitly required temp dirs) writable, dedicated non-root system account, storage outside `public/`. `app:boot` refuses unsupported filesystems unless `storage.allow_unsupported_fs` (logged warning) and verifies that `link()` and `rename()` work on the volumes (§9.5, l. 1312).

## 2. Directory tree

```text
/var/lib/quietlink/                       # data volume (only writable mount besides tmp)
├── pastes/                               # storage.root_dir
│   └── <s1>/                             # 2 first chars of the encoded id
│       └── <s2>/                         # chars 3–4 of the encoded id
│           ├── .<id>.tmp-<rand>/         # creation staging dir (transient)
│           └── <id>/
│               ├── payload.bin           # immutable: nonce ‖ ciphertext (removed on consume/delete)
│               ├── meta.json             # immutable, schema meta.v1
│               ├── state.json            # mutable, schema state.v1
│               ├── .state.json.tmp-<rand># transient temp file of a state write
│               └── state.lock            # flock() target, never recreated
├── idempotency/                          # storage.idempotency_dir
│   └── <s1>/<key_hash>.json              # Proposed (non-normative) layout, schema idempotency.v1
├── ratelimit/                            # storage.ratelimit_dir (out of scope of this document)
└── state/                                # storage.state_dir
    ├── usage.json                        # quota counters (bytes, item count)
    ├── health.json                       # free space / inodes, timestamped (purge, app:boot)
    ├── boot.json                         # boot marker (validated config fingerprint)
    ├── usage.lock                        # created by app:boot
    └── purge.lock                        # global purge lock

/var/lib/quietlink-generated/             # storage.generated_assets_dir, separate volume
└── tokens.<hash>.css
```

Source: §9.4.1, l. 1244–1265; §9.5, l. 1365–1372.

### 2.1 Sharding

- Pastes MUST be partitioned by prefixes of their identifier (l. 1242). The id is 24 bytes (64 random bits + two 64-bit fingerprints, §9.4 l. 1237).
- **Proposed (non-normative):** the id is encoded base64url without padding (32 chars); `<s1>` = chars 1–2, `<s2>` = chars 3–4. With 64 symbols this gives 4096 × 4096 buckets, far more than `max_items` = 100,000. The first 8 bytes (`A`) are a hash of `access_pk`, so prefixes are uniformly distributed. Base64url is case-sensitive, which is fine on the supported filesystems (ext4/XFS). See OQ-03.
- Shard directories are created on demand (`mkdir` with `0700`, tolerate `EEXIST`) and are not removed when empty (Proposed (non-normative): avoids a race between `rmdir` of a shard and a concurrent creation; at most 16.7 M empty dirs is not reachable in practice, but see OQ-14).

### 2.2 Ownership, permissions, umask

- All directories and files MUST belong to the dedicated PHP system account and be unreachable by the static web server (l. 1281); paths MUST NOT traverse an outgoing symlink (l. 1410); automatic creation applies restrictive permissions (l. 1411).
- **Proposed (non-normative):** process umask `0077`; directories `0700`; files `0600`; `generated_assets_dir` is the only exception (readable by the web server container) and is out of scope here.
- The purge and `app:boot` MUST refuse outgoing symlinks (l. 1289). Proposed (non-normative): open paths with `lstat()` checks on every component below the configured root, and reject any entry that is not a regular file/directory of the expected owner.

## 3. Files

| File | Mutability | Written by | Schema |
|---|---|---|---|
| `payload.bin` | write-once; unlinked on consume/delete | create | binary (nonce ‖ ciphertext) |
| `meta.json` | write-once | create | [`meta.v1`](schemas/meta.v1.schema.json) |
| `state.json` | rewritten atomically under `state.lock` | create, open, consume, release, delete, purge | [`state.v1`](schemas/state.v1.schema.json) |
| `state.lock` | created once with the staging dir; content empty | create | — |
| idempotency record | write-once (published with `link()`) | create | [`idempotency.v1`](schemas/idempotency.v1.schema.json) |
| `usage.json` | rewritten atomically under `usage.lock` | create, delete paths, purge | [`usage.v1`](schemas/usage.v1.schema.json) |
| `health.json`, `boot.json` | rewritten atomically | purge, `app:boot` | [`health.v1`](schemas/health.v1.schema.json), [`boot.v1`](schemas/boot.v1.schema.json) |

All JSON files carry an integer `schema_version` (ADR-0002). Proposed (non-normative) encodings: binary values in base64url without padding, timestamps as integer Unix seconds (same convention as the challenge `issued_at`, §6.3.1 l. 442), UTF-8 JSON without BOM.

### 3.1 `payload.bin`

Raw bytes `nonce ‖ ciphertext` (l. 1249). Its size is what is charged to the byte quota (l. 1291). Present while the paste is `available` or `reserved`; unlinked under lock right after `consumed` or `deleted` is written (l. 1272–1273).

### 3.2 `meta.json` (immutable)

| Field | Type | Source |
|---|---|---|
| `schema_version` | `1` | ADR-0002, l. 1234 |
| `id` | string, 24 bytes b64url | l. 1224 |
| `aad` | string, canonical AAD bytes b64url (protocol version, KDF params, `access_pk`, `consume_pk`) | l. 1226 |
| `created_at` | int | l. 1227 |
| `expires_at` | int or `null` (never) | l. 1228 |
| `read_once` | bool | l. 1229 |
| `deletion_token_hash` | string (hash, never the raw token) | l. 1233 |
| `idempotency_key_hash` | string, SHA-256 of the raw `Idempotency-Key` (never the raw key) | §10, creation step 3 and orphan purge (step 6) |

The AAD is stored byte-exact; the server compares the request's `access_pk` with the one inside the stored AAD (§6.3.1 l. 436). The nonce lives in `payload.bin`, not in `meta.json`.

### 3.3 `state.json` (mutable)

| Field | Type | Meaning | Source |
|---|---|---|---|
| `schema_version` | `1` | | ADR-0002 |
| `state` | `available` \| `reserved` \| `consumed` \| `deleted` | | l. 1230 |
| `terminal_at` | int or `null` | time of transition to `consumed` or `deleted` | l. 1230 |
| `unconfirmed_opens` | int ≥ 0 | released-without-confirmation counter | l. 1232, 495 |
| `reservation` | `null` or object | | l. 1231 |
| `reservation.id_hash` | string | hash of the 128-bit client reservation id | l. 489, 1231 |
| `reservation.consume_challenge` | string (82 bytes b64url) | stored as-is, compared in constant time | l. 456, 1231 |
| `reservation.expires_at` | int | reservation deadline | l. 1231 |
| `consumed_signature_hash` | string or `null` | hash of the consuming signature | l. 494, 1231 |

`reservation` and `consumed_signature_hash` MUST remain after `consumed` (l. 497) for idempotent `consume` replay. On a release back to `available`, `reservation` is reset to `null` (Proposed (non-normative)).

A freshly created paste has `{"state":"available","terminal_at":null,"unconfirmed_opens":0,"reservation":null,"consumed_signature_hash":null}`.

### 3.4 Idempotency record

Fields (l. 1235): `key_hash`, `request_sha256` (SHA-256 of the request body), `paste_id`, `expires_at` (of the created paste), `retain_until` (retention deadline) plus `schema_version`. See §8.

## 4. Existence rule

A paste exists **only** if its final directory `<id>/` contains `meta.json`, `state.json`, `state.lock`, and `payload.bin` unless the state is `consumed` (l. 1271). In addition (l. 1274) the state MUST NOT be `deleted`, and it is never served once `meta.expires_at` ≤ now (expired, l. 1343) or once `consumed` (l. 516). Any other situation yields the uniform public `404`.

Readers MUST fail closed (ADR-0002): unreadable JSON, schema violation, unknown or missing `schema_version` ⇒ public `404`, internal error logged **without** the id or any secret.

## 5. Lock protocol

### 5.1 Locks

| Lock | Mode | Operations | Source |
|---|---|---|---|
| `<id>/state.lock` | `LOCK_EX` | reserve (`open` of read-once), resume, release of expired reservation, `consume`, delete, purge of a paste | l. 1238, 1276 |
| `<id>/state.lock` | `LOCK_SH` | Proposed (non-normative): `status` and `open` of a non-read-once paste when no transition is needed (see 5.4) | — |
| `state/usage.lock` | `LOCK_EX` | any read-modify-write of `usage.json` (quota check + reservation at create, decrements, purge delta) | l. 1291, 1521 |
| `state/purge.lock` | `LOCK_EX \| LOCK_NB` | whole purge run; if busy, exit immediately with success | l. 1289 |

### 5.2 Opening `state.lock`

1. `state.lock` is created only inside the staging directory at creation. Afterwards it MUST be opened **without creation**: `fopen($path, 'r+')`, never `c`, `a`, `w`, `x` (l. 1277). `ENOENT` ⇒ paste does not exist ⇒ uniform `404`.
2. Acquire `flock()`.
3. **After** acquisition, verify identity: `fstat($fd)` and `stat("<id>/state.lock")` MUST have the same `dev` and `ino` (l. 1274). Mismatch or missing path ⇒ abort with the generic unavailability response.
4. Re-read `meta.json` and `state.json`. Abort if `state = deleted`, or if `state = consumed` except for an idempotent `consume` replay (l. 1274).
5. Perform the transition, release the lock (`flock(LOCK_UN)` then `fclose`).

### 5.3 Lock order (deadlock avoidance)

**Proposed (non-normative)** global order, the spec being silent (OQ-05):

```text
purge.lock  →  <id>/state.lock (one at a time)  →  usage.lock
```

- Never hold two `state.lock` at once.
- Never acquire `state.lock` while holding `usage.lock`; `usage.lock` is the innermost lock and is held only for an in-memory read-modify-write and one atomic file write.
- Creation takes `usage.lock` alone (quota reservation), releases it, then builds the paste; it never holds a `state.lock` of another paste.

### 5.4 Shared vs exclusive

The spec requires `LOCK_EX` for read-once, reservation and deletion transitions (l. 1276). Because an expired reservation is released "under lock at the next request on the paste" (l. 498), any request on a read-once paste may need a write. **Proposed (non-normative):** take `LOCK_EX` for every request on a read-once paste (cheap: the critical section is a small JSON read/write), and `LOCK_SH` for reads of non-read-once pastes (prevents serving during a concurrent delete).

### 5.5 Timeouts

`flock()` has no timeout. **Proposed (non-normative):** request-path code uses `LOCK_EX|LOCK_NB` in a retry loop with a short total budget (order of 1–2 s), then returns the generic unavailability response; the purge uses `LOCK_NB` per paste and skips busy pastes until the next run. The budget value is undecided (OQ-06).

### 5.6 Crash while holding a lock

`flock()` locks are released by the kernel when the process dies (l. 1286). Because every mutation is a single atomic `rename()`, a crash leaves either the old or the new `state.json`, plus possibly an orphan temp file (§6.3). Multi-step operations are made restartable:

| Crash point | Effect | Recovery |
|---|---|---|
| during staging-dir write | orphan `.<id>.tmp-<rand>/`; quota already reserved | purge removes old staging dirs (§6.3); hourly usage recompute corrects the counters |
| after `consumed` written, before `payload.bin` unlink | `consumed` with payload still on disk (never served) | purge unlinks `payload.bin` for any `consumed`/`deleted` paste and decrements bytes (Proposed (non-normative)) |
| after `deleted` written | partial directory | purge completes deletion of any `deleted` paste (l. 1273) |
| after `state.json` unlinked | dir without `state.json`/`state.lock` | treated as deleted and removed by purge/`app:boot` (l. 1275) |
| between `usage.json` reserve and failure handling | counter drift | hourly recompute (l. 1521) |

## 6. Atomic write procedures

### 6.1 State update (`state.json`)

Under `LOCK_EX` on `state.lock` (l. 1278):

1. Serialize the new state; validate it against `state.v1` in debug/test builds.
2. Create `<id>/.state.json.tmp-<rand>` with `fopen(..., 'x')` (exclusive create, `0600`), `<rand>` from `random_bytes()` (Proposed: 8 bytes hex).
3. `fwrite()` the full content; check the written length (short write ⇒ failure).
4. `fflush()` + `fsync()` (PHP ≥ 8.1) on the file.
5. `rename(tmp, "<id>/state.json")` — atomic replacement.
6. Directory sync of `<id>/` when the runtime allows it (l. 1279); otherwise durability relies on the supported journaled filesystem. Proposed (non-normative): attempt `fsync()` on a directory handle and treat failure as non-fatal, logged once at debug level.
7. On any failure before step 5: `unlink(tmp)`, keep the previous `state.json`, return a generic error. The in-memory transition MUST NOT be reported as done.

### 6.2 Creation (paste)

1. Validate request; compute ciphertext size `n = strlen(nonce ‖ ciphertext)`.
2. Check free space with `disk_free_space()` against `storage.min_free_bytes` (l. 938) and, Proposed (non-normative), the last `health.json` inode measurement.
3. Under `usage.lock`: read `usage.json`; refuse if `bytes + n > max_total_bytes` or `items + 1 > max_items`; else write `bytes += n`, `items += 1` atomically (§6.1 procedure in `state/`). Release `usage.lock`.
4. Draw the server random part of the id; compute final path `<s1>/<s2>/<id>/`; `mkdir -p` the shard dirs.
5. `mkdir("<s1>/<s2>/.<id>.tmp-<rand>/", 0700)` in the **same parent** as the final dir.
6. In it, create and fsync `payload.bin`, `meta.json`, `state.json` (initial), and `state.lock` (empty) — the only moment `state.lock` is created (l. 1277).
7. fsync the staging directory (best effort, see 6.1 step 6).
8. Check that `<id>/` does not exist (l. 1271: on Linux `rename()` of a directory succeeds over an *empty* target), then `rename(staging, "<id>")`. On `EEXIST`/`ENOTEMPTY` or if `<id>/` exists: draw a new random part, rename/rebuild staging under the new id, retry.
9. fsync the shard directory (best effort).
10. Publish the idempotency record if an `Idempotency-Key` was supplied (§8.2).
11. On failure at any step after 3: remove the staging dir (or the paste dir if already renamed, using the delete procedure), then under `usage.lock` decrement `bytes -= n`, `items -= 1` (l. 1291).

Proposed (non-normative): the check in step 8 has a theoretical TOCTOU window with an *empty* `<id>/`; this is harmless because no code path creates an empty `<id>/` (shards contain only staging and final dirs, final dirs are never empty by l. 1275), and the probability of an id collision is 2^-64 (l. 1271).

### 6.3 Orphan cleanup

Orphans: `.<id>.tmp-<rand>/` staging dirs and `.state.json.tmp-<rand>` files. They MAY be removed only after checking their age **and** the absence of an active lock (l. 1286).

- **Proposed (non-normative):** minimum age 1 hour (mtime); for a staging dir, open its `state.lock` (if present) with `r+` and require `flock(LOCK_EX|LOCK_NB)` to succeed; for a state temp file, hold the paste's `LOCK_EX` while unlinking it.
- Temp files MUST NOT be created in a shared or web-exposed directory (l. 1285): never `sys_get_temp_dir()`.

### 6.4 Paste deletion (manual, expiry, consumed > 10 min)

Two phases (l. 1273, 1275), all under `LOCK_EX` on `state.lock` in one operation:

1. Open + lock + verify identity (§5.2). A manual deletion with a valid token removes the paste in any state, `consumed` included (spec §10, l. 1622; ADR-0008 resolves the conflict with l. 1290 in favour of §10).
2. Write `state.json` = `deleted`, `terminal_at = now` (§6.1).
3. `unlink(payload.bin)` if present; under `usage.lock`: `bytes -= size(payload.bin)` (size measured with `fstat`/`stat` before unlink).
4. `unlink(meta.json)`, `unlink(state.json)`, then `unlink(state.lock)` last among files (the flock on the open descriptor stays valid), then `rmdir(<id>)`. `ENOENT` at any step is tolerated (idempotence).
5. Under `usage.lock`: `items -= 1` (only when the directory is removed, l. 1291).
6. Directory sync of the shard dir (best effort); release and close the lock descriptor.

Note: l. 1273 lists "the other files then the directory, `state.lock` last"; since `rmdir()` needs an empty directory, `state.lock` is unlinked last *among files*, before `rmdir` (OQ-12).

## 7. Paste state machine

Persistent states: `available`, `reserved`, `consumed`, `deleted` (technical). `confirmed` is not persistent (l. 486). **Expired** is not a stored state: it is the predicate `meta.expires_at ≠ null ∧ now ≥ meta.expires_at`, and it wins over any reservation (l. 516).

```text
                   create
                     │
                     ▼
   ┌──────────►  available ──── open(rid) [read_once, not expired] ───►  reserved
   │                 │                                                   │  │  │
   │                 │                    open(same rid) = resume ◄──────┘  │  │
   │                 │                    (same payload & challenge,        │  │
   │                 │                     no counter change, no extension) │  │
   │   release [res.expires_at ≤ now ∧ unconfirmed_opens+1 < max]           │  │
   └────────────────────────────────────────────────────────────────────────┘  │
                     │                                                         │
                     │      consume(valid sig, rid, challenge) [not expired] ──┤
                     │      release [unconfirmed_opens+1 ≥ max] ───────────────┤
                     │                                                         ▼
                     │                                                     consumed ── purge [now ≥ terminal_at+10 min] ──► deleted ──► (removed)
                     │
                     └── delete (manual) / purge [expired] ──────────────────────────────────────────────► deleted ──► (removed)
   reserved ── delete/purge [expired] ──► deleted
```

### 7.1 Transition table

| # | From | Event / guard | To | Writes | Source |
|---|---|---|---|---|---|
| T1 | — | create | `available` | staging dir, usage +n/+1 | l. 1271, 1291 |
| T2 | `available` | `open` with valid proof, read-once, not expired | `reserved` | `reservation = {id_hash, consume_challenge, expires_at = now + read_once_reservation_ttl}` | l. 489, 1326 |
| T3 | `reserved` | `open` with same reservation id, reservation active | `reserved` (no change) | none; return stored payload + challenge | l. 489, 503 |
| T4 | `reserved` | `open` with other reservation id, active | `reserved` (no change) | none; "reserved" answer with remaining time, no payload | l. 490 |
| T5 | `reserved` | reservation expired, `unconfirmed_opens + 1 < max_unconfirmed_opens`, applied at next request or purge | `available` | `unconfirmed_opens += 1`, `reservation = null` | l. 495, 498 |
| T6 | `reserved` | reservation expired, `unconfirmed_opens + 1 ≥ max_unconfirmed_opens` | `consumed` | `unconfirmed_opens += 1`, `terminal_at = now`; unlink payload, usage −size | l. 496 |
| T7 | `reserved` | `consume`: signature valid for `consume_pk`, reservation id hash matches, challenge equal (constant time), reservation active, paste not expired | `consumed` | `terminal_at = now`, `consumed_signature_hash`; then unlink payload, usage −size | l. 494, 516 |
| T8 | `consumed` | exact replay of the same proof (same rid, challenge, signature) | `consumed` (no change) | none; same success | l. 497 |
| T9 | `available`/`reserved` | manual delete with valid deletion token, or purge of an expired paste | `deleted` → removed | §6.4 | l. 1273 |
| T10 | `consumed` | purge, `now ≥ terminal_at + 10 min` | `deleted` → removed | §6.4 | l. 1290 |
| T11 | `consumed` | manual delete with a valid token | `deleted` → removed | §6.4 | §10 l. 1622, ADR-0008 |
| T12 | `deleted` | purge | removed | finish §6.4 | l. 1273 |

Non-read-once pastes only use T1, T9, T12 (`open` serves the payload without state change).

Any other (state, event) pair is rejected with the uniform response. In particular: no transition out of `deleted` except removal; `consumed` never returns to `available` or `reserved`; `consume` on an expired paste is refused (l. 516) and the paste is not consumed (Proposed (non-normative): it stays `reserved` until purge deletes it as expired).

Whether T6 is evaluated with "reaches the threshold" after incrementing is ambiguous; this document reads l. 496 as `unconfirmed_opens` (after increment) `≥ max_unconfirmed_opens` ⇒ `consumed`. With default 3, the third unconfirmed release destroys the paste.

## 8. Idempotency records

### 8.1 Layout

- Directory `storage.idempotency_dir`; one file per key, written once (l. 1235).
- **Proposed (non-normative):** path `idempotency/<s1>/<key_hash>.json`, `<s1>` = first 2 chars of `key_hash`; schema [`idempotency.v1`](schemas/idempotency.v1.schema.json).
- Content: `key_hash`, `request_sha256`, `paste_id`, `expires_at` (of the paste), `retain_until`, `schema_version`. No raw key, no plaintext, no raw deletion token (l. 1288).
- `retain_until` is bounded by `paste.idempotency_max_ttl` (default `24h`, allowed 1 h–7 d, l. 1336, 1424), independently of the paste expiry (l. 1288). Proposed (non-normative): `retain_until = created_at + idempotency_max_ttl`.

### 8.2 Write-once publication with `link()`

1. Write the record to a temp file in the same shard directory (`fopen 'x'`, write, `fsync`).
2. `link(tmp, "<key_hash>.json")`: succeeds only if the target does not exist (atomic first-writer-wins).
3. `unlink(tmp)` in all cases; directory sync best effort.
4. On `EEXIST` (another request won): read the existing record (fail closed on bad schema). This request is the **loser**: delete the paste it just created (§6.4) and decrement usage (l. 1291), then answer from the winner's record if `request_sha256` matches and `retain_until > now`, or with the idempotency conflict response otherwise (response codes belong to the API spec).

Proposed (non-normative): an early read of the record *before* building the paste avoids most losers; `link()` remains the authority.

### 8.3 Expiry

A record with `retain_until ≤ now` is ignored by readers and unlinked by the purge (l. 1521). No lock is needed: records are immutable and unlinking is atomic; a reader that opened the file before unlink still sees a consistent content.

## 9. Quota accounting

Limits (§9.5 l. 1368–1371): `max_total_bytes` = 10,737,418,240 (10 GiB), `max_items` = 100,000, `min_free_bytes` = 1 GiB, `min_free_inodes_percent` = 10.

`usage.json` holds `bytes` (sum of `payload.bin` sizes still on disk) and `items` (number of `<id>/` directories, including `consumed` and `deleted` ones not yet removed). Content: `{"schema_version":1,"bytes":0,"items":0,"recomputed_at":null,"generation":0}` (`generation` is optional when reading).

Rules (l. 1291):

| Event | `bytes` | `items` |
|---|---|---|
| creation (reserved before writing, under `usage.lock`, with the quota check) | `+n` | `+1` |
| creation failure / idempotency loser | `−n` | `−1` |
| `payload.bin` unlinked (consume, threshold, delete, expiry) | `−size` | — |
| `<id>/` directory removed | — | `−1` |

Consistency:

- Check and increment happen in one `usage.lock` critical section, so concurrent creations cannot exceed the quotas.
- Decrements are applied under `usage.lock` by whoever performs the unlink/rmdir, after the filesystem operation succeeded (never before), and never go below zero (clamp + log).
- Drift sources (crash between filesystem operation and counter update, orphan staging dirs) are corrected by the purge's full recompute, **at most once per hour** (l. 1521), in its own read-only pass after the removals: read the counters and their `generation`, scan `pastes/` without `usage.lock`, then under `usage.lock` replace the counters with the observed totals **only if** `generation` is unchanged. Every reservation, release and completed creation (after its rename) increments `generation`, so any concurrent change, even one that leaves the totals unchanged, defers the recomputation, which is retried 10 minutes later rather than at every purge run (OQ-09, resolved).

## 10. Purge (`app:purge-expired`, §9.7)

Preconditions: `state/boot.json` present and matching the loaded configuration, else refuse to run (l. 1521). Acquire `purge.lock` with `LOCK_EX|LOCK_NB`; if busy, exit immediately (l. 1289). Recommended schedule: every minute.

Steps (each idempotent):

1. Walk only `pastes/<s1>/<s2>/` entries matching the expected naming; refuse symlinks (l. 1289).
2. For each `<id>/`:
   - missing `state.json` or `state.lock` ⇒ treat as deleted, remove remaining files and the directory, `items −= 1`, and `bytes −= size` if a `payload.bin` remained (l. 1275);
   - else lock it (§5.2, non-blocking per Proposed 5.5) and apply, in order: finish `deleted` (T12); expired (any non-terminal state) ⇒ delete (T9); `consumed` with `now ≥ terminal_at + 10 min` ⇒ delete (T10); `consumed` with leftover `payload.bin` ⇒ unlink it; `reserved` with expired reservation ⇒ release (T5/T6).
3. Remove orphan staging dirs and state temp files (§6.3).
4. Unlink idempotency records with `retain_until ≤ now`.
5. Remove expired rate-limiting entries (out of scope here).
6. Update `health.json` (free bytes, free inode percentage, timestamp) atomically.
7. At most once per hour, recompute `usage.json` (§9).

Concurrency with reads: the purge never touches a paste without its `LOCK_EX`; requests re-check identity and state after acquiring their own lock (§5.2), so a request that raced with a deletion sees `deleted`, a different inode, or a missing path and returns `404`. Expired pastes are refused by requests as soon as they expire, regardless of whether the purge has run (l. 1343). Opportunistic cleanup on requests remains a safety net only (l. 1521).

## 11. Versioning and migration

- Every JSON file has integer `schema_version`; v1 schemas live in `docs/schemas/<name>.v<N>.schema.json` (ADR-0002).
- Readers accept only versions they know; unknown/missing ⇒ fail closed (`404` public, internal log without identifiers).
- Writers always write the newest version they support. A paste's `meta.json` is immutable, so it keeps its creation version for life; `state.json` MAY be upgraded in place by an explicit migration, under `LOCK_EX`, through the §6.1 procedure.
- Migrations are explicit, tested code paths (a fixture per old version), executed lazily per file or by a dedicated CLI command (Proposed (non-normative): `app:storage:migrate`, run before `app:boot` succeeds when a store contains versions the new code can no longer read).
- Any incompatible change of the storage format is a breaking change (`!` in Conventional Commits, ADR-0002). Changes to the encrypted payload or AAD format are governed by the protocol versioning (§8, `sp-proto/v1`), not by `schema_version`.

## 12. Failure modes

| Failure | Behaviour |
|---|---|
| Disk full / `ENOSPC` on temp write | temp file unlinked, previous `state.json` intact, generic error; creation rolls back usage. Creation is refused beforehand when below `min_free_bytes` (l. 938). |
| Inode exhaustion | detected by `health.json` (purge and `app:boot`, since PHP cannot read inode counts, l. 939); creation refusal policy is OQ-08. Idempotency retention is bounded to avoid it (l. 1288). |
| Partial / short write | detected by length check before `rename()`; never published. A reader that still finds an invalid JSON fails closed (`404`). |
| Power loss | supported journaled FS + `fsync()` on files; directory fsync best effort (l. 1279); worst case a recent transition is lost but no torn file is visible. |
| Crash mid-operation | see §5.6. |
| Clock skew / jump | all timestamps use the server wall clock (`time()`). A backward jump extends reservations/expiry; a forward jump releases reservations and expires pastes early. Not specified (OQ-07). Proposed (non-normative): require NTP-synced host, log a warning when `now < created_at` of a freshly read file, never compute negative remaining times (clamp to 0). |
| `state.lock` missing | paste does not exist (l. 1277). |
| Lock inode mismatch | abort, `404` (l. 1274). |
| Unsupported FS (NFS, SMB, FUSE) | `app:boot` refuses (l. 1280, 1283). |
| Read-only data volume | `app:boot` fails (path and `link()`/`rename()` checks, l. 1312). |
| Backup | from a consistent snapshot or stopped/read-only service only; live `tar` is not a backup (l. 1293–1294). |

## 13. Open questions

| ID | Topic | Spec reference | Question |
|---|---|---|---|
| OQ-01 | Hash algorithms | §9.4 l. 1231, 1233, 1235 | Which hash (and whether keyed) for `deletion_token_hash`, `reservation.id_hash`, `consumed_signature_hash`, `key_hash`? A keyed hash under `QUIETLINK_APP_SECRET` would break on secret rotation (cf. l. 456 rationale); a plain SHA-256 of a 128/256-bit random value is sufficient. Must be fixed with test vectors. |
| OQ-02 | Idempotency key scope | §9.4 l. 1235 | Is the key global or scoped (e.g. per client)? Two clients using the same key would collide; the spec only says "hash of the idempotency key". |
| OQ-03 | Id encoding and shard prefix | §9.4.1 l. 1242, 1246–1247 | Which encoding (base64url assumed) and which characters form `ab/cd`? |
| OQ-04 | Timestamp and binary encodings | §9.4 l. 1222–1235 | Unix seconds vs RFC 3339; base64url vs hex. Proposed: Unix seconds + base64url. |
| OQ-05 | Lock order | §9.4.1 l. 1276, 1291 | No order defined between `state.lock` and `usage.lock`; proposed `purge → state → usage`. |
| OQ-06 | Lock timeouts | §9.4.1 l. 1276 | Blocking vs non-blocking `flock()` and maximal wait on request paths; response on timeout. |
| OQ-07 | Clock source and skew | §6.3.1 l. 495, 516; §9.4 l. 1231 | Wall clock assumed; no rule for backward/forward jumps. |
| OQ-08 | Inode threshold at creation | §9.5 l. 1335, 1371 | Is `min_free_inodes_percent` enforced per creation (using `health.json`, how stale?) or only reported? |
| OQ-09 | Hourly usage recompute | §9.7 l. 1521 | How to "apply the observed difference without overwriting concurrent creations" exactly; scan is not atomic. **Resolved:** applied only when no concurrent change happened (§9). |
| OQ-10 | Schemas for `usage.json`, `health.json`, `boot.json` | §9.4.1 l. 1259–1261 | ADR-0002 requires schemas for every JSON file; their fields are not specified. |
| OQ-11 | Delete while `reserved` | §9.4.1 l. 1273; §6.3.1 l. 489–498 | Is manual deletion allowed during an active reservation (assumed yes: T9)? |
| OQ-12 | Deletion file order | §9.4.1 l. 1273 vs 1275 | "`state.lock` last" vs `rmdir` requiring an empty dir; interpreted as last *file* before `rmdir`. |
| OQ-13 | Threshold semantics and payload on T6 | §6.3.1 l. 495–496; §9.4.1 l. 1272 | Is the threshold compared after increment? Does T6 also unlink `payload.bin` and start the 10-min window (assumed yes)? |
| OQ-14 | Empty shard directories | §9.4.1 l. 1242, 1275 | May empty `ab/` and `ab/cd/` be removed by the purge? Proposed: never removed. |
| OQ-15 | Orphan age threshold | §9.4.1 l. 1286 | Minimum age before removing temp files/dirs is not specified; proposed 1 h. |
| OQ-16 | `root_dir` meaning | §9.4.1 l. 1245, 1280 vs §9.5 l. 1365 | Tree shows `/var/lib/quietlink/` as root with `pastes/` inside, while the config sets `storage.root_dir` to `/var/lib/quietlink/pastes`; FS check at l. 1280 targets `root_dir` only — should it cover all storage dirs? |
| OQ-17 | `payload.bin` size bookkeeping | §9.4 l. 1222; §9.4.1 l. 1291 | Field list is closed, so the ciphertext size is not stored; decrements rely on `stat()` before unlink. If a crash happens after unlink but before the decrement, only the hourly recompute fixes it. Acceptable? |
| OQ-18 | `idempotency.expires_at` meaning | §9.4 l. 1235 | Assumed to be the paste's `expires_at` (returned on replay); confirm. Also whether `retain_until` should be `min(created_at + idempotency_max_ttl, paste expiry)`. |
| OQ-19 | `consume` on expired reserved paste | §6.3.1 l. 516 | State after refusal is not specified (assumed: unchanged until purge deletes it as expired). |
| OQ-20 | Directory fsync in PHP | §9.4.1 l. 1279 | Which runtime mechanism is accepted (`fopen` on a directory + `fsync` is not portable); define the "when the runtime allows it" test. |
