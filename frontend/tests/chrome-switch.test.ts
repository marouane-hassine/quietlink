// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (language switch, §6.6.1): EXG-I18N-005, EXG-I18N-007, EXG-UX-051.

import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PublicConfig } from '../src/config';

const loads = vi.hoisted(() => ({ pending: new Map<string, (ok: boolean) => void>(), failing: new Set<string>() }));
vi.mock('../src/i18n', async (original) => {
  const actual = await original<typeof import('../src/i18n')>();
  return {
    ...actual,
    // Controlled loading: resolves when the test says so, or fails.
    loadLocale: (code: string) => (code === 'en' || code === 'fr'
      ? Promise.resolve(true)
      : new Promise<boolean>((resolve) => {
        if (loads.failing.has(code)) resolve(false);
        else loads.pending.set(code, resolve);
      })),
  };
});

const { renderChrome, initTheme } = await import('../src/ui/chrome');
const { locale, setLocale, t } = await import('../src/i18n');

const config = { enabledLocales: ['en', 'es', 'it'], darkMode: 'auto', page: 'create', locales: [{ code: 'en', name: 'English', dir: 'ltr' }, { code: 'es', name: 'Español', dir: 'ltr' }, { code: 'it', name: 'Italiano', dir: 'ltr' }] } as PublicConfig;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('language switch robustness', () => {
  beforeEach(() => {
    setLocale('en');
    localStorage.clear();
    loads.pending.clear();
    loads.failing.clear();
    document.body.innerHTML = '<div id="ql-controls"></div>';
  });

  it('keeps the current language and the selector in sync when a catalogue cannot load', async () => {
    loads.failing.add('it');
    const redraws: string[] = [];
    renderChrome(config, 'auto', () => redraws.push(locale()));
    const select = document.getElementById('ql-language') as HTMLSelectElement;
    select.value = 'it';
    select.dispatchEvent(new Event('change'));
    await settle();

    expect(locale()).toBe('en');
    expect((document.getElementById('ql-language') as HTMLSelectElement).value).toBe('en');
    expect(redraws).toEqual([]);
    expect(document.getElementById('ql-toast')?.textContent).toBe(t('nav.languageFailed'));
  });

  it('applies only the last of two quick choices, never an intermediate fallback', async () => {
    const redraws: string[] = [];
    renderChrome(config, 'auto', () => redraws.push(locale()));
    const select = document.getElementById('ql-language') as HTMLSelectElement;
    select.value = 'es';
    select.dispatchEvent(new Event('change'));
    select.value = 'it';
    select.dispatchEvent(new Event('change'));
    // "es" finishes loading after "it" was chosen: it must not be applied.
    loads.pending.get('es')?.(true);
    await settle();

    expect(redraws).toEqual([]);
    expect(localStorage.getItem('ql-locale')).toBeNull();
  });

  it('ignores an unknown stored theme and shows the effective choice', () => {
    localStorage.setItem('ql-theme', 'bogus');
    const theme = initTheme(config);
    renderChrome(config, theme, () => undefined);

    expect(theme).toBe('auto');
    expect((document.getElementById('ql-theme-auto') as HTMLInputElement).checked).toBe(true);
  });
});
