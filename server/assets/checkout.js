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

  // config answers the session's CSRF token (created once, never rotated); fetched once per page and shared.
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

  // ---- scroll regions: a [data-scroll-label] wrapper that overflows becomes a focusable, labelled region
  // so keyboard users can scroll it; when everything fits it stays a plain wrapper.
  function markScrollRegions() {
    document.querySelectorAll('[data-scroll-label]').forEach(function (el) {
      if (el.scrollWidth > el.clientWidth + 1) {
        el.setAttribute('tabindex', '0');
        el.setAttribute('role', 'region');
        el.setAttribute('aria-label', el.getAttribute('data-scroll-label'));
      } else {
        el.removeAttribute('tabindex');
        el.removeAttribute('role');
        el.removeAttribute('aria-label');
      }
    });
  }
  markScrollRegions();
  // Widths change after layout settles (webfonts, images) and with the viewport: re-check on each.
  window.addEventListener('resize', markScrollRegions);
  window.addEventListener('load', markScrollRegions);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(markScrollRegions);
  if (window.ResizeObserver) {
    var ro = new ResizeObserver(markScrollRegions);
    document.querySelectorAll('[data-scroll-label]').forEach(function (el) {
      ro.observe(el);
      if (el.firstElementChild) ro.observe(el.firstElementChild);
    });
  }

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
    var status = $(btn.getAttribute('data-copy-status') || 'copy-status');
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
    var plan = data.plan === 'ai' ? 'ai' : 'pro';   // 'ai' = Pro + AI monthly subscription, 'pro' = one-time
    var locked = false;                              // a payment may have gone through: the plan can no longer change
    var isBusy = false;                              // confirming an approved payment: the plan cannot change either
    var sdkSeq = 0;                                  // which SDK load is current (a stale load must not render)
    var slotLoading = slot ? slot.innerHTML : '';

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
      locked = true;
      var pick = $('plan-pick');
      if (pick) pick.setAttribute('aria-disabled', 'true');
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
        if (plan === 'ai') return;   // a subscription carries no sales-tax lines
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
      if (plan === 'pro' && country && country.value === 'US' && !region.value) return { msg: T.co_err_region, field: region };
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
      isBusy = on;
      show($('busy'), on);
      form.classList.toggle('is-busy', on);
      var pick = $('plan-pick');
      if (pick && !locked) { if (on) pick.setAttribute('aria-disabled', 'true'); else pick.removeAttribute('aria-disabled'); }
    }

    function success(r) {
      busy(false);
      show(form, false);
      if (!r.key) {   // not this session's order: the key went to the buyer's email only
        show(document.querySelector('#done .keybox'), false);
        show(document.querySelector('#done .linkbox'), false);
        fillWithLtr($('emailed'), T.co_key_emailed, '{email}', r.email_masked || '');
        show($('done'), true);
        $('done-title').focus();
        return;
      }
      $('key').textContent = r.key;
      $('account-link').setAttribute('href', '/palette/account?key=' + encodeURIComponent(r.key) + '&lang=' + encodeURIComponent(lang));
      fillWithLtr($('emailed'), r.email_masked ? T.co_emailed : '', '{email}', r.email_masked);
      show($('done'), true);
      $('done-title').focus();
      tellExtension(r.key);
    }

    // PayPal took the subscription but the first payment is not settled yet: the key comes by email.
    function pending() {
      busy(false);
      lock('');
      $('pending-text').textContent = T.co_sub_pending;
      show($('pending'), true);
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
      slot = null;   // gone: a later plan switch has nothing to reload
    }

    var STYLE = { layout: 'vertical', color: 'gold', shape: 'rect', height: 48 };

    function common(extra) {
      var o = {
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
        onCancel: function () { showError(''); },
        onError: function () {
          busy(false);
          showError(lastError || T.err_paypal);
          lastError = '';
        },
      };
      for (var k in extra) o[k] = extra[k];
      return o;
    }

    function proButtons(csrf) {
      return common({
        style: Object.assign({ label: 'pay' }, STYLE),
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
            // approved, but we never heard back, or PayPal holds the capture as PENDING (202): the key comes by email
            if (r.error === 'network' || r.error === 'co_err_confirm') lock(T.co_err_confirm);
            else if (r.status === 503) lock(r.message);        // err_retry: paid, key not ready yet
            else showError(r.message);
          });
        },
      });
    }

    function subButtons(csrf) {
      return common({
        style: Object.assign({ label: 'subscribe' }, STYLE),
        // subscribe_start answers our PayPal plan id and the custom_id that ties the subscription to this checkout
        createSubscription: function (_d, actions) {
          lastError = '';
          var body = { email: email.value.trim(), consent: consent.checked ? '1' : '', csrf: csrf };
          return api('subscribe_start', body).then(function (r) {
            if (!r.ok) { lastError = r.message; throw new Error(r.error || 'subscribe_start'); }
            return actions.subscription.create({ plan_id: r.plan_id, custom_id: r.custom_id });
          });
        },
        onApprove: function (d) {
          busy(true);
          showError('');
          return api('subscribe_confirm', { subscription_id: d.subscriptionID, csrf: csrf }).then(function (r) {
            if (r.ok && r.pending) { pending(); return; }
            if (r.ok) { success(r); return; }
            busy(false);
            // approved, but we could not confirm it (any error: 400, 419, 429, 502, network ...): never offer a second
            // subscription; the key comes by email
            if (r.status === 503 && r.error === 'err_retry') lock(r.message);   // paid, key not ready yet
            else lock(T.co_err_confirm_sub);
          });
        },
      });
    }

    function renderButtons(csrf) {
      slot.innerHTML = '';
      slot.removeAttribute('aria-busy');
      window.paypal.Buttons(plan === 'ai' ? subButtons(csrf) : proButtons(csrf))
        .render(slot).catch(function () { unavailable(T.co_load_failed); });
    }

    // (Re)loads the PayPal SDK for the current plan: capture for Pro, vault + subscription for Pro + AI. The old script
    // and its buttons are removed first; a load that finishes after another switch is ignored.
    function loadSdk() {
      if (!slot) return;
      var seq = ++sdkSeq;
      var old = $('paypal-sdk');
      if (old) old.parentNode.removeChild(old);
      try { delete window.paypal; } catch (e) { window.paypal = undefined; }
      buttons = null;
      show(slot, true);
      show($('pay-note'), true);
      slot.innerHTML = slotLoading;
      slot.setAttribute('aria-busy', 'true');
      slot.classList.add('is-locked');
      config().then(function (c) {
        if (seq !== sdkSeq) return;
        if (!c.ok) { unavailable(c.message); return; }
        if (!c.enabled || !c.client_id) { paused(); return; }
        var s = document.createElement('script');
        s.id = 'paypal-sdk';
        s.src = 'https://www.paypal.com/sdk/js?client-id=' + encodeURIComponent(c.client_id) + '&currency=USD'
          + (plan === 'ai' ? '&vault=true&intent=subscription' : '&intent=capture') + '&components=buttons';
        s.async = true;
        s.onload = function () {
          if (seq !== sdkSeq) return;
          if (window.paypal && window.paypal.Buttons) renderButtons(c.csrf);
          else unavailable(T.co_load_failed);
        };
        s.onerror = function () { if (seq === sdkSeq) unavailable(T.co_load_failed); };
        document.head.appendChild(s);
      });
    }

    // ---- plan cards (radio semantics): swap the summary, copy, URL and PayPal button for the chosen plan
    var cards = Array.prototype.slice.call(document.querySelectorAll('.plan-card'));

    function setText(id, text) { var el = $(id); if (el) el.textContent = text; }

    function applyPlan() {
      var ai = plan === 'ai';
      cards.forEach(function (c) {
        var on = c.getAttribute('data-plan') === plan;
        c.setAttribute('aria-checked', on ? 'true' : 'false');
        c.setAttribute('tabindex', on ? '0' : '-1');
        c.classList.toggle('is-on', on);
      });
      setText('sum-item', ai ? T.co_item_ai : T.co_item);
      setText('sum-price', ai ? data.total_ai : data.total);
      setText('refund', ai ? T.co_refund_ai : T.co_refund);
      setText('pay-note', ai ? T.co_note_ai : T.co_card_note);
      setText('done-title', ai ? T.co_success_title_ai : T.co_success_title);
      show($('tax-fields'), !ai);
      show($('sum-note'), !ai);
      if (ai) {
        setText('total', data.total_ai);
        show($('tax-row'), false);
      } else if (country) {
        syncTax();   // restores the Pro total and the California line
      } else {
        setText('total', data.total);
      }
      if (err.textContent === T.co_err_region) showError('');
    }

    // Keeps ?plan= in the address bar and in the language links, without adding a history entry.
    function syncUrl() {
      try {
        var u = new URL(location.href);
        u.searchParams.set('plan', plan);
        history.replaceState(null, '', u.pathname + u.search + u.hash);
        document.querySelectorAll('.lang-menu a').forEach(function (a) {
          var l = new URL(a.getAttribute('href'), location.href);
          l.searchParams.set('plan', plan);
          a.setAttribute('href', l.pathname + l.search);
        });
      } catch (e) { /* the address bar is a nicety */ }
    }

    function choose(id) {
      var c = cards.filter(function (x) { return x.getAttribute('data-plan') === id; })[0];
      if (!c || locked || isBusy || c.getAttribute('aria-disabled') === 'true' || id === plan) return false;
      plan = id;
      applyPlan();
      syncUrl();
      showError('');
      loadSdk();
      return true;
    }

    cards.forEach(function (c) {
      c.addEventListener('click', function () { choose(c.getAttribute('data-plan')); });
      c.addEventListener('keydown', function (e) {
        var rtl = document.documentElement.dir === 'rtl';
        var step = { ArrowRight: rtl ? -1 : 1, ArrowDown: 1, ArrowLeft: rtl ? 1 : -1, ArrowUp: -1 }[e.key];
        if (e.key === ' ' || e.key === 'Enter') {
          e.preventDefault();
          choose(c.getAttribute('data-plan'));
        } else if (step) {
          e.preventDefault();
          var next = cards[(cards.indexOf(c) + step + cards.length) % cards.length];
          if (choose(next.getAttribute('data-plan'))) next.focus();
        }
      });
    });

    applyPlan();
    loadSdk();
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

  // ---- account: cancel a subscription (confirm step is in the page, not window.confirm)
  function initCancel() {
    var csrf = data.csrf || '';

    function ended(row, endsOn) {
      var st = row.querySelector('[data-sub-status]');
      if (st) {
        fillWithLtr(st, T.acct_st_cancelled, '{date}', endsOn || '');
        st.className = 'sub-status is-cancelled';
      }
      var renews = row.querySelector('[data-sub-renews]');
      if (renews) renews.parentNode.removeChild(renews);
    }

    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-cancel-open], [data-cancel-keep], [data-cancel-yes]') : null;
      if (!t) return;
      var panel = t.closest('.sub-panel');
      var open = panel.querySelector('[data-cancel-open]');
      var confirmBox = panel.querySelector('.cancel-confirm');
      var msg = panel.querySelector('.sub-msg');
      if (t.hasAttribute('data-cancel-open')) {
        msg.textContent = '';
        show(open, false);
        show(confirmBox, true);
        panel.querySelector('[data-cancel-keep]').focus();   // the safe choice has focus
      } else if (t.hasAttribute('data-cancel-keep')) {
        show(confirmBox, false);
        show(open, true);
        open.focus();
      } else {
        var row = t.closest('.keyrow');
        var yes = t;
        yes.disabled = true;
        api('sub_cancel', { key: row.getAttribute('data-key'), csrf: csrf }).then(function (r) {
          yes.disabled = false;
          show(confirmBox, false);
          msg.textContent = r.message || '';
          msg.className = 'sub-msg ' + (r.ok ? 'is-ok' : 'is-error');
          if (r.ok) ended(row, r.ends_on); else { show(open, true); open.focus(); }
        });
      }
    });

    var form = $('cancel-by');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var key = $('cancel-key');
      var mail = $('cancel-email');
      var status = $('cancel-by-status');
      if (!key.value.trim()) { key.focus(); return; }
      if (!mail.value.trim() || !mail.checkValidity()) {
        status.textContent = T.err_email;
        status.className = 'claim-status is-error';
        mail.focus();
        return;
      }
      var btn = form.querySelector('button');
      btn.disabled = true;
      api('sub_cancel', { key: key.value.trim(), email: mail.value.trim(), csrf: csrf }).then(function (r) {
        btn.disabled = false;
        status.textContent = r.message || '';
        status.className = 'claim-status ' + (r.ok ? 'is-ok' : 'is-error');
      });
    });
  }

  initCheckout();
  initResend();
  initClaim();
  initCancel();
})();
