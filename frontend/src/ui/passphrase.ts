// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Passphrase generation (≥ 6 words from a ≥ 2048-word list, CSPRNG, ≥ 66 bits) and a
 * lightweight local strength estimate. Nothing is persisted.
 */

export const WORDS = 6;

export async function wordlist(localeCode: string): Promise<string[]> {
  // The French list built from Lexique is pending (human action); English is used meanwhile.
  void localeCode;
  return (await import('../wordlists/en')).default;
}

/** Unbiased index in [0, n) by rejection sampling. */
export function randomIndex(n: number): number {
  const limit = Math.floor(0x100000000 / n) * n;
  const buf = new Uint32Array(1);
  for (;;) {
    crypto.getRandomValues(buf);
    const value = buf[0] ?? 0;
    if (value < limit) return value % n;
  }
}

export function generate(words: string[], count = WORDS): string {
  if (words.length < 2048) throw new Error('Word list too small');
  return Array.from({ length: count }, () => words[randomIndex(words.length)]).join('-');
}

export function entropyBits(listSize: number, count = WORDS): number {
  return count * Math.log2(listSize);
}

/** Rough estimate from length and character classes; never blocking. */
export function strength(passphrase: string): 'weak' | 'fair' | 'strong' {
  const classes = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^A-Za-z0-9]/].filter((r) => r.test(passphrase)).length;
  const pool = [26, 26, 10, 33].slice(0, classes).reduce((a, b) => a + b, 0) || 26;
  const unique = new Set(passphrase).size;
  const bits = Math.min(passphrase.length, unique * 2) * Math.log2(pool);
  if (bits < 50) return 'weak';
  if (bits < 80) return 'fair';
  return 'strong';
}
