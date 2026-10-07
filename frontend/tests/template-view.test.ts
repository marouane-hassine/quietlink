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

  it('shows neither empty fields nor sections left without content (EXG-MD-029)', () => {
    setLocale('en');
    const view = renderTemplateView(parseTemplateText('# Login credentials\n\n## Service\n- Name: dummy-service\n- URL:\n\n## Security\n- Expiry date:\n- Contact:\n\n## Notes\n')!);

    expect([...view.querySelectorAll('dt')].map((dt) => dt.textContent)).toEqual(['Name']);
    expect([...view.querySelectorAll('h3')].map((h) => h.textContent)).toEqual(['Service']);
  });

  it('does not show a field whose value is only spaces', () => {
    setLocale('en');
    const view = renderTemplateView(parseTemplateText('# Login credentials\n\n## Service\n- Name: svc\n- URL:   \n')!);
    expect([...view.querySelectorAll('dt')].map((dt) => dt.textContent)).toEqual(['Name']);
  });
});
