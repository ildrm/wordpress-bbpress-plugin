import { nativeLogin } from '../Support/native-login.mjs';
import { URL } from 'node:url';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFile } from 'node:fs/promises';
const fixture = JSON.parse(await readFile('.runtime/e2e.json', 'utf8'));
const login = nativeLogin;test('public search filters hidden content and works with JavaScript disabled', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.goto(fixture.search_url, { waitUntil: 'domcontentloaded' });
  await page.getByRole('searchbox', { name: 'Search the community' }).fill('browser cache invalidation');
  await page.locator('.fi-panel').getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.locator('.fi-panel a').filter({ hasText: 'Browser cache invalidation question' }).filter({ visible: true }).first()).toBeVisible();
  await expect(page.getByText('CANARY_HIDDEN', { exact: false })).toHaveCount(0);
  await context.close();
});
test('accepted solution, confirmations and keyboard-accessible controls', async ({ page }) => {
  await login(page, 'e2e_author');
  await page.goto(fixture.topic_url, { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: 'Accept solution', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Go to accepted solution' })).toBeVisible();
  await expect(page.getByText('Accepted solution', { exact: true }).first()).toBeVisible();
  const accessibility = await new AxeBuilder({ page }).include('.fi-panel').include('.fi-controls').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(accessibility.violations).toEqual([]);
  await page.keyboard.press('Tab');
  expect(await page.evaluate(() => document.activeElement.tagName)).not.toBe('BODY');
});
test('moderation reports and notification preferences work end-to-end', async ({ page }) => {
  await login(page, 'e2e_member');
  await page.goto(fixture.topic_url, { waitUntil: 'domcontentloaded' });
  await page.getByRole('combobox', { name: 'Report reason' }).selectOption('other');
  await page.getByRole('textbox', { name: 'Notes for moderators' }).fill('Browser moderation regression');
  await page.getByRole('button', { name: 'Send report' }).click();
  await page.goto(fixture.hub_url, { waitUntil: 'domcontentloaded' });
  await page.getByRole('combobox', { name: 'Email delivery' }).selectOption('daily');
  await page.getByRole('button', { name: 'Save preferences' }).click();
  await expect(page.getByRole('combobox', { name: 'Email delivery' })).toHaveValue('daily');
});
test('mobile and RTL controls stay inside the viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(fixture.search_url, { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
  const overflow = await page.locator('.fi-panel').evaluateAll((elements) => elements.some((element) => element.getBoundingClientRect().right > window.innerWidth + 1 || element.getBoundingClientRect().left < -1));
  expect(overflow).toBe(false);
  await expect(page.getByRole('searchbox', { name: 'Search the community' })).toBeVisible();
});

test('HTTP mutations require nonces and hidden objects stay unavailable', async ({ page }) => {
  await login(page, 'e2e_author');
  const native = await page.request.post('/wp-admin/admin-post.php', { form: { action: 'fi_action', operation: 'solution', 'data[topic]': String(fixture.topic), 'data[command]': 'reopen' } });
  expect(native.status()).toBe(403);
  const rest = await page.request.post('/?rest_route=/forum-intelligence/v1/actions/solution', { data: { data: { topic: fixture.topic, command: 'reopen' } } });
  expect([401, 403]).toContain(rest.status());
  const denied = await page.request.get('/?rest_route=/forum-intelligence/v1/solutions/' + fixture.private_topic);
  expect(denied.status()).toBe(404);
});
test('reflected search text is escaped in the browser', async ({ page }) => {
  const url = new URL(fixture.search_url); url.searchParams.set('fi_q', '<img src=x onerror="window.fiXss=1">');
  await page.goto(url.href, { waitUntil: 'domcontentloaded' });
  expect(await page.evaluate(() => window.fiXss)).toBeUndefined();
  await expect(page.locator('.fi-panel img')).toHaveCount(0);
  expect(await page.getByRole('searchbox', { name: 'Search the community' }).inputValue()).not.toContain('<img');
});

test('question templates prefill explicitly and preserve existing composer text', async ({ page }) => {
  await login(page, 'e2e_author');
  await page.goto(fixture.forum_url, { waitUntil: 'domcontentloaded' });
  const body = page.locator('#bbp_topic_content');
  await expect(body).toBeAttached();
  const button = page.getByRole('button', { name: 'Use this question template' });
  await expect(button).toBeVisible();
  await button.click();
  expect(await body.inputValue()).toContain('Expected behavior:');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await body.fill('Existing question details must survive template use.');
  await expect(button).toBeVisible(); await button.click();
  await expect(body).toHaveValue('Existing question details must survive template use.');
  await expect(page.getByText('Your existing text was kept.', { exact: false })).toBeVisible();
});
test('private conversations, read state, reports and blocking work without JavaScript', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false }); const page = await context.newPage();
  await login(page, 'e2e_author'); await page.goto(fixture.messages_url, { waitUntil: 'domcontentloaded' });
  await page.getByRole('spinbutton', { name: 'Recipient member ID' }).fill(String(fixture.member));
  await page.getByRole('textbox', { name: 'Message', exact: true }).fill('E2E_PRIVATE_MESSAGE_CANARY');
  await page.getByRole('button', { name: 'Start conversation' }).click();
  await page.locator('[data-fi-conversation]').first().locator('summary').click();
  await page.locator('[data-fi-conversation]').first().getByRole('button', { name: 'Mark read' }).click();
  await page.locator('[data-fi-conversation]').first().locator('summary').click();
  await expect(page.locator('[data-fi-conversation]').first().getByText('E2E_PRIVATE_MESSAGE_CANARY', { exact: true })).toBeVisible();
  await page.locator('[data-fi-conversation]').first().getByRole('button', { name: 'Report this message' }).click();
  await page.getByRole('spinbutton', { name: 'Member ID', exact: true }).fill(String(fixture.member));
  await page.getByRole('button', { name: 'Block', exact: true }).click();
  const conversation = page.locator('[data-fi-conversation]').first(); await conversation.locator('summary').click();
  await conversation.getByRole('textbox', { name: 'Reply', exact: true }).fill('This blocked message must not be sent.');
  await conversation.getByRole('button', { name: 'Send reply' }).click();
  await expect(page.getByText('Messaging is blocked.', { exact: true })).toBeVisible();
  await context.close();
});

test('members control achievements and moderators review badges without JavaScript', async ({ browser }) => {
  test.setTimeout(90000);
  const memberContext = await browser.newContext({ javaScriptEnabled: false });
  const modContext = await browser.newContext({ javaScriptEnabled: false });
  try {
    const member = await memberContext.newPage(); await login(member, 'e2e_member');
    await member.goto(fixture.hub_url, { waitUntil: 'domcontentloaded' });
    const erase = member.getByRole('button', { name: 'Disable and erase achievements', exact: true });
    if (await erase.count()) await erase.click();
    await member.getByRole('button', { name: 'Enable achievements', exact: true }).click();
    await expect(member.getByRole('button', { name: 'Disable and erase achievements' })).toBeVisible();
    const mod = await modContext.newPage(); await login(mod, 'e2e_mod');
    await mod.goto('/wp-admin/admin.php?page=forum-intelligence&tab=reputation', { waitUntil: 'domcontentloaded' });
    const review = mod.locator('.fi-panel').filter({ has: mod.getByRole('heading', { name: 'Reviewed achievements', exact: true }) });
    await review.getByRole('spinbutton', { name: 'Member ID', exact: true }).fill(String(fixture.member));
    await review.getByRole('combobox', { name: 'Reviewed badge' }).selectOption('community_mentor');
    await review.getByRole('textbox', { name: 'Review explanation' }).fill('Reviewed sustained mentoring contributions.');
    await review.getByRole('button', { name: 'Save reviewed badge' }).click();
    await member.goto(fixture.hub_url, { waitUntil: 'domcontentloaded' });
    const achievements = member.locator('.fi-panel').filter({ has: member.getByRole('heading', { name: 'Your achievements', exact: true }) });
    await expect(achievements.getByText('Community mentor', { exact: true })).toBeVisible();
    const auditContext = await browser.newContext({ storageState: await memberContext.storageState() });
    try {
      const auditPage = await auditContext.newPage(); await auditPage.goto(fixture.hub_url, { waitUntil: 'domcontentloaded' });
      const accessibility = await new AxeBuilder({ page: auditPage }).include('.fi-panel').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
      expect(accessibility.violations).toEqual([]);
    } finally { await auditContext.close(); }
    await member.getByRole('button', { name: 'Disable and erase achievements' }).click();
    await expect(member.getByText('Community mentor', { exact: true })).toHaveCount(0);
    await expect(member.getByRole('button', { name: 'Enable achievements', exact: true })).toBeVisible();
  } finally { await memberContext.close(); await modContext.close(); }
});
