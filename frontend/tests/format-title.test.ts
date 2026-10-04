// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Localised byte sizes and window titles. Requirements: EXG-I18N-001, EXG-A11Y-016.

import { beforeEach, describe, expect, it } from 'vitest';
import { setLocale } from '../src/i18n';
import { el, setInstanceName, showScreen } from '../src/ui/dom';
import { formatBytes as rawFormat } from '../src/ui/format';

// Intl inserts non-breaking spaces; compare with plain ones.
const formatBytes = (n: number) => rawFormat(n).replace(/\s/g, ' ');

describe('byte sizes', () => {
  it('use units localised by the browser, not hard-coded english abbreviations', () => {
    setLocale('en');
    expect(formatBytes(500)).toBe('500 byte');
    expect(formatBytes(1_441_792)).toBe('1.4 MB');
    setLocale('fr');
    expect(formatBytes(2_500)).toBe('2,5 ko');
    expect(formatBytes(1_441_792)).toBe('1,4 Mo');
  });
});

describe('window title', () => {
  beforeEach(() => {
    setLocale('en');
    document.body.innerHTML = '<main id="main"></main>';
  });

  it('keeps an instance name that itself contains the separator', () => {
    setInstanceName('Acme · Paste');
    document.title = 'New confidential text · Acme · Paste';
    const main = document.getElementById('main') as HTMLElement;
    showScreen(main, el('h1', {}, 'First'));
    showScreen(main, el('h1', {}, 'Second'));
    expect(document.title).toBe('Second · Acme · Paste');
  });
});
