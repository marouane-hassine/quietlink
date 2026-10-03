// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Management page (§8.5): never shows the content, reveals nothing before the action,
 * checks the token against the identifier locally, then deletes after confirmation.
 */

import { api, ApiError } from '../api';
import { decode, encode, EncodingError } from '../crypto/base64url';
import { matchesDeletionToken } from '../crypto/protocol';
import { t } from '../i18n';
import { announce } from '../ui/announcer';
import { confirmInline } from '../ui/confirm';
import { el, showScreen } from '../ui/dom';
import { cryptoAvailable } from './create';

export function mountManage(main: HTMLElement): () => void {
  const render = async () => {
    const message = (key: string) => {
      showScreen(main, el('h1', { class: 'page-title' }, t('page.manage.title')), el('p', { role: 'alert' }, t(key)), el('a', { href: '/', class: 'button button-secondary' }, t('action.new')));
      announce(t(key));
    };
    if (!cryptoAvailable()) return message('app.unsupported');
    const id = location.pathname.split('/').pop() ?? '';
    let idBytes: Uint8Array;
    let token: Uint8Array;
    try {
      idBytes = decode(id, 24);
      token = decode(location.hash.slice(1), 32);
    } catch (error) {
      if (error instanceof EncodingError) return message('error.incompleteLink');
      throw error;
    }
    if (!(await matchesDeletionToken(idBytes, token))) return message('error.alteredLink');

    const button = el('button', { type: 'button', class: 'button button-danger' }, t('manage.delete'));
    button.addEventListener('click', async () => {
      if (!(await confirmInline(button, t('manage.confirm'), t('manage.delete'), true))) return;
      button.disabled = true;
      announce(t('state.deleting'));
      try {
        await api.remove(id, encode(token));
      } catch (error) {
        if (error instanceof ApiError && error.kind !== 'unavailable') {
          button.disabled = false;
          announce(t(error.kind === 'network' ? 'error.network' : 'error.server'), true);
          return;
        }
      }
      // One message whether deleted, expired or invalid (§8.5).
      message('manage.done');
    });
    showScreen(main, el('h1', { class: 'page-title' }, t('page.manage.title')), el('p', {}, t('manage.intro')), el('p', { class: 'warning' }, t('manage.warning')), el('div', { class: 'action-bar' }, button));
  };
  void render();
  return () => void render();
}
