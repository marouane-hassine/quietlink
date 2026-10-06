// SPDX-License-Identifier: AGPL-3.0-or-later

/** Reading page (§5.1 "Écran de lecture", §6.2, §6.3.1, parcours B). */

import { api, ApiError, retrying, type OpenResponse, type StatusResponse } from '../api';
import { clearExpiredReservations, clearReservation, loadReservation, reservationUntil, saveReservation } from '../reservation';
import { parse, type ParsedAad } from '../crypto/aad';
import { decode, EncodingError } from '../crypto/base64url';
import { equal, randomBytes, wipe } from '../crypto/bytes';
import { encode } from '../crypto/base64url';
import { Argon2UnavailableError, argon2Supported, deriveInWorker, preloadArgon2 } from '../crypto/argon2-client';
import { parseEnvelope, type Envelope } from '../crypto/envelope';
import { DecryptionError } from '../crypto/primitives';
import { accessPublicKey, accessSeed, checkConsumeKey, decrypt, matchesAccessKey, prove, WrongPassphraseError } from '../crypto/protocol';
import type { PublicConfig } from '../config';
import type { buildContentView, ContentDisplay } from '../render/content-view';

type BuildContentView = typeof buildContentView;
import { t, tn } from '../i18n';
import { exportButton, printButton } from '../ui/local-output';
import { announce } from '../ui/announcer';
import { copyText } from '../ui/clipboard';
import { synchronise, type Sync } from '../ui/countdown';
import { runCountdown } from '../ui/expiry-view';
import { el, focusUnlessRedrawing, forgetFragment, nextId, reloadOnFragmentChange, showScreen } from '../ui/dom';
import { holdRetry } from '../ui/retry-delay';
import { formatDate, formatRelative } from '../ui/format';
import { cryptoAvailable } from '../ui/capabilities';

const RESERVATION_MAX_SECONDS = 300;
/** Auto-hide delays offered (minutes; 0 = never), the default and its preference key (§5.1). */
const AUTO_HIDE_CHOICES = [1, 2, 5, 0] as const;
const AUTO_HIDE_DEFAULT = 2;
const AUTO_HIDE_KEY = 'ql-autohide';

/** Remembered delay: a display preference only, never content (storage may be unavailable). */
function storedAutoHide(): number {
  try {
    const value = Number(localStorage.getItem(AUTO_HIDE_KEY));
    return localStorage.getItem(AUTO_HIDE_KEY) !== null && (AUTO_HIDE_CHOICES as readonly number[]).includes(value) ? value : AUTO_HIDE_DEFAULT;
  } catch {
    return AUTO_HIDE_DEFAULT;
  }
}
/** Lifetime of open/status challenges when the server does not state it (§6.3.1). */
const CHALLENGE_LIFETIME_S = 60;
/** A challenge with less validity left than this is replaced before being signed. */
const CHALLENGE_RENEW_MARGIN_S = 10;

class LinkError extends Error {}

/** Parses /p/<id>#<key> and checks the access fingerprint before any network call (§8.2). */
async function parseLink(): Promise<{ id: string; idBytes: Uint8Array; urlKey: Uint8Array; accessPk: Uint8Array }> {
  const id = location.pathname.split('/').pop() ?? '';
  let idBytes: Uint8Array;
  let urlKey: Uint8Array;
  try {
    idBytes = decode(id, 24);
    urlKey = decode(location.hash.slice(1), 32);
  } catch (error) {
    if (error instanceof EncodingError) throw new LinkError('incomplete');
    throw error;
  }
  const accessPk = await accessPublicKey(urlKey);
  if (!(await matchesAccessKey(idBytes, accessPk))) throw new LinkError('altered');
  return { id, idBytes, urlKey, accessPk };
}

export function mountRead(main: HTMLElement, config: PublicConfig): () => void {
  reloadOnFragmentChange();

  /** Redraws the current screen in a new language; null while busy or once content is shown. */
  let redraw: (() => void) | null = null;

  const fail = (messageKey: string, values: Record<string, string | number> = {}, retry: (() => void) | null = null, retryAfter: number | null = null) => {
    // Deadline fixed once: a redraw (language change) continues the countdown, never restarts it.
    const retryAt = retryAfter !== null && retryAfter > 0 ? Date.now() + retryAfter * 1000 : null;
    const draw = () => {
      redraw = draw;
      const left = retryAt === null ? null : Math.max(0, (retryAt - Date.now()) / 1000);
      // A delay quoted in the message follows the countdown too.
      const shown = 'seconds' in values && left !== null ? { ...values, seconds: Math.ceil(left) } : values;
      const message = t(messageKey, shown);
      let retryButton: HTMLButtonElement | null = null;
      if (retry) {
        retryButton = el('button', { type: 'button', class: 'button button-primary' }, t('action.retry'));
        retryButton.addEventListener('click', retry);
        holdRetry(retryButton, left);
      }
      showScreen(main, el('h1', { class: 'page-title' }, t('page.read.title')), el('p', { class: 'error', role: 'alert' }, message), el('div', { class: 'action-bar' }, ...[retryButton, newLink()].filter((n): n is NonNullable<typeof n> => n !== null)));
    };
    draw();
  };

  const newLink = () => el('a', { href: '/', class: 'button button-secondary' }, t('action.new'));

  /**
   * Challenges held for later use, each with the monotonic instant before which it cannot have
   * been issued and its lifetime. The embedded ones were issued while the page was served, after
   * navigation started: the performance time origin (0) bounds their age (§6.3.1).
   */
  type Held = { value: string; issuedAfter: number; lifetime: number };
  const embeddedLifetime = typeof config.challenges?.expires_in === 'number' ? config.challenges.expires_in : CHALLENGE_LIFETIME_S;
  let held: Partial<Record<'open' | 'status', Held>> = config.challenges
    ? { open: { value: config.challenges.open, issuedAfter: 0, lifetime: embeddedLifetime }, status: { value: config.challenges.status, issuedAfter: 0, lifetime: embeddedLifetime } }
    : {};
  /** Still valid when it reaches the server: a margin covers the round trip. */
  const fresh = (challenge: Held | undefined): challenge is Held => challenge !== undefined && challenge.value !== '' && performance.now() < challenge.issuedAfter + (challenge.lifetime - CHALLENGE_RENEW_MARGIN_S) * 1000;
  const fetchChallenge = async (id: string, usage: 'open' | 'status'): Promise<Held> => {
    const fetched = await api.challenge(id, usage);
    return { value: fetched.challenge, issuedAfter: fetched.issuedAfter, lifetime: fetched.expiresIn ?? CHALLENGE_LIFETIME_S };
  };

  /**
   * Each challenge is signed once. One about to expire (the reader waited on the Reveal screen, a
   * slow answer) is proactively replaced by a fresh one instead of paying a 404 and a retry (§6.3.1).
   */
  const takeChallenge = async (id: string, usage: 'open' | 'status'): Promise<string> => {
    const kept = held[usage];
    held = { ...held, [usage]: undefined };
    if (fresh(kept)) return kept.value;
    const first = await fetchChallenge(id, usage);
    // A single renewal: an answer slower than the challenge lifetime would loop otherwise.
    return fresh(first) ? first.value : (await fetchChallenge(id, usage)).value;
  };

  const proofBody = async (link: Awaited<ReturnType<typeof parseLink>>, usage: 'open' | 'status', extra: Record<string, string> = {}) => {
    const challenge = await takeChallenge(link.id, usage);
    const seed = await accessSeed(link.urlKey);
    try {
      return { challenge, access_pk: encode(link.accessPk), signature: await prove(seed, challenge), ...extra };
    } finally {
      wipe(seed);
    }
  };

  /** One transparent retry with a fresh challenge on 404, whatever the age of the first one (§6.3.1). */
  const withRetry = async <T,>(call: () => Promise<T>): Promise<T> => {
    try {
      return await call();
    } catch (error) {
      if (error instanceof ApiError && error.kind === 'unavailable') {
        held = {};
        return call();
      }
      throw error;
    }
  };

  const handleError = (error: unknown) => {
    if (error instanceof LinkError) return fail(error.message === 'altered' ? 'error.alteredLink' : 'error.incompleteLink');
    if (error instanceof ApiError) {
      // Recoverable states offer a retry; unavailability is final (§5.1).
      if (error.kind === 'reserved') return fail('error.reserved', { seconds: error.retryAfter ?? 60 }, () => void start(), error.retryAfter ?? 60);
      if (error.kind === 'unavailable') forgetFragment();
      const recoverable = error.kind === 'network' || error.kind === 'rate' || error.kind === 'server' || error.kind === 'quota';
      return fail(({ network: 'error.networkRead', rate: 'error.rateLimited', unavailable: 'error.unavailable' } as Record<string, string>)[error.kind] ?? 'error.server', {}, recoverable ? () => void start() : null, error.retryAfter);
    }
    if (error instanceof DecryptionError) return fail('error.integrity');
    return fail('error.server');
  };

  /** Monotonic end (performance.now ms) of the reservation this tab resumes after a reload. */
  let reservationEnd: number | null = null;

  /**
   * Remaining time of a reservation made by this tab before a reload: the server figure when it
   * reports the paste reserved, the stored estimate from an older server; none once released.
   */
  const resumedReservationEnd = (pasteId: string, status: StatusResponse): number | null => {
    const until = reservationUntil(pasteId, Date.now());
    if (until === null || status.state === 'available') return null;
    const seconds = status.state === 'reserved' && typeof status.retry_after === 'number' ? status.retry_after : (until - Date.now()) / 1000;
    return performance.now() + Math.max(0, seconds) * 1000;
  };

  /** Live "reservation kept for m:ss" line of the Reveal screen; stops once detached. */
  const reservationCountdown = (end: number): HTMLElement => {
    const line = el('p', { class: 'notice reservation-left' });
    let previous = Number.POSITIVE_INFINITY;
    const tick = () => {
      if (!line.isConnected && previous !== Number.POSITIVE_INFINITY) return;
      const left = Math.max(0, Math.ceil((end - performance.now()) / 1000));
      const time = `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
      line.textContent = left > 0 ? t('read.reservationLeft', { time }) : t('read.reservationExpired');
      // Announced at the last minute and at expiry only, not every second (WCAG 4.1.3).
      if ((previous > 60 && left <= 60 && left > 0) || (previous > 0 && left === 0)) announce(line.textContent);
      previous = left;
      // Next tick when the displayed second changes.
      if (left > 0) window.setTimeout(tick, (end - performance.now()) % 1000 || 1000);
    };
    tick();
    return line;
  };

  async function start(): Promise<void> {
    // A new attempt replaces the previous screen: a language change must not redraw it.
    redraw = null;
    clearExpiredReservations(Date.now());
    if (!cryptoAvailable()) return fail('app.unsupported');
    showScreen(main, el('h1', { class: 'page-title' }, t('page.read.title')), el('p', { class: 'status', role: 'status' }, t('state.checking')));
    try {
      const link = await parseLink();
      const status = await withRetry(async () => api.status(link.id, await proofBody(link, 'status')));
      const aad = parse(decode(status.data.aad));
      if (!equal(aad.accessPk, link.accessPk)) throw new DecryptionError('integrity');
      const sync = status.data.expires_at === null ? null : synchronise(status.data.expires_at, status.data.server_time, status.t0, status.t1);
      reservationEnd = aad.object.read_once ? resumedReservationEnd(link.id, status.data) : null;
      if (aad.object.read_once || aad.object.kdf !== null) showReveal(link, aad, status.data, sync);
      else await openAndShow(link, aad, null, null, sync);
    } catch (error) {
      handleError(error);
    }
  }

  function showReveal(link: Awaited<ReturnType<typeof parseLink>>, aad: ParsedAad, status: StatusResponse, sync: Sync | null, errorKey: string | null = null): void {
    redraw = () => showReveal(link, aad, status, sync, errorKey);
    // Loaded while the reader decides, so a connection lost before Reveal does not block it.
    void import('../render/content-view').catch(() => undefined);
    const needsPassphrase = aad.object.kdf !== null;
    const inputId = nextId('passphrase');
    // A real password input (ADR-0009): CSS masking would leave the value readable by screen readers.
    const input = el('input', { id: inputId, class: 'passphrase', type: 'password', autocomplete: 'off', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false' });
    input.addEventListener('focus', preloadArgon2, { once: true });
    const error = el('p', { class: 'field-error', role: 'alert', id: nextId('error') });
    input.setAttribute('aria-describedby', error.id);
    const button = el('button', { type: 'button', class: 'button button-primary' }, t('read.reveal'));
    const statusLine = el('p', { class: 'status', role: 'status' });

    button.addEventListener('click', async () => {
      redraw = null;
      button.disabled = true;
      error.textContent = '';
      let kPass: Uint8Array | null = null;
      let consumeSeed: Uint8Array | null = null;
      try {
        if (needsPassphrase) {
          if (!argon2Supported()) throw new Error('argon2');
          const kdf = aad.object.kdf;
          if (!kdf || !aad.salt) throw new DecryptionError('kdf');
          statusLine.textContent = t('state.deriving');
          kPass = await deriveInWorker(input.value, aad.salt, kdf.m, kdf.t);
        }
        // Passphrase verified locally before any reservation (§6.3.1 step 0).
        consumeSeed = await checkConsumeKey(aad, link.urlKey, kPass);
        let reservationId: string | null = null;
        if (aad.object.read_once) {
          // Read at click time: a reservation that lapsed on this screen is not resumed.
          reservationId = loadReservation(link.id, Date.now()) ?? encode(randomBytes(16));
          // Stored before open (§6.3.1); refined with the real remaining time after open.
          saveReservation(link.id, reservationId, Date.now(), RESERVATION_MAX_SECONDS);
        }
        await openAndShow(link, aad, kPass, consumeSeed, sync, reservationId);
      } catch (e) {
        button.disabled = false;
        statusLine.textContent = '';
        if (e instanceof WrongPassphraseError) {
          // Read-once: checked locally against consume_pk, certain. Multi-read: ambiguous.
          showReveal(link, aad, status, sync, e.message === 'ambiguous' ? 'error.wrongPassphraseOrAltered' : 'error.wrongPassphrase');
          return;
        }
        // Unsupported (no WebAssembly or workers): final. Otherwise a worker that failed (stale
        // chunk after a redeploy, out of memory) can be retried: nothing is reserved yet.
        if (e instanceof Error && e.message === 'argon2') return fail('error.argon2');
        if (e instanceof Argon2UnavailableError) return argon2Supported() ? fail('error.argon2Failed', {}, () => showReveal(link, aad, status, sync, null)) : fail('error.argon2');
        // A final answer ends the reservation; transient failures keep it for resumption.
        if ((e instanceof ApiError && e.kind === 'unavailable') || e instanceof DecryptionError) clearReservation(link.id);
        handleError(e);
      } finally {
        wipe(kPass, consumeSeed);
      }
    });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') button.click();
    });

    const opens = status.unconfirmed_opens ?? 0;
    const expiry = el('p', { class: 'expiry' });
    runCountdown(expiry, status.expires_at, sync);
    showScreen(
      main,
      el('h1', { class: 'page-title' }, t('read.revealTitle')),
      el('p', {}, t('read.decryptedLocally')),
      expiry,
      aad.object.read_once ? el('p', { class: 'warning' }, t('read.readOnce')) : null,
      reservationEnd !== null ? reservationCountdown(reservationEnd) : null,
      opens > 0 ? el('p', { class: 'warning', role: 'alert' }, tn('read.priorOpens', opens)) : null,
      needsPassphrase ? el('div', { class: 'field' }, el('label', { for: inputId }, t('read.passphraseRequired')), input, error) : error,
      el('div', { class: 'action-bar' }, button, statusLine),
    );
    if (errorKey !== null) {
      error.textContent = t(errorKey);
    }
    if (needsPassphrase) focusUnlessRedrawing(input);
  }

  async function openAndShow(link: Awaited<ReturnType<typeof parseLink>>, aad: ParsedAad, kPass: Uint8Array | null, consumeSeed: Uint8Array | null, sync: Sync | null, reservationId: string | null = null): Promise<void> {
    showScreen(main, el('h1', { class: 'page-title' }, t('page.read.title')), el('p', { class: 'status', role: 'status' }, t('state.opening')));
    // The rendering code is loaded before open: a read-once paste is never consumed without
    // the means to display it (stale chunk after a redeploy, network failure) (§6.3.1).
    const view = await import('../render/content-view').catch(() => {
      // A chunk that failed to load (offline, stale page after a redeploy) is recoverable.
      throw new ApiError('network');
    });
    const opened = await withRetry(async () => api.open(link.id, await proofBody(link, 'open', reservationId ? { reservation_id: reservationId } : {})));
    const data: OpenResponse = opened.data;
    // The AAD returned by open must be byte-identical to the one returned by status (§8.3).
    if (!equal(decode(data.aad), aad.bytes)) throw new DecryptionError('integrity');
    announce(t('state.decrypting'));
    let plaintext: string;
    try {
      plaintext = await decrypt(link.urlKey, kPass, aad, decode(data.nonce, 12), decode(data.ciphertext));
    } catch (error) {
      // Multi-read with a passphrase: an AES-GCM failure cannot tell a wrong passphrase from
      // altered content (no local check exists), the message covers both (ADR-0009).
      if (aad.object.kdf !== null && !aad.object.read_once) throw new WrongPassphraseError('ambiguous');
      throw error instanceof DecryptionError ? error : new DecryptionError('integrity');
    }
    let envelope: Envelope;
    try {
      envelope = parseEnvelope(plaintext);
    } catch {
      // Decrypted but not a valid envelope: the content was altered, whatever the passphrase.
      throw new DecryptionError('integrity');
    }

    let consumedKey: string | null = null;
    if (aad.object.read_once && reservationId && consumeSeed && data.consume_challenge) {
      saveReservation(link.id, reservationId, Date.now(), data.retry_after ?? RESERVATION_MAX_SECONDS);
      // The same signature is resent after network errors, never re-signed (§12.3).
      const body = { access_pk: encode(link.accessPk), reservation_id: reservationId, challenge: data.consume_challenge, signature: await prove(consumeSeed, data.consume_challenge) };
      try {
        await retrying(() => api.consume(link.id, body));
        consumedKey = 'read.destroyed';
        clearReservation(link.id);
        forgetFragment();
      } catch (error) {
        // Only a final 404 ends the reservation: after a transient failure (network, 429, 5xx)
        // a reload of this tab resumes it instead of being locked out (§6.3.1). The 404 says the
        // content is gone from the server (deleted or expired meanwhile): told apart (§5.1).
        const gone = error instanceof ApiError && error.kind === 'unavailable';
        consumedKey = gone ? 'read.consumeUnavailable' : 'read.consumeFailed';
        if (gone) {
          clearReservation(link.id);
          forgetFragment();
        }
      }
      window.addEventListener('beforeunload', (event) => {
        event.preventDefault();
        event.returnValue = t('read.leaveWarning');
      });
    }
    showContent(view.buildContentView, envelope, data, consumedKey, sync ?? (data.expires_at === null ? null : synchronise(data.expires_at, data.server_time, opened.t0, opened.t1)), data.unconfirmed_opens ?? 0);
  }

  /** Removes the timers and document listeners of the content screen being replaced. */
  let teardownContent: (() => void) | null = null;

  /**
   * Content screen. A language change redraws it from the decrypted envelope kept in memory
   * (no request: a read-once paste cannot be fetched again), keeping it hidden if it was.
   */
  function showContent(buildContentView: BuildContentView, envelope: Envelope, data: OpenResponse, consumedKey: string | null, sync: Sync | null, priorOpens: number, view = { hidden: false, autoHide: storedAutoHide(), display: { mode: null, wrap: true } as ContentDisplay }): void {
    teardownContent?.();
    const listeners = new AbortController();
    const { signal } = listeners;
    redraw = () => showContent(buildContentView, envelope, data, consumedKey, sync, priorOpens, view);
    const { container, controls } = buildContentView(envelope, { wifiQr: config.enableQrCode, display: view.display });

    const hiddenNotice = el('p', { class: 'hint', hidden: true }, t('read.hidden'));
    const hideButton = el('button', { type: 'button', class: 'button button-secondary' }, t('read.hide'));
    const setHidden = (hidden: boolean) => {
      // Focus inside content becoming inert would fall back to the page: move it to the button.
      if (hidden && !view.hidden && container.contains(document.activeElement)) hideButton.focus();
      if (hidden && !view.hidden) announce(t('read.hidden'));
      view.hidden = hidden;
      container.classList.toggle('is-hidden', hidden);
      container.setAttribute('aria-hidden', String(hidden));
      container.toggleAttribute('inert', hidden);
      hiddenNotice.hidden = !hidden;
      hideButton.textContent = hidden ? t('read.show') : t('read.hide');
      if (hidden) window.getSelection()?.removeAllRanges();
    };
    hideButton.addEventListener('click', () => setHidden(!container.classList.contains('is-hidden')));
    const copyAll = el('button', { type: 'button', class: 'button button-primary' }, t('action.copy'));
    copyAll.addEventListener('click', () => void copyText(envelope.text, t('read.copied')));

    let idle = 0;
    const activity = () => {
      window.clearTimeout(idle);
      if (view.autoHide > 0) idle = window.setTimeout(() => setHidden(true), view.autoHide * 60_000);
    };
    activity();
    for (const type of ['pointerdown', 'keydown', 'scroll', 'touchstart']) document.addEventListener(type, activity, { passive: true, signal });
    const autoHide = el('select', { id: nextId('autohide'), class: 'control-select autohide' },
      ...AUTO_HIDE_CHOICES.map((minutes) => el('option', { value: String(minutes), selected: minutes === view.autoHide }, minutes === 0 ? t('read.autoHideNever') : t('read.autoHideMinutes', { minutes }))));
    autoHide.addEventListener('change', () => {
      view.autoHide = Number(autoHide.value);
      try {
        localStorage.setItem(AUTO_HIDE_KEY, autoHide.value);
      } catch {
        // Storage unavailable: the choice applies to this page only.
      }
      activity();
    });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') setHidden(true);
    }, { signal });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') setHidden(true);
    }, { signal });
    teardownContent = () => {
      listeners.abort();
      window.clearTimeout(idle);
    };

    const expiry = el('p', { class: 'expiry' });
    runCountdown(expiry, data.expires_at, sync);
    showScreen(
      main,
      el('h1', { class: 'page-title' }, t('page.read.title')),
      consumedKey === null ? null : el('p', { class: 'banner', role: 'status' }, t(consumedKey)),
      priorOpens > 0 ? el('p', { class: 'warning', role: 'alert' }, tn('read.priorOpens', priorOpens)) : null,
      el('p', { class: 'hint' }, t('read.decryptedLocally')),
      expiry,
      controls,
      container,
      hiddenNotice,
      el('div', { class: 'field autohide-field' }, el('label', { for: autoHide.id }, t('read.autoHideLabel')), autoHide),
      el('div', { class: 'action-bar' }, copyAll, hideButton, ...(config.allowExport ? [exportButton(() => envelope.text)] : []), ...(config.allowPrint ? [printButton()] : []), newLink()),
    );
    if (view.hidden) setHidden(true);
  }

  void start();
  return () => redraw?.();
}
