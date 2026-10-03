// SPDX-License-Identifier: AGPL-3.0-or-later
// Instance-level rate limit seen from the interface (§5.1, §7.5): the 429 is simulated on one
// request so that the campaign keeps the server limits untouched.
// Requirements: EXG-UX-052, EXG-SEC-074.

import { expect, test } from '@playwright/test';
import { editorOf } from './support';

test.use({ locale: 'en-US' });

test('a rate-limited creation keeps the text and offers a retry', async ({ page }) => {
  let posts = 0;
  await page.route('**/api/v1/pastes', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    posts += 1;
    if (posts === 1) {
      await route.fulfill({
        status: 429,
        headers: { 'Retry-After': '30', 'Content-Type': 'application/problem+json' },
        body: JSON.stringify({ type: 'about:blank', title: 'Too Many Requests', status: 429 }),
      });
      return;
    }
    await route.continue();
  });

  await page.goto('/');
  await editorOf(page).fill('Dummy rate-limited text');
  await page.getByRole('button', { name: 'Encrypt and create the link' }).click();
  await expect(page.locator('.error-box')).toContainText('Too many attempts. Try again in a moment.');
  await expect(editorOf(page)).toHaveValue('Dummy rate-limited text');
  const retry = page.locator('.error-box').getByRole('button', { name: 'Retry' });
  await expect(retry).toBeVisible();
  expect(posts).toBe(1);

  await retry.click();
  await expect(page.getByRole('heading', { name: 'Encrypted and ready to share' })).toBeVisible({ timeout: 30_000 });
  expect(posts).toBe(2);
});
