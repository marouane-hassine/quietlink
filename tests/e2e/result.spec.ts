// SPDX-License-Identifier: AGPL-3.0-or-later
// Result screen and editor conveniences (§5.1). Requirements: EXG-UX-017, EXG-UX-061,
// EXG-UX-064, EXG-UX-065, EXG-UX-073, EXG-THEME-003, EXG-MD-009, EXG-UX-025, EXG-UX-026,
// EXG-SEC-016, EXG-URL-015, EXG-UX-070, EXG-UX-071.

import { expect, test } from '@playwright/test';

test.use({ locale: 'en-US', timezoneId: 'Europe/Paris' });

test('result screen: hidden QR code, message and native share carry only the share link', async ({ page, context, browserName }) => {
  test.skip(browserName !== 'chromium', 'clipboard permissions are granted in Chromium only');
  await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  await page.addInitScript(() => {
    (window as unknown as { __shared: unknown[] }).__shared = [];
    Object.defineProperty(navigator, 'share', {
      value: (data: unknown) => {
        (window as unknown as { __shared: unknown[] }).__shared.push(data);
        return Promise.resolve();
      },
    });
  });
  await page.goto('/');
  await expect(page.getByRole('button', { name: 'Paste from clipboard' })).toBeVisible();
  await page.getByRole('textbox', { name: /Text to protect/ }).fill('Dummy result text');
  await page.getByRole('button', { name: 'Encrypt and create the link' }).click();
  const link = await page.locator('input.link-field').inputValue();

  // Only the share link is in the main area; the management link stays hidden.
  await expect(page.locator('main input.link-field')).toHaveCount(1);
  await expect(page.locator('.qr')).toHaveCount(0);
  await page.getByRole('button', { name: 'Show QR code' }).click();
  await expect(page.locator('.qr')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Full screen' })).toBeVisible();

  await page.getByRole('button', { name: 'Copy with a message' }).click();
  const message = await page.evaluate(() => navigator.clipboard.readText());
  expect(message).toContain(link);
  expect(message).toMatch(/expires .*20\d\d.*(GMT|UTC|CET|CEST)/);
  expect(message).not.toContain('/manage/');

  await page.getByRole('button', { name: 'Share…' }).click();
  const shared = await page.evaluate(() => (window as unknown as { __shared: unknown[] }).__shared);
  expect(shared).toEqual([{ url: link }]);
});

test('three-state theme selector and a warning before leaving unpublished text', async ({ page }) => {
  await page.goto('/');
  const theme = page.getByLabel('Theme');
  await expect(theme.locator('option')).toHaveText(['System', 'Light', 'Dark']);
  await theme.selectOption('dark');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await theme.selectOption('auto');
  await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.+/);

  await page.getByRole('textbox', { name: /Text to protect/ }).fill('unpublished draft');
  let warned = false;
  page.on('dialog', async (dialog) => {
    warned = dialog.type() === 'beforeunload';
    await dialog.accept();
  });
  await page.evaluate(() => document.querySelector('main')?.click());
  await page.reload().catch(() => undefined);
  expect(warned).toBe(true);
});
