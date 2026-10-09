import { nativeLogin } from '../Support/native-login.mjs';
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
test.describe('Packaged contextual question guidance', () => {
  test.skip(process.env.FI_RELEASE_E2E !== '1', 'Requires the isolated packaged release site.');
  test.use({ baseURL: 'http://127.0.0.1:8899' });
  let state;
  const fixture = (command) => {
    const output = execFileSync('rtk', ['proxy', process.env.FI_PHP || 'C:/xampp/php/php.exe', 'tools/release-fixture.php', command], { encoding: 'utf8', windowsHide: true });
    return JSON.parse(output.trim().split(/\r?\n/).findLast(line => line.startsWith('{"fixture"')));
  };
  test.beforeAll(() => { state = fixture('seed'); state = fixture('templates'); });
  test.afterAll(() => { if (state) fixture('cleanup'); });
  test('installed native catalog and optional selectors prefill explicitly and preserve occupied content', async ({ page, context }) => {
    await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
    await page.locator('#loginform').evaluate(form => {
      form.querySelector('#user_login').value = 'release_admin';
      form.querySelector('#user_pass').value = 'isolated-release-only';
    });
    const [login] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-login.php')),
      page.locator('#wp-submit').click(),
    ]);
    expect([302, 303]).toContain(login.status());
    await expect.poll(async () => (await context.cookies()).some(cookie => cookie.name.startsWith('wordpress_logged_in_'))).toBe(true);
    await page.goto('/?post_type=forum&p=' + state.fixture.forum, { waitUntil: 'domcontentloaded' });
    const panel = page.locator('[data-fi-template-controls]');
    const native = page.locator('[data-fi-template-guidance]');
    await expect(panel).toBeVisible();
    await expect(native).toContainText('Installed support guidance');
    for (const [axis, value] of [['topic_type', 'Support'], ['product', 'Widget'], ['category', 'Troubleshooting']]) {
      const select = panel.locator('[data-fi-template-axis="' + axis + '"]');
      await expect(select).toBeVisible();
      expect(await select.getAttribute('name')).toBeNull(); expect(await select.getAttribute('required')).toBeNull();
      await select.selectOption(value);
      await expect(panel.getByRole('status')).not.toContainText('Finding template guidance');
    }
    const use = panel.getByRole('button', { name: 'Use this question template' });
    await expect(use).toBeVisible();
    expect(await page.locator('#bbp_topic_content').inputValue()).toBe('');
    await use.click();
    await expect(page.locator('#bbp_topic_content')).toHaveValue(/INSTALLED_CONTEXT_TEMPLATE/);
    await page.locator('#bbp_topic_content').fill('OCCUPIED_INSTALLED_TEMPLATE_CANARY');
    await panel.locator('[data-fi-template-axis="category"]').selectOption('');
    await panel.locator('[data-fi-template-axis="category"]').selectOption('Troubleshooting');
    await expect(use).toBeVisible(); await use.click();
    await expect(page.locator('#bbp_topic_content')).toHaveValue('OCCUPIED_INSTALLED_TEMPLATE_CANARY');
    await expect(panel.getByRole('status')).toContainText('Your existing text was kept');
    await page.locator('#bbp_topic_title').fill('Installed contextual guidance verification');
    expect(await page.locator('#bbp_topic_content').evaluate(node => node.closest('form').checkValidity())).toBe(true);
  });
  test('installed search returns additive diagnostics only for a currently authenticated administrator', async ({ page, request }) => {
    await nativeLogin(page, 'release_admin', 'isolated-release-only');
    await page.goto(state.fixture.url, { waitUntil: 'domcontentloaded' });
    const actual = await page.evaluate(async (forum) => {
      const endpoint = new window.URL(window.fiConfig.rest);
      if (endpoint.searchParams.has('rest_route')) endpoint.searchParams.set('rest_route', endpoint.searchParams.get('rest_route').replace(/\/$/, '') + '/search');
      else endpoint.pathname = endpoint.pathname.replace(/\/$/, '') + '/search';
      endpoint.searchParams.set('q', 'Actual multipart upload test'); endpoint.searchParams.set('forum', String(forum)); endpoint.searchParams.set('explain', 'true');
      const response = await fetch(endpoint, { credentials: 'same-origin', headers: { 'X-WP-Nonce': window.fiConfig.nonce } });
      return { status: response.status, cache: response.headers.get('Cache-Control'), data: await response.json() };
    }, state.fixture.forum);
    expect(actual.status).toBe(200); expect(actual.cache).toContain('no-store');
    const item = actual.data.items.find(result => result.id === state.fixture.topic);
    expect(item.ranking).toBeDefined(); expect(item.ranking.score).toBe(item.score);
    expect(Object.values(item.ranking.contributions).reduce((sum, contribution) => sum + contribution, 0)).toBe(item.score);
    const anonymous = await request.get('/?rest_route=/forum-intelligence/v1/search&q=Actual%20multipart%20upload%20test&forum=' + state.fixture.forum + '&explain=true');
    expect(anonymous.status()).toBe(200);
    const publicItem = (await anonymous.json()).items.find(result => result.id === state.fixture.topic);
    expect(publicItem).toBeDefined(); expect(publicItem.ranking).toBeUndefined();
  });
});
