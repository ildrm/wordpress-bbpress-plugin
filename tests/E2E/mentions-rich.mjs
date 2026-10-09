import { nativeLogin } from '../Support/native-login.mjs';
import {test,expect} from '@playwright/test';
import {readFile} from 'node:fs/promises';
const fixture=JSON.parse(await readFile('.runtime/e2e.json','utf8'));
test.skip(process.env.FI_RICH_MENTION_E2E!=='1','Requires the owned TinyMCE compatibility fixture.');
test('mentions insert in a native TinyMCE composer without losing selected text',async({page})=>{
await nativeLogin(page, 'e2e_author');
await page.goto(fixture.forum_url);
const body=page.frameLocator('#bbp_topic_content_ifr').locator('body');
await expect(body).toBeVisible();
const managed=await page.evaluate(()=>window.fiConfig.mentionsManaged);
if(managed){
await expect.poll(()=>body.evaluate(element=>Boolean(window.parent.jQuery(element).data('atwho')))).toBe(true);
await body.pressSequentially('Existing rich details @e2e_me',{delay:90});
await expect(page.locator('.atwho-view:visible li').filter({hasText:'@e2e_member'}).first()).toBeVisible();
await body.press('Enter');await expect(body).toContainText('@e2e_member');
}else{
await body.fill('Selected rich details must survive.');
await page.evaluate(()=>window.tinymce.get('bbp_topic_content').selection.select(window.tinymce.get('bbp_topic_content').getBody(),true));
const picker=page.locator('.fi-mentions');const input=picker.getByRole('combobox');
await input.fill('e2e_me');await expect(picker.getByRole('option')).toBeVisible();await input.press('ArrowDown');await input.press('Enter');
await expect(body).toContainText('@e2e_member');await expect(body).toContainText('Selected rich details must survive.');
}
expect(page.url()).toBe(fixture.forum_url);
});
