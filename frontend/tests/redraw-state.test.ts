// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// A language change redraws the screen without losing what the user typed or where focus was
// (§6.6.1). Requirements: EXG-I18N-005, EXG-A11Y-017.

import { beforeEach, describe, expect, it } from 'vitest';
import { el, redrawInPlace, showScreen } from '../src/ui/dom';

let counter = 0;
const screen = (main: HTMLElement, label: string) =>
  showScreen(
    main,
    el('h1', {}, label),
    el('input', { id: `pass-${++counter}`, type: 'password' }),
    el('input', { id: `keep-${counter}`, type: 'checkbox' }),
    el('textarea', { id: `note-${counter}` }),
  );

describe('redraw in place', () => {
  beforeEach(() => {
    document.body.innerHTML = '<main id="main"></main>';
  });

  it('keeps typed values, focus and caret when fields are recreated with new ids', () => {
    const main = document.getElementById('main') as HTMLElement;
    screen(main, 'Reveal');
    const pass = main.querySelector('input[type=password]') as HTMLInputElement;
    pass.value = 'dummy passphrase';
    pass.focus();
    pass.setSelectionRange(5, 5);

    redrawInPlace(() => screen(main, 'Révéler'));

    const again = main.querySelector('input[type=password]') as HTMLInputElement;
    expect(again).not.toBe(pass);
    expect(again.value).toBe('dummy passphrase');
    expect(document.activeElement).toBe(again);
    expect(again.selectionStart).toBe(5);
  });

  it('never overwrites a value the redrawn screen set itself, nor a different form', () => {
    const main = document.getElementById('main') as HTMLElement;
    screen(main, 'One');
    (main.querySelector('textarea') as HTMLTextAreaElement).value = 'old';
    redrawInPlace(() => {
      screen(main, 'Two');
      (main.querySelector('textarea') as HTMLTextAreaElement).value = 'kept by the page';
    });
    expect((main.querySelector('textarea') as HTMLTextAreaElement).value).toBe('kept by the page');

    (main.querySelector('input[type=password]') as HTMLInputElement).value = 'x';
    redrawInPlace(() => showScreen(main, el('h1', {}, 'Other'), el('input', { type: 'text' })));
    expect((main.querySelector('input') as HTMLInputElement).value).toBe('');
  });
});
