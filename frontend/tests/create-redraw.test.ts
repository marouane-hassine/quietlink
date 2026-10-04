// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Creation page state kept across a language change and while a creation is in flight
// (§5.1, §6.6.1, §10 idempotent retries). Requirements: EXG-UX-049, EXG-UX-050, EXG-UX-052,
// EXG-UX-056, EXG-UX-058, EXG-UX-062, EXG-UX-038, EXG-UX-040, EXG-UX-042, EXG-UX-073,
// EXG-I18N-005, EXG-SEC-007.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PublicConfig } from '../src/config';
import { deferred } from './support/fake-api';

interface FakePrepared {
  json: string;
  idempotencyKey: string;
  urlKey: Uint8Array;
  deletionToken: Uint8Array;
  accessPk: Uint8Array;
}
const prepared: FakePrepared[] = [];
const createKeys: string[] = [];
let failNext = 0;
let failKind: 'network' | 'rate' = 'network';
let gate: Promise<void> | null = null;

vi.mock('../src/crypto/protocol', () => ({
  prepare: async (options: { readOnce: boolean }) => {
    const index = prepared.length + 1;
    const paste: FakePrepared = {
      json: JSON.stringify({ readOnce: options.readOnce }),
      idempotencyKey: `key-${index}`,
      urlKey: new Uint8Array(32).fill(7),
      deletionToken: new Uint8Array(32).fill(9),
      accessPk: new Uint8Array(32),
    };
    prepared.push(paste);
    if (gate) await gate;
    return paste;
  },
  matchesAccessKey: async () => true,
  matchesDeletionToken: async () => true,
}));
vi.mock('../src/api', async (original) => {
  const actual = await original<typeof import('../src/api')>();
  return {
    ...actual,
    api: {
      create: async (_json: string, key: string) => {
        createKeys.push(key);
        if (failNext > 0) {
          failNext--;
          throw new actual.ApiError(failKind, failKind === 'rate' ? 30 : null);
        }
        return { data: { id: 'A'.repeat(32), expires_at: null, server_time: new Date().toISOString() }, t0: 0, t1: 1 };
      },
    },
  };
});

const { mountCreate } = await import('../src/pages/create');
const { setLocale, t } = await import('../src/i18n');
const { redrawInPlace } = await import('../src/ui/dom');

const config: PublicConfig = {
  page: 'create', enabledLocales: ['en', 'fr'], defaultExpiration: '1d', expirations: ['5m', '1h', '1d'],
  allowReadOnce: true, allowPassphrase: true, maxEnvelopeBytes: 1048576, kdf: { m: 65536, t: 3 },
  enableQrCode: false, darkMode: 'auto', templates: [],
};

const settle = async () => {
  for (let i = 0; i < 10; i++) await new Promise((resolve) => setTimeout(resolve, 0));
};

describe('creation page state', () => {
  let main: HTMLElement;
  let rerender: () => void;
  const mount = (overrides: Partial<PublicConfig> = {}) => {
    document.body.innerHTML = '<main id="main"></main>';
    main = document.getElementById('main') as HTMLElement;
    rerender = mountCreate(main, { ...config, ...overrides });
  };
  const editor = () => main.querySelector('textarea.editor') as HTMLTextAreaElement;
  const type = (text: string) => {
    editor().value = text;
    editor().dispatchEvent(new Event('input'));
  };
  const submit = () => (main.querySelector('.action-bar .button-primary') as HTMLButtonElement).click();
  const button = (label: string) => [...main.querySelectorAll('button')].find((b) => b.textContent === label) as HTMLButtonElement | undefined;
  const readOnceBox = () => main.querySelector('.options input[type=checkbox]') as HTMLInputElement;
  const changeLanguage = (code: string) => {
    setLocale(code);
    redrawInPlace(rerender);
  };

  beforeEach(() => {
    prepared.length = 0;
    createKeys.length = 0;
    failNext = 0;
    failKind = 'network';
    gate = null;
    setLocale('en');
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    mount();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    setLocale('en');
  });

  it('labels the result with the settings snapshotted before encryption, whatever changes meanwhile', async () => {
    const encryption = deferred<void>();
    gate = encryption.promise;
    type('dummy text');
    submit();
    await settle();
    // A change racing the encryption (event fired programmatically on the inert control).
    readOnceBox().checked = true;
    readOnceBox().dispatchEvent(new Event('change'));
    encryption.resolve();
    await settle();

    expect(JSON.parse(prepared[0]?.json ?? '{}')).toEqual({ readOnce: false });
    expect(main.querySelector('.mode')?.textContent).toBe(t('result.mode.normal'));
  });

  it('makes the editor, template and option controls inert while a creation is busy', async () => {
    mount({ templates: ['credentials'] });
    const encryption = deferred<void>();
    gate = encryption.promise;
    failNext = 1;
    type('dummy text');
    submit();
    await settle();

    expect(editor().readOnly).toBe(true);
    expect(readOnceBox().disabled).toBe(true);
    for (const select of main.querySelectorAll('select')) expect(select.disabled).toBe(true);
    expect(button(t('preset.secret'))?.disabled).toBe(true);

    encryption.resolve();
    await settle();
    expect(editor().readOnly).toBe(false);
    expect(readOnceBox().disabled).toBe(false);
    for (const select of main.querySelectorAll('select')) expect(select.disabled).toBe(false);
    expect(button(t('action.retry'))).toBeDefined();
  });

  it('retries with the same prepared request and Idempotency-Key only while nothing changed', async () => {
    failNext = 2;
    type('dummy text');
    submit();
    await settle();
    button(t('action.retry'))?.click();
    await settle();
    expect(prepared).toHaveLength(1);
    expect(createKeys).toEqual(['key-1', 'key-1']);

    // Edited and typed back: the retry offered for the old paste is gone, a new paste is made.
    type('dummy text, edited');
    type('dummy text');
    expect(button(t('action.retry'))).toBeUndefined();
    submit();
    await settle();
    expect(prepared).toHaveLength(2);
    expect(createKeys.at(-1)).toBe('key-2');
  });

  it('wipes the keys of an abandoned prepared paste (edit or cancel)', async () => {
    failNext = 2;
    type('dummy text');
    submit();
    await settle();
    const first = prepared[0] as FakePrepared;
    type('dummy text, edited');
    expect(first.urlKey.every((b) => b === 0)).toBe(true);
    expect(first.deletionToken.every((b) => b === 0)).toBe(true);

    submit();
    await settle();
    const second = prepared[1] as FakePrepared;
    button(t('action.cancel'))?.click();
    expect(second.urlKey.every((b) => b === 0)).toBe(true);
    expect(second.deletionToken.every((b) => b === 0)).toBe(true);
  });

  it('keeps the error and its Retry box across a language change', async () => {
    failNext = 1;
    type('dummy text');
    submit();
    await settle();
    changeLanguage('fr');

    const box = main.querySelector('.error-box') as HTMLElement;
    expect(box.hidden).toBe(false);
    expect(box.textContent).toContain(t('error.network'));
    expect(button(t('action.retry'))).toBeDefined();
    button(t('action.retry'))?.click();
    await settle();
    expect(createKeys).toEqual(['key-1', 'key-1']);
    expect(main.querySelector('h1')?.textContent).toBe(t('result.title'));
  });

  it('keeps counting down Retry-After from the remaining time across a language change', async () => {
    let now = 1_000_000;
    vi.spyOn(Date, 'now').mockImplementation(() => now);
    failNext = 1;
    failKind = 'rate';
    type('dummy text');
    submit();
    await settle();
    now += 10_000;
    changeLanguage('fr');

    const retry = main.querySelector('.error-box .button-secondary') as HTMLButtonElement;
    expect(retry.disabled).toBe(true);
    expect(retry.textContent).toBe(t('action.retryIn', { seconds: 20 }));
  });

  it('shows the passphrase strength and mismatch again after a language change', () => {
    (main.querySelector('details.options') as HTMLDetailsElement).open = true;
    const use = [...main.querySelectorAll('.options input[type=checkbox]')].at(-1) as HTMLInputElement;
    use.checked = true;
    use.dispatchEvent(new Event('change'));
    const [pass, confirm] = [...main.querySelectorAll('input.passphrase')] as HTMLInputElement[];
    (pass as HTMLInputElement).value = 'dummy';
    pass?.dispatchEvent(new Event('input'));
    (confirm as HTMLInputElement).value = 'dummx';
    confirm?.dispatchEvent(new Event('input'));
    changeLanguage('fr');

    expect(main.querySelector('.passphrase-panel .hint[aria-live]')?.textContent).toBe(t('passphrase.strength.weak'));
    expect(main.querySelector('.passphrase-panel .field-error')?.textContent).toBe(t('passphrase.mismatch'));
  });

  it('keeps revealed template fields revealed across a language change, masked in a new form', async () => {
    mount({ templates: ['credentials'] });
    const template = [...main.querySelectorAll('select')].find((select) => select.querySelector('option[value="credentials"]')) as HTMLSelectElement;
    template.value = 'credentials';
    template.dispatchEvent(new Event('change'));
    await settle();
    expect(main.querySelector('.template-form input[type=password]')).not.toBeNull();
    (main.querySelector('.template-form .button-tertiary') as HTMLButtonElement).click();
    changeLanguage('fr');

    expect(main.querySelector('.template-form input[type=password]')).toBeNull();
    expect(main.querySelector('.template-form .button-tertiary')?.textContent).toBe(t('editor.sensitive.hide'));
    // Switching to text and back builds a new form: masked again.
    button(t('editor.mode.text'))?.click();
    button(t('editor.mode.form'))?.click();
    expect(main.querySelector('.template-form input[type=password]')).not.toBeNull();
  });

  it('keeps the QR code and the revealed management link open across a language change', async () => {
    mount({ enableQrCode: true });
    type('dummy text');
    submit();
    await settle();
    button(t('result.qr'))?.click();
    button(t('manage.reveal'))?.click();
    await settle();
    changeLanguage('fr');
    await settle();

    expect((main.querySelector('.qr-box') as HTMLElement).hidden).toBe(false);
    expect(main.querySelectorAll('.qr-box svg')).toHaveLength(1);
    expect(button(t('result.qrHide'))?.getAttribute('aria-expanded')).toBe('true');
    expect((main.querySelector('.danger-body') as HTMLElement).hidden).toBe(false);
    expect((main.querySelector('.danger-body .link-field') as HTMLInputElement).value).toContain('/manage/');
    expect(button(t('manage.reveal'))?.getAttribute('aria-expanded')).toBe('true');
  });
});
