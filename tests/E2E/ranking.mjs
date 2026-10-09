import { test, expect } from '@playwright/test';
import { URL } from 'node:url';
import { readFile } from 'node:fs/promises';
import AxeBuilder from '@axe-core/playwright';
import { nativeLogin } from '../Support/native-login.mjs';
const fixture = JSON.parse(await readFile('.runtime/e2e.json', 'utf8'));
const url = () => { const address = new URL(fixture.search_url); address.searchParams.set('fi_q', 'browser cache invalidation'); address.searchParams.set('fi_forum', String(fixture.forum)); return address; };
test('administrator ranking details work through the native search form without JavaScript', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false }); const page = await context.newPage();
  try {
    await nativeLogin(page, 'e2e_admin'); await page.goto(url().href, { waitUntil: 'domcontentloaded' });
    const panel = page.locator('.fi-panel').filter({ has: page.getByRole('heading', { name: 'Community search', exact: true }) });
    await expect(panel.locator('.fi-ranking')).toHaveCount(0);
    await panel.getByText('Advanced search', { exact: true }).click();
    const debug = panel.getByRole('checkbox', { name: 'Show ranking explanations' }); await expect(debug).toBeVisible();
    await debug.check(); await panel.getByRole('button', { name: 'Search', exact: true }).click();
    const details = panel.locator('.fi-ranking').first(); await expect(details).toBeVisible();
    await details.locator('summary').click(); await expect(details.getByText('Lexical contribution', { exact: true })).toBeVisible();
    await expect(details.getByText('Semantic contribution', { exact: true })).toBeVisible();
    await expect(details).toContainText('not a probability');
    await panel.getByText('Advanced search', { exact: true }).click(); await debug.uncheck();
    await panel.getByRole('button', { name: 'Search', exact: true }).click(); await expect(panel.locator('.fi-ranking')).toHaveCount(0);
  } finally { await context.close(); }
});
test('ranking controls support keyboard, scoped accessibility and mobile RTL', async ({ page }) => {
  await nativeLogin(page, 'e2e_admin'); const address = url(); address.searchParams.set('fi_explain', '1');
  await page.goto(address.href, { waitUntil: 'domcontentloaded' });
  const details = page.locator('.fi-ranking').first(); await expect(details).toBeVisible();
  await details.locator('summary').focus(); await page.keyboard.press('Enter'); await expect(details).toHaveAttribute('open', '');
  await expect(details.getByText('Vote contribution', { exact: true })).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
  const box = await details.boundingBox(); expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(392);
  const accessibility = await new AxeBuilder({ page }).include('.fi-ranking').analyze(); expect(accessibility.violations).toEqual([]);
});
test('guest and participant cannot enable administrator ranking details with a query parameter', async ({ browser }) => {
  for (const login of [false, true]) {
    const context = await browser.newContext(); const page = await context.newPage();
    try {
      if (login) await nativeLogin(page, 'e2e_author');
      const address = url(); address.searchParams.set('fi_explain', '1'); await page.goto(address.href, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[name="fi_explain"]')).toHaveCount(0); await expect(page.locator('.fi-ranking')).toHaveCount(0);
      await expect(page.getByText('CANARY_HIDDEN', { exact: false })).toHaveCount(0);
    } finally { await context.close(); }
  }
});
