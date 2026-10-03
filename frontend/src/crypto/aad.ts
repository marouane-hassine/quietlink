// SPDX-License-Identifier: AGPL-3.0-or-later

/** Canonical AAD of sp-proto/v1 §8 (RFC 8785 subset), built and validated client side. */

import { decode, EncodingError } from './base64url';
import { ascii } from './bytes';
import { ARGON2_MAX_M, ARGON2_MAX_T, ARGON2_MIN_M, ARGON2_MIN_T, EXPIRATIONS, type Expiration } from './constants';

export interface KdfParams {
  alg: 'argon2id13';
  m: number;
  t: number;
  p: 1;
  salt: string;
}

export interface AadObject {
  v: 1;
  alg: 'A256GCM';
  read_once: boolean;
  expiration: Expiration;
  kdf: KdfParams | null;
  access_pk: string;
  consume_pk: string | null;
}

export interface ParsedAad {
  bytes: Uint8Array;
  object: AadObject;
  accessPk: Uint8Array;
  consumePk: Uint8Array | null;
  salt: Uint8Array | null;
}

export class AadError extends Error {}

type Json = null | boolean | number | string | { [key: string]: Json };

export function canonicalize(value: Json): string {
  if (value === null || typeof value === 'boolean') return JSON.stringify(value);
  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value) || value < 0 || Object.is(value, -0)) throw new AadError('Invalid number');
    return String(value);
  }
  if (typeof value === 'string') {
    if (!/^[\x20-\x7e]*$/.test(value) || /["\\]/.test(value)) throw new AadError('Invalid string');
    return `"${value}"`;
  }
  if (Array.isArray(value)) throw new AadError('Arrays are forbidden');
  return `{${Object.keys(value)
    .sort()
    .map((k) => `${canonicalize(k)}:${canonicalize(value[k] as Json)}`)
    .join(',')}}`;
}

export function build(object: AadObject): Uint8Array {
  return ascii(canonicalize(object as unknown as Json));
}

const KEYS = ['access_pk', 'alg', 'consume_pk', 'expiration', 'kdf', 'read_once', 'v'];
const KDF_KEYS = ['alg', 'm', 'p', 'salt', 't'];

function exactKeys(value: unknown, keys: string[]): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value) && Object.keys(value).sort().join() === keys.join();
}

/** Validates received AAD bytes exactly like the server (sp-proto/v1 §8.3). */
export function parse(bytes: Uint8Array): ParsedAad {
  if (bytes.length === 0 || bytes.length > 4096 || bytes.some((b) => b < 0x20 || b > 0x7e)) throw new AadError('Invalid AAD bytes');
  const text = new TextDecoder().decode(bytes);
  let value: unknown;
  try {
    value = JSON.parse(text);
  } catch {
    throw new AadError('Invalid JSON');
  }
  if (!exactKeys(value, KEYS)) throw new AadError('Unexpected members');
  if (value.v !== 1 || value.alg !== 'A256GCM' || typeof value.read_once !== 'boolean') throw new AadError('Unsupported AAD');
  if (typeof value.expiration !== 'string' || !(EXPIRATIONS as readonly string[]).includes(value.expiration)) throw new AadError('Unknown expiration');
  try {
    if (typeof value.access_pk !== 'string') throw new AadError('Invalid access key');
    const accessPk = decode(value.access_pk, 32);
    const consumePk = value.consume_pk === null ? null : typeof value.consume_pk === 'string' ? decode(value.consume_pk, 32) : null;
    if (value.consume_pk !== null && consumePk === null) throw new AadError('Invalid consume key');
    if (value.read_once !== (consumePk !== null)) throw new AadError('read_once and consume_pk disagree');
    let salt: Uint8Array | null = null;
    if (value.kdf !== null) {
      const kdf = value.kdf;
      if (!exactKeys(kdf, KDF_KEYS) || kdf.alg !== 'argon2id13' || kdf.p !== 1) throw new AadError('Invalid KDF');
      const { m, t } = kdf;
      if (typeof m !== 'number' || typeof t !== 'number' || !Number.isSafeInteger(m) || !Number.isSafeInteger(t)
        || m < ARGON2_MIN_M || m > ARGON2_MAX_M || t < ARGON2_MIN_T || t > ARGON2_MAX_T) throw new AadError('KDF out of bounds');
      if (typeof kdf.salt !== 'string') throw new AadError('Invalid salt');
      salt = decode(kdf.salt, 16);
    }
    if (canonicalize(value as Json) !== text) throw new AadError('Not canonical');
    return { bytes, object: value as unknown as AadObject, accessPk, consumePk, salt };
  } catch (error) {
    if (error instanceof EncodingError) throw new AadError('Invalid binary member');
    throw error;
  }
}
