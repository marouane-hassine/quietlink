// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-UX-042, EXG-UX-043, EXG-UX-044, EXG-SEC-020, EXG-TEST-007.

import { describe, expect, it } from 'vitest';
import { setLocale } from '../src/i18n';
import { renderTemplateView } from '../src/render/template-view';
import { parseTemplateText } from '../src/templates';

describe('template reading view', () => {
  it('masks sensitive values until revealed and offers one copy button per field', () => {
    setLocale('en');
    const view = renderTemplateView(parseTemplateText('# Login credentials\n\n## Identity\n- Username: dummy-user\n- Password: dummy-secret-value\n')!);
    document.body.replaceChildren(view);

    expect(view.textContent).toContain('dummy-user');
    expect(view.textContent).not.toContain('dummy-secret-value');
    expect(view.innerHTML).not.toContain('dummy-secret-value');
    expect([...view.querySelectorAll('button')].map((b) => b.textContent)).toEqual(['Copy Username', 'Show Password', 'Copy Password']);

    (view.querySelector('button[aria-pressed]') as HTMLButtonElement).click();
    expect(view.textContent).toContain('dummy-secret-value');
  });
});
