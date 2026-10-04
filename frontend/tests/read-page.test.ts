// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (reading page §5.1, §6.2, §6.3.1, §8.3, §13): EXG-SEC-018, EXG-SEC-024, EXG-READ-012,
// EXG-SEC-019, EXG-UX-081, EXG-UX-083, EXG-UX-048, EXG-READ-029, EXG-SEC-009, EXG-CRYPTO-057,
// EXG-CRYPTO-058, EXG-TEST-094, EXG-CRYPTO-079.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mountRead } from '../src/pages/read';
import { setLocale, t } from '../src/i18n';
import { decode, encode } from '../src/crypto/base64url';
import { concat, utf8 } from '../src/crypto/bytes';
import { serialize, type Envelope } from '../src/crypto/envelope';
import { sha256 } from '../src/crypto/primitives';
import { prepare } from '../src/crypto/protocol';
import { formatRelative } from '../src/ui/format';
import type { PublicConfig } from '../src/config';
import type { ContentView } from '../src/render/content-view';
import { deferred, dummyChallenge, flush, idForCreation, mockFetch, renderedText, response, setSecureContext, until, type RecordedRequest } from './support/fake-api';

/** Dummy deterministic stand-in for Argon2id (the real one is covered by the shared vectors). */
const fakeArgon2 = async (passphrase: string, salt: Uint8Array) => sha256(concat(utf8.encode(passphrase), salt));

const argon2 = vi.hoisted(() => ({
  supported: true,
  gate: null as null | Promise<void>,
  events: [] as string[],
  calls: 0,
}));
vi.mock('../src/crypto/argon2-client', () => ({
  Argon2UnavailableError: class extends Error {},
  argon2Supported: () => argon2.supported,
  preloadArgon2: () => undefined,
  deriveInWorker: async (passphrase: string, salt: Uint8Array) => {
    argon2.calls += 1;
    argon2.events.push('derive:start');
    if (argon2.gate) await argon2.gate;
    const key = await fakeArgon2(passphrase, salt);
    argon2.events.push('derive:end');
    return key;
  },
}));

const views = vi.hoisted(() => ({ built: [] as { envelope: unknown; view: unknown }[] }));
vi.mock('../src/render/content-view', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../src/render/content-view')>();
  return {
    buildContentView: (envelope: Envelope, options?: { wifiQr?: boolean }) => {
      const view = actual.buildContentView(envelope, options);
      views.built.push({ envelope, view });
      return view;
    },
  };
});

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
  page: 'read', enabledLocales: ['en', 'fr'], defaultExpiration: '1d', expirations: ['5m', '1h', '1d', '7d', '30d'],
  allowReadOnce: true, allowPassphrase: true, maxEnvelopeBytes: 1048576, kdf: { m: 19456, t: 2 },
  enableQrCode: false, darkMode: 'auto', templates: [],
};

const PLAINTEXT = '# DUMMY-HEADING-51c2\n\nDummy body.';
const PASSPHRASE = 'dummy-passphrase-lantern-3307';

interface Paste {
  id: string;
  body: { aad: string; nonce: string; ciphertext: string };
}

/** Encrypts a dummy paste with the real protocol and points the page location to its link. */
async function makePaste(options: { readOnce?: boolean; passphrase?: string | null; format?: Envelope['format'] } = {}): Promise<Paste> {
  const prepared = await prepare({
    envelope: serialize({ format: options.format ?? 'markdown', language: null, template: null, text: PLAINTEXT }),
    expiration: '1d',
    readOnce: options.readOnce ?? false,
    passphrase: options.passphrase ?? null,
    kdf: config.kdf,
    derive: fakeArgon2,
  });
  const id = await idForCreation(prepared.json);
  history.replaceState(null, '', `/p/${id}#${encode(prepared.urlKey)}`);
  return { id, body: prepared.body };
}

interface Times {
  expiresAt?: string | null;
  serverTime?: string;
}

/** Answers challenge/status/open/consume like the real API. */
function answer(paste: Paste, request: RecordedRequest, times: Times = {}): Response {
  const meta = { aad: paste.body.aad, expires_at: times.expiresAt === undefined ? '2026-10-04T12:00:00Z' : times.expiresAt, server_time: times.serverTime ?? '2026-10-03T12:00:00Z', read_once: false };
  const action = request.path.split('/').pop();
  if (action === 'challenge') return response(200, { challenge: dummyChallenge() });
  if (action === 'status') return response(200, meta);
  if (action === 'open') return response(200, { ...meta, nonce: paste.body.nonce, ciphertext: paste.body.ciphertext, consume_challenge: dummyChallenge() });
  if (action === 'consume') return response(200, { consumed: true });
  return response(404);
}

/** Serves `paste`; `override` may replace any answer by returning a response. */
function serve(paste: Paste, times: Times = {}, override: (request: RecordedRequest) => Promise<Response | null> | Response | null = () => null) {
  return mockFetch(async (request) => (await override(request)) ?? answer(paste, request, times));
}

let main: HTMLElement;
let rerender: () => void = () => undefined;
const mount = () => {
  document.body.innerHTML = '<main id="main"></main>';
  main = document.getElementById('main') as HTMLElement;
  rerender = mountRead(main, config);
};
const paths = (requests: RecordedRequest[]) => requests.map((request) => request.path.split('/').pop());
const content = () => views.built.at(-1)?.view as ContentView | undefined;
const onContent = () => until(() => content()?.container.isConnected === true);
const errorText = () => main.querySelector('[role=alert]')?.textContent ?? '';
const revealButton = () => main.querySelector('.action-bar .button-primary') as HTMLButtonElement;
const typePassphrase = (value: string) => {
  const input = main.querySelector('input.passphrase') as HTMLInputElement;
  input.value = value;
  input.dispatchEvent(new Event('input'));
};

beforeEach(() => {
  setLocale('en');
  setSecureContext(true);
  argon2.supported = true;
  argon2.gate = null;
  argon2.events = [];
  argon2.calls = 0;
  views.built = [];
  announced.length = 0;
  sessionStorage.clear();
});

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('nothing is rendered before decryption and integrity checks', () => {
  it('shows only textual states until the content is decrypted, then renders it from the envelope', async () => {
    const paste = await makePaste();
    const opening = deferred<void>();
    serve(paste, {}, async (request) => {
      if (!request.path.endsWith('/open')) return null;
      await opening.promise;
      return answer(paste, request);
    });
    mount();
    expect(main.querySelector('[role=status]')?.textContent).toBe(t('state.checking'));
    await until(() => main.textContent?.includes(t('state.opening')) === true);
    expect(views.built).toHaveLength(0);
    expect(renderedText()).not.toContain('DUMMY-HEADING');
    expect(main.querySelector('.reader, .markdown, pre, code, .reader-controls')).toBeNull();
    expect(main.textContent).not.toMatch(/markdown|plain text/i);

    opening.resolve();
    await onContent();
    expect(announced).toContain(t('state.decrypting'));
    expect(views.built).toHaveLength(1);
    expect(views.built[0]?.envelope).toEqual({ format: 'markdown', language: null, template: null, text: PLAINTEXT, v: 1 });
    expect(main.querySelector('.reader h1')?.textContent).toBe('DUMMY-HEADING-51c2');
  });

  it('shows an integrity error and no content for a tampered ciphertext', async () => {
    const paste = await makePaste();
    const ciphertext = decode(paste.body.ciphertext);
    ciphertext[0] = (ciphertext[0] ?? 0) ^ 0x01;
    serve({ ...paste, body: { ...paste.body, ciphertext: encode(ciphertext) } });
    mount();
    await until(() => errorText() !== '');
    expect(errorText()).toBe(t('error.integrity'));
    expect(views.built).toHaveLength(0);
    expect(document.body.innerHTML).not.toContain('DUMMY-HEADING');
  });

  it('shows an integrity error and no content when open returns a different AAD than status', async () => {
    const other = await makePaste({ readOnce: true });
    const original = await makePaste();
    const served = serve(original, {}, (request) => {
      if (!request.path.endsWith('/open')) return null;
      return response(200, { aad: other.body.aad, expires_at: null, server_time: '2026-10-03T12:00:00Z', read_once: false, nonce: original.body.nonce, ciphertext: original.body.ciphertext });
    });
    mount();
    await until(() => errorText() !== '');
    expect(paths(served.requests)).toContain('open');
    expect(errorText()).toBe(t('error.integrity'));
    expect(views.built).toHaveLength(0);
  });
});

describe('auto-hide', () => {
  it('hides the content after two minutes without activity, unless kept visible', async () => {
    const paste = await makePaste();
    serve(paste);
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    mount();
    await onContent();
    const container = (content() as ContentView).container;
    const hidden = () => container.classList.contains('is-hidden');
    vi.advanceTimersByTime(119_000);
    expect(hidden()).toBe(false);
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));
    vi.advanceTimersByTime(119_000);
    expect(hidden()).toBe(false);
    vi.advanceTimersByTime(2_000);
    expect(hidden()).toBe(true);
    expect(container.getAttribute('aria-hidden')).toBe('true');
    // Hidden content can be neither focused nor used (links, block copy).
    expect(container.hasAttribute('inert')).toBe(true);
    expect(main.textContent).toContain(t('read.hidden'));

    // The user can show it again and keep it visible.
    ([...main.querySelectorAll('button')].find((b) => b.textContent === t('read.show')) as HTMLButtonElement).click();
    expect(hidden()).toBe(false);
    const keep = main.querySelector('input[id^="keep"]') as HTMLInputElement;
    keep.checked = true;
    keep.dispatchEvent(new Event('change'));
    vi.advanceTimersByTime(600_000);
    expect(hidden()).toBe(false);
  });
});

describe('expiry display', () => {
  const expiryText = () => main.querySelector('.expiry')?.textContent ?? '';
  // The relative-time line alone, without the inaccurate-clock notice.
  const expiryLine = () => main.querySelector('.expiry span')?.textContent ?? expiryText();

  it('computes the remaining time from server_time, whatever the local clock says', async () => {
    const paste = await makePaste();
    // The local clock (2026) is years away from the server time (2031).
    serve(paste, { serverTime: '2031-05-01T08:00:00Z', expiresAt: '2031-05-01T08:10:00Z' });
    mount();
    await onContent();
    expect(expiryText()).toContain(formatRelative(600));
    expect(expiryText()).not.toContain(t('time.approximate'));
  });

  it('shows the near-expiry and expired states', async () => {
    const paste = await makePaste();
    serve(paste, { serverTime: '2031-05-01T08:00:00Z', expiresAt: '2031-05-01T08:00:30Z' });
    mount();
    await onContent();
    expect(expiryText()).toContain(formatRelative(30));

    serve(paste, { serverTime: '2031-05-01T08:00:00Z', expiresAt: '2031-05-01T08:00:00Z' });
    views.built = [];
    mount();
    await onContent();
    expect(expiryLine()).toBe(t('time.expired'));
  });

  it('marks the remaining time as approximate after a slow round trip', async () => {
    const paste = await makePaste();
    let now = 1_000;
    vi.spyOn(performance, 'now').mockImplementation(() => now);
    serve(paste, { serverTime: '2031-05-01T08:00:00Z', expiresAt: '2031-05-01T09:00:00Z' }, (request) => {
      if (request.path.endsWith('/status')) now += 6_000;
      return null;
    });
    mount();
    await onContent();
    expect(expiryText()).toContain(t('time.approximate'));
  });

  it('resynchronises the countdown when the page becomes visible again', async () => {
    const paste = await makePaste();
    let wall = Date.parse('2026-10-03T12:00:00Z');
    vi.spyOn(performance, 'now').mockReturnValue(5_000);
    vi.spyOn(Date, 'now').mockImplementation(() => wall);
    serve(paste, { serverTime: '2031-05-01T08:00:00Z', expiresAt: '2031-05-02T08:00:00Z' });
    mount();
    await onContent();
    expect(expiryText()).toContain(formatRelative(24 * 3600 - 0));
    // The device clock is years behind the server: the reader is told so (§5.1).
    expect(expiryText()).toContain(t('time.clockWarning'));
    const setVisibility = (state: DocumentVisibilityState) => {
      Object.defineProperty(document, 'visibilityState', { value: state, configurable: true });
      document.dispatchEvent(new Event('visibilitychange'));
    };
    try {
      setVisibility('hidden');
      wall += 23 * 3600 * 1000; // device asleep: the monotonic clock did not move
      setVisibility('visible');
      expect(expiryText()).toContain(formatRelative(3600));
    } finally {
      Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
    }
  });
});

describe('passphrase', () => {
  it('finishes the Argon2id derivation before the reservation and open requests', async () => {
    const paste = await makePaste({ readOnce: true, passphrase: PASSPHRASE });
    const gate = deferred<void>();
    argon2.gate = gate.promise;
    const { requests } = serve(paste, {}, (request) => {
      argon2.events.push(`request:${request.path.split('/').pop()}`);
      return null;
    });
    mount();
    await until(() => main.querySelector('input.passphrase') !== null);
    typePassphrase(PASSPHRASE);
    revealButton().click();
    await until(() => argon2.calls > 0);
    expect(main.querySelector('[role=status]')?.textContent).toBe(t('state.deriving'));
    expect(revealButton().disabled).toBe(true);
    expect(paths(requests)).not.toContain('open');
    expect(sessionStorage.length).toBe(0);
    gate.resolve();
    await onContent();
    const events = argon2.events.filter((event) => event.startsWith('derive') || event === 'request:open');
    expect(events).toEqual(['derive:start', 'derive:end', 'request:open']);
    expect(paths(requests)).toContain('consume');
  });

  it('rejects a wrong passphrase locally, without open and without echoing it', async () => {
    const paste = await makePaste({ readOnce: true, passphrase: PASSPHRASE });
    const { requests } = serve(paste);
    mount();
    await until(() => main.querySelector('input.passphrase') !== null);
    const wrong = 'dummy-wrong-passphrase-8812';
    typePassphrase(wrong);
    revealButton().click();
    await until(() => errorText() === t('error.wrongPassphrase'));
    expect(paths(requests)).not.toContain('open');
    expect(renderedText()).not.toContain(wrong);
    expect(announced.join('\n')).not.toContain(wrong);
    expect(document.title).not.toContain(wrong);
    expect(location.href).not.toContain(wrong);
  });

  it('shows an explicit message, without open nor any fallback derivation, when Argon2id is unsupported', async () => {
    const paste = await makePaste({ passphrase: PASSPHRASE });
    const importKey = vi.spyOn(crypto.subtle, 'importKey');
    const { requests } = serve(paste);
    argon2.supported = false;
    mount();
    await until(() => main.querySelector('input.passphrase') !== null);
    typePassphrase(PASSPHRASE);
    revealButton().click();
    await until(() => errorText() !== '');
    expect(errorText()).toBe(t('error.argon2'));
    expect(argon2.calls).toBe(0);
    expect(paths(requests)).not.toContain('open');
    expect(views.built).toHaveLength(0);
    const algorithms = importKey.mock.calls.map((call) => (typeof call[2] === 'string' ? call[2] : (call[2] as { name: string }).name));
    expect(algorithms).not.toContain('PBKDF2');
  });
});

describe('capabilities', () => {
  it('reports the missing Web Crypto support without any request', async () => {
    const paste = await makePaste();
    const { spy } = serve(paste);
    setSecureContext(false);
    mount();
    expect(errorText()).toBe(t('app.unsupported'));
    vi.stubGlobal('crypto', { getRandomValues: (array: Uint8Array) => array });
    setSecureContext(true);
    mount();
    expect(errorText()).toBe(t('app.unsupported'));
    await flush();
    expect(spy).not.toHaveBeenCalled();
  });
});

describe('language change on the content screen', () => {
  it('redraws the decrypted content in the new language without any request, keeping it hidden', async () => {
    const paste = await makePaste({ readOnce: true });
    const { requests } = serve(paste);
    mount();
    await until(() => revealButton()?.textContent === t('read.reveal'));
    revealButton().click();
    await onContent();
    (main.querySelector('.action-bar .button-secondary') as HTMLButtonElement).click();
    const sent = requests.length;

    setLocale('fr');
    rerender();

    expect(main.querySelector('h1')?.textContent).toBe(t('page.read.title'));
    expect(main.textContent).toContain(t('read.destroyed'));
    expect(main.querySelector('.action-bar .button-primary')?.textContent).toBe(t('action.copy'));
    expect(content()?.container.classList.contains('is-hidden')).toBe(true);
    expect(requests).toHaveLength(sent);
    setLocale('en');
  });
});

describe('copy feedback', () => {
  it('confirms the copy and gives the clipboard advice in one message', async () => {
    const paste = await makePaste();
    serve(paste);
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText: async () => undefined } });
    mount();
    await onContent();
    (main.querySelector('.action-bar .button-primary') as HTMLButtonElement).click();
    await until(() => document.getElementById('ql-toast')?.textContent !== undefined && document.getElementById('ql-toast')?.textContent !== '');

    expect(document.getElementById('ql-toast')?.textContent).toBe(t('read.copied'));
  });
});

describe('read-once reservation in degraded conditions', () => {
  const reservationKeys = () => Object.keys(sessionStorage).filter((key) => key.startsWith('ql-reservation-'));

  it('keeps the reservation when the destruction cannot be confirmed for a transient reason', async () => {
    const paste = await makePaste({ readOnce: true });
    serve(paste, {}, (request) => (request.path.endsWith('/consume') ? response(500) : null));
    mount();
    await until(() => revealButton()?.textContent === t('read.reveal'));
    revealButton().click();
    await until(() => main.textContent?.includes(t('read.consumeFailed')) === true, 10_000);

    // A reload of the same tab resumes the reservation instead of being locked out.
    expect(reservationKeys()).toHaveLength(1);
  }, 15_000);

  it('forgets the reservation when the paste turned out to be unavailable', async () => {
    const paste = await makePaste({ readOnce: true });
    serve(paste, {}, (request) => (request.path.endsWith('/open') ? response(404) : null));
    mount();
    await until(() => revealButton()?.textContent === t('read.reveal'));
    revealButton().click();
    await until(() => main.textContent?.includes(t('error.unavailable')) === true);

    expect(reservationKeys()).toHaveLength(0);
  });

  it('drops expired reservations of any paste when a page loads', async () => {
    sessionStorage.setItem('ql-reservation-OTHER', JSON.stringify({ id: 'dummy', until: Date.now() - 1 }));
    const paste = await makePaste();
    serve(paste);
    mount();
    await onContent();

    expect(reservationKeys()).toHaveLength(0);
  });

  it('words a lost connection for the reader, not for an author', async () => {
    const paste = await makePaste();
    serve(paste, {}, (request) => (request.path.endsWith('/status') ? Promise.reject(new TypeError('offline')) : null));
    mount();
    await until(() => main.textContent?.includes(t('error.networkRead')) === true);

    expect(main.textContent).not.toContain(t('error.network'));
  });
});

