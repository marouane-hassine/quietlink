// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Ed25519 from a 32-byte seed: Web Crypto when available and conformant, otherwise the
 * bundled @noble/ed25519 (pure JavaScript, never loaded remotely). Chosen once at load.
 */

import * as noble from '@noble/ed25519';
import { decode } from './base64url';
import { buffer, concat, equal, fromHex } from './bytes';

const PKCS8_PREFIX = fromHex('302e020100300506032b657004220420');

export interface Ed25519 {
  readonly name: 'webcrypto' | 'noble';
  publicKey(seed: Uint8Array): Promise<Uint8Array>;
  sign(seed: Uint8Array, message: Uint8Array): Promise<Uint8Array>;
}

export function pkcs8(seed: Uint8Array): Uint8Array {
  return concat(PKCS8_PREFIX, seed);
}

export const webCryptoEd25519: Ed25519 = {
  name: 'webcrypto',
  async publicKey(seed) {
    // Extractable only to export the JWK "x" member (public key); the reference is dropped after use.
    const key = await crypto.subtle.importKey('pkcs8', buffer(pkcs8(seed)), { name: 'Ed25519' }, true, ['sign']);
    const jwk = await crypto.subtle.exportKey('jwk', key);
    if (typeof jwk.x !== 'string') throw new Error('Ed25519 export failed');
    return decode(jwk.x, 32);
  },
  async sign(seed, message) {
    const key = await crypto.subtle.importKey('pkcs8', buffer(pkcs8(seed)), { name: 'Ed25519' }, false, ['sign']);
    return new Uint8Array(await crypto.subtle.sign({ name: 'Ed25519' }, key, buffer(message)));
  },
};

export const nobleEd25519: Ed25519 = {
  name: 'noble',
  publicKey: (seed) => noble.getPublicKeyAsync(seed),
  sign: (seed, message) => noble.signAsync(message, seed),
};

// RFC 8032 test vector 1 (public, not a secret).
const RFC_SEED = fromHex('9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60');
const RFC_PUBLIC = fromHex('d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a');

let selected: Promise<Ed25519> | null = null;

/** Picks Web Crypto only if it reproduces the RFC 8032 vector, else the local fallback. */
export function ed25519(): Promise<Ed25519> {
  selected ??= (async () => {
    try {
      if (equal(await webCryptoEd25519.publicKey(RFC_SEED), RFC_PUBLIC)) return webCryptoEd25519;
    } catch {
      // Web Crypto without Ed25519: use the bundled implementation.
    }
    return nobleEd25519;
  })();
  return selected;
}
