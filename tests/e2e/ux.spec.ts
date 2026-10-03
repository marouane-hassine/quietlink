// SPDX-License-Identifier: AGPL-3.0-or-later
// Interface rules of §5.1 checked in a real browser.
// Requirements: EXG-UX-003, EXG-UX-004, EXG-UX-011, EXG-UX-021, EXG-UX-029, EXG-UX-032, EXG-UX-033,
// EXG-UX-034, EXG-UX-035, EXG-UX-036, EXG-UX-037, EXG-UX-038, EXG-UX-040, EXG-UX-041, EXG-UX-116,
// EXG-UX-059, EXG-UX-060, EXG-UX-062, EXG-UX-063, EXG-UX-066, EXG-UX-067, EXG-UX-069, EXG-SEC-015,
// EXG-UX-068, EXG-UX-074, EXG-UX-075, EXG-UX-077, EXG-UX-078, EXG-UX-080, EXG-READ-010, EXG-SEC-010,
// EXG-UX-085, EXG-UX-102, EXG-UX-104, EXG-UX-097, EXG-UX-099, EXG-UX-100, EXG-LIFE-004, EXG-I18N-013.

import { expect, test } from '@playwright/test';

test.use({ locale: 'en-US' });

test('creation page rules and passphrase helpers', async ({ page }) => {
  await page.goto('/');
  const editor = page.getByRole('textbox', { name: /Text to protect/ });
  await expect(editor).toBeVisible();
  await expect(page.getByText('The editor is empty: type or paste the text to share.')).toBeVisible();
  const create = page.getByRole('button', { name: 'Encrypt and create the link' });
  await expect(create).toBeDisabled();
  await expect(page.locator('.disabled-reason')).toHaveText('Type a text first.');
  await page.getByText('What is sent to the server?').click();
  await expect(page.getByText(/The text, the key in the link and the passphrase never leave your browser/)).toBeVisible();
  await expect(page.getByRole('link', { name: 'How it works' })).toHaveAttribute('href', '/how-it-works');

  await editor.fill('Dummy text with passphrase');
  await page.locator('details.options > summary').click();
  await expect(page.getByLabel('Expires after')).toBeVisible();
  await page.getByLabel(/Protect with a passphrase/).check();
  await expect(page.getByText('Send the passphrase through another channel than the link.')).toBeVisible();
  const passphrase = page.getByLabel('Passphrase', { exact: true });
  await passphrase.fill('abc');
  await expect(page.getByText('Weak passphrase: it can be guessed. Prefer a generated one.')).toBeVisible();
  await expect(create).toBeDisabled();
  await page.getByLabel('Confirm the passphrase').fill('abd');
  await expect(page.getByText('The two passphrases do not match.')).toBeVisible();

  await page.getByRole('button', { name: 'Generate a strong passphrase' }).click();
  await expect(page.getByRole('alertdialog')).toContainText('Replace the passphrase you typed');
  await page.getByRole('alertdialog').getByRole('button', { name: 'Generate a strong passphrase' }).click();
  await expect(passphrase).toHaveValue(/^[a-z-]+(-[a-z-]+){5}$/);
  await expect(page.getByText('Strong passphrase.')).toBeVisible();
  await expect(create).toBeEnabled();
});

test('keyboard shortcut, result screen, reading actions', async ({ page, context, browserName }) => {
  await page.goto('/');
  await page.getByRole('textbox', { name: /Text to protect/ }).fill('Dummy shortcut text');
  await page.keyboard.press(process.platform === 'darwin' ? 'Meta+Enter' : 'Control+Enter');
  await expect(page.getByRole('heading', { name: 'Encrypted and ready to share' })).toBeVisible();
  await expect(page.getByText('The text was encrypted in your browser before being sent.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Copy', exact: true })).toBeVisible();
  await expect(page.getByText('Readable until expiration')).toBeVisible();
  await expect(page.locator('.expiry')).toContainText(/Expires in 24 hours/);
  await expect(page.getByText(/Anyone with this link can read the content/)).toBeVisible();
  await expect(page.locator('.danger-zone input')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Show the management link' })).toBeVisible();
  const link = await page.locator('input.link-field').inputValue();

  await page.getByRole('button', { name: 'New text' }).click();
  await expect(page.getByRole('alertdialog')).toContainText('You have not copied the management link');
  await page.getByRole('alertdialog').getByRole('button', { name: 'Cancel' }).click();
  await expect(page.getByRole('heading', { name: 'Encrypted and ready to share' })).toBeVisible();

  if (browserName === 'chromium') await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  const reader = await context.newPage();
  await reader.goto(link);
  await expect(reader.getByText('The content is decrypted in your browser; the server cannot read it.')).toBeVisible();
  await expect(reader.locator('.reader')).toContainText('Dummy shortcut text');
  await expect(reader.locator('.expiry')).toContainText(/Expires in .*\(/);
  if (browserName === 'chromium') {
    await reader.getByRole('button', { name: 'Copy', exact: true }).click();
    expect(await reader.evaluate(() => navigator.clipboard.readText())).toBe('Dummy shortcut text');
    await expect(reader.locator('#ql-toast')).toHaveText(/Remember to clear your clipboard after use|Copied/);
  }
  await reader.keyboard.press('Escape');
  await expect(reader.locator('.reader')).toHaveClass(/is-hidden/);
  await expect(reader.getByText('The content is hidden.')).toBeVisible();
  await reader.getByRole('button', { name: 'Show' }).click();
  await expect(reader.locator('.reader')).not.toHaveClass(/is-hidden/);
});

test('touch targets and narrow screens', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await page.goto('/');
  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  expect(scrollWidth).toBeLessThanOrEqual(320);
  const small = await page.evaluate(() =>
    [...document.querySelectorAll('main button:not([hidden]), main select, header select')]
      .filter((el) => (el as HTMLElement).offsetParent !== null)
      .map((el) => el.getBoundingClientRect())
      .filter((r) => r.height < 44 || r.width < 44).length,
  );
  expect(small).toBe(0);
});
