import { test, expect, request } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
test.describe('Packaged protected attachments', () => {
  test.skip(process.env.FI_RELEASE_E2E !== '1', 'Requires the isolated packaged release installation on port 8899.');
  const baseURL = 'http://127.0.0.1:8899';
  const fixture = (action) => {
    const output = execFileSync(process.env.FI_PHP || 'C:/xampp/php/php.exe', ['tools/release-fixture.php', action], { encoding: 'utf8' });
    return JSON.parse(output.trim().split(/\r?\n/).findLast((line) => line.startsWith('{"fixture"')));
  };
  let client; let anonymous; let state; let nonce;
  test.beforeAll(async () => {
    state = fixture('seed');
    client = await request.newContext({ baseURL }); anonymous = await request.newContext({ baseURL });
    await client.get('/wp-login.php');
    const login = await client.post('/wp-login.php', { form: { log: 'release_admin', pwd: 'isolated-release-only', 'wp-submit': 'Log In', redirect_to: baseURL, testcookie: '1' } });
    expect(login.ok()).toBeTruthy();
    const html = await (await client.get(state.fixture.url)).text();
    nonce = html.match(/name="_fi_nonce" value="([^"]+)"/)?.[1]; expect(nonce).toBeTruthy();
  });
  test.afterAll(async () => { fixture('cleanup'); await client?.dispose(); await anonymous?.dispose(); });
  const upload = (name, buffer, mimeType = 'text/plain') => client.post('/wp-admin/admin-post.php', { maxRedirects: 0, headers: { Referer: state.fixture.url }, multipart: { action: 'fi_action', operation: 'attachment', _fi_nonce: nonce, 'data[object]': String(state.fixture.topic), attachment: { name, mimeType, buffer } } });
  test('genuine multipart bytes are protected, permission checked and erased', async () => {
    const content = Buffer.from('PROTECTED_FILE_CANARY: actual HTTP upload bytes.\n');
    let response = await upload('evidence.txt', content);
    expect(response.status()).toBe(302);
    state = fixture('state'); expect(state.files).toHaveLength(1);
    const file = state.files[0]; expect(fs.readFileSync(file.path).equals(content)).toBeTruthy();
    const download = '/wp-admin/admin-post.php?action=fi_download&id=' + file.id;
    response = await anonymous.get(download); expect(response.status()).toBe(200); expect((await response.body()).equals(content)).toBeTruthy();
    expect(response.headers()['x-content-type-options']).toBe('nosniff');
    expect(response.headers()['content-disposition']).toContain('attachment;');
    expect(response.headers()['cache-control']).toContain('no-cache');
    fixture('hide');
    response = await anonymous.get(download); expect(response.status()).toBe(404); expect(await response.text()).not.toContain('PROTECTED_FILE_CANARY');
    response = await client.get(download); expect(response.status()).toBe(200);
    const direct = await anonymous.get('/' + file.path.split(/[\\/]/).at(-1));
    expect(await direct.text()).not.toContain('PROTECTED_FILE_CANARY'); expect((await direct.body()).equals(content)).toBeFalsy();
    fixture('show');
    fixture('erase'); expect(fs.existsSync(file.path)).toBeFalsy();
    expect((await client.get(download)).status()).toBe(404);
  });
  test('rejects forged types, executable content, oversized bytes and missing nonce', async () => {
    fixture('reset_limits');
    expect((await upload('forged.png', Buffer.from('This is plain text with a fake browser MIME.'), 'image/png')).status()).toBe(400);
    expect((await upload('executable.txt', Buffer.from('<?php echo "EXECUTABLE_CANARY";'))).status()).toBe(400);
    expect((await upload('oversized.txt', Buffer.alloc(5 * 1024 * 1024 + 1, 65))).status()).toBe(400);
    const response = await client.post('/wp-admin/admin-post.php', { form: { action: 'fi_action', operation: 'attachment', 'data[object]': String(state.fixture.topic) } });
    expect(response.status()).toBe(403); expect(fixture('state').files).toHaveLength(0);
  });
  test('enforces the per-post file cap without orphan bytes', async () => {
    fixture('reset_limits');
    for (let i = 0; i < 5; i++) expect((await upload('file-' + i + '.txt', Buffer.from('Verified bounded attachment ' + i))).status()).toBe(302);
    state = fixture('state'); expect(state.files).toHaveLength(5);
    const directory = fs.readdirSync(process.env.FI_PROTECTED_TEST_DIR || fileDirectory(state.files[0].path));
    fixture('reset_limits');
    expect((await upload('overflow.txt', Buffer.from('Beyond the allowed per-post maximum'))).status()).toBe(400);
    expect(fixture('state').files).toHaveLength(5);
    expect(fs.readdirSync(fileDirectory(state.files[0].path))).toEqual(directory);
    fixture('erase'); for (const file of state.files) expect(fs.existsSync(file.path)).toBeFalsy();
  });
});
function fileDirectory(path) { return path.slice(0, Math.max(path.lastIndexOf('/'), path.lastIndexOf('\\'))); }
