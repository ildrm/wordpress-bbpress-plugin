import { URL } from 'node:url';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFile } from 'node:fs/promises';
const fixture = JSON.parse(await readFile('.runtime/e2e.json', 'utf8'));
test.skip(process.env.FI_TEMPLATE_E2E !== '1', 'Requires owned contextual template configuration.');
const login = async (page, context) => {
  await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#loginform').evaluate(form => { form.querySelector('#user_login').value = 'e2e_author'; form.querySelector('#user_pass').value = 'test-browser-only'; });
  const [response] = await Promise.all([page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/wp-login.php')), page.locator('#wp-submit').click()]);
  expect([302, 303]).toContain(response.status());
  await expect.poll(async () => (await context.cookies()).some(cookie => cookie.name.startsWith('wordpress_logged_in_'))).toBe(true);
};
const choices = async (page) => {
  await expect(page.locator('[data-fi-template-axis="topic_type"]')).toBeVisible();
  await page.locator('[data-fi-template-axis="topic_type"]').selectOption('Support');
  await expect(page.locator('[data-fi-template-controls] [role="status"]')).toHaveText('Support guidance');
  await page.locator('[data-fi-template-axis="product"]').selectOption('Widget');
  await expect(page.locator('[data-fi-template-controls] [role="status"]')).toHaveText('Widget support');
  await page.locator('[data-fi-template-axis="category"]').selectOption('Troubleshooting');
  await expect(page.locator('[data-fi-template-controls] [role="status"]')).toHaveText('Widget troubleshooting');
};
test('context selectors preview explicit guidance, preserve text and remain private, accessible and responsive', async ({ page, context }) => {
  await login(page, context); await page.goto(fixture.forum_url, { waitUntil: 'domcontentloaded' });
  const body = page.locator('#bbp_topic_content'); const panel = page.locator('[data-fi-template-controls]');
  await choices(page);
  expect(await body.inputValue()).toBe('');
  expect(await panel.locator('pre').textContent()).toContain('CONTEXT_TEMPLATE_CANARY');
  expect(await page.locator('[data-fi-template-guidance]').textContent()).not.toContain('PRIVATE_TEMPLATE_');
  await expect(panel.locator('select[name]')).toHaveCount(0);
  await expect(panel.locator('select[required]')).toHaveCount(0);
  await body.fill('CURRENT_OCCUPIED_TEMPLATE_CANARY');
  await panel.getByRole('button', { name: 'Use this question template' }).click();
  await expect(body).toHaveValue('CURRENT_OCCUPIED_TEMPLATE_CANARY');
  await body.fill('');
  await panel.getByRole('button', { name: 'Use this question template' }).click();
  await expect(body).toHaveValue(/CONTEXT_TEMPLATE_CANARY متن 😀/);
  await page.locator('#bbp_topic_title').fill('Current contextual guidance subject');
  expect(await page.evaluate(() => document.querySelector('#bbp_topic_content').form.checkValidity())).toBe(true);
  await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
  const box = await panel.boundingBox(); expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(392);
  const accessibility = await new AxeBuilder({ page }).include('[data-fi-template-controls]').analyze(); expect(accessibility.violations).toEqual([]);
});
test('late template responses and source changes never replace current text or retain a private catalog', async ({ page, context }) => {
  await login(page, context); await page.goto(fixture.forum_url, { waitUntil: 'domcontentloaded' });
  const body = page.locator('#bbp_topic_content'); const panel = page.locator('[data-fi-template-controls]');
  await expect(panel.getByRole('button', { name: 'Use this question template' })).toBeVisible();
  let held; let release;
  await page.route('**/*', async route => {
    if (new URL(route.request().url()).searchParams.get('topic_type') === 'Support') {
      const response = await route.fetch(); held = true;
      await new Promise(resolve => { release = resolve; }); await route.fulfill({ response });
    } else await route.continue();
  });
  await page.locator('[data-fi-template-axis="topic_type"]').selectOption('Support');
  await expect.poll(() => Boolean(held)).toBe(true);
  await body.fill('CURRENT_DURING_TEMPLATE_REQUEST');
  await page.locator('#bbp_forum_id').evaluate((select, hidden) => { const option = document.createElement('option'); option.value = String(hidden); option.textContent = 'Changed inaccessible source'; select.append(option); select.value = String(hidden); select.dispatchEvent(new Event('change', { bubbles: true })); }, fixture.hidden);
  release();
  await expect(panel.getByRole('status')).toContainText('unavailable');
  await expect(panel.getByRole('button', { name: 'Use this question template' })).toBeHidden();
  await expect(body).toHaveValue('CURRENT_DURING_TEMPLATE_REQUEST');
  expect(await page.locator('[data-fi-template-guidance]').textContent()).not.toContain('CONTEXT_TEMPLATE_CANARY');
  expect(await panel.textContent()).not.toContain('PRIVATE_TEMPLATE_');
});
test('native template catalogs stay useful without JavaScript and never add a required posting step', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false }); const page = await context.newPage();
  try {
    await login(page, context); await page.goto(fixture.forum_url, { waitUntil: 'domcontentloaded' });
    const catalog = page.locator('[data-fi-template-guidance]');
    await expect(catalog).toBeAttached(); await catalog.locator(':scope > summary').click();
    await expect(catalog).toContainText('Expected behavior:');
    expect(await catalog.textContent()).toContain('CONTEXT_TEMPLATE_CANARY');
    expect(await catalog.textContent()).not.toContain('PRIVATE_TEMPLATE_');
    await expect(catalog.locator('form, input, select, textarea, button')).toHaveCount(0);
    await expect(page.locator('#bbp_topic_submit')).toBeEnabled();
    await page.locator('#bbp_topic_content').fill('NATIVE_TEMPLATE_TEXT_KEPT'); await expect(page.locator('#bbp_topic_content')).toHaveValue('NATIVE_TEMPLATE_TEXT_KEPT');
  } finally { await context.close(); }
});

test('contextual guidance respects native visual and text editor modes', async ({ page, context }) => {
  test.skip(process.env.FI_RICH_TEMPLATE_E2E !== '1', 'Requires the owned native TinyMCE fixture.');
  await login(page, context); await page.goto(fixture.forum_url, { waitUntil: 'domcontentloaded' });
  const body = page.locator('#bbp_topic_content'); const button = page.locator('[data-fi-template-controls]').getByRole('button', { name: 'Use this question template' });
  await expect.poll(() => page.evaluate(() => Boolean(window.tinymce?.get('bbp_topic_content')?.initialized && !window.tinymce.get('bbp_topic_content').isHidden()))).toBe(true);
  await page.evaluate(() => window.tinymce.get('bbp_topic_content').setContent('<p>VISUAL_TEMPLATE_OCCUPIED</p>'));
  await choices(page);
  expect(await page.evaluate(() => window.tinymce.get('bbp_topic_content').getContent())).toContain('VISUAL_TEMPLATE_OCCUPIED');
  await button.click();
  expect(await page.evaluate(() => window.tinymce.get('bbp_topic_content').getContent())).toContain('VISUAL_TEMPLATE_OCCUPIED');
  await page.evaluate(() => { const editor = window.tinymce.get('bbp_topic_content'); editor.focus(); editor.selection.select(editor.getBody().querySelector('p'), true); editor.selection.collapse(false); });
  await page.locator('#bbp_topic_content-html').click(); await expect(body).toBeFocused();
  await body.fill('CURRENT_TEXT_MODE_TEMPLATE_CANARY'); await button.click();
  await expect(body).toHaveValue('CURRENT_TEXT_MODE_TEMPLATE_CANARY');
  await body.fill(''); await button.click();
  await expect(body).toHaveValue(/CONTEXT_TEMPLATE_CANARY متن 😀/);
  await page.locator('#bbp_topic_content-tmce').click();
  await expect.poll(() => page.evaluate(() => window.tinymce.get('bbp_topic_content').getContent())).toContain('CONTEXT_TEMPLATE_CANARY');
  expect(await page.evaluate(() => window.tinymce.get('bbp_topic_content').getContent())).not.toContain('CURRENT_TEXT_MODE_TEMPLATE_CANARY');
});
