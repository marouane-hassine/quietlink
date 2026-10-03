// SPDX-License-Identifier: AGPL-3.0-or-later
// Shared sp-proto/v1 vectors (tests/vectors/sp-proto-v1.json), same file as PHPUnit and the CLI.
// Requirements: EXG-CRYPTO-014, EXG-CRYPTO-022, EXG-CRYPTO-035, EXG-CRYPTO-036, EXG-CRYPTO-031,
// EXG-CRYPTO-045, EXG-CRYPTO-047, EXG-CRYPTO-053, EXG-CRYPTO-060, EXG-CRYPTO-070, EXG-CRYPTO-073.

import { describe, expect, it } from 'vitest';
import vectors from '../../tests/vectors/sp-proto-v1.json';
import { canonicalize, parse, AadError } from '../src/crypto/aad';
import { derivePassphraseKey } from '../src/crypto/argon2';
import { decode, encode, EncodingError } from '../src/crypto/base64url';
import { fromHex, hex } from '../src/crypto/bytes';
import { nobleEd25519, pkcs8, webCryptoEd25519 } from '../src/crypto/ed25519';
import { accessSeed, consumeSeed, contentKey, matchesAccessKey, matchesDeletionToken, proofMessage } from '../src/crypto/protocol';
import { aesGcmDecrypt, aesGcmEncrypt, DecryptionError, sha256 } from '../src/crypto/primitives';

type Vector = { name: string; input: Record<string, any>; expected: Record<string, any>; negative?: Record<string, any>[] };
const group = (name: string): Vector[] => (vectors.groups as Record<string, Vector[]>)[name] ?? [];
const each = (name: string) => group(name).map((v) => [v.name, v] as const);

describe('hkdf', () => {
  it.each(each('hkdf_no_passphrase'))('%s', async (_, v) => {
    const kUrl = fromHex(v.input.k_url);
    expect(hex(await accessSeed(kUrl))).toBe(v.expected.k_access_seed);
    expect(hex(await contentKey(kUrl, null))).toBe(v.expected.k_enc);
    expect(hex(await consumeSeed(kUrl, null))).toBe(v.expected.k_consume_seed);
  });
  it.each(each('hkdf_with_passphrase'))('%s', async (_, v) => {
    const kUrl = fromHex(v.input.k_url);
    const kPass = fromHex(v.input.k_pass);
    expect(hex(await contentKey(kUrl, kPass))).toBe(v.expected.k_enc);
    expect(hex(await consumeSeed(kUrl, kPass))).toBe(v.expected.k_consume_seed);
  });
});

describe('ed25519', () => {
  for (const impl of [webCryptoEd25519, nobleEd25519]) {
    it.each(each('pkcs8'))(`${impl.name} public key and PKCS#8 %s`, async (_, v) => {
      const seed = fromHex(v.input.seed);
      expect(hex(pkcs8(seed))).toBe(v.expected.pkcs8_der);
      expect(hex(await impl.publicKey(seed))).toBe(v.expected.public_key);
    });
    it.each([...each('proof'), ...each('consume_proof')])(`${impl.name} proof %s`, async (_, v) => {
      const message = proofMessage(fromHex(v.input.challenge));
      expect(hex(message)).toBe(v.expected.message);
      expect(hex(await impl.sign(fromHex(v.input.seed), message))).toBe(v.expected.signature);
    });
  }
});

describe('argon2id', () => {
  it.each(each('argon2id'))('%s', async (_, v) => {
    const key = await derivePassphraseKey(v.input.passphrase, fromHex(v.input.salt), v.input.m, v.input.t);
    expect(hex(key)).toBe(v.expected.k_pass);
  });
  it.each(each('nfc'))('normalises %s', async (_, v) => {
    const decomposed = new TextDecoder().decode(fromHex(v.input.passphrase_utf8));
    expect(hex(await derivePassphraseKey(decomposed, fromHex(v.input.salt), v.input.m, v.input.t))).toBe(v.expected.k_pass);
  });
});

describe('identifier and deletion token', () => {
  it.each(each('identifier'))('%s', async (_, v) => {
    const id = fromHex(v.expected.id);
    expect(encode(id)).toBe(v.expected.id_b64u);
    expect(hex(await sha256(fromHex(v.input.deletion_token)))).toBe(v.expected.deletion_hash);
    expect(await matchesAccessKey(id, fromHex(v.input.access_pk))).toBe(true);
    expect(await matchesDeletionToken(id, fromHex(v.input.deletion_token))).toBe(true);
  });
  it.each(each('delete_check'))('%s', async (_, v) => {
    let accepted = false;
    try {
      accepted = await matchesDeletionToken(fromHex(v.input.id), decode(v.input.token_b64u, 32));
    } catch (e) {
      expect(e).toBeInstanceOf(EncodingError);
    }
    expect(accepted).toBe(v.expected.accept);
  });
});

describe('aad', () => {
  it.each(each('aad'))('canonicalises %s', (_, v) => {
    expect(canonicalize(v.input.object)).toBe(v.expected.canonical);
    expect(parse(new TextEncoder().encode(v.expected.canonical)).object).toEqual(v.input.object);
  });
  it.each(each('aad_reject'))('rejects %s', (_, v) => {
    expect(() => parse(new TextEncoder().encode(v.input.aad))).toThrow(AadError);
  });
});

describe('aes-256-gcm', () => {
  it.each(each('envelope_encrypt'))('%s', async (_, v) => {
    const args = [fromHex(v.input.k_enc), fromHex(v.input.nonce), fromHex(v.input.aad_hex)] as const;
    expect(hex(await aesGcmEncrypt(...args, fromHex(v.input.envelope_hex)))).toBe(v.expected.ciphertext);
    expect(hex(await aesGcmDecrypt(...args, fromHex(v.expected.ciphertext)))).toBe(v.input.envelope_hex);
  });
  it.each(each('envelope_reject'))('rejects %s', async (_, v) => {
    await expect(aesGcmDecrypt(fromHex(v.input.k_enc), fromHex(v.input.nonce), fromHex(v.input.aad_hex), fromHex(v.input.ciphertext))).rejects.toThrow(DecryptionError);
  });
});

describe('base64url', () => {
  const lengths: Record<string, number> = { k_url: 32, id: 24, nonce: 12, salt: 16, signature: 64 };
  it.each(each('base64url_reject'))('rejects %s', (_, v) => {
    expect(() => decode(v.input.value, lengths[v.input.field])).toThrow(EncodingError);
  });
  it('round-trips every length', () => {
    for (let n = 0; n < 40; n++) {
      const bytes = crypto.getRandomValues(new Uint8Array(n));
      expect(decode(encode(bytes), n)).toEqual(bytes);
    }
  });
});
