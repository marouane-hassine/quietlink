// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (WCAG 2.2 AA audit, §6.6): EXG-A11Y-016, EXG-A11Y-005, EXG-A11Y-004, EXG-I18N-001,
// EXG-MD-003, EXG-UX-108, EXG-A11Y-018.

import { describe, expect, it } from 'vitest';
import en from '../../translations/en.json';
import fr from '../../translations/fr.json';
import { setLocale, t } from '../src/i18n';
import { buildContentView } from '../src/render/content-view';
import { renderMarkdown } from '../src/render/markdown';
import { renderChrome } from '../src/ui/chrome';
import { confirmInline } from '../src/ui/confirm';
import { showScreen } from '../src/ui/dom';
import { parseTemplateText, renderTemplate } from '../src/templates';
import { buildTemplateForm } from '../src/ui/template-form';
import type { PublicConfig } from '../src/config';

describe('accessibility fixes', () => {
  it('numbers the how-it-works steps once (the list numbers them)', () => {
    for (const catalog of [en, fr] as unknown as Record<string, string>[]) {
      for (const n of [1, 2, 3]) expect(catalog[`how.flow.step${n}.title`]).not.toMatch(/^\d/);
    }
  });

  it('names the window after the current screen (2.4.2)', () => {
    document.title = 'New confidential text · QuietLink';
    document.body.innerHTML = '<main id="main"></main>';
    showScreen(document.getElementById('main') as HTMLElement, Object.assign(document.createElement('h1'), { textContent: 'Encrypted and ready to share' }));
    expect(document.title).toBe('Encrypted and ready to share · QuietLink');
  });

  it('keeps one h1 per page: decrypted Markdown headings start at h2, with no skipped level (1.3.1)', () => {
    // The reading screen has no h2 of its own: h1 content becomes h2 (axe heading-order).
    const fragment = renderMarkdown('# Heading\n\n## Sub\n\n##### Five\n\n###### Deep');
    expect(fragment.querySelector('h1')).toBeNull();
    expect(fragment.querySelector('h2')?.textContent).toBe('Heading');
    expect(fragment.querySelector('h3')?.textContent).toBe('Sub');
    expect([...fragment.querySelectorAll('h6')].map((h) => h.textContent)).toEqual(['Five', 'Deep']);
  });

  it('names the inline confirmation dialog by its message (4.1.2)', async () => {
    setLocale('en');
    const trigger = document.createElement('button');
    document.body.replaceChildren(trigger);
    const answer = confirmInline(trigger, 'Delete this dummy item?', 'Delete');
    const dialog = document.querySelector('[role=alertdialog]') as HTMLElement;
    const labelId = dialog.getAttribute('aria-labelledby') ?? '';
    expect(labelId).not.toBe('');
    expect(document.getElementById(labelId)?.textContent).toBe('Delete this dummy item?');
    (dialog.querySelector('.button-secondary') as HTMLButtonElement).click();
    await answer;
  });

  it('gives the focusable reading container a region role so its label is exposed (4.1.2)', () => {
    setLocale('en');
    const { container } = buildContentView({ v: 1, format: 'plain', language: null, template: null, text: 'dummy' });
    expect(container.getAttribute('tabindex')).toBe('0');
    expect(container.getAttribute('role')).toBe('region');
    expect(container.getAttribute('aria-label')).not.toBe('');
  });

  it('offers block copy only where it adds something: not for a single plain text', () => {
    setLocale('en');
    const plain = buildContentView({ v: 1, format: 'plain', language: null, template: null, text: 'dummy' });
    expect(plain.container.querySelector('.copy-block')).toBeNull();
    const markdown = buildContentView({ v: 1, format: 'markdown', language: null, template: null, text: 'text\n\n```\ncode\n```' });
    expect(markdown.container.querySelector('.copy-block')).not.toBeNull();
  });

  it('marks each language name with its own language and labels the theme visibly (3.1.2, 3.3.2)', () => {
    setLocale('en');
    document.body.innerHTML = '<div id="ql-controls"></div>';
    renderChrome({ enabledLocales: ['en', 'fr'], darkMode: 'auto', page: 'create' } as PublicConfig, 'auto', () => undefined);
    const options = [...(document.getElementById('ql-language') as HTMLSelectElement).options];
    expect(options.map((o) => o.lang)).toEqual(['en', 'fr']);
    // The theme choices sit in a fieldset whose legend names them (see chrome.test.ts).
    expect(document.querySelector('#ql-theme legend')?.textContent).toBe(t('nav.theme'));
  });

  it('does not contradict a toggle label with aria-pressed (4.1.2)', () => {
    setLocale('en');
    const form = buildTemplateForm(parseTemplateText(renderTemplate('credentials'))!, () => undefined);
    const toggle = [...form.querySelectorAll('button')].find((b) => b.textContent === t('editor.sensitive.show')) as HTMLButtonElement;
    expect(toggle.hasAttribute('aria-pressed')).toBe(false);
  });

  it('lets user content take its own direction and keeps links and code left to right (§6.6.1)', () => {
    setLocale('en');
    const markdown = buildContentView({ v: 1, format: 'markdown', language: null, template: null, text: 'نص\n\n```\ncode\n```' });
    expect(markdown.container.getAttribute('dir')).toBe('auto');
    expect(markdown.container.querySelector('pre')?.getAttribute('dir')).toBe('ltr');
    const code = buildContentView({ v: 1, format: 'code', language: 'python', template: null, text: 'x = 1' });
    expect(code.container.querySelector('pre')?.getAttribute('dir')).toBe('ltr');
  });
});
