import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFile } from 'node:fs/promises';
const fixture = JSON.parse(await readFile('.runtime/e2e.json', 'utf8'));
const passphrase = 'browser copy test passphrase 2026';
const login = async (page) => {
  await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#loginform').evaluate(form => {
    form.querySelector('#user_login').value = 'e2e_member';
    form.querySelector('#user_pass').value = 'test-browser-only';
  });
  const [response] = await Promise.all([
    page.waitForResponse(result => result.request().method() === 'POST' && result.url().includes('/wp-login.php')),
    page.locator('#wp-submit').click(),
  ]);
  expect([302, 303]).toContain(response.status());
  await expect.poll(async () => (await page.context().cookies()).some(cookie => cookie.name.startsWith('wordpress_logged_in_'))).toBe(true);
};
const open = async (page, type = 'reply') => {
  await page.goto(type === 'reply' ? fixture.topic_url : fixture.forum_url, { waitUntil: 'domcontentloaded' });
  const panel = page.locator('[data-fi-offline-draft="' + type + '"]');
  await expect(panel).toBeVisible(); await panel.locator('summary').click();
  return panel;
};
const unlock = async (panel, phrase = passphrase) => {
  await panel.locator('[data-fi-offline-passphrase]').fill(phrase);
  await panel.locator('[data-fi-offline-action="unlock"]').click();
};
const bodyValue = async (page, text) => page.evaluate((value) => {
  const textarea = document.querySelector('#bbp_reply_content') || document.querySelector('#bbp_topic_content');
  textarea.value = value; window.tinymce?.get(textarea.id)?.setContent(value);
}, text);
test('opt-in encrypted copies save offline and recover only online without leaking the passphrase', async ({ page, context }) => {
  test.setTimeout(90000);
  const networkBodies = [];
  page.on('request', request => { if (request.postData()) networkBodies.push(request.postData()); });
  await login(page); const panel = await open(page);
  const password = panel.locator('[data-fi-offline-passphrase]');
  await expect(panel.locator('[data-fi-offline-action="save"]')).toBeDisabled();
  expect(await password.getAttribute('name')).toBeNull(); expect(await password.getAttribute('required')).toBeNull();
  await unlock(panel); await expect(panel.getByRole('status')).toContainText('unlocked');
  await expect(password).toHaveValue('');
  await context.setOffline(true);
  await bodyValue(page, '<p>OFFLINE_BROWSER_PRIVATE_CANARY متن 😀</p><script>window.fiUnsafeRestore=true</script><img src="x" onerror="window.fiUnsafeRestore=true">');
  await panel.locator('[data-fi-offline-action="save"]').click();
  await expect(panel.getByRole('status')).toContainText('saved for 24 hours');
  const stored = await page.evaluate(() => Object.entries(window.localStorage).filter(([key]) => key.startsWith('fi:encrypted-draft:v1:')));
  expect(stored).toHaveLength(1); expect(JSON.stringify(stored)).not.toContain('OFFLINE_BROWSER_PRIVATE_CANARY');
  expect(JSON.stringify(stored)).not.toContain(passphrase);
  await bodyValue(page, '');
  await panel.locator('[data-fi-offline-action="recover"]').click();
  await expect(panel.getByRole('status')).toContainText('online permission check');
  await expect(page.locator('#bbp_reply_content')).toHaveValue('');
  await context.setOffline(false); await unlock(panel);
  await expect(panel.getByRole('status')).toContainText('unlocked');
  await bodyValue(page, 'Unsaved current text must survive.');
  await panel.locator('[data-fi-offline-action="recover"]').click();
  await expect(panel.getByRole('status')).toContainText('current text was kept');
  await expect(page.locator('#bbp_reply_content')).toHaveValue('Unsaved current text must survive.');
  await bodyValue(page, '');
  await panel.locator('[data-fi-offline-action="recover"]').click();
  await expect(panel.getByRole('status')).toContainText('recovered after current permission checks');
  const restored = await page.locator('#bbp_reply_content').inputValue();
  expect(restored).toContain('OFFLINE_BROWSER_PRIVATE_CANARY'); expect(restored).not.toContain('<script');
  expect(restored).not.toContain('onerror'); expect(await page.evaluate(() => window.fiUnsafeRestore)).toBeUndefined();
  await panel.locator('[data-fi-offline-action="lock"]').click(); await unlock(panel, 'incorrect browser copy passphrase');
  await expect(panel.getByRole('status')).toContainText('could not be opened');
  await unlock(panel); await expect(panel.getByRole('status')).toContainText('unlocked');
  await panel.locator('[data-fi-offline-action="lock"]').click();
  await page.evaluate(() => {
    const key = Object.keys(window.localStorage).find(key => key.startsWith('fi:encrypted-draft:v1:'));
    const record = JSON.parse(window.localStorage.getItem(key));
    record.expires = Date.now() - 1; record.created = record.expires - 24 * 60 * 60 * 1000;
    window.localStorage.setItem(key, JSON.stringify(record));
  });
  await unlock(panel); await expect(panel.getByRole('status')).toContainText('expired');
  await panel.locator('[data-fi-offline-action="remove"]').click();
  await expect(panel.getByRole('status')).toContainText('removed');
  expect(await page.evaluate(() => Object.keys(window.localStorage).filter(key => key.startsWith('fi:encrypted-draft:v1:')))).toEqual([]);
  expect(networkBodies.join('\n')).not.toContain(passphrase);
});
test('browser copy failures preserve native posting, with keyboard, mobile RTL and accessibility checks', async ({ page }) => {
  test.setTimeout(90000); await login(page); const panel = await open(page, 'topic');
  await panel.locator('[data-fi-offline-passphrase]').focus(); await page.keyboard.press('Tab');
  await expect(panel.locator('[data-fi-offline-action="unlock"]')).toBeFocused();
  await panel.locator('[data-fi-offline-passphrase]').fill(passphrase);
  await panel.locator('[data-fi-offline-passphrase]').press('Enter');
  await expect(panel.getByRole('status')).toContainText('unlocked');
  await page.evaluate(() => {
    const original = window.Storage.prototype.setItem;
    window.Storage.prototype.setItem = function (key, value) {
      if (key.startsWith('fi:encrypted-draft:v1:')) throw new window.DOMException('Quota exceeded', 'QuotaExceededError');
      return original.call(this, key, value);
    };
  });
  await page.locator('#bbp_topic_title').fill('Native topic title');
  await bodyValue(page, 'Native topic text survives failed encrypted storage.');
  await panel.locator('[data-fi-offline-action="save"]').click();
  await expect(panel.getByRole('status')).toContainText('unavailable');
  await expect(page.locator('#bbp_topic_content')).toHaveValue('Native topic text survives failed encrypted storage.');
  const nativeForm = page.locator('#bbp_topic_content').locator('xpath=ancestor::form');
  expect(await nativeForm.evaluate(form => form.checkValidity())).toBe(true);
  await expect(nativeForm.locator('#bbp_topic_submit')).toBeEnabled();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
  expect(await panel.evaluate(region => [...region.querySelectorAll('input,button')].some(element => {
    const bounds = element.getBoundingClientRect();
    return bounds.width > 0 && (bounds.right > window.innerWidth + 1 || bounds.left < -1);
  }))).toBe(false);
  const axe = await new AxeBuilder({ page }).include('[data-fi-offline-draft]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(axe.violations).toEqual([]);
});
test('guests receive no encrypted-copy controls', async ({ page }) => {
  await page.goto(fixture.topic_url, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-fi-offline-draft]')).toHaveCount(0);
});

test('rich editor receives only server-sanitized decrypted text', async ({ page }) => {
  test.skip(process.env.FI_RICH_DRAFT_E2E !== '1', 'Requires the owned native TinyMCE fixture.');
  test.setTimeout(90000); await login(page); const panel = await open(page);
  await expect.poll(() => page.evaluate(() => !!window.tinymce?.get('bbp_reply_content')?.initialized)).toBe(true);
  await unlock(panel); await expect(panel.getByRole('status')).toContainText('unlocked');
  await bodyValue(page, '<p>RICH_OFFLINE_CANARY متن 😀</p>');
  await panel.locator('[data-fi-offline-action="save"]').click();
  await expect(panel.getByRole('status')).toContainText('saved for 24 hours');
  await page.locator('#bbp_reply_content-html').click();
  await expect(page.locator('#bbp_reply_content')).toBeVisible();
  await page.locator('#bbp_reply_content').fill('<p>TEXT_MODE_OFFLINE_CANARY متن 😀</p>');
  await panel.locator('[data-fi-offline-action="save"]').click();
  await expect(panel.getByRole('status')).toContainText('saved for 24 hours');
  await page.locator('#bbp_reply_content').fill('');
  await panel.locator('[data-fi-offline-action="recover"]').click();
  await expect(panel.getByRole('status')).toContainText('recovered after current permission checks');
  await expect(page.locator('#bbp_reply_content')).toHaveValue('<p>TEXT_MODE_OFFLINE_CANARY متن 😀</p>');
  await expect(page.locator('[data-fi-draft-status="reply"]')).toHaveText('Draft saved on this site.', { timeout: 15000 });
  const savedBody = await page.evaluate(async () => {
    const endpoint = new window.URL(window.fiConfig.rest);
    if (endpoint.searchParams.has('rest_route')) endpoint.searchParams.set('rest_route', endpoint.searchParams.get('rest_route').replace(/\/$/, '') + '/draft');
    else endpoint.pathname = endpoint.pathname.replace(/\/$/, '') + '/draft';
    endpoint.searchParams.set('object', document.querySelector('[name="bbp_topic_id"]').value);
    endpoint.searchParams.set('type', 'reply');
    return (await (await fetch(endpoint, { credentials: 'same-origin', headers: { 'X-WP-Nonce': window.fiConfig.nonce } })).json()).body;
  });
  expect(savedBody).toContain('TEXT_MODE_OFFLINE_CANARY'); expect(savedBody).not.toContain('RICH_OFFLINE_CANARY');
  await page.locator('#bbp_reply_content-tmce').click();
  await expect.poll(() => page.evaluate(() => window.tinymce.get('bbp_reply_content').isHidden())).toBe(false);
  await panel.locator('[data-fi-offline-action="lock"]').click();
  // Model an untrusted encrypted payload without inserting it into TinyMCE before sanitization.
  await page.evaluate(async (passphrase) => {
    const { createVault } = await import(window.fiConfig.offlineDraftModule);
    const scope = { origin: window.location.origin, blog: window.fiConfig.draftBlog, owner: window.fiConfig.draftOwner, type: 'reply', object: Number(document.querySelector('[name="bbp_topic_id"]').value) };
    const vault = await createVault(scope, window.localStorage, window.crypto, Date.now, callback => navigator.locks.request('fi-encrypted-draft:' + JSON.stringify(scope), callback));
    await vault.unlock(passphrase);
    await vault.save({ title: '', body: '<p>RICH_OFFLINE_CANARY متن 😀</p><script>window.fiUnsafeRich=true</script><img src="x" onerror="window.fiUnsafeRich=true"><iframe srcdoc="<script>window.fiUnsafeRich=true</script>"></iframe>' });
    vault.lock();
  }, passphrase);
  await bodyValue(page, ''); await unlock(panel); await expect(panel.getByRole('status')).toContainText('unlocked');
  await panel.locator('[data-fi-offline-action="recover"]').click();
  await expect(panel.getByRole('status')).toContainText('recovered after current permission checks');
  const rich = await page.evaluate(() => window.tinymce.get('bbp_reply_content').getContent());
  expect(rich).toContain('RICH_OFFLINE_CANARY'); expect(rich).not.toContain('<script'); expect(rich).not.toContain('onerror'); expect(rich).not.toContain('<iframe');
  expect(await page.evaluate(() => window.fiUnsafeRich)).toBeUndefined();
  await panel.locator('[data-fi-offline-action="remove"]').click(); await expect(panel.getByRole('status')).toContainText('removed');
});
