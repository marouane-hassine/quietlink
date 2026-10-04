// SPDX-License-Identifier: AGPL-3.0-or-later

/** Header controls: language and light/dark choice (only preferences are remembered). */

import { availableLocales, loadLocale, locale, setLocale, t } from '../i18n';
import { el, redrawInPlace, windowTitle } from './dom';
import { toast } from './announcer';
import type { PublicConfig } from '../config';

const THEME_KEY = 'ql-theme';

const THEMES = ['auto', 'light', 'dark'];
/** Increments with each language choice; only the latest one is applied. */
let languageRequest = 0;

/** The remembered theme, if it is one of the known choices (an old or edited value is ignored). */
function storedTheme(): string | null {
  try {
    const value = localStorage.getItem(THEME_KEY);
    return value !== null && THEMES.includes(value) ? value : null;
  } catch {
    return null;
  }
}

export function applyTheme(choice: string): void {
  if (choice === 'light' || choice === 'dark') document.documentElement.dataset.theme = choice;
  else delete document.documentElement.dataset.theme;
}

export function initTheme(config: PublicConfig): string {
  const choice = storedTheme() ?? (THEMES.includes(config.darkMode) ? config.darkMode : 'auto');
  applyTheme(choice);
  return choice;
}

/** Window title in the current language; the server rendered it from Accept-Language. */
export function translateTitle(config: PublicConfig): void {
  document.title = windowTitle(t(`page.${config.page}.title`));
}

const SVG_NS = 'http://www.w3.org/2000/svg';

/** Line icons drawn with SVG elements (no inline style or markup string, CSP-safe). */
const THEME_ICONS: Record<'auto' | 'light' | 'dark', [string, Record<string, string>][]> = {
  // Half-filled circle: follows the system setting.
  auto: [['circle', { cx: '12', cy: '12', r: '8' }], ['path', { d: 'M12 4a8 8 0 0 1 0 16z', class: 'icon-fill' }]],
  // Sun.
  light: [
    ['circle', { cx: '12', cy: '12', r: '4' }],
    ['path', { d: 'M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4' }],
  ],
  // Crescent moon.
  dark: [['path', { d: 'M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z' }]],
};

function themeIcon(name: 'auto' | 'light' | 'dark'): SVGSVGElement {
  const svg = document.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  svg.setAttribute('class', 'icon');
  for (const [tag, attributes] of THEME_ICONS[name]) {
    const shape = document.createElementNS(SVG_NS, tag);
    for (const [key, value] of Object.entries(attributes)) shape.setAttribute(key, value);
    svg.append(shape);
  }
  return svg;
}

/** Renders the controls; `rerender` redraws the page texts after a language change. */
export function renderChrome(config: PublicConfig, theme: string, rerender: () => void): void {
  const slot = document.getElementById('ql-controls');
  if (!slot) return;
  const languageSelect = el('select', { id: 'ql-language', class: 'control-select' });
  for (const option of availableLocales(config.enabledLocales, config.locales)) {
    languageSelect.append(el('option', { value: option.code, selected: option.code === locale(), lang: option.code }, option.name));
  }
  languageSelect.addEventListener('change', async () => {
    // Captured before loading: a later choice made meanwhile wins (arrow keys on the select fire
    // one change per step), and an earlier load finishing late is dropped.
    const code = languageSelect.value;
    const name = languageSelect.selectedOptions[0]?.textContent ?? '';
    const request = ++languageRequest;
    // Catalogues other than English and French are loaded on first use.
    const loaded = await loadLocale(code);
    if (request !== languageRequest) return;
    if (!loaded || !setLocale(code, true)) {
      languageSelect.value = locale();
      toast(t('nav.languageFailed'));
      return;
    }
    const hadFocus = document.activeElement === languageSelect || document.activeElement === document.body;
    // Page title first: the redraw then names the current screen (showScreen).
    translateTitle(config);
    redrawInPlace(rerender);
    renderChrome(config, storedTheme() ?? theme, rerender);
    if (hadFocus) document.getElementById('ql-language')?.focus();
    toast(t('nav.languageChanged', { language: name }));
  });

  // Theme: three icon-only choices in a labelled group (native radios: arrow keys, screen
  // readers); each name stays available to assistive technologies and as a tooltip.
  const themeGroup = el('fieldset', { id: 'ql-theme', class: 'theme-switch' }, el('legend', { class: 'visually-hidden' }, t('nav.theme')));
  for (const value of ['auto', 'light', 'dark'] as const) {
    const id = `ql-theme-${value}`;
    const radio = el('input', { type: 'radio', id, name: 'ql-theme-choice', value, checked: value === theme });
    const name = t(`theme.${value}`);
    themeGroup.append(radio, el('label', { for: id, title: name }, themeIcon(value), el('span', { class: 'visually-hidden' }, name)));
  }
  themeGroup.addEventListener('change', (event) => {
    const choice = (event.target as HTMLInputElement).value;
    applyTheme(choice);
    try {
      localStorage.setItem(THEME_KEY, choice);
    } catch {
      // Not remembered in private mode.
    }
  });

  slot.replaceChildren(
    el('label', { for: 'ql-language', class: 'visually-hidden' }, t('nav.language')),
    languageSelect,
    themeGroup,
  );
  const how = document.getElementById('ql-footer-how');
  if (how) how.textContent = t('footer.how');
  const source = document.getElementById('ql-footer-source');
  if (source) source.textContent = t('footer.source');
  const skip = document.getElementById('ql-skip');
  if (skip) skip.textContent = t('app.skip');
}
