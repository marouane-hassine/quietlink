// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-UX-046, EXG-UX-088, EXG-UX-105.

import { describe, expect, it, vi } from 'vitest';
import { confirmInline } from '../src/ui/confirm';
import { setLocale } from '../src/i18n';

describe('inline confirmation', () => {
  setLocale('en');
  const trigger = () => {
    const button = document.createElement('button');
    document.body.replaceChildren(button);
    return button;
  };

  it('resolves true on confirm and returns focus to the trigger', async () => {
    const button = trigger();
    const answer = confirmInline(button, 'Delete?', 'Delete', true);
    const dialog = document.querySelector('[role=alertdialog]');
    expect(dialog?.getAttribute('aria-modal')).toBe('false');
    (dialog?.querySelector('.button-danger') as HTMLButtonElement).click();
    await expect(answer).resolves.toBe(true);
    expect(document.querySelector('[role=alertdialog]')).toBeNull();
    expect(document.activeElement).toBe(button);
  });

  it('resolves false on Escape', async () => {
    const answer = confirmInline(trigger(), 'Replace?', 'Replace');
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await expect(answer).resolves.toBe(false);
  });

  it('lets Escape through once a redraw removed the panel', async () => {
    const button = trigger();
    const answer = confirmInline(button, 'Replace?', 'Replace');
    document.body.replaceChildren(); // the screen was redrawn (language change)
    const later = vi.fn();
    document.addEventListener('keydown', later);
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    document.removeEventListener('keydown', later);

    expect(later).toHaveBeenCalledOnce();
    await expect(answer).resolves.toBe(false);
  });
});
