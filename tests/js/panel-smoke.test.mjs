// Loads the whole panel (extension/app.js and its modules) against a stub page
// and checks that it lists the templates without reporting an error. Catches
// what `node --check` cannot: a missing import or an undefined name.
// No dependencies: node tests/js/panel-smoke.test.mjs
import assert from 'node:assert';

const errors = [];
const rows = [];
const nodes = {};
const stubNode = (id) => {
  const state = { hidden: false, children: [], value: '', textContent: '' };
  const node = new Proxy(function () {}, {
    get: (target, prop) => {
      if (prop === 'dataset') return {};
      if (prop === 'isConnected') return true;
      if (prop in state) return state[prop];
      if (prop === 'replaceChildren') return (...children) => { state.children = children; if (id === 'list') rows.splice(0, rows.length, ...children); };
      if (prop === 'classList') return { add() {}, remove() {} };
      return stubNode(`${id}.${String(prop)}`);
    },
    set: (target, prop, value) => {
      state[prop] = value;
      if (id === 'error' && prop === 'textContent' && value) errors.push(value);
      return true;
    },
    apply: () => stubNode(`${id}()`),
  });
  return node;
};

globalThis.window = {
  location: { pathname: '/modules/custom/x' },
  addEventListener() {},
  frameElement: null,
};
globalThis.window.parent = globalThis.window;
globalThis.document = {
  documentElement: { lang: 'en' },
  title: '',
  hidden: false,
  getElementById: (id) => (nodes[id] ??= stubNode(id)),
  createElement: (tag) => stubNode(`<${tag}>`),
  addEventListener() {},
  querySelectorAll: () => [],
  body: stubNode('body'),
  head: stubNode('head'),
};
globalThis.window.parent.document = globalThis.document;

const json = (body) => ({ ok: true, status: 200, json: async () => body, text: async () => 'token' });
globalThis.fetch = async (url) => {
  if (url.endsWith('/panel-strings')) return json({ langcode: 'en', dir: 'ltr', strings: {} });
  if (url.endsWith('/canvas-view-template/usage')) return json({ templates: { beta: [{ label: 'Blog: Page' }] } });
  return json({
    beta: { id: 'beta', label: 'Beta' },
    alpha: { id: 'alpha', label: 'Alpha', description: 'First' },
  });
};

await import('../../extension/app.js');
await new Promise((resolve) => setTimeout(resolve, 200));

try {
  assert.deepStrictEqual(errors, [], 'The panel reported an error.');
  assert.strictEqual(rows.length, 2, 'The panel did not list both templates.');
  console.log('PASS the panel loads and lists two templates');
}
catch (e) {
  console.log('FAIL ' + e.message);
  process.exitCode = 1;
}
