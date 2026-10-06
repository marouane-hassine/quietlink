// SPDX-License-Identifier: AGPL-3.0-or-later
// Network failures and recovery (§10, §6.3.1). Requirements: EXG-UX-052, EXG-UX-056, EXG-UX-058,
// EXG-UX-118, EXG-API-018, EXG-READ-003, EXG-READ-020, EXG-READ-023, EXG-READ-037, EXG-TEST-028,
// EXG-TEST-041, EXG-TEST-061, EXG-TEST-069, EXG-TEST-071, EXG-TEST-122, EXG-UX-057.

import { expect, test } from '@playwright/test';

test.use({ locale: 'en-US' });

test('a failed send is retried on request with the same idempotency key and body', async ({ page }) => {
  const attempts: { key: string | null; body: string | null }[] = [];
  let first = true;
  await page.route('**/api/v1/pastes', async (route) => {
    attempts.push({ key: route.request().headers()['idempotency-key'] ?? null, body: route.request().postData() });
    if (first) {
      first = false;
      await route.abort('failed');
      return;
    }
    await route.continue();
  });
  await page.goto('/');
  await page.getByRole('textbox', { name: /Text to protect/ }).fill('Dummy retried text');
  await page.getByRole('button', { name: 'Encrypt and create the link' }).click();
  await expect(page.locator('.error-box')).toContainText('Connection lost');
  await expect(page.getByRole('textbox', { name: /Text to protect/ })).toHaveValue('Dummy retried text');
  expect(attempts).toHaveLength(1);

  await page.getByRole('button', { name: 'Retry' }).click();
  await expect(page.getByRole('heading', { name: 'Encrypted and ready to share' })).toBeVisible();
  expect(attempts).toHaveLength(2);
  expect(attempts[1]).toEqual(attempts[0]);
});

test('a lost confirmation keeps the text, and the reservation resumes after a reload', async ({ page, context }) => {
  await page.goto('/');
  await page.getByRole('textbox', { name: /Text to protect/ }).fill('Dummy resumed text');
  await page.locator('details.options > summary').click();
  await page.getByLabel(/Read once/).check();
  await page.getByLabel(/Protect with a passphrase/).check();
  await page.getByLabel('Passphrase', { exact: true }).fill('dummy-resume-passphrase');
  await page.getByLabel('Confirm the passphrase').fill('dummy-resume-passphrase');
  await page.getByRole('button', { name: 'Encrypt and create the link' }).click();
  const link = await page.locator('input.link-field').inputValue();

  const reader = await context.newPage();
  await reader.route('**/consume', (route) => route.abort('failed'));
  await reader.goto(link);
  await reader.getByLabel('A passphrase is required.').fill('dummy-resume-passphrase');
  await reader.getByRole('button', { name: 'Reveal' }).click();
  await expect(reader.locator('.reader')).toContainText('Dummy resumed text');
  await expect(reader.locator('.banner')).toContainText('the server could not confirm its destruction');

  await reader.unroute('**/consume');
  reader.on('dialog', (dialog) => void dialog.accept());
  await reader.reload();
  await reader.getByLabel('A passphrase is required.').fill('dummy-resume-passphrase');
  await reader.getByRole('button', { name: 'Reveal' }).click();
  await expect(reader.locator('.reader')).toContainText('Dummy resumed text');
  // Resuming the same reservation is not an unconfirmed open.
  await expect(reader.getByText(/opened .* time\(s\) before/)).toHaveCount(0);
  await expect(reader.locator('.banner')).toContainText('This content has been destroyed on the server');
});

test('an expired embedded challenge is renewed transparently', async ({ page, context }) => {
  // 61 s of deliberate waiting: a 120 s budget left too little margin for WebKit under the
  // load of the full parallel campaign.
  test.setTimeout(180_000);
  await page.goto('/');
  await page.getByRole('textbox', { name: /Text to protect/ }).fill('Dummy late reveal');
  await page.locator('details.options > summary').click();
  await page.getByLabel(/Read once/).check();
  await page.getByRole('button', { name: 'Encrypt and create the link' }).click();
  const link = await page.locator('input.link-field').inputValue();

  const reader = await context.newPage();
  await reader.goto(link);
  await expect(reader.getByRole('button', { name: 'Reveal' })).toBeVisible();
  await reader.waitForTimeout(61_000);
  await reader.getByRole('button', { name: 'Reveal' }).click();
  await expect(reader.locator('.reader')).toContainText('Dummy late reveal');
});
