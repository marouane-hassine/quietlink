// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (creation and result screens, §5.1): EXG-UX-022, EXG-A11Y-002, EXG-UX-027,
// EXG-UX-028, EXG-UX-033, EXG-UX-054, EXG-UX-056, EXG-UX-060, EXG-UX-070, EXG-LIFE-001,
// EXG-SEC-015, EXG-UX-089, EXG-UX-092, EXG-A11Y-020.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PublicConfig } from '../src/config';

vi.mock('../src/crypto/protocol', () => ({
  prepare: async () => ({ json: '{}', idempotencyKey: 'key-1', urlKey: new Uint8Array(32), deletionToken: new Uint8Array(32), accessPk: new Uint8Array(32) }),
  matchesAccessKey: async () => true,
  matchesDeletionToken: async () => true,
}));
vi.mock('../src/api', async (original) => {
  const actual = await original<typeof import('../src/api')>();
  return {
    ...actual,
    api: { create: async () => ({ data: { id: 'A'.repeat(32), expires_at: null, server_time: new Date().toISOString() }, t0: 0, t1: 1 }) },
  };
});
const clipboard = vi.hoisted(() => ({ readable: false, copied: [] as string[] }));
vi.mock('../src/ui/clipboard', () => ({
  canReadClipboard: () => clipboard.readable,
  copyText: async (text: string) => {
    clipboard.copied.push(text);
    return true;
  },
}));
const announced = vi.hoisted(() => [] as string[]);
vi.mock('../src/ui/announcer', async (original) => {
  const actual = await original<typeof import('../src/ui/announcer')>();
  return {
    ...actual,
    announce: (message: string, urgent?: boolean) => {
      announced.push(message);
      actual.announce(message, urgent);
    },
  };
});

const { mountCreate } = await import('../src/pages/create');
const { setLocale, t } = await import('../src/i18n');
const { formatBytes } = await import('../src/ui/format');
const { byteLength, serialize } = await import('../src/crypto/envelope');

const baseConfig: PublicConfig = {
  page: 'create', enabledLocales: ['en', 'fr'], defaultExpiration: '1d', expirations: ['5m', '1h', '1d', '7d', '30d'],
  allowReadOnce: true, allowPassphrase: true, maxEnvelopeBytes: 1_000_000, kdf: { m: 65536, t: 3 },
  enableQrCode: false, darkMode: 'auto', templates: [],
};

const settle = async () => {
  for (let i = 0; i < 10; i++) await new Promise((resolve) => setTimeout(resolve, 0));
};

let main: HTMLElement;
const mount = (overrides: Partial<PublicConfig> = {}) => {
  document.body.innerHTML = '<main id="main"></main>';
  main = document.getElementById('main') as HTMLElement;
  mountCreate(main, { ...baseConfig, ...overrides });
};
const editor = () => main.querySelector('textarea') as HTMLTextAreaElement;
const type = (text: string) => {
  editor().value = text;
  editor().dispatchEvent(new Event('input'));
};
const button = (label: string) => [...main.querySelectorAll('button')].find((b) => b.textContent === label) as HTMLButtonElement | undefined;
const check = (index: number) => {
  const box = main.querySelectorAll<HTMLInputElement>('.options input[type=checkbox]')[index] as HTMLInputElement;
  box.checked = true;
  box.dispatchEvent(new Event('change'));
};
const summary = () => main.querySelector('.summary span')?.textContent ?? '';
const describedBy = (node: Element | null) => (node?.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean);
const before = (a: Node, b: Node) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
const submit = async () => {
  (main.querySelector('.action-bar .button-primary') as HTMLButtonElement).click();
  await settle();
};

beforeEach(() => {
  setLocale('en');
  Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
  clipboard.readable = false;
  clipboard.copied.length = 0;
  announced.length = 0;
  mount();
});

describe('size line (§5.1, EXG-UX-022, EXG-A11Y-002)', () => {
  it('always shows the current size against the limit, even for a short text', () => {
    const size = main.querySelector('.size') as HTMLElement;
    expect(size.hidden).toBe(false);
    type('dummy');
    expect(size.hidden).toBe(false);
    expect(size.textContent).toContain(formatBytes(1_000_000));
  });

  it('is not a live region: only a crossed threshold is announced, once', () => {
    const size = main.querySelector('.size') as HTMLElement;
    expect(size.hasAttribute('aria-live')).toBe(false);
    type('a');
    type('ab');
    expect(announced).toEqual([]);
    type('x'.repeat(850_000));
    expect(announced).toHaveLength(1);
    expect(announced[0]).toBe(size.textContent);
    type('x'.repeat(850_001));
    expect(announced).toHaveLength(1);
    type('x'.repeat(1_100_000));
    expect(announced).toHaveLength(2);
    expect(announced[1]).toMatch(/^The text is too large/);
  });
});

describe('settings summary line (§5.1, EXG-UX-027)', () => {
  it('lists negative states and the current size, and follows every change', () => {
    type('dummy text');
    const size = formatBytes(byteLength(serialize({ format: 'plain', language: null, template: null, text: 'dummy text' })));
    expect(summary()).toBe(t('summary.line', { settings: [t('summary.expires', { duration: t('expiration.1d') }), t('summary.multipleReads'), t('summary.noPassphrase'), size].join(' · ') }));
    check(0);
    check(1);
    expect(summary()).toContain(t('summary.readOnce'));
    expect(summary()).toContain(t('summary.passphrase'));
    expect(summary()).not.toContain(t('summary.noPassphrase'));
    // The edit button is kept.
    expect(main.querySelector('.summary .link-button')?.textContent).toBe(t('summary.change'));
  });
});

describe('what is sent to the server (§5.1, EXG-UX-033)', () => {
  it('reflects the encrypted size, expiration, read-once flag and passphrase presence, live, never the text', () => {
    const sent = () => main.querySelector('details.sent')?.textContent ?? '';
    type('DUMMY-SECRET-TEXT');
    expect(sent()).toContain(t('sent.expiration', { expiration: t('expiration.1d') }));
    expect(sent()).toContain(t('sent.readOnceNo'));
    expect(sent()).toContain(t('sent.passphraseNo'));
    expect(main.querySelector('details.sent .sent-size')?.textContent).toMatch(/^Encrypted text: about /);
    const sizeBefore = main.querySelector('details.sent .sent-size')?.textContent;
    type('DUMMY-SECRET-TEXT plus more dummy words to grow it');
    expect(main.querySelector('details.sent .sent-size')?.textContent).not.toBe(sizeBefore);
    check(0);
    check(1);
    expect(sent()).toContain(t('sent.readOnceYes'));
    expect(sent()).toContain(t('sent.passphraseYes'));
    expect(sent()).not.toContain('DUMMY-SECRET-TEXT');
    // The reminder that the text and key stay in the browser is kept.
    expect(sent()).toContain(t('sent.body'));
  });
});

describe('Share preset (§5.1, EXG-UX-028)', () => {
  const share = () => [...main.querySelectorAll('.presets .chip')].find((b) => b.textContent?.startsWith('Share')) as HTMLButtonElement;

  it('applies 7 days and says so', () => {
    expect(share().textContent).toBe(t('preset.share', { duration: t('expiration.7d') }));
    share().click();
    expect((main.querySelectorAll('.options select')[0] as HTMLSelectElement).value).toBe('7d');
  });

  it('falls back to the longest accepted duration up to 7 days', () => {
    mount({ expirations: ['5m', '1h', '1d', '30d'] });
    expect(share().textContent).toBe(t('preset.share', { duration: t('expiration.1d') }));
    share().click();
    expect((main.querySelectorAll('.options select')[0] as HTMLSelectElement).value).toBe('1d');
  });
});

describe('no layout shift while typing (EXG-UX-092)', () => {
  it('keeps the Paste button, the hint, the gauge and the reason line in the layout', () => {
    clipboard.readable = true;
    mount();
    const paste = button(t('editor.paste')) as HTMLButtonElement;
    const gauge = main.querySelector('.gauge') as HTMLElement;
    type('dummy');
    expect(paste.hidden).toBe(false);
    expect(paste.classList.contains('is-invisible')).toBe(true);
    expect(gauge.hidden).toBe(false);
    expect(gauge.dataset.level).toBe('idle');
    type('');
    expect(paste.classList.contains('is-invisible')).toBe(false);
    const css = readFileSync(join(process.cwd(), 'frontend/src/styles/app.css'), 'utf8');
    expect(css).toMatch(/\.is-invisible\s*\{[^}]*visibility:\s*hidden/);
    expect(css).toMatch(/\.gauge\[data-level="idle"\]\s*\{[^}]*visibility:\s*hidden/);
    expect(css).toMatch(/\.disabled-reason\s*\{[^}]*min-block-size/);
    expect(css).toMatch(/\.editor-hint\s*\{[^}]*min-block-size/);
  });
});

describe('disabled Create tied to its field (EXG-A11Y-020)', () => {
  it('describes the editor with the reason when it is empty or too large, the passphrase fields otherwise', () => {
    const reasonId = (main.querySelector('.disabled-reason') as HTMLElement).id;
    expect(describedBy(editor())).toContain(reasonId);
    type('dummy');
    expect(describedBy(editor())).not.toContain(reasonId);
    check(1);
    const fields = [...main.querySelectorAll<HTMLInputElement>('.passphrase-panel input.passphrase')];
    expect(fields).toHaveLength(2);
    for (const field of fields) expect(describedBy(field)).toContain(reasonId);
    expect(describedBy(editor())).not.toContain(reasonId);
    // The hint stays attached to the fields.
    expect(describedBy(editor()).length).toBeGreaterThan(0);
  });
});

describe('result screen (§5.1)', () => {
  const toResult = async (readOnce: boolean) => {
    type('dummy text');
    if (readOnce) check(0);
    await submit();
  };

  it('puts the read-once warning before the share link and its Copy action (EXG-LIFE-001, EXG-UX-070)', async () => {
    await toResult(true);
    const warning = [...main.querySelectorAll('.warning')].find((n) => n.textContent === t('result.readOnceWarning')) as HTMLElement;
    const link = main.querySelector('.link-field') as HTMLInputElement;
    const copy = button(t('action.copy')) as HTMLButtonElement;
    expect(before(warning, link)).toBe(true);
    expect(before(warning, copy)).toBe(true);
  });

  it('makes Copy the primary action of the bottom bar and New text the secondary one (EXG-UX-089, EXG-UX-060)', async () => {
    await toResult(false);
    const bar = main.querySelector('.action-bar') as HTMLElement;
    const primary = bar.querySelector('.button-primary') as HTMLButtonElement;
    expect(primary.textContent).toBe(t('action.copy'));
    expect(bar.querySelector('.button-secondary')?.textContent).toBe(t('action.new'));
    expect(main.querySelectorAll('.button-primary')).toHaveLength(1);
    primary.click();
    await settle();
    expect(clipboard.copied).toEqual([(main.querySelector('.link-field') as HTMLInputElement).value]);
  });

  it('asks for an explicit confirmation, with the irreversibility warning, before revealing the management link (EXG-SEC-015)', async () => {
    await toResult(false);
    button(t('manage.reveal'))?.click();
    await settle();
    const dialog = main.querySelector('[role=alertdialog]') as HTMLElement;
    expect(dialog.textContent).toContain(t('manage.revealWarning'));
    expect(main.querySelector('.danger-body .link-field')).toBeNull();
    (dialog.querySelector('.button-secondary') as HTMLButtonElement).click();
    await settle();
    expect(main.querySelector('.danger-body .link-field')).toBeNull();

    button(t('manage.reveal'))?.click();
    await settle();
    (main.querySelector('[role=alertdialog] .button-danger') as HTMLButtonElement).click();
    await settle();
    expect((main.querySelector('.danger-body .link-field') as HTMLInputElement).value).toContain('/manage/');
    expect(button(t('manage.reveal'))?.getAttribute('aria-expanded')).toBe('true');
  });
});

describe('messages (§5.1)', () => {
  const en = JSON.parse(readFileSync(join(process.cwd(), 'translations/en.json'), 'utf8')) as Record<string, string>;

  it('says that the content was not sent after a network failure (EXG-UX-056)', () => {
    expect(en['error.network']).toMatch(/not sent/);
  });

  it('promises no fixed Argon2id duration without calibration (EXG-UX-054)', () => {
    expect(en['state.deriving']).not.toMatch(/usually/);
    expect(en['state.deriving']).toMatch(/several seconds on slow devices/);
  });
});
