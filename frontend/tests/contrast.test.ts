// SPDX-License-Identifier: AGPL-3.0-or-later
// Automated WCAG 2.2 AA contrast checks of the shipped palettes (§6.5).
// Requirements: EXG-A11Y-010, EXG-A11Y-011, EXG-A11Y-014, EXG-A11Y-015, EXG-THEME-004, EXG-THEME-006, EXG-THEME-011, EXG-TEST-078.

import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const css = readFileSync(new URL('../src/styles/app.css', import.meta.url), 'utf8');

function block(selector: string): Record<string, string> {
  const start = css.indexOf(selector);
  if (start < 0) throw new Error(`Missing block ${selector}`);
  const body = css.slice(css.indexOf('{', start) + 1, css.indexOf('}', start));
  return Object.fromEntries([...body.matchAll(/--ql-([\w-]+):\s*(#[0-9a-fA-F]{6})/g)].map((m) => [m[1] as string, (m[2] as string).toLowerCase()]));
}

function luminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map((i) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255;
    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  }) as [number, number, number];
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrast(a: string, b: string): number {
  const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x) as [number, number];
  return (l1 + 0.05) / (l2 + 0.05);
}

const light = block(':root {');
const dark = { ...light, ...block(':root[data-theme="dark"]') };

/** [foreground, background, minimum ratio] — text 4.5:1, controls and focus 3:1. */
const PAIRS: [string, string, number][] = [
  ['color-text', 'color-background', 4.5],
  ['color-text', 'color-surface', 4.5],
  ['color-text', 'color-surface-accent', 4.5],
  ['color-text-muted', 'color-background', 4.5],
  ['color-text-muted', 'color-surface', 4.5],
  ['color-primary-text', 'color-background', 4.5],
  ['color-primary-text', 'color-surface', 4.5],
  ['color-primary-text', 'color-surface-accent', 4.5],
  ['color-primary-contrast', 'color-primary', 4.5],
  ['color-primary-contrast', 'color-primary-hover', 4.5],
  ['color-border-control', 'color-background', 3],
  ['color-border-control', 'color-surface', 3],
  ['color-focus', 'color-background', 3],
  ['color-focus', 'color-surface', 3],
  ['security-success-text', 'security-success-surface', 4.5],
  ['security-warning-text', 'security-warning-surface', 4.5],
  ['security-danger-text', 'security-danger-surface', 4.5],
];

describe.each([
  ['light', light],
  ['dark', dark],
])('%s theme', (_, palette) => {
  it.each(PAIRS)('%s on %s ≥ %s:1', (fg, bg, min) => {
    const a = palette[fg];
    const b = palette[bg];
    expect(a, fg).toBeDefined();
    expect(b, bg).toBeDefined();
    expect(contrast(a as string, b as string)).toBeGreaterThanOrEqual(min);
  });
  it('white text on the danger button', () => {
    expect(contrast('#ffffff', palette['security-danger-fill'] as string)).toBeGreaterThanOrEqual(4.5);
  });
});

it('writes text on a primary-colour fill in the primary contrast colour only (EXG-A11Y-011)', () => {
  // The background colour on the primary fill is 4.04:1 in the dark theme (step badges, URL
  // fragment of the how-it-works page): only color-primary-contrast is checked against it.
  const rules = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].filter((m) => /(^|;)\s*background:\s*var\(--ql-color-primary\)\s*;/.test(m[2] as string));
  expect(rules.length).toBeGreaterThan(0);
  for (const rule of rules) {
    const color = /(?:^|;)\s*color:\s*([^;]+);/.exec(rule[2] as string)?.[1]?.trim();
    if (color !== undefined) expect(color, (rule[1] as string).trim()).toBe('var(--ql-color-primary-contrast)');
  }
  expect(contrast(dark['color-primary-contrast'] as string, dark['color-primary'] as string)).toBeGreaterThanOrEqual(4.5);
});

it('the dark theme block in the media query matches the explicit dark block', () => {
  expect(block(':root:not([data-theme="light"]) {')).toEqual(block(':root[data-theme="dark"]'));
});
