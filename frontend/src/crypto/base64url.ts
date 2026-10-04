// SPDX-License-Identifier: AGPL-3.0-or-later

/** Canonical base64url without padding (sp-proto/v1 §3.1). */

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
const LOOKUP = new Map([...ALPHABET].map((c, i) => [c, i]));

export class EncodingError extends Error {}

export function encode(bytes: Uint8Array): string {
  let out = '';
  for (let i = 0; i < bytes.length; i += 3) {
    const n = ((bytes[i] ?? 0) << 16) | ((bytes[i + 1] ?? 0) << 8) | (bytes[i + 2] ?? 0);
    const chars = Math.min(4, Math.ceil(((bytes.length - i) * 8) / 6));
    for (let j = 0; j < chars; j++) out += ALPHABET[(n >> (18 - 6 * j)) & 63];
  }
  return out;
}

export function decode(text: string, length?: number): Uint8Array {
  if (!/^[A-Za-z0-9_-]*$/.test(text) || text.length % 4 === 1) throw new EncodingError('Invalid base64url');
  const out = new Uint8Array(Math.floor((text.length * 6) / 8));
  let bits = 0;
  let value = 0;
  let index = 0;
  for (const char of text) {
    value = (value << 6) | (LOOKUP.get(char) ?? 0);
    bits += 6;
    if (bits >= 8) {
      bits -= 8;
      out[index++] = (value >> bits) & 0xff;
    }
  }
  if (bits > 0 && (value & ((1 << bits) - 1)) !== 0) throw new EncodingError('Non-canonical base64url');
  if (length !== undefined && out.length !== length) throw new EncodingError('Unexpected length');
  return out;
}
