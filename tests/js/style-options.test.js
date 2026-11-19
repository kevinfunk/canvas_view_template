// Runs js/style-options.js against a small DOM stub. No dependencies:
// node tests/js/style-options.test.js
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert');
const src = fs.readFileSync(path.join(__dirname, '../../js/style-options.js'), 'utf8');

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

// A select, the wrapper holding the edit link, and the link.
function setup(urls, options) {
  const link = { href: '' };
  const wrapper = { hidden: false, querySelector: (sel) => (sel === 'a' ? (options?.noLink ? null : link) : null) };
  const form = { querySelector: (sel) => (sel === '[data-cvt-edit-wrapper]' && !options?.noWrapper ? wrapper : null) };
  const listeners = {};
  const select = {
    value: options?.value ?? '',
    closest: (sel) => (sel === 'form' ? form : null),
    addEventListener: (type, fn) => { listeners[type] = fn; },
  };
  const behaviors = {};
  const seen = new Set();
  const once = (id, sel, context) => {
    assert.strictEqual(sel, '[data-cvt-template-select]');
    const fresh = context.selects.filter((s) => !seen.has(id + context.selects.indexOf(s)));
    fresh.forEach((s) => seen.add(id + context.selects.indexOf(s)));
    return fresh;
  };
  const drupalSettings = urls === undefined ? {} : { canvasViewTemplate: { editUrls: urls } };
  new Function('Drupal', 'once', 'drupalSettings', src)({ behaviors }, once, drupalSettings);
  const context = { selects: [select] };
  return { behaviors, context, select, listeners, link, wrapper };
}

check('registers the behavior', () => {
  const s = setup({});
  assert.strictEqual(typeof s.behaviors.canvasViewTemplateStyleOptions.attach, 'function');
});

check('shows the link for a selected template', () => {
  const s = setup({ a: '/edit/a' }, { value: 'a' });
  s.behaviors.canvasViewTemplateStyleOptions.attach(s.context);
  assert.strictEqual(s.wrapper.hidden, false);
  assert.strictEqual(s.link.href, '/edit/a');
});

check('hides the link when nothing or an unknown value is selected', () => {
  const s = setup({ a: '/edit/a' }, { value: '' });
  s.behaviors.canvasViewTemplateStyleOptions.attach(s.context);
  assert.strictEqual(s.wrapper.hidden, true);
  assert.strictEqual(s.link.href, '');
});

check('follows the select on change', () => {
  const s = setup({ a: '/edit/a', b: '/edit/b' }, { value: 'a' });
  s.behaviors.canvasViewTemplateStyleOptions.attach(s.context);
  s.select.value = 'b';
  s.listeners.change();
  assert.strictEqual(s.link.href, '/edit/b');
  s.select.value = '';
  s.listeners.change();
  assert.strictEqual(s.wrapper.hidden, true);
});

check('attaching twice binds once', () => {
  const s = setup({ a: '/edit/a' }, { value: 'a' });
  const attach = s.behaviors.canvasViewTemplateStyleOptions.attach;
  let binds = 0;
  s.select.addEventListener = () => { binds++; };
  attach(s.context);
  attach(s.context);
  assert.strictEqual(binds, 1);
});

check('missing settings, wrapper or link do not throw', () => {
  for (const [urls, options] of [[undefined, {}], [{ a: '/x' }, { noWrapper: true }], [{ a: '/x' }, { noLink: true }]]) {
    const s = setup(urls, options);
    s.behaviors.canvasViewTemplateStyleOptions.attach(s.context);
  }
});

console.log(results.join('\n'));
const failed = results.filter((r) => r.startsWith('FAIL')).length;
console.log(`${results.length - failed} passed, ${failed} failed`);
