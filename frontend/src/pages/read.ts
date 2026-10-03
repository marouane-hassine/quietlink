// SPDX-License-Identifier: AGPL-3.0-or-later

/** Reading page (§5.1 "Écran de lecture", §6.2, §6.3.1, parcours B). */

import { api, ApiError, retrying, type OpenResponse, type StatusResponse } from '../api';
import { clearReservation, loadReservation, saveReservation } from '../reservation';
import { parse, type ParsedAad } from '../crypto/aad';
import { decode, EncodingError } from '../crypto/base64url';
import { equal, randomBytes, wipe } from '../crypto/bytes';
import { encode } from '../crypto/base64url';
import { argon2Supported, deriveInWorker } from '../crypto/argon2-client';
import { parseEnvelope, byteLength, type Envelope } from '../crypto/envelope';
import { DecryptionError } from '../crypto/primitives';
import { accessPublicKey, accessSeed, checkConsumeKey, decrypt, matchesAccessKey, prove, WrongPassphraseError } from '../crypto/protocol';
import type { PublicConfig } from '../config';
import { t } from '../i18n';
import { HIGHLIGHT_LIMIT_BYTES, highlightToHtml } from '../render/highlight';
import { renderMarkdown } from '../render/markdown';
import { renderTemplateView } from '../render/template-view';
import { parseTemplateText } from '../templates';
import { announce, toast } from '../ui/announcer';
import { copyText } from '../ui/clipboard';
import { crossedThreshold, nextTickMs, remainingAt, synchronise, type Sync } from '../ui/countdown';
import { el, nextId, showScreen } from '../ui/dom';
import { formatDate, formatRelative } from '../ui/format';
import { cryptoAvailable } from './create';
import DOMPurify from 'dompurify';

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

  const fail = (messageKey: string, values: Record<string, string | number> = {}) => {
    const message = t(messageKey, values);
    showScreen(main, el('h1', { class: 'page-title' }, t('page.read.title')), el('p', { class: 'error', role: 'alert' }, message), newLink());
    announce(message, true);
  };

  const newLink = () => el('a', { href: '/', class: 'button button-secondary' }, t('action.new'));

  const proofBody = async (link: Awaited<ReturnType<typeof parseLink>>, usage: 'open' | 'status', extra: Record<string, string> = {}) => {
    const challenge = challenges?.[usage] ?? (await api.challenge(link.id, usage));
    if (challenges) challenges = { ...challenges, [usage]: '' } as typeof challenges;
    if (challenges && !challenges[usage]) challenges = null;
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
      if (error.kind === 'reserved') return fail('error.reserved', { seconds: error.retryAfter ?? 60 });
      return fail(({ network: 'error.network', rate: 'error.rateLimited', unavailable: 'error.unavailable' } as Record<string, string>)[error.kind] ?? 'error.server');
    }
    if (error instanceof DecryptionError) return fail('error.integrity');
    return fail('error.server');
  };

  async function start(): Promise<void> {
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

  function showReveal(link: Awaited<ReturnType<typeof parseLink>>, aad: ParsedAad, status: StatusResponse, sync: Sync | null, initialError = ''): void {
    const needsPassphrase = aad.object.kdf !== null;
    const inputId = nextId('passphrase');
    const input = el('input', { id: inputId, class: 'passphrase is-masked', type: 'text', autocomplete: 'off', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false' });
    if (!(typeof CSS !== 'undefined' && CSS.supports('-webkit-text-security', 'disc'))) input.type = 'password';
    const error = el('p', { class: 'field-error', role: 'alert', id: nextId('error') });
    input.setAttribute('aria-describedby', error.id);
    const button = el('button', { type: 'button', class: 'button button-primary' }, t('read.reveal'));
    const statusLine = el('p', { class: 'status', role: 'status' });
    const resumable = loadReservation(link.id, Date.now());

    button.addEventListener('click', async () => {
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
          showReveal(link, aad, status, sync, t('error.wrongPassphrase'));
          return;
        }
        if (e instanceof Error && e.message === 'argon2') return fail('error.argon2');
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
    startCountdown(expiry, status.expires_at, sync);
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
    if (initialError !== '') {
      error.textContent = initialError;
      announce(initialError, true);
    }
    if (needsPassphrase) input.focus();
  }

  async function openAndShow(link: Awaited<ReturnType<typeof parseLink>>, aad: ParsedAad, kPass: Uint8Array | null, consumeSeed: Uint8Array | null, sync: Sync | null, reservationId: string | null = null): Promise<void> {
    showScreen(main, el('h1', { class: 'page-title' }, t('page.read.title')), el('p', { class: 'status', role: 'status' }, t('state.opening')));
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

    let consumedNotice: HTMLElement | null = null;
    if (aad.object.read_once && reservationId && consumeSeed && data.consume_challenge) {
      saveReservation(link.id, reservationId, Date.now(), data.retry_after ?? RESERVATION_MAX_SECONDS);
      // The same signature is resent after network errors, never re-signed (§12.3).
      const body = { access_pk: encode(link.accessPk), reservation_id: reservationId, challenge: data.consume_challenge, signature: await prove(consumeSeed, data.consume_challenge) };
      try {
        await retrying(() => api.consume(link.id, body));
        consumedNotice = el('p', { class: 'banner', role: 'status' }, t('read.destroyed'));
        clearReservation(link.id);
      } catch (error) {
        consumedNotice = el('p', { class: 'banner', role: 'status' }, t('read.consumeFailed'));
        if (!(error instanceof ApiError && error.kind === 'network')) clearReservation(link.id);
      }
      window.addEventListener('beforeunload', (event) => {
        event.preventDefault();
        event.returnValue = t('read.leaveWarning');
      });
    }
    showContent(envelope, data, consumedNotice, sync ?? (data.expires_at === null ? null : synchronise(data.expires_at, data.server_time, opened.t0, opened.t1)), data.unconfirmed_opens ?? 0);
  }

  function showContent(envelope: Envelope, data: OpenResponse, consumedNotice: HTMLElement | null, sync: Sync | null, priorOpens: number): void {
    const container = el('div', { class: 'reader', tabindex: '0', 'aria-label': t('page.read.title') });
    const large = byteLength(envelope.text) > HIGHLIGHT_LIMIT_BYTES;
    const template = envelope.template !== null && envelope.format === 'markdown' && !large ? parseTemplateText(envelope.text) : null;
    let viewSwitch: HTMLElement | null = null;
    if (template) {
      // Field view by default: per-field copy and masked sensitive values (§0.3).
      const fieldsButton = el('button', { type: 'button', class: 'chip', 'aria-pressed': 'true' }, t('tpl.view.fields'));
      const markdownButton = el('button', { type: 'button', class: 'chip', 'aria-pressed': 'false' }, t('tpl.view.markdown'));
      const show = (fields: boolean) => {
        container.replaceChildren(fields ? renderTemplateView(template) : renderMarkdown(envelope.text));
        container.classList.toggle('markdown', !fields);
        fieldsButton.setAttribute('aria-pressed', String(fields));
        markdownButton.setAttribute('aria-pressed', String(!fields));
      };
      fieldsButton.addEventListener('click', () => show(true));
      markdownButton.addEventListener('click', () => show(false));
      viewSwitch = el('div', { class: 'presets', role: 'group', 'aria-label': t('tpl.view.label') }, fieldsButton, markdownButton);
      container.append(renderTemplateView(template));
    } else if (envelope.format === 'markdown' && !large) {
      container.append(renderMarkdown(envelope.text));
      container.classList.add('markdown');
    } else if (envelope.format === 'code' && !large && envelope.language && highlightToHtml('', envelope.language) !== null) {
      const code = el('code', { class: `hljs language-${envelope.language}` });
      code.append(DOMPurify.sanitize(highlightToHtml(envelope.text, envelope.language) ?? '', { ALLOWED_TAGS: ['span'], ALLOWED_ATTR: ['class'], RETURN_DOM_FRAGMENT: true }));
      container.append(el('pre', {}, code));
    } else {
      container.append(el('pre', { class: 'plain' }, envelope.text));
    }
    for (const pre of container.querySelectorAll('pre')) {
      const blockText = pre.textContent ?? '';
      const copyBlock = el('button', { type: 'button', class: 'button button-tertiary copy-block' }, t('read.copyBlock'));
      copyBlock.addEventListener('click', async () => {
        if (await copyText(blockText)) toast(t('read.clipboardAdvice'));
      });
      pre.before(copyBlock);
    }

    const hiddenNotice = el('p', { class: 'hint', hidden: true }, t('read.hidden'));
    const hideButton = el('button', { type: 'button', class: 'button button-secondary', 'aria-pressed': 'false' }, t('read.hide'));
    const setHidden = (hidden: boolean) => {
      container.classList.toggle('is-hidden', hidden);
      container.setAttribute('aria-hidden', String(hidden));
      hiddenNotice.hidden = !hidden;
      hideButton.textContent = hidden ? t('read.show') : t('read.hide');
      hideButton.setAttribute('aria-pressed', String(hidden));
      if (hidden) window.getSelection()?.removeAllRanges();
    };
    hideButton.addEventListener('click', () => setHidden(!container.classList.contains('is-hidden')));
    const copyAll = el('button', { type: 'button', class: 'button button-primary' }, t('action.copy'));
    copyAll.addEventListener('click', async () => {
      if (await copyText(envelope.text)) toast(t('read.clipboardAdvice'));
    });

    let keepVisible = false;
    let idle = window.setTimeout(() => setHidden(true), AUTO_HIDE_MS);
    const activity = () => {
      window.clearTimeout(idle);
      if (!keepVisible) idle = window.setTimeout(() => setHidden(true), AUTO_HIDE_MS);
    };
    for (const type of ['pointerdown', 'keydown', 'scroll', 'touchstart']) document.addEventListener(type, activity, { passive: true });
    const keep = el('input', { type: 'checkbox', id: nextId('keep') });
    keep.addEventListener('change', () => {
      keepVisible = keep.checked;
      activity();
    });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') setHidden(true);
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') setHidden(true);
    });

    const expiry = el('p', { class: 'expiry' });
    startCountdown(expiry, data.expires_at, sync);
    showScreen(
      main,
      el('h1', { class: 'page-title' }, t('page.read.title')),
      consumedNotice,
      priorOpens > 0 ? el('p', { class: 'warning', role: 'alert' }, t('read.priorOpens', { count: priorOpens })) : null,
      el('p', { class: 'hint' }, t('read.decryptedLocally')),
      expiry,
      viewSwitch,
      container,
      hiddenNotice,
      el('p', { class: 'hint' }, t('read.autoHide'), ' ', keep, el('label', { for: keep.id }, t('read.keepVisible'))),
      el('div', { class: 'action-bar' }, copyAll, hideButton, newLink()),
    );
  }

  function startCountdown(node: HTMLElement, expiresAt: string | null, sync: Sync | null): void {
    if (expiresAt === null || sync === null) {
      node.textContent = t('result.never');
      return;
    }
    let previous = Number.POSITIVE_INFINITY;
    const tick = () => {
      const remaining = remainingAt(sync, performance.now());
      node.textContent = remaining <= 0 ? t('time.expired') : t('result.expires', { relative: (sync.approximate ? `${t('time.approximate')} ` : '') + formatRelative(remaining), date: formatDate(Date.parse(expiresAt)) });
      if (crossedThreshold(previous, remaining) !== null) announce(node.textContent);
      previous = remaining;
      if (remaining > 0 && node.isConnected) window.setTimeout(tick, nextTickMs(remaining));
    };
    tick();
  }

  void start();
  return () => undefined;
}
