import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
test.describe('Packaged encrypted composer copies', () => {
  test.skip(process.env.FI_RELEASE_E2E !== '1', 'Requires the isolated packaged release site.');
  test.use({ baseURL: 'http://127.0.0.1:8899' });
  let state;
  const fixture = (command) => {
    const output = execFileSync('rtk', ['proxy', process.env.FI_PHP || 'C:/xampp/php/php.exe', 'tools/release-fixture.php', command], { encoding: 'utf8', windowsHide: true });
    return JSON.parse(output.trim().split(/\r?\n/).findLast(line => line.startsWith('{"fixture"')));
  };
  test.beforeAll(() => { state = fixture('seed'); });
  test.afterAll(() => { if (state) fixture('cleanup'); });
  test('installed module encrypts offline and verifies current identity before safe recovery', async ({ page, context }) => {
    test.setTimeout(90000);
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
    await page.goto(state.fixture.url, { waitUntil: 'domcontentloaded' });
    const panel = page.locator('[data-fi-offline-draft="reply"]'); await expect(panel).toBeVisible(); await panel.locator('summary').click();
    const secret = 'installed browser test passphrase';
    await panel.locator('[data-fi-offline-passphrase]').fill(secret);
    await panel.locator('[data-fi-offline-action="unlock"]').click();
    await expect(panel.getByRole('status')).toContainText('unlocked');
    const nativeNonce = await page.locator('input[name="_fi_nonce"]').first().inputValue();
    const identity = await page.evaluate(() => ({ owner: window.fiConfig.draftOwner, blog: window.fiConfig.draftBlog }));
    for (const operation of ['composer_preview', 'offline_restore']) {
      const blocked = await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: {
        action: 'fi_action', operation, _fi_nonce: nativeNonce, 'data[object]': String(state.fixture.topic), 'data[type]': 'reply',
        'data[owner]': String(identity.owner), 'data[blog]': String(identity.blog), 'data[body]': 'PACKAGED_NATIVE_CANARY_DO_NOT_STORE',
      } });
      expect(blocked.status()).toBe(400); expect(await blocked.text()).not.toContain('PACKAGED_NATIVE_CANARY_DO_NOT_STORE');
    }
    await context.setOffline(true);
    await page.locator('#bbp_reply_content').fill('<p>PACKAGED_OFFLINE_CANARY متن 😀</p><script>window.fiPackagedUnsafe=true</script>');
    await panel.locator('[data-fi-offline-action="save"]').click();
    await expect(panel.getByRole('status')).toContainText('saved for 24 hours');
    const stored = await page.evaluate(() => Object.entries(window.localStorage).filter(([key]) => key.startsWith('fi:encrypted-draft:v1:')));
    expect(stored).toHaveLength(1); expect(JSON.stringify(stored)).not.toContain('PACKAGED_OFFLINE_CANARY'); expect(JSON.stringify(stored)).not.toContain(secret);
    await page.locator('#bbp_reply_content').fill('');
    await context.setOffline(false);
    const changedIdentity = await page.evaluate(async (object) => {
      const endpoint = new window.URL(window.fiConfig.rest);
      if (endpoint.searchParams.has('rest_route')) {
        endpoint.searchParams.set('rest_route', endpoint.searchParams.get('rest_route').replace(/\/$/, '') + '/actions/offline_restore');
      } else { endpoint.pathname = endpoint.pathname.replace(/\/$/, '') + '/actions/offline_restore'; }
      const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.fiConfig.nonce }, body: JSON.stringify({ data: { object, owner: window.fiConfig.draftOwner + 1, blog: window.fiConfig.draftBlog, type: 'reply', title: '', body: 'IDENTITY_CHANGED_CANARY' } }) });
      return response.status;
    }, state.fixture.topic);
    expect(changedIdentity).toBe(403);
    const tools = page.locator('[data-fi-composer-tools="reply"]');
    await expect(tools).toBeVisible();
    await panel.locator('[data-fi-offline-action="recover"]').click();
    await expect(panel.getByRole('status')).toContainText('recovered after current permission checks');
    const recovered = await page.locator('#bbp_reply_content').inputValue();
    expect(recovered).toContain('PACKAGED_OFFLINE_CANARY'); expect(recovered).not.toContain('<script'); expect(await page.evaluate(() => window.fiPackagedUnsafe)).toBeUndefined();
    await tools.locator('[data-fi-preview]').click(); await expect(tools.getByRole('status')).toContainText('Preview ready');
    await expect(page.frameLocator('[data-fi-composer-tools="reply"] iframe').locator('p').first()).toContainText('PACKAGED_OFFLINE_CANARY');
    await tools.locator('[data-fi-preview-close]').click();
    await panel.locator('[data-fi-offline-action="remove"]').click(); await expect(panel.getByRole('status')).toContainText('removed');
    expect(await page.evaluate(() => Object.keys(window.localStorage).filter(key => key.startsWith('fi:encrypted-draft:v1:')))).toEqual([]);
  });
});
