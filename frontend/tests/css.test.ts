// SPDX-License-Identifier: AGPL-3.0-or-later
// Static checks of the stylesheet. Requirements: EXG-A11Y-008, EXG-A11Y-009, EXG-A11Y-012,
// EXG-UX-015, EXG-UX-085, EXG-SEC-034, EXG-SEC-033, EXG-I18N-018.

import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const css = readFileSync(new URL('../src/styles/app.css', import.meta.url), 'utf8');

describe('stylesheet', () => {
  it('draws the focus ring with an offset of at least 2px', () => {
    expect(css).toMatch(/:focus-visible\s*\{[^}]*outline-offset:\s*2px/);
  });
  it('respects reduced motion and the system colour scheme', () => {
    expect(css).toContain('@media (prefers-reduced-motion: reduce)');
    expect(css).toContain('@media (prefers-color-scheme: dark)');
  });
  it('keeps inputs at 16px or more and touch targets at 44px', () => {
    expect(css).toContain('font-size: max(16px, 1rem)');
    expect(css).toContain('min-block-size: 44px');
  });
  it('loads no remote resource and uses local font stacks', () => {
    expect(css).not.toMatch(/@import|url\(\s*['"]?https?:/);
    expect(css).toContain('system-ui');
  });
  it('uses logical properties instead of physical left/right', () => {
    expect(css).not.toMatch(/(margin|padding)-(left|right)\s*:/);
    expect(css).not.toMatch(/text-align:\s*(left|right)/);
  });
  it('draws no ring on screen titles focused by script, which Tab never reaches', () => {
    // Screen titles receive focus on each screen change for screen readers (§6.6).
    expect(css).toMatch(/\.page-title\[tabindex="-1"\]:focus\s*\{[^}]*outline:\s*none/);
  });
  it('lays out every checkbox row as a centred flex row', () => {
    expect(css).toMatch(/\.field-check\s*\{[^}]*display:\s*flex/);
  });
  it('keeps the focused field clear of the sticky action bar (WCAG 2.4.11, §5.1)', () => {
    expect(css).toMatch(/scroll-padding-block-end:\s*calc\(var\(--ql-bar-height/);
    expect(css).toMatch(/@media \(max-height: 30rem\)\s*\{[^}]*\.action-bar\s*\{[^}]*position:\s*static/);
  });
  it('enlarges the full-screen QR code and keeps its button readable on the forced white background', () => {
    expect(css).toMatch(/\.qr-box:fullscreen \.qr\s*\{[^}]*90vmin/);
    expect(css).toMatch(/\.qr-box:fullscreen \.button\s*\{[^}]*color:\s*#1a1a1a/);
  });
  it('styles tables, placeholders and the full-screen QR focus ring for contrast (1.4.3, 1.4.11)', () => {
    expect(css).toMatch(/\.reader (table|th)[^{]*\{[^}]*border/);
    expect(css).toMatch(/::placeholder\s*\{[^}]*color:\s*var\(--ql-color-text-muted\)[^}]*opacity:\s*1/);
    expect(css).toMatch(/\.qr-box:fullscreen\s*\{[^}]*--ql-color-focus:\s*#8f2f16/);
  });
  it('drops the bar scroll padding when the bar no longer sticks, and sizes footer links (2.4.11, §6.4)', () => {
    expect(css).toMatch(/@media \(max-height: 30rem\)\s*\{[^@]*html\s*\{\s*scroll-padding-block-end:\s*1rem/);
    expect(css).toMatch(/\.site-footer a\s*\{[^}]*min-block-size:\s*44px/);
  });
  it('uses only theme variables that are defined', () => {
    const defined = new Set([...css.matchAll(/(--ql-[a-z0-9-]+)\s*:/g)].map((m) => m[1]));
    const used = [...css.matchAll(/var\((--ql-[a-z0-9-]+)\s*[,)]/g)].map((m) => m[1]);
    // Variables set at runtime by the scripts (viewport helpers) are provided with a fallback.
    const runtime = new Set(['--ql-keyboard-offset', '--ql-bar-height']);
    expect(used.filter((name) => name !== undefined && !defined.has(name) && !runtime.has(name))).toEqual([]);
  });
  it('mirrors what logical properties cannot in a right-to-left language (§6.6.1)', () => {
    expect(css).toMatch(/\[dir="rtl"\] select\s*\{[^}]*background-position:\s*0\.75rem 55%,\s*1\.1rem 55%/);
    expect(css).not.toMatch(/padding-inline:\s*env\(safe-area-inset-left\)\s*env\(safe-area-inset-right\)/);
  });
});
