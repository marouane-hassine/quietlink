// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (creation page §5.1, §8.3, §8.5, §13): EXG-CRYPTO-001, EXG-SEC-005, EXG-SEC-006,
// EXG-UX-018, EXG-TEST-030, EXG-SEC-009, EXG-SEC-013, EXG-UX-047, EXG-UX-048, EXG-UX-049,
// EXG-SEC-014, EXG-SEC-038, EXG-CRYPTO-057, EXG-CRYPTO-058, EXG-TEST-094, EXG-CRYPTO-079, EXG-URL-012,
// EXG-UX-081, EXG-UX-083.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mountCreate } from '../src/pages/create';
import { setLocale, t } from '../src/i18n';
import { parse } from '../src/crypto/aad';
import { decode } from '../src/crypto/base64url';
import { decrypt } from '../src/crypto/protocol';
import { applyTheme } from '../src/ui/chrome';
import { formatRelative } from '../src/ui/format';
import type { PublicConfig } from '../src/config';
import { creationResponse, deferred, flush, mockFetch, renderedText, response, setSecureContext, until } from './support/fake-api';

const argon2 = vi.hoisted(() => ({
  supported: true,
  derive: null as null | ((passphrase: string, salt: Uint8Array, m: number, t: number) => Promise<Uint8Array>),
  calls: 0,
}));
vi.mock('../src/crypto/argon2-client', () => ({
  Argon2UnavailableError: class extends Error {},
  argon2Supported: () => argon2.supported,
  preloadArgon2: () => undefined,
  deriveInWorker: (passphrase: string, salt: Uint8Array, m: number, tt: number) => {
    argon2.calls += 1;
    return argon2.derive ? argon2.derive(passphrase, salt, m, tt) : Promise.resolve(new Uint8Array(32).fill(7));
  },
}));

// Records every live-region announcement and toast, in addition to the real behaviour.
const announced = vi.hoisted(() => [] as string[]);
vi.mock('../src/ui/announcer', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../src/ui/announcer')>();
  return {
    ...actual,
    announce: (message: string, urgent?: boolean) => {
      announced.push(message);
      actual.announce(message, urgent);
    },
    toast: (message: string) => {
      announced.push(message);
      actual.toast(message);
    },
  };
});

const config: PublicConfig = {
  page: 'create', enabledLocales: ['en', 'fr'], defaultExpiration: '1d', expirations: ['5m', '1h', '1d', '7d', '30d'],
  allowReadOnce: true, allowPassphrase: true, maxEnvelopeBytes: 1048576, kdf: { m: 65536, t: 3 },
  enableQrCode: true, darkMode: 'auto', templates: ['credentials', 'wifi'],
};

const PLAINTEXT = 'DUMMY-PLAINTEXT-7f3a';
const PASSPHRASE = 'dummy-passphrase-quartz-9142';

let main: HTMLElement;
const mount = () => {
  document.body.innerHTML = '<main id="main"></main>';
  main = document.getElementById('main') as HTMLElement;
  mountCreate(main, config);
};
const editor = () => main.querySelector('textarea.editor') as HTMLTextAreaElement;
const submit = () => main.querySelector('.action-bar .button-primary') as HTMLButtonElement;
const statusLine = () => main.querySelector('.action-bar .status') as HTMLElement;
const errorBox = () => main.querySelector('.error-box') as HTMLElement;
const pasteButton = () => [...main.querySelectorAll('button')].find((b) => b.textContent === t('editor.paste')) as HTMLButtonElement;
const passphraseCheckbox = () => main.querySelector('input[id^="use-passphrase"]') as HTMLInputElement;
const type = (field: HTMLInputElement | HTMLTextAreaElement, value: string) => {
  field.value = value;
  field.dispatchEvent(new Event('input'));
};
const enablePassphrase = (passphrase: string, confirmation = passphrase) => {
  const box = passphraseCheckbox();
  box.checked = true;
  box.dispatchEvent(new Event('change'));
  const [input, confirm] = [...main.querySelectorAll('.passphrase-panel input.passphrase')] as HTMLInputElement[];
  type(input as HTMLInputElement, passphrase);
  type(confirm as HTMLInputElement, confirmation);
};
const shareLink = () => (main.querySelector('input.link-field') as HTMLInputElement).value;
const onResult = () => until(() => main.querySelector('input.link-field') !== null);

beforeEach(() => {
  setLocale('en');
  setSecureContext(true);
  argon2.supported = true;
  argon2.derive = null;
  argon2.calls = 0;
  announced.length = 0;
  localStorage.clear();
  sessionStorage.clear();
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  delete document.documentElement.dataset.theme;
});

describe('fail-safe encryption', () => {
  it('never sends anything when local encryption fails', async () => {
    const { spy } = mockFetch(() => response(201, {}));
    vi.spyOn(crypto.subtle, 'encrypt').mockRejectedValue(new Error('dummy encryption failure'));
    mount();
    type(editor(), PLAINTEXT);
    submit().click();
    await until(() => !errorBox().hidden);
    expect(spy).not.toHaveBeenCalled();
    expect(errorBox().textContent).toContain(t('error.server'));
    expect(editor().value).toBe(PLAINTEXT);
    expect(submit().disabled).toBe(false);
  });

  it('never sends anything when the Argon2id derivation fails, and has no other KDF path', async () => {
    const { spy } = mockFetch(() => response(201, {}));
    const importKey = vi.spyOn(crypto.subtle, 'importKey');
    const deriveBits = vi.spyOn(crypto.subtle, 'deriveBits');
    const deriveKey = vi.spyOn(crypto.subtle, 'deriveKey');
    argon2.derive = () => Promise.reject(new Error('dummy worker failure'));
    mount();
    type(editor(), PLAINTEXT);
    enablePassphrase(PASSPHRASE);
    submit().click();
    await until(() => !errorBox().hidden);
    expect(argon2.calls).toBe(1);
    expect(spy).not.toHaveBeenCalled();
    expect(deriveKey).not.toHaveBeenCalled();
    const algorithms = [...importKey.mock.calls.map((call) => call[2]), ...deriveBits.mock.calls.map((call) => call[0])]
      .map((algorithm) => (typeof algorithm === 'string' ? algorithm : (algorithm as { name: string }).name));
    expect(algorithms).not.toContain('PBKDF2');
    expect(main.querySelector('input.link-field')).toBeNull();
  });
});

describe('publication states', () => {
  it('disables the publish action and shows textual states while sending', async () => {
    const answer = deferred<Response>();
    const { spy } = mockFetch(() => answer.promise);
    mount();
    expect(submit().textContent).toBe(t('action.create'));
    type(editor(), PLAINTEXT);
    expect(submit().disabled).toBe(false);
    submit().click();
    await until(() => spy.mock.calls.length > 0);
    expect(submit().disabled).toBe(true);
    expect(submit().textContent).toBe(t('action.creating'));
    expect(statusLine().textContent).toBe(t('state.sending'));
    // Neither a second click nor the keyboard shortcut starts another upload.
    submit().click();
    main.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true }));
    await flush();
    expect(spy).toHaveBeenCalledTimes(1);
    const json = spy.mock.calls[0]?.[1]?.body as string;
    answer.resolve(await creationResponse(json));
    await onResult();
    expect(main.querySelector('h1')?.textContent).toBe(t('result.title'));
  });

  it('shows the Argon2id state with the publish action disabled, before any request', async () => {
    const derivation = deferred<Uint8Array>();
    argon2.derive = () => derivation.promise;
    const { spy } = mockFetch(async (request) => creationResponse(request.body as string));
    mount();
    type(editor(), PLAINTEXT);
    enablePassphrase(PASSPHRASE);
    submit().click();
    await until(() => argon2.calls > 0);
    expect(statusLine().textContent).toBe(t('state.deriving'));
    expect(announced).toContain(t('state.deriving'));
    expect(submit().disabled).toBe(true);
    expect(spy).not.toHaveBeenCalled();
    derivation.resolve(new Uint8Array(32).fill(3));
    await onResult();
    expect(spy).toHaveBeenCalledTimes(1);
  });

  // DEFECT: create.ts sets state.encrypting only after prepare() has returned and overwrites
  // it synchronously with state.sending, so no encryption state is ever displayed.
  it('shows an explicit encryption state while encrypting', async () => {
    const encryption = deferred<void>();
    const original = crypto.subtle.encrypt.bind(crypto.subtle);
    const encrypt = vi.spyOn(crypto.subtle, 'encrypt').mockImplementation(async (...args) => {
      await encryption.promise;
      return original(...args);
    });
    mockFetch(async (request) => creationResponse(request.body as string));
    mount();
    type(editor(), PLAINTEXT);
    submit().click();
    await until(() => encrypt.mock.calls.length > 0);
    try {
      expect(statusLine().textContent).toBe(t('state.encrypting'));
    } finally {
      encryption.resolve();
      await onResult();
    }
  });

  it('offers a retry with the same request after a recoverable error, and none after a final one', async () => {
    let attempt = 0;
    const { requests } = mockFetch(async (request) => {
      attempt += 1;
      if (attempt === 1) throw new TypeError('dummy network failure');
      return creationResponse(request.body as string);
    });
    mount();
    type(editor(), PLAINTEXT);
    submit().click();
    await until(() => !errorBox().hidden);
    expect(errorBox().textContent).toContain(t('error.network'));
    expect(submit().disabled).toBe(false);
    expect(submit().textContent).toBe(t('action.create'));
    expect(statusLine().textContent).toBe('');
    expect(editor().value).toBe(PLAINTEXT);
    const retry = [...errorBox().querySelectorAll('button')].find((b) => b.textContent === t('action.retry')) as HTMLButtonElement;
    retry.click();
    await onResult();
    expect(requests).toHaveLength(2);
    expect(requests[1]?.body).toBe(requests[0]?.body);
    expect(requests[1]?.headers['Idempotency-Key']).toBe(requests[0]?.headers['Idempotency-Key']);

    mockFetch(() => response(400));
    mount();
    type(editor(), PLAINTEXT);
    submit().click();
    await until(() => !errorBox().hidden);
    expect(errorBox().textContent).toContain(t('error.refused'));
    expect([...errorBox().querySelectorAll('button')].map((b) => b.textContent)).not.toContain(t('action.retry'));
  });
});

describe('clipboard', () => {
  const stubClipboard = (readText: () => Promise<string>) => {
    const clipboard = { readText: vi.fn(readText), writeText: vi.fn(async () => undefined) };
    Object.defineProperty(navigator, 'clipboard', { value: clipboard, configurable: true });
    return clipboard;
  };
  afterEach(() => {
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
  });

  it('is never read on load or while typing', async () => {
    const clipboard = stubClipboard(async () => 'DUMMY-CLIPBOARD');
    mount();
    await flush();
    type(editor(), 'typed');
    type(editor(), '');
    await flush();
    expect(clipboard.readText).not.toHaveBeenCalled();
    expect(pasteButton().hidden).toBe(false);
  });

  it('is read only by the explicit Paste button, never stored, announced or sent', async () => {
    const { spy } = mockFetch(() => response(201, {}));
    const clipboard = stubClipboard(async () => 'DUMMY-CLIPBOARD-CONTENT');
    mount();
    pasteButton().click();
    await until(() => editor().value !== '');
    expect(clipboard.readText).toHaveBeenCalledTimes(1);
    expect(editor().value).toBe('DUMMY-CLIPBOARD-CONTENT');
    const stored = [...Object.values(localStorage), ...Object.values(sessionStorage), document.cookie].join('\n');
    expect(stored).not.toContain('DUMMY-CLIPBOARD');
    expect(announced.join('\n')).not.toContain('DUMMY-CLIPBOARD');
    expect(location.href).not.toContain('DUMMY-CLIPBOARD');
    expect(spy).not.toHaveBeenCalled();
  });

  it('keeps manual paste with a non-blocking message when reading is denied', async () => {
    const clipboard = stubClipboard(() => Promise.reject(new DOMException('denied', 'NotAllowedError')));
    mount();
    pasteButton().click();
    await until(() => announced.includes(t('editor.pasteDenied')));
    expect(clipboard.readText).toHaveBeenCalledTimes(1);
    expect(document.getElementById('ql-toast')?.textContent).toBe(t('editor.pasteDenied'));
    expect(main.querySelector('[role=alertdialog]')).toBeNull();
    expect(editor().disabled).toBe(false);
    expect(editor().readOnly).toBe(false);
    type(editor(), 'manually pasted dummy text');
    expect(submit().disabled).toBe(false);
  });

  it('hides the Paste button when the Clipboard API is unavailable or the context is not secure', () => {
    mount();
    expect(pasteButton().hidden).toBe(true);
    stubClipboard(async () => 'unused');
    setSecureContext(false);
    mount();
    expect(pasteButton().hidden).toBe(true);
    expect(editor().disabled).toBe(false);
  });
});

describe('passphrase and plaintext never exposed', () => {
  it('keeps the passphrase out of every message, request and URL', async () => {
    const answers = [() => response(429), () => response(500), () => Promise.reject(new TypeError('dummy offline'))];
    const { requests } = mockFetch(async (request) => {
      const next = answers.shift();
      return next ? next() : creationResponse(request.body as string);
    });
    mount();
    type(editor(), PLAINTEXT);
    enablePassphrase(PASSPHRASE, `${PASSPHRASE}-different`);
    expect(main.querySelector('.field-error')?.textContent).toBe(t('passphrase.mismatch'));
    expect(main.querySelector('.disabled-reason')?.textContent).toBe(t('disabled.passphrase'));
    const scans: string[] = [renderedText()];
    enablePassphrase(PASSPHRASE);
    for (let i = 0; i < 3; i++) {
      const errors = announced.length;
      if (i === 0) submit().click();
      else ([...errorBox().querySelectorAll('button')].find((b) => b.textContent === t('action.retry')) as HTMLButtonElement).click();
      await until(() => !errorBox().hidden && announced.length > errors && announced.at(-1) === errorBox().querySelector('p')?.textContent);
      scans.push(renderedText());
    }
    (errorBox().querySelector('button') as HTMLButtonElement).click();
    await onResult();
    scans.push(renderedText(), announced.join('\n'), document.title, location.href);
    for (const text of scans) {
      expect(text).not.toContain(PASSPHRASE);
      expect(text).not.toContain(PLAINTEXT);
    }
    for (const request of requests) {
      expect(`${request.path}\n${request.body}\n${JSON.stringify(request.headers)}`).not.toContain(PASSPHRASE);
      expect(request.body).not.toContain(PLAINTEXT);
    }
    const link = new URL(shareLink());
    expect(link.href).not.toContain(PASSPHRASE);
    expect(link.search).toBe('');
    expect(decode(link.hash.slice(1))).toHaveLength(32);
  });

  it('drops the plaintext and the passphrase from the page once the link is created', async () => {
    mockFetch(async (request) => creationResponse(request.body as string));
    mount();
    type(editor(), PLAINTEXT);
    enablePassphrase(PASSPHRASE);
    submit().click();
    await onResult();
    expect(main.querySelector('textarea')).toBeNull();
    const values = [...document.querySelectorAll('input, textarea')].map((field) => (field as HTMLInputElement).value);
    expect(values).toEqual([shareLink()]);
    expect(renderedText()).not.toContain(PLAINTEXT);
    expect(document.body.innerHTML).not.toContain(PLAINTEXT);
    expect(document.body.innerHTML).not.toContain(PASSPHRASE);

    // A new text starts from an empty editor: the previous state was cleared.
    const again = [...main.querySelectorAll('button')].find((b) => b.textContent === t('action.new')) as HTMLButtonElement;
    again.click();
    (main.querySelector('[role=alertdialog] .button-primary') as HTMLButtonElement).click();
    await until(() => main.querySelector('textarea.editor') !== null);
    expect(editor().value).toBe('');
    expect(passphraseCheckbox().checked).toBe(false);
    expect([...main.querySelectorAll('.passphrase-panel input')].map((field) => (field as HTMLInputElement).value)).toEqual(['', '']);
  });
});

describe('expiry on the result screen', () => {
  it('counts down from server_time and resynchronises when the page becomes visible again', async () => {
    let wall = Date.parse('2029-01-01T00:00:00Z'); // local clock far from the server time
    vi.spyOn(performance, 'now').mockReturnValue(5_000);
    vi.spyOn(Date, 'now').mockImplementation(() => wall);
    mockFetch(async (request) => creationResponse(request.body as string, '2026-10-03T13:00:00Z', '2026-10-03T12:00:00Z'));
    mount();
    type(editor(), PLAINTEXT);
    submit().click();
    await onResult();
    const expiry = () => main.querySelector('.expiry')?.textContent ?? '';
    const expiryLine = () => main.querySelector('.expiry span')?.textContent ?? expiry();
    expect(expiry()).toContain(formatRelative(3600));
    const setVisibility = (state: DocumentVisibilityState) => {
      Object.defineProperty(document, 'visibilityState', { value: state, configurable: true });
      document.dispatchEvent(new Event('visibilitychange'));
    };
    try {
      setVisibility('hidden');
      wall += 50 * 60 * 1000; // device asleep: the monotonic clock did not move
      setVisibility('visible');
      expect(expiry()).toContain(formatRelative(600));
      setVisibility('hidden');
      wall += 20 * 60 * 1000;
      setVisibility('visible');
      expect(expiryLine()).toBe(t('time.expired'));
    } finally {
      Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
    }
  });
});

describe('theme', () => {
  it('never puts the active theme in the encrypted envelope or the AAD', async () => {
    localStorage.setItem('ql-theme', 'dark');
    applyTheme('dark');
    const { requests } = mockFetch(async (request) => creationResponse(request.body as string));
    mount();
    type(editor(), PLAINTEXT);
    submit().click();
    await onResult();
    const body = JSON.parse(requests[0]?.body as string) as { aad: string; nonce: string; ciphertext: string };
    const aad = parse(decode(body.aad));
    const envelope = await decrypt(decode(new URL(shareLink()).hash.slice(1), 32), null, aad, decode(body.nonce, 12), decode(body.ciphertext));
    expect(Object.keys(JSON.parse(envelope) as object).sort()).toEqual(['format', 'language', 'template', 'text', 'v']);
    expect(envelope).not.toMatch(/theme|dark/);
    expect(new TextDecoder().decode(aad.bytes)).not.toMatch(/theme|dark/);
    // Switching the theme keeps only the preference, never the content.
    applyTheme('light');
    expect(Object.values(localStorage).join('\n')).not.toContain(PLAINTEXT);
    expect(document.documentElement.outerHTML).not.toContain(PLAINTEXT);
  });
});

describe('capabilities', () => {
  it('disables the passphrase option when Argon2id is unsupported, without fallback', () => {
    argon2.supported = false;
    mount();
    expect(passphraseCheckbox().disabled).toBe(true);
    expect(main.textContent).toContain(t('passphrase.unsupported'));
  });

  it('keeps the passphrase option available when Argon2id is supported', () => {
    mount();
    expect(passphraseCheckbox().disabled).toBe(false);
    expect(main.textContent).not.toContain(t('passphrase.unsupported'));
  });

  it('refuses to publish without Web Crypto in a secure context', async () => {
    const { spy } = mockFetch(() => response(201, {}));
    setSecureContext(false);
    mount();
    type(editor(), PLAINTEXT);
    expect(submit().disabled).toBe(true);
    expect(main.querySelector('.disabled-reason')?.textContent).toBe(t('disabled.crypto'));
    main.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true }));

    setSecureContext(true);
    vi.stubGlobal('crypto', { getRandomValues: (array: Uint8Array) => array });
    mount();
    type(editor(), PLAINTEXT);
    expect(submit().disabled).toBe(true);
    expect(main.querySelector('.disabled-reason')?.textContent).toBe(t('disabled.crypto'));
    main.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true }));
    await flush();
    expect(spy).not.toHaveBeenCalled();
  });
});
