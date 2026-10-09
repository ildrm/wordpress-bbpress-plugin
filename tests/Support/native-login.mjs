import { expect } from '@playwright/test';
/** Preserve native authentication while avoiding WordPress's delayed login autofocus. */
export async function nativeLogin(page, user, password = 'test-browser-only') {
  await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#loginform').evaluate((form, credentials) => {
    form.querySelector('#user_login').value = credentials.user;
    form.querySelector('#user_pass').value = credentials.password;
  }, { user, password });
  const [response] = await Promise.all([
    page.waitForResponse(candidate => candidate.request().method() === 'POST' && candidate.url().includes('/wp-login.php')),
    page.locator('#wp-submit').click(),
  ]);
  expect([302, 303]).toContain(response.status());
  await expect.poll(async () => (await page.context().cookies()).some(cookie => cookie.name.startsWith('wordpress_logged_in_'))).toBe(true);
}
