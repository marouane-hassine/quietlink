// SPDX-License-Identifier: AGPL-3.0-or-later
// Secret fields, ambiguous decryption errors and the key fragment lifecycle (ADR-0009, §5.1, §8.3, §8.5).
// Requirements: EXG-SEC-007, EXG-CRYPTO-057, EXG-SEC-024, EXG-URL-003, EXG-URL-004, EXG-A11Y-004.

import { expect, test, type Page } from '@playwright/test';
import { editorOf, submitAndGetLink } from './support';

const SECRET_TEXT = 'Dummy text for the fragment lifecycle';
const PASSPHRASE = 'dummy-e2e-passphrase';
const NO_FRAGMENT = /^[^#]*$/;

test.use({ locale: 'en-US' });

async function create(page: Page, options: { readOnce?: boolean; passphrase?: string } = {}): Promise<string> {
  await page.goto('/');
  await editorOf(page).fill(SECRET_TEXT);
  if (options.readOnce || options.passphrase) await page.locator('details.options > summary').click();
  if (options.readOnce) await page.getByLabel(/Read once/).check();
  if (options.passphrase) {
    await page.getByLabel(/Protect with a passphrase/).check();
    await page.getByLabel('Passphrase', { exact: true }).fill(options.passphrase);
    await page.getByLabel('Confirm the passphrase').fill(options.passphrase);
  }
  return submitAndGetLink(page);
}

/** Opens the management link from the result screen and deletes the paste. */
async function deleteFromManagement(page: Page, manager: Page): Promise<void> {
  await page.getByRole('button', { name: 'Show the management link' }).click();
  // Revealing asks for an explicit confirmation after the irreversibility warning (§5.1).
  await page.getByRole('alertdialog').getByRole('button', { name: 'Show the link' }).click();
  const manageLink = await page.locator('.danger-zone input.link-field').inputValue();
  expect(manageLink).toContain('#');
  await manager.goto(manageLink);
  await manager.getByRole('button', { name: 'Delete permanently' }).click();
  await manager.getByRole('alertdialog').getByRole('button', { name: 'Delete permanently' }).click();
  await expect(manager.locator('main p[role=alert]')).toHaveText('Deleted, or already unavailable.');
}

test('a read-once paste loses its fragment once it is destroyed', async ({ page, context }) => {
  const link = await create(page, { readOnce: true });
  const reader = await context.newPage();
  await reader.goto(link);
  await expect(reader).toHaveURL(link);
  await reader.getByRole('button', { name: 'Reveal' }).click();
  await expect(reader.locator('.reader')).toContainText(SECRET_TEXT, { timeout: 30_000 });
  await expect(reader.locator('.banner')).toContainText('This content has been destroyed on the server');
  await expect(reader).toHaveURL(NO_FRAGMENT);
});

test('a multi-read paste keeps its fragment after reading', async ({ page, context }) => {
  const link = await create(page);
  const reader = await context.newPage();
  await reader.goto(link);
  await expect(reader.locator('.reader')).toContainText(SECRET_TEXT);
  await expect(reader).toHaveURL(link);
});

test('a deletion from the management page removes the fragment', async ({ page, context }) => {
  await create(page);
  const manager = await context.newPage();
  await deleteFromManagement(page, manager);
  await expect(manager).toHaveURL(NO_FRAGMENT);
});

test('an unavailable link ends without its fragment', async ({ page, context }) => {
  const link = await create(page);
  await deleteFromManagement(page, await context.newPage());
  const reader = await context.newPage();
  await reader.goto(link);
  await expect(reader.locator('main p.error')).toContainText('This content is unavailable');
  await expect(reader).toHaveURL(NO_FRAGMENT);
});

test('a wrong passphrase on a multi-read paste reports a possible alteration', async ({ page, context }) => {
  const link = await create(page, { passphrase: PASSPHRASE });
  const reader = await context.newPage();
  await reader.goto(link);
  const field = reader.getByLabel('A passphrase is required.');
  expect(await field.evaluate((input) => (input as HTMLInputElement).type)).toBe('password');
  await field.fill('wrong dummy passphrase');
  await reader.getByRole('button', { name: 'Reveal' }).click();
  await expect(reader.locator('main')).toContainText('Incorrect passphrase, or the content was altered. Check the passphrase and try again; if it is right, do not trust this link.', { timeout: 30_000 });
  await expect(reader.locator('.reader')).toHaveCount(0);
  // The content may still be read: the link keeps its fragment.
  await expect(reader).toHaveURL(link);

  await reader.getByLabel('A passphrase is required.').fill(PASSPHRASE);
  await reader.getByRole('button', { name: 'Reveal' }).click();
  await expect(reader.locator('.reader')).toContainText(SECRET_TEXT, { timeout: 30_000 });
});

test('passphrase inputs are password fields, shown as text only on request', async ({ page }) => {
  await page.goto('/');
  await page.locator('details.options > summary').click();
  await page.getByLabel(/Protect with a passphrase/).check();
  const passphrase = page.getByLabel('Passphrase', { exact: true });
  const confirmation = page.getByLabel('Confirm the passphrase');
  const typeOf = (field: typeof passphrase) => field.evaluate((input) => (input as HTMLInputElement).type);
  expect(await typeOf(passphrase)).toBe('password');
  expect(await typeOf(confirmation)).toBe('password');

  await page.locator('.passphrase-panel').getByRole('button', { name: 'Show', exact: true }).click();
  expect(await typeOf(passphrase)).toBe('text');
  await page.locator('.passphrase-panel').getByRole('button', { name: 'Hide', exact: true }).click();
  expect(await typeOf(passphrase)).toBe('password');
  expect(await typeOf(confirmation)).toBe('password');
});
