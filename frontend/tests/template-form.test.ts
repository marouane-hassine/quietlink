// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (templates edited as forms without data loss, §6.1.1): EXG-UX-030, EXG-MD-008,
// EXG-MD-010, EXG-UX-042.

import { describe, expect, it } from 'vitest';
import { setLocale, t } from '../src/i18n';
import { parseTemplateText, renderTemplate } from '../src/templates';
import { buildTemplateForm } from '../src/ui/template-form';

describe('template form', () => {
  it('keeps every line typed in a notes field, shell comments included', () => {
    setLocale('en');
    let text = renderTemplate('incident');
    const form = buildTemplateForm(parseTemplateText(text)!, (written) => (text = written));
    document.body.replaceChildren(form);
    const notes = form.querySelector('textarea') as HTMLTextAreaElement;
    notes.value = '```bash\n# restart nginx\nsystemctl restart nginx\n```';
    notes.dispatchEvent(new Event('input'));

    expect(text).toContain('# restart nginx\nsystemctl restart nginx');
  });

  it('writes notes that the form and the reading view can parse back (blank lines, "## ")', () => {
    setLocale('en');
    let text = renderTemplate('credentials');
    const form = buildTemplateForm(parseTemplateText(text)!, (written) => (text = written));
    const notes = form.querySelector('textarea') as HTMLTextAreaElement;
    notes.value = 'line a\n\nline b\n## not a section';
    notes.dispatchEvent(new Event('input'));

    const parsed = parseTemplateText(text);
    expect(parsed).not.toBeNull();
    expect(text).toContain('line a\n\nline b');
  });

  it('keeps a notes line written like a field ("- x: y") a note after a round trip', () => {
    setLocale('en');
    let text = renderTemplate('credentials');
    const form = buildTemplateForm(parseTemplateText(text)!, (written) => (text = written));
    const notesFields = form.querySelectorAll('textarea');
    const notes = notesFields[notesFields.length - 1] as HTMLTextAreaElement;
    const fieldCount = (parsed: ReturnType<typeof parseTemplateText>) => parsed!.sections.reduce((n, s) => n + s.fields.length, 0);
    const before = fieldCount(parseTemplateText(text));
    notes.value = '- dummy: value\n- second: item';
    notes.dispatchEvent(new Event('input'));

    const parsed = parseTemplateText(text);
    expect(parsed).not.toBeNull();
    expect(fieldCount(parsed)).toBe(before);
    expect(parsed!.sections.at(-1)?.notes).toHaveLength(2);
  });

  it('keeps revealed sensitive fields revealed when the form is rebuilt, masked by default', () => {
    // EXG-MD-010, EXG-UX-042, EXG-I18N-005: a language change rebuilds the form.
    setLocale('en');
    const text = renderTemplate('credentials');
    const mask = { hidden: true };
    const first = buildTemplateForm(parseTemplateText(text)!, () => undefined, mask);
    expect(first.querySelector('input[type=password]')).not.toBeNull();
    (first.querySelector('.button-tertiary') as HTMLButtonElement).click();
    expect(first.querySelector('input[type=password]')).toBeNull();

    setLocale('fr');
    const again = buildTemplateForm(parseTemplateText(text)!, () => undefined, mask);
    expect(again.querySelector('input[type=password]')).toBeNull();
    expect(again.querySelector('.button-tertiary')?.textContent).toBe(t('editor.sensitive.hide'));
    setLocale('en');
    expect(buildTemplateForm(parseTemplateText(text)!, () => undefined).querySelector('input[type=password]')).not.toBeNull();
  });
});
