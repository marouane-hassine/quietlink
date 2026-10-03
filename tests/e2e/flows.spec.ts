// SPDX-License-Identifier: AGPL-3.0-or-later
// End-to-end journeys (§12). Requirements: EXG-CRYPTO-002, EXG-READ-001, EXG-READ-002,
// EXG-READ-006, EXG-URL-001, EXG-URL-007, EXG-URL-008, EXG-SEC-001, EXG-UX-117, EXG-UX-119.

import { expect, test, type Page } from '@playwright/test';

const SECRET_TEXT = 'Dummy confidential text for e2e';

async function create(page: Page, options: { readOnce?: boolean; passphrase?: string } = {}): Promise<string> {
  await page.goto('/');
  await page.getByRole('textbox', { name: /Text to protect/ }).fill(SECRET_TEXT);
  if (options.readOnce || options.passphrase) {
    await page.locator('details.options > summary').click();
  }
  if (options.readOnce) await page.getByLabel(/Read once/).check();
  if (options.passphrase) {
    await page.getByLabel(/Protect with a passphrase/).check();
    await page.getByLabel('Passphrase', { exact: true }).fill(options.passphrase);
    await page.getByLabel('Confirm the passphrase').fill(options.passphrase);
  }
  await page.getByRole('button', { name: 'Encrypt and create the link' }).click();
  await expect(page.getByRole('heading', { name: 'Encrypted and ready to share' })).toBeVisible({ timeout: 30_000 });
  return page.locator('input.link-field').inputValue();
}

test.use({ locale: 'en-US' });

test('creates and reads a paste; the server never receives the text or the key', async ({ page, context }) => {
  const bodies: string[] = [];
  page.on('request', (request) => bodies.push(`${request.url()} ${request.postData() ?? ''}`));
  const link = await create(page);
  const key = link.split('#')[1] ?? '';
  for (const body of bodies) {
    expect(body).not.toContain(SECRET_TEXT);
    expect(body).not.toContain(key);
  }

  const reader = await context.newPage();
  await reader.goto(link);
  await expect(reader.locator('.reader')).toContainText(SECRET_TEXT);
});

test('read once with passphrase: local check, reveal, consumption', async ({ page, context }) => {
  const link = await create(page, { readOnce: true, passphrase: 'dummy-e2e-passphrase' });
  const reader = await context.newPage();
  const opens: string[] = [];
  reader.on('request', (request) => {
    if (request.url().endsWith('/open')) opens.push(request.url());
  });
  await reader.goto(link);
  await expect(reader.getByRole('heading', { name: 'A confidential text was shared with you' })).toBeVisible();
  expect(opens).toHaveLength(0);

  await reader.getByLabel('A passphrase is required.').fill('wrong passphrase');
  await reader.getByRole('button', { name: 'Reveal' }).click();
  await expect(reader.locator('.field-error')).toHaveText('Incorrect passphrase. Try again.', { timeout: 30_000 });
  expect(opens).toHaveLength(0);

  await reader.getByLabel('A passphrase is required.').fill('dummy-e2e-passphrase');
  await reader.getByRole('button', { name: 'Reveal' }).click();
  await expect(reader.locator('.reader')).toContainText(SECRET_TEXT, { timeout: 30_000 });
  await expect(reader.locator('.banner')).toContainText('This content has been destroyed on the server');

  const again = await context.newPage();
  await again.goto(link);
  await expect(again.locator('main p.error')).toContainText('This content is unavailable', { timeout: 30_000 });
});

test('an incomplete link is reported without contacting the API', async ({ page }) => {
  const calls: string[] = [];
  page.on('request', (request) => {
    if (request.url().includes('/api/')) calls.push(request.url());
  });
  await page.goto('/p/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA#short');
  await expect(page.locator('main p.error')).toContainText('This link is incomplete');
  expect(calls).toHaveLength(0);
});

test('the management link deletes the paste after confirmation', async ({ page, context }) => {
  const link = await create(page);
  await page.getByRole('button', { name: 'Show the management link' }).click();
  const manageLink = await page.locator('.danger-zone input.link-field').inputValue();

  const manager = await context.newPage();
  const calls: string[] = [];
  manager.on('request', (request) => {
    if (request.url().includes('/api/')) calls.push(request.method());
  });
  await manager.goto(manageLink);
  await expect(manager.getByRole('button', { name: 'Delete permanently' })).toBeVisible();
  expect(calls).toHaveLength(0);
  await manager.getByRole('button', { name: 'Delete permanently' }).click();
  await expect(manager.getByRole('alertdialog')).toContainText('This cannot be undone');
  expect(calls).toHaveLength(0);
  await manager.getByRole('alertdialog').getByRole('button', { name: 'Delete permanently' }).click();
  await expect(manager.locator('main p[role=alert]')).toHaveText('Deleted, or already unavailable.');

  const reader = await context.newPage();
  await reader.goto(link);
  await expect(reader.locator('main p.error')).toContainText('This content is unavailable');
});

test('no file input and the interface switches to French', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('input[type=file]')).toHaveCount(0);
  await page.getByLabel('Language', { exact: true }).selectOption('fr');
  await expect(page.getByRole('heading', { name: 'Nouveau texte confidentiel' })).toBeVisible();
});
