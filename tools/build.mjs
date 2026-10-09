import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
await mkdir('assets/build', { recursive: true });
const manifest = {};
for (const name of ['frontend.js', 'frontend.css', 'offline-drafts.js', 'composer-tools.js']) {
  const source = await readFile('assets/src/' + name);
  await writeFile('assets/build/' + name, source);
  manifest[name] = createHash('sha256').update(source).digest('hex');
}
await writeFile('assets/build/manifest.json', JSON.stringify(manifest, null, 2) + '\n');
console.log('Built frontend assets from source.');
