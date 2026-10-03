// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-MD (templates §6.1.1: confirmation, undo, no silent replacement), EXG-SEC-004.

import { beforeEach, describe, expect, it } from 'vitest';
import { mountCreate } from '../src/pages/create';
import { setLocale } from '../src/i18n';
import type { PublicConfig } from '../src/config';

const config: PublicConfig = {
  page: 'create', enabledLocales: ['en', 'fr'], defaultExpiration: '1d', expirations: ['5m', '1h', '1d', '7d', '30d'],
  allowReadOnce: true, allowPassphrase: true, maxEnvelopeBytes: 1048576, kdf: { m: 65536, t: 3 },
  enableQrCode: true, darkMode: 'auto', templates: ['credentials', 'wifi'],
};

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('templates on the creation page', () => {
  let main: HTMLElement;
  beforeEach(() => {
    setLocale('en');
    document.body.innerHTML = '<main id="main"></main>';
    main = document.getElementById('main') as HTMLElement;
    mountCreate(main, config);
  });
  const editor = () => main.querySelector('textarea') as HTMLTextAreaElement;
  const select = () => main.querySelectorAll('select')[2] as HTMLSelectElement;
  const choose = (value: string) => {
    select().value = value;
    select().dispatchEvent(new Event('change'));
  };
  const confirmButton = () => main.querySelector('[role=alertdialog] .button-primary') as HTMLButtonElement;

  it('keeps the latest choice when a template is changed while a confirmation is open', async () => {
    editor().value = 'existing text';
    editor().dispatchEvent(new Event('input'));
    choose('credentials');
    choose('wifi');
    await tick();
    expect(select().value).toBe('wifi');
    confirmButton().click();
    await tick();
    expect(editor().value).toContain('Wi-Fi');
    expect(select().value).toBe('wifi');
  });

  it('offers a single undo that restores the text typed before the templates', async () => {
    editor().value = 'my notes';
    editor().dispatchEvent(new Event('input'));
    choose('credentials');
    await tick();
    confirmButton().click();
    await tick();
    choose('wifi');
    await tick();
    confirmButton().click();
    await tick();
    const undos = main.querySelectorAll('.inline-notice .link-button');
    expect(undos).toHaveLength(1);
    (undos[0] as HTMLButtonElement).click();
    expect(editor().value).toBe('my notes');
  });

  it('edits a template as a form and masks sensitive fields', async () => {
    choose('credentials');
    await tick();
    const inputs = [...main.querySelectorAll('.template-form input')] as HTMLInputElement[];
    expect(inputs.length).toBeGreaterThan(5);
    const password = inputs.find((input) => main.querySelector(`label[for="${input.id}"]`)?.textContent === 'Password') as HTMLInputElement;
    expect(password.type === 'password' || password.classList.contains('is-masked')).toBe(true);
    password.value = 'dummy-value';
    password.dispatchEvent(new Event('input'));
    expect(editor().value).toContain('- Password: dummy-value');
    expect((main.querySelector('textarea.editor')?.closest('.field') as HTMLElement).hidden).toBe(true);
  });

  it('disables input assistance and never stores the draft', () => {
    const before = { local: localStorage.length, session: sessionStorage.length };
    editor().value = 'DUMMY-DRAFT-TEXT';
    editor().dispatchEvent(new Event('input'));
    for (const field of [editor(), ...main.querySelectorAll('.passphrase-panel input')] as HTMLElement[]) {
      expect(field.getAttribute('spellcheck')).toBe('false');
      expect(field.getAttribute('autocomplete')).toBe('off');
      expect(field.getAttribute('autocapitalize')).toBe('off');
      expect(field.getAttribute('autocorrect')).toBe('off');
    }
    expect(localStorage.length).toBe(before.local);
    expect(sessionStorage.length).toBe(before.session);
    expect(document.cookie).toBe('');
    expect(location.href).not.toContain('DUMMY');
    expect(document.title).not.toContain('DUMMY');
  });

  it('moves focus to the screen title', () => {
    expect(document.activeElement?.textContent).toBe('New confidential text');
  });

  it('shows the settings summary, applies clamped presets and previews Markdown locally', () => {
    const summary = () => main.querySelector('.summary')?.textContent ?? '';
    expect(summary()).toContain('Expires after 1 day');
    (main.querySelector('details.options') as HTMLDetailsElement).open = true;
    const secret = [...main.querySelectorAll('.presets .chip')].find((b) => b.textContent?.startsWith('Secret')) as HTMLButtonElement;
    secret.click();
    expect(summary()).toContain('Expires after 1 hour');
    expect(summary()).toContain('read once');

    const format = main.querySelectorAll('select')[0] as HTMLSelectElement;
    expect(format.selectedOptions[0]?.textContent).toBe('Plain text');
    format.value = 'markdown';
    format.dispatchEvent(new Event('change'));
    editor().value = '# Dummy heading';
    editor().dispatchEvent(new Event('input'));
    const preview = [...main.querySelectorAll('button')].find((b) => b.textContent === 'Preview') as HTMLButtonElement;
    preview.click();
    expect(main.querySelector('.preview h1')?.textContent).toBe('Dummy heading');
  });
});

