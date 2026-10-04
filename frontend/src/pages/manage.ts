// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Management page (§8.5): never shows the content, reveals nothing before the action,
 * checks the token against the identifier locally, then deletes after confirmation.
 */

import { api, ApiError } from '../api';
import { decode, encode, EncodingError } from '../crypto/base64url';
import { matchesDeletionToken } from '../crypto/protocol';
import { t } from '../i18n';
import { confirmInline } from '../ui/confirm';
import { el, forgetFragment, reloadOnFragmentChange, showScreen } from '../ui/dom';
import { cryptoAvailable } from '../ui/capabilities';

export function mountManage(main: HTMLElement): () => void {
  reloadOnFragmentChange();
  /** Set once the deletion request is answered: a redraw must not offer deletion again. */
  let done = false;
  /** Deletion request in flight, and the last failure: drawn again by a redraw. */
  let deleting = false;
  let failureKey: string | null = null;
  /** Controls of the screen currently shown (a redraw replaces them). */
  let controls: { button: HTMLButtonElement; status: HTMLElement; failure: HTMLElement } | null = null;

  const apply = () => {
    if (!controls) return;
    controls.button.disabled = deleting;
    controls.status.textContent = deleting ? t('state.deleting') : '';
    // Visible on the screen, not only announced (§5.1).
    controls.failure.hidden = failureKey === null;
    controls.failure.textContent = failureKey === null ? '' : t(failureKey);
  };
  /** Result of the local link check, made once: redraws (language change) are synchronous. */
  let verdict: { messageKey: string } | { id: string; token: Uint8Array } | null = null;

  const message = (key: string) => {
    // The role="alert" paragraph is announced on insertion: no second announcement.
    showScreen(main, el('h1', { class: 'page-title' }, t('page.manage.title')), el('p', { role: 'alert' }, t(key)), el('a', { href: '/', class: 'button button-secondary' }, t('action.new')));
  };

  const check = async (): Promise<typeof verdict> => {
    if (!cryptoAvailable()) return { messageKey: 'app.unsupported' };
    const id = location.pathname.split('/').pop() ?? '';
    let idBytes: Uint8Array;
    let token: Uint8Array;
    try {
      idBytes = decode(id, 24);
      token = decode(location.hash.slice(1), 32);
    } catch (error) {
      if (error instanceof EncodingError) return { messageKey: 'error.incompleteLink' };
      throw error;
    }
    return (await matchesDeletionToken(idBytes, token)) ? { id, token } : { messageKey: 'error.alteredLink' };
  };

  const draw = () => {
    if (done) return message('manage.done');
    if (verdict === null) return;
    if ('messageKey' in verdict) return message(verdict.messageKey);
    const { id, token } = verdict;
    const button = el('button', { type: 'button', class: 'button button-danger' }, t('manage.delete'));
    const status = el('p', { class: 'status', role: 'status' });
    const failure = el('p', { class: 'error', role: 'alert', hidden: true });
    button.addEventListener('click', async () => {
      if (deleting || !(await confirmInline(button, t('manage.confirm'), t('manage.delete'), true))) return;
      deleting = true;
      failureKey = null;
      apply();
      try {
        await api.remove(id, encode(token));
      } catch (error) {
        if (error instanceof ApiError && error.kind !== 'unavailable') {
          deleting = false;
          failureKey = error.kind === 'network' ? 'manage.deleteNetwork' : 'manage.deleteFailed';
          apply();
          return;
        }
      }
      // One message whether deleted, expired or invalid (§8.5).
      deleting = false;
      done = true;
      controls = null;
      forgetFragment();
      message('manage.done');
    });
    controls = { button, status, failure };
    showScreen(main, el('h1', { class: 'page-title' }, t('page.manage.title')), el('p', {}, t('manage.intro')), el('p', { class: 'warning' }, t('manage.warning')), failure, el('div', { class: 'action-bar' }, button, status));
    apply();
  };

  void check().then((result) => {
    verdict = result;
    draw();
  });
  return draw;
}
