import { nativeLogin } from '../Support/native-login.mjs';
import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
const fixture = JSON.parse(await readFile('.runtime/e2e.json', 'utf8'));
const login = page => nativeLogin(page, 'e2e_member');test('native reply and topic drafts save recover and discard without publishing or JavaScript', async ({ browser }) => {
  test.setTimeout(90000);
  const context = await browser.newContext({ javaScriptEnabled: false }); const page = await context.newPage();
  try {
    await login(page);
    for (const type of ['reply', 'topic']) {
      await page.goto(type === 'reply' ? fixture.topic_url : fixture.forum_url, { waitUntil: 'domcontentloaded' });
      const body = page.locator(type === 'reply' ? '#bbp_reply_content' : '#bbp_topic_content');
      const form = body.locator('xpath=ancestor::form');
      const discard = form.locator('[name="fi_draft_command"][value="discard"]');
      if (await discard.count()) await discard.click();
      if (type === 'topic') await page.locator('#bbp_topic_title').fill('Native owned draft');
      await body.fill('DRAFT_PRIVATE_CANARY ' + type);
      await form.locator('[name="fi_draft_command"][value="save"]').click();
      await expect(body).toHaveValue('DRAFT_PRIVATE_CANARY ' + type);
      await expect(page.locator('.bbp-reply-content, .bbp-topic-content').filter({ hasText: 'DRAFT_PRIVATE_CANARY' })).toHaveCount(0);
      await page.goto(type === 'reply' ? fixture.topic_url : fixture.forum_url, { waitUntil: 'domcontentloaded' });
      await expect(body).toHaveValue('');
      await form.locator('[name="fi_draft_command"][value="recover"]').click();
      await expect(body).toHaveValue('DRAFT_PRIVATE_CANARY ' + type);
      await form.locator('[name="fi_draft_command"][value="discard"]').click();
      await expect(body).toHaveValue('');
      await expect(form.locator('[name="fi_draft_command"][value="recover"]')).toHaveCount(0);
    }
  } finally { await context.close(); }
});
test('reply autosave rejects an older tab and recovery preserves unsaved text', async ({ browser }) => {
  test.setTimeout(90000);
  const context = await browser.newContext(); const first = await context.newPage();
  try {
    await login(first); await first.goto(fixture.topic_url, { waitUntil: 'domcontentloaded' });
    const existing = first.locator('[name="fi_draft_command"][value="discard"]');
    if (await existing.count()) await existing.click();
    const second = await context.newPage(); await second.goto(fixture.topic_url, { waitUntil: 'domcontentloaded' });
    const body1 = first.locator('#bbp_reply_content'); const body2 = second.locator('#bbp_reply_content');
    await expect(first.locator('[name="fi_draft_version"]')).toHaveValue('0');
    await expect(second.locator('[name="fi_draft_version"]')).toHaveValue('0');
    await body1.fill('Newer reply text from the first tab.');
    await expect(first.locator('[data-fi-draft-status="reply"]')).toHaveText('Draft saved on this site.', { timeout: 15000 });
    await body2.fill('Unsaved text from the older tab must survive.');
    await expect(second.locator('[data-fi-draft-status="reply"]')).toContainText('newer draft', { timeout: 15000 });
    await expect(body2).toHaveValue('Unsaved text from the older tab must survive.');
    const recovery = second.locator('button[type="button"]').filter({ hasText: 'Recover saved draft' });
    await recovery.click(); await expect(body2).toHaveValue('Unsaved text from the older tab must survive.');
    await expect(second.locator('[data-fi-draft-status="reply"]')).toContainText('current text was kept');
    await body2.fill(''); await recovery.click();
    await expect(body2).toHaveValue('Newer reply text from the first tab.');
    await second.reload({ waitUntil: 'domcontentloaded' });
    await second.locator('[name="fi_draft_command"][value="discard"]').click();
    await expect(second.locator('[name="fi_draft_command"][value="recover"]')).toHaveCount(0);
  } finally { await context.close(); }
});
