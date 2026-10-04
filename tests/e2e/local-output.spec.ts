// SPDX-License-Identifier: AGPL-3.0-or-later
// Local export and printing when the instance enables them (§6.2, §6.8; tools/e2e/server.sh
// turns ui.allow_export and ui.allow_print on).
// Requirements: EXG-UX-101, EXG-UX-111, EXG-TEST-080.

import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { createPlain } from './support';

test.use({ locale: 'en-US' });

test('save as text file downloads the decrypted text without any network request', async ({ page, context }) => {
  const text = 'Dummy exported text for example.test';
  const link = await createPlain(page, text);
  const reader = await context.newPage();
  await reader.goto(link);
  await expect(reader.locator('.reader')).toContainText(text);

  const requests: string[] = [];
  reader.on('request', (request) => requests.push(`${request.url()} ${request.postData() ?? ''}`));
  const [download] = await Promise.all([reader.waitForEvent('download'), reader.getByRole('button', { name: 'Save as text file' }).click()]);
  expect(download.suggestedFilename()).toMatch(/^quietlink-\d{4}-\d{2}-\d{2}\.txt$/);
  const path = await download.path();
  expect(await readFile(path, 'utf8')).toBe(text);
  await expect(reader.locator('#ql-toast')).toHaveText('Text file saved on this device.');
  // Only the local object URL may appear; nothing reaches the server.
  expect(requests.filter((entry) => !entry.startsWith('blob:'))).toEqual([]);
  for (const entry of requests) expect(entry).not.toContain(text);
});

test('print asks for confirmation and only prints after it', async ({ page, context }) => {
  const link = await createPlain(page, 'Dummy printed text');
  const reader = await context.newPage();
  await reader.addInitScript(() => {
    const state = window as unknown as { printCalls: number };
    state.printCalls = 0;
    window.print = () => {
      state.printCalls += 1;
    };
  });
  await reader.goto(link);
  await expect(reader.locator('.reader')).toContainText('Dummy printed text');
  const printCalls = () => reader.evaluate(() => (window as unknown as { printCalls: number }).printCalls);

  const print = reader.getByRole('button', { name: 'Print', exact: true });
  await print.click();
  const dialog = reader.getByRole('alertdialog');
  await expect(dialog).toContainText('Printing leaves the protection of QuietLink');
  expect(await printCalls()).toBe(0);
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toHaveCount(0);
  expect(await printCalls()).toBe(0);
  await expect(reader.locator('body')).not.toHaveClass(/print-allowed/);

  await print.click();
  await reader.getByRole('alertdialog').getByRole('button', { name: 'Print anyway' }).click();
  await expect.poll(printCalls).toBe(1);
  await expect(reader.locator('body')).toHaveClass(/print-allowed/);

  // Print stylesheet: only the decrypted content remains.
  await reader.emulateMedia({ media: 'print' });
  await expect(reader.locator('.reader')).toBeVisible();
  await expect(reader.locator('main .action-bar')).toBeHidden();
  await expect(reader.locator('.site-header')).toBeHidden();
});
