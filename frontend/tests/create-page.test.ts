// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (templates §6.1.1, editor §5.1): EXG-SEC-003, EXG-SEC-004, EXG-SEC-007, EXG-SEC-008,
// EXG-SEC-012, EXG-I18N-016, EXG-A11Y-004, EXG-A11Y-018, EXG-UX-019, EXG-UX-027, EXG-UX-028,
// EXG-MD-001, EXG-MD-002, EXG-MD-003, EXG-MD-004, EXG-MD-005, EXG-MD-006, EXG-MD-007, EXG-MD-008,
// EXG-MD-010, EXG-TEST-074, EXG-UX-013, EXG-UX-022, EXG-UX-023, EXG-UX-024, EXG-CRYPTO-042, EXG-TEST-029,
// EXG-TEST-031, EXG-TEST-064, EXG-TEST-067, EXG-UX-030.

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

  it('shows the settings summary, applies clamped presets and previews Markdown locally', async () => {
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
    for (let i = 0; i < 50 && !main.querySelector('.preview h1'); i++) await tick();
    expect(main.querySelector('.preview h1')?.textContent).toBe('Dummy heading');
  });

  it('shows the size gauge near the limit and refuses dropped files', () => {
    editor().value = 'x'.repeat(900 * 1024);
    editor().dispatchEvent(new Event('input'));
    const size = main.querySelector('.size') as HTMLElement;
    expect(size.hidden).toBe(false);
    expect(size.textContent).toMatch(/Size: .* of 1 MB/);
    editor().value = 'x'.repeat(1100 * 1024);
    editor().dispatchEvent(new Event('input'));
    expect(size.textContent).toMatch(/^The text is too large: /);
    expect((main.querySelector('.action-bar .button-primary') as HTMLButtonElement).disabled).toBe(true);

    const drop = new Event('drop', { cancelable: true }) as Event & { dataTransfer: { types: string[] } };
    Object.defineProperty(drop, 'dataTransfer', { value: { types: ['Files'] } });
    editor().dispatchEvent(drop);
    expect(drop.defaultPrevented).toBe(true);
  });

  it('suggests the Secret preset for a template with sensitive fields, without forcing it', async () => {
    choose('credentials');
    await tick();
    const suggestion = main.querySelector('.secret-suggestion') as HTMLElement;
    expect(suggestion.textContent).toContain('use the Secret preset');
    expect(main.querySelector('.summary')?.textContent).not.toContain('read once');
    (suggestion.querySelector('button') as HTMLButtonElement).click();
    expect(main.querySelector('.summary')?.textContent).toContain('read once');
    expect(main.querySelector('.secret-suggestion')).toBeNull();
  });
});

