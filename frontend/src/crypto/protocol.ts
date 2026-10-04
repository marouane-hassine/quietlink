// SPDX-License-Identifier: AGPL-3.0-or-later

/** Client side of sp-proto/v1: key derivations, creation body, proofs, decryption. */

import { build, parse, type AadObject, type ParsedAad } from './aad';
import { decode, encode } from './base64url';
import { ascii, concat, equal, randomBytes, utf8, wipe } from './bytes';
import { ID_ACCESS_PREFIX, ID_DELETE_PREFIX, INFO_ACCESS, INFO_CONSUME, INFO_CONTENT, KEY_BYTES, NONCE_BYTES, PROOF_PREFIX, SALT_BYTES, type Expiration } from './constants';
import { ed25519 } from './ed25519';
import { aesGcmDecrypt, aesGcmEncrypt, fingerprint, hkdf, sha256 } from './primitives';

export type PassphraseDeriver = (passphrase: string, salt: Uint8Array, m: number, t: number) => Promise<Uint8Array>;

export interface PreparedPaste {
  body: { aad: string; nonce: string; ciphertext: string; deletion_hash: string };
  json: string;
  idempotencyKey: string;
  urlKey: Uint8Array;
  deletionToken: Uint8Array;
  accessPk: Uint8Array;
  deletionHash: Uint8Array;
}

export interface PrepareOptions {
  envelope: string;
  expiration: Expiration;
  readOnce: boolean;
  passphrase: string | null;
  kdf: { m: number; t: number };
  derive: PassphraseDeriver;
}

export const accessSeed = (urlKey: Uint8Array): Promise<Uint8Array> => hkdf(urlKey, INFO_ACCESS);

function contentIkm(urlKey: Uint8Array, kPass: Uint8Array | null): Uint8Array {
  return kPass === null ? urlKey : concat(urlKey, kPass);
}

export const contentKey = (urlKey: Uint8Array, kPass: Uint8Array | null): Promise<Uint8Array> => hkdf(contentIkm(urlKey, kPass), INFO_CONTENT);
export const consumeSeed = (urlKey: Uint8Array, kPass: Uint8Array | null): Promise<Uint8Array> => hkdf(contentIkm(urlKey, kPass), INFO_CONSUME);

export async function accessPublicKey(urlKey: Uint8Array): Promise<Uint8Array> {
  const seed = await accessSeed(urlKey);
  try {
    return await (await ed25519()).publicKey(seed);
  } finally {
    wipe(seed);
  }
}

export async function prepare(options: PrepareOptions): Promise<PreparedPaste> {
  const urlKey = randomBytes(KEY_BYTES);
  let kPass: Uint8Array | null = null;
  let kdf: AadObject['kdf'] = null;
  if (options.passphrase !== null) {
    const salt = randomBytes(SALT_BYTES);
    kPass = await options.derive(options.passphrase, salt, options.kdf.m, options.kdf.t);
    kdf = { alg: 'argon2id13', m: options.kdf.m, t: options.kdf.t, p: 1, salt: encode(salt) };
  }
  const signer = await ed25519();
  const accessPk = await accessPublicKey(urlKey);
  let consumePk: Uint8Array | null = null;
  if (options.readOnce) {
    const seed = await consumeSeed(urlKey, kPass);
    consumePk = await signer.publicKey(seed);
    wipe(seed);
  }
  const aad = build({
    v: 1,
    alg: 'A256GCM',
    read_once: options.readOnce,
    expiration: options.expiration,
    kdf,
    access_pk: encode(accessPk),
    consume_pk: consumePk === null ? null : encode(consumePk),
  });
  const nonce = randomBytes(NONCE_BYTES);
  const key = await contentKey(urlKey, kPass);
  const plaintext = utf8.encode(options.envelope);
  const ciphertext = await aesGcmEncrypt(key, nonce, aad, plaintext);
  wipe(key, kPass, plaintext);
  const deletionToken = randomBytes(32);
  const deletionHash = await sha256(deletionToken);
  const body = { aad: encode(aad), nonce: encode(nonce), ciphertext: encode(ciphertext), deletion_hash: encode(deletionHash) };
  return { body, json: JSON.stringify(body), idempotencyKey: encode(randomBytes(16)), urlKey, deletionToken, accessPk, deletionHash };
}

/** message = "sp-proto/v1/proof" ‖ 0x00 ‖ challenge (§10.2). */
export function proofMessage(challenge: Uint8Array): Uint8Array {
  return concat(ascii(PROOF_PREFIX), new Uint8Array([0]), challenge);
}

export async function prove(seed: Uint8Array, challengeB64u: string): Promise<string> {
  return encode(await (await ed25519()).sign(seed, proofMessage(decode(challengeB64u, 82))));
}

export async function matchesAccessKey(id: Uint8Array, accessPk: Uint8Array): Promise<boolean> {
  return id.length === 24 && equal(id.slice(0, 8), await fingerprint(ID_ACCESS_PREFIX, accessPk));
}

export async function matchesDeletionToken(id: Uint8Array, token: Uint8Array): Promise<boolean> {
  return id.length === 24 && token.length === 32 && equal(id.slice(8, 16), await fingerprint(ID_DELETE_PREFIX, await sha256(token)));
}

export class WrongPassphraseError extends Error {}

/** Local passphrase check against consume_pk before any reservation (§6.3.1 step 0). */
export async function checkConsumeKey(aad: ParsedAad, urlKey: Uint8Array, kPass: Uint8Array | null): Promise<Uint8Array | null> {
  if (aad.consumePk === null) return null;
  const seed = await consumeSeed(urlKey, kPass);
  if (!equal(await (await ed25519()).publicKey(seed), aad.consumePk)) {
    wipe(seed);
    throw new WrongPassphraseError();
  }
  return seed;
}

export async function decrypt(urlKey: Uint8Array, kPass: Uint8Array | null, aad: ParsedAad, nonce: Uint8Array, ciphertext: Uint8Array): Promise<string> {
  const key = await contentKey(urlKey, kPass);
  try {
    const plaintext = await aesGcmDecrypt(key, nonce, aad.bytes, ciphertext);
    return new TextDecoder('utf-8', { fatal: true }).decode(plaintext);
  } finally {
    wipe(key);
  }
}

export { parse as parseAad };
