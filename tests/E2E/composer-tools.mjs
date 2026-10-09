import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFile } from 'node:fs/promises';
const fixture = JSON.parse(await readFile('.runtime/e2e.json', 'utf8'));
const login = async (page) => {
  await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#loginform').evaluate(form => {
    form.querySelector('#user_login').value = 'e2e_member'; form.querySelector('#user_pass').value = 'test-browser-only';
  });
  const [response] = await Promise.all([
    page.waitForResponse(result => result.request().method() === 'POST' && result.url().includes('/wp-login.php')),
    page.locator('#wp-submit').click(),
  ]);
  expect([302, 303]).toContain(response.status());
  await expect.poll(async () => (await page.context().cookies()).some(cookie => cookie.name.startsWith('wordpress_logged_in_'))).toBe(true);
};
const open = async (page) => {
  await page.goto(fixture.topic_url, { waitUntil: 'domcontentloaded' });
  const panel = page.locator('[data-fi-composer-tools="reply"]'); await expect(panel).toBeVisible(); return panel;
};
const selectText = async (body, text) => body.evaluate((textarea, text) => {
  const start = textarea.value.indexOf(text); textarea.focus(); textarea.setSelectionRange(start, start + text.length);
}, text);
const previewFrame = (page) => page.frameLocator('[data-fi-composer-tools="reply"] iframe');
test('formatting preserves selection and the authorized preview is inert, accessible and responsive', async ({ page }) => {
  test.setTimeout(90000); await login(page); const panel = await open(page); const body = page.locator('#bbp_reply_content');
  await body.fill('prefix selected متن 😀 suffix'); await selectText(body, 'selected متن 😀');
  await panel.locator('[data-fi-format="bold"]').click();
  await expect(body).toHaveValue('prefix <strong>selected متن 😀</strong> suffix');
  expect(await body.evaluate(textarea => textarea.value.slice(textarea.selectionStart, textarea.selectionEnd))).toBe('selected متن 😀');
  await body.fill('prefix <img src="x" onerror="attack()"> suffix'); await selectText(body, '<img src="x" onerror="attack()">');
  await panel.locator('[data-fi-format="code"]').click();
  await expect(body).toHaveValue('prefix <code>&lt;img src=&quot;x&quot; onerror=&quot;attack()&quot;&gt;</code> suffix');
  const toolbar = panel.getByRole('toolbar', { name: 'Formatting' });
  await toolbar.getByRole('button', { name: 'Bold', exact: true }).focus(); await page.keyboard.press('ArrowRight');
  await expect(toolbar.getByRole('button', { name: 'Italic', exact: true })).toBeFocused();
  await page.keyboard.press('End'); await expect(toolbar.getByRole('button', { name: 'Quote', exact: true })).toBeFocused();
  await page.keyboard.press('Tab'); await expect(panel.locator('[data-fi-preview]')).toBeFocused();
  const external = [];
  page.on('request', request => { if (request.url().includes('preview-never-fetch.example')) external.push(request.url()); });
  const source = '<p><strong>PREVIEW_BROWSER_CANARY متن 😀</strong> <a href="https://preview-never-fetch.example/">link</a></p><img src="https://preview-never-fetch.example/image" onerror="window.fiPreviewUnsafe=true"><iframe src="https://preview-never-fetch.example/"></iframe><script>window.fiPreviewUnsafe=true</script>';
  await body.fill(source); await panel.locator('[data-fi-preview]').click();
  await expect(panel.getByRole('status')).toContainText('Preview ready');
  await expect(previewFrame(page).locator('strong')).toHaveText('PREVIEW_BROWSER_CANARY متن 😀');
  expect(await panel.locator('iframe').getAttribute('sandbox')).toBe('');
  expect(await previewFrame(page).locator('a').getAttribute('href')).toBeNull();
  await expect(previewFrame(page).locator('img,iframe,script,form')).toHaveCount(0);
  expect(await page.evaluate(() => window.fiPreviewUnsafe)).toBeUndefined(); expect(external).toEqual([]);
  await expect(body).toHaveValue(source);
  await expect(panel.locator('iframe')).toHaveAttribute('title', 'Discussion preview');
  await expect(previewFrame(page).locator('html')).toHaveAttribute('lang', /[a-zA-Z]{2}/);
  await expect(previewFrame(page).locator('main')).toBeVisible();
  // Keep the production sandbox intact; axe cannot inject scripts into this inert frame.
  const axe = await new AxeBuilder({ page }).include('[data-fi-composer-tools]').exclude('[data-fi-composer-tools] iframe').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(axe.violations).toEqual([]);
  await panel.locator('[data-fi-preview-close]').click(); await expect(panel.locator('[data-fi-preview]')).toBeFocused();
  await expect(panel.locator('[data-fi-preview]')).toHaveAttribute('aria-expanded', 'false');
  await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
  await toolbar.getByRole('button', { name: 'Bold', exact: true }).focus(); await page.keyboard.press('ArrowLeft');
  await expect(toolbar.getByRole('button', { name: 'Italic', exact: true })).toBeFocused();
  expect(await panel.evaluate(region => [...region.querySelectorAll('button,iframe')].some(element => {
    const bounds = element.getBoundingClientRect(); return bounds.width > 0 && (bounds.right > window.innerWidth + 1 || bounds.left < -1);
  }))).toBe(false);
});
test('editing during preview and a failed request preserve current text and native posting', async ({ page }) => {
  test.setTimeout(90000); await login(page); const panel = await open(page); const body = page.locator('#bbp_reply_content');
  const nativeNonce = await page.locator('input[name="_fi_nonce"]').first().inputValue();
  const identity = await page.evaluate(() => ({ owner: window.fiConfig.draftOwner, blog: window.fiConfig.draftBlog }));
  for (const operation of ['composer_preview', 'offline_restore']) {
    const blocked = await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: {
      action: 'fi_action', operation, _fi_nonce: nativeNonce, 'data[object]': String(fixture.topic), 'data[type]': 'reply',
      'data[owner]': String(identity.owner), 'data[blog]': String(identity.blog), 'data[body]': 'NATIVE_PREPARATION_CANARY_DO_NOT_STORE',
    } });
    expect(blocked.status()).toBe(400); expect(await blocked.text()).not.toContain('NATIVE_PREPARATION_CANARY_DO_NOT_STORE');
  }
  await body.fill('Original preview text');
  let resume; const held = new Promise(resolve => { resume = resolve; });
  let notify; const reached = new Promise(resolve => { notify = resolve; });
  await page.route('**/*', async route => {
    if (route.request().method() === 'POST' && route.request().url().includes('composer_preview')) { notify(); await held; }
    await route.continue();
  });
  await panel.locator('[data-fi-preview]').click(); await reached;
  await body.fill('New text typed while preview was preparing.'); resume();
  await expect(panel.locator('[data-fi-preview]')).toBeEnabled();
  await expect(panel.getByRole('status')).toContainText('text changed'); await expect(panel.locator('iframe')).toBeHidden();
  await expect(body).toHaveValue('New text typed while preview was preparing.');
  await page.unroute('**/*');
  await page.route('**/*', async route => {
    if (route.request().method() === 'POST' && route.request().url().includes('composer_preview')) await route.fulfill({ status: 503, contentType: 'application/json', body: '{"code":"operation_failed","message":"Unavailable"}' });
    else await route.continue();
  });
  await panel.locator('[data-fi-preview]').click(); await expect(panel.getByRole('status')).toContainText('unavailable');
  await expect(body).toHaveValue('New text typed while preview was preparing.'); await expect(page.locator('#bbp_reply_submit')).toBeEnabled();
  await page.unroute('**/*');
  await page.locator('[name="bbp_topic_id"]').evaluate((input, value) => { input.value = value; input.dispatchEvent(new Event('change', { bubbles: true })); }, String(fixture.private_topic));
  await panel.locator('[data-fi-preview]').click(); await expect(panel.getByRole('status')).toContainText('unavailable'); await expect(panel.locator('iframe')).toBeHidden();
  await expect(body).toHaveValue('New text typed while preview was preparing.');
});
test('native composer remains usable without JavaScript and guests have no extra controls', async ({ browser, page }) => {
  await page.goto(fixture.topic_url, { waitUntil: 'domcontentloaded' }); await expect(page.locator('[data-fi-composer-tools]')).toHaveCount(0);
  const context = await browser.newContext({ javaScriptEnabled: false }); const native = await context.newPage();
  try {
    await login(native); await native.goto(fixture.topic_url, { waitUntil: 'domcontentloaded' });
    await native.locator('#bbp_reply_content').fill('Native composer text without JavaScript.');
    await expect(native.locator('[data-fi-composer-tools]')).toHaveCount(0);
    await expect(native.locator('#bbp_reply_submit')).toBeEnabled();
    await expect(native.locator('#bbp_reply_content')).toHaveValue('Native composer text without JavaScript.');
  } finally { await context.close(); }
});
test('formatting and preview respect native rich selection and text-mode switching', async ({ page }) => {
  test.skip(process.env.FI_RICH_COMPOSER_E2E !== '1', 'Requires the owned native TinyMCE fixture.');
  test.setTimeout(90000); await login(page); const panel = await open(page);
  await expect.poll(() => page.evaluate(() => !!window.tinymce?.get('bbp_reply_content')?.initialized)).toBe(true);
  await page.evaluate(() => {
    const editor = window.tinymce.get('bbp_reply_content'); editor.setContent('<p>prefix richselected suffix</p>');
    const node = editor.getBody().querySelector('p').firstChild; const range = editor.getDoc().createRange();
    range.setStart(node, 7); range.setEnd(node, 19); editor.focus(); editor.selection.setRng(range);
  });
  await panel.locator('[data-fi-format="bold"]').click();
  expect(await page.evaluate(() => window.tinymce.get('bbp_reply_content').getContent())).toMatch(/prefix <strong>richselected<\/strong> suffix/);
  await panel.locator('[data-fi-preview]').click(); await expect(panel.getByRole('status')).toContainText('Preview ready');
  await expect(previewFrame(page).locator('strong')).toHaveText('richselected');
  await expect(panel.locator('[data-fi-format="bold"]')).toHaveAttribute('aria-pressed', 'true');
  await page.evaluate(() => window.tinymce.get('bbp_reply_content').setContent('<p>Replacement from a native editor integration.</p>'));
  await expect(panel.locator('iframe')).toBeHidden();
  await page.locator('#bbp_reply_content-html').click();
  // WordPress restores its HTML-mode selection with a delayed native focus.
  await expect(page.locator('#bbp_reply_content')).toBeFocused();
  expect(await panel.locator('[data-fi-format="bold"]').getAttribute('aria-pressed')).toBeNull();
  await page.locator('#bbp_reply_content').fill('prefix textselected suffix'); await selectText(page.locator('#bbp_reply_content'), 'textselected');
  await panel.locator('[data-fi-format="italic"]').click();
  await expect(page.locator('#bbp_reply_content')).toHaveValue('prefix <em>textselected</em> suffix');
  await panel.locator('[data-fi-preview]').click(); await expect(panel.getByRole('status')).toContainText('Preview ready');
  await expect(previewFrame(page).locator('em')).toHaveText('textselected');
  await page.locator('#bbp_reply_content-tmce').click(); await expect.poll(() => page.evaluate(() => window.tinymce.get('bbp_reply_content').isHidden())).toBe(false);
  expect(await page.evaluate(() => window.tinymce.get('bbp_reply_content').getContent())).toContain('<em>textselected</em>');
});
