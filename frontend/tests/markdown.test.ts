// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-SEC-050, EXG-SEC-072, EXG-MD (links §6.9, no images V1), EXG-CRYPTO-038, EXG-CRYPTO-039, EXG-CRYPTO-040.

import { describe, expect, it } from 'vitest';
import { renderMarkdown } from '../src/render/markdown';
import { setLocale } from '../src/i18n';
import { parseEnvelope, serialize } from '../src/crypto/envelope';

const html = (source: string): string => {
  const div = document.createElement('div');
  div.append(renderMarkdown(source));
  return div.innerHTML;
};

describe('markdown sanitisation', () => {
  setLocale('en');
  const payloads = [
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '<iframe src="https://evil.example.test"></iframe>',
    '[x](javascript:alert(1))',
    '[x](data:text/html;base64,PHNjcmlwdD4=)',
    '[x](file:///etc/passwd)',
    '<a href="https://evil.example.test" onclick="alert(1)">x</a>',
    '<div style="background:url(https://evil.example.test)">x</div>',
    '<svg><script>alert(1)</script></svg>',
  ];
  it.each(payloads)('neutralises %s', (payload) => {
    const div = document.createElement('div');
    div.append(renderMarkdown(payload));
    expect(div.querySelector('script, iframe, img, svg, object, embed, style')).toBeNull();
    for (const node of div.querySelectorAll('*')) {
      for (const attribute of node.getAttributeNames()) {
        expect(attribute.startsWith('on')).toBe(false);
        expect(attribute).not.toBe('style');
      }
    }
    for (const link of div.querySelectorAll('a[href]')) {
      expect(link.getAttribute('href')).toMatch(/^https?:/);
    }
  });

  it('keeps http(s) links with rel noopener noreferrer and an indicator', () => {
    const out = html('[docs](https://docs.example.test/page)');
    expect(out).toContain('href="https://docs.example.test/page"');
    expect(out).toContain('rel="noopener noreferrer nofollow"');
    expect(out).toContain('External link: docs.example.test');
  });

  it('shows images as alt text and URL, never loads them', () => {
    const out = html('![diagram](https://img.example.test/a.png)');
    expect(out).not.toContain('<img');
    expect(out).toContain('Image (not displayed): diagram https://img.example.test/a.png');
  });

  it('highlights code with classes only', () => {
    const out = html('```json\n{"a": 1}\n```');
    expect(out).toContain('hljs-');
    expect(out).not.toContain('style=');
  });
});

describe('envelope', () => {
  it('normalises line endings and round-trips strictly', () => {
    const json = serialize({ format: 'markdown', language: null, template: 'wifi', text: 'a\r\nb\rc' });
    expect(parseEnvelope(json)).toEqual({ format: 'markdown', language: null, template: 'wifi', text: 'a\nb\nc', v: 1 });
    expect(() => parseEnvelope(json.replace('"v":1', '"v":1,"x":2'))).toThrow();
  });
});
