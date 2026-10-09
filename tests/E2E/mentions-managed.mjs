import { nativeLogin } from '../Support/native-login.mjs';
import {test, expect} from '@playwright/test';
import {readFile} from 'node:fs/promises';
const fixture=JSON.parse(await readFile('.runtime/e2e.json','utf8'));
test('enabled BuddyPress owns composer mention autocomplete',async({page})=>{
  await nativeLogin(page, 'e2e_author');
  await page.goto(fixture.forum_url,{waitUntil:'domcontentloaded'});
  await expect(page.locator('.fi-mentions')).toHaveCount(0);
  const body=page.locator('#bbp_topic_content');
  await expect(body).toHaveClass(/bp-suggestions/);
  await expect.poll(()=>body.evaluate((element)=>Boolean(window.jQuery?.(element).data('atwho')))).toBe(true);
  expect((await page.request.get('/wp-content/plugins/forum-intelligence-for-bbpress/vendor/autoload.php')).status()).toBe(404);
  await body.pressSequentially('Question for @e2e_me',{delay:90});
  await expect(page.locator('.atwho-view:visible li').filter({hasText:'@e2e_member'}).first()).toBeVisible();
  await page.keyboard.press('Enter');
  expect(await body.inputValue()).toContain('@e2e_member');
});
