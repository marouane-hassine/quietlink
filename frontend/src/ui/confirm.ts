// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Inline confirmation shown right after the triggering control (§5.1: no blocking modal,
 * dismissible, reachable with one hand). Escape or Cancel dismisses; focus returns to the
 * trigger.
 */

import { t } from '../i18n';
import { el, nextId } from './dom';

let open: (() => void) | null = null;

export function confirmInline(trigger: HTMLElement, message: string, confirmLabel: string, danger = false): Promise<boolean> {
  open?.();
  return new Promise((resolve) => {
    const confirm = el('button', { type: 'button', class: `button ${danger ? 'button-danger' : 'button-primary'}` }, confirmLabel);
    const cancel = el('button', { type: 'button', class: 'button button-secondary' }, t('action.cancel'));
    const text = el('p', { class: 'confirm-message' }, message);
    text.id = nextId('confirm');
    const panel = el('div', { class: 'confirm-panel', role: 'alertdialog', 'aria-modal': 'false', 'aria-labelledby': text.id }, text, el('div', { class: 'button-row' }, confirm, cancel));
    const close = (result: boolean) => {
      const shown = panel.isConnected;
      panel.remove();
      document.removeEventListener('keydown', onKey, true);
      open = null;
      if (shown && trigger.isConnected) trigger.focus();
      resolve(result);
    };
    const onKey = (event: KeyboardEvent) => {
      if (!panel.isConnected) {
        // A redraw removed the panel: release the listener and let the key through.
        close(false);
        return;
      }
      if (event.key === 'Escape') {
        event.stopPropagation();
        close(false);
      }
    };
    confirm.addEventListener('click', () => close(true));
    cancel.addEventListener('click', () => close(false));
    document.addEventListener('keydown', onKey, true);
    open = () => close(false);
    trigger.after(panel);
    confirm.focus();
  });
}
