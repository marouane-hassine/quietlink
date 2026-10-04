// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Form editing of a template (§6.1.1): one input per field, sensitive fields masked by a
 * single "hide/show sensitive fields" control. Every change rewrites the Markdown text.
 */

import { t } from '../i18n';
import { isSensitiveLabel, serializeTemplate, type ParsedTemplate } from '../templates';
import { el, nextId } from './dom';

export function buildTemplateForm(template: ParsedTemplate, onChange: (text: string) => void): HTMLElement {
  const sensitiveInputs: HTMLInputElement[] = [];
  let hidden = true;
  const emit = () => onChange(serializeTemplate(template));

  const toggle = el('button', { type: 'button', class: 'button button-tertiary', 'aria-pressed': 'true' }, t('editor.sensitive.show'));
  const applyMask = () => {
    // Real password inputs (ADR-0009): CSS masking would leave values readable by screen readers.
    for (const input of sensitiveInputs) input.type = hidden ? 'password' : 'text';
    toggle.textContent = t(hidden ? 'editor.sensitive.show' : 'editor.sensitive.hide');
    toggle.setAttribute('aria-pressed', String(hidden));
  };
  toggle.addEventListener('click', () => {
    hidden = !hidden;
    applyMask();
  });

  const form = el('div', { class: 'template-form' }, el('h2', {}, template.title));
  for (const section of template.sections) {
    const group = el('fieldset', { class: 'template-section' }, el('legend', {}, section.title));
    for (const field of section.fields) {
      const id = nextId('tpl-field');
      const input = el('input', { id, type: 'text', class: 'passphrase', autocomplete: 'off', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false' });
      input.value = field.value;
      input.addEventListener('input', () => {
        field.value = input.value.replace(/[\r\n]+/g, ' ');
        emit();
      });
      if (isSensitiveLabel(field.label)) sensitiveInputs.push(input);
      group.append(el('div', { class: 'field' }, el('label', { for: id }, field.label), input));
    }
    const notesId = nextId('tpl-notes');
    const notes = el('textarea', { id: notesId, rows: section.fields.length > 0 ? '2' : '4', spellcheck: 'false', autocomplete: 'off' });
    notes.value = section.notes.join('\n');
    notes.addEventListener('input', () => {
      // Kept as typed (nothing is dropped silently); trailing blank lines only are trimmed.
      const typed = notes.value.replace(/\s+$/, '');
      section.notes = typed === '' ? [] : typed.split('\n');
      emit();
    });
    group.append(el('div', { class: 'field' }, el('label', { for: notesId }, t('tpl.notes', { section: section.title })), notes));
    form.append(group);
  }
  if (sensitiveInputs.length > 0) form.insertBefore(toggle, form.children[1] ?? null);
  applyMask();
  return form;
}
