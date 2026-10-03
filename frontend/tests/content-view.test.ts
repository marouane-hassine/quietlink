// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-MD-014, EXG-MD-019, EXG-UX-079, EXG-PERF-003, EXG-TEST-072, EXG-TEST-075, EXG-MD-015, EXG-MD-016.

import { describe, expect, it } from 'vitest';
import { setLocale } from '../src/i18n';
import { buildContentView } from '../src/render/content-view';

const envelope = (format: 'plain' | 'markdown' | 'code', text: string, template: string | null = null, language: string | null = null) => ({ format, language, template, text, v: 1 as const });

describe('content view', () => {
  setLocale('en');

  it('switches between rendered Markdown and its source', () => {
    const { container, controls } = buildContentView(envelope('markdown', '# Title\n\n```\nblock one\n```\n\n```\nblock two\n```'));
    expect(container.querySelector('h1')?.textContent).toBe('Title');
    expect(container.querySelectorAll('.copy-block')).toHaveLength(2);
    const source = [...controls.querySelectorAll('button')].find((b) => b.textContent === 'Source') as HTMLButtonElement;
    source.click();
    expect(container.querySelector('h1')).toBeNull();
    expect(container.querySelector('pre')?.textContent).toContain('# Title');
  });

  it('starts templates in the field view with masked sensitive values', () => {
    const { container, controls } = buildContentView(envelope('markdown', '# Wi-Fi connection\n\n## Network\n- Password: dummy-wifi-value\n', 'wifi'));
    expect(container.textContent).not.toContain('dummy-wifi-value');
    expect([...controls.querySelectorAll('button')].map((b) => b.textContent)).toEqual(['Fields', 'Rendered', 'Source']);
  });

  it('shows large content as plain text and formats it only on request', () => {
    const big = '# Big\n\n' + 'x'.repeat(210 * 1024);
    const { container, controls } = buildContentView(envelope('markdown', big));
    expect(container.querySelector('h1')).toBeNull();
    const enable = [...controls.querySelectorAll('button')].find((b) => b.textContent === 'Show the formatted version') as HTMLButtonElement;
    enable.click();
    expect(container.querySelector('h1')?.textContent).toBe('Big');
  });

  it('highlights code and toggles line wrapping without changing the text', () => {
    const { container, controls } = buildContentView(envelope('code', '{"a": 1}', null, 'json'));
    document.body.replaceChildren(controls, container);
    expect(container.querySelector('.hljs')).not.toBeNull();
    const wrap = controls.querySelector('input[type=checkbox]') as HTMLInputElement;
    wrap.click();
    expect(container.classList.contains('no-wrap')).toBe(true);
    expect(container.querySelector('pre')?.textContent).toBe('{"a": 1}');
  });
});
