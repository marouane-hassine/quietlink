// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (management page §8.5, language switch §6.6.1): EXG-URL-012, EXG-I18N-001,
// EXG-I18N-005, EXG-UX-049.

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { encode } from '../src/crypto/base64url';
import { concat, randomBytes } from '../src/crypto/bytes';
import { ID_ACCESS_PREFIX, ID_DELETE_PREFIX } from '../src/crypto/constants';
import { fingerprint, sha256 } from '../src/crypto/primitives';
import { mountManage } from '../src/pages/manage';
import { setLocale, t } from '../src/i18n';
import { deferred, mockFetch, response, setSecureContext, until } from './support/fake-api';
import { navigation, redrawInPlace } from '../src/ui/dom';

describe('management page', () => {
  let main: HTMLElement;
  let rerender: () => void;

  beforeEach(async () => {
    setLocale('en');
    setSecureContext(true);
    const token = randomBytes(32);
    const id = concat(await fingerprint(ID_ACCESS_PREFIX, randomBytes(32)), await fingerprint(ID_DELETE_PREFIX, await sha256(token)), randomBytes(8));
    history.replaceState(null, '', `/manage/${encode(id)}#${encode(token)}`);
    mockFetch(() => response(204));
    document.body.innerHTML = '<main id="main"></main>';
    main = document.getElementById('main') as HTMLElement;
    rerender = mountManage(main);
  });

  it('keeps the completion message after a language change instead of offering deletion again', async () => {
    await until(() => main.querySelector('.button-danger') !== null);
    (main.querySelector('.button-danger') as HTMLButtonElement).click();
    await until(() => main.querySelector('[role=alertdialog] .button-danger, [role=alertdialog] .button-primary') !== null);
    (main.querySelector('[role=alertdialog] .button-danger, [role=alertdialog] .button-primary') as HTMLButtonElement).click();
    await until(() => main.textContent?.includes(t('manage.done')) === true);
    // The deletion token has no further use: it leaves the address bar and history (ADR-0009).
    expect(location.hash).toBe('');

    setLocale('fr');
    rerender();
    await new Promise((resolve) => setTimeout(resolve, 20));

    expect(main.textContent).toContain(t('manage.done'));
    expect(main.querySelector('.button-danger')).toBeNull();
  });

  it('shows the deletion state and a visible, deletion-specific error when the request fails', async () => {
    mockFetch(() => Promise.reject(new TypeError('offline')));
    await until(() => main.querySelector('.button-danger') !== null);
    (main.querySelector('.button-danger') as HTMLButtonElement).click();
    await until(() => main.querySelector('[role=alertdialog] .button-danger') !== null);
    (main.querySelector('[role=alertdialog] .button-danger') as HTMLButtonElement).click();
    await until(() => main.querySelector('.error')?.textContent === t('manage.deleteNetwork'));

    expect(main.querySelector('.error')?.getAttribute('role')).toBe('alert');
    expect((main.querySelector('.button-danger') as HTMLButtonElement).disabled).toBe(false);
  });

  it('keeps focus where it is when a language change redraws the page', async () => {
    await until(() => main.querySelector('.button-danger') !== null);
    const outside = document.createElement('button');
    document.body.append(outside);
    outside.focus();
    setLocale('fr');
    redrawInPlace(rerender);
    await new Promise((resolve) => setTimeout(resolve, 20));

    expect(document.activeElement).toBe(outside);
    expect(main.querySelector('.button-danger')?.textContent).toBe(t('manage.delete'));
    setLocale('en');
  });

  it('keeps Delete disabled when the language changes during the deletion, then reports its outcome (EXG-UX-049)', async () => {
    const answer = deferred<Response>();
    mockFetch(() => answer.promise);
    await until(() => main.querySelector('.button-danger') !== null);
    (main.querySelector('.button-danger') as HTMLButtonElement).click();
    await until(() => main.querySelector('[role=alertdialog] .button-danger') !== null);
    (main.querySelector('[role=alertdialog] .button-danger') as HTMLButtonElement).click();
    await until(() => main.querySelector('.status')?.textContent === t('state.deleting'));

    setLocale('fr');
    redrawInPlace(rerender);
    const remove = main.querySelector('.action-bar .button-danger') as HTMLButtonElement;
    expect(remove.disabled).toBe(true);
    expect(main.querySelector('.status')?.textContent).toBe(t('state.deleting'));

    answer.reject(new TypeError('offline'));
    await until(() => main.querySelector('.error')?.textContent === t('manage.deleteNetwork'));
    expect((main.querySelector('.action-bar .button-danger') as HTMLButtonElement).disabled).toBe(false);
    expect(main.querySelector('.status')?.textContent).toBe('');
    setLocale('en');
  });

  it('reloads when the fragment is corrected in the same tab', async () => {
    const reload = vi.fn();
    const original = navigation.reload;
    navigation.reload = reload;
    try {
      await until(() => main.querySelector('.button-danger') !== null);
      window.dispatchEvent(new HashChangeEvent('hashchange'));
      expect(reload).toHaveBeenCalled();
    } finally {
      navigation.reload = original;
    }
  });
});
