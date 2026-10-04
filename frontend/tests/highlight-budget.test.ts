// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (formatting never freezes the interface, §5.1 reading screen, §13): EXG-UX-085,
// EXG-PERF-005.

import { describe, expect, it } from 'vitest';
import { setLocale, t } from '../src/i18n';
import { buildContentView } from '../src/render/content-view';
import { highlightToHtml } from '../src/render/highlight';
import { renderMarkdown } from '../src/render/markdown';

// highlight.js is quadratic on long lines: 100 KB of hex on one line blocks Java for ~24 s.
const longLine = 'ab12'.repeat(200);

describe('highlighting budget', () => {
  setLocale('en');

  it('highlights ordinary code', () => {
    expect(highlightToHtml('const a = 1;', 'javascript')).toContain('hljs-');
  });

  it('refuses lines longer than 500 characters and blocks larger than 64 KiB', () => {
    expect(highlightToHtml(longLine, 'java')).toBeNull();
    expect(highlightToHtml('int a = 1;\n'.repeat(7000), 'java')).toBeNull();
  });

  it('shows code that is too costly to highlight as plain text, with a notice', () => {
    const view = buildContentView({ v: 1, format: 'code', language: 'java', template: null, text: longLine });
    expect(view.container.querySelector('pre')?.textContent).toBe(longLine);
    expect(view.container.querySelector('.hljs-number, .hljs-string')).toBeNull();
    expect(view.controls.textContent).toContain(t('read.highlightSkipped'));
  });

  it('limits the total highlighted per Markdown document', () => {
    const fence = '```java\n' + 'int a = 1;\n'.repeat(4000) + '```\n\n';
    const html = renderMarkdown(fence + fence);
    const blocks = [...html.querySelectorAll('pre code')];
    expect(blocks).toHaveLength(2);
    expect(blocks[0]?.querySelector('[class^="hljs-"]')).not.toBeNull();
    expect(blocks[1]?.querySelector('[class^="hljs-"]')).toBeNull();
  });
});
