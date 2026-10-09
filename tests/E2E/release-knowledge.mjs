import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { URL } from 'node:url';
import { nativeLogin } from '../Support/native-login.mjs';

test.describe('Installed native knowledge editorial permissions', () => {
  test.skip(process.env.FI_RELEASE_E2E !== '1', 'Requires the owned installed release site.');
  test.use({ baseURL: 'http://127.0.0.1:8899' });
  let state;
  const fixture = command => {
    const output = execFileSync('rtk', ['proxy', process.env.FI_PHP || 'C:/xampp/php/php.exe', 'tools/release-knowledge-fixture.php', command], { encoding: 'utf8', windowsHide: true });
    return JSON.parse(output.trim().split(/\r?\n/).findLast(line => line.startsWith('{"fixture"')));
  };
  test.beforeAll(() => { state = fixture('seed').fixture; });
  test.afterAll(() => { fixture('cleanup'); });
  test('native editor/revisions and public shortcode renew contributing-source access and article passwords', async ({ page, request }) => {
    test.setTimeout(90000);
    await nativeLogin(page, 'release_admin', 'isolated-release-only');
    const edit = '/wp-admin/post.php?post=' + state.article + '&action=edit';
    let response = await page.goto(edit, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(200);
    await expect(page.locator('#title')).toHaveValue('INSTALLED_KNOWLEDGE_TITLE_CANARY');
    expect(await page.locator('#content').inputValue()).toContain('INSTALLED_KNOWLEDGE_REVIEWED_BODY_CANARY');
    const view = '/?page_id=' + state.page;
    expect(await (await request.get(view)).text()).toContain('INSTALLED_KNOWLEDGE_REVIEWED_BODY_CANARY');
    fixture('protect');
    response = await page.goto(edit, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(403);
    expect(await page.content()).not.toContain('INSTALLED_KNOWLEDGE_');
    const revisionUrl = '/wp-admin/revision.php?revision=' + state.revision;
    const deniedRevision = await page.request.get(revisionUrl, { maxRedirects: 0 });
    expect(deniedRevision.status()).toBe(302);
    expect(deniedRevision.headers().location).toBe('edit.php');
    response = await page.goto(revisionUrl, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(200);
    await expect(page).toHaveURL(/\/wp-admin\/edit\.php$/);
    expect(await page.content()).not.toContain('INSTALLED_KNOWLEDGE_');
    expect(await (await request.get(view)).text()).not.toContain('INSTALLED_KNOWLEDGE_');
    fixture('restore');
    response = await page.goto(edit, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(200);
    expect(await page.locator('#content').inputValue()).toContain('INSTALLED_KNOWLEDGE_REVIEWED_BODY_CANARY');
    expect(await (await request.get(view)).text()).toContain('INSTALLED_KNOWLEDGE_REVIEWED_BODY_CANARY');
    fixture('article-password');
    response = await page.goto(edit, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(200);
    expect(await page.locator('#content').inputValue()).toContain('INSTALLED_KNOWLEDGE_REVIEWED_BODY_CANARY');
    expect(await (await request.get(view)).text()).not.toContain('INSTALLED_KNOWLEDGE_');
  });
  test('native source review shows stale status, requires a valid nonce and preserves human content', async ({ page, request }, testInfo) => {
    test.setTimeout(120000);
    await nativeLogin(page, 'release_admin', 'isolated-release-only');
    const create = await page.request.get('/wp-admin/post-new.php?post_type=fi_knowledge', { maxRedirects: 0 });
    expect(create.status()).toBe(403);
    fixture('clear-article-password'); fixture('source-change');
    const edit = '/wp-admin/post.php?post=' + state.article + '&action=edit';
    const view = '/?page_id=' + state.page;
    await page.goto(edit, { waitUntil: 'domcontentloaded' });
    const review = page.locator('#fi-knowledge-review');
    await expect(review).toBeVisible();
    await expect(review.locator('[data-fi-review-status]')).toContainText('source discussion has changed');
    await expect(review.getByRole('link', { name: 'Review source discussion', exact: true })).toHaveAttribute('href', state.topic_url);
    expect(await (await request.get(view)).text()).toContain('This article may need review because its source discussion has changed.');
    const comparison = review.locator('[data-fi-source-comparison]');
    const disclosure = comparison.locator('summary'); await disclosure.focus(); await page.keyboard.press('Space');
    await expect(comparison).toHaveAttribute('open', '');
    await review.screenshot({ path: testInfo.outputPath('native-source-comparison.png') });
    const compare = comparison.getByRole('link', { name: 'Compare recorded and current text', exact: true });
    await expect(compare).toHaveCount(1);
    const compareUrl = new URL(await compare.getAttribute('href'));
    expect(compareUrl.searchParams.get('from')).toBe(String(state.source_baseline));
    expect(await review.textContent()).not.toContain('INSTALLED_SOURCE_COMPARE_');
    await compare.focus(); await page.keyboard.press('Enter'); await page.waitForURL(/\/wp-admin\/revision\.php/);
    await expect(page.locator('.revisions-diff')).toContainText('INSTALLED_SOURCE_COMPARE_OLD_CANARY');
    await expect(page.locator('.revisions-diff')).toContainText('INSTALLED_SOURCE_COMPARE_NEW_CANARY');
    await page.goto(edit, { waitUntil: 'domcontentloaded' });
    const form = await page.locator('#post').evaluate(element => {
      window.tinyMCE?.triggerSave();
      const values = Object.fromEntries(new window.FormData(element));
      values.fi_knowledge_reviewed = '1'; values.fi_knowledge_review_nonce = 'invalid';
      return values;
    });
    const invalid = await page.request.post('/wp-admin/post.php', { form, maxRedirects: 0 });
    expect(invalid.status()).toBe(302);
    expect(invalid.headers().location).toContain('fi_knowledge_review=unavailable');
    await page.goto(edit, { waitUntil: 'domcontentloaded' });
    await expect(review.locator('[data-fi-review-status]')).toContainText('source discussion has changed');
    const axe = await new AxeBuilder({ page }).include('#fi-knowledge-review').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations).toEqual([]);
    const confirm = review.getByRole('checkbox', { name: 'I reviewed the source discussion and verified this article.' });
    await confirm.focus(); await expect(confirm).toBeFocused(); await page.keyboard.press('Space'); await expect(confirm).toBeChecked();
    const [save] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-admin/post.php')),
      page.locator('#publish').click(),
    ]);
    expect(save.status()).toBe(302);
    await page.waitForURL(url => url.searchParams.get('post') === String(state.article) && url.searchParams.has('message'), { waitUntil: 'domcontentloaded' });
    await expect(review.locator('[data-fi-review-status]')).toContainText('matches this article');
    await expect(review).toContainText('Last verified (UTC):');
    await expect(review.locator('[data-fi-source-comparison]')).toContainText('No current source differences were found.');
    expect(await page.locator('#content').inputValue()).toContain('INSTALLED_KNOWLEDGE_REVIEWED_BODY_CANARY');
    const publicHtml = await (await request.get(view)).text();
    expect(publicHtml).toContain('INSTALLED_KNOWLEDGE_REVIEWED_BODY_CANARY');
    expect(publicHtml).not.toContain('This article may need review because its source discussion has changed.');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    expect(await review.evaluate(region => [...region.querySelectorAll('input:not([type="hidden"]), a')].some(element => {
      const bounds = element.getBoundingClientRect(); return bounds.width > 0 && (bounds.left < -1 || bounds.right > window.innerWidth + 1);
    }))).toBe(false);
  });


  test('responsible editor inbox uses a generic review link, renews privacy and clears after verification', async ({ page, request }) => {
    test.setTimeout(120000);
    await nativeLogin(page, 'release_admin', 'isolated-release-only');
    fixture('enable-review-alerts'); fixture('source-change-for-alert'); fixture('deliver-review-alerts');
    const inbox = '/?page_id=' + state.inbox;
    await page.goto(inbox, { waitUntil: 'domcontentloaded' });
    const center = page.locator('[data-fi-center]');
    const alert = center.getByRole('link', { name: 'Knowledge article needs source review', exact: true });
    await expect(alert).toHaveCount(1);
    await expect(center.getByRole('option', { name: 'Article source reviews', exact: true })).toHaveCount(1);
    await expect(alert).toHaveAttribute('href', new RegExp('post=' + state.article + '&action=edit'));
    expect(await center.textContent()).not.toContain('INSTALLED_KNOWLEDGE_');
    const anonymous = await (await request.get(inbox)).text();
    expect(anonymous).not.toContain('Knowledge article needs source review');
    expect(anonymous).not.toContain('INSTALLED_KNOWLEDGE_');
    const axe = await new AxeBuilder({ page }).include('[data-fi-center]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations).toEqual([]);
    fixture('protect'); await page.reload({ waitUntil: 'domcontentloaded' }); await expect(alert).toHaveCount(0);
    fixture('restore'); await page.reload({ waitUntil: 'domcontentloaded' }); await expect(alert).toHaveCount(1);
    await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    expect(await center.evaluate(region => [...region.querySelectorAll('input:not([type="hidden"]), select, button, a')].some(element => {
      const bounds = element.getBoundingClientRect(); return bounds.width > 0 && (bounds.left < -1 || bounds.right > window.innerWidth + 1);
    }))).toBe(false);
    await alert.focus(); await expect(alert).toBeFocused(); await page.keyboard.press('Enter');
    await page.waitForURL(url => url.searchParams.get('post') === String(state.article) && url.searchParams.get('action') === 'edit', { waitUntil: 'domcontentloaded' });
    const review = page.locator('#fi-knowledge-review');
    await expect(review.locator('[data-fi-review-status]')).toContainText('source discussion has changed');
    await review.getByRole('checkbox', { name: 'I reviewed the source discussion and verified this article.', exact: true }).check();
    await page.locator('#publish').click();
    await page.waitForURL(url => url.searchParams.get('post') === String(state.article) && url.searchParams.has('message'), { waitUntil: 'domcontentloaded' });
    await expect(review.locator('[data-fi-review-status]')).toContainText('matches this article');
    await page.goto(inbox, { waitUntil: 'domcontentloaded' }); await expect(alert).toHaveCount(0);
  });


  test('moderator recommendations renew source privacy and convert through explicit native forms', async ({ page, request }, testInfo) => {
    test.setTimeout(120000);
    await nativeLogin(page, 'release_admin', 'isolated-release-only');
    fixture('recommend');
    const admin = '/wp-admin/admin.php?page=forum-intelligence&tab=knowledge';
    await page.goto(admin, { waitUntil: 'domcontentloaded' });
    const queue = page.locator('[data-fi-knowledge-recommendations]');
    const card = queue.locator('[data-fi-recommendation="' + state.topic + '"]');
    await expect(card).toContainText('INSTALLED_PRIVATE_RECOMMENDATION_NOTE_CANARY');
    await expect(card.getByRole('button', { name: 'Create editorial draft', exact: true })).toBeVisible();
    expect(await (await request.get(admin)).text()).not.toContain('INSTALLED_PRIVATE_RECOMMENDATION_NOTE_CANARY');
    fixture('protect'); await page.reload({ waitUntil: 'domcontentloaded' }); await expect(card).toHaveCount(0);
    fixture('restore'); await page.reload({ waitUntil: 'domcontentloaded' }); await expect(card).toBeVisible();
    await expect(card).toContainText('The source changed after this recommendation.');
    await expect(card.getByRole('button', { name: 'Create editorial draft', exact: true })).toHaveCount(0);
    const controls = page.locator('.fi-panel').filter({ has: page.getByRole('heading', { name: 'Review community knowledge', exact: true }) });
    await controls.getByLabel('Topic ID', { exact: true }).fill(String(state.topic));
    await controls.getByLabel('Action', { exact: true }).selectOption('edit');
    await controls.getByLabel('Reviewed summary text or recommendation note', { exact: true }).fill('INSTALLED_RECOMMENDATION_SUMMARY_CANARY');
    const [saved] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-admin/admin-post.php')),
      controls.getByRole('button', { name: 'Apply', exact: true }).click(),
    ]);
    expect(saved.status()).toBe(302);
    await page.goto(admin, { waitUntil: 'domcontentloaded' });
    await expect(card.getByRole('button', { name: 'Create editorial draft', exact: true })).toBeVisible();
    const axe = await new AxeBuilder({ page }).include('[data-fi-knowledge-recommendations]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations).toEqual([]);
    await queue.screenshot({ path: testInfo.outputPath('native-moderator-recommendations.png') });
    await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    expect(await queue.evaluate(region => [...region.querySelectorAll('a, button')].some(element => {
      const bounds = element.getBoundingClientRect(); return bounds.width > 0 && (bounds.left < -1 || bounds.right > window.innerWidth + 1);
    }))).toBe(false);
    const create = card.getByRole('button', { name: 'Create editorial draft', exact: true });
    await create.focus(); await expect(create).toBeFocused();
    const [converted] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-admin/admin-post.php')),
      page.keyboard.press('Enter'),
    ]);
    expect(converted.status()).toBe(302); await page.goto(admin, { waitUntil: 'domcontentloaded' }); await expect(card).toHaveCount(0);
    const after = fixture('state').fixture; expect(after.recommendation_article).toBeGreaterThan(0); expect(after.original_body_preserved).toBe(true); expect(after.recommendation_body_valid).toBe(true);
    await controls.getByLabel('Topic ID', { exact: true }).fill(String(state.topic));
    await controls.getByLabel('Action', { exact: true }).selectOption('recommend');
    await controls.getByLabel('Reviewed summary text or recommendation note', { exact: true }).fill('INSTALLED_RENEWED_RECOMMENDATION_NOTE');
    const [renewed] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-admin/admin-post.php')),
      controls.getByRole('button', { name: 'Apply', exact: true }).click(),
    ]);
    expect(renewed.status()).toBe(302); await page.goto(admin, { waitUntil: 'domcontentloaded' }); await expect(card).toContainText('INSTALLED_RENEWED_RECOMMENDATION_NOTE');
    const [withdrawn] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-admin/admin-post.php')),
      card.getByRole('button', { name: 'Withdraw recommendation', exact: true }).click(),
    ]);
    expect(withdrawn.status()).toBe(302); await page.goto(admin, { waitUntil: 'domcontentloaded' }); await expect(card).toHaveCount(0);
    expect(fixture('state').fixture.original_body_preserved).toBe(true);
  });

  test('administrator-selected native target preserves source guards and human editorial review', async ({ page, request }, testInfo) => {
    test.setTimeout(150000);
    fixture('target'); await nativeLogin(page, 'release_admin', 'isolated-release-only');
    await page.goto('/wp-admin/admin.php?page=forum-intelligence&tab=settings', { waitUntil: 'domcontentloaded' });
    const choice = page.getByLabel('Editorial target', { exact: true });
    await expect(choice.getByRole('option', { name: 'Owned editorial target', exact: true })).toHaveCount(1);
    await choice.selectOption('owned-release');
    const [settings] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-admin/options.php')),
      page.getByRole('button', { name: 'Save Changes', exact: true }).click(),
    ]);
    expect(settings.status()).toBe(302); await page.waitForURL(url => url.searchParams.has('settings-updated'), { waitUntil: 'domcontentloaded' });
    await expect(choice).toHaveValue('owned-release'); expect(fixture('state').fixture.target_selected).toBe(true);
    const admin = '/wp-admin/admin.php?page=forum-intelligence&tab=knowledge';
    await page.goto(admin, { waitUntil: 'domcontentloaded' });
    const controls = page.locator('.fi-panel').filter({ has: page.getByRole('heading', { name: 'Review community knowledge', exact: true }) });
    await controls.getByLabel('Topic ID', { exact: true }).fill(String(state.topic));
    await controls.getByLabel('Action', { exact: true }).selectOption('draft');
    const [created] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-admin/admin-post.php')),
      controls.getByRole('button', { name: 'Apply', exact: true }).click(),
    ]);
    expect(created.status()).toBe(302);
    const target = fixture('state').fixture;
    expect(target.target_article).toBeGreaterThan(0); expect(target.target_body_valid).toBe(true); expect(target.target_original_body_preserved).toBe(true);
    const edit = '/wp-admin/post.php?post=' + target.target_article + '&action=edit';
    await page.goto(edit, { waitUntil: 'domcontentloaded' });
    const review = page.locator('#fi-knowledge-review'); await expect(review).toBeVisible();
    const axe = await new AxeBuilder({ page }).include('#fi-knowledge-review').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze(); expect(axe.violations).toEqual([]);
    fixture('protect'); const denied = await page.goto(edit, { waitUntil: 'domcontentloaded' }); expect([403, 404]).toContain(denied.status());
    expect(await page.content()).not.toContain('INSTALLED_ADAPTER_SUMMARY_CANARY');
    fixture('restore'); await page.goto(edit, { waitUntil: 'domcontentloaded' });
    await expect(review.locator('[data-fi-review-status]')).toContainText('source discussion has changed');
    await page.locator('#content-html').click(); await page.locator('#content').fill('INSTALLED_ADAPTER_HUMAN_CANARY');
    const confirm = review.getByRole('checkbox', { name: 'I reviewed the source discussion and verified this article.', exact: true });
    await confirm.focus(); await page.keyboard.press('Space'); await expect(confirm).toBeChecked();
    const [saved] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-admin/post.php')),
      page.locator('#publish').click(),
    ]);
    expect(saved.status()).toBe(302); await page.waitForURL(url => url.searchParams.get('post') === String(target.target_article) && url.searchParams.has('message'), { waitUntil: 'domcontentloaded' });
    await expect(review.locator('[data-fi-review-status]')).toContainText('matches this article');
    expect(await page.locator('#content').inputValue()).toContain('INSTALLED_ADAPTER_HUMAN_CANARY');
    await review.screenshot({ path: testInfo.outputPath('native-adapted-knowledge-review.png') });
    await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    expect(await review.evaluate(region => [...region.querySelectorAll('input:not([type="hidden"]), a')].some(element => {
      const bounds = element.getBoundingClientRect(); return bounds.width > 0 && (bounds.left < -1 || bounds.right > window.innerWidth + 1);
    }))).toBe(false);
    const view = '/?page_id=' + state.page;
    expect(await (await request.get(view)).text()).toContain('INSTALLED_ADAPTER_HUMAN_CANARY');
    fixture('protect'); expect(await (await request.get(view)).text()).not.toContain('INSTALLED_ADAPTER_HUMAN_CANARY'); fixture('restore');
    const after = fixture('state').fixture; expect(after.target_original_body_preserved).toBe(true); expect(after.target_body_valid).toBe(true);
  });


  test('damaged native summary is withheld, draft refuses safely and explicit moderator correction recovers', async ({ page, request }, testInfo) => {
    test.setTimeout(120000);
    fixture('damage-summary'); let saved = fixture('state').fixture;
    expect(saved.summary_withheld).toBe(true); expect(saved.summary_damage_preserved).toBe(true);
    const source = new URL(state.topic_url);
    const before = await request.get(source.pathname + source.search); expect(before.status()).toBe(200);
    expect(await before.text()).not.toContain('INSTALLED_DAMAGED_SUMMARY_RECORD_CANARY');
    await nativeLogin(page, 'release_admin', 'isolated-release-only');
    const admin = '/wp-admin/admin.php?page=forum-intelligence&tab=knowledge';
    await page.goto(admin, { waitUntil: 'domcontentloaded' });
    const controls = page.locator('.fi-panel').filter({ has: page.getByRole('heading', { name: 'Review community knowledge', exact: true }) });
    await controls.getByLabel('Topic ID', { exact: true }).fill(String(state.topic));
    await controls.getByLabel('Action', { exact: true }).selectOption('draft');
    const [denied] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-admin/admin-post.php')),
      controls.getByRole('button', { name: 'Apply', exact: true }).click(),
    ]);
    expect(denied.status()).toBe(409); expect(await denied.text()).not.toContain('INSTALLED_DAMAGED_SUMMARY_RECORD_CANARY');
    saved = fixture('state').fixture; expect(saved.summary_damage_preserved).toBe(true); expect(saved.summary_no_new_copy).toBe(true); expect(saved.summary_article_bodies_preserved).toBe(true);
    await page.goto(admin, { waitUntil: 'domcontentloaded' });
    await controls.getByLabel('Topic ID', { exact: true }).fill(String(state.topic));
    await controls.getByLabel('Action', { exact: true }).selectOption('edit');
    await controls.getByLabel('Reviewed summary text or recommendation note', { exact: true }).fill('INSTALLED_SUMMARY_RECOVERY_CANARY فارسی');
    await controls.getByRole('button', { name: 'Apply', exact: true }).focus();
    const [repaired] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-admin/admin-post.php')),
      page.keyboard.press('Enter'),
    ]);
    expect(repaired.status()).toBe(302); await page.goto(admin, { waitUntil: 'domcontentloaded' });
    saved = fixture('state').fixture; expect(saved.summary_repaired).toBe(true); expect(saved.summary_article_bodies_preserved).toBe(true); expect(saved.summary_no_new_copy).toBe(true);
    const axe = await new AxeBuilder({ page }).include('#wpbody-content > .wrap').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze(); expect(axe.violations).toEqual([]);
    await controls.screenshot({ path: testInfo.outputPath('native-summary-record-recovery.png') });
    const textBounds = await controls.locator('#fi-knowledge-text').boundingBox();
    const panelBounds = await controls.boundingBox();
    expect(textBounds.width).toBeGreaterThan(panelBounds.width * 0.75);
    const labelBounds = await controls.locator('label[for="fi-knowledge-text"]').boundingBox();
    expect(labelBounds.y + labelBounds.height).toBeLessThanOrEqual(textBounds.y);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    expect(await controls.evaluate(region => [...region.querySelectorAll('input:not([type="hidden"]), select, textarea, button')].some(element => { const bounds = element.getBoundingClientRect(); return bounds.width > 0 && (bounds.left < -1 || bounds.right > window.innerWidth + 1); }))).toBe(false);
    await controls.screenshot({ path: testInfo.outputPath('native-summary-review-mobile-rtl.png') });

    expect(await (await request.get(source.pathname + source.search)).text()).toContain('INSTALLED_SUMMARY_RECOVERY_CANARY');
    fixture('protect'); expect(await (await request.get(source.pathname + source.search)).text()).not.toContain('INSTALLED_SUMMARY_RECOVERY_CANARY'); fixture('restore');
    expect(fixture('state').fixture.summary_article_bodies_preserved).toBe(true);
  });

  test('configured reactions submit with native keyboard controls and remain withdrawable after disabling', async ({ page, browser }, testInfo) => {
    test.setTimeout(150000);
    const owned = fixture('reactions').fixture;
    await nativeLogin(page, owned.reaction_login, 'isolated-reaction-only');
    const topic = new URL(state.topic_url);
    const source = topic.pathname + topic.search;
    await page.goto(source, { waitUntil: 'domcontentloaded' });
    const controls = page.locator('.fi-controls').filter({ has: page.locator('input[name="data[object]"][value="' + state.reply + '"]') });
    await expect(controls).toHaveCount(1);
    await expect(controls.getByRole('button', { name: 'Helpful reaction', exact: true })).toBeVisible();
    await expect(controls.getByRole('button', { name: 'Helpful', exact: true })).toBeVisible();
    await expect(controls.getByRole('button', { name: 'Like', exact: true })).toHaveCount(0);
    await expect(controls.getByRole('button', { name: 'Insightful', exact: true })).toHaveCount(0);
    const submit = async name => {
      await controls.getByRole('button', { name, exact: true }).focus();
      const [response] = await Promise.all([
        page.waitForResponse(candidate => candidate.request().method() === 'POST' && candidate.url().includes('/wp-admin/admin-post.php')),
        page.keyboard.press('Enter'),
      ]);
      expect(response.status()).toBe(302);
      await page.waitForURL(url => url.searchParams.has('fi_result'), { waitUntil: 'domcontentloaded' });
    };
    await submit('Thanks'); await expect(controls.getByRole('button', { name: 'Remove Thanks reaction', exact: true })).toBeVisible();
    const adminContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8899' });
    try {
      const adminPage = await adminContext.newPage(); await nativeLogin(adminPage, 'release_admin', 'isolated-release-only');
      await adminPage.goto('/wp-admin/admin.php?page=forum-intelligence&tab=settings', { waitUntil: 'domcontentloaded' });
      const group = adminPage.getByRole('group', { name: 'Enabled reactions', exact: true }); await expect(group.getByRole('checkbox')).toHaveCount(4);
      for (const checkbox of await group.getByRole('checkbox').all()) await checkbox.uncheck();
      const [saved] = await Promise.all([
        adminPage.waitForResponse(candidate => candidate.request().method() === 'POST' && candidate.url().includes('/wp-admin/options.php')),
        adminPage.getByRole('button', { name: 'Save Changes', exact: true }).click(),
      ]);
      expect(saved.status()).toBe(302); await adminPage.waitForURL(url => url.searchParams.has('settings-updated'), { waitUntil: 'domcontentloaded' });
      await expect(group.getByRole('checkbox', { checked: true })).toHaveCount(0);
      const adminAxe = await new AxeBuilder({ page: adminPage }).include('#wpbody-content > .wrap').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze(); expect(adminAxe.violations).toEqual([]);
      await group.screenshot({ path: testInfo.outputPath('native-disabled-reaction-settings.png') });
    } finally { await adminContext.close(); }
    await page.goto(source, { waitUntil: 'domcontentloaded' });
    await expect(controls.getByRole('button', { name: 'Helpful reaction', exact: true })).toHaveCount(0);
    await expect(controls.getByRole('button', { name: 'Helpful', exact: true })).toBeVisible();
    await expect(controls.getByRole('button', { name: 'Remove Thanks reaction', exact: true })).toBeVisible();
    expect(await controls.innerText()).not.toContain('reaction_thanks');
    const axe = await new AxeBuilder({ page }).include('.fi-controls').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze(); expect(axe.violations).toEqual([]);
    await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    expect(await controls.evaluate(region => [...region.querySelectorAll('button')].some(element => { const bounds = element.getBoundingClientRect(); return bounds.width > 0 && (bounds.left < -1 || bounds.right > window.innerWidth + 1); }))).toBe(false);
    await controls.screenshot({ path: testInfo.outputPath('native-disabled-reaction-withdrawal.png') });
    await submit('Remove Thanks reaction');
    await expect(controls.locator('input[name="data[kind]"][value^="reaction_"]')).toHaveCount(0);
    await expect(controls.getByRole('button', { name: 'Helpful', exact: true })).toBeVisible();
  });

  test('native helpful and downvote forms preserve unique reversible credits under current administrator policy', async ({ page, browser }, testInfo) => {
    test.setTimeout(150000);
    const owned = fixture('state').fixture;
    await nativeLogin(page, owned.reaction_login, 'isolated-reaction-only');
    const topic = new URL(state.topic_url); const source = topic.pathname + topic.search;
    const controls = page.locator('.fi-controls').filter({ has: page.locator('input[name="data[object]"][value="' + state.reply + '"]') });
    const adminContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8899' });
    try {
      const adminPage = await adminContext.newPage(); await nativeLogin(adminPage, 'release_admin', 'isolated-release-only');
      const configure = async enabled => {
        await adminPage.goto('/wp-admin/admin.php?page=forum-intelligence&tab=settings', { waitUntil: 'domcontentloaded' });
        await adminPage.getByRole('checkbox', { name: 'Enable downvotes', exact: true }).setChecked(enabled);
        const [saved] = await Promise.all([
          adminPage.waitForResponse(candidate => candidate.request().method() === 'POST' && candidate.url().includes('/wp-admin/options.php')),
          adminPage.getByRole('button', { name: 'Save Changes', exact: true }).click(),
        ]);
        expect(saved.status()).toBe(302);
        await adminPage.waitForURL(url => url.searchParams.has('settings-updated'), { waitUntil: 'domcontentloaded' });
        await expect(adminPage.getByRole('checkbox', { name: 'Enable downvotes', exact: true })).toBeChecked({ checked: enabled });
      };
      const submit = async name => {
        const previous = page.url(); await controls.getByRole('button', { name, exact: true }).focus();
        const [saved] = await Promise.all([
          page.waitForResponse(candidate => candidate.request().method() === 'POST' && candidate.url().includes('/wp-admin/admin-post.php')),
          page.keyboard.press('Enter'),
        ]);
        expect(saved.status()).toBe(302);
        await page.waitForURL(url => url.href !== previous && url.searchParams.has('fi_result'), { waitUntil: 'domcontentloaded' });
      };
      await configure(true); await page.goto(source, { waitUntil: 'domcontentloaded' });
      const missing = await page.request.post('/wp-admin/admin-post.php', { form: { action: 'fi_action', operation: 'signal', 'data[object]': String(state.reply), 'data[kind]': 'vote', 'data[value]': '1' } });
      expect(missing.status()).toBe(403);
      await submit('Helpful'); let saved = fixture('state').fixture; expect(saved.owned_vote_points).toBe(2); expect(saved.owned_signal_values.vote).toBe(1); expect(saved.owned_vote_subject_valid).toBe(true);
      await submit('Not helpful'); saved = fixture('state').fixture; expect(saved.owned_vote_points).toBe(-2); expect(saved.owned_signal_values.vote).toBe(-1); expect(saved.owned_vote_subject_valid).toBe(true);
      await configure(false); await page.goto(source, { waitUntil: 'domcontentloaded' });
      const nonce = await controls.locator('form').first().locator('input[name="_fi_nonce"]').inputValue();
      const denied = await page.request.post('/wp-admin/admin-post.php', { form: { action: 'fi_action', operation: 'signal', _fi_nonce: nonce, 'data[object]': String(state.reply), 'data[kind]': 'vote', 'data[value]': '-1' } });
      expect(denied.status()).toBe(400); saved = fixture('state').fixture; expect(saved.owned_vote_points).toBe(-2); expect(saved.owned_signal_values.vote).toBe(-1);
      await expect(controls.getByRole('button', { name: 'Not helpful', exact: true })).toBeVisible();
      const axe = await new AxeBuilder({ page }).include('.fi-controls').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze(); expect(axe.violations).toEqual([]);
      await controls.screenshot({ path: testInfo.outputPath('native-disabled-downvote-withdrawal.png') });
      await submit('Not helpful'); saved = fixture('state').fixture; expect(saved.owned_vote_points).toBe(0); expect(saved.owned_signal_values.vote).toBe(0);
      await expect(controls.getByRole('button', { name: 'Not helpful', exact: true })).toHaveCount(0);
      await expect(controls.getByRole('button', { name: 'Helpful', exact: true })).toBeVisible();
    } finally { await adminContext.close(); }
  });

});
