// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-UX-055, EXG-UX-057, EXG-A11Y-003, EXG-I18N-009.

import { describe, expect, it } from 'vitest';
import { setLocale, t } from '../src/i18n';
import { watchConnectivity } from '../src/ui/connectivity';
import { showScreen } from '../src/ui/dom';

describe('connectivity', () => {
  it('shows a banner while offline and removes it when the connection returns, keeping the page content', () => {
    setLocale('en');
    document.body.innerHTML = '<main><textarea>draft kept</textarea></main>';
    const stop = watchConnectivity();

    window.dispatchEvent(new Event('offline'));
    expect(document.querySelector('.offline-banner')?.textContent).toContain('You are offline');
    expect(document.querySelector('textarea')?.value).toBe('draft kept');

    window.dispatchEvent(new Event('online'));
    expect(document.querySelector('.offline-banner')).toBeNull();
    stop();
  });

  it('translates the offline banner again on a language change (EXG-I18N-009)', () => {
    setLocale('en');
    document.body.innerHTML = '<main id="main"><h1>First</h1></main>';
    const stop = watchConnectivity();
    window.dispatchEvent(new Event('offline'));
    setLocale('fr');

    expect(document.querySelector('.offline-banner')?.textContent).toBe(t('net.offline'));
    expect(t('net.offline')).not.toBe('');
    window.dispatchEvent(new Event('online'));
    stop();
    setLocale('en');
  });

  it('keeps the banner across screen changes while still offline', () => {
    document.body.innerHTML = '<main id="main"><h1>First</h1></main>';
    const stop = watchConnectivity();
    window.dispatchEvent(new Event('offline'));
    showScreen(document.getElementById('main') as HTMLElement, Object.assign(document.createElement('h1'), { textContent: 'Second' }));

    expect(document.querySelector('.offline-banner')).not.toBeNull();
    window.dispatchEvent(new Event('online'));
    stop();
  });
});
