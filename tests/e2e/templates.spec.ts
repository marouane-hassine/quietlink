// SPDX-License-Identifier: AGPL-3.0-or-later
// Wi-Fi template filled in form mode, then read as field cards (§6.1.1, §6.2).
// Requirements: EXG-MD-015, EXG-MD-016, EXG-MD-017, EXG-MD-018.

import { expect, test } from '@playwright/test';
import { submitAndGetLink } from './support';

test.use({ locale: 'en-US' });

const SSID = 'dummy-network.example.test';
const PASSWORD = 'dummy-wifi-password-e2e';

test('a Wi-Fi template keeps its password masked and its QR code hidden until requested', async ({ page, context, browserName }) => {
  await page.goto('/');
  await page.getByLabel('Template', { exact: true }).selectOption('wifi');
  const form = page.locator('.template-form');
  await expect(form).toBeVisible();
  await expect(page.getByRole('group', { name: 'Editing mode' }).getByRole('button', { name: 'Form' })).toHaveAttribute('aria-pressed', 'true');
  await form.getByLabel('SSID', { exact: true }).fill(SSID);
  await form.getByLabel('Security', { exact: true }).fill('WPA2');
  const password = form.getByLabel('Password', { exact: true });
  await password.fill(PASSWORD);
  // Sensitive inputs are masked while typing in the form.
  expect(await password.evaluate((input) => input.classList.contains('is-masked') || (input as HTMLInputElement).type === 'password')).toBe(true);
  await expect(page.locator('.secret-suggestion')).toBeVisible();
  const link = await submitAndGetLink(page);

  if (browserName === 'chromium') await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  const reader = await context.newPage();
  await reader.goto(link);
  const view = reader.locator('.template-view');
  await expect(view).toBeVisible({ timeout: 30_000 });
  await expect(reader.getByRole('button', { name: 'Fields' })).toHaveAttribute('aria-pressed', 'true');
  await expect(view).toContainText(SSID);
  await expect(view).not.toContainText(PASSWORD);
  const passwordRow = view.locator('dd').filter({ has: reader.getByRole('button', { name: 'Copy Password' }) });
  await expect(passwordRow.locator('.field-value')).toHaveText('••••••••');
  await expect(passwordRow.locator('.field-value')).toHaveAttribute('aria-label', 'Hidden sensitive value');

  // Wi-Fi QR code: absent from the page until explicitly requested.
  const qrToggle = reader.getByRole('button', { name: 'Show Wi-Fi QR code' });
  await expect(qrToggle).toHaveAttribute('aria-expanded', 'false');
  await expect(view.locator('.qr-box')).toBeHidden();
  await expect(view.locator('.qr-box svg')).toHaveCount(0);

  await reader.getByRole('button', { name: 'Show Password' }).click();
  await expect(passwordRow.locator('.field-value')).toHaveText(PASSWORD);
  await expect(reader.getByRole('button', { name: 'Hide Password' })).toHaveAttribute('aria-pressed', 'true');

  await qrToggle.click();
  await expect(reader.getByRole('button', { name: 'Hide Wi-Fi QR code' })).toHaveAttribute('aria-expanded', 'true');
  await expect(view.locator('.qr-box svg')).toBeVisible();
  await expect(view.getByRole('img', { name: 'QR code to join the Wi-Fi network' })).toBeVisible();
  await reader.getByRole('button', { name: 'Hide Wi-Fi QR code' }).click();
  await expect(view.locator('.qr-box svg')).toHaveCount(0);

  if (browserName === 'chromium') {
    // The per-field Copy button copies the value only.
    await reader.getByRole('button', { name: 'Copy Password' }).click();
    expect(await reader.evaluate(() => navigator.clipboard.readText())).toBe(PASSWORD);
  }
});
