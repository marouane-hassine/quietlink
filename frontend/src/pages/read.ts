// SPDX-License-Identifier: AGPL-3.0-or-later

/** Reading page (§5.1 "Écran de lecture", §6.2, §6.3.1, parcours B). */

import { api, ApiError, retrying, type OpenResponse, type StatusResponse } from '../api';
import { clearExpiredReservations, clearReservation, loadReservation, saveReservation } from '../reservation';
import { parse, type ParsedAad } from '../crypto/aad';
import { decode, EncodingError } from '../crypto/base64url';
import { equal, randomBytes, wipe } from '../crypto/bytes';
import { encode } from '../crypto/base64url';
import { Argon2UnavailableError, argon2Supported, deriveInWorker, preloadArgon2 } from '../crypto/argon2-client';
import { parseEnvelope, type Envelope } from '../crypto/envelope';
import { DecryptionError } from '../crypto/primitives';
import { accessPublicKey, accessSeed, checkConsumeKey, decrypt, matchesAccessKey, prove, WrongPassphraseError } from '../crypto/protocol';
import type { PublicConfig } from '../config';
import type { buildContentView } from '../render/content-view';

type BuildContentView = typeof buildContentView;
import { t } from '../i18n';
import { exportButton, printButton } from '../ui/local-output';
import { announce } from '../ui/announcer';
import { copyText } from '../ui/clipboard';
import { synchronise, type Sync } from '../ui/countdown';
import { runCountdown } from '../ui/expiry-view';
import { el, focusUnlessRedrawing, nextId, showScreen } from '../ui/dom';
import { holdRetry } from '../ui/retry-delay';
import { formatDate, formatRelative } from '../ui/format';
import { cryptoAvailable } from '../ui/capabilities';

const RESERVATION_MAX_SECONDS = 300;
const AUTO_HIDE_MS = 120_000;

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
  let challenges = config.challenges ?? null;

  /** Redraws the current screen in a new language; null while busy or once content is shown. */
  let redraw: (() => void) | null = null;

  const fail = (messageKey: string, values: Record<string, string | number> = {}, retry: (() => void) | null = null, retryAfter: number | null = null) => {
    redraw = () => fail(messageKey, values, retry, retryAfter);
    const message = t(messageKey, values);
    let retryButton: HTMLButtonElement | null = null;
    if (retry) {
      retryButton = el('button', { type: 'button', class: 'button button-primary' }, t('action.retry'));
      retryButton.addEventListener('click', retry);
      holdRetry(retryButton, retryAfter);
    }
    showScreen(main, el('h1', { class: 'page-title' }, t('page.read.title')), el('p', { class: 'error', role: 'alert' }, message), el('div', { class: 'action-bar' }, ...[retryButton, newLink()].filter((n): n is NonNullable<typeof n> => n !== null)));
    announce(message, true);
  };

  const newLink = () => el('a', { href: '/', class: 'button button-secondary' }, t('action.new'));

  const proofBody = async (link: Awaited<ReturnType<typeof parseLink>>, usage: 'open' | 'status', extra: Record<string, string> = {}) => {
    // Each embedded challenge is used once, then fresh ones are requested (§6.3.1).
    const embedded = challenges?.[usage] ?? '';
    if (challenges) challenges = { ...challenges, [usage]: '' };
    const challenge = embedded !== '' ? embedded : await api.challenge(link.id, usage);
    const seed = await accessSeed(link.urlKey);
    try {
      return { challenge, access_pk: encode(link.accessPk), signature: await prove(seed, challenge), ...extra };
    } finally {
      wipe(seed);
    }
  };

  /** One transparent retry with a fresh challenge on 404 (§6.3.1). */
  const withRetry = async <T,>(call: () => Promise<T>): Promise<T> => {
    try {
      return await call();
    } catch (error) {
      if (error instanceof ApiError && error.kind === 'unavailable') {
        challenges = null;
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
      const recoverable = error.kind === 'network' || error.kind === 'rate' || error.kind === 'server' || error.kind === 'quota';
      return fail(({ network: 'error.networkRead', rate: 'error.rateLimited', unavailable: 'error.unavailable' } as Record<string, string>)[error.kind] ?? 'error.server', {}, recoverable ? () => void start() : null, error.retryAfter);
    }
    if (error instanceof DecryptionError) return fail('error.integrity');
    return fail('error.server');
  };

  async function start(): Promise<void> {
    clearExpiredReservations(Date.now());
    if (!cryptoAvailable()) return fail('app.unsupported');
    showScreen(main, el('h1', { class: 'page-title' }, t('page.read.title')), el('p', { class: 'status', role: 'status' }, t('state.checking')));
    try {
      const link = await parseLink();
      const status = await withRetry(async () => api.status(link.id, await proofBody(link, 'status')));
      const aad = parse(decode(status.data.aad));
      if (!equal(aad.accessPk, link.accessPk)) throw new DecryptionError('integrity');
      const sync = status.data.expires_at === null ? null : synchronise(status.data.expires_at, status.data.server_time, status.t0, status.t1);
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
    const input = el('input', { id: inputId, class: 'passphrase is-masked', type: 'text', autocomplete: 'off', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false' });
    if (!(typeof CSS !== 'undefined' && CSS.supports('-webkit-text-security', 'disc'))) input.type = 'password';
    input.addEventListener('focus', preloadArgon2, { once: true });
    const error = el('p', { class: 'field-error', role: 'alert', id: nextId('error') });
    input.setAttribute('aria-describedby', error.id);
    const button = el('button', { type: 'button', class: 'button button-primary' }, t('read.reveal'));
    const statusLine = el('p', { class: 'status', role: 'status' });
    const resumable = loadReservation(link.id, Date.now());

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
          announce(t('state.deriving'));
          kPass = await deriveInWorker(input.value, aad.salt, kdf.m, kdf.t);
        }
        // Passphrase verified locally before any reservation (§6.3.1 step 0).
        consumeSeed = await checkConsumeKey(aad, link.urlKey, kPass);
        let reservationId: string | null = null;
        if (aad.object.read_once) {
          reservationId = resumable ?? encode(randomBytes(16));
          // Stored before open (§6.3.1); refined with the real remaining time after open.
          saveReservation(link.id, reservationId, Date.now(), RESERVATION_MAX_SECONDS);
        }
        await openAndShow(link, aad, kPass, consumeSeed, sync, reservationId);
      } catch (e) {
        button.disabled = false;
        statusLine.textContent = '';
        if (e instanceof WrongPassphraseError) {
          showReveal(link, aad, status, sync, 'error.wrongPassphrase');
          return;
        }
        if (e instanceof Argon2UnavailableError || (e instanceof Error && e.message === 'argon2')) return fail('error.argon2');
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
      opens > 0 ? el('p', { class: 'warning', role: 'alert' }, t('read.priorOpens', { count: opens })) : null,
      needsPassphrase ? el('div', { class: 'field' }, el('label', { for: inputId }, t('read.passphraseRequired')), input, error) : error,
      el('div', { class: 'action-bar' }, button, statusLine),
    );
    if (errorKey !== null) {
      error.textContent = t(errorKey);
      announce(error.textContent, true);
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
    let envelope: Envelope;
    try {
      envelope = parseEnvelope(await decrypt(link.urlKey, kPass, aad, decode(data.nonce, 12), decode(data.ciphertext)));
    } catch (error) {
      if (aad.object.kdf !== null && !aad.object.read_once) {
        throw new WrongPassphraseError();
      }
      throw error instanceof DecryptionError ? error : new DecryptionError('integrity');
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
      } catch (error) {
        consumedKey = 'read.consumeFailed';
        // Only a final 404 ends the reservation: after a transient failure (network, 429, 5xx)
        // a reload of this tab resumes it instead of being locked out (§6.3.1).
        if (error instanceof ApiError && error.kind === 'unavailable') clearReservation(link.id);
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
  function showContent(buildContentView: BuildContentView, envelope: Envelope, data: OpenResponse, consumedKey: string | null, sync: Sync | null, priorOpens: number, view = { hidden: false, keepVisible: false }): void {
    teardownContent?.();
    const listeners = new AbortController();
    const { signal } = listeners;
    redraw = () => showContent(buildContentView, envelope, data, consumedKey, sync, priorOpens, view);
    const { container, controls } = buildContentView(envelope, { wifiQr: config.enableQrCode });

    const hiddenNotice = el('p', { class: 'hint', hidden: true }, t('read.hidden'));
    const hideButton = el('button', { type: 'button', class: 'button button-secondary', 'aria-pressed': 'false' }, t('read.hide'));
    const setHidden = (hidden: boolean) => {
      view.hidden = hidden;
      container.classList.toggle('is-hidden', hidden);
      container.setAttribute('aria-hidden', String(hidden));
      container.toggleAttribute('inert', hidden);
      hiddenNotice.hidden = !hidden;
      hideButton.textContent = hidden ? t('read.show') : t('read.hide');
      hideButton.setAttribute('aria-pressed', String(hidden));
      if (hidden) window.getSelection()?.removeAllRanges();
    };
    hideButton.addEventListener('click', () => setHidden(!container.classList.contains('is-hidden')));
    const copyAll = el('button', { type: 'button', class: 'button button-primary' }, t('action.copy'));
    copyAll.addEventListener('click', () => void copyText(envelope.text, t('read.copied')));

    let idle = 0;
    const activity = () => {
      window.clearTimeout(idle);
      if (!view.keepVisible) idle = window.setTimeout(() => setHidden(true), AUTO_HIDE_MS);
    };
    activity();
    for (const type of ['pointerdown', 'keydown', 'scroll', 'touchstart']) document.addEventListener(type, activity, { passive: true, signal });
    const keep = el('input', { type: 'checkbox', id: nextId('keep'), checked: view.keepVisible });
    keep.addEventListener('change', () => {
      view.keepVisible = keep.checked;
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
      priorOpens > 0 ? el('p', { class: 'warning', role: 'alert' }, t('read.priorOpens', { count: priorOpens })) : null,
      el('p', { class: 'hint' }, t('read.decryptedLocally')),
      expiry,
      controls,
      container,
      hiddenNotice,
      el('p', { class: 'hint' }, t('read.autoHide'), ' ', keep, el('label', { for: keep.id }, t('read.keepVisible'))),
      el('div', { class: 'action-bar' }, copyAll, hideButton, ...(config.allowExport ? [exportButton(() => envelope.text)] : []), ...(config.allowPrint ? [printButton()] : []), newLink()),
    );
    if (view.hidden) setHidden(true);
  }

  void start();
  return () => redraw?.();
}
