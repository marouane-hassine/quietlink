// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-UX-080, EXG-READ-033, EXG-UX-081, EXG-UX-082, EXG-UX-084, EXG-A11Y-007, EXG-I18N-002, EXG-I18N-006, EXG-I18N-007, EXG-CRYPTO-004, EXG-CRYPTO-003, EXG-I18N-008, EXG-I18N-012, EXG-TEST-026, EXG-TEST-063, EXG-TEST-064.

import { describe, expect, it } from 'vitest';
import { crossedThreshold, nextTickMs, remainingAt, synchronise } from '../src/ui/countdown';
import { formatDate, formatRelative } from '../src/ui/format';
import { catalogKeys, loadLocale, setLocale, t, tn } from '../src/i18n';
import { entropyBits, generate, strength } from '../src/ui/passphrase';
import words from '../src/wordlists/en';
import en from '../../translations/en.json';
import fr from '../../translations/fr.json';

describe('countdown', () => {
  it('corrects the clock with half the round trip', () => {
    const sync = synchronise('2026-10-03T12:10:00Z', '2026-10-03T12:00:00Z', 1000, 3000);
    expect(sync.remaining).toBe(599);
    expect(sync.approximate).toBe(false);
    expect(remainingAt(sync, 3000 + 99_000)).toBe(500);
    expect(remainingAt(sync, 10_000_000)).toBe(0);
  });
  it('flags a local clock far from the server time (EXG-UX-081)', () => {
    const received = Date.parse('2026-10-03T12:00:01Z');
    expect(synchronise('2026-10-03T12:10:00Z', '2026-10-03T12:00:00Z', 0, 2000, received).skewed).toBe(false);
    expect(synchronise('2026-10-03T12:10:00Z', '2026-10-03T12:00:00Z', 0, 2000, received + 3 * 60_000).skewed).toBe(true);
    expect(synchronise('2026-10-03T12:10:00Z', '2026-10-03T12:00:00Z', 0, 2000, received - 3 * 60_000).skewed).toBe(true);
  });
  it('flags slow round trips as approximate', () => {
    expect(synchronise('2026-10-03T12:10:00Z', '2026-10-03T12:00:00Z', 0, 5001).approximate).toBe(true);
  });
  it('announces only 5 min, 1 min and expiry', () => {
    expect(crossedThreshold(301, 299)).toBe(300);
    expect(crossedThreshold(61, 60)).toBe(60);
    expect(crossedThreshold(1, 0)).toBe(0);
    expect(crossedThreshold(200, 150)).toBeNull();
    expect(nextTickMs(30)).toBe(1000);
  });
});

describe('formatting', () => {
  it('formats absolute dates with a time zone and relative times', () => {
    setLocale('fr');
    expect(formatDate(Date.UTC(2026, 9, 3, 12, 0))).toMatch(/2026/);
    expect(formatRelative(3600)).toBe('dans 1 heure');
    setLocale('en');
    expect(formatRelative(-120)).toBe('2 minutes ago');
  });

  it('rounds to the next unit instead of showing "24 hours" or "60 minutes"', () => {
    setLocale('en');
    // A one-day paste seen a moment after creation (round trip, clock correction).
    expect(formatRelative(86_399)).toBe(formatRelative(86_400));
    expect(formatRelative(23.6 * 3600)).toBe(formatRelative(86_400));
    expect(formatRelative(23.4 * 3600)).toBe('in 23 hours');
    expect(formatRelative(3590)).toBe('in 1 hour');
    expect(formatRelative(59.7)).toBe('in 1 minute');
    expect(formatRelative(-3590)).toBe('1 hour ago');
  });

  it('never says "tomorrow": a relative day is not a calendar day (23.6 h at 00:10 ends today)', () => {
    setLocale('en');
    expect(formatRelative(84_600)).toBe('in 1 day');
    expect(formatRelative(129_599)).toBe('in 1 day');
    expect(formatRelative(3 * 86_400)).toBe('in 3 days');
    setLocale('fr');
    expect(formatRelative(86_400)).toBe('dans 1 jour');
    setLocale('en');
  });
});

describe('plural messages', () => {
  it('chooses the plural form of the active language (Intl.PluralRules), _other as fallback', async () => {
    setLocale('en');
    expect(tn('read.priorOpens', 1)).toContain('1 unconfirmed opening)');
    expect(tn('read.priorOpens', 3)).toContain('3 unconfirmed openings)');
    expect(tn('read.priorOpens', 1)).not.toContain('(s)');
    setLocale('fr');
    expect(tn('read.priorOpens', 1)).toContain('1 ouverture non confirmée');
    expect(tn('read.priorOpens', 2)).toContain('2 ouvertures non confirmées');
    // Arabic has zero/one/two/few/many/other categories: missing ones use _other.
    expect(await loadLocale('ar')).toBe(true);
    setLocale('ar');
    expect(tn('read.priorOpens', 2)).toBe(t('read.priorOpens_other', { count: 2 }));
    expect(tn('read.priorOpens', 2)).toContain('2');
    setLocale('en');
  });

  it('ships every plural key with both _one and _other forms in all catalogues', () => {
    for (const catalog of [en, fr] as unknown as Record<string, string>[]) {
      const bases = Object.keys(catalog).filter((k) => /_(one|other)$/.test(k)).map((k) => k.replace(/_(one|other)$/, ''));
      for (const base of new Set(bases)) {
        expect(catalog[`${base}_one`], base).toBeDefined();
        expect(catalog[`${base}_other`], base).toBeDefined();
      }
    }
  });
});

describe('catalogs', () => {
  it('have the same keys in every language', () => {
    expect(catalogKeys('fr').sort()).toEqual(catalogKeys('en').sort());
    expect(Object.keys(fr).sort()).toEqual(Object.keys(en).sort());
  });
  it('fall back to English and interpolate values as text', () => {
    setLocale('xx');
    expect(t('error.reserved', { seconds: 12 })).toBe('This content is being opened elsewhere. Try again in 12 seconds.');
    expect(t('unknown.key')).toBe('unknown.key');
  });
  it('declare a direction', () => {
    expect(en._meta.dir).toBe('ltr');
    expect(fr._meta.dir).toBe('ltr');
  });
});

describe('passphrase generator', () => {
  it('draws six words from a list of at least 2048 words (≥ 66 bits)', () => {
    const passphrase = generate(words);
    expect(passphrase.split('-')).toHaveLength(6);
    expect(words.length).toBeGreaterThanOrEqual(2048);
    expect(entropyBits(words.length)).toBeGreaterThanOrEqual(66);
    expect(generate(words)).not.toBe(passphrase);
  });
  it('never draws words containing the separator, so the word count stays readable', () => {
    const list = [...Array.from({ length: 2048 }, (_, i) => `word${i}`), 'felt-tip'];
    for (let i = 0; i < 200; i++) expect(generate(list)).not.toContain('felt-tip');
  });
  it('explains unconfirmed openings as §5.1 requires, in both languages', () => {
    expect(en['read.priorOpens_other']).toMatch(/interception.*reload.*connection.*compromised/s);
    expect(fr['read.priorOpens_other']).toMatch(/interception.*rechargement.*coupure réseau.*compromis/s);
  });
  it('estimates strength without blocking', () => {
    expect(strength('password')).toBe('weak');
    expect(strength('correct-horse-battery-staple-orbit-lantern')).not.toBe('weak');
  });
  it('rates by the character classes present, penalising digits only and sequences', () => {
    expect(strength('12345678901234567890')).toBe('weak');
    expect(strength('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')).toBe('weak');
    expect(strength('abcdefghijklmnopqrstuvwxyz')).toBe('weak');
    // Digits only, without a sequence: weaker than letters of the same length.
    expect(strength('83920571640293')).toBe('weak');
    // A digit alone does not bring the pools of absent classes (lower case, upper case).
    expect(strength('qwzpmxkr7')).toBe('weak');
    // Non-Latin letters are letters, not symbols; code points, not UTF-16 units, are counted.
    expect(strength('سكرتيرلبحدن')).toBe(strength('qvbtmzlqxhd'));
    expect(strength('x😀y😃z😆w😉')).toBe(strength('x!y@z#w$'));
    for (let i = 0; i < 50; i++) expect(strength(generate(words))).toBe('strong');
  });
});
