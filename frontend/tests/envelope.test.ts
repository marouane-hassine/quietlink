// SPDX-License-Identifier: AGPL-3.0-or-later
// Requirements (strict envelope, §8.2.1): EXG-CRYPTO-038, EXG-CRYPTO-039, EXG-SEC-024.

import { describe, expect, it } from 'vitest';
import { EnvelopeError, parseEnvelope, serialize } from '../src/crypto/envelope';

describe('envelope parsing', () => {
  it('accepts a serialized envelope, including key-like text', () => {
    const json = serialize({ format: 'plain', language: null, template: null, text: 'a "text": "b", "v": 2, {x}' });
    expect(parseEnvelope(json).text).toBe('a "text": "b", "v": 2, {x}');
  });

  it('rejects duplicate members, which JSON.parse would silently collapse', () => {
    const duplicate = '{"format":"plain","language":null,"template":null,"text":"shown","text":"other","v":1}';
    expect(() => parseEnvelope(duplicate)).toThrow(EnvelopeError);
  });

  it('rejects what the CLI rejects: a non-integer "v" token and lone surrogates (EXG-CRYPTO-038)', () => {
    const base = (v: string, text = 'dummy') => `{"format":"plain","language":null,"template":null,"text":${JSON.stringify(text)},"v":${v}}`;
    expect(parseEnvelope(base('1')).v).toBe(1);
    expect(parseEnvelope(base(' 1 ')).v).toBe(1);
    for (const v of ['1.0', '1e0', '1E0', '10e-1', '1.00']) expect(() => parseEnvelope(base(v)), v).toThrow(EnvelopeError);
    expect(() => parseEnvelope(base('1', 'a\ud800b'))).toThrow(EnvelopeError);
    expect(() => parseEnvelope(base('1').replace('"dummy"', '"a\\udc00b"'))).toThrow(EnvelopeError);
    expect(parseEnvelope(base('1').replace('"dummy"', '"\\ud83d\\ude00"')).text).toBe('\u{1F600}');
  });
});

describe('envelope on browsers without ES2024 string methods (Safari < 16.4, Firefox < 119, Chrome < 111)', () => {
  it('parses and serialises without String.prototype.isWellFormed / toWellFormed (EXG-CRYPTO-038)', () => {
    const proto = String.prototype as unknown as Record<string, unknown>;
    const saved = { isWellFormed: proto.isWellFormed, toWellFormed: proto.toWellFormed };
    delete proto.isWellFormed;
    delete proto.toWellFormed;
    try {
      const valid = serialize({ format: 'plain', language: null, template: null, text: 'dummy \u{1F600} text' });
      expect(parseEnvelope(valid).text).toBe('dummy \u{1F600} text');
      const lone = '{"format":"plain","language":null,"template":null,"text":"a\\ud800b","v":1}';
      expect(() => parseEnvelope(lone)).toThrow(EnvelopeError);
      expect(() => parseEnvelope(lone.replace('\\ud800', '\\udc00'))).toThrow(EnvelopeError);
      // Lone surrogates typed in the editor are replaced by U+FFFD, as toWellFormed() does.
      expect(JSON.parse(serialize({ format: 'plain', language: null, template: null, text: 'a\ud800b\udc00c😀' })).text).toBe('a�b�c\u{1F600}');
    } finally {
      Object.assign(proto, saved);
    }
  });

  it('refuses a format that is not a string, even one that converts to a valid code', () => {
    expect(() => parseEnvelope('{"format":["plain"],"language":null,"template":null,"text":"x","v":1}')).toThrow(EnvelopeError);
  });
});
