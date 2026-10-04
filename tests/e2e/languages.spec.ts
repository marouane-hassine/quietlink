// SPDX-License-Identifier: AGPL-3.0-or-later
// Additional languages discovered from translations/*.json, right-to-left layout and the
// language choice (§6.6, §6.6.1, ADR-0010).
// Requirements: EXG-I18N-003, EXG-I18N-005, EXG-I18N-007, EXG-I18N-008.

import { expect, test, type Locator, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

type Catalogue = Record<string, unknown> & { _meta: { name: string } };
const catalogue = (locale: string): Catalogue => JSON.parse(readFileSync(new URL(`../../translations/${locale}.json`, import.meta.url), 'utf8')) as Catalogue;
const [ar, en, es, it] = ['ar', 'en', 'es', 'it'].map(catalogue) as [Catalogue, Catalogue, Catalogue, Catalogue];

/** A catalogue text, so that the journeys follow the translations instead of copies of them. */
function text(messages: Catalogue, key: string): string {
  const value = messages[key];
  if (typeof value !== 'string') throw new Error(`Missing translation: ${key}`);
  return value;
}

const SECRET_TEXT = 'Dummy text for the language journeys';

/** Fails when the element sticks out of the viewport on either side (RTL overflows to the left). */
async function expectInsideViewport(page: Page, locator: Locator): Promise<void> {
  const width = page.viewportSize()?.width ?? 0;
  const box = await locator.boundingBox();
  expect(box).not.toBeNull();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(width + 0.5);
  expect(await locator.evaluate((node) => node.scrollWidth <= node.clientWidth + 1)).toBe(true);
}

test.describe('Arabic browser', () => {
  test.use({ locale: 'ar' });

  test('gets a right-to-left interface, creates and reads in Arabic without overflow at 375 px', async ({ page, context }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('h1')).toHaveText(text(ar, 'page.create.title'));

    await page.getByRole('textbox', { name: text(ar, 'editor.label') }).fill(SECRET_TEXT);
    const doc = page.locator('html');
    expect(await doc.evaluate((node) => node.scrollWidth <= node.clientWidth)).toBe(true);
    await expectInsideViewport(page, page.locator('main .action-bar'));
    await expectInsideViewport(page, page.getByLabel(text(ar, 'nav.language'), { exact: true }));

    await page.getByRole('button', { name: text(ar, 'action.create') }).click();
    await expect(page.getByRole('heading', { name: text(ar, 'result.title') })).toBeVisible({ timeout: 30_000 });
    await expectInsideViewport(page, page.locator('main .action-bar').first());
    const link = await page.locator('input.link-field').inputValue();

    const reader = await context.newPage();
    await reader.setViewportSize({ width: 375, height: 800 });
    await reader.goto(link);
    await expect(reader.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(reader.locator('.reader')).toContainText(SECRET_TEXT, { timeout: 30_000 });
    await expect(reader.locator('h1')).toHaveText(text(ar, 'page.read.title'));
    expect(await reader.locator('html').evaluate((node) => node.scrollWidth <= node.clientWidth)).toBe(true);
  });
});

test.describe('language selector', () => {
  test.use({ locale: 'en-US' });

  test('switches to Spanish then Italian without reload and keeps the choice after a reload', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('h1')).toHaveText(text(en, 'page.create.title'));
    // A full navigation would clear this marker: its survival proves there was no reload.
    await page.evaluate(() => ((window as unknown as { noReload: boolean }).noReload = true));

    await page.getByLabel(text(en, 'nav.language'), { exact: true }).selectOption({ label: es._meta.name });
    await expect(page.locator('h1')).toHaveText(text(es, 'page.create.title'));
    await expect(page.locator('html')).toHaveAttribute('lang', 'es');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');

    await page.getByLabel(text(es, 'nav.language'), { exact: true }).selectOption({ label: it._meta.name });
    await expect(page.locator('h1')).toHaveText(text(it, 'page.create.title'));
    await expect(page.locator('html')).toHaveAttribute('lang', 'it');
    expect(await page.evaluate(() => (window as unknown as { noReload?: boolean }).noReload)).toBe(true);

    await page.reload();
    await expect(page.locator('h1')).toHaveText(text(it, 'page.create.title'));
    await expect(page.locator('html')).toHaveAttribute('lang', 'it');
  });
});

test.describe('template across languages', () => {
  test.use({ locale: 'it-IT' });

  test('an Italian credentials template read with the English interface keeps the password masked', async ({ page, browser }) => {
    const username = 'dummy-user';
    const password = 'dummy-password-e2e-it';
    await page.goto('/');
    await expect(page.locator('h1')).toHaveText(text(it, 'page.create.title'));
    await page.getByLabel(text(it, 'template.label'), { exact: true }).selectOption('credentials');
    const form = page.locator('.template-form');
    await expect(form).toBeVisible();
    await form.getByLabel(text(it, 'tpl.field.username'), { exact: true }).fill(username);
    await form.getByLabel(text(it, 'tpl.field.password'), { exact: true }).fill(password);
    await page.getByRole('button', { name: text(it, 'action.create') }).click();
    await expect(page.getByRole('heading', { name: text(it, 'result.title') })).toBeVisible({ timeout: 30_000 });
    const link = await page.locator('input.link-field').inputValue();

    const english = await browser.newContext({ locale: 'en-US' });
    try {
      const reader = await english.newPage();
      await reader.goto(link);
      await expect(reader.locator('html')).toHaveAttribute('lang', 'en');
      const view = reader.locator('.template-view');
      await expect(view).toBeVisible({ timeout: 30_000 });
      await expect(reader.getByRole('button', { name: 'Fields' })).toHaveAttribute('aria-pressed', 'true');
      await expect(view).toContainText(username);
      await expect(view).not.toContainText(password);
      await expect(view.locator('.field-value', { hasText: '••••••••' })).toHaveCount(1);
      await expect(view.locator('.field-value', { hasText: '••••••••' })).toHaveAttribute('aria-label', 'Hidden sensitive value');
    } finally {
      await english.close();
    }
  });
});
