/* Registration settings (admin/competition.php). Nothing is written while the organiser types:
   the save button of the page (fixed at the bottom right) sends the whole form.
   - Every level is in the page. Choosing one shows its settings at once ([data-lvl]), so it can be
     set up completely before saving puts it live; at levels 2 and 3 its parts are tabs
     ([data-tab]) — display only, every field stays in the form. The tab is kept per level for the
     visit (sessionStorage).
   - On a closed competition, the payments box shows the tariffs (saved with it).
   - Level 2 has its own fee field: it follows the one of the tariffs, and back.
   The explanations ("?") are the frame's (AUTH/assets/admin.js). */
(function () {
  'use strict';
  var root = document.getElementById('bkadm');
  var form = document.getElementById('bk-cfg');
  if (!root || !form) return;
  var current = form.getAttribute('data-level');
  var wait = document.getElementById('bk-lvl-wait');
  var KEY = 'bk-tab-' + (window.BK_TOUR || 0) + '-';

  function store(k, v) {
    try { sessionStorage.setItem(k, v); } catch (e) { /* private window */ }
  }
  function read(k) {
    try { return sessionStorage.getItem(k); } catch (e) { return null; }
  }
  function each(sel, fn, from) { Array.prototype.forEach.call((from || root).querySelectorAll(sel), fn); }

  /* ---- Levels and tabs ---- */
  function chosen() {
    var r = form.querySelector('input[name="level"]:checked');
    return r ? r.value : current;
  }

  function show() {
    var l = chosen();
    var other = l !== current;
    each('.bk-lvl', function (c) {
      var r = c.querySelector('input');
      c.classList.toggle('chosen', !!r && r.checked && other);
    });
    if (wait) wait.hidden = !other;
    var bar = null;
    each('[data-tabs]', function (b) {
      b.hidden = b.getAttribute('data-tabs') !== l;
      if (!b.hidden) bar = b;
    });
    var tab = null;
    if (bar) {
      var tabs = bar.querySelectorAll('.bk-tab');
      tab = read(KEY + l);
      if (!tab || !bar.querySelector('.bk-tab[data-tab="' + tab + '"]')) tab = tabs.length ? tabs[0].getAttribute('data-tab') : null;
      Array.prototype.forEach.call(tabs, function (t) {
        var on = t.getAttribute('data-tab') === tab;
        t.classList.toggle('on', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    }
    var pay = document.getElementById('bk-payments');
    each('[data-lvl]', function (p) {
      var vis = p.getAttribute('data-lvl').split(' ').indexOf(l) >= 0;
      var t = p.getAttribute('data-tab');
      if (vis && t && bar) vis = t === tab;
      if (vis && t === 'tariffs' && l === '1') vis = !!pay && pay.checked;
      p.hidden = !vis;
    });
  }

  form.addEventListener('change', function (e) {
    var t = e.target;
    if (t.name === 'level' || t.id === 'bk-payments') show();
  });
  root.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('.bk-tab') : null;
    if (!t) return;
    store(KEY + chosen(), t.getAttribute('data-tab'));
    show();
  });

  /* ---- The fee of level 2 and the one of the tariffs: one amount ---- */
  form.addEventListener('input', function (e) {
    if (!e.target.hasAttribute('data-fee')) return;
    each('[data-fee]', function (f) { if (f !== e.target) f.value = e.target.value; }, form);
  });

  show();
})();
