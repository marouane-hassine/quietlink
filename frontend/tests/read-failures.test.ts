// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (read-once safety §6.3.1, explicit crypto states §8.3, i18n §6.6.1): EXG-READ-029,
// EXG-CRYPTO-057, EXG-I18N-001.

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { encode } from '../src/crypto/base64url';
import { concat, utf8 } from '../src/crypto/bytes';
import { serialize } from '../src/crypto/envelope';
import { sha256 } from '../src/crypto/primitives';
import { prepare } from '../src/crypto/protocol';
import type { PublicConfig } from '../src/config';
import { dummyChallenge, idForCreation, mockFetch, response, setSecureContext, until, type RecordedRequest } from './support/fake-api';

const fakeArgon2 = async (passphrase: string, salt: Uint8Array) => sha256(concat(utf8.encode(passphrase), salt));
const argon2 = vi.hoisted(() => ({ unavailable: false }));
vi.mock('../src/crypto/argon2-client', () => {
  class Argon2UnavailableError extends Error {}
  return {
    Argon2UnavailableError,
    argon2Supported: () => true,
    preloadArgon2: () => undefined,
    deriveInWorker: async (passphrase: string, salt: Uint8Array) => {
      if (argon2.unavailable) throw new Argon2UnavailableError('worker');
      return fakeArgon2(passphrase, salt);
    },
  };
});
// The rendering chunk cannot be fetched (for example a stale page after a redeploy).
vi.mock('../src/render/content-view', () => {
  throw new Error('chunk unavailable');
});

const { mountRead } = await import('../src/pages/read');
const { setLocale, t } = await import('../src/i18n');

const config: PublicConfig = {
  page: 'read', enabledLocales: ['en', 'fr'], defaultExpiration: '1d', expirations: ['1d'],
  allowReadOnce: true, allowPassphrase: true, maxEnvelopeBytes: 1048576, kdf: { m: 19456, t: 2 },
  enableQrCode: false, darkMode: 'auto', templates: [],
};

async function makePaste(readOnce: boolean, passphrase: string | null) {
  const prepared = await prepare({ envelope: serialize({ format: 'plain', language: null, template: null, text: 'dummy text' }), expiration: '1d', readOnce, passphrase, kdf: config.kdf, derive: fakeArgon2 });
  const id = await idForCreation(prepared.json);
  history.replaceState(null, '', `/p/${id}#${encode(prepared.urlKey)}`);
  const meta = { aad: prepared.body.aad, expires_at: '2026-10-04T12:00:00Z', server_time: new Date().toISOString(), read_once: readOnce };
  return mockFetch((request: RecordedRequest) => {
    const action = request.path.split('/').pop();
    if (action === 'challenge') return response(200, { challenge: dummyChallenge() });
    if (action === 'status') return response(200, meta);
    if (action === 'open') return response(200, { ...meta, nonce: prepared.body.nonce, ciphertext: prepared.body.ciphertext, consume_challenge: dummyChallenge() });
    if (action === 'consume') return response(200, { consumed: true });
    return response(404);
  });
}

describe('reading page failures', () => {
  let main: HTMLElement;
  let rerender: () => void;
  const mount = () => {
    document.body.innerHTML = '<main id="main"></main>';
    main = document.getElementById('main') as HTMLElement;
    rerender = mountRead(main, config);
  };
  const revealButton = () => main.querySelector('.action-bar .button-primary') as HTMLButtonElement | null;
  const paths = (requests: RecordedRequest[]) => requests.map((request) => request.path.split('/').pop());

  beforeEach(() => {
    setLocale('en');
    setSecureContext(true);
    argon2.unavailable = false;
    sessionStorage.clear();
  });

  it('never consumes a read-once paste it cannot display', async () => {
    const { requests } = await makePaste(true, null);
    mount();
    await until(() => revealButton() !== null);
    revealButton()!.click();
    await until(() => main.textContent?.includes(t('error.networkRead')) === true);

    expect(paths(requests)).not.toContain('consume');
    expect(paths(requests)).not.toContain('open');
    // A chunk that failed to load is a recoverable network failure (§12 journey C).
    expect([...main.querySelectorAll('button')].some((b) => b.textContent === t('action.retry'))).toBe(true);
  });

  it('reports a failed Argon2id worker explicitly, as retryable, without opening', async () => {
    argon2.unavailable = true;
    const { requests } = await makePaste(true, 'dummy-passphrase');
    mount();
    await until(() => main.querySelector('input.passphrase') !== null);
    const input = main.querySelector('input.passphrase') as HTMLInputElement;
    input.value = 'dummy-passphrase';
    revealButton()!.click();
    // WebAssembly and workers exist: a transient failure, never "browser unsupported".
    await until(() => main.textContent?.includes(t('error.argon2Failed')) === true);

    expect(paths(requests)).not.toContain('open');
    expect([...main.querySelectorAll('button')].some((b) => b.textContent === t('action.retry'))).toBe(true);
  });

  it('translates the reveal screen when the language changes', async () => {
    await makePaste(true, null);
    mount();
    await until(() => revealButton() !== null);
    setLocale('fr');
    rerender();
    await until(() => revealButton()?.textContent === t('read.reveal'));

    expect(main.querySelector('h1')?.textContent).toBe(t('read.revealTitle'));
  });

  it('translates the wrong-passphrase message when the language changes', async () => {
    // Read-once: the passphrase is checked locally, before any request (§6.3.1 step 0).
    await makePaste(true, 'dummy-passphrase');
    mount();
    await until(() => main.querySelector('input.passphrase') !== null);
    (main.querySelector('input.passphrase') as HTMLInputElement).value = 'not-the-passphrase';
    revealButton()!.click();
    await until(() => main.textContent?.includes(t('error.wrongPassphrase')) === true);
    setLocale('fr');
    rerender();

    expect(main.textContent).toContain(t('error.wrongPassphrase'));
    setLocale('en');
  });
});
