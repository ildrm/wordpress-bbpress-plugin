// Opt-in local copies. Browser storage contains authenticated ciphertext; keys stay in this page.
const PREFIX = 'fi:encrypted-draft:v1:';
const ITERATIONS = 600000;
const TTL = 24 * 60 * 60 * 1000;
const MAX_RECORD = 140000;
const encoder = new globalThis.TextEncoder();
const fail = (code) => { const error = new Error(code); error.code = code; throw error; };
const encode = (bytes) => {
  let text = '';
  for (let i = 0; i < bytes.length; i += 8192) text += String.fromCharCode(...bytes.subarray(i, i + 8192));
  return globalThis.btoa(text);
};
const decode = (value, min, max) => {
  if (typeof value !== 'string' || value.length > Math.ceil(max / 3) * 4 || !/^[A-Za-z0-9+/]*={0,2}$/.test(value)) fail('invalid');
  let bytes;
  try { bytes = Uint8Array.from(globalThis.atob(value), (char) => char.charCodeAt(0)); } catch { fail('invalid'); }
  if (bytes.length < min || bytes.length > max || encode(bytes) !== value) fail('invalid');
  return bytes;
};
const validText = (draft) => {
  if (!draft || typeof draft.title !== 'string' || typeof draft.body !== 'string') fail('invalid');
  if ([...draft.title].length > 200 || [...draft.body].length > 12000) fail('too_large');
  return { title: draft.title, body: draft.body };
};
const header = (record) => ({ v: record.v, alg: record.alg, kdf: record.kdf, iterations: record.iterations, salt: record.salt, iv: record.iv, created: record.created, expires: record.expires, revision: record.revision });
const parse = (raw, now) => {
  if (typeof raw !== 'string' || raw.length > MAX_RECORD) fail('invalid');
  let record;
  try { record = JSON.parse(raw); } catch { fail('invalid'); }
  const fields = ['v', 'alg', 'kdf', 'iterations', 'salt', 'iv', 'created', 'expires', 'revision', 'cipher'];
  if (!record || Array.isArray(record) || Object.keys(record).length !== fields.length || fields.some((name) => !Object.hasOwn(record, name))) fail('invalid');
  if (record.v !== 1 || record.alg !== 'AES-GCM-256' || record.kdf !== 'PBKDF2-SHA256' || record.iterations !== ITERATIONS ||
      !Number.isSafeInteger(record.created) || !Number.isSafeInteger(record.expires) || record.created < 0 ||
      record.expires - record.created !== TTL || record.created > now + 60000) fail('invalid');
  decode(record.salt, 16, 16); decode(record.iv, 12, 12); decode(record.revision, 16, 16); decode(record.cipher, 16, 100000);
  if (record.expires <= now) fail('expired');
  return record;
};
/** Storage is injectable for real WebCrypto tests. No passphrase or draft text enters storage keys. */
export async function createVault(scope, storage, crypto = globalThis.crypto, now = Date.now, exclusive = async (callback) => callback()) {
  if (!scope || !['topic', 'reply'].includes(scope.type) || !['blog', 'owner', 'object'].every((key) => Number.isSafeInteger(scope[key]) && scope[key] > 0) ||
      typeof scope.origin !== 'string' || !scope.origin || !crypto?.subtle) fail('unavailable');
  const identity = JSON.stringify([scope.origin, scope.blog, scope.owner]);
  const context = JSON.stringify([scope.origin, scope.blog, scope.owner, scope.type, scope.object]);
  const digest = async (text) => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(text))), (byte) => byte.toString(16).padStart(2, '0')).join('');
  const ownerPrefix = PREFIX + await digest(identity) + ':';
  const storageKey = ownerPrefix + await digest(context);
  let key = null; let salt = null; let baseline = null; let generation = 0;
  const lock = () => { ++generation; key = null; salt = null; baseline = null; };
  const current = (ticket) => { if (ticket !== generation || !key) fail('locked'); };
  const read = () => storage.getItem(storageKey);
  const compare = () => { if (read() !== baseline) fail('conflict'); };
  const aad = (record) => encoder.encode(JSON.stringify([context, header(record)]));
  const decrypt = async (record, sessionKey) => {
    try {
      const bytes = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: decode(record.iv, 12, 12), additionalData: aad(record), tagLength: 128 }, sessionKey, decode(record.cipher, 16, 100000));
      return validText(JSON.parse(new globalThis.TextDecoder('utf-8', { fatal: true }).decode(bytes)));
    } catch (error) { if (error.code === 'too_large') throw error; fail('invalid'); }
  };
  return {
    storageKey,
    lock,
    async unlock(passphrase) {
      lock(); const ticket = generation;
      if (typeof passphrase !== 'string' || [...passphrase].length < 16 || [...passphrase].length > 128) fail('passphrase_error');
      const raw = read(); const existing = raw === null ? null : parse(raw, now());
      const nextSalt = existing ? decode(existing.salt, 16, 16) : crypto.getRandomValues(new Uint8Array(16));
      const secret = encoder.encode(passphrase); passphrase = '';
      let material;
      try { material = await crypto.subtle.importKey('raw', secret, 'PBKDF2', false, ['deriveKey']); } finally { secret.fill(0); }
      const nextKey = await crypto.subtle.deriveKey({ name: 'PBKDF2', hash: 'SHA-256', salt: nextSalt, iterations: ITERATIONS }, material, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
      if (existing) { await decrypt(existing, nextKey); parse(raw, now()); }
      if (ticket !== generation) fail('locked');
      if (read() !== raw) fail('conflict');
      key = nextKey; salt = nextSalt; baseline = raw;
      return { exists: existing !== null };
    },
    async save(draft) {
      const ticket = generation; current(ticket); compare(); draft = validText(draft);
      // Count only this origin/blog/account's entries. Never clear another application's storage.
      let count = 0;
      for (let i = 0; i < storage.length; ++i) {
        if (storage.key(i)?.startsWith(ownerPrefix)) ++count;
      }
      if (baseline === null && count >= 10) fail('limit');
      const created = now();
      const record = { v: 1, alg: 'AES-GCM-256', kdf: 'PBKDF2-SHA256', iterations: ITERATIONS, salt: encode(salt), iv: encode(crypto.getRandomValues(new Uint8Array(12))), created, expires: created + TTL, revision: encode(crypto.getRandomValues(new Uint8Array(16))) };
      const plain = encoder.encode(JSON.stringify(draft));
      try {
        record.cipher = encode(new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv: decode(record.iv, 12, 12), additionalData: aad(record), tagLength: 128 }, key, plain)));
      } finally { plain.fill(0); }
      current(ticket); compare();
      const serialized = JSON.stringify(record);
      if (serialized.length > MAX_RECORD) fail('too_large');
      await exclusive(() => {
        current(ticket); compare();
        storage.setItem(storageKey, serialized); baseline = serialized;
      });
      return { expires: record.expires };
    },
    async recover() {
      const ticket = generation; current(ticket); compare();
      if (baseline === null) fail('missing');
      const draft = await decrypt(parse(baseline, now()), key);
      current(ticket); compare(); parse(baseline, now()); return draft;
    },
    async remove() {
      // Removal needs no passphrase, but must not delete a replacement saved during this operation.
      const raw = read();
      if (key && raw !== baseline) fail('conflict');
      await exclusive(() => {
        if (read() !== raw) fail('conflict');
        storage.removeItem(storageKey); lock();
      });
    },
  };
}

export function mountOfflineDraft({ config, action, target, type, form, anchor, read, write }) {
  if (!form || !target?.value || !config.loggedIn || !config.draftOwner || !config.draftBlog || !navigator.locks?.request) return;
  const labels = config.offlineDraftLabels;
  const panel = document.createElement('details'); panel.className = 'fi-panel'; panel.dataset.fiOfflineDraft = type;
  const summary = document.createElement('summary'); summary.textContent = labels.heading; panel.append(summary);
  const help = document.createElement('p'); help.textContent = labels.help; panel.append(help);
  const label = document.createElement('label'); label.textContent = labels.passphrase;
  const password = document.createElement('input'); password.type = 'password'; password.autocomplete = 'off';
  password.dataset.fiOfflinePassphrase = type; label.append(password); panel.append(label);
  const status = document.createElement('p'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); panel.append(status);
  const buttons = {};
  for (const name of ['unlock', 'save', 'recover', 'remove', 'lock']) {
    const button = document.createElement('button'); button.type = 'button'; button.textContent = labels[name];
    button.dataset.fiOfflineAction = name; panel.append(button); buttons[name] = button;
  }
  anchor.insertAdjacentElement('afterend', panel);
  let vault = null; let unlocked = false; let busy = false; let generation = 0;
  const states = () => {
    for (const name of ['unlock', 'save', 'recover', 'remove', 'lock']) buttons[name].disabled = busy || (['save', 'recover', 'lock'].includes(name) && !unlocked);
    password.disabled = busy || unlocked;
  };
  const lock = () => {
    ++generation; vault?.lock(); unlocked = false; password.value = ''; states();
  };
  const errorStatus = (error) => { status.textContent = labels[error.code] || labels.unavailable; };
  const scope = () => ({ origin: window.location.origin, blog: Number(config.draftBlog), owner: Number(config.draftOwner), object: Number(target.value), type });
  const authorize = async (draft, capturedScope) => {
    const result = await action('offline_restore', { ...draft, object: capturedScope.object, owner: capturedScope.owner, blog: capturedScope.blog, type });
    if (!result || result.owner !== capturedScope.owner || result.blog !== capturedScope.blog || result.object !== capturedScope.object || result.type !== type ||
        typeof result.title !== 'string' || typeof result.body !== 'string') fail('unavailable');
    return result;
  };
  const run = async (name) => {
    if (busy) return;
    if (name === 'lock') { lock(); status.textContent = labels.locked; return; }
    const ticket = generation; const capturedScope = scope(); let secret = password.value; password.value = '';
    busy = true; states();
    try {
      if (!vault) {
        const nextVault = await createVault(capturedScope, window.localStorage, window.crypto, Date.now, (callback) => navigator.locks.request('fi-encrypted-draft:' + JSON.stringify(capturedScope), callback));
        if (ticket !== generation) { nextVault.lock(); return; }
        vault = nextVault;
      }
      if (ticket !== generation) return;
      if (name === 'unlock') {
        await authorize({ title: '', body: '' }, capturedScope);
        if (ticket !== generation) return;
        await vault.unlock(secret); secret = '';
        if (ticket !== generation) { vault.lock(); return; }
        unlocked = true; status.textContent = labels.ready;
      } else if (name === 'save') {
        await vault.save(read());
        if (ticket === generation) status.textContent = labels.saved;
      } else if (name === 'recover') {
        if (read().body.trim() || read().title.trim()) fail('occupied');
        const draft = await vault.recover();
        if (ticket !== generation) return;
        const safe = await authorize(draft, capturedScope);
        if (ticket !== generation) return;
        // The user may have typed while decrypting or while the authorization request was in flight.
        if (read().body.trim() || read().title.trim()) fail('occupied');
        write(safe); status.textContent = labels.recovered;
      } else if (name === 'remove') {
        await vault.remove();
        if (ticket === generation) { lock(); status.textContent = labels.removed; }
      }
    } catch (error) {
      if (ticket === generation) { if (!['occupied', 'too_large', 'missing', 'limit'].includes(error.code)) lock(); errorStatus(error); }
    } finally { secret = ''; busy = false; states(); }
  };
  for (const [name, button] of Object.entries(buttons)) button.addEventListener('click', () => run(name));
  password.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') { event.preventDefault(); run('unlock'); }
  });
  target.addEventListener('change', () => { lock(); vault = null; status.textContent = labels.locked; });
  form.addEventListener('submit', lock);
  window.addEventListener('pagehide', lock);
  document.addEventListener('visibilitychange', () => { if (document.hidden) { lock(); status.textContent = labels.locked; } });
  window.addEventListener('storage', (event) => { if (vault && event.key === vault.storageKey) { lock(); status.textContent = labels.conflict; } });
  states();
}
