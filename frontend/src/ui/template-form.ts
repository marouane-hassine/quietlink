// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Form editing of a template (§6.1.1): one input per field, sensitive fields masked by a
 * single "hide/show sensitive fields" control. Every change rewrites the Markdown text.
 */

import { t } from '../i18n';
import { isFieldLine, isSensitiveLabel, serializeTemplate, type ParsedTemplate } from '../templates';
import { el, nextId } from './dom';

/** Mask state of the sensitive fields; the caller keeps it to rebuild the form unchanged. */
export interface SensitiveMask {
  hidden: boolean;
}

/**
 * `mask` is shared with the caller so that a rebuild (language change) keeps revealed fields
 * revealed; a new form starts masked.
 */
export function buildTemplateForm(template: ParsedTemplate, onChange: (text: string) => void, mask: SensitiveMask = { hidden: true }): HTMLElement {
  const sensitiveInputs: HTMLInputElement[] = [];
  const emit = () => onChange(serializeTemplate(template));

  // The label says the action ("Show"/"Hide"): no aria-pressed, which would contradict it.
  const toggle = el('button', { type: 'button', class: 'button button-tertiary' }, t('editor.sensitive.show'));
  const applyMask = () => {
    // Real password inputs (ADR-0009): CSS masking would leave values readable by screen readers.
    for (const input of sensitiveInputs) input.type = mask.hidden ? 'password' : 'text';
    toggle.textContent = t(mask.hidden ? 'editor.sensitive.show' : 'editor.sensitive.hide');
  };
  toggle.addEventListener('click', () => {
    mask.hidden = !mask.hidden;
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
      // A notes line starting with "## " would start a new section: escaped as "\## ", which
      // Markdown shows unchanged. Likewise a first line written like a field ("- label: value")
      // would be read back as a field: its "- " is escaped as "\- ".
      const lines = typed === '' ? [] : typed.split('\n').map((line) => line.replace(/^## /, '\\## '));
      const first = lines.findIndex((line) => line.trim() !== '');
      if (first >= 0 && isFieldLine(lines[first] ?? '')) lines[first] = `\\${lines[first] ?? ''}`;
      section.notes = lines;
      emit();
    });
    group.append(el('div', { class: 'field' }, el('label', { for: notesId }, t('tpl.notes', { section: section.title })), notes));
    form.append(group);
  }
  if (sensitiveInputs.length > 0) form.insertBefore(toggle, form.children[1] ?? null);
  applyMask();
  return form;
}
