// SPDX-License-Identifier: AGPL-3.0-or-later

/** Header controls: language and light/dark choice (only preferences are remembered). */

import { availableLocales, locale, setLocale, t } from '../i18n';
import { el, redrawInPlace } from './dom';
import { toast } from './announcer';
import type { PublicConfig } from '../config';

const THEME_KEY = 'ql-theme';

function storedTheme(): string | null {
  try {
    return localStorage.getItem(THEME_KEY);
  } catch {
    return null;
  }
}

export function applyTheme(choice: string): void {
  if (choice === 'light' || choice === 'dark') document.documentElement.dataset.theme = choice;
  else delete document.documentElement.dataset.theme;
}

export function initTheme(config: PublicConfig): string {
  const choice = storedTheme() ?? config.darkMode;
  applyTheme(choice);
  return choice;
}

/** Window title in the current language; the server rendered it from Accept-Language. */
export function translateTitle(config: PublicConfig): void {
  const separator = document.title.lastIndexOf(' · ');
  const suffix = separator >= 0 ? document.title.slice(separator) : '';
  document.title = t(`page.${config.page}.title`) + suffix;
}

/** Renders the controls; `rerender` redraws the page texts after a language change. */
export function renderChrome(config: PublicConfig, theme: string, rerender: () => void): void {
  const slot = document.getElementById('ql-controls');
  if (!slot) return;
  const languageSelect = el('select', { id: 'ql-language', class: 'control-select' });
  for (const option of availableLocales(config.enabledLocales)) {
    languageSelect.append(el('option', { value: option.code, selected: option.code === locale() }, option.name));
  }
  languageSelect.addEventListener('change', () => {
    setLocale(languageSelect.value, true);
    redrawInPlace(rerender);
    translateTitle(config);
    renderChrome(config, storedTheme() ?? theme, rerender);
    document.getElementById('ql-language')?.focus();
    toast(t('nav.languageChanged', { language: languageSelect.selectedOptions[0]?.textContent ?? '' }));
  });

  const themeSelect = el('select', { id: 'ql-theme', class: 'control-select' });
  for (const value of ['auto', 'light', 'dark']) {
    themeSelect.append(el('option', { value, selected: value === theme }, t(`theme.${value}`)));
  }
  themeSelect.addEventListener('change', () => {
    applyTheme(themeSelect.value);
    try {
      localStorage.setItem(THEME_KEY, themeSelect.value);
    } catch {
      // Not remembered in private mode.
    }
  });

  slot.replaceChildren(
    el('label', { for: 'ql-language', class: 'visually-hidden' }, t('nav.language')),
    languageSelect,
    el('label', { for: 'ql-theme', class: 'visually-hidden' }, t('nav.theme')),
    themeSelect,
  );
  const how = document.getElementById('ql-footer-how');
  if (how) how.textContent = t('footer.how');
  const source = document.getElementById('ql-footer-source');
  if (source) source.textContent = t('footer.source');
  const skip = document.getElementById('ql-skip');
  if (skip) skip.textContent = t('app.skip');
}
