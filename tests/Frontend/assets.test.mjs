import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
test('shipping assets exactly match reproducible sources', async () => {
  const manifest = JSON.parse(await readFile('assets/build/manifest.json', 'utf8'));
  for (const name of ['frontend.js', 'frontend.css', 'offline-drafts.js', 'composer-tools.js']) {
    const source = await readFile('assets/src/' + name);
    const build = await readFile('assets/build/' + name);
    assert.deepEqual(build, source);
    assert.equal(manifest[name], createHash('sha256').update(source).digest('hex'));
  }
});
