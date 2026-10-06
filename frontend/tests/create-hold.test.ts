// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Creation page: Retry-After hold kept whatever the user does, passphrase generation racing a
// submission, settings summary and passphrase confirmation (§5.1, §10, §12 journey C).
// Requirements: EXG-UX-120, EXG-UX-050, EXG-UX-052, EXG-UX-080, EXG-I18N-005.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PublicConfig } from '../src/config';
import { deferred } from './support/fake-api';

const prepared: string[] = [];
const createKeys: string[] = [];
let failNext = 0;
let failKind: 'network' | 'rate' = 'network';
let gate: Promise<void> | null = null;
let wordlistImpl: () => Promise<string[]> = async () => [];

vi.mock('../src/crypto/protocol', () => ({
  prepare: async (options: { passphrase: string | null }) => {
    prepared.push(options.passphrase ?? '');
    const paste = { json: '{}', idempotencyKey: `key-${prepared.length}`, urlKey: new Uint8Array(32).fill(7), deletionToken: new Uint8Array(32).fill(9), accessPk: new Uint8Array(32) };
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
vi.mock('../src/ui/passphrase', async (original) => {
  const actual = await original<typeof import('../src/ui/passphrase')>();
  return { ...actual, wordlist: () => wordlistImpl() };
});

const { mountCreate } = await import('../src/pages/create');
const { setLocale, t } = await import('../src/i18n');

const config: PublicConfig = {
  page: 'create', enabledLocales: ['en', 'fr'], defaultExpiration: '1d', expirations: ['5m', '1h', '1d', 'never'],
  allowReadOnce: true, allowPassphrase: true, maxEnvelopeBytes: 1048576, kdf: { m: 65536, t: 3 },
  enableQrCode: false, darkMode: 'auto', templates: [],
};

const settle = async () => {
  for (let i = 0; i < 10; i++) await new Promise((resolve) => setTimeout(resolve, 0));
};
const WORDS = Array.from({ length: 2048 }, (_, i) => `word${i}`);

describe('creation page holds and races', () => {
  let main: HTMLElement;
  let rerender: () => void;
  const editor = () => main.querySelector('textarea.editor') as HTMLTextAreaElement;
  const type = (text: string) => {
    editor().value = text;
    editor().dispatchEvent(new Event('input'));
  };
  const createButton = () => main.querySelector('.action-bar .button-primary') as HTMLButtonElement;
  const submit = () => createButton().click();
  const reason = () => main.querySelector('.disabled-reason')?.textContent ?? '';
  const button = (label: string) => [...main.querySelectorAll('button')].find((b) => b.textContent === label) as HTMLButtonElement | undefined;
  const enablePassphrase = () => {
    const use = [...main.querySelectorAll<HTMLInputElement>('.options input[type=checkbox]')].at(-1) as HTMLInputElement;
    use.checked = true;
    use.dispatchEvent(new Event('change'));
  };
  const passphraseInputs = () => [...main.querySelectorAll<HTMLInputElement>('input.passphrase')];
  const typePassphrase = (passphrase: string, confirmation: string) => {
    const [input, confirm] = passphraseInputs() as [HTMLInputElement, HTMLInputElement];
    input.value = passphrase;
    input.dispatchEvent(new Event('input'));
    confirm.value = confirmation;
    confirm.dispatchEvent(new Event('input'));
  };

  beforeEach(() => {
    prepared.length = 0;
    createKeys.length = 0;
    failNext = 0;
    failKind = 'network';
    gate = null;
    wordlistImpl = async () => WORDS;
    setLocale('en');
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    document.body.innerHTML = '<main id="main"></main>';
    main = document.getElementById('main') as HTMLElement;
    rerender = mountCreate(main, config);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    setLocale('en');
  });

  it('keeps the Retry-After hold after an edit and tells how long to wait', async () => {
    let now = 1_000_000;
    vi.spyOn(Date, 'now').mockImplementation(() => now);
    failNext = 1;
    failKind = 'rate';
    type('dummy text');
    submit();
    await settle();

    type('dummy text, edited');
    expect(button(t('action.retry'))).toBeUndefined();
    expect(createButton().disabled).toBe(true);
    expect(reason()).toBe(t('disabled.retryAfter', { seconds: 30 }));
    now += 10_000;
    type('dummy text, edited again');
    expect(reason()).toBe(t('disabled.retryAfter', { seconds: 20 }));
    submit();
    editor().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true }));
    await settle();
    expect(createKeys).toEqual(['key-1']);
  });

  it('keeps the Retry-After hold after Cancel', async () => {
    vi.spyOn(Date, 'now').mockImplementation(() => 1_000_000);
    failNext = 1;
    failKind = 'rate';
    type('dummy text');
    submit();
    await settle();

    button(t('action.cancel'))?.click();
    expect(createButton().disabled).toBe(true);
    expect(reason()).toBe(t('disabled.retryAfter', { seconds: 30 }));
    submit();
    await settle();
    expect(createKeys).toEqual(['key-1']);
  });

  it('enables Create again at the deadline, even without a Retry button', async () => {
    failNext = 1;
    failKind = 'rate';
    type('dummy text');
    submit();
    await settle();
    vi.useFakeTimers();
    type('dummy text, edited');
    expect(createButton().disabled).toBe(true);

    vi.advanceTimersByTime(15_000);
    expect(reason()).toMatch(/\b1[4-6] /);
    expect(createButton().disabled).toBe(true);
    vi.advanceTimersByTime(16_000);
    expect(createButton().disabled).toBe(false);
    expect(reason()).toBe('');
  });

  it('forgets a language change of a successful creation: a later failure does not redraw the form', async () => {
    const encryption = deferred<void>();
    gate = encryption.promise;
    type('dummy text');
    submit();
    await settle();
    rerender();
    encryption.resolve();
    await settle();
    gate = null;
    button(t('action.new'))?.click();
    await settle();
    main.querySelector<HTMLButtonElement>('[role=alertdialog] .button-primary')?.click();
    await settle();

    const before = editor();
    failNext = 1;
    type('second dummy text');
    submit();
    await settle();
    expect(main.querySelector('.error-box')?.hasAttribute('hidden')).toBe(false);
    expect(editor()).toBe(before);
  });

  it('ignores a generated passphrase arriving after the submission started', async () => {
    const list = deferred<string[]>();
    wordlistImpl = () => list.promise;
    enablePassphrase();
    type('dummy text');
    button(t('passphrase.generate'))?.click();
    await settle();
    typePassphrase('dummy typed passphrase', 'dummy typed passphrase');
    const encryption = deferred<void>();
    gate = encryption.promise;
    submit();
    await settle();
    list.resolve(WORDS);
    await settle();

    expect(passphraseInputs()[0]?.value).toBe('dummy typed passphrase');
    encryption.resolve();
    await settle();
    expect(prepared).toEqual(['dummy typed passphrase']);
  });

  it('reports a word list that cannot be loaded with a translated message', async () => {
    wordlistImpl = () => Promise.reject(new TypeError('Failed to fetch dynamically imported module'));
    enablePassphrase();
    button(t('passphrase.generate'))?.click();
    await settle();

    expect(document.getElementById('ql-toast')?.textContent).toBe(t('passphrase.generateFailed'));
    expect(passphraseInputs()[0]?.value).toBe('');
  });

  it('says "never expires" in the settings summary', () => {
    const select = [...main.querySelectorAll('select')].find((s) => s.querySelector('option[value="never"]')) as HTMLSelectElement;
    select.value = 'never';
    select.dispatchEvent(new Event('change'));

    const summary = main.querySelector('.summary')?.textContent ?? '';
    expect(summary).toContain(t('summary.never'));
    expect(summary).not.toContain(t('summary.expires', { duration: t('expiration.never') }));
  });

  it('compares the passphrase and its confirmation in NFC, as the derivation does', () => {
    enablePassphrase();
    type('dummy text');
    typePassphrase('café dummy words', 'café dummy words');

    expect(main.querySelector('.passphrase-panel .field-error')?.textContent).toBe('');
    expect(createButton().disabled).toBe(false);
  });
});
