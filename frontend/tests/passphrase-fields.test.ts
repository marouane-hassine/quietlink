// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (masked secrets not read out by assistive technologies, §5.1, ADR-0009):
// EXG-SEC-007, EXG-A11Y-004.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mountCreate } from '../src/pages/create';
import { setLocale, t } from '../src/i18n';
import { parseTemplateText, renderTemplate } from '../src/templates';
import { buildTemplateForm } from '../src/ui/template-form';
import type { PublicConfig } from '../src/config';

const config: PublicConfig = {
  page: 'create', enabledLocales: ['en'], defaultExpiration: '1d', expirations: ['1d'],
  allowReadOnce: true, allowPassphrase: true, maxEnvelopeBytes: 1048576, kdf: { m: 65536, t: 3 },
  enableQrCode: false, darkMode: 'auto', templates: ['credentials'],
};

describe('secret fields', () => {
  beforeEach(() => {
    setLocale('en');
    // A browser supporting CSS text masking must still get real password fields: CSS masking
    // leaves the value readable by screen readers.
    vi.stubGlobal('CSS', { supports: () => true });
    vi.stubGlobal('Worker', class {});
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
  });
  afterEach(() => vi.unstubAllGlobals());

  it('uses password inputs for the passphrase and its confirmation, text only when shown', () => {
    document.body.innerHTML = '<main id="main"></main>';
    const main = document.getElementById('main') as HTMLElement;
    mountCreate(main, config);
    const use = [...main.querySelectorAll('.options input[type=checkbox]')].at(-1) as HTMLInputElement;
    use.checked = true;
    use.dispatchEvent(new Event('change'));
    const fields = [...main.querySelectorAll('.passphrase-panel input.passphrase')] as HTMLInputElement[];

    expect(fields.length).toBeGreaterThanOrEqual(2);
    for (const field of fields) expect(field.type).toBe('password');
    const show = [...main.querySelectorAll('.passphrase-panel button')].find((b) => b.textContent === t('passphrase.show')) as HTMLButtonElement;
    show.click();
    expect((main.querySelector('.passphrase-panel input.passphrase') as HTMLInputElement).type).toBe('text');
  });

  it('uses password inputs for sensitive template fields', () => {
    const form = buildTemplateForm(parseTemplateText(renderTemplate('credentials'))!, () => undefined);
    const password = [...form.querySelectorAll('input')].find((input) => form.querySelector(`label[for="${input.id}"]`)?.textContent === 'Password') as HTMLInputElement;

    expect(password.type).toBe('password');
  });
});
