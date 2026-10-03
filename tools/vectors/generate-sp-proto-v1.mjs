#!/usr/bin/env node
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Reproducible generator for the sp-proto/v1 shared test vectors.
//
// It relies only on node:crypto (Node >= 24.7 for argon2Sync) so that the
// vectors are produced by an implementation independent from the PHP
// backend, the TypeScript frontend and the CLI that consume them.
// Every input is a fixed dummy value: running the script twice yields
// byte-identical output.
//
// Usage: node tools/vectors/generate-sp-proto-v1.mjs > tests/vectors/sp-proto-v1.json
//        node tools/vectors/generate-sp-proto-v1.mjs --check tests/vectors/sp-proto-v1.json

import crypto from 'node:crypto';
import { readFileSync } from 'node:fs';

const PROTO = 'sp-proto/v1';
const HKDF_SALT = Buffer.from(`${PROTO}/hkdf`, 'ascii');
const INFO_ACCESS = `${PROTO}/access/ed25519`;
const INFO_CONTENT = `${PROTO}/content/aes-256-gcm`;
const INFO_CONSUME = `${PROTO}/read-once/consume/ed25519`;
const INFO_CHALLENGE = `${PROTO}/server/challenge`;
const PKCS8_PREFIX = Buffer.from('302e020100300506032b657004220420', 'hex');
const PROOF_PREFIX = Buffer.concat([Buffer.from(`${PROTO}/proof`, 'ascii'), Buffer.from([0])]);

// Open questions of docs/protocol/sp-proto-v1.md whose "Proposed" answer is
// applied by these vectors. They must be confirmed before the vectors are frozen.
const ASSUMPTIONS = {
  'OQ-1': 'Context strings are raw ASCII bytes, no length prefix, no terminator.',
  'OQ-2': 'Envelope plaintext bytes are an opaque fixed input (keys in example order, no whitespace, raw UTF-8).',
  'OQ-3': 'The consume challenge mac is HMAC-SHA-256 under K_challenge, like other usages.',
  'OQ-9': 'The consume signature uses the same "sp-proto/v1/proof" || 0x00 prefix as the access proof.',
  'OQ-21': 'Binary values inside the AAD must also be canonical base64url.',
};

// Requirement identifiers (docs/traceability.md) covered by each vector group.
const REQUIREMENTS = {
  hkdf_no_passphrase: ['EXG-CRYPTO-035', 'EXG-CRYPTO-036', 'EXG-CRYPTO-064'],
  argon2id: ['EXG-CRYPTO-029', 'EXG-CRYPTO-055', 'EXG-CRYPTO-060', 'EXG-CRYPTO-061'],
  hkdf_with_passphrase: ['EXG-CRYPTO-035', 'EXG-CRYPTO-036', 'EXG-CRYPTO-064'],
  nfc: ['EXG-CRYPTO-029', 'EXG-CRYPTO-055', 'EXG-CRYPTO-060', 'EXG-CRYPTO-061'],
  pkcs8: ['EXG-CRYPTO-070'],
  identifier: ['EXG-CRYPTO-025', 'EXG-CRYPTO-033', 'EXG-URL-011'],
  aad: ['EXG-CRYPTO-043', 'EXG-CRYPTO-044', 'EXG-CRYPTO-045', 'EXG-CRYPTO-046', 'EXG-CRYPTO-047', 'EXG-CRYPTO-048', 'EXG-CRYPTO-049', 'EXG-CRYPTO-062'],
  aad_reject: ['EXG-CRYPTO-043', 'EXG-CRYPTO-044', 'EXG-CRYPTO-045', 'EXG-CRYPTO-046', 'EXG-CRYPTO-047', 'EXG-CRYPTO-048', 'EXG-CRYPTO-049', 'EXG-CRYPTO-062'],
  envelope_encrypt: ['EXG-CRYPTO-031', 'EXG-CRYPTO-052', 'EXG-CRYPTO-068'],
  envelope_reject: ['EXG-CRYPTO-031', 'EXG-CRYPTO-052', 'EXG-CRYPTO-068'],
  challenge: ['EXG-CRYPTO-006', 'EXG-CRYPTO-007'],
  challenge_verify: ['EXG-READ-017', 'EXG-READ-019', 'EXG-READ-024'],
  public_key_reject: ['EXG-CRYPTO-028'],
  proof: ['EXG-CRYPTO-008', 'EXG-CRYPTO-069', 'EXG-CRYPTO-073'],
  consume_proof: ['EXG-CRYPTO-008', 'EXG-CRYPTO-069', 'EXG-CRYPTO-073'],
  delete_check: ['EXG-API-038', 'EXG-CRYPTO-033'],
  base64url_reject: ['EXG-CRYPTO-053', 'EXG-URL-011'],
};

const hex = (b) => Buffer.from(b).toString('hex');
const b64u = (b) => Buffer.from(b).toString('base64url');
const h = (s) => Buffer.from(s, 'hex');
const sha256 = (b) => crypto.createHash('sha256').update(b).digest();
const hmac = (k, m) => crypto.createHmac('sha256', k).update(m).digest();
const extract = (ikm) => hmac(HKDF_SALT, ikm);
const hkdf = (ikm, info) => Buffer.from(crypto.hkdfSync('sha256', ikm, HKDF_SALT, Buffer.from(info, 'ascii'), 32));
const pattern = (start, len) => Buffer.from(Array.from({ length: len }, (_, i) => (start + i) & 0xff));
const tronc64 = (b) => b.subarray(0, 8);
const B64U_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
// Sets an unused low-order bit of the last character: same decoded bytes, non-canonical text.
const nonCanonical = (s) => s.slice(0, -1) + B64U_ALPHABET[B64U_ALPHABET.indexOf(s.at(-1)) | 1];

function ed25519FromSeed(seed) {
  const pkcs8 = Buffer.concat([PKCS8_PREFIX, seed]);
  const sk = crypto.createPrivateKey({ key: pkcs8, format: 'der', type: 'pkcs8' });
  const jwk = crypto.createPublicKey(sk).export({ format: 'jwk' });
  return { sk, pk: Buffer.from(jwk.x, 'base64url'), pkcs8, jwkX: jwk.x };
}

const sign = (sk, msg) => crypto.sign(null, msg, sk);
const verify = (pk, msg, sig) => crypto.verify(
  null,
  msg,
  crypto.createPublicKey({ key: { kty: 'OKP', crv: 'Ed25519', x: b64u(pk) }, format: 'jwk' }),
  sig,
);

function argon2id(passphrase, salt, m, t) {
  return Buffer.from(crypto.argon2Sync('argon2id', {
    message: Buffer.from(passphrase.normalize('NFC'), 'utf8'),
    nonce: salt,
    parallelism: 1,
    tagLength: 32,
    memory: m,
    passes: t,
  }));
}

// RFC 8785 subset of §8.2: sorted keys, no whitespace, integers, ASCII strings without escapes.
function canonicalAad(value) {
  if (value === null || typeof value === 'boolean') return JSON.stringify(value);
  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value) || value < 0 || Object.is(value, -0)) throw new Error('Invalid AAD number');
    return String(value);
  }
  if (typeof value === 'string') {
    if (!/^[\x20-\x7e]*$/.test(value) || /["\\]/.test(value)) throw new Error('Invalid AAD string');
    return `"${value}"`;
  }
  if (Array.isArray(value)) throw new Error('Arrays are forbidden in the AAD');
  return `{${Object.keys(value).sort().map((k) => `"${k}":${canonicalAad(value[k])}`).join(',')}}`;
}

function deriveKeys(kUrl, kPass) {
  const access = ed25519FromSeed(hkdf(kUrl, INFO_ACCESS));
  const ikmContent = kPass ? Buffer.concat([kUrl, kPass]) : kUrl;
  const kEnc = hkdf(ikmContent, INFO_CONTENT);
  const consumeSeed = hkdf(ikmContent, INFO_CONSUME);
  const consume = ed25519FromSeed(consumeSeed);
  return { access, accessSeed: hkdf(kUrl, INFO_ACCESS), ikmContent, kEnc, consumeSeed, consume };
}

function identifier(accessPk, deletionHash, r) {
  const a = tronc64(sha256(Buffer.concat([Buffer.from(`${PROTO}/id`, 'ascii'), Buffer.from([0]), accessPk])));
  const d = tronc64(sha256(Buffer.concat([Buffer.from(`${PROTO}/id-delete`, 'ascii'), Buffer.from([0]), deletionHash])));
  return { a, d, id: Buffer.concat([a, d, r]) };
}

function encrypt(kEnc, nonce, aad, plaintext) {
  const c = crypto.createCipheriv('aes-256-gcm', kEnc, nonce);
  c.setAAD(aad);
  const ct = Buffer.concat([c.update(plaintext), c.final()]);
  const tag = c.getAuthTag();
  return { ciphertext: Buffer.concat([ct, tag]), tag };
}

function decrypts(kEnc, nonce, aad, ciphertextWithTag) {
  try {
    const d = crypto.createDecipheriv('aes-256-gcm', kEnc, nonce);
    d.setAAD(aad);
    d.setAuthTag(ciphertextWithTag.subarray(-16));
    Buffer.concat([d.update(ciphertextWithTag.subarray(0, -16)), d.final()]);
    return true;
  } catch {
    return false;
  }
}

function challenge(kChallenge, usage, id, issuedAt, nonce) {
  const head = Buffer.alloc(34);
  head[0] = 0x01;
  head[1] = usage;
  id.copy(head, 2);
  head.writeBigUInt64BE(BigInt(issuedAt), 26);
  const body = Buffer.concat([head, nonce]);
  const mac = hmac(kChallenge, body);
  return { bytes: Buffer.concat([body, mac]), mac };
}

function flip(buf, index) {
  const out = Buffer.from(buf);
  out[index] ^= 0x01;
  return out;
}

// ---------------------------------------------------------------------------
// Fixed dummy inputs
// ---------------------------------------------------------------------------

const K_URL = pattern(0x00, 32);
const K_URL_2 = pattern(0x80, 32);
const PASSPHRASE = 'correct horse battery staple';
const PASSPHRASE_NFD = 'cafe\u0301 cre\u0300me bru\u0302le\u0301e';
const SALT = Buffer.alloc(16, 0xa5);
const ARGON_FAST = { m: 19456, t: 2 };
const ARGON_DEFAULT = { m: 65536, t: 3 };
const DELETION_TOKEN = pattern(0x40, 32);
const R = h('0011223344556677');
const NONCE = h('000102030405060708090a0b');
const APP_SECRET_B64 = Buffer.from(pattern(0xc0, 32)).toString('base64');
const CHALLENGE_NONCE = pattern(0xe0, 16);
const ISSUED_AT = 1790000000;
const INSTANCE = 'https://paste.example.test';
const ENVELOPE = '{"format":"markdown","language":null,"template":null,"text":"# Dummy\\nNot a secret: example only.","v":1}';

// ---------------------------------------------------------------------------
// Groups
// ---------------------------------------------------------------------------

const noPass = deriveKeys(K_URL);
const kPassFast = argon2id(PASSPHRASE, SALT, ARGON_FAST.m, ARGON_FAST.t);
const kPassDefault = argon2id(PASSPHRASE, SALT, ARGON_DEFAULT.m, ARGON_DEFAULT.t);
const withPass = deriveKeys(K_URL, kPassFast);

const hkdfNoPassphrase = [{
  name: 'random-url-key-no-passphrase',
  input: { k_url: hex(K_URL) },
  expected: {
    prk_url: hex(extract(K_URL)),
    k_access_seed: hex(noPass.accessSeed),
    access_pk: hex(noPass.access.pk),
    access_pk_b64u: b64u(noPass.access.pk),
    ikm_content: hex(noPass.ikmContent),
    prk_content: hex(extract(noPass.ikmContent)),
    k_enc: hex(noPass.kEnc),
    k_consume_seed: hex(noPass.consumeSeed),
    consume_pk: hex(noPass.consume.pk),
    consume_pk_b64u: b64u(noPass.consume.pk),
  },
}];

const argon2Group = [
  { name: 'minimum-cost', params: ARGON_FAST, k: kPassFast },
  { name: 'default-cost', params: ARGON_DEFAULT, k: kPassDefault },
].map(({ name, params, k }) => ({
  name,
  input: {
    passphrase: PASSPHRASE,
    passphrase_nfc_utf8: hex(Buffer.from(PASSPHRASE.normalize('NFC'), 'utf8')),
    salt: hex(SALT),
    m: params.m,
    t: params.t,
    p: 1,
  },
  expected: { k_pass: hex(k) },
}));

const hkdfWithPassphrase = [{
  name: 'url-key-with-minimum-cost-passphrase',
  input: { k_url: hex(K_URL), k_pass: hex(kPassFast) },
  expected: {
    k_access_seed: hex(withPass.accessSeed),
    access_pk: hex(withPass.access.pk),
    ikm_content: hex(withPass.ikmContent),
    prk_content: hex(extract(withPass.ikmContent)),
    k_enc: hex(withPass.kEnc),
    k_consume_seed: hex(withPass.consumeSeed),
    consume_pk: hex(withPass.consume.pk),
    consume_pk_b64u: b64u(withPass.consume.pk),
  },
}];

const nfcGroup = [{
  name: 'decomposed-accents',
  input: {
    passphrase_utf8: hex(Buffer.from(PASSPHRASE_NFD, 'utf8')),
    salt: hex(SALT),
    m: ARGON_FAST.m,
    t: ARGON_FAST.t,
    p: 1,
  },
  expected: {
    nfc_utf8: hex(Buffer.from(PASSPHRASE_NFD.normalize('NFC'), 'utf8')),
    k_pass: hex(argon2id(PASSPHRASE_NFD, SALT, ARGON_FAST.m, ARGON_FAST.t)),
    k_pass_of_precomposed: hex(argon2id(PASSPHRASE_NFD.normalize('NFC'), SALT, ARGON_FAST.m, ARGON_FAST.t)),
  },
}];

const pkcs8Group = [noPass.accessSeed, noPass.consumeSeed].map((seed, i) => {
  const kp = ed25519FromSeed(seed);
  return {
    name: i === 0 ? 'access-seed' : 'consume-seed',
    input: { seed: hex(seed) },
    expected: { pkcs8_der: hex(kp.pkcs8), pkcs8_length: kp.pkcs8.length, jwk_x: kp.jwkX, public_key: hex(kp.pk) },
  };
});

const deletionHash = sha256(DELETION_TOKEN);
const ids = identifier(noPass.access.pk, deletionHash, R);
const identifierGroup = [{
  name: 'no-passphrase-identifier',
  input: {
    access_pk: hex(noPass.access.pk),
    deletion_token: hex(DELETION_TOKEN),
    deletion_token_b64u: b64u(DELETION_TOKEN),
    r: hex(R),
    instance: INSTANCE,
  },
  expected: {
    deletion_hash: hex(deletionHash),
    deletion_hash_b64u: b64u(deletionHash),
    a: hex(ids.a),
    d: hex(ids.d),
    id: hex(ids.id),
    id_b64u: b64u(ids.id),
    share_url: `${INSTANCE}/p/${b64u(ids.id)}#${b64u(K_URL)}`,
    management_url: `${INSTANCE}/manage/${b64u(ids.id)}#${b64u(DELETION_TOKEN)}`,
  },
}];

const aadReadOncePass = {
  v: 1,
  alg: 'A256GCM',
  read_once: true,
  expiration: '1d',
  kdf: { alg: 'argon2id13', m: ARGON_FAST.m, t: ARGON_FAST.t, p: 1, salt: b64u(SALT) },
  access_pk: b64u(withPass.access.pk),
  consume_pk: b64u(withPass.consume.pk),
};
const aadPlain = {
  v: 1,
  alg: 'A256GCM',
  read_once: false,
  expiration: '7d',
  kdf: null,
  access_pk: b64u(noPass.access.pk),
  consume_pk: null,
};
const aadGroup = [
  { name: 'read-once-with-passphrase', object: aadReadOncePass },
  { name: 'plain-no-passphrase', object: aadPlain },
].map(({ name, object }) => {
  const text = canonicalAad(object);
  return { name, input: { object }, expected: { canonical: text, hex: hex(Buffer.from(text, 'ascii')), length: text.length, b64u: b64u(Buffer.from(text, 'ascii')) } };
});

const plainCanonical = canonicalAad(aadPlain);
const replaceIn = (search, replacement) => plainCanonical.replace(search, replacement);
const aadReject = [
  ['whitespace', replaceIn('"alg":', '"alg": ')],
  ['wrong-key-order', replaceIn(`"access_pk":"${aadPlain.access_pk}","alg":"A256GCM"`, `"alg":"A256GCM","access_pk":"${aadPlain.access_pk}"`)],
  ['duplicate-key', replaceIn('"v":1}', '"v":1,"v":1}')],
  ['unknown-key', replaceIn('"expiration":"7d",', '"expiration":"7d","extra":null,')],
  ['missing-key', replaceIn('"kdf":null,', '')],
  ['float', replaceIn('"v":1}', '"v":1.0}')],
  ['exponent', replaceIn('"v":1}', '"v":1e0}')],
  ['negative-zero', replaceIn('"v":1}', '"v":-0}')],
  ['array', replaceIn('"kdf":null', '"kdf":[]')],
  ['wrong-type-read-once', replaceIn('"read_once":false', '"read_once":"false"')],
  ['read-once-without-consume-pk', replaceIn('"read_once":false', '"read_once":true')],
  ['consume-pk-without-read-once', replaceIn('"consume_pk":null', `"consume_pk":"${b64u(noPass.consume.pk)}"`)],
  ['unsupported-version', replaceIn('"v":1}', '"v":2}')],
  ['unsupported-alg', replaceIn('"A256GCM"', '"A128GCM"')],
  ['unknown-expiration', replaceIn('"7d"', '"2d"')],
  ['short-access-pk', replaceIn(aadPlain.access_pk, b64u(noPass.access.pk.subarray(0, 30)))],
  ['non-canonical-access-pk', replaceIn(aadPlain.access_pk, nonCanonical(aadPlain.access_pk))],
  ['escaped-string', replaceIn('"7d"', '"7\\u0064"')],
  ['non-ascii-string', replaceIn('"7d"', '"7\u00e9"')],
  ['integer-above-2-pow-53', replaceIn('"v":1}', '"v":9007199254740992}')],
  ['non-canonical-consume-pk', canonicalAad({ ...aadReadOncePass, consume_pk: nonCanonical(aadReadOncePass.consume_pk) })],
  ['non-canonical-salt', canonicalAad({ ...aadReadOncePass, kdf: { ...aadReadOncePass.kdf, salt: nonCanonical(aadReadOncePass.kdf.salt) } })],
  ['wrong-kdf-alg', canonicalAad({ ...aadReadOncePass, kdf: { ...aadReadOncePass.kdf, alg: 'argon2i13' } })],
  ['wrong-key-order-in-kdf', canonicalAad(aadReadOncePass).replace('"m":19456,"p":1', '"p":1,"m":19456')],
  ...[['m-too-low', 'm', 19455], ['m-too-high', 'm', 262145], ['t-too-low', 't', 1], ['t-too-high', 't', 11], ['p-not-one', 'p', 2]].map(([name, key, value]) => [
    name,
    canonicalAad({ ...aadReadOncePass, kdf: { ...aadReadOncePass.kdf, [key]: value } }),
  ]),
  ['short-salt', canonicalAad({ ...aadReadOncePass, kdf: { ...aadReadOncePass.kdf, salt: b64u(SALT.subarray(0, 15)) } })],
].map(([name, text]) => ({ name, input: { aad: text, aad_b64u: b64u(Buffer.from(text, 'utf8')) }, expected: { accept: false } }));

const aadPlainBytes = Buffer.from(plainCanonical, 'ascii');
const aadPassBytes = Buffer.from(canonicalAad(aadReadOncePass), 'ascii');
const envelopeBytes = Buffer.from(ENVELOPE, 'utf8');
const encPlain = encrypt(noPass.kEnc, NONCE, aadPlainBytes, envelopeBytes);
const encPass = encrypt(withPass.kEnc, NONCE, aadPassBytes, envelopeBytes);
const envelopeEncrypt = [
  { name: 'plain-no-passphrase', kEnc: noPass.kEnc, aad: aadPlainBytes, enc: encPlain },
  { name: 'read-once-with-passphrase', kEnc: withPass.kEnc, aad: aadPassBytes, enc: encPass },
].map(({ name, kEnc, aad, enc }) => ({
  name,
  input: { envelope_utf8: ENVELOPE, envelope_hex: hex(envelopeBytes), k_enc: hex(kEnc), nonce: hex(NONCE), nonce_b64u: b64u(NONCE), aad_hex: hex(aad) },
  expected: { ciphertext: hex(enc.ciphertext), ciphertext_b64u: b64u(enc.ciphertext), tag: hex(enc.tag), ciphertext_length: enc.ciphertext.length },
}));

const envelopeReject = [
  ['flipped-ciphertext-byte', noPass.kEnc, NONCE, aadPlainBytes, flip(encPlain.ciphertext, 0)],
  ['flipped-tag-byte', noPass.kEnc, NONCE, aadPlainBytes, flip(encPlain.ciphertext, encPlain.ciphertext.length - 1)],
  ['flipped-nonce-byte', noPass.kEnc, flip(NONCE, 0), aadPlainBytes, encPlain.ciphertext],
  ['flipped-aad-byte', noPass.kEnc, NONCE, Buffer.from(plainCanonical.replace('"7d"', '"1d"'), 'ascii'), encPlain.ciphertext],
  ['other-url-key', deriveKeys(K_URL_2).kEnc, NONCE, aadPlainBytes, encPlain.ciphertext],
].map(([name, kEnc, nonce, aad, ct]) => {
  if (decrypts(kEnc, nonce, aad, ct)) throw new Error(`Tampered vector ${name} still decrypts`);
  return { name, input: { k_enc: hex(kEnc), nonce: hex(nonce), aad_hex: hex(aad), ciphertext: hex(ct) }, expected: { decrypts: false } };
});

const appSecret = Buffer.from(APP_SECRET_B64, 'base64');
const kChallenge = hkdf(appSecret, INFO_CHALLENGE);
const USAGES = { open: 0x01, status: 0x02, consume: 0x03 };
const challenges = Object.fromEntries(Object.entries(USAGES).map(([u, code]) => [u, challenge(kChallenge, code, ids.id, ISSUED_AT, CHALLENGE_NONCE)]));
const challengeGroup = Object.entries(challenges).map(([u, c]) => ({
  name: `usage-${u}`,
  input: { app_secret_base64: APP_SECRET_B64, app_secret: hex(appSecret), usage: USAGES[u], id: hex(ids.id), issued_at: ISSUED_AT, nonce: hex(CHALLENGE_NONCE) },
  expected: { k_challenge: hex(kChallenge), challenge: hex(c.bytes), challenge_b64u: b64u(c.bytes), challenge_length: c.bytes.length, mac: hex(c.mac) },
}));

function proofVector(name, seedHex, kp, ch) {
  const message = Buffer.concat([PROOF_PREFIX, ch]);
  const signature = sign(kp.sk, message);
  if (!verify(kp.pk, message, signature)) throw new Error(`Signature ${name} does not verify`);
  return {
    name,
    input: { challenge: hex(ch), seed: seedHex },
    expected: { message: hex(message), message_length: message.length, public_key: hex(kp.pk), signature: hex(signature), signature_b64u: b64u(signature), verifies: true },
    negative: [
      { name: 'flipped-challenge-byte', message: hex(Buffer.concat([PROOF_PREFIX, flip(ch, 40)])), verifies: false },
      { name: 'flipped-signature-byte', signature: hex(flip(signature, 0)), verifies: false },
    ].map((n) => {
      const ok = verify(kp.pk, n.message ? h(n.message) : message, n.signature ? h(n.signature) : signature);
      if (ok !== n.verifies) throw new Error(`Negative vector ${name}/${n.name} is inconsistent`);
      return n;
    }),
  };
}

const proofGroup = [
  proofVector('open-with-access-key', hex(noPass.accessSeed), noPass.access, challenges.open.bytes),
  proofVector('status-with-access-key', hex(noPass.accessSeed), noPass.access, challenges.status.bytes),
];
const consumeProofGroup = [proofVector('consume-with-consume-key', hex(noPass.consumeSeed), noPass.consume, challenges.consume.bytes)];

// Server-side challenge verification (usage, identifier, MAC, freshness), docs §10.3.
// Freshness boundary (OQ-7) is not asserted: only clearly valid or clearly expired cases.
const otherId = Buffer.concat([ids.a, ids.d, h('8899aabbccddeeff')]);
const challengeVerify = [
  ['valid-open', challenges.open.bytes, 0x01, ids.id, ISSUED_AT + 30, true],
  ['usage-mismatch-status-for-open', challenges.status.bytes, 0x01, ids.id, ISSUED_AT + 30, false],
  ['usage-mismatch-consume-for-status', challenges.consume.bytes, 0x02, ids.id, ISSUED_AT + 30, false],
  ['identifier-mismatch', challenges.open.bytes, 0x01, otherId, ISSUED_AT + 30, false],
  ['bad-mac', flip(challenges.open.bytes, 81), 0x01, ids.id, ISSUED_AT + 30, false],
  ['tampered-issued-at', flip(challenges.open.bytes, 33), 0x01, ids.id, ISSUED_AT + 30, false],
  ['expired', challenges.open.bytes, 0x01, ids.id, ISSUED_AT + 120, false],
].map(([name, ch, usage, pathId, now, accept]) => ({
  name,
  input: { k_challenge: hex(kChallenge), challenge: hex(ch), endpoint_usage: usage, path_id: hex(pathId), now },
  expected: { accept },
}));

// Public keys the server must reject at creation (EXG-CRYPTO-028). Ed25519 verification
// strictness for signatures (OQ-5) is still open and not covered here.
const publicKeyReject = [
  ['small-order-identity-point', `01${'00'.repeat(31)}`],
  ['non-canonical-y-equals-p', `ed${'ff'.repeat(30)}7f`],
  ['short-key', hex(noPass.access.pk.subarray(0, 31))],
].map(([name, pk]) => ({ name, input: { public_key: pk }, expected: { accept: false } }));

const deleteCheck = [
  ['valid-token', b64u(DELETION_TOKEN), true],
  ['other-token', b64u(pattern(0x41, 32)), false],
  ['truncated-token', b64u(DELETION_TOKEN.subarray(0, 31)), false],
  ['padded-token', `${b64u(DELETION_TOKEN)}=`, false],
].map(([name, token, accept]) => {
  const decoded = Buffer.from(token.replace(/=+$/, ''), 'base64url');
  return {
    name,
    input: { token_b64u: token, id: hex(ids.id) },
    expected: { accept, decoded_length: decoded.length, token_hash: hex(sha256(decoded)), expected_d: hex(ids.d) },
  };
});

const b64uReject = [
  ['padding', 'k_url', `${b64u(K_URL)}=`],
  ['non-zero-trailing-bits', 'k_url', nonCanonical(b64u(K_URL))],
  ['standard-alphabet-plus', 'k_url', `+${b64u(K_URL).slice(1)}`],
  ['standard-alphabet-slash', 'k_url', `/${b64u(K_URL).slice(1)}`],
  ['whitespace', 'k_url', ` ${b64u(K_URL)}`],
  ['wrong-length-id', 'id', b64u(ids.id.subarray(0, 23))],
  ['wrong-length-nonce', 'nonce', b64u(Buffer.alloc(11))],
  ['wrong-length-salt', 'salt', b64u(Buffer.alloc(15))],
  ['wrong-length-signature', 'signature', b64u(Buffer.alloc(63))],
].map(([name, field, value]) => ({ name, input: { field, value }, expected: { accept: false } }));

const vectors = {
  protocol: PROTO,
  description: 'Shared deterministic test vectors for sp-proto/v1 (dummy values only, never real secrets). Binary values are lowercase hex unless suffixed _b64u.',
  generator: 'tools/vectors/generate-sp-proto-v1.mjs',
  spec: 'docs/protocol/sp-proto-v1.md',
  status: 'provisional',
  assumptions: ASSUMPTIONS,
  requirements: REQUIREMENTS,
  groups: {
    hkdf_no_passphrase: hkdfNoPassphrase,
    argon2id: argon2Group,
    hkdf_with_passphrase: hkdfWithPassphrase,
    nfc: nfcGroup,
    pkcs8: pkcs8Group,
    identifier: identifierGroup,
    aad: aadGroup,
    aad_reject: aadReject,
    envelope_encrypt: envelopeEncrypt,
    envelope_reject: envelopeReject,
    challenge: challengeGroup,
    proof: proofGroup,
    consume_proof: consumeProofGroup,
    challenge_verify: challengeVerify,
    public_key_reject: publicKeyReject,
    delete_check: deleteCheck,
    base64url_reject: b64uReject,
  },
};

const output = `${JSON.stringify(vectors, null, 2)}\n`;
const checkIndex = process.argv.indexOf('--check');
if (checkIndex !== -1) {
  const current = readFileSync(process.argv[checkIndex + 1], 'utf8');
  if (current !== output) {
    process.stderr.write('Vector file is out of date; regenerate it.\n');
    process.exit(1);
  }
  process.stdout.write('Vector file is up to date.\n');
} else {
  process.stdout.write(output);
}
