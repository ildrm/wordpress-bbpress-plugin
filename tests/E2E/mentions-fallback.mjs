import { nativeLogin } from '../Support/native-login.mjs';
import {test, expect} from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
const fixture=JSON.parse(await readFile('.runtime/e2e.json','utf8'));
test.skip(process.env.FI_FALLBACK_MENTION_E2E!=='1','Requires the isolated fallback fixture with BuddyPress temporarily disabled.');
const login = nativeLogin;test('fallback autocomplete preserves text and native posting delivers one authorized mention',async({browser})=>{
  test.setTimeout(90000);
  const author=await browser.newContext();const member=await browser.newContext();
  const title='Owned mention fixture '+Date.now();let post=0;
  try{
    const page=await author.newPage();await login(page,'e2e_author');
    await page.goto(fixture.forum_url,{waitUntil:'domcontentloaded'});
    const body=page.locator('#bbp_topic_content');await body.fill('Existing topic details survive mention selection.');
    await body.evaluate((element)=>element.setSelectionRange(0,element.value.length));
    const picker=page.locator('.fi-mentions').filter({has:page.getByRole('combobox',{name:'Find a member to mention'})}).first();
    const input=picker.getByRole('combobox',{name:'Find a member to mention'});
    await input.fill('e2e_m');await expect(picker.getByRole('option').filter({hasText:'@e2e_member'})).toBeVisible();
    await input.press('ArrowDown');await input.press('Enter');
    await expect(body).toHaveValue(/@e2e_member.*Existing topic details survive mention selection\./);
    expect(page.url()).toBe(fixture.forum_url);
    const axe=await new AxeBuilder({page}).include('.fi-mentions').withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();expect(axe.violations).toEqual([]);
    await page.locator('#bbp_topic_title').fill(title);
    await page.locator('#bbp_topic_submit').click();
    await expect(page.getByText(title,{exact:true}).first()).toBeVisible();
    post=await page.locator('body').evaluate((element)=>Number(element.className.match(/postid-(\d+)/)?.[1]||0));expect(post).toBeGreaterThan(0);
    const inbox=await member.newPage();await login(inbox,'e2e_member');await inbox.goto(fixture.hub_url,{waitUntil:'domcontentloaded'});
    const row=inbox.locator('[data-fi-center] li').filter({has:inbox.getByRole('link',{name:title,exact:true})});
    await expect(row).toHaveCount(1);await expect(row.getByText('Mentions',{exact:false})).toBeVisible();
  }finally{
    await author.close();await member.close();
    execFileSync(process.env.FI_PHP||'C:/xampp/php/php.exe',['tools/cleanup-mention-fixture.php',String(post),title],{stdio:'pipe'});
  }
});

test('fallback lookup failure keeps composing available and mobile RTL picker remains keyboard usable',async({page})=>{
await login(page,'e2e_author');await page.setViewportSize({width:390,height:844});await page.goto(fixture.forum_url);
await page.evaluate(()=>{document.documentElement.dir='rtl';document.body.classList.add('rtl');});
const body=page.locator('#bbp_topic_content');const text='Unsaved content remains available during lookup failures.';await body.fill(text);
const picker=page.locator('.fi-mentions');const input=picker.getByRole('combobox');
const isLookup=url=>url.pathname.endsWith('/mentions')||url.searchParams.get('rest_route')?.endsWith('/mentions');
await page.route(isLookup,route=>route.fulfill({status:429,contentType:'application/json',body:JSON.stringify({code:'rate_limit',message:'Try later.'})}));
await input.fill('e2e_me');await expect(picker.getByRole('status')).toContainText('unavailable');await expect(body).toHaveValue(text);
await page.unroute(isLookup);await input.fill('e2e_mem');await expect(picker.getByRole('option')).toBeVisible();
await input.press('ArrowDown');await expect(input).toHaveAttribute('aria-activedescendant',/fi-mentions/);
const bounds=await picker.boundingBox();expect(bounds.x).toBeGreaterThanOrEqual(0);expect(bounds.x+bounds.width).toBeLessThanOrEqual(391);
const axe=await new AxeBuilder({page}).include('.fi-mentions').withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();expect(axe.violations).toEqual([]);
await input.press('Escape');await expect(input).toHaveAttribute('aria-expanded','false');await expect(body).toHaveValue(text);
expect(await input.evaluate(element=>element.name===''&&!element.required)).toBe(true);
});
