// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Reading view of a templated paste (§0.3 Should): one row per field with its own Copy
 * button; sensitive values are masked until revealed. Values only live in text nodes and
 * closures, never in attributes.
 */

import { t } from '../i18n';
import { isSensitiveLabel, type ParsedTemplate } from '../templates';
import { toast } from '../ui/announcer';
import { copyText } from '../ui/clipboard';
import { el } from '../ui/dom';

const MASK = '••••••••';

export function renderTemplateView(template: ParsedTemplate): HTMLElement {
  const root = el('div', { class: 'template-view' }, el('h2', {}, template.title));
  for (const section of template.sections) {
    const list = el('dl', { class: 'template-fields' });
    for (const field of section.fields) {
      const sensitive = isSensitiveLabel(field.label) && field.value !== '';
      const value = el('span', { class: 'field-value' }, sensitive ? MASK : field.value);
      if (sensitive) value.setAttribute('aria-label', t('tpl.field.masked'));
      const actions = el('span', { class: 'field-actions' });
      if (sensitive) {
        let shown = false;
        const toggle = el('button', { type: 'button', class: 'button button-tertiary', 'aria-pressed': 'false' }, t('tpl.field.show', { label: field.label }));
        toggle.addEventListener('click', () => {
          shown = !shown;
          value.textContent = shown ? field.value : MASK;
          if (shown) value.removeAttribute('aria-label');
          else value.setAttribute('aria-label', t('tpl.field.masked'));
          toggle.textContent = t(shown ? 'tpl.field.hide' : 'tpl.field.show', { label: field.label });
          toggle.setAttribute('aria-pressed', String(shown));
        });
        actions.append(toggle);
      }
      if (field.value !== '') {
        const copy = el('button', { type: 'button', class: 'button button-secondary' }, t('tpl.field.copy', { label: field.label }));
        copy.addEventListener('click', async () => {
          if (await copyText(field.value, t('tpl.field.copied', { label: field.label }))) toast(t('read.clipboardAdvice'));
        });
        actions.append(copy);
      }
      list.append(el('dt', {}, field.label), el('dd', {}, value, actions));
    }
    root.append(el('section', { class: 'template-section' }, el('h3', {}, section.title), section.fields.length > 0 ? list : null, ...section.notes.map((note) => el('p', { class: 'template-note' }, note))));
  }
  return root;
}
