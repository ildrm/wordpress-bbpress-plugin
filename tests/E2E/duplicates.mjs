import { nativeLogin } from '../Support/native-login.mjs';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { URL } from 'node:url';
const fixture = command => {
  const output = execFileSync(process.env.FI_PHP || 'C:/xampp/php/php.exe', ['tools/duplicate-fixture.php', command], { encoding: 'utf8' });
  const line = output.split(/\r?\n/).find(value => value.startsWith('{"fixture":'));
  if (!line) throw new Error('No owned duplicate fixture result');
  return JSON.parse(line).fixture;
};
test.use({ baseURL: process.env.FI_RELEASE_E2E === '1' ? 'http://127.0.0.1:8899' : 'http://127.0.0.1:8898' });
let state;
const isFeedback = response => { const url = new URL(response.url()); return (url.searchParams.get('rest_route') || url.pathname).endsWith('/actions/duplicate_feedback'); };
test.describe.serial('Native duplicate-prevention composer', () => {
  test.beforeAll(() => { state = fixture('seed'); });
  test.afterAll(() => { fixture('cleanup'); });
  const composer = async page => {
    await nativeLogin(page, state.login, 'isolated-duplicate-only');
    const url = new URL(state.forum_url);
    await page.goto(url.pathname + url.search, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#bbp_topic_title')).toBeVisible();
  };
  test('debounced native suggestions expose relevance, authorized accepted preview and optional keyboard dismissal', async ({ page }, info) => {
    await composer(page);
    const title = page.locator('#bbp_topic_title'); const body = page.locator('#bbp_topic_content'); const panel = page.locator('[data-fi-duplicates]');
    let requests = 0; page.on('request', request => { if (request.method() === 'POST' && request.url().includes('duplicates')) ++requests; });
    await title.fill('short'); await page.waitForTimeout(700); expect(requests).toBe(0);
    await body.fill('UNSAVED_BROWSER_DUPLICATE_CANARY');
    const responsePromise = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('duplicates'));
    await title.fill('Browser duplicate'); await title.fill('Browser duplicate cache'); await title.fill('Browser duplicate cache invalidation question');
    const response = await responsePromise; expect(response.status()).toBe(200); expect(requests).toBe(1);
    const data = await response.json(); expect(data.offer).toBeTruthy();
    await expect(panel.getByText('OWNED_DUPLICATE_ACCEPTED_PREVIEW:', { exact: false })).toBeVisible();
    await expect(panel.getByText('Relevance score:', { exact: false })).toBeVisible();
    const link = panel.getByRole('link', { name: /Browser duplicate cache invalidation question.*Opens in a new tab/ });
    await expect(link).toHaveAttribute('target', '_blank'); await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    const axe = await new AxeBuilder({ page }).include('[data-fi-duplicates]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze(); expect(axe.violations).toEqual([]);
    await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    expect(await panel.evaluate(region => [...region.querySelectorAll('button,a')].some(element => { const b = element.getBoundingClientRect(); return b.width > 0 && (b.left < -1 || b.right > window.innerWidth + 1); }))).toBe(false);
    await panel.screenshot({ path: info.outputPath('native-duplicate-suggestions-rtl.png') });
    const dismiss = panel.getByRole('button', { name: 'None of these discussions answer my question', exact: true }); await dismiss.focus();
    const feedback = page.waitForResponse(response => response.request().method() === 'POST' && isFeedback(response) && response.request().postData()?.includes('dismissed'));
    await page.keyboard.press('Enter'); expect((await feedback).status()).toBe(200);
    await expect(title).toBeFocused(); await expect(body).toHaveValue('UNSAVED_BROWSER_DUPLICATE_CANARY');
    await expect(panel.getByRole('link')).toHaveCount(0); await expect(panel.getByRole('status')).toContainText('Suggestions dismissed');
    await expect(page.locator('#bbp_topic_submit')).toBeEnabled();
    const replay = await page.request.post('/?rest_route=/forum-intelligence/v1/actions/duplicate_feedback', { headers: { 'X-WP-Nonce': await page.evaluate(() => window.fiConfig.nonce) }, data: { data: { offer: data.offer, outcome: 'dismissed' } } });
    expect(replay.status()).toBe(200); expect(await replay.json()).toEqual({ recorded: false });
  });
  test('opening a suggestion keeps the unsaved composer and acknowledges only the issued topic', async ({ page, context }) => {
    await composer(page); const title = page.locator('#bbp_topic_title'); const body = page.locator('#bbp_topic_content');
    await body.fill('Preserved native question details'); await title.fill('Browser duplicate cache invalidation question');
    const link = page.locator('[data-fi-duplicates]').getByRole('link').first(); await expect(link).toBeVisible();
    const feedback = page.waitForResponse(response => response.request().method() === 'POST' && isFeedback(response) && response.request().postData()?.includes('opened'));
    const opened = context.waitForEvent('page'); await link.focus(); await page.keyboard.press('Enter'); const topic = await opened;
    expect((await feedback).status()).toBe(200); await expect(body).toHaveValue('Preserved native question details'); await topic.close();
  });
  test('changed composer clears old evidence immediately and revoked source feedback fails safely', async ({ page }) => {
    await composer(page); const title = page.locator('#bbp_topic_title'); const panel = page.locator('[data-fi-duplicates]');
    const responsePromise = page.waitForResponse(response => response.url().includes('duplicates') && response.request().method() === 'POST'); await title.fill('Browser duplicate cache invalidation question'); const data = await (await responsePromise).json(); await expect(panel.getByRole('link')).toHaveCount(1);
    const nonce = await page.evaluate(() => window.fiConfig.nonce);
    const missing = await page.request.post('/?rest_route=/forum-intelligence/v1/actions/duplicate_feedback', { data: { data: { offer: data.offer, outcome: 'dismissed' } } }); expect([401,403]).toContain(missing.status());
    fixture('protect');
    const denied = await page.request.post('/?rest_route=/forum-intelligence/v1/actions/duplicate_feedback', { headers: { 'X-WP-Nonce': nonce }, data: { data: { offer: data.offer, outcome: 'dismissed' } } }); expect(denied.status()).toBe(404);
    await title.fill('short'); await expect(panel.getByRole('link')).toHaveCount(0); await expect(panel.getByRole('button')).toBeHidden();
    fixture('restore');
  });
});
