// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Passphrase generation (≥ 6 words from a ≥ 2048-word list, CSPRNG, ≥ 66 bits) and a
 * lightweight local strength estimate. Nothing is persisted.
 */

export const WORDS = 6;

/**
 * Word list of the active language (spec §5.1, §19): French from Lexique, English (EFF) for the
 * other languages until they have their own list. Each list is a separate lazy chunk.
 */
export async function wordlist(localeCode: string): Promise<string[]> {
  if (localeCode === 'fr') return (await import('../wordlists/fr')).default;
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

const SEPARATOR = '-';

export function generate(words: string[], count = WORDS): string {
  // Words containing the separator (EFF "felt-tip") would blur the word boundaries.
  const usable = words.filter((word) => !word.includes(SEPARATOR));
  if (usable.length < 2048) throw new Error('Word list too small');
  return Array.from({ length: count }, () => usable[randomIndex(usable.length)]).join(SEPARATOR);
}

export function entropyBits(listSize: number, count = WORDS): number {
  return count * Math.log2(listSize);
}

/** Pool size of each character class; any Unicode letter is a letter (§5.1 local estimate). */
const CLASSES: [RegExp, number][] = [
  [/\p{Ll}/u, 26],
  [/\p{Lu}/u, 26],
  // Letters without case (Arabic, CJK…): a letter alphabet, not symbols.
  [/[^\P{L}\p{Ll}\p{Lu}]/u, 26],
  [/\p{Nd}/u, 10],
  [/[^\p{L}\p{Nd}]/u, 33],
];

/**
 * Rough estimate from length and the character classes present; never blocking. Code points
 * are counted; repeated and sequential characters ("aaaa", "1234", "abcd") add almost nothing,
 * and digits only (dates, phone numbers, PINs) are penalised.
 */
export function strength(passphrase: string): 'weak' | 'fair' | 'strong' {
  const chars = Array.from(passphrase);
  const pool = CLASSES.filter(([pattern]) => chars.some((c) => pattern.test(c))).reduce((sum, [, size]) => sum + size, 0) || 26;
  let length = 0;
  for (let i = 0; i < chars.length; i++) {
    const delta = i === 0 ? Infinity : (chars[i]?.codePointAt(0) ?? 0) - (chars[i - 1]?.codePointAt(0) ?? 0);
    if (Math.abs(delta) > 1) length++;
  }
  const unique = new Set(chars).size;
  let bits = Math.min(length, unique * 2) * Math.log2(pool);
  if (chars.length > 0 && chars.every((c) => /\p{Nd}/u.test(c))) bits *= 0.75;
  if (bits < 50) return 'weak';
  if (bits < 80) return 'fair';
  return 'strong';
}
