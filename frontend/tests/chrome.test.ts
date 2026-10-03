// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (texts from the catalogues only, §6.6.1): EXG-I18N-001, EXG-UX-051.

import { describe, expect, it } from 'vitest';
import { setLocale } from '../src/i18n';
import { renderChrome } from '../src/ui/chrome';
import type { PublicConfig } from '../src/config';

const config = { enabledLocales: ['en', 'fr'], darkMode: 'auto' } as PublicConfig;

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
});
