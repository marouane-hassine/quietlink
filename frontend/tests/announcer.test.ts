// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (accessible states, no stale alerts, §5.1, §6.6): EXG-A11Y-005, EXG-UX-047.

import { describe, expect, it } from 'vitest';
import { announce } from '../src/ui/announcer';
import { showScreen } from '../src/ui/dom';

describe('announcements', () => {
  it('drops an urgent alert of the previous screen when the screen changes', async () => {
    document.body.innerHTML = '<main id="main"></main>';
    announce('Dummy error of the previous screen', true);
    await new Promise((resolve) => setTimeout(resolve, 60));
    const alert = document.querySelector('[aria-live="assertive"]') as HTMLElement;
    expect(alert.textContent).toBe('Dummy error of the previous screen');

    showScreen(document.getElementById('main') as HTMLElement, Object.assign(document.createElement('h1'), { textContent: 'Next' }));
    expect(alert.textContent).toBe('');
  });
});
