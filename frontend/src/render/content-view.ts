// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Decrypted content view (§5.1 "Écran de lecture", §6.1.1, §6.9): template fields, rendered
 * Markdown or highlighted code, and the source; above 200 KiB the plain text is shown and the
 * formatted version is offered on explicit action. Each code block gets its own Copy button.
 */

import DOMPurify from 'dompurify';
import { byteLength, type Envelope } from '../crypto/envelope';
import { t } from '../i18n';
import { parseTemplateText } from '../templates';
import { toast } from '../ui/announcer';
import { copyText } from '../ui/clipboard';
import { el, nextId } from '../ui/dom';
import { HIGHLIGHT_LIMIT_BYTES, highlightToHtml } from './highlight';
import { renderMarkdown } from './markdown';
import { renderTemplateView } from './template-view';

type Mode = 'fields' | 'rendered' | 'source';

export interface ContentView {
  container: HTMLElement;
  controls: HTMLElement;
}

export function buildContentView(envelope: Envelope): ContentView {
  const container = el('div', { class: 'reader', tabindex: '0', 'aria-label': t('page.read.title') });
  const controls = el('div', { class: 'reader-controls' });
  const large = byteLength(envelope.text) > HIGHLIGHT_LIMIT_BYTES;
  const template = envelope.template !== null && envelope.format === 'markdown' ? parseTemplateText(envelope.text) : null;
  const highlightable = envelope.format === 'code' && envelope.language !== null && highlightToHtml('', envelope.language) !== null;

  const modes: Mode[] = [];
  if (template) modes.push('fields');
  if (envelope.format === 'markdown' || highlightable) modes.push('rendered');
  modes.push('source');

  const render = (mode: Mode) => {
    container.classList.toggle('markdown', mode === 'rendered' && envelope.format === 'markdown');
    if (mode === 'fields' && template) {
      container.replaceChildren(renderTemplateView(template));
    } else if (mode === 'rendered' && envelope.format === 'markdown') {
      container.replaceChildren(renderMarkdown(envelope.text));
    } else if (mode === 'rendered' && highlightable && envelope.language) {
      const code = el('code', { class: `hljs language-${envelope.language}` });
      code.append(DOMPurify.sanitize(highlightToHtml(envelope.text, envelope.language) ?? '', { ALLOWED_TAGS: ['span'], ALLOWED_ATTR: ['class'], RETURN_DOM_FRAGMENT: true }));
      container.replaceChildren(el('pre', {}, code));
    } else {
      container.replaceChildren(el('pre', { class: 'plain' }, envelope.text));
    }
    for (const pre of container.querySelectorAll('pre')) {
      const blockText = pre.textContent ?? '';
      const copyBlock = el('button', { type: 'button', class: 'button button-tertiary copy-block' }, t('read.copyBlock'));
      copyBlock.addEventListener('click', async () => {
        if (await copyText(blockText)) toast(t('read.clipboardAdvice'));
      });
      pre.before(copyBlock);
    }
    for (const [button, value] of buttons) button.setAttribute('aria-pressed', String(value === mode));
  };

  const buttons: [HTMLButtonElement, Mode][] = [];
  if (modes.length > 1) {
    const labels: Record<Mode, string> = { fields: t('tpl.view.fields'), rendered: t('read.view.rendered'), source: t('read.view.source') };
    const group = el('div', { class: 'presets', role: 'group', 'aria-label': t('tpl.view.label') });
    for (const mode of modes) {
      const button = el('button', { type: 'button', class: 'chip', 'aria-pressed': 'false' }, labels[mode]);
      button.addEventListener('click', () => render(mode));
      buttons.push([button, mode]);
      group.append(button);
    }
    controls.append(group);
  }

  const wrapId = nextId('wrap');
  const wrap = el('input', { type: 'checkbox', id: wrapId, checked: true });
  wrap.addEventListener('change', () => container.classList.toggle('no-wrap', !wrap.checked));
  controls.append(el('p', { class: 'hint field-check' }, wrap, el('label', { for: wrapId }, t('read.wrap'))));

  if (large && modes.length > 1) {
    // Above the documented threshold: plain text first, formatting on explicit action.
    const enable = el('button', { type: 'button', class: 'button button-secondary' }, t('read.richEnable'));
    const notice = el('p', { class: 'notice' }, t('read.richDisabled'), ' ', enable);
    enable.addEventListener('click', () => {
      notice.remove();
      render(modes[0] ?? 'source');
    });
    controls.prepend(notice);
    render('source');
  } else {
    render(modes[0] ?? 'source');
  }

  return { container, controls };
}
