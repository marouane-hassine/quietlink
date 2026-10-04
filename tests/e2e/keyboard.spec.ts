// SPDX-License-Identifier: AGPL-3.0-or-later
// Keyboard-only creation and reading (§6.6): Tab reaches the editor, the options and the primary
// action in that order, Enter and Space activate controls, and focus is never trapped.
// Requirements: EXG-A11Y-017.

import { expect, test, type Locator, type Page } from '@playwright/test';

test.use({ locale: 'en-US' });

const MAX_TABS = 60;

const isFocused = (locator: Locator) => locator.evaluate((node) => node === document.activeElement);

/**
 * Starts sequential navigation from the top of the page, as a first Tab would: the skip link is
 * the first focusable element (the creation page may autofocus the editor on fine pointers).
 */
const fromTop = (page: Page) => page.locator('#ql-skip').focus();

/** Presses Tab until the locator is focused; returns the number of presses, or fails. */
async function tabTo(page: Page, key: string, target: Locator): Promise<number> {
  for (let presses = 1; presses <= MAX_TABS; presses += 1) {
    await page.keyboard.press(key);
    if (await isFocused(target)) return presses;
  }
  throw new Error('Target not reachable with the keyboard');
}

test('creation and reading with the keyboard only', async ({ page, context, browserName }) => {
  // WebKit only moves focus to buttons and links with Option+Tab, as Safari does by default.
  const tab = browserName === 'webkit' ? 'Alt+Tab' : 'Tab';
  await page.goto('/');
  const editor = page.getByRole('textbox', { name: /Text to protect/ });
  await expect(editor).toBeVisible();

  await fromTop(page);
  await tabTo(page, tab, editor);
  await page.keyboard.type('Dummy keyboard-only text');
  await expect(editor).toHaveValue('Dummy keyboard-only text');
  // The editor does not swallow Tab: the very next press leaves it for the options.
  const optionsSummary = page.locator('details.options > summary');
  expect(await tabTo(page, tab, optionsSummary)).toBe(1);
  await page.keyboard.press('Enter');
  await expect(page.locator('details.options')).toHaveAttribute('open', '');
  const readOnce = page.getByLabel(/Read once/);
  await tabTo(page, tab, readOnce);
  await page.keyboard.press('Space');
  await expect(readOnce).toBeChecked();

  const submit = page.getByRole('button', { name: 'Encrypt and create the link' });
  await tabTo(page, tab, submit);
  // Focus leaves the main content after the primary action: no trap before the footer.
  await tabTo(page, tab, page.locator('#ql-footer-how'));
  await submit.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Encrypted and ready to share' })).toBeVisible({ timeout: 30_000 });
  const link = await page.locator('input.link-field').inputValue();

  const reader = await context.newPage();
  await reader.goto(link);
  const reveal = reader.getByRole('button', { name: 'Reveal' });
  await expect(reveal).toBeVisible();
  await fromTop(reader);
  await tabTo(reader, tab, reveal);
  await reader.keyboard.press('Space');
  await expect(reader.locator('.reader')).toContainText('Dummy keyboard-only text', { timeout: 30_000 });

  const hide = reader.getByRole('button', { name: 'Hide', exact: true });
  await tabTo(reader, tab, hide);
  await reader.keyboard.press('Enter');
  await expect(reader.locator('.reader')).toHaveClass(/is-hidden/);
  const show = reader.getByRole('button', { name: 'Show', exact: true });
  expect(await isFocused(show)).toBe(true);
  await reader.keyboard.press('Space');
  await expect(reader.locator('.reader')).not.toHaveClass(/is-hidden/);
  await tabTo(reader, tab, reader.locator('#ql-footer-how'));
});
