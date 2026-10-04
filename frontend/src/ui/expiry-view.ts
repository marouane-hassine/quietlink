// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Live expiration line shared by the result and reading screens (§5.1): relative time from the
 * synchronised countdown, local date, threshold announcements, an inaccurate-clock notice, and
 * resynchronisation with the elapsed wall-clock gap when the page becomes visible again.
 */

import { t } from '../i18n';
import { announce } from './announcer';
import { crossedThreshold, nextTickMs, remainingAt, type Sync } from './countdown';
import { formatDate, formatRelative } from './format';

export function runCountdown(node: HTMLElement, expiresAt: string | null, sync: Sync | null): void {
  if (expiresAt === null || sync === null) {
    node.textContent = t('result.never');
    return;
  }
  const line = document.createElement('span');
  node.replaceChildren(line);
  if (sync.skewed) {
    const warning = document.createElement('span');
    warning.className = 'clock-warning';
    warning.textContent = ` ${t('time.clockWarning')}`;
    node.append(warning);
  }
  const date = formatDate(Date.parse(expiresAt));
  let previous = Number.POSITIVE_INFINITY;
  let first = true;
  let timer = 0;
  let hiddenWall = 0;
  let hiddenMono = 0;

  const tick = () => {
    const remaining = remainingAt(sync, performance.now());
    line.textContent = remaining <= 0 ? t('time.expired') : t('result.expires', { relative: (sync.approximate ? `${t('time.approximate')} ` : '') + formatRelative(remaining), date });
    if (crossedThreshold(previous, remaining) !== null) announce(line.textContent);
    previous = remaining;
    // The first tick may run before the screen is attached; later ticks stop once it is gone.
    if (remaining > 0 && (node.isConnected || first)) timer = window.setTimeout(tick, nextTickMs(remaining));
    first = false;
  };
  const onVisibility = () => {
    if (!node.isConnected) {
      document.removeEventListener('visibilitychange', onVisibility);
      window.clearTimeout(timer);
      return;
    }
    if (document.visibilityState === 'hidden') {
      hiddenWall = Date.now();
      hiddenMono = performance.now();
    } else if (hiddenWall > 0) {
      // performance.now() may stop during sleep: apply the elapsed wall-clock gap (step 4).
      const gap = Date.now() - hiddenWall - (performance.now() - hiddenMono);
      if (gap > 1000) sync.at -= gap;
      hiddenWall = 0;
      window.clearTimeout(timer);
      tick();
    }
  };
  document.addEventListener('visibilitychange', onVisibility);
  tick();
}
