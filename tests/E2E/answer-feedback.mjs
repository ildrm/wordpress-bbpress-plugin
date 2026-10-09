import { nativeLogin } from '../Support/native-login.mjs';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { URL } from 'node:url';
const fixture = command => {
  const output = execFileSync(process.env.FI_PHP || 'C:/xampp/php/php.exe', ['tools/answer-feedback-fixture.php', command], { encoding: 'utf8' });
  const line = output.split(/\r?\n/).find(value => value.startsWith('{"fixture":'));
  if (!line) throw new Error('No owned answer feedback fixture result');
  return JSON.parse(line).fixture;
};
test.use({ baseURL: process.env.FI_RELEASE_E2E === '1' ? 'http://127.0.0.1:8899' : 'http://127.0.0.1:8898' });
let state;
const visit = async (page, url) => { const target = new URL(url); await page.goto(target.pathname + target.search, { waitUntil: 'domcontentloaded' }); };
test.describe.serial('Native generated-answer feedback', () => {
  test.beforeAll(() => { state = fixture('seed'); });
  test.afterAll(() => { fixture('cleanup'); });
  test('member feedback uses a generated receipt and a native keyboard form', async ({ page }, info) => {
    state = fixture('result'); await nativeLogin(page, state.member_login, 'isolated-answer-only'); await visit(page, state.result_url);
    await expect(page.getByText('OWNED_BROWSER_GENERATED_ANSWER:', { exact: false }).first()).toBeVisible();
    const panel = page.locator('[data-fi-answer-feedback]').first(); await expect(panel).toBeVisible();
    const choice = panel.getByLabel('How useful was this generated answer?'); await expect(choice.locator('option')).toHaveCount(6);
    const axe = await new AxeBuilder({ page }).include('[data-fi-answer-feedback]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze(); expect(axe.violations).toEqual([]);
    await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    expect(await panel.evaluate(region => [...region.querySelectorAll('button,select')].some(element => { const b = element.getBoundingClientRect(); return b.width > 0 && (b.left < -1 || b.right > window.innerWidth + 1); }))).toBe(false);
    await panel.screenshot({ path: info.outputPath('native-answer-feedback-rtl.png') });
    await choice.selectOption('missing_context'); await panel.getByRole('button', { name: 'Send answer feedback' }).focus();
    const beforeSubmit = page.url(); await Promise.all([page.waitForURL(url => url.href !== beforeSubmit && url.searchParams.has('fi_result'), { waitUntil: 'domcontentloaded' }), page.keyboard.press('Enter')]); await expect(page.getByText('Your change was saved.', { exact: true }).first()).toBeVisible();
    const stored = fixture('state').records; expect(stored).toHaveLength(1); expect(JSON.parse(stored[0].payload).value).toBe('missing_context'); expect(stored[0].payload).not.toContain('OWNED_BROWSER_GENERATED_ANSWER');
  });
  test('moderator reviews the report through the current native source and conflict version', async ({ page }, info) => {
    await nativeLogin(page, state.admin_login, 'isolated-answer-only'); await visit(page, state.admin_url);
    const panel = page.locator('[data-fi-feedback-review]'); await expect(panel.getByRole('link', { name: 'Owned answer cache invalidation' })).toBeVisible(); await expect(panel.getByText('Missing context · Needs review', { exact: true })).toBeVisible();
    const axe = await new AxeBuilder({ page }).include('[data-fi-feedback-review]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze(); expect(axe.violations).toEqual([]);
    await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(() => { document.documentElement.dir = 'rtl'; }); await panel.screenshot({ path: info.outputPath('native-answer-review-rtl.png') });
    await panel.getByLabel('Review status').selectOption('resolved'); await panel.getByRole('button', { name: 'Save feedback review' }).focus(); await Promise.all([page.waitForURL(/fi_result=/), page.keyboard.press('Enter')]);
    await expect(panel.getByText('Missing context · Reviewed and resolved', { exact: true })).toBeVisible();
    expect(JSON.parse(fixture('state').records[0].payload).state).toBe('resolved');
  });
  test('restricted evidence hides both the saved answer and moderator report', async ({ page, browser }) => {
    test.setTimeout(90000);
    state = fixture('result'); await nativeLogin(page, state.member_login, 'isolated-answer-only'); await visit(page, state.result_url);
    const panel = page.locator('[data-fi-answer-feedback]').first(); const token = await panel.locator('input[name="data[token]"]').inputValue(); const nonce = await page.evaluate(() => window.fiConfig.nonce);
    const missing = await page.request.post('/?rest_route=/forum-intelligence/v1/actions/ai_feedback', { data: { data: { object: state.topic, value: 'outdated', token } } }); expect([401,403]).toContain(missing.status());
    fixture('protect'); const denied = await page.request.post('/?rest_route=/forum-intelligence/v1/actions/ai_feedback', { headers: { 'X-WP-Nonce': nonce }, data: { data: { object: state.topic, value: 'outdated', token } } }); expect(denied.status()).toBe(404);
    await page.reload(); await expect(page.locator('[data-fi-answer-feedback]')).toHaveCount(0); await expect(page.getByText('OWNED_BROWSER_GENERATED_ANSWER:', { exact: false })).toHaveCount(0);
    const moderator = await browser.newPage({ baseURL: process.env.FI_RELEASE_E2E === '1' ? 'http://127.0.0.1:8899' : 'http://127.0.0.1:8898' });
    try { await nativeLogin(moderator, state.admin_login, 'isolated-answer-only'); await visit(moderator, state.admin_url); await expect(moderator.locator('[data-fi-feedback-id]')).toHaveCount(0); fixture('restore'); await moderator.reload(); await expect(moderator.locator('[data-fi-feedback-id]')).toHaveCount(1); } finally { await moderator.close(); }
  });
  test('local spelling suggestion retains the typed query until the member chooses it', async ({ page }) => {
    await nativeLogin(page, state.member_login, 'isolated-answer-only');
    const target = new URL(state.page_url); target.searchParams.set('fi_q', 'invalidaton'); target.searchParams.set('fi_forum', String(state.forum)); await visit(page, target.href);
    const search = page.locator('section.fi-panel').filter({ has: page.getByRole('heading', { name: 'Community search', exact: true }) }).first();
    await expect(page.locator('input[name="fi_q"]')).toHaveValue('invalidaton');
    const suggestion = search.getByRole('link', { name: 'invalidation', exact: true }); await expect(suggestion).toBeVisible();
    await suggestion.focus(); await page.keyboard.press('Enter'); await expect(page.locator('input[name="fi_q"]')).toHaveValue('invalidation');
    await expect(search.getByRole('link', { name: 'Owned answer cache invalidation', exact: true }).first()).toBeVisible();
  });
});
