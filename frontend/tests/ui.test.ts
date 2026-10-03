// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-UX-082, EXG-UX-084, EXG-A11Y-007, EXG-I18N-002, EXG-I18N-006, EXG-I18N-007, EXG-CRYPTO-004, EXG-CRYPTO-003, EXG-I18N-008, EXG-I18N-012.

import { describe, expect, it } from 'vitest';
import { crossedThreshold, nextTickMs, remainingAt, synchronise } from '../src/ui/countdown';
import { formatDate, formatRelative } from '../src/ui/format';
import { catalogKeys, setLocale, t } from '../src/i18n';
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
  it('estimates strength without blocking', () => {
    expect(strength('password')).toBe('weak');
    expect(strength('correct-horse-battery-staple-orbit-lantern')).not.toBe('weak');
  });
});
