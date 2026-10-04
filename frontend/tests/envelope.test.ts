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
});
