// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-UX-055, EXG-UX-057, EXG-A11Y-003.

import { describe, expect, it } from 'vitest';
import { setLocale } from '../src/i18n';
import { watchConnectivity } from '../src/ui/connectivity';

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
});
