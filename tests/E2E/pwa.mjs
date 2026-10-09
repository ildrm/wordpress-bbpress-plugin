import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
const fixture = JSON.parse(await readFile('.runtime/e2e.json', 'utf8'));
test.describe('Optional PWA browser behavior', () => {
  test.skip(process.env.FI_PWA_E2E !== '1', 'Requires an explicitly provisioned isolated PWA fixture.');
  const run = (action) => execFileSync(process.env.FI_PHP || 'C:/xampp/php/php.exe', ['tools/pwa-fixture.php', action], { encoding: 'utf8' });
  test.beforeAll(() => { run('seed'); });
  test.afterAll(() => { run('cleanup'); });
  test('valid manifest and activated worker provide a generic offline page without content caches', async ({ page, context }) => {
    await page.goto(fixture.search_url, { waitUntil: 'domcontentloaded' });
    const manifest = await page.request.get('/?fi_manifest=1');
    expect(manifest.headers()['content-type']).toContain('application/manifest+json');
    const json = await manifest.json(); expect(json.scope).toBe('/'); expect(json.display).toBe('standalone');
    for (const icon of json.icons) {
      const response = await page.request.get(icon.src); expect(response.ok()).toBeTruthy();
      const bytes = await response.body(); expect(bytes.subarray(1, 4).toString()).toBe('PNG');
    }
    const cdp = await context.newCDPSession(page); await cdp.send('Page.enable');
    const details = await cdp.send('Page.getAppManifest'); expect(details.errors).toEqual([]);
    await expect.poll(() => page.evaluate(async () => (await navigator.serviceWorker.getRegistration('/'))?.active?.scriptURL || '')).toContain('fi_worker=1');
    await page.reload({ waitUntil: 'domcontentloaded' });
    expect(await page.evaluate(() => window.caches.keys())).toEqual([]);
    await context.setOffline(true);
    await page.goto(fixture.messages_url, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('You are offline. Reconnect to read discussions or send messages.', { exact: true })).toBeVisible();
    expect(await page.content()).not.toContain('E2E_PRIVATE_MESSAGE_CANARY');
    expect(await page.evaluate(() => window.caches.keys())).toEqual([]);
    await context.setOffline(false);
  });
  test('a different worker with the same query flag retains its registration', async ({ page }) => {
    await page.goto(fixture.search_url, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => navigator.serviceWorker.ready);
    await page.evaluate(async () => {
      const registration = await navigator.serviceWorker.register('/fi-e2e-foreign-worker.js?fi_worker=1', { scope: '/' });
      const worker = registration.installing || registration.waiting;
      if (worker && worker.state !== 'activated') await new Promise((resolve) => worker.addEventListener('statechange', () => { if (worker.state === 'activated') resolve(); }));
    });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);
    expect(await page.evaluate(async () => (await navigator.serviceWorker.getRegistration('/')).active.scriptURL)).toContain('/fi-e2e-foreign-worker.js');
  });
});
