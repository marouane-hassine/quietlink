// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (templates edited as forms without data loss, §6.1.1): EXG-UX-030.

import { describe, expect, it } from 'vitest';
import { setLocale } from '../src/i18n';
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
});
