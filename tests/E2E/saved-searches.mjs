import { nativeLogin } from '../Support/native-login.mjs';
import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import AxeBuilder from '@axe-core/playwright';
const fixture = JSON.parse(await readFile('.runtime/e2e.json', 'utf8'));
const login = page => nativeLogin(page, 'e2e_member');test('saved-search alert opt-in, opt-out and removal work without JavaScript', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  try {
    await login(page); await page.goto(fixture.hub_url, { waitUntil: 'domcontentloaded' });
    const panel = page.locator('.fi-panel').filter({ has: page.getByRole('heading', { name: 'Saved searches', exact: true }) });
    await panel.getByRole('textbox', { name: 'Keywords', exact: true }).fill('browser cache invalidation');
    await panel.getByRole('button', { name: 'Save search', exact: true }).click();
    await expect(panel.getByRole('button', { name: 'Enable matching alerts', exact: true })).toBeVisible();
    await panel.getByRole('button', { name: 'Enable matching alerts', exact: true }).click();
    await expect(panel.getByRole('button', { name: 'Disable matching alerts', exact: true })).toBeVisible();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(panel.getByRole('button', { name: 'Disable matching alerts', exact: true })).toBeVisible();
    const accessible = await browser.newContext(); const a11yPage = await accessible.newPage();
    try {
      await login(a11yPage); await a11yPage.goto(fixture.hub_url, { waitUntil: 'domcontentloaded' });
      const results = await new AxeBuilder({ page: a11yPage }).include('.fi-panel').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
      expect(results.violations).toEqual([]);
    } finally { await accessible.close(); }
    await panel.getByRole('button', { name: 'Disable matching alerts', exact: true }).click();
    await expect(panel.getByRole('button', { name: 'Enable matching alerts', exact: true })).toBeVisible();
    await panel.getByRole('button', { name: 'Remove saved search', exact: true }).click();
    await expect(panel.getByRole('button', { name: 'Enable matching alerts', exact: true })).toHaveCount(0);
  } finally { await context.close(); }
});
