// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Optional local export and printing (§6.8, Could): disabled by default, explicit action only,
 * nothing sent over the network, printing preceded by a warning.
 */

import { t } from '../i18n';
import { toast } from './announcer';
import { confirmInline } from './confirm';
import { el } from './dom';

/** Saves the decrypted text as a local file through a short-lived object URL. */
export function exportButton(text: () => string): HTMLButtonElement {
  const button = el('button', { type: 'button', class: 'button button-secondary' }, t('read.export'));
  button.addEventListener('click', () => {
    const url = URL.createObjectURL(new Blob([text()], { type: 'text/plain;charset=utf-8' }));
    const anchor = el('a', { href: url, download: `quietlink-${new Date().toISOString().slice(0, 10)}.txt`, hidden: true });
    document.body.append(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    toast(t('read.exported'));
  });
  return button;
}

/** Prints only the decrypted content, after an explicit warning (print CSS hides everything else). */
export function printButton(): HTMLButtonElement {
  const button = el('button', { type: 'button', class: 'button button-secondary' }, t('read.print'));
  button.addEventListener('click', async () => {
    if (!(await confirmInline(button, t('read.printWarning'), t('read.printConfirm')))) return;
    document.body.classList.add('print-allowed');
    // Browsers may print the page address in headers or footers: the key fragment of a
    // multi-read link is dropped from it while printing, then put back.
    const hash = location.hash;
    if (hash !== '') history.replaceState(history.state, '', location.pathname + location.search);
    window.addEventListener('afterprint', () => {
      document.body.classList.remove('print-allowed');
      if (hash !== '' && location.hash === '') history.replaceState(history.state, '', location.pathname + location.search + hash);
    }, { once: true });
    window.print();
  });
  return button;
}
