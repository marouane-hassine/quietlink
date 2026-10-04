// SPDX-License-Identifier: AGPL-3.0-or-later

/** aria-live regions: status updates and confirmations announced without moving focus. */

let polite: HTMLElement | null = null;
let assertive: HTMLElement | null = null;
let toastTimer: number | undefined;

function region(kind: 'polite' | 'assertive'): HTMLElement {
  const existing = kind === 'polite' ? polite : assertive;
  if (existing) return existing;
  const node = document.createElement('div');
  node.className = 'visually-hidden';
  node.setAttribute('aria-live', kind);
  node.setAttribute('role', kind === 'polite' ? 'status' : 'alert');
  document.body.append(node);
  if (kind === 'polite') polite = node;
  else assertive = node;
  return node;
}

export function announce(message: string, urgent = false): void {
  const node = region(urgent ? 'assertive' : 'polite');
  node.textContent = '';
  window.setTimeout(() => {
    node.textContent = message;
  }, 50);
}

/** Empties the urgent region so a previous screen's error is not read again later. */
export function clearAlerts(): void {
  if (assertive) assertive.textContent = '';
}

/** Visible, temporary confirmation (also announced). Never contains secrets. */
export function toast(message: string): void {
  let node = document.getElementById('ql-toast');
  if (!node) {
    node = document.createElement('div');
    node.id = 'ql-toast';
    node.className = 'toast';
    document.body.append(node);
  }
  node.textContent = message;
  node.classList.add('is-visible');
  window.clearTimeout(toastTimer);
  toastTimer = window.setTimeout(() => node?.classList.remove('is-visible'), 2500);
  announce(message);
}
