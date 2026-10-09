import { nativeLogin } from '../Support/native-login.mjs';
import { URL } from 'node:url';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFile } from 'node:fs/promises';
const fixture = JSON.parse(await readFile('.runtime/e2e.json', 'utf8'));
const login = page => nativeLogin(page, 'e2e_member');test('owned notification filters, UTC groups, deletion and unread indicator', async ({ browser }) => {
  test.setTimeout(90000);
  const native = await browser.newContext({ javaScriptEnabled: false });
  const enhanced = await browser.newContext();
  try {
    const page = await native.newPage(); await login(page);
    await page.goto(fixture.hub_url, { waitUntil: 'domcontentloaded' });
    const center = page.locator('[data-fi-center]');
    await expect(center.getByRole('heading', { name: '2020-01-01 UTC', exact: true })).toBeVisible();
    await expect(center.getByRole('heading', { name: '2020-01-02 UTC', exact: true })).toBeVisible();
    await expect(center.getByText('CANARY_HIDDEN', { exact: false })).toHaveCount(0);
    await center.getByRole('combobox', { name: 'Notification category' }).selectOption('reply');
    await center.getByRole('combobox', { name: 'Notification status' }).selectOption('unread');
    await center.getByRole('button', { name: 'Filter notifications', exact: true }).click();
    await expect(center.getByRole('heading', { name: '2020-01-02 UTC', exact: true })).toHaveCount(0);
    const row = center.locator('li').filter({ has: page.getByRole('link', { name: /Browser cache invalidation question/ }) }).first();
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Change read status', exact: true }).click();
    await expect(center.getByRole('heading', { name: '2020-01-01 UTC', exact: true })).toHaveCount(0);
    await center.getByRole('combobox', { name: 'Notification status' }).selectOption('read');
    await center.getByRole('button', { name: 'Filter notifications', exact: true }).click();
    await expect(center.getByRole('heading', { name: '2020-01-01 UTC', exact: true })).toBeVisible();
    await center.locator('li').first().getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect(center.getByRole('heading', { name: '2020-01-01 UTC', exact: true })).toHaveCount(0);
    await expect(page.locator('[data-fi-unread-label]')).toHaveText('Notifications');
    const live = await enhanced.newPage(); await login(live);
    const failed = [];
    live.on('requestfailed', (request) => { if (request.url().includes('notification_summary')) failed.push(request.failure()?.errorText); });
    const response = live.waitForResponse((candidate) => candidate.url().includes('notification_summary'));
    await live.goto(fixture.hub_url, { waitUntil: 'domcontentloaded' });
    const summary = await response; expect(summary.status()).toBe(200);
    expect(summary.headers()['cache-control']).toContain('no-store');
    const data = await summary.json();
    await expect(live.locator('[data-fi-unread-label]')).toHaveText(data.label);
    expect(data.unread).toBeGreaterThan(0); expect(failed).toEqual([]);
    const url = new URL(fixture.hub_url); url.searchParams.set('fi_inbox_kind', 'mention'); url.searchParams.set('fi_inbox_state', 'unread');
    await live.goto(url.href, { waitUntil: 'domcontentloaded' });
    await expect(live.locator('[data-fi-center]').getByRole('heading', { name: '2020-01-02 UTC', exact: true })).toBeVisible();
    const axe = await new AxeBuilder({ page: live }).include('[data-fi-center]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations).toEqual([]);
    await live.setViewportSize({ width: 390, height: 844 });
    await live.evaluate(() => { document.documentElement.dir = 'rtl'; });
    expect(await live.locator('[data-fi-center]').evaluate((element) => element.getBoundingClientRect().right > window.innerWidth + 1 || element.getBoundingClientRect().left < -1)).toBe(false);
    await live.route('**/*notification_summary*', (route) => route.fulfill({
      status: 401, contentType: 'application/json', body: JSON.stringify({ message: 'Sign in again.' }),
    }));
    const rejected = live.waitForResponse((candidate) => candidate.url().includes('notification_summary') && candidate.status() === 401);
    await live.evaluate(() => {
      const original = Date.now;
      Date.now = () => original() + 61000;
      window.dispatchEvent(new Event('focus'));
      Date.now = original;
    });
    await rejected;
    await expect(live.locator('[data-fi-unread-label]')).toHaveText('Notifications');
  } finally { await native.close(); await enhanced.close(); }
});
