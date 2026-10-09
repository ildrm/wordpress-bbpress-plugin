import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { URL } from 'node:url';

async function login(page) {
  await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
  // WordPress's delayed autofocus can move focus while Playwright fills the password.
  // Set the native inputs together, then exercise the real native form submission.
  await page.locator('#loginform').evaluate(form => {
    form.querySelector('#user_login').value = 'release_admin';
    form.querySelector('#user_pass').value = 'isolated-release-only';
  });
  const [response] = await Promise.all([
    page.waitForResponse(result => result.request().method() === 'POST' && result.url().includes('/wp-login.php')),
    page.locator('#wp-submit').click(),
  ]);
  expect([302, 303]).toContain(response.status());
  expect(new URL(response.headers().location).origin).toBe('http://127.0.0.1:8899');
  await expect.poll(async () => (await page.context().cookies('http://127.0.0.1:8899')).some(cookie => cookie.name.startsWith('wordpress_logged_in_'))).toBe(true);
  await page.goto('/wp-admin/admin.php?page=forum-intelligence&tab=tools', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'Getting started', exact: true })).toBeVisible();
}

test.describe('Packaged administrative screens', () => {
  test.skip(process.env.FI_RELEASE_E2E !== '1', 'Requires the isolated packaged release site on port 8899.');
  test.use({ baseURL: 'http://127.0.0.1:8899' });
  test('all administration sections have labelled controls and keyboard access', async ({ page }) => {
    test.setTimeout(180000);
    await login(page);
    for (const tab of ['dashboard', 'moderation', 'knowledge', 'jobs', 'settings', 'audit', 'tools', 'analytics', 'reputation', 'messages', 'feedback']) {
      const response = await page.goto('/wp-admin/admin.php?page=forum-intelligence&tab=' + tab, { waitUntil: 'domcontentloaded' });
      expect(response.status(), tab).toBe(200);
      const region = page.locator('#wpbody-content > .wrap');
      await expect(region.getByRole('heading', { name: 'Forum Intelligence', exact: true })).toBeVisible();
      const sections = region.getByRole('navigation', { name: 'Administration sections' });
      await expect(sections.getByRole('link')).toHaveCount(11);
      const axe = await new AxeBuilder({ page }).include('#wpbody-content > .wrap').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
      expect(axe.violations, tab).toEqual([]);
      await sections.getByRole('link').first().focus();
      await expect(sections.getByRole('link').first()).toBeFocused();
      await page.keyboard.press('Tab');
      await expect(sections.getByRole('link').nth(1)).toBeFocused();
    }
    await page.goto('/wp-admin/admin.php?page=forum-intelligence&tab=audit', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#wpbody-content > .wrap').getByText(/Configuration exported/).first()).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/wp-admin/admin.php?page=forum-intelligence&tab=settings', { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    expect(await page.locator('#wpbody-content > .wrap').evaluate((region) => [...region.querySelectorAll('input:not([type="hidden"]), select, textarea, button')].some((element) => {
      const bounds = element.getBoundingClientRect();
      return bounds.width > 0 && (bounds.right > window.innerWidth + 1 || bounds.left < -1);
    }))).toBe(false);
  });
  test('guided setup uses native verified forms and an explicit indexing review', async ({ page }) => {
    test.setTimeout(90000);
    await login(page);
    await page.goto('/wp-admin/admin.php?page=forum-intelligence&tab=tools', { waitUntil: 'domcontentloaded' });
    const setup = page.locator('section[aria-labelledby="fi-setup-title"]');
    await expect(setup.getByRole('heading', { name: 'Getting started' })).toBeVisible();
    await expect(setup.locator('.fi-setup-steps > li')).toHaveCount(6);
    await expect(setup.getByText('AI is currently disabled. You can build the local index now.')).toBeVisible();
    const missingNonce = await page.request.post('/wp-admin/admin-post.php', { form: { action: 'fi_action', operation: 'setup_defaults' } });
    expect(missingNonce.status()).toBe(403);
    const [saveResponse] = await Promise.all([page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-admin/admin-post.php')), setup.getByRole('button', { name: 'Save missing defaults' }).click()]);
    expect(saveResponse.status()).toBe(302);
    await page.waitForURL(url => url.searchParams.has('fi_result'), { waitUntil: 'domcontentloaded' });
    await expect(setup.getByText('AI is currently disabled. You can build the local index now.')).toBeVisible();
    const index = setup.locator('form').filter({ has: page.locator('input[name="operation"][value="setup_index"]') });
    await index.getByRole('button', { name: 'Queue initial index build' }).click();
    expect(await index.getByRole('checkbox').evaluate(element => element.validity.valueMissing)).toBe(true);
    await index.getByRole('checkbox').check();
    const resultUrl = page.url();
    await index.getByRole('button', { name: 'Queue initial index build' }).click();
    await page.waitForURL(url => url.href !== resultUrl && url.searchParams.has('fi_result'), { waitUntil: 'domcontentloaded' });
    await expect(setup.getByRole('link', { name: 'Open jobs and indexing' })).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    const axe = await new AxeBuilder({ page }).include('section[aria-labelledby="fi-setup-title"]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations).toEqual([]);
    expect(await setup.evaluate(region => [...region.querySelectorAll('input:not([type="hidden"]), button, a')].some(element => {
      const bounds = element.getBoundingClientRect();
      return bounds.width > 0 && (bounds.right > window.innerWidth + 1 || bounds.left < -1);
    }))).toBe(false);
  });

  test('summary and editor-alert settings save through native forms with separate consent', async ({ page }) => {
    test.setTimeout(90000);
    await login(page);
    await page.goto('/wp-admin/admin.php?page=forum-intelligence&tab=settings', { waitUntil: 'domcontentloaded' });
    const region = page.locator('#wpbody-content > .wrap');
    const automatic = region.getByRole('checkbox', { name: 'Enable automatic long-discussion summaries', exact: true });
    await expect(automatic).not.toBeChecked();
    await expect(region.getByRole('checkbox', { name: 'Enable external AI processing of public content', exact: true })).not.toBeChecked();
    const review = region.getByRole('checkbox', { name: 'Notify responsible article editors when sources change', exact: true });
    const reviewBefore = await review.isChecked();
    const fields = ['summary_min_replies', 'summary_debounce_seconds', 'summary_min_interval'];
    const before = Object.fromEntries(await Promise.all(fields.map(async name => [name, await region.locator('input[name="fi_settings[' + name + ']"]').inputValue()])));
    await region.getByRole('spinbutton', { name: 'Minimum published replies for automatic summaries', exact: true }).fill('30');
    await region.getByRole('spinbutton', { name: 'Wait after discussion changes, in seconds', exact: true }).fill('900');
    await region.getByRole('spinbutton', { name: 'Minimum seconds between generated summaries', exact: true }).fill('7200');
    await automatic.focus(); await page.keyboard.press('Space'); await expect(automatic).toBeChecked();
    await review.check();
    const save = async () => {
      const [response] = await Promise.all([
        page.waitForResponse(result => result.request().method() === 'POST' && result.url().includes('/wp-admin/options.php')),
        region.getByRole('button', { name: 'Save Changes', exact: true }).click(),
      ]);
      expect(response.status()).toBe(302);
      await page.waitForURL(url => url.searchParams.has('settings-updated'), { waitUntil: 'domcontentloaded' });
    };
    try {
      await save();
      await expect(automatic).not.toBeChecked();
      await expect(review).toBeChecked();
      await expect(region.getByRole('spinbutton', { name: 'Minimum published replies for automatic summaries', exact: true })).toHaveValue('30');
      await expect(region.getByRole('spinbutton', { name: 'Wait after discussion changes, in seconds', exact: true })).toHaveValue('900');
      await expect(region.getByRole('spinbutton', { name: 'Minimum seconds between generated summaries', exact: true })).toHaveValue('7200');
    } finally {
      for (const name of fields) await region.locator('input[name="fi_settings[' + name + ']"]').fill(before[name]);
      await automatic.uncheck(); await review.setChecked(reviewBefore); await save();
    }
  });
  test('automatic knowledge draft criteria require a native moderator and save without external AI', async ({ page }) => {
    test.setTimeout(90000);
    await login(page);
    await page.goto('/wp-admin/profile.php', { waitUntil: 'domcontentloaded' });
    const actor = await page.locator('input[name="user_id"]').inputValue();
    expect(Number(actor)).toBeGreaterThan(0);
    await page.goto('/wp-admin/admin.php?page=forum-intelligence&tab=settings', { waitUntil: 'domcontentloaded' });
    const region = page.locator('#wpbody-content > .wrap');
    const enabled = region.getByRole('checkbox', { name: 'Enable automatic knowledge article drafts', exact: true });
    const fields = ['knowledge_auto_draft_actor', 'knowledge_auto_draft_min_replies', 'knowledge_auto_draft_min_confirmations', 'knowledge_auto_draft_debounce'];
    const before = Object.fromEntries(await Promise.all(fields.map(async name => [name, await region.locator('input[name="fi_settings[' + name + ']"]').inputValue()])));
    const checked = await enabled.isChecked();
    const save = async () => {
      const [response] = await Promise.all([page.waitForResponse(result => result.request().method() === 'POST' && result.url().includes('/wp-admin/options.php')), region.getByRole('button', { name: 'Save Changes', exact: true }).click()]);
      expect(response.status()).toBe(302);
      await page.waitForURL(url => url.searchParams.has('settings-updated'), { waitUntil: 'domcontentloaded' });
    };
    try {
      await region.getByRole('spinbutton', { name: 'Responsible article moderator user ID', exact: true }).fill(actor);
      await region.getByRole('spinbutton', { name: 'Minimum published replies for article drafts', exact: true }).fill('4');
      await region.getByRole('spinbutton', { name: 'Minimum accepted-answer confirmations for article drafts', exact: true }).fill('1');
      await region.getByRole('spinbutton', { name: 'Wait before article conversion, in seconds', exact: true }).fill('180');
      await enabled.focus(); await page.keyboard.press('Space'); await expect(enabled).toBeChecked();
      await save();
      await expect(enabled).toBeChecked();
      await expect(region.getByRole('checkbox', { name: 'Enable external AI processing of public content', exact: true })).not.toBeChecked();
      await expect(region.getByRole('spinbutton', { name: 'Minimum published replies for article drafts', exact: true })).toHaveValue('4');
      await expect(region.getByRole('spinbutton', { name: 'Minimum accepted-answer confirmations for article drafts', exact: true })).toHaveValue('1');
      await expect(region.getByRole('spinbutton', { name: 'Wait before article conversion, in seconds', exact: true })).toHaveValue('180');
      await region.getByRole('spinbutton', { name: 'Responsible article moderator user ID', exact: true }).fill('0');
      await save(); await expect(enabled).not.toBeChecked();
    } finally {
      for (const name of fields) await region.locator('input[name="fi_settings[' + name + ']"]').fill(before[name]);
      await enabled.setChecked(checked); await save();
    }
  });
  test('anonymous clients cannot load administrative sections', async ({ request }) => {
    for (const tab of ['audit', 'settings', 'messages', 'jobs', 'tools', 'feedback']) {
      const response = await request.get('/wp-admin/admin.php?page=forum-intelligence&tab=' + tab, { maxRedirects: 0 });
      expect([302, 303]).toContain(response.status());
      expect(response.headers().location).toContain('wp-login.php');
    }
  });
});
