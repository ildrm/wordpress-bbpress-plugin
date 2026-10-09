import test from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { createVault } from '../../assets/src/offline-drafts.js';
class Storage {
  entries = new Map();
  get length() { return this.entries.size; }
  key(index) { return [...this.entries.keys()][index] ?? null; }
  getItem(key) { return this.entries.get(key) ?? null; }
  setItem(key, value) { this.entries.set(key, String(value)); }
  removeItem(key) { this.entries.delete(key); }
}
const scope = { origin: 'https://forum.example', blog: 1, owner: 12, object: 34, type: 'topic' };
const secret = 'unique test passphrase 2026';
const text = { title: 'عنوان 😀', body: '<p>PRIVATE_OFFLINE_CANARY متن 😀</p>' };
const denied = (code) => (error) => error.code === code;
test('real WebCrypto round-trip, fresh IVs, non-plaintext storage, reload and wrong passphrase', async () => {
  const storage = new Storage();
  const vault = await createVault(scope, storage, webcrypto);
  await vault.unlock(secret); await vault.save(text);
  const first = storage.getItem(vault.storageKey);
  assert.ok(!first.includes('PRIVATE_OFFLINE_CANARY')); assert.ok(!first.includes(secret));
  assert.ok(!vault.storageKey.includes(secret)); assert.deepEqual(await vault.recover(), text);
  await vault.save(text); const second = JSON.parse(storage.getItem(vault.storageKey));
  assert.notEqual(second.iv, JSON.parse(first).iv); assert.notEqual(second.revision, JSON.parse(first).revision);
  assert.notEqual(second.cipher, JSON.parse(first).cipher);
  vault.lock(); await assert.rejects(vault.recover(), denied('locked'));
  const reloaded = await createVault(scope, storage, webcrypto);
  await assert.rejects(reloaded.unlock('a different long passphrase'), denied('invalid'));
  await reloaded.unlock(secret); assert.deepEqual(await reloaded.recover(), text);
  await reloaded.remove(); assert.equal(storage.length, 0);
});
test('site, blog, account, source and composer type isolate keys and authenticate transplanted ciphertext', async () => {
  const storage = new Storage(); const vault = await createVault(scope, storage, webcrypto);
  await vault.unlock(secret); await vault.save(text); const raw = storage.getItem(vault.storageKey);
  for (const change of [{ origin: 'https://other.example' }, { blog: 2 }, { owner: 13 }, { object: 35 }, { type: 'reply' }]) {
    const other = await createVault({ ...scope, ...change }, storage, webcrypto);
    assert.notEqual(other.storageKey, vault.storageKey);
    storage.setItem(other.storageKey, raw);
    await assert.rejects(other.unlock(secret), denied('invalid'));
    storage.removeItem(other.storageKey);
  }
});
test('authenticated headers, ciphertext, expiry and bounded KDF reject corruption before restoration', async () => {
  const storage = new Storage(); let clock = 1000000;
  const vault = await createVault(scope, storage, webcrypto, () => clock);
  await vault.unlock(secret); await vault.save(text);
  const original = JSON.parse(storage.getItem(vault.storageKey));
  for (const field of ['cipher', 'salt', 'iv', 'revision']) {
    const record = structuredClone(original);
    record[field] = (record[field][0] === 'A' ? 'B' : 'A') + record[field].slice(1);
    storage.setItem(vault.storageKey, JSON.stringify(record));
    const reload = await createVault(scope, storage, webcrypto, () => clock);
    await assert.rejects(reload.unlock(secret), denied('invalid'));
  }
  for (const patch of [{ iterations: 1 }, { iterations: 999999999 }, { alg: 'AES-CBC' }, { v: 2 }, { extra: 'invalid' }, { created: original.created + 1, expires: original.expires + 1 }, { cipher: 'x'.repeat(140001) }]) {
    storage.setItem(vault.storageKey, JSON.stringify({ ...original, ...patch }));
    const reload = await createVault(scope, storage, webcrypto, () => clock);
    await assert.rejects(reload.unlock(secret), denied('invalid'));
  }
  storage.setItem(vault.storageKey, JSON.stringify(original));
  clock = original.expires;
  const expired = await createVault(scope, storage, webcrypto, () => clock);
  await assert.rejects(expired.unlock(secret), denied('expired'));
  await expired.remove(); assert.equal(storage.length, 0);
});
test('cross-tab compare under shared lock rejects a stale save and preserves the newer ciphertext', async () => {
  const storage = new Storage(); let tail = Promise.resolve();
  const exclusive = (callback) => {
    const job = tail.then(callback); tail = job.catch(() => {}); return job;
  };
  const first = await createVault(scope, storage, webcrypto, Date.now, exclusive);
  await first.unlock(secret); await first.save(text);
  const second = await createVault(scope, storage, webcrypto, Date.now, exclusive);
  await second.unlock(secret);
  const results = await Promise.allSettled([first.save({ title: '', body: 'first tab' }), second.save({ title: '', body: 'second tab' })]);
  assert.equal(results.filter((result) => result.status === 'fulfilled').length, 1);
  assert.equal(results.find((result) => result.status === 'rejected').reason.code, 'conflict');
  const winner = results[0].status === 'fulfilled' ? first : second;
  assert.ok(['first tab', 'second tab'].includes((await winner.recover()).body));
});
test('bounds, quota and unavailable storage never overwrite a previous copy or unrelated data', async () => {
  const storage = new Storage(); storage.setItem('unrelated-owner', 'keep');
  const vault = await createVault(scope, storage, webcrypto);
  await assert.rejects(vault.unlock('short'), denied('passphrase_error'));
  await vault.unlock(secret);
  await assert.rejects(vault.save({ title: '', body: '😀'.repeat(12001) }), denied('too_large'));
  await vault.save({ title: '😀'.repeat(200), body: '😀'.repeat(12000) });
  assert.equal((await vault.recover()).body.length, 24000);
  const raw = storage.getItem(vault.storageKey);
  storage.setItem = () => { throw new Error('QuotaExceededError'); };
  await assert.rejects(vault.save(text), /QuotaExceededError/);
  assert.equal(storage.getItem(vault.storageKey), raw);
  assert.equal(storage.getItem('unrelated-owner'), 'keep');
  await vault.remove(); assert.equal(storage.getItem('unrelated-owner'), 'keep');
});
test('locking while key derivation is pending prevents a late unlock', async () => {
  const vault = await createVault(scope, new Storage(), webcrypto);
  const pending = vault.unlock(secret); vault.lock();
  await assert.rejects(pending, denied('locked'));
  await assert.rejects(vault.save(text), denied('locked'));
});

test('ten-copy limit is scoped to the account and does not evict another source', async () => {
  const storage = new Storage(); const first = await createVault(scope, storage, webcrypto);
  await first.unlock(secret); await first.save(text); const raw = storage.getItem(first.storageKey);
  for (let object = 40; object < 49; ++object) {
    const slot = await createVault({ ...scope, object }, storage, webcrypto);
    storage.setItem(slot.storageKey, raw);
  }
  const eleventh = await createVault({ ...scope, object: 99 }, storage, webcrypto);
  await eleventh.unlock(secret); await assert.rejects(eleventh.save(text), denied('limit'));
  assert.equal(storage.length, 10); assert.equal(storage.getItem(first.storageKey), raw);
  const other = await createVault({ ...scope, owner: 13 }, storage, webcrypto);
  await other.unlock(secret); await other.save(text); assert.equal(storage.length, 11);
});
