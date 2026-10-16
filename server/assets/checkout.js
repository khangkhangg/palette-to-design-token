// Site to Design Token: checkout, account and shared page behaviour. Vanilla, no build.
// Strings come from the page (#palette-data); the API is /palette/api.php (JSON in, JSON out).
(function () {
  'use strict';

  var API = '/palette/api.php';
  var EXT_RE = /^[a-p]{32}$/;
  var dataEl = document.getElementById('palette-data');
  var data = dataEl ? JSON.parse(dataEl.textContent || '{}') : {};
  var T = data.t || {};
  var lang = data.lang || 'en';

  function $(id) { return document.getElementById(id); }

  function show(el, on) { if (el) el.hidden = !on; }

  // Sets el's text to template with placeholder replaced by value inside a left-to-right isolate (emails in RTL copy).
  function fillWithLtr(el, template, placeholder, value) {
    var parts = template.split(placeholder);
    el.textContent = parts[0];
    if (parts.length < 2) return;
    var bdi = document.createElement('bdi');
    bdi.dir = 'ltr';
    bdi.textContent = value;
    el.appendChild(bdi);
    el.appendChild(document.createTextNode(parts.slice(1).join(placeholder)));
  }

  // GET (no body) or POST JSON. Always resolves to {ok, status, message, ...body}.
  function api(action, body) {
    var init = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body) {
      init.method = 'POST';
      init.headers['Content-Type'] = 'application/json';
      body.lang = lang;
      init.body = JSON.stringify(body);
    }
    return fetch(API + '?action=' + encodeURIComponent(action), init).then(function (res) {
      return res.json().then(function (j) {
        if (!j || typeof j !== 'object') throw new Error('bad json');
        j.status = res.status;
        if (!j.ok && !j.message) j.message = T.err_paypal;
        return j;
      });
    }).catch(function () {
      return { ok: false, status: 0, error: 'network', message: T.co_err_network };
    });
  }

  // One CSRF token per page load: config rotates it, so it is fetched once and shared.
  var configPromise = null;
  function config() {
    if (!configPromise) {
      configPromise = api('config').then(function (c) {
        if (!c.ok) configPromise = null;
        return c;
      });
    }
    return configPromise;
  }

  // ---- language menu: close on outside click and on Escape
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details.lang[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.lang[open]').forEach(function (d) {
      d.removeAttribute('open');
      d.querySelector('summary').focus();
    });
  });

  // ---- copy buttons: [data-copy="text"] or [data-copy-from="element id"]
  function selectText(el) {
    var range = document.createRange();
    range.selectNodeContents(el);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-copy], [data-copy-from]') : null;
    if (!btn) return;
    var src = btn.hasAttribute('data-copy-from') ? $(btn.getAttribute('data-copy-from')) : null;
    var text = src ? src.textContent : btn.getAttribute('data-copy');
    var keyEl = src || btn.parentNode.querySelector('code');
    var status = $('copy-status');
    var label = btn.querySelector('span');
    var write = navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject(new Error('no clipboard'));
    write.then(function () {
      if (status) status.textContent = T.co_copied;
      label.textContent = T.co_copied;
      btn.classList.add('is-done');
      clearTimeout(btn._reset);
      btn._reset = setTimeout(function () { label.textContent = T.co_copy; btn.classList.remove('is-done'); }, 2000);
    }, function () {
      if (status) status.textContent = T.co_copy_failed;
      else btn.setAttribute('title', T.co_copy_failed);
      if (keyEl) selectText(keyEl);
    });
  });

  // ---- checkout
  function initCheckout() {
    var form = $('buy');
    if (!form) return;
    var email = $('email');
    var consent = $('consent');
    var err = $('buy-error');
    var slot = $('paypal');
    var country = $('country');
    var region = $('region');
    var lastError = '';
    var buttons = null;

    var invalid = null;   // the field currently marked aria-invalid

    // Shows msg (or clears it) and ties it to the field that caused it.
    function showError(msg, field) {
      if (invalid) {
        invalid.removeAttribute('aria-invalid');
        if (invalid.dataset.describedby) invalid.setAttribute('aria-describedby', invalid.dataset.describedby);
        else invalid.removeAttribute('aria-describedby');
        delete invalid.dataset.describedby;
        invalid = null;
      }
      err.textContent = msg || '';
      show(err, !!msg);
      if (msg && field) {
        var d = field.getAttribute('aria-describedby') || '';
        field.dataset.describedby = d;
        field.setAttribute('aria-describedby', (d ? d + ' ' : '') + 'buy-error');
        field.setAttribute('aria-invalid', 'true');
        invalid = field;
      }
    }

    // After a payment that may have gone through: no second button, nothing to edit, the message stays.
    function lock(msg) {
      show(slot, false);
      show(document.querySelector('.buy .hint-center'), false);
      Array.prototype.forEach.call(form.querySelectorAll('input, select'), function (el) { el.disabled = true; });
      showError(msg);
    }

    function caTax() {
      return !!(country && country.value === 'US' && region.value === 'CA');
    }

    if (country) {
      var syncTax = function () {
        var us = country.value === 'US';
        show($('region-wrap'), us);
        if (!us) region.value = '';
        $('total').textContent = caTax() ? data.total_ca : data.total;
        show($('tax-row'), caTax());
        if (err.textContent === T.co_err_region && region.value) showError('');
      };
      country.addEventListener('change', syncTax);
      region.addEventListener('change', syncTax);
      syncTax();
    }

    // The first thing stopping a purchase, with the field to focus; null when ready.
    function problem() {
      if (!email.value.trim() || !email.checkValidity()) return { msg: T.err_email, field: email };
      if (country && country.value === 'US' && !region.value) return { msg: T.co_err_region, field: region };
      if (!consent.checked) return { msg: T.err_consent, field: consent };
      return null;
    }

    // The consent checkbox gates the PayPal button.
    function gate() {
      var on = consent.checked;
      if (slot) slot.classList.toggle('is-locked', !on);
      if (buttons) { if (on) buttons.enable(); else buttons.disable(); }
    }
    consent.addEventListener('change', function () {
      gate();
      if (consent.checked && err.textContent === T.err_consent) showError('');
    });
    email.addEventListener('input', function () {
      if (err.textContent === T.err_email) showError('');
    });

    function busy(on) {
      show($('busy'), on);
      form.classList.toggle('is-busy', on);
    }

    function success(r) {
      busy(false);
      show(form, false);
      $('key').textContent = r.key;
      $('account-link').setAttribute('href', '/palette/account?key=' + encodeURIComponent(r.key) + '&lang=' + encodeURIComponent(lang));
      fillWithLtr($('emailed'), r.email_masked ? T.co_emailed : '', '{email}', r.email_masked);
      show($('done'), true);
      $('done-title').focus();
      tellExtension(r.key);
    }

    // Hands the key to the extension that opened this page (?ext=<id>, externally_connectable).
    function tellExtension(key) {
      var status = $('ext-status');
      var paste = function () { status.textContent = T.co_ext_paste; status.className = 'ext-status'; };
      var ext = new URLSearchParams(location.search).get('ext');
      var rt = window.chrome && window.chrome.runtime;
      if (!ext || !EXT_RE.test(ext) || !rt || typeof rt.sendMessage !== 'function') { paste(); return; }
      var settled = false;
      var timer = setTimeout(function () { if (!settled) { settled = true; paste(); } }, 4000);
      try {
        rt.sendMessage(ext, { type: 'license', key: key }, function (resp) {
          void rt.lastError;   // read it so Chrome does not log "unchecked runtime.lastError"
          if (settled) return;
          settled = true;
          clearTimeout(timer);
          if (resp && resp.ok === true) {
            status.textContent = T.co_ext_ok;
            status.className = 'ext-status is-ok';
          } else {
            paste();
          }
        });
      } catch (e) {
        settled = true;
        clearTimeout(timer);
        paste();
      }
    }

    // PayPal could not be reached or loaded: drop the placeholder and say so.
    function unavailable(msg) {
      show(slot, false);
      show(document.querySelector('.buy .hint-center'), false);
      showError(msg);
    }

    function paused() {
      show(document.querySelector('.buy .hint-center'), false);
      slot.outerHTML = '<div class="notice" role="status"></div>';
      document.querySelector('.buy .notice').textContent = T.err_paused;
    }

    function renderButtons(csrf) {
      slot.innerHTML = '';
      slot.removeAttribute('aria-busy');
      window.paypal.Buttons({
        style: { layout: 'vertical', color: 'gold', shape: 'rect', label: 'pay', height: 48 },
        onInit: function (_d, actions) {
          buttons = actions;
          gate();
        },
        onClick: function (_d, actions) {
          var p = problem();
          if (p) {
            showError(p.msg, p.field);
            p.field.focus();
            return actions.reject();
          }
          showError('');
          return actions.resolve();
        },
        createOrder: function () {
          lastError = '';
          var body = { email: email.value.trim(), consent: consent.checked ? '1' : '', csrf: csrf };
          if (country) { body.country = country.value; body.region = region.value; }
          return api('create', body).then(function (r) {
            if (!r.ok) { lastError = r.message; throw new Error(r.error || 'create'); }
            return r.order_id;
          });
        },
        onApprove: function (d) {
          busy(true);
          showError('');
          return api('capture', { order_id: d.orderID, csrf: csrf }).then(function (r) {
            if (r.ok) { success(r); return; }
            busy(false);
            if (r.error === 'network') lock(T.co_err_confirm);   // approved, but we never heard back
            else if (r.status === 503) lock(r.message);        // err_retry: paid, key not ready yet
            else showError(r.message);
          });
        },
        onCancel: function () { showError(''); },
        onError: function () {
          busy(false);
          showError(lastError || T.err_paypal);
          lastError = '';
        },
      }).render(slot).catch(function () { unavailable(T.co_load_failed); });
    }

    if (slot) {
      config().then(function (c) {
        if (!c.ok) { unavailable(c.message); return; }
        if (!c.enabled || !c.client_id) { paused(); return; }
        var s = document.createElement('script');
        s.src = 'https://www.paypal.com/sdk/js?client-id=' + encodeURIComponent(c.client_id) + '&currency=USD&intent=capture&components=buttons';
        s.async = true;
        s.onload = function () {
          if (window.paypal && window.paypal.Buttons) renderButtons(c.csrf);
          else unavailable(T.co_load_failed);
        };
        s.onerror = function () { unavailable(T.co_load_failed); };
        document.head.appendChild(s);
      });
    }
  }

  // ---- lost key: always the same confirmation
  function initResend() {
    var form = $('resend');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = $('resend-email');
      var status = $('resend-status');
      if (!input.value.trim() || !input.checkValidity()) {
        status.textContent = T.err_email;
        status.className = 'lost-status is-error';
        input.focus();
        return;
      }
      var btn = form.querySelector('button');
      btn.disabled = true;
      config().then(function (c) {
        return c.ok ? api('resend', { email: input.value.trim(), csrf: c.csrf }) : c;
      }).then(function (r) {
        btn.disabled = false;
        status.textContent = r.ok ? (r.message || T.api_resend_ok) : r.message;
        status.className = 'lost-status ' + (r.ok ? 'is-ok' : 'is-error');
      });
    });
  }

  // ---- account: add a key (claim)
  function initClaim() {
    var form = $('claim');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = $('claim-key');
      var status = $('claim-status');
      var btn = form.querySelector('button');
      if (!input.value.trim()) { input.focus(); return; }
      btn.disabled = true;
      config().then(function (c) {
        return c.ok ? api('claim', { key: input.value.trim(), csrf: c.csrf }) : c;
      }).then(function (r) {
        btn.disabled = false;
        status.textContent = r.ok ? T.acct_added : r.message;
        status.className = 'claim-status ' + (r.ok ? 'is-ok' : 'is-error');
        if (r.ok) setTimeout(function () { location.assign('/palette/account?lang=' + encodeURIComponent(lang)); }, 800);
      });
    });
  }

  initCheckout();
  initResend();
  initClaim();
})();
