// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (creation flow consistency, §5.1, §10 idempotent retries): EXG-UX-050,
// EXG-API-025, EXG-UX-051.

import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PublicConfig } from '../src/config';
import { until } from './support/fake-api';

const prepared: { envelope: string; readOnce: boolean }[] = [];
const createCalls: number[] = [];
let failNext = 0;
let prepareError: Error | null = null;

vi.mock('../src/crypto/protocol', () => ({
  prepare: async (options: { envelope: string; readOnce: boolean }) => {
    if (prepareError) throw prepareError;
    prepared.push({ envelope: options.envelope, readOnce: options.readOnce });
    return { json: '{}', idempotencyKey: `key-${prepared.length}`, urlKey: new Uint8Array(32), deletionToken: new Uint8Array(32), accessPk: new Uint8Array(32) };
  },
  matchesAccessKey: async () => true,
  matchesDeletionToken: async () => true,
}));
vi.mock('../src/api', async (original) => {
  const actual = await original<typeof import('../src/api')>();
  return {
    ...actual,
    api: {
      create: async () => {
        createCalls.push(prepared.length);
        if (failNext > 0) {
          failNext--;
          throw new actual.ApiError('network');
        }
        return { data: { id: 'A'.repeat(32), expires_at: null, server_time: new Date().toISOString() }, t0: 0, t1: 1 };
      },
    },
  };
});

const { mountCreate } = await import('../src/pages/create');
const { Argon2UnavailableError } = await import('../src/crypto/argon2-client');
const { setLocale, t } = await import('../src/i18n');

const config: PublicConfig = {
  page: 'create', enabledLocales: ['en', 'fr'], defaultExpiration: '1d', expirations: ['5m', '1h', '1d'],
  allowReadOnce: true, allowPassphrase: true, maxEnvelopeBytes: 1048576, kdf: { m: 65536, t: 3 },
  enableQrCode: false, darkMode: 'auto', templates: [],
};

const settle = async () => {
  for (let i = 0; i < 10; i++) await new Promise((resolve) => setTimeout(resolve, 0));
};

describe('creation flow', () => {
  let main: HTMLElement;
  let rerender: () => void;
  const editor = () => main.querySelector('textarea') as HTMLTextAreaElement;
  const type = (text: string) => {
    editor().value = text;
    editor().dispatchEvent(new Event('input'));
  };
  const button = (label: string) => [...main.querySelectorAll('button')].find((b) => b.textContent === label) as HTMLButtonElement;
  const lastText = () => (JSON.parse(prepared.at(-1)!.envelope) as { text: string }).text;

  beforeEach(() => {
    prepared.length = 0;
    createCalls.length = 0;
    failNext = 0;
    prepareError = null;
    setLocale('en');
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    document.body.innerHTML = '<main id="main"></main>';
    main = document.getElementById('main') as HTMLElement;
    rerender = mountCreate(main, config);
  });

  it('encrypts the current text with Ctrl+Enter after a new text and a language change', async () => {
    type('first dummy text');
    (main.querySelector('.action-bar .button-primary') as HTMLButtonElement).click();
    await settle();
    button(t('action.new')).click();
    await settle();
    (main.querySelector('[role=alertdialog] .button-primary') as HTMLButtonElement).click();
    await settle();
    rerender();
    type('second dummy text');
    editor().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true }));
    await settle();

    expect(prepared).toHaveLength(2);
    expect(lastText()).toBe('second dummy text');
  });

  it('prepares a new paste when the text or options change after a failed attempt', async () => {
    failNext = 1;
    type('dummy text');
    (main.querySelector('.action-bar .button-primary') as HTMLButtonElement).click();
    await settle();
    expect(button(t('action.retry'))).toBeDefined();

    type('dummy text, edited');
    expect(button(t('action.retry'))).toBeUndefined();
    (main.querySelector('.action-bar .button-primary') as HTMLButtonElement).click();
    await settle();

    expect(prepared).toHaveLength(2);
    expect(lastText()).toBe('dummy text, edited');
  });

  it('labels the result with the settings of the paste actually sent', async () => {
    failNext = 1;
    type('dummy text');
    (main.querySelector('.action-bar .button-primary') as HTMLButtonElement).click();
    await settle();
    button(t('action.retry')).click();
    await settle();

    expect(prepared).toHaveLength(1);
    expect(main.querySelector('.mode')?.textContent).toBe(t('result.mode.normal'));
  });

  it('reports an Argon2id worker failure explicitly, without sending anything', async () => {
    prepareError = new Argon2UnavailableError('worker');
    type('dummy text');
    (main.querySelector('.action-bar .button-primary') as HTMLButtonElement).click();
    await settle();

    expect(main.querySelector('.error-box')?.textContent).toContain(t('error.argon2'));
    expect(createCalls).toHaveLength(0);
  });

  it('adds the share-link QR code once, however fast the button is toggled', async () => {
    document.body.innerHTML = '<main id="main"></main>';
    main = document.getElementById('main') as HTMLElement;
    mountCreate(main, { ...config, enableQrCode: true });
    type('dummy text');
    (main.querySelector('.action-bar .button-primary') as HTMLButtonElement).click();
    await settle();
    const qr = button(t('result.qr'));
    qr.click();
    qr.click();
    qr.click();
    await until(() => main.querySelector('.qr-box svg') !== null);
    await settle();

    expect(main.querySelectorAll('.qr-box svg')).toHaveLength(1);
  });

  it('keeps the template form, open options and masking across a language change', async () => {
    document.body.innerHTML = '<main id="main"></main>';
    main = document.getElementById('main') as HTMLElement;
    rerender = mountCreate(main, { ...config, templates: ['credentials'] });
    const template = [...main.querySelectorAll('select')].find((select) => select.querySelector('option[value="credentials"]')) as HTMLSelectElement;
    template.value = 'credentials';
    template.dispatchEvent(new Event('change'));
    await settle();
    (main.querySelector('details.options') as HTMLDetailsElement).open = true;
    main.querySelector('details.options')?.dispatchEvent(new Event('toggle'));

    setLocale('fr');
    rerender();

    expect((main.querySelector('textarea.editor')?.closest('.field') as HTMLElement).hidden).toBe(true);
    // Field labels belong to the template text inserted in English: content is not translated.
    const password = [...main.querySelectorAll('.template-form input')].find((input) => main.querySelector(`label[for="${input.id}"]`)?.textContent === 'Password') as HTMLInputElement;
    expect(password.type === 'password' || password.classList.contains('is-masked')).toBe(true);
    expect((main.querySelector('details.options') as HTMLDetailsElement).open).toBe(true);
    expect(main.querySelector('.secret-suggestion')).not.toBeNull();
    expect(main.querySelector('.inline-notice .link-button')).not.toBeNull();
    setLocale('en');
  });

  it('translates the result screen on a language change', async () => {
    type('dummy text');
    (main.querySelector('.action-bar .button-primary') as HTMLButtonElement).click();
    await settle();
    expect(main.querySelector('h1')?.textContent).toBe(t('result.title'));

    setLocale('fr');
    rerender();

    expect(main.querySelector('h1')?.textContent).toBe(t('result.title'));
    expect(main.querySelector('h1')?.textContent).not.toBe('Encrypted and ready to share');
    expect((main.querySelector('.link-field') as HTMLInputElement).value).toContain('/p/');
    setLocale('en');
  });

  it('returns focus to the panel summary when Escape closes the options', () => {
    const options = main.querySelector('details.options') as HTMLDetailsElement;
    options.open = true;
    (options.querySelector('select') as HTMLSelectElement).focus();
    editor().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

    expect(options.open).toBe(false);
    expect(document.activeElement).toBe(options.querySelector('summary'));
  });

  it('builds the settings line from one catalogue entry, with the punctuation of the language', () => {
    expect(main.querySelector('.summary span')?.textContent).toBe(t('summary.line', { settings: t('summary.expires', { duration: t('expiration.1d') }) }));
    expect(main.querySelector('.summary span')?.textContent).toMatch(/^Settings: /);
  });

  it('copies nothing and confirms nothing while the passphrase is empty', async () => {
    const writeText = vi.fn(async () => undefined);
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } });
    (main.querySelector('details.options') as HTMLDetailsElement).open = true;
    const use = [...main.querySelectorAll('.options input[type=checkbox]')].at(-1) as HTMLInputElement;
    use.checked = true;
    use.dispatchEvent(new Event('change'));
    button(t('passphrase.copy')).click();
    await settle();

    expect(writeText).not.toHaveBeenCalled();
    vi.unstubAllGlobals();
  });
});
