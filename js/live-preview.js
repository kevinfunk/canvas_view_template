/**
 * Shows the live view content in the full-width preview only (Canvas's editor
 * frames carry `data-canvas-iframe`; the preview frame does not).
 *
 * Canvas blocks navigation and form submits in its preview, so filter and pager
 * links are caught first and the live block is re-rendered by the server.
 */
(function () {
  'use strict';
  var frame = window.frameElement;
  if (!frame || frame.hasAttribute('data-canvas-iframe')) {
    return;
  }
  var hint = document.querySelector('.cvt-preview-hint');
  if (hint) {
    hint.hidden = false;
  }
  var live = document.querySelector('.cvt-live-preview');
  if (!live) {
    return;
  }
  live.hidden = false;
  var edit = document.querySelector('.cvt-edit-preview');
  if (edit) {
    edit.hidden = true;
  }

  var endpoint = live.getAttribute('data-cvt-url');
  var busy = false;
  var queued = null;
  var notice = null;

  // Shows why the content didn't change; the content already shown stays.
  function showError(failure) {
    if (!notice) {
      notice = document.createElement('div');
      notice.className = 'cvt-preview-error';
      notice.setAttribute('role', 'alert');
      live.parentNode.insertBefore(notice, live);
    }
    var status = failure && failure.status ? failure.status : 0;
    var reason;
    var advice = Drupal.t('Check the view for errors, then try again.');
    if (failure && failure.unreadable) {
      reason = Drupal.t('The server sent something the preview could not read.');
    }
    else if (status === 403) {
      reason = Drupal.t('The server answered with error @status.', { '@status': status });
      advice = Drupal.t('Your session may have expired: reload the editor, then try again.');
    }
    else if (status) {
      reason = Drupal.t('The server answered with error @status.', { '@status': status });
    }
    else {
      reason = Drupal.t('The server could not be reached.');
    }
    notice.textContent = Drupal.t('The preview could not load this view.') + ' ' + reason + ' ' + advice;
    notice.hidden = false;
  }

  function clearError() {
    if (notice) {
      notice.hidden = true;
    }
  }

  // Whether a matching file is already loaded, ignoring any query string.
  function known(selector, attribute, url) {
    var bare = url.split('?')[0];
    return Array.prototype.some.call(document.querySelectorAll(selector), function (node) {
      return (node.getAttribute(attribute) || '').split('?')[0] === bare;
    });
  }

  function mergeSettings(target, source) {
    Object.keys(source).forEach(function (key) {
      var value = source[key];
      if (value && typeof value === 'object' && !Array.isArray(value)) {
        if (!target[key] || typeof target[key] !== 'object' || Array.isArray(target[key])) {
          target[key] = {};
        }
        mergeSettings(target[key], value);
      }
      else {
        target[key] = value;
      }
    });
  }

  // Before any new script runs, as Drupal's own AJAX does.
  function applySettings(settings) {
    if (!settings || typeof settings !== 'object') {
      return;
    }
    window.drupalSettings = window.drupalSettings || {};
    if (window.jQuery && jQuery.extend) {
      jQuery.extend(true, window.drupalSettings, settings);
    }
    else {
      mergeSettings(window.drupalSettings, settings);
    }
  }

  // Loads stylesheets and scripts the new content needs that the preview lacks.
  // Resolves once they are in, so the content never shows unstyled.
  function loadAssets(result) {
    var waits = [];
    (result.css || []).forEach(function (url) {
      if (known('link[rel="stylesheet"]', 'href', url)) {
        return;
      }
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = url;
      waits.push(new Promise(function (resolve) {
        link.onload = resolve;
        link.onerror = resolve;
      }));
      document.head.appendChild(link);
    });
    (result.js || []).forEach(function (url) {
      if (known('script[src]', 'src', url)) {
        return;
      }
      var script = document.createElement('script');
      script.src = url;
      script.async = false;
      waits.push(new Promise(function (resolve) {
        script.onload = resolve;
        script.onerror = resolve;
      }));
      document.head.appendChild(script);
    });
    return Promise.all(waits);
  }

  // The libraries the page has, so only new ones come back.
  function pageState(params) {
    var state = window.drupalSettings && drupalSettings.ajaxPageState;
    if (!state) {
      return;
    }
    ['libraries', 'theme', 'theme_token'].forEach(function (key) {
      if (state[key]) {
        params.append('ajax_page_state[' + key + ']', state[key]);
      }
    });
  }

  function parse(text) {
    var result;
    try {
      result = JSON.parse(text);
    }
    catch (error) {
      result = null;
    }
    return result && typeof result.html === 'string' ? result : Promise.reject({ unreadable: true });
  }

  // While a fetch runs, the latest request waits and the others are dropped.
  // Detach from the old content only. Canvas's own preview behaviors are
  // skipped: they unbind from `context.body`, which an element does not have,
  // and from the whole page, which is not being replaced.
  function detachFromLive(settings) {
    var behaviors = window.Drupal && Drupal.behaviors;
    if (!behaviors) {
      return;
    }
    Object.keys(behaviors).forEach(function (name) {
      var behavior = behaviors[name];
      if (name.indexOf('canvas') === 0 || !behavior || typeof behavior.detach !== 'function') {
        return;
      }
      try {
        behavior.detach(live, settings, 'unload');
      }
      catch (error) {
        Drupal.throwError(error);
      }
    });
  }

  // Drupal.attachBehaviors() on the new content only. Canvas's own behaviors
  // need `document` (they read `context.body`), so they get that.
  function attachToLive(settings) {
    var behaviors = window.Drupal && Drupal.behaviors;
    if (!behaviors) {
      return;
    }
    Object.keys(behaviors).forEach(function (name) {
      var behavior = behaviors[name];
      if (!behavior || typeof behavior.attach !== 'function') {
        return;
      }
      try {
        behavior.attach(name.indexOf('canvas') === 0 ? document : live, settings);
      }
      catch (error) {
        Drupal.throwError(error);
      }
    });
  }

  function attrSelector(name, value) {
    return '[' + name + '="' + String(value).replace(/["\\]/g, '\\$&') + '"]';
  }

  // What has focus inside the live content, as ways to find it again.
  function rememberFocus() {
    var active = document.activeElement;
    if (!active || active === live || !live.contains(active)) {
      return null;
    }
    var selectors = [];
    ['data-drupal-selector', 'id', 'name'].forEach(function (name) {
      var value = active.getAttribute(name);
      if (value) {
        selectors.push(active.tagName.toLowerCase() + attrSelector(name, value));
      }
    });
    var href = active.tagName === 'A' ? active.getAttribute('href') : null;
    if (href) {
      selectors.push('a' + attrSelector('href', href));
    }
    return selectors;
  }

  // The same control if it is still there, else the content itself, so
  // keyboard users do not start again from the top of the page.
  function restoreFocus(selectors) {
    if (!selectors) {
      return;
    }
    var target = null;
    selectors.some(function (selector) {
      target = live.querySelector(selector);
      return target;
    });
    if (!target) {
      live.setAttribute('tabindex', '-1');
      target = live;
    }
    target.focus({ preventScroll: true });
  }

  function load(query) {
    if (!endpoint) {
      return;
    }
    if (busy) {
      queued = query;
      return;
    }
    busy = true;
    live.setAttribute('aria-busy', 'true');
    var params = new URLSearchParams(query);
    pageState(params);
    var search = params.toString();
    fetch(endpoint + (search ? '?' + search : ''), { credentials: 'same-origin' })
      .then(function (response) {
        return response.ok ? response.text().then(parse) : Promise.reject(response);
      })
      .then(function (result) {
        applySettings(result.settings);
        return loadAssets(result).then(function () {
          var settings = window.drupalSettings;
          var focus = rememberFocus();
          detachFromLive(settings);
          live.innerHTML = result.html;
          clearError();
          attachToLive(settings);
          restoreFocus(focus);
          if (Drupal.announce) {
            Drupal.announce(Drupal.t('Results updated'));
          }
        });
      })
      .catch(showError)
      .then(function () {
        busy = false;
        live.removeAttribute('aria-busy');
        if (queued !== null) {
          var next = queued;
          queued = null;
          load(next);
        }
      });
  }

  function submitForm(form, submitter) {
    var data = new URLSearchParams();
    new FormData(form).forEach(function (value, key) {
      if (typeof value === 'string') {
        data.append(key, value);
      }
    });
    // FormData leaves out the pressed button, which tells Reset from Apply.
    if (submitter && submitter.name) {
      data.append(submitter.name, submitter.value);
    }
    load(data.toString());
  }

  function plainClick(event) {
    return event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
  }

  function submitButton(target) {
    var button = target && target.closest ? target.closest('button, input[type="submit"], input[type="image"]') : null;
    if (!button || !button.form || !live.contains(button.form)) {
      return null;
    }
    return button.type === 'submit' || button.type === 'image' ? button : null;
  }

  // Capture phase on `window`, ahead of Canvas's handlers and of AJAX Views,
  // which submit from the button's click (Enter in a field clicks it too).
  window.addEventListener('submit', function (event) {
    var form = event.target;
    if (!live.contains(form)) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    submitForm(form, event.submitter);
  }, true);

  window.addEventListener('keydown', function (event) {
    var button = event.key === 'Enter' ? submitButton(event.target) : null;
    if (!button) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    submitForm(button.form, button);
  }, true);

  // Only pager and sort links are re-rendered here: links in a pager or a
  // table header, or `?query` links. Other links, modified clicks and links
  // with a target are left to the browser and Canvas.
  window.addEventListener('click', function (event) {
    if (!plainClick(event)) {
      return;
    }
    var button = submitButton(event.target);
    if (button) {
      event.preventDefault();
      event.stopPropagation();
      submitForm(button.form, button);
      return;
    }
    var link = event.target.closest ? event.target.closest('a[href]') : null;
    if (!link || !live.contains(link)) {
      return;
    }
    var target = link.getAttribute('target');
    if (target && target !== '_self') {
      return;
    }
    var href = link.getAttribute('href') || '';
    var paging = link.closest('.pager, .js-pager__items, [class*="pager"], th, .views-table thead');
    if (href.charAt(0) !== '?' && !(paging && live.contains(paging))) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    var at = href.indexOf('?');
    load(at === -1 ? '' : href.slice(at + 1).split('#')[0]);
  }, true);
})();
