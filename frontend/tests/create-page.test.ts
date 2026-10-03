// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-MD (templates §6.1.1: confirmation, undo, no silent replacement).

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
});

