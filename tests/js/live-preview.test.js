// Runs js/live-preview.js against a small DOM stub. No dependencies:
// node tests/js/live-preview.test.js
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert');
const src = fs.readFileSync(path.join(__dirname, '../../js/live-preview.js'), 'utf8');

function el(tag, attrs, parent) {
  const node = {
    tagName: tag.toUpperCase(), attrs: Object.assign({}, attrs || {}), children: [], parentNode: parent || null,
    hidden: false, classes: (attrs && attrs.class || '').split(' ').filter(Boolean),
    getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; },
    setAttribute(n, v) { this.attrs[n] = String(v); },
    removeAttribute(n) { delete this.attrs[n]; },
    hasAttribute(n) { return n in this.attrs; },
    contains(o) { for (let x = o; x; x = x.parentNode) { if (x === this) return true; } return false; },
    insertBefore(n) { n.parentNode = this; this.children.push(n); },
    appendChild(n) { n.parentNode = this; this.children.push(n); return n; },
    matches(sel) { return sel.split(',').some((s) => matchOne(this, s.trim())); },
    closest(sel) { for (let x = this; x && x.tagName; x = x.parentNode) { if (x.matches(sel)) return x; } return null; },
  };
  if (parent) parent.children.push(node);
  return node;
}
// Enough selector support for the script: tag, .class, [class*=], [type=], th, ".views-table thead".
function matchOne(node, s) {
  const parts = s.split(/\s+/);
  if (parts.length > 1) {
    if (!matchOne(node, parts[parts.length - 1])) return false;
    for (let x = node.parentNode; x && x.tagName; x = x.parentNode) { if (matchOne(x, parts.slice(0, -1).join(' '))) return true; }
    return false;
  }
  let m;
  if ((m = s.match(/^\[class\*="(.+)"\]$/))) return node.classes.some((c) => c.includes(m[1]));
  if ((m = s.match(/^\.(.+)$/))) return node.classes.includes(m[1]);
  if ((m = s.match(/^(\w+)\[(\w+)="?([^"\]]+)"?\]$/))) return node.tagName === m[1].toUpperCase() && node.attrs[m[2]] === m[3];
  if ((m = s.match(/^(\w+)\[(\w+)\]$/))) return node.tagName === m[1].toUpperCase() && m[2] in node.attrs;
  if (/^\w+$/.test(s)) return node.tagName === s.toUpperCase();
  return false;
}

function setup(options) {
  const listeners = {};
  const log = [];
  const head = el('head');
  const body = el('body');
  head.appendChild(el('link', { rel: 'stylesheet', href: '/core/misc/known.css?x' }));
  head.appendChild(el('script', { src: '/core/misc/known.js' }));
  const live = el('div', { class: 'cvt-live-preview', 'data-cvt-url': '/lp/t' }, body);
  const pager = el('ul', { class: 'pager' }, live);
  const pagerLink = el('a', { href: '?page=1' }, pager);
  const pathPagerLink = el('a', { href: '/foo?page=2' }, pager);
  const plainLink = el('a', { href: '/node/1' }, live);
  const queryLink = el('a', { href: '?sort=title' }, live);
  const otherQuery = el('a', { href: '/node/1?x=1' }, live);
  const blank = el('a', { href: '?page=3', target: '_blank' }, live);
  const form = el('form', {}, live);
  const apply = el('input', { type: 'submit', name: 'op', value: 'Apply' }, form);
  apply.type = 'submit'; apply.name = ''; apply.value = 'Apply'; apply.form = form;
  const reset = el('input', { type: 'submit', name: 'op', value: 'Reset' }, form);
  reset.type = 'submit'; reset.name = 'op'; reset.value = 'Reset'; reset.form = form;
  const text = el('input', { type: 'text' }, form);
  text.type = 'text'; text.form = form;
  form.fields = [['title', 'abc']];

  const fetches = [];
  const document = {
    head, body,
    querySelector(sel) { return sel === '.cvt-live-preview' ? live : null; },
    querySelectorAll(sel) { return (sel.startsWith('link') ? head.children.filter((c) => c.tagName === 'LINK') : head.children.filter((c) => c.tagName === 'SCRIPT')); },
    createElement(tag) { return el(tag); },
  };
  let html = '';
  Object.defineProperty(live, 'innerHTML', { get() { return html; }, set(v) { log.push('html'); html = v; } });
  const drupalSettings = { ajaxPageState: { libraries: 'COMPRESSED', theme: 'olivero', theme_token: 'tok' }, deep: { a: 1 } };
  const window = {
    frameElement: el('iframe'),
    addEventListener(type, fn, capture) { assert.strictEqual(capture, true); listeners[type] = fn; },
    drupalSettings,
    Drupal: {
      t: (s, a) => Object.keys(a || {}).reduce((r, k) => r.replace(k, a[k]), s),
      // Behaviors are attached one by one, so the whole page is not.
      attachBehaviors() { throw new Error('whole-page attach must not be used'); },
      announce(message) { log.push(['announce', message]); },
      throwError(e) { throw e; },
      behaviors: {
        ordinary: {
          attach(ctx, settings) { log.push(['attach', ctx === live, JSON.stringify(settings.deep), settings.ajaxPageState.libraries]); },
          detach(ctx, settings, trigger) { log.push(['detach', ctx === live, trigger]); },
        },
        // Canvas's behavior reads `context.body`: detached never, attached to `document`.
        canvasDisableLinks: {
          attach(ctx) { log.push(['attach-canvas', ctx === document]); },
          detach() { throw new Error('canvas behavior must not be detached'); },
        },
      },
    },
  };
  function fetch(url) {
    let resolve;
    const p = new Promise((r) => { resolve = r; });
    fetches.push({ url, resolve });
    return p;
  }
  class FormData { constructor(f) { this.f = f; } forEach(cb) { this.f.fields.forEach(([k, v]) => cb(v, k)); } }
  // Appended assets "load" at once.
  const origAppend = head.appendChild.bind(head);
  head.appendChild = (n) => { origAppend(n); log.push(['asset', n.href || n.src]); setTimeout(() => n.onload && n.onload(), 0); return n; };
  new Function('window', 'document', 'fetch', 'FormData', 'Drupal', 'drupalSettings', 'jQuery', 'URLSearchParams', src)(
    window, document, fetch, FormData, window.Drupal, drupalSettings, options && options.jquery, URLSearchParams);
  live.focus = (options) => log.push(['focus', live, options && options.preventScroll]);
  // What `live.querySelector()` finds after the content is replaced.
  const found = {};
  live.querySelector = (selector) => found[selector] || null;
  const notice = () => live.parentNode.children.find((c) => c.className === 'cvt-preview-error');
  function event(target, extra) {
    const e = Object.assign({ target, button: 0, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, prevented: false, stopped: false,
      preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; } }, extra || {});
    return e;
  }
  return { document, found, listeners, log, fetches, live, pagerLink, pathPagerLink, plainLink, queryLink, otherQuery, blank, form, apply, reset, text, event, notice, window };
}

function respond(f, body, status) {
  f.resolve({ ok: (status || 200) < 300, status: status || 200, text: () => Promise.resolve(typeof body === 'string' ? body : JSON.stringify(body)) });
}
const tick = () => new Promise((r) => setTimeout(r, 5));
const results = [];
async function check(name, fn) {
  try { await fn(); results.push('PASS ' + name); }
  catch (e) { results.push('FAIL ' + name + ': ' + e.message); process.exitCode = 1; }
}

(async () => {
  await check('page state is sent with the query', async () => {
    const s = setup();
    s.listeners.click(s.event(s.pagerLink));
    const url = decodeURIComponent(s.fetches[0].url);
    assert.ok(url.startsWith('/lp/t?page=1&'), url);
    assert.ok(url.includes('ajax_page_state[libraries]=COMPRESSED') && url.includes('ajax_page_state[theme]=olivero') && url.includes('ajax_page_state[theme_token]=tok'), url);
  });

  await check('known assets not added, new ones added; settings merged before attach; detach on live before html', async () => {
    const s = setup();
    s.listeners.click(s.event(s.pagerLink));
    respond(s.fetches[0], { html: '<p>x</p>', css: ['/core/misc/known.css', '/new.css'], js: ['/core/misc/known.js', '/new.js'], settings: { deep: { b: 2 }, ajaxPageState: { libraries: 'NEW' } } });
    await tick(); await tick();
    const assets = s.log.filter((l) => l[0] === 'asset').map((l) => l[1]);
    assert.deepStrictEqual(assets, ['/new.css', '/new.js']);
    const attach = s.log.find((l) => l[0] === 'attach');
    assert.deepStrictEqual(attach, ['attach', true, '{"a":1,"b":2}', 'NEW']);
    assert.ok(s.log.some((l) => l[0] === 'attach-canvas' && l[1] === true), 'Canvas behaviors attach to document');
    assert.ok(s.log.some((l) => l[0] === 'announce' && l[1] === 'Results updated'));
    const iDetach = s.log.findIndex((l) => l[0] === 'detach');
    assert.deepStrictEqual(s.log[iDetach], ['detach', true, 'unload']);
    assert.ok(iDetach < s.log.indexOf('html') && s.log.indexOf('html') < s.log.indexOf(attach));
    assert.strictEqual(s.live.innerHTML, '<p>x</p>');
    assert.strictEqual(s.live.getAttribute('aria-busy'), null);
  });

  await check('focus returns to the same link after the content is replaced', async () => {
    const s = setup();
    const fresh = el('a', { href: '?page=1' });
    fresh.focus = (o) => s.log.push(['focus', fresh, o && o.preventScroll]);
    s.found['a[href="?page=1"]'] = fresh;
    s.document.activeElement = s.pagerLink;
    s.listeners.click(s.event(s.pagerLink));
    respond(s.fetches[0], { html: 'new' });
    await tick(); await tick();
    const focused = s.log.filter((l) => l[0] === 'focus');
    assert.deepStrictEqual(focused.map((l) => [l[1] === fresh, l[2]]), [[true, true]]);
    assert.strictEqual(s.live.getAttribute('tabindex'), null);
  });

  await check('focus falls back to the content when the control is gone', async () => {
    const s = setup();
    s.document.activeElement = s.text;
    s.listeners.click(s.event(s.pagerLink));
    respond(s.fetches[0], { html: 'new' });
    await tick(); await tick();
    assert.deepStrictEqual(s.log.filter((l) => l[0] === 'focus').map((l) => l[1] === s.live), [true]);
    assert.strictEqual(s.live.getAttribute('tabindex'), '-1');
  });

  await check('focus outside the content is left alone', async () => {
    const s = setup();
    s.document.activeElement = el('button');
    s.listeners.click(s.event(s.pagerLink));
    respond(s.fetches[0], { html: 'new' });
    await tick(); await tick();
    assert.strictEqual(s.log.filter((l) => l[0] === 'focus').length, 0);
  });

  await check('field selectors prefer data-drupal-selector, id and name', async () => {
    const s = setup();
    s.text.attrs['data-drupal-selector'] = 'edit-title';
    s.text.attrs.name = 'title';
    const fresh = el('input');
    fresh.focus = () => s.log.push(['focus', fresh]);
    s.found['input[name="title"]'] = fresh;
    s.document.activeElement = s.text;
    s.listeners.click(s.event(s.pagerLink));
    respond(s.fetches[0], { html: 'new' });
    await tick(); await tick();
    assert.ok(s.log.some((l) => l[0] === 'focus' && l[1] === fresh));
  });

  await check('jQuery.extend is used when present', async () => {
    let used = false;
    const jq = { extend(deep, t, src2) { used = deep === true; Object.assign(t, src2); return t; } };
    const s = setup({ jquery: jq });
    s.window.jQuery = jq;
    s.listeners.click(s.event(s.pagerLink));
    respond(s.fetches[0], { html: '', settings: { z: 1 } });
    await tick();
    assert.ok(used);
  });

  await check('ordinary links not intercepted', async () => {
    const s = setup();
    for (const link of [s.plainLink, s.otherQuery]) {
      const e = s.event(link);
      s.listeners.click(e);
      assert.ok(!e.prevented && !e.stopped, link.attrs.href);
    }
    assert.strictEqual(s.fetches.length, 0);
  });

  await check('pager, path pager and ?query links intercepted', async () => {
    const s = setup();
    const e = s.event(s.pathPagerLink);
    s.listeners.click(e);
    assert.ok(e.prevented);
    assert.ok(s.fetches[0].url.startsWith('/lp/t?page=2&'));
    const e2 = s.event(s.queryLink);
    s.listeners.click(e2);
    assert.ok(e2.prevented);
  });

  await check('modified clicks and target links ignored', async () => {
    const s = setup();
    for (const extra of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
      const e = s.event(s.pagerLink, extra);
      s.listeners.click(e);
      assert.ok(!e.prevented, JSON.stringify(extra));
    }
    const e = s.event(s.blank);
    s.listeners.click(e);
    assert.ok(!e.prevented);
    assert.strictEqual(s.fetches.length, 0);
  });

  await check('Reset submitter included; nameless Apply not', async () => {
    const s = setup();
    s.listeners.submit(s.event(s.form, { submitter: s.reset }));
    assert.ok(s.fetches[0].url.includes('title=abc&op=Reset'), s.fetches[0].url);
    respond(s.fetches[0], { html: '' });
    await tick();
    s.listeners.submit(s.event(s.form, { submitter: s.apply }));
    assert.ok(!s.fetches[1].url.includes('op='), s.fetches[1].url);
  });

  await check('AJAX views: submit button click and Enter on button go through the live fetch', async () => {
    const s = setup();
    const e = s.event(s.reset);
    s.listeners.click(e);
    assert.ok(e.prevented && e.stopped);
    assert.ok(s.fetches[0].url.includes('op=Reset'));
    respond(s.fetches[0], { html: '' });
    await tick();
    const k = s.event(s.apply, { key: 'Enter' });
    s.listeners.keydown(k);
    assert.ok(k.prevented);
    assert.strictEqual(s.fetches.length, 2);
    const k2 = s.event(s.text, { key: 'Enter' });
    s.listeners.keydown(k2);
    assert.ok(!k2.prevented, 'Enter in a field is left to implicit submission (a click on the button).');
  });

  await check('latest queued request runs after the current one', async () => {
    const s = setup();
    s.listeners.click(s.event(s.pagerLink));
    s.listeners.click(s.event(s.pathPagerLink));
    const last = s.event(s.queryLink);
    s.listeners.click(last);
    assert.ok(last.prevented);
    assert.strictEqual(s.fetches.length, 1);
    assert.strictEqual(s.live.getAttribute('aria-busy'), 'true');
    respond(s.fetches[0], { html: 'one' });
    await tick(); await tick();
    assert.strictEqual(s.fetches.length, 2);
    assert.ok(s.fetches[1].url.startsWith('/lp/t?sort=title&'), s.fetches[1].url);
    respond(s.fetches[1], { html: 'two' });
    await tick(); await tick();
    assert.strictEqual(s.fetches.length, 2);
    assert.strictEqual(s.live.innerHTML, 'two');
  });

  const cases = [
    ['non-JSON 200', (f) => respond(f, '<html>Log in</html>'), 'The server sent something the preview could not read.'],
    ['JSON without html', (f) => respond(f, { css: [] }), 'The server sent something the preview could not read.'],
    ['403', (f) => respond(f, '', 403), 'Your session may have expired: reload the editor'],
    ['500', (f) => respond(f, '', 500), 'The server answered with error 500. Check the view for errors, then try again.'],
    ['network', (f) => f.resolve(Promise.reject(new TypeError('Failed to fetch'))), 'The server could not be reached. Check the view'],
  ];
  for (const [name, act, text] of cases) {
    await check('error: ' + name, async () => {
      const s = setup();
      s.live.innerHTML = 'old';
      s.listeners.click(s.event(s.pagerLink));
      act(s.fetches[0]);
      await tick(); await tick();
      const n = s.notice();
      assert.ok(n && n.textContent.includes(text), n && n.textContent);
      assert.ok(n.textContent.startsWith('The preview could not load this view.'));
      assert.strictEqual(s.live.innerHTML, 'old');
    });
  }

  console.log(results.join('\n'));
  const failed = results.filter((r) => r.startsWith('FAIL')).length;
  console.log(`${results.length - failed} passed, ${failed} failed`);
})();
