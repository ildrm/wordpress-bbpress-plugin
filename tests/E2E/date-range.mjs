import { URL } from 'node:url';
import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
const fixture = JSON.parse(await readFile('.runtime/e2e.json', 'utf8'));
test('publication date range works without JavaScript and invalid ranges keep the search form usable', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false }); const page = await context.newPage();
  try {
    await page.goto(fixture.search_url, { waitUntil: 'domcontentloaded' });
    await page.getByRole('searchbox', { name: 'Search the community' }).fill('browser cache invalidation');
    await page.getByText('Advanced search', { exact: true }).click();
    await page.getByLabel('Published on or before (UTC)', { exact: true }).fill('2000-01-01');
    await page.locator('.fi-panel').getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('.fi-panel a').filter({ hasText: 'Browser cache invalidation question' })).toHaveCount(0);
    await page.getByText('Advanced search', { exact: true }).click();
    await page.getByLabel('Published on or after (UTC)', { exact: true }).fill('2000-01-01');
    await page.getByLabel('Published on or before (UTC)', { exact: true }).fill('2999-01-01');
    await page.locator('.fi-panel').getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('.fi-panel a').filter({ hasText: 'Browser cache invalidation question' }).first()).toBeVisible();
    const url = new URL(fixture.search_url);
    url.searchParams.set('fi_q', 'browser cache invalidation'); url.searchParams.set('fi_after', '2021-01-01'); url.searchParams.set('fi_before', '2020-01-01');
    await page.goto(url.href, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('alert')).toHaveText('The start date must be on or before the end date.');
    await expect(page.getByRole('searchbox', { name: 'Search the community' })).toHaveValue('browser cache invalidation');
    await expect(page.locator('.fi-panel').getByRole('button', { name: 'Search', exact: true })).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByText('Advanced search', { exact: true }).click();
    await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    expect(await page.locator('.fi-panel input').evaluateAll(elements => elements.some(element => element.getBoundingClientRect().left < -1 || element.getBoundingClientRect().right > window.innerWidth + 1))).toBe(false);
  } finally { await context.close(); }
});
