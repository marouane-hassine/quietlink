// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Form editing of a template (§6.1.1): one input per field, sensitive fields masked by a
 * single "hide/show sensitive fields" control. Every change rewrites the Markdown text.
 */

import { t } from '../i18n';
import { escapeNotes, isSensitiveLabel, noteTexts, serializeTemplate, type ParsedTemplate } from '../templates';
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
    const notesId = nextId('tpl-notes');
    const notes = el('textarea', { id: notesId, rows: section.fields.length > 0 ? '2' : '4', spellcheck: 'false', autocomplete: 'off' });
    // Announces a multi-line paste moved from a field to the notes.
    const moved = el('p', { class: 'hint', role: 'status' });
    for (const field of section.fields) {
      const id = nextId('tpl-field');
      const input = el('input', { id, type: 'text', class: 'passphrase', autocomplete: 'off', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false' });
      input.value = field.value;
      input.addEventListener('input', () => {
        field.value = input.value.replace(/[\r\n]+/g, ' ');
        emit();
      });
      input.addEventListener('paste', (event) => {
        // A field is one "- Label: value" line: a multi-line value (a private key) would lose
        // its line breaks. It goes to the section notes, which keep them.
        const pasted = (event as ClipboardEvent).clipboardData?.getData('text') ?? '';
        if (!/[\r\n]/.test(pasted.trim())) return;
        event.preventDefault();
        const kept = notes.value.replace(/\s+$/, '');
        const text = pasted.replace(/\r\n?/g, '\n').replace(/\s+$/, '');
        notes.value = kept === '' ? text : `${kept}\n${text}`;
        notes.dispatchEvent(new Event('input'));
        moved.textContent = t('tpl.field.multilineMoved');
      });
      if (isSensitiveLabel(field.label)) sensitiveInputs.push(input);
      group.append(el('div', { class: 'field' }, el('label', { for: id }, field.label), input));
    }
    notes.value = noteTexts(section.notes).join('\n');
    notes.addEventListener('input', () => {
      // Kept as typed (nothing is dropped silently); trailing blank lines only are trimmed.
      // Leading blank lines too: the text could not be read back as this template.
      const typed = notes.value.replace(/\s+$/, '').replace(/^(\s*\n)+/, '');
      // A notes line starting with "## " would start a new section, and a first line written
      // like a field ("- label: value") would be read back as a field: both are escaped.
      const lines = typed === '' ? [] : escapeNotes(typed.split('\n'));
      section.notes = lines;
      emit();
    });
    group.append(el('div', { class: 'field' }, el('label', { for: notesId }, t('tpl.notes', { section: section.title })), notes), moved);
    form.append(group);
  }
  if (sensitiveInputs.length > 0) form.insertBefore(toggle, form.children[1] ?? null);
  applyMask();
  return form;
}
