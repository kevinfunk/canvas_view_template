// Runs the panel's pure helpers (extension/names.js, extension/strings.js).
// No dependencies: node tests/js/panel.test.mjs
import assert from 'node:assert';
import fs from 'node:fs';
import path from 'node:path';

globalThis.document = { documentElement: { lang: 'en' } };
const { slug, freeId, sortTemplates, matchesQuery, MACHINE_NAME } = await import('../../extension/names.js');
const { t, setStrings, summarize, list } = await import('../../extension/strings.js');

const results = [];
function check(name, fn) {
  try {
    fn();
    results.push('PASS ' + name);
  }
  catch (e) {
    results.push('FAIL ' + name + ': ' + e.message);
    process.exitCode = 1;
  }
}

check('slug lowercases and joins words with underscores', () => {
  assert.strictEqual(slug('Articles page'), 'articles_page');
  assert.strictEqual(slug('  --Hello, World!--  '), 'hello_world');
});
check('slug drops accents instead of the letter', () => {
  assert.strictEqual(slug('Café Crème'), 'cafe_creme');
});
check('slug starts with a letter', () => {
  assert.strictEqual(slug('2024 news'), 't_2024_news');
});
check('slug falls back for labels with no Latin letters', () => {
  assert.strictEqual(slug('日本語'), 'template');
  assert.strictEqual(slug('!!!'), 'template');
});
check('slug results are valid machine names and at most 64 long', () => {
  for (const label of ['A'.repeat(200), 'x', '9', 'Ünïcödé label']) {
    const id = slug(label);
    assert.ok(MACHINE_NAME.test(id), `${label} gave ${id}`);
  }
});

check('freeId returns the slug when free, then numbers it', () => {
  assert.strictEqual(freeId('Copy of one', new Set()), 'copy_of_one');
  assert.strictEqual(freeId('Copy of one', new Set(['copy_of_one'])), 'copy_of_one_2');
  assert.strictEqual(freeId('Copy of one', new Set(['copy_of_one', 'copy_of_one_2'])), 'copy_of_one_3');
});
check('freeId keeps a long id inside the limit with its suffix', () => {
  const stem = 'a'.repeat(64);
  const id = freeId(stem, new Set([stem]));
  assert.ok(MACHINE_NAME.test(id), id);
  assert.ok(id.endsWith('_2'));
});

check('sortTemplates sorts by label, ignoring case, numbers in order', () => {
  const sorted = sortTemplates(
    [{ id: 'b', label: 'beta' }, { id: 'a10', label: 'Item 10' }, { id: 'a2', label: 'Item 2' }, { id: 'c', label: 'Alpha' }],
    'en',
  );
  assert.deepStrictEqual(sorted.map((x) => x.id), ['c', 'b', 'a2', 'a10']);
});
check('sortTemplates uses the id when there is no label and leaves its input alone', () => {
  const input = [{ id: 'zed' }, { id: 'abc', label: '' }];
  assert.deepStrictEqual(sortTemplates(input, 'en').map((x) => x.id), ['abc', 'zed']);
  assert.strictEqual(input[0].id, 'zed');
});

check('matchesQuery looks at label, id and description, ignoring case', () => {
  const template = { id: 'blog_list', label: 'Blog', description: 'Shows Recent posts' };
  assert.ok(matchesQuery(template, 'BLOG'));
  assert.ok(matchesQuery(template, '_list'));
  assert.ok(matchesQuery(template, 'recent'));
  assert.ok(!matchesQuery(template, 'news'));
});
check('matchesQuery matches everything for an empty query and handles missing fields', () => {
  assert.ok(matchesQuery({ id: 'x' }, ''));
  assert.ok(matchesQuery({ id: 'x' }, '   '));
  assert.ok(!matchesQuery({ id: 'x' }, 'y'));
});

check('t returns the source text and fills placeholders', () => {
  setStrings({}, 'en');
  assert.strictEqual(t('Actions for @name', { name: 'Blog' }), 'Actions for Blog');
});
check('t leaves a placeholder with no value alone', () => {
  assert.strictEqual(t('Hello @who'), 'Hello @who');
});
check('t uses the table and still fills placeholders', () => {
  setStrings({ 'Actions for @name': 'Actions pour @name' }, 'fr');
  assert.strictEqual(t('Actions for @name', { name: 'Blog' }), 'Actions pour Blog');
  assert.strictEqual(t('Not in the table'), 'Not in the table');
  setStrings({}, 'en');
});
check('summarize lists up to three names and counts the rest', () => {
  assert.strictEqual(list(['a', 'b']), 'a and b');
  assert.strictEqual(summarize(['a', 'b', 'c']), 'a, b, and c');
  assert.strictEqual(summarize(['a', 'b', 'c', 'd', 'e']), 'a, b, c, and 2 more');
});

check('t keeps what it is given as text: no HTML escaping, no markup', () => {
  setStrings({}, 'en');
  assert.strictEqual(t('Used by @views', { views: 'News & <b>Events</b>' }), 'Used by News & <b>Events</b>');
});

// The panel sets every name with textContent. innerHTML, and anything else that
// parses markup, is only for the two icons, which are constants.
check('the panel scripts parse markup only for their constant icons', () => {
  const dir = path.join(path.dirname(new URL(import.meta.url).pathname), '..', '..', 'extension');
  const sink = /innerHTML|outerHTML|insertAdjacentHTML|document\.write|createContextualFragment|\bsrcdoc\b|\beval\(|new Function/;
  const allowed = [/^\s*icon\.innerHTML = TEMPLATE_ICON;\s*$/, /^\s*dots\.innerHTML = DOTS_ICON;\s*$/];
  const found = [];
  for (const file of fs.readdirSync(dir).filter((name) => name.endsWith('.js'))) {
    fs.readFileSync(path.join(dir, file), 'utf8').split('\n').forEach((line, index) => {
      if (sink.test(line) && !allowed.some((pattern) => pattern.test(line))) {
        found.push(`${file}:${index + 1}: ${line.trim()}`);
      }
    });
  }
  assert.deepStrictEqual(found, [], 'New markup sink in the panel: ' + found.join('; '));
  const source = fs.readFileSync(path.join(dir, 'app.js'), 'utf8');
  const constants = source.match(/const (TEMPLATE_ICON|DOTS_ICON) =\s*([^;]*);/g) ?? [];
  assert.strictEqual(constants.length, 2, 'The two icon constants were not found.');
  assert.ok(constants.every((text) => !text.includes('${')), 'An icon constant is built from a value.');
});

console.log(results.join('\n'));
console.log(`${results.filter((r) => r.startsWith('PASS')).length} passed`);
