// SPDX-License-Identifier: AGPL-3.0-or-later
// Read-once concurrency as seen by readers (§6.3.1, §5.1 "Écran de lecture").
// Requirements: EXG-READ-004, EXG-READ-005, EXG-READ-033, EXG-LIFE-001, EXG-LIFE-013, EXG-URL-017.

import { expect, test } from '@playwright/test';
import { decode, encode } from '../../frontend/src/crypto/base64url';
import { accessPublicKey, accessSeed, prove } from '../../frontend/src/crypto/protocol';

test.use({ locale: 'en-US' });

/** Reserves the paste like a reader that never confirms (e.g. an interceptor or a crash). */
async function reserveWithoutConfirming(baseURL: string, link: string): Promise<void> {
  const [path, fragment] = link.replace(baseURL, '').split('#');
  const id = (path ?? '').split('/').pop() ?? '';
  const urlKey = decode(fragment ?? '', 32);
  const challengeResponse = await fetch(`${baseURL}/api/v1/pastes/${id}/challenge`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"usage":"open"}' });
  const { challenge } = (await challengeResponse.json()) as { challenge: string };
  const response = await fetch(`${baseURL}/api/v1/pastes/${id}/open`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      challenge,
      access_pk: encode(await accessPublicKey(urlKey)),
      signature: await prove(await accessSeed(urlKey), challenge),
      reservation_id: encode(crypto.getRandomValues(new Uint8Array(16))),
    }),
  });
  expect(response.status).toBe(200);
}

test('a concurrent reservation is reported, then the unconfirmed open is announced', async ({ page, context, baseURL }) => {
  test.setTimeout(120_000);
  await page.goto('/');
  await page.getByRole('textbox', { name: /Text to protect/ }).fill('Dummy read-once text');
  await page.locator('details.options > summary').click();
  await page.getByLabel(/Read once/).check();
  await page.getByRole('button', { name: 'Encrypt and create the link' }).click();
  await expect(page.getByText('Do not open this link yourself: it can be read only once.')).toBeVisible();
  const field = page.locator('input.link-field');
  const link = await field.inputValue();
  expect(await field.getAttribute('readonly')).not.toBeNull();
  await expect(page.locator('main a[href*="#"]')).toHaveCount(0);
  const key = link.split('#')[1] ?? '';
  await expect(page.locator('main')).not.toContainText(new RegExp(`(^|\\s)${key}(\\s|$)`));

  await reserveWithoutConfirming(baseURL ?? '', link);

  const reader = await context.newPage();
  await reader.goto(link);
  await reader.getByRole('button', { name: 'Reveal' }).click();
  await expect(reader.locator('main p.error')).toContainText('This content is being opened elsewhere');

  // The reservation expires (30 s in the e2e instance) and counts as an unconfirmed open.
  await reader.waitForTimeout(31_000);
  await reader.getByRole('button', { name: 'Retry' }).click();
  await expect(reader.getByRole('alert').filter({ hasText: '(1 unconfirmed opening)' }).first()).toBeVisible();
  await reader.getByRole('button', { name: 'Reveal' }).click();
  await expect(reader.locator('.reader')).toContainText('Dummy read-once text');
});
