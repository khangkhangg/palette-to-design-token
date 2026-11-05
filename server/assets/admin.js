// Site to Design Token admin: the payment-reference hint on "Issue a key" and the copy button for a freshly issued key.
// Plain script (CSP: script-src 'self'); the page works without it.
(function () {
  'use strict';

  // ---- Issue a key: look a Stripe cs_/pi_ reference up (GET admin.php?tab=issue&lookup=, admin only, read-only)
  var ref = document.getElementById('ref');
  var hint = document.getElementById('ref-hint');
  if (ref && hint && ref.getAttribute('data-lookup')) {
    var idle = hint.textContent;
    var seq = 0;
    var timer = null;
    var RE = /^(cs|pi)_[A-Za-z0-9_]{1,77}$/;
    var say = function (text, cls) {
      hint.textContent = text;
      hint.className = 'hint' + (cls ? ' ' + cls : '');
    };
    var look = function () {
      var v = ref.value.trim();
      var mine = ++seq;
      if (!v) { say(idle); return; }
      if (!RE.test(v)) {
        say(/^(cs|pi)_/.test(v) ? 'That does not look like a Stripe reference.' : 'Not a Stripe reference: it is kept in the history only.');
        return;
      }
      say('Looking it up in Stripe...');
      fetch(ref.getAttribute('data-lookup') + encodeURIComponent(v), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          if (mine !== seq) return;
          if (!j) { say('Could not look it up. Try again in a moment.', 'is-bad'); return; }
          if (j.error === 'stripe') { say('Stripe did not answer. Try again in a moment.', 'is-bad'); return; }
          if (!j.found) { say('Not a palette payment. A new key is issued and the reference kept in the history.'); return; }
          var who = j.amount + ' ' + (j.paid ? 'paid' : 'not paid') + ' ' + j.date + ' by ' + j.email + '.';
          if (j.has_key) say('Found: ' + who + ' It already has a key; issuing shows that key again.', 'is-ok');
          else if (j.paid) say('Found in Stripe: ' + who + ' This links the key to that order.', 'is-ok');
          else say('Found: ' + who + ' Stripe has no payment for it, so a new key is issued by hand.', 'is-bad');
          var email = document.getElementById('em');
          if (email && !email.value && j.email) email.value = j.email;
          var lang = document.getElementById('lg');
          if (lang && j.lang) lang.value = j.lang;
        })
        .catch(function () { if (mine === seq) say('Could not look it up. Try again in a moment.', 'is-bad'); });
    };
    ref.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(look, 400);
    });
    if (ref.value.trim()) look();
  }

  // ---- copy buttons (data-copy-from = id of the element whose text is copied)
  Array.prototype.forEach.call(document.querySelectorAll('[data-copy-from]'), function (btn) {
    btn.addEventListener('click', function () {
      var src = document.getElementById(btn.getAttribute('data-copy-from'));
      var status = document.getElementById('copy-status');
      if (!src || !navigator.clipboard) return;
      navigator.clipboard.writeText(src.textContent.trim()).then(function () {
        if (status) status.textContent = 'Copied.';
      }, function () {
        if (status) status.textContent = 'Could not copy. Select the key and copy it.';
      });
    });
  });
})();
