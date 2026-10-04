// SPDX-License-Identifier: AGPL-3.0-or-later

/** Web Crypto wrappers: HKDF-SHA-256, SHA-256, AES-256-GCM. */

import { ascii, buffer, concat } from './bytes';
import { HKDF_SALT } from './constants';

const subtle = (): SubtleCrypto => {
  if (!globalThis.crypto?.subtle) throw new Error('Web Crypto is unavailable');
  return globalThis.crypto.subtle;
};

export async function hkdf(ikm: Uint8Array, info: string): Promise<Uint8Array> {
  const key = await subtle().importKey('raw', buffer(ikm), 'HKDF', false, ['deriveBits']);
  const bits = await subtle().deriveBits({ name: 'HKDF', hash: 'SHA-256', salt: buffer(ascii(HKDF_SALT)), info: buffer(ascii(info)) }, key, 256);
  return new Uint8Array(bits);
}

export async function sha256(data: Uint8Array): Promise<Uint8Array> {
  return new Uint8Array(await subtle().digest('SHA-256', buffer(data)));
}

/** Truncated hash fingerprint of §5.4: first 8 bytes of SHA-256(prefix ‖ 0x00 ‖ value). */
export async function fingerprint(prefix: string, value: Uint8Array): Promise<Uint8Array> {
  return (await sha256(concat(ascii(prefix), new Uint8Array([0]), value))).slice(0, 8);
}

export async function aesGcmEncrypt(key: Uint8Array, nonce: Uint8Array, aad: Uint8Array, plaintext: Uint8Array): Promise<Uint8Array> {
  const k = await subtle().importKey('raw', buffer(key), 'AES-GCM', false, ['encrypt']);
  return new Uint8Array(await subtle().encrypt({ name: 'AES-GCM', iv: buffer(nonce), additionalData: buffer(aad), tagLength: 128 }, k, buffer(plaintext)));
}

export class DecryptionError extends Error {}

export async function aesGcmDecrypt(key: Uint8Array, nonce: Uint8Array, aad: Uint8Array, ciphertext: Uint8Array): Promise<Uint8Array> {
  const k = await subtle().importKey('raw', buffer(key), 'AES-GCM', false, ['decrypt']);
  try {
    return new Uint8Array(await subtle().decrypt({ name: 'AES-GCM', iv: buffer(nonce), additionalData: buffer(aad), tagLength: 128 }, k, buffer(ciphertext)));
  } catch {
    throw new DecryptionError('Authentication failed');
  }
}
