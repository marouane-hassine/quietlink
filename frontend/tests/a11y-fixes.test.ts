// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (WCAG 2.2 AA audit, §6.6): EXG-A11Y-016, EXG-A11Y-005, EXG-A11Y-004, EXG-I18N-001,
// EXG-MD-003, EXG-UX-108.

import { describe, expect, it } from 'vitest';
import en from '../../translations/en.json';
import fr from '../../translations/fr.json';
import { setLocale, t } from '../src/i18n';
import { buildContentView } from '../src/render/content-view';
import { renderMarkdown } from '../src/render/markdown';
import { renderChrome } from '../src/ui/chrome';
import { showScreen } from '../src/ui/dom';
import { parseTemplateText, renderTemplate } from '../src/templates';
import { buildTemplateForm } from '../src/ui/template-form';
import type { PublicConfig } from '../src/config';

describe('accessibility fixes', () => {
  it('numbers the how-it-works steps once (the list numbers them)', () => {
    for (const catalog of [en, fr] as unknown as Record<string, string>[]) {
      for (const n of [1, 2, 3]) expect(catalog[`how.step${n}.title`]).not.toMatch(/^\d/);
    }
  });

  it('names the window after the current screen (2.4.2)', () => {
    document.title = 'New confidential text · QuietLink';
    document.body.innerHTML = '<main id="main"></main>';
    showScreen(document.getElementById('main') as HTMLElement, Object.assign(document.createElement('h1'), { textContent: 'Encrypted and ready to share' }));
    expect(document.title).toBe('Encrypted and ready to share · QuietLink');
  });

  it('keeps one h1 per page: decrypted Markdown headings start at h3 (1.3.1)', () => {
    const fragment = renderMarkdown('# Heading\n\n## Sub\n\n###### Deep');
    expect(fragment.querySelector('h1, h2')).toBeNull();
    expect(fragment.querySelector('h3')?.textContent).toBe('Heading');
    expect(fragment.querySelector('h4')?.textContent).toBe('Sub');
    expect(fragment.querySelectorAll('h6')).toHaveLength(1);
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
    const theme = document.getElementById('ql-theme') as HTMLSelectElement;
    expect(theme.options[0]?.textContent).toBe(t('nav.themeOption', { theme: t('theme.auto') }));
  });

  it('does not contradict a toggle label with aria-pressed (4.1.2)', () => {
    setLocale('en');
    const form = buildTemplateForm(parseTemplateText(renderTemplate('credentials'))!, () => undefined);
    const toggle = [...form.querySelectorAll('button')].find((b) => b.textContent === t('editor.sensitive.show')) as HTMLButtonElement;
    expect(toggle.hasAttribute('aria-pressed')).toBe(false);
  });
});
