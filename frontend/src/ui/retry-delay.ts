// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Retry after the delay the server announced (Retry-After, §10; journey C, §12): the button
 * stays disabled with a countdown instead of offering an attempt that would be refused again.
 */

import { t } from '../i18n';

/** `onDone` runs once when a countdown ends (the page re-enables its other actions). */
export function holdRetry(button: HTMLButtonElement, seconds: number | null, onDone?: () => void): void {
  let left = seconds !== null && seconds > 0 ? Math.ceil(seconds) : 0;
  const update = () => {
    button.disabled = left > 0;
    button.textContent = left > 0 ? t('action.retryIn', { seconds: left }) : t('action.retry');
  };
  update();
  if (left === 0) return;
  const timer = window.setInterval(() => {
    left -= 1;
    update();
    if (left <= 0 || !button.isConnected) {
      window.clearInterval(timer);
      if (left <= 0 && button.isConnected) onDone?.();
    }
  }, 1000);
}
