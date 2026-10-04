// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (texts from the catalogues only, §6.6.1): EXG-I18N-001, EXG-UX-051.

import { describe, expect, it } from 'vitest';
import { setLocale, t } from '../src/i18n';
import { showScreen } from '../src/ui/dom';
import { renderChrome } from '../src/ui/chrome';
import type { PublicConfig } from '../src/config';

const config = { enabledLocales: ['en', 'fr'], darkMode: 'auto', page: 'read' } as PublicConfig;

describe('header controls', () => {
  it('announces the language change with the punctuation of the new language', () => {
    setLocale('fr');
    document.body.innerHTML = '<div id="ql-controls"></div><div id="ql-toast" aria-live="polite"></div>';
    renderChrome(config, 'auto', () => undefined);
    const select = document.getElementById('ql-language') as HTMLSelectElement;
    select.value = 'en';
    select.dispatchEvent(new Event('change'));

    expect(document.getElementById('ql-toast')?.textContent).toBe('Language: English');

  });

  it('keeps focus on the language control and translates the window title (§6.6.1)', () => {
    setLocale('fr');
    document.title = 'Texte confidentiel · QuietLink';
    document.body.innerHTML = '<div id="ql-controls"></div><main id="main"></main>';
    const main = document.getElementById('main') as HTMLElement;
    const draw = () => showScreen(main, Object.assign(document.createElement('h1'), { textContent: t('page.read.title') }));
    draw();
    renderChrome(config, 'auto', draw);
    const select = document.getElementById('ql-language') as HTMLSelectElement;
    select.focus();
    select.value = 'en';
    select.dispatchEvent(new Event('change'));

    expect(document.activeElement?.id).toBe('ql-language');
    expect(main.querySelector('h1')?.textContent).toBe('Confidential text');
    expect(document.title).toBe('Confidential text · QuietLink');
  });

  it('offers the theme as a labelled group of three choices, with icons, applied and remembered', () => {
    setLocale('en');
    localStorage.clear();
    document.body.innerHTML = '<div id="ql-controls"></div>';
    renderChrome(config, 'auto', () => undefined);
    const group = document.getElementById('ql-theme') as HTMLFieldSetElement;

    expect(group.tagName).toBe('FIELDSET');
    expect(group.querySelector('legend')?.textContent).toBe(t('nav.theme'));
    const radios = [...group.querySelectorAll('input[type=radio]')] as HTMLInputElement[];
    expect(radios.map((radio) => radio.value)).toEqual(['auto', 'light', 'dark']);
    expect(radios.map((radio) => group.querySelector(`label[for="${radio.id}"]`)?.textContent)).toEqual([t('theme.auto'), t('theme.light'), t('theme.dark')]);
    // Icons only on screen; the name stays available to assistive technologies and as a tooltip.
    for (const radio of radios) {
      const label = group.querySelector(`label[for="${radio.id}"]`) as HTMLLabelElement;
      expect(label.querySelector('svg[aria-hidden="true"]')).not.toBeNull();
      expect(label.querySelector('span')?.classList.contains('visually-hidden')).toBe(true);
      expect(label.title).toBe(label.textContent);
    }
    expect(radios.find((radio) => radio.checked)?.value).toBe('auto');

    radios[2]!.checked = true;
    radios[2]!.dispatchEvent(new Event('change', { bubbles: true }));
    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(localStorage.getItem('ql-theme')).toBe('dark');
  });
});
