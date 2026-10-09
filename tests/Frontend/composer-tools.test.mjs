import test from 'node:test';
import assert from 'node:assert/strict';
import { formatSelection } from '../../assets/src/composer-tools.js';
test('plain formatting preserves surrounding bytes, selection and Unicode across all actions', () => {
  const text = 'before متن 😀 after';
  const start = text.indexOf('متن'); const end = text.indexOf(' after');
  const tags = { bold: ['<strong>', '</strong>'], italic: ['<em>', '</em>'], code: ['<code>', '</code>'], quote: ['\n<blockquote>', '</blockquote>\n'] };
  for (const [kind, [before, after]] of Object.entries(tags)) {
    const result = formatSelection(text, start, end, kind);
    assert.equal(result.text, text.slice(0, start) + before + text.slice(start, end) + after + text.slice(end));
    assert.equal(result.text.slice(result.start, result.end), 'متن 😀');
  }
});
test('code renders selected HTML literally and a collapsed selection provides editable text', () => {
  const source = 'prefix <img src="x" onerror="attack()">& suffix';
  const start = source.indexOf('<img'); const end = source.indexOf(' suffix');
  const result = formatSelection(source, start, end, 'code');
  assert.ok(!result.text.includes('<img')); assert.ok(result.text.includes('&lt;img')); assert.ok(result.text.includes('&amp;'));
  assert.equal(result.text.slice(0, start), 'prefix '); assert.ok(result.text.endsWith(' suffix'));
  const collapsed = formatSelection('beforeafter', 6, 6, 'bold', 'متن');
  assert.equal(collapsed.text, 'before<strong>متن</strong>after');
  assert.equal(collapsed.text.slice(collapsed.start, collapsed.end), 'متن');
});
test('invalid selection and prototype names are rejected without changing the supplied text', () => {
  for (const [start, end, kind] of [[-1, 0, 'bold'], [3, 2, 'code'], [0, 9, 'italic'], [0.5, 2, 'quote'], [0, 0, '__proto__'], [0, 0, 'unknown']]) {
    assert.throws(() => formatSelection('text', start, end, kind), /invalid_selection/);
  }
});
