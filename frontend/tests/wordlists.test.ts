// SPDX-License-Identifier: AGPL-3.0-or-later
// Passphrase word lists per language (spec §5.1, §19): at least 2048 distinct words that can be
// typed and spelled out, a French list derived from Lexique, English for the other languages.
// Requirements: EXG-UX-038, EXG-UX-040, EXG-SEC-007.

import { describe, expect, it } from 'vitest';
import en from '../src/wordlists/en';
import fr from '../src/wordlists/fr';
import { generate, wordlist } from '../src/ui/passphrase';

describe('passphrase word lists', () => {
  it('picks the French list in French and English elsewhere', async () => {
    expect(await wordlist('fr')).toBe(fr);
    for (const code of ['en', 'es', 'it', 'ar']) expect(await wordlist(code)).toBe(en);
  });

  it('ships a French list of 4096 distinct plain words giving at least 66 bits in 6 words', () => {
    expect(fr).toHaveLength(4096);
    expect(new Set(fr).size).toBe(fr.length);
    for (const word of fr) expect(word).toMatch(/^[a-z]{4,8}$/);
    expect(6 * Math.log2(fr.length)).toBeGreaterThanOrEqual(66);
    expect([...fr].sort()).toEqual(fr);
  });

  it('generates French passphrases from the French list only', () => {
    const words = generate(fr).split('-');
    expect(words).toHaveLength(6);
    for (const word of words) expect(fr).toContain(word);
  });
});
