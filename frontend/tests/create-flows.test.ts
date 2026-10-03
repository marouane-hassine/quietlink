// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (creation flow consistency, §5.1, §10 idempotent retries): EXG-UX-050,
// EXG-API-025, EXG-UX-051.

import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PublicConfig } from '../src/config';

const prepared: { envelope: string; readOnce: boolean }[] = [];
const createCalls: number[] = [];
let failNext = 0;

vi.mock('../src/crypto/protocol', () => ({
  prepare: async (options: { envelope: string; readOnce: boolean }) => {
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
});
