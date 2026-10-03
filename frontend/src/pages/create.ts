// SPDX-License-Identifier: AGPL-3.0-or-later

/** Creation page (§5.1, §6.1, parcours A) and result screen. */

import { api, ApiError } from '../api';
import { encode } from '../crypto/base64url';
import { wipe } from '../crypto/bytes';
import type { Expiration } from '../crypto/constants';
import { argon2Supported, deriveInWorker } from '../crypto/argon2-client';
import { byteLength, serialize, type Format } from '../crypto/envelope';
import { matchesAccessKey, matchesDeletionToken, prepare, type PreparedPaste } from '../crypto/protocol';
import { decode } from '../crypto/base64url';
import type { PublicConfig } from '../config';
import { t } from '../i18n';
import { LANGUAGE_IDS } from '../render/highlight';
import { renderTemplate } from '../templates';
import { announce, toast } from '../ui/announcer';
import { canReadClipboard, copyText } from '../ui/clipboard';
import { confirmInline } from '../ui/confirm';
import { crossedThreshold, nextTickMs, remainingAt, synchronise, type Sync } from '../ui/countdown';
import { el, nextId, showScreen } from '../ui/dom';
import { formatBytes, formatDate, formatRelative } from '../ui/format';
import { generate, strength, wordlist } from '../ui/passphrase';
import { qrSvg } from '../ui/qrcode';
import { locale } from '../i18n';

interface State {
  text: string;
  format: Format;
  language: string;
  template: string;
  expiration: Expiration;
  readOnce: boolean;
  usePassphrase: boolean;
  passphrase: string;
  confirmation: string;
  passphraseVisible: boolean;
  generated: boolean;
}

export function cryptoAvailable(): boolean {
  return typeof crypto !== 'undefined' && typeof crypto.subtle !== 'undefined' && window.isSecureContext;
}

export function mountCreate(main: HTMLElement, config: PublicConfig): () => void {
  const state: State = {
    text: '',
    format: 'plain',
    language: '',
    template: '',
    expiration: config.defaultExpiration,
    readOnce: false,
    usePassphrase: false,
    passphrase: '',
    confirmation: '',
    passphraseVisible: false,
    generated: false,
  };
  let busy = false;
  let inResult = false;
  let pending: PreparedPaste | null = null;
  let unloadGuard: ((event: BeforeUnloadEvent) => void) | null = null;

  const setUnloadGuard = (active: boolean, message: string) => {
    if (unloadGuard) window.removeEventListener('beforeunload', unloadGuard);
    unloadGuard = null;
    if (active) {
      unloadGuard = (event) => {
        event.preventDefault();
        event.returnValue = message;
      };
      window.addEventListener('beforeunload', unloadGuard);
    }
  };

  const envelopeSize = () => byteLength(serialize({ format: state.format, language: state.format === 'code' && state.language ? state.language : null, template: state.template || null, text: state.text }));

  const disabledReason = (): string | null => {
    if (!cryptoAvailable()) return t('disabled.crypto');
    if (state.text.trim() === '') return t('disabled.empty');
    if (envelopeSize() > config.maxEnvelopeBytes) return t('disabled.tooLarge');
    if (state.usePassphrase && (state.passphrase === '' || (!state.passphraseVisible && !state.generated && state.passphrase !== state.confirmation))) return t('disabled.passphrase');
    return null;
  };

  function render(): void {
    inResult = false;
    const editorId = nextId('editor');
    const hintId = nextId('hint');
    const editor = el('textarea', {
      id: editorId,
      class: 'editor',
      placeholder: t('editor.placeholder'),
      spellcheck: 'false',
      autocomplete: 'off',
      autocapitalize: 'off',
      autocorrect: 'off',
      'aria-describedby': hintId,
      rows: '12',
    });
    editor.value = state.text;
    const emptyHint = el('p', { id: hintId, class: 'hint' }, state.text === '' ? t('editor.empty') : '');

    const sizeLine = el('p', { class: 'size', 'aria-live': 'polite' });
    const gauge = el('div', { class: 'gauge', 'aria-hidden': 'true' }, el('div', { class: 'gauge-bar' }));
    const reason = el('p', { class: 'disabled-reason', id: nextId('reason') });
    const submit = el('button', { type: 'button', class: 'button button-primary', 'aria-describedby': reason.id }, t('action.create'));
    const summary = el('p', { class: 'summary' });

    const refresh = () => {
      state.text = editor.value;
      emptyHint.textContent = state.text === '' ? t('editor.empty') : '';
      const used = envelopeSize();
      const ratio = used / config.maxEnvelopeBytes;
      const visible = ratio >= 0.8;
      sizeLine.hidden = !visible;
      gauge.hidden = !visible;
      sizeLine.textContent = t(ratio > 1 ? 'size.tooLarge' : 'size.label', { used: formatBytes(used), limit: formatBytes(config.maxEnvelopeBytes) });
      gauge.dataset.level = ratio > 1 ? 'over' : ratio > 0.95 ? 'high' : 'near';
      const bar = gauge.firstElementChild as HTMLElement | null;
      bar?.style.setProperty('inline-size', `${Math.min(100, Math.round(ratio * 100))}%`);
      const why = disabledReason();
      submit.disabled = busy || why !== null;
      reason.textContent = busy ? '' : why ?? '';
      const parts = [t('summary.expires', { duration: t(`expiration.${state.expiration}`) })];
      if (state.readOnce) parts.push(t('summary.readOnce'));
      if (state.usePassphrase) parts.push(t('summary.passphrase'));
      summary.replaceChildren(el('span', {}, `${t('summary.label')} : ${parts.join(' · ')}`), ' ', changeButton);
      setUnloadGuard(state.text !== '', t('editor.empty'));
    };

    editor.addEventListener('input', refresh);
    editor.addEventListener('dragover', (event) => {
      if (event.dataTransfer?.types.includes('Files')) event.preventDefault();
    });
    editor.addEventListener('drop', (event) => {
      if (event.dataTransfer?.types.includes('Files')) {
        event.preventDefault();
        announce(t('editor.dropRefused'), true);
        toast(t('editor.dropRefused'));
      }
    });

    const pasteButton = el('button', { type: 'button', class: 'button button-secondary' }, t('editor.paste'));
    pasteButton.hidden = !canReadClipboard() || state.text !== '';
    pasteButton.addEventListener('click', async () => {
      try {
        const text = await navigator.clipboard.readText();
        editor.value = text;
        refresh();
        editor.focus();
      } catch {
        toast(t('editor.pasteDenied'));
      }
    });
    editor.addEventListener('input', () => {
      pasteButton.hidden = !canReadClipboard() || editor.value !== '';
    });

    // Format, language and template.
    const formatSelect = el('select', { id: nextId('format') });
    for (const format of ['plain', 'markdown', 'code'] as const) formatSelect.append(el('option', { value: format, selected: format === state.format }, t(`format.${format}`)));
    const languageSelect = el('select', { id: nextId('language') });
    languageSelect.append(el('option', { value: '' }, t('language.auto')));
    for (const id of LANGUAGE_IDS) languageSelect.append(el('option', { value: id, selected: id === state.language }, id));
    const languageField = el('div', { class: 'field' }, el('label', { for: languageSelect.id }, t('language.label')), languageSelect);
    languageField.hidden = state.format !== 'code';
    formatSelect.addEventListener('change', () => {
      state.format = formatSelect.value as Format;
      languageField.hidden = state.format !== 'code';
      refresh();
    });
    languageSelect.addEventListener('change', () => {
      state.language = languageSelect.value;
      refresh();
    });

    const templateSelect = el('select', { id: nextId('template') });
    templateSelect.append(el('option', { value: '' }, t('template.none')));
    for (const id of config.templates) templateSelect.append(el('option', { value: id, selected: id === state.template }, t(`template.${id}`)));
    // Text typed before the first applied template, restored by the single Undo action.
    let textBeforeTemplates: string | null = null;
    let undoLine: HTMLElement | null = null;
    templateSelect.addEventListener('change', async () => {
      const id = templateSelect.value;
      if (!id) {
        state.template = '';
        refresh();
        return;
      }
      const previous = editor.value;
      if (previous.trim() !== '' && !(await confirmInline(templateSelect, t('template.confirmReplace'), t('template.label')))) {
        // Only revert when no newer choice replaced this one meanwhile.
        if (templateSelect.value === id) templateSelect.value = state.template;
        return;
      }
      textBeforeTemplates ??= previous;
      const restored = textBeforeTemplates;
      editor.value = renderTemplate(id);
      state.template = id;
      state.format = 'markdown';
      formatSelect.value = 'markdown';
      languageField.hidden = true;
      refresh();
      const undo = el('button', { type: 'button', class: 'link-button' }, t('template.undo'));
      undo.addEventListener('click', () => {
        editor.value = restored;
        state.template = '';
        templateSelect.value = '';
        textBeforeTemplates = null;
        refresh();
        undoLine?.remove();
        undoLine = null;
        editor.focus();
      });
      undoLine?.remove();
      undoLine = el('p', { class: 'inline-notice' }, t('template.applied'), ' ', undo);
      templateRow.after(undoLine);
      announce(t('template.applied'));
    });

    // Expiration, read-once, passphrase.
    const expirationSelect = el('select', { id: nextId('expiration') });
    for (const code of config.expirations) expirationSelect.append(el('option', { value: code, selected: code === state.expiration }, t(`expiration.${code}`)));
    expirationSelect.addEventListener('change', () => {
      state.expiration = expirationSelect.value as Expiration;
      refresh();
    });

    const readOnce = el('input', { type: 'checkbox', id: nextId('read-once'), checked: state.readOnce });
    readOnce.addEventListener('change', () => {
      state.readOnce = readOnce.checked;
      refresh();
    });

    const passphraseAllowed = config.allowPassphrase && argon2Supported();
    const usePassphrase = el('input', { type: 'checkbox', id: nextId('use-passphrase'), checked: state.usePassphrase, disabled: !passphraseAllowed });
    const passphrasePanel = buildPassphrasePanel(refresh);
    passphrasePanel.hidden = !state.usePassphrase;
    usePassphrase.addEventListener('change', () => {
      state.usePassphrase = usePassphrase.checked;
      passphrasePanel.hidden = !state.usePassphrase;
      refresh();
    });

    const presets = el('div', { class: 'presets', role: 'group', 'aria-label': t('preset.label') });
    const applyPreset = (expiration: Expiration, once: boolean) => {
      const allowed: Expiration[] = config.expirations.filter((c) => c !== 'never');
      state.expiration = allowed.includes(expiration) ? expiration : (allowed[0] ?? config.defaultExpiration);
      state.readOnce = once && config.allowReadOnce;
      expirationSelect.value = state.expiration;
      readOnce.checked = state.readOnce;
      refresh();
      announce(`${t('summary.label')} : ${t(`expiration.${state.expiration}`)}`);
    };
    if (config.allowReadOnce) {
      const secret = el('button', { type: 'button', class: 'chip' }, t('preset.secret'));
      secret.addEventListener('click', () => applyPreset('1h', true));
      presets.append(secret);
    }
    const share = el('button', { type: 'button', class: 'chip' }, t('preset.share'));
    share.addEventListener('click', () => applyPreset('1d', false));
    presets.append(share);

    const options = el(
      'details',
      { class: 'options', id: nextId('options') },
      el('summary', {}, t('options.title')),
      presets,
      el('div', { class: 'field' }, el('label', { for: expirationSelect.id }, t('options.expiration')), expirationSelect),
      config.allowReadOnce ? el('div', { class: 'field field-check' }, readOnce, el('label', { for: readOnce.id }, t('options.readOnce'))) : null,
      el('div', { class: 'field field-check' }, usePassphrase, el('label', { for: usePassphrase.id }, t('options.passphrase'))),
      passphraseAllowed ? null : el('p', { class: 'hint' }, t('passphrase.unsupported')),
      passphrasePanel,
    );

    const changeButton = el('button', { type: 'button', class: 'link-button' }, t('summary.change'));
    changeButton.addEventListener('click', () => {
      options.open = true;
      expirationSelect.focus();
    });

    const templateRow = el(
      'div',
      { class: 'row' },
      el('div', { class: 'field' }, el('label', { for: formatSelect.id }, t('format.label')), formatSelect),
      languageField,
      el('div', { class: 'field' }, el('label', { for: templateSelect.id }, t('template.label')), templateSelect),
    );

    const sent = el('details', { class: 'sent' }, el('summary', {}, t('sent.title')), el('p', {}, t('sent.body')));
    const shortcuts = el('details', { class: 'shortcuts' }, el('summary', {}, t('shortcuts.title')), el('p', {}, t('shortcuts.body')));
    const status = el('p', { class: 'status', role: 'status' });
    const errorBox = el('div', { class: 'error-box', hidden: true });

    const doSubmit = async (retry = false) => {
      if (busy || (!retry && disabledReason() !== null)) return;
      busy = true;
      submit.textContent = t('action.creating');
      errorBox.hidden = true;
      refresh();
      try {
        if (!retry || pending === null) {
          if (state.usePassphrase) {
            status.textContent = t('state.deriving');
            announce(t('state.deriving'));
          }
          const envelope = serialize({ format: state.format, language: state.format === 'code' && state.language ? state.language : null, template: state.template || null, text: state.text });
          pending = await prepare({
            envelope,
            expiration: state.expiration,
            readOnce: state.readOnce,
            passphrase: state.usePassphrase ? state.passphrase : null,
            kdf: config.kdf,
            derive: (p, salt, m, tt) => {
              status.textContent = t('state.deriving');
              return deriveInWorker(p, salt, m, tt);
            },
          });
          status.textContent = t('state.encrypting');
        }
        status.textContent = t('state.sending');
        announce(t('state.sending'));
        const response = await api.create(pending.json, pending.idempotencyKey);
        const id = decode(response.data.id, 24);
        if (!(await matchesAccessKey(id, pending.accessPk)) || !(await matchesDeletionToken(id, pending.deletionToken))) throw new ApiError('server');
        const prepared = pending;
        pending = null;
        setUnloadGuard(false, '');
        const sync = response.data.expires_at === null ? null : synchronise(response.data.expires_at, response.data.server_time, response.t0, response.t1);
        showResult(response.data.id, prepared, response.data.expires_at, sync);
      } catch (error) {
        busy = false;
        submit.textContent = t('action.create');
        status.textContent = '';
        const kind = error instanceof ApiError ? error.kind : 'server';
        const message = t(({ network: 'error.network', rate: 'error.rateLimited', quota: 'error.quota', refused: 'error.refused', tooLarge: 'error.tooLarge' } as Record<string, string>)[kind] ?? 'error.server');
        const retryButton = el('button', { type: 'button', class: 'button button-secondary' }, t('action.retry'));
        retryButton.addEventListener('click', () => void doSubmit(true));
        const cancelButton = el('button', { type: 'button', class: 'button button-tertiary' }, t('action.cancel'));
        cancelButton.addEventListener('click', () => {
          // A new attempt must use a new key and a new link (§10).
          pending = null;
          errorBox.hidden = true;
          refresh();
        });
        errorBox.replaceChildren(...[el('p', {}, message), pending !== null && kind !== 'refused' && kind !== 'tooLarge' ? retryButton : null, cancelButton].filter((n): n is NonNullable<typeof n> => n !== null));
        errorBox.hidden = false;
        announce(message, true);
        refresh();
      }
    };
    submit.addEventListener('click', () => void doSubmit(false));

    const onKey = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        void doSubmit(false);
      } else if (event.key === 'Escape') {
        if (options.open) options.open = false;
        else if (sent.open) sent.open = false;
      }
    };
    main.addEventListener('keydown', onKey);

    showScreen(
      main,
      el('h1', { class: 'page-title' }, t('page.create.title')),
      el('p', { class: 'lead' }, t('app.tagline')),
      el('div', { class: 'field' }, el('label', { for: editorId, class: 'field-label' }, t('editor.label')), editor, emptyHint, pasteButton),
      templateRow,
      options,
      sent,
      el('div', { class: 'action-bar' }, sizeLine, gauge, summary, submit, reason, status),
      errorBox,
      shortcuts,
    );
    refresh();
    if (window.matchMedia?.('(pointer: fine)').matches) editor.focus();
  }

  function buildPassphrasePanel(refresh: () => void): HTMLElement {
    const inputId = nextId('passphrase');
    const hintId = nextId('passphrase-hint');
    const input = el('input', { id: inputId, class: 'passphrase', type: 'text', autocomplete: 'off', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false', 'aria-describedby': hintId });
    const confirmInput = el('input', { id: nextId('confirm'), class: 'passphrase', type: 'text', autocomplete: 'off', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false' });
    const masking = typeof CSS !== 'undefined' && CSS.supports('-webkit-text-security', 'disc');
    const applyMask = () => {
      for (const field of [input, confirmInput]) {
        if (masking) {
          field.type = 'text';
          field.classList.toggle('is-masked', !state.passphraseVisible);
        } else {
          field.type = state.passphraseVisible ? 'text' : 'password';
        }
      }
      confirmField.hidden = state.passphraseVisible || state.generated;
      toggle.textContent = state.passphraseVisible ? t('passphrase.hide') : t('passphrase.show');
      toggle.setAttribute('aria-pressed', String(state.passphraseVisible));
    };
    const strengthLine = el('p', { class: 'hint', 'aria-live': 'polite' });
    const mismatch = el('p', { class: 'field-error', id: nextId('mismatch') });
    confirmInput.setAttribute('aria-describedby', mismatch.id);
    const updateStrength = () => {
      state.passphrase = input.value;
      state.confirmation = confirmInput.value;
      strengthLine.textContent = state.passphrase === '' ? '' : t(`passphrase.strength.${state.generated ? 'strong' : strength(state.passphrase)}`);
      mismatch.textContent = !state.passphraseVisible && !state.generated && state.confirmation !== '' && state.confirmation !== state.passphrase ? t('passphrase.mismatch') : '';
      refresh();
    };
    input.addEventListener('input', () => {
      state.generated = false;
      applyMask();
      updateStrength();
    });
    confirmInput.addEventListener('input', updateStrength);

    const toggle = el('button', { type: 'button', class: 'button button-tertiary' });
    toggle.addEventListener('click', () => {
      state.passphraseVisible = !state.passphraseVisible;
      applyMask();
      updateStrength();
    });
    const generateButton = el('button', { type: 'button', class: 'button button-secondary' }, t('passphrase.generate'));
    generateButton.addEventListener('click', async () => {
      if (input.value !== '' && !state.generated && !(await confirmInline(generateButton, t('passphrase.replaceConfirm'), t('passphrase.generate')))) return;
      input.value = generate(await wordlist(locale()));
      confirmInput.value = '';
      state.generated = true;
      state.passphraseVisible = true;
      applyMask();
      updateStrength();
      toast(t('passphrase.generated'));
    });
    const copyButton = el('button', { type: 'button', class: 'button button-tertiary' }, t('passphrase.copy'));
    copyButton.addEventListener('click', () => void copyText(input.value, t('passphrase.copied')));

    const confirmField = el('div', { class: 'field' }, el('label', { for: confirmInput.id }, t('passphrase.confirm')), confirmInput, mismatch);
    const panel = el(
      'div',
      { class: 'passphrase-panel' },
      el('div', { class: 'field' }, el('label', { for: inputId }, t('passphrase.label')), input, el('p', { id: hintId, class: 'hint' }, t('passphrase.hint')), strengthLine),
      confirmField,
      el('div', { class: 'button-row' }, toggle, generateButton, copyButton),
    );
    input.value = state.passphrase;
    confirmInput.value = state.confirmation;
    applyMask();
    return panel;
  }

  function showResult(id: string, prepared: PreparedPaste, expiresAt: string | null, sync: Sync | null): void {
    inResult = true;
    const shareLink = `${location.origin}/p/${id}#${encode(prepared.urlKey)}`;
    const manageLink = `${location.origin}/manage/${id}#${encode(prepared.deletionToken)}`;
    wipe(prepared.urlKey);
    const usedPassphrase = state.usePassphrase;
    const readOnceMode = state.readOnce;
    state.text = '';
    state.passphrase = '';
    state.confirmation = '';
    state.template = '';
    let manageCopied = false;
    setUnloadGuard(true, t('result.leaveWarning'));

    const linkInput = el('input', { id: nextId('share'), class: 'link-field', type: 'text', readonly: true, value: shareLink, spellcheck: 'false' });
    const copy = el('button', { type: 'button', class: 'button button-primary' }, t('action.copy'));
    copy.addEventListener('click', () => void copyText(shareLink));

    const expiry = el('p', { class: 'expiry' });
    let previous = Number.POSITIVE_INFINITY;
    let timer = 0;
    let hiddenWall = 0;
    let hiddenMono = 0;
    const tick = () => {
      if (!sync || expiresAt === null) {
        expiry.textContent = t('result.never');
        return;
      }
      if (!expiry.isConnected && previous !== Number.POSITIVE_INFINITY) return;
      const remaining = remainingAt(sync, performance.now());
      const date = formatDate(Date.parse(expiresAt));
      expiry.textContent = remaining <= 0 ? t('time.expired') : t('result.expires', { relative: (sync.approximate ? `${t('time.approximate')} ` : '') + formatRelative(remaining), date });
      const crossed = crossedThreshold(previous, remaining);
      if (crossed !== null) announce(expiry.textContent);
      previous = remaining;
      if (remaining > 0) timer = window.setTimeout(tick, nextTickMs(remaining));
    };
    const onVisibility = () => {
      if (!expiry.isConnected) {
        document.removeEventListener('visibilitychange', onVisibility);
        return;
      }
      if (!sync) return;
      if (document.visibilityState === 'hidden') {
        hiddenWall = Date.now();
        hiddenMono = performance.now();
      } else if (hiddenWall > 0) {
        // performance.now() may stop during sleep: apply the elapsed wall-clock gap.
        const gap = Date.now() - hiddenWall - (performance.now() - hiddenMono);
        if (gap > 1000) sync.at -= gap;
        window.clearTimeout(timer);
        tick();
      }
    };
    document.addEventListener('visibilitychange', onVisibility);

    const extras = el('div', { class: 'button-row' });
    if (config.enableQrCode) {
      const qrBox = el('div', { class: 'qr-box', hidden: true });
      const qrButton = el('button', { type: 'button', class: 'button button-secondary', 'aria-expanded': 'false' }, t('result.qr'));
      qrButton.addEventListener('click', () => {
        const open = qrBox.hidden;
        qrBox.hidden = !open;
        qrButton.textContent = open ? t('result.qrHide') : t('result.qr');
        qrButton.setAttribute('aria-expanded', String(open));
        if (open && qrBox.childElementCount === 0) {
          const full = el('button', { type: 'button', class: 'button button-tertiary' }, t('result.qrFullscreen'));
          full.addEventListener('click', () => void qrBox.requestFullscreen?.());
          qrBox.append(qrSvg(shareLink, t('result.qrLabel')), full);
        }
      });
      extras.append(qrButton, qrBox);
    }
    const message = el('button', { type: 'button', class: 'button button-secondary' }, t('result.copyMessage'));
    message.addEventListener('click', () => {
      const date = expiresAt === null ? '' : formatDate(Date.parse(expiresAt));
      const key = expiresAt === null ? 'result.messageNever' : readOnceMode ? 'result.messageReadOnce' : 'result.message';
      void copyText(t(key, { link: shareLink, date }));
    });
    extras.append(message);
    if (typeof navigator.share === 'function') {
      const shareButton = el('button', { type: 'button', class: 'button button-secondary' }, t('result.share'));
      shareButton.addEventListener('click', () => void navigator.share({ url: shareLink }).catch(() => undefined));
      extras.append(shareButton);
    }

    // Danger zone: management link behind an explicit action, never next to the share link.
    const dangerBody = el('div', { class: 'danger-body', hidden: true });
    const reveal = el('button', { type: 'button', class: 'button button-tertiary', 'aria-expanded': 'false' }, t('manage.reveal'));
    reveal.addEventListener('click', () => {
      dangerBody.hidden = !dangerBody.hidden;
      reveal.setAttribute('aria-expanded', String(!dangerBody.hidden));
      if (dangerBody.childElementCount === 0) {
        const manageField = el('input', { class: 'link-field', type: 'text', readonly: true, value: manageLink, 'aria-label': t('manage.title') });
        const copyManage = el('button', { type: 'button', class: 'button button-secondary' }, t('action.copy'));
        copyManage.addEventListener('click', async () => {
          if (await copyText(manageLink, t('manage.copied'))) {
            manageCopied = true;
            setUnloadGuard(false, '');
          }
        });
        const remove = el('button', { type: 'button', class: 'button button-danger' }, t('manage.delete'));
        remove.addEventListener('click', async () => {
          if (!(await confirmInline(remove, t('manage.confirm'), t('manage.delete'), true))) return;
          try {
            await api.remove(id, encode(prepared.deletionToken));
          } catch (error) {
            if (!(error instanceof ApiError) || error.kind !== 'unavailable') {
              toast(t('error.network'));
              return;
            }
          }
          manageCopied = true;
          setUnloadGuard(false, '');
          showScreen(main, el('h1', { class: 'page-title' }, t('manage.done')), newButton());
          announce(t('manage.done'));
        });
        dangerBody.append(el('p', { class: 'warning' }, t('manage.warning')), manageField, el('div', { class: 'button-row' }, copyManage, remove));
      }
    });

    const newButton = () => {
      const button = el('button', { type: 'button', class: 'button button-secondary' }, t('action.new'));
      button.addEventListener('click', async () => {
        if (!manageCopied && !(await confirmInline(button, t('result.leaveWarning'), t('action.new')))) return;
        setUnloadGuard(false, '');
        window.clearTimeout(timer);
        busy = false;
        state.usePassphrase = false;
        state.readOnce = false;
        render();
      });
      return button;
    };

    showScreen(
      main,
      el('h1', { class: 'page-title' }, t('result.title')),
      el('p', { class: 'success' }, t('result.encrypted')),
      el('div', { class: 'field' }, el('label', { for: linkInput.id, class: 'field-label' }, t('result.shareLink')), el('div', { class: 'link-row' }, linkInput, copy)),
      el('p', { class: 'mode' }, readOnceMode ? t('result.mode.readOnce') : t('result.mode.normal')),
      expiry,
      el('p', { class: 'warning' }, t('result.careful')),
      readOnceMode ? el('p', { class: 'warning' }, t('result.readOnceWarning')) : null,
      usedPassphrase ? el('p', { class: 'notice' }, t('result.passphraseReminder')) : null,
      extras,
      el('section', { class: 'danger-zone', 'aria-label': t('manage.title') }, el('h2', {}, t('manage.title')), reveal, dangerBody),
      el('div', { class: 'action-bar' }, newButton()),
    );
    tick();
    announce(t('result.title'));
  }

  render();
  return () => {
    if (!inResult) render();
  };
}
