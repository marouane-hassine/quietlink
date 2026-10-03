// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (sticky action bar never hides the focus, §5.1): EXG-UX-089, EXG-A11Y-016.

import { afterEach, describe, expect, it, vi } from 'vitest';
import { followActionBar } from '../src/ui/viewport';

describe('action bar height', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('publishes the height of the current action bar for scroll padding', () => {
    let notify: () => void = () => undefined;
    vi.stubGlobal('ResizeObserver', class {
      constructor(callback: () => void) {
        notify = callback;
      }
      observe(): void {}
      disconnect(): void {}
    });
    document.body.innerHTML = '<main id="main"><div class="action-bar"></div></main>';
    const bar = document.querySelector('.action-bar') as HTMLElement;
    vi.spyOn(bar, 'getBoundingClientRect').mockReturnValue({ height: 184 } as DOMRect);
    followActionBar(document.getElementById('main') as HTMLElement);
    notify();

    expect(document.documentElement.style.getPropertyValue('--ql-bar-height')).toBe('184px');
  });
});
