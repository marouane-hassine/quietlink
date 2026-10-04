// SPDX-License-Identifier: AGPL-3.0-or-later
// Shared helpers for the Playwright journeys (not a spec file: no test runs from here).

import { expect, type Page } from '@playwright/test';

export const editorOf = (page: Page) => page.getByRole('textbox', { name: /Text to protect/ });

/** Submits the creation form and returns the share link once the result screen is shown. */
export async function submitAndGetLink(page: Page): Promise<string> {
  await page.getByRole('button', { name: 'Encrypt and create the link' }).click();
  await expect(page.getByRole('heading', { name: 'Encrypted and ready to share' })).toBeVisible({ timeout: 30_000 });
  return page.locator('input.link-field').inputValue();
}

/** Creates a plain-text paste with default options and returns its share link. */
export async function createPlain(page: Page, text: string): Promise<string> {
  await page.goto('/');
  await editorOf(page).fill(text);
  return submitAndGetLink(page);
}
