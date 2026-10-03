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
});
