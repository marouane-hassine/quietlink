// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (retry after the announced delay, §12 journey C, §10 Retry-After): EXG-UX-120.

import { afterEach, describe, expect, it, vi } from 'vitest';
import { setLocale, t } from '../src/i18n';
import { holdRetry } from '../src/ui/retry-delay';

afterEach(() => vi.useRealTimers());

describe('retry delay', () => {
  it('keeps Retry disabled with a countdown until Retry-After has elapsed', () => {
    setLocale('en');
    vi.useFakeTimers();
    const button = document.createElement('button');
    document.body.replaceChildren(button);
    holdRetry(button, 3);

    expect(button.disabled).toBe(true);
    expect(button.textContent).toBe(t('action.retryIn', { seconds: 3 }));
    vi.advanceTimersByTime(1000);
    expect(button.textContent).toBe(t('action.retryIn', { seconds: 2 }));
    vi.advanceTimersByTime(2000);
    expect(button.disabled).toBe(false);
    expect(button.textContent).toBe(t('action.retry'));
  });

  it('leaves Retry available at once without a delay', () => {
    const button = document.createElement('button');
    holdRetry(button, null);
    expect(button.disabled).toBe(false);
    expect(button.textContent).toBe(t('action.retry'));
  });
});
