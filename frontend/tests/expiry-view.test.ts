// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-UX-081, EXG-UX-082, EXG-UX-083, EXG-A11Y-007.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setLocale, t } from '../src/i18n';
import { synchronise } from '../src/ui/countdown';
import { runCountdown } from '../src/ui/expiry-view';

describe('expiry line', () => {
  let now = 0;
  beforeEach(() => {
    setLocale('en');
    vi.useFakeTimers();
    now = 10_000;
    vi.spyOn(performance, 'now').mockImplementation(() => now);
    document.body.innerHTML = '';
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it('keeps ticking when it is started before the screen is attached', () => {
    const wall = Date.parse('2026-10-03T12:00:00Z');
    vi.spyOn(Date, 'now').mockReturnValue(wall);
    const sync = synchronise('2026-10-03T12:02:00Z', '2026-10-03T12:00:00Z', now, now);
    const node = document.createElement('p');
    runCountdown(node, '2026-10-03T12:02:00Z', sync);
    document.body.append(node);

    now += 125_000;
    vi.advanceTimersByTime(125_000);

    expect(node.textContent).toBe(t('time.expired'));
  });

  it('stops its timer and listener once the screen is gone', () => {
    const sync = synchronise('2026-10-03T13:00:00Z', '2026-10-03T12:00:00Z', now, now, Date.parse('2026-10-03T12:00:00Z'));
    const node = document.createElement('p');
    document.body.append(node);
    runCountdown(node, '2026-10-03T13:00:00Z', sync);
    node.remove();
    vi.advanceTimersByTime(3_600_000);

    expect(vi.getTimerCount()).toBe(0);
  });
});
