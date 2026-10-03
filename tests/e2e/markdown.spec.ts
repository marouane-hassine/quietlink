// SPDX-License-Identifier: AGPL-3.0-or-later
// Markdown preview in the editor and Rendered/Source switch in the reading view (§6.1.1, §6.2).
// Requirements: EXG-MD-007, EXG-MD-014.

import { expect, test } from '@playwright/test';
import { editorOf, submitAndGetLink } from './support';

test.use({ locale: 'en-US' });

const MARKDOWN = '# Dummy heading\n\nSome *emphasised* text for example.test.';

test('the editor previews Markdown and the reader switches between rendered and source', async ({ page, context }) => {
  await page.goto('/');
  const preview = page.getByRole('button', { name: 'Preview', exact: true });
  await expect(preview).toBeHidden();
  await page.getByLabel('Format', { exact: true }).selectOption('markdown');
  await editorOf(page).fill(MARKDOWN);
  await preview.click();
  const panel = page.getByRole('region', { name: 'Preview (rendered locally, nothing is sent)' });
  await expect(panel.getByRole('heading', { level: 1, name: 'Dummy heading' })).toBeVisible();
  await expect(panel.locator('em')).toHaveText('emphasised');
  await page.getByRole('button', { name: 'Hide preview' }).click();
  await expect(panel).toBeHidden();
  const link = await submitAndGetLink(page);

  const reader = await context.newPage();
  await reader.goto(link);
  const content = reader.locator('.reader');
  const rendered = reader.getByRole('button', { name: 'Rendered' });
  const source = reader.getByRole('button', { name: 'Source' });
  await expect(content.getByRole('heading', { level: 1, name: 'Dummy heading' })).toBeVisible({ timeout: 30_000 });
  await expect(rendered).toHaveAttribute('aria-pressed', 'true');

  await source.click();
  await expect(source).toHaveAttribute('aria-pressed', 'true');
  await expect(content.locator('pre')).toContainText('# Dummy heading');
  await expect(content.getByRole('heading', { name: 'Dummy heading' })).toHaveCount(0);

  await rendered.click();
  await expect(content.getByRole('heading', { level: 1, name: 'Dummy heading' })).toBeVisible();
});
