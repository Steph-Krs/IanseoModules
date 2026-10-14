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

/* Categories open to online registration (Restrictions, level 3), one grid per departure. The
   mouse paints: the first cell pressed decides (open or closed), every cell passed over while the
   button is down takes the same; a header switches its whole row or column, the two buttons every
   cell. A tab per departure; "take from" / "copy to" copy a grid onto another, in the page. The
   closed ones go in the hidden field of each departure, saved with the form (a change event: the
   save button lights up). */
(function () {
  'use strict';
  var box = document.getElementById('bk-cats');
  if (!box) return;
  var T = window.BK_T || {};
  var KEY = 'bk-cat-ses-' + (window.BK_TOUR || 0);
  var panes = Array.prototype.slice.call(box.querySelectorAll('.bk-cat-pane'));
  var paint = null;

  function cellsOf(p) { return Array.prototype.slice.call(p.querySelectorAll('td.bk-cat')); }
  function paneOf(ses) { return panes.filter(function (p) { return p.getAttribute('data-ses') === String(ses); })[0]; }
  function sync(p) {
    var closed = [], on = 0;
    cellsOf(p).forEach(function (c) { if (c.classList.contains('on')) on++; else closed.push(c.getAttribute('data-c')); });
    var input = p.querySelector('input[data-forbid]');
    input.value = closed.join(',');
    p.querySelector('.bk-cat-on').textContent = on;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }
  function set(p, list, open) { list.forEach(function (c) { c.classList.toggle('on', open); }); sync(p); }

  function show(ses) {
    if (!paneOf(ses)) ses = panes[0].getAttribute('data-ses');
    panes.forEach(function (p) { p.hidden = p.getAttribute('data-ses') !== String(ses); });
    Array.prototype.forEach.call(box.querySelectorAll('.bk-cat-tab'), function (t) {
      var on = t.getAttribute('data-ses') === String(ses);
      t.classList.toggle('on', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    try { localStorage.setItem(KEY, ses); } catch (e) { /* storage off: the first departure next time */ }
  }
  if (panes.length > 1) {
    var kept = null;
    try { kept = localStorage.getItem(KEY); } catch (e) { kept = null; }
    if (kept) show(kept);
  }

  function say(p, text, ko) {
    var m = p.querySelector('.bk-cat-msg');
    if (!m) return;
    m.textContent = text;
    m.classList.toggle('ko', !!ko);
  }
  // The grid of one departure onto another: every cell takes the state of the source one.
  function copy(p, way) {
    var other = p.querySelector('select[data-copy-sel="' + way + '"]').value;
    if (!other) { say(p, way === 'from' ? T.pickSrc : T.pickDest, true); return; }
    var src = way === 'from' ? paneOf(other) : p, dst = way === 'from' ? p : paneOf(other);
    if (!src || !dst) return;
    var open = {};
    cellsOf(src).forEach(function (c) { open[c.getAttribute('data-c')] = c.classList.contains('on'); });
    cellsOf(dst).forEach(function (c) { c.classList.toggle('on', !!open[c.getAttribute('data-c')]); });
    sync(dst);
    say(p, String(way === 'from' ? T.catsFrom : T.catsTo).replace('%s', other));
  }

  box.addEventListener('mousedown', function (e) {
    var c = e.target.closest('td.bk-cat');
    if (!c || e.button !== 0) return;
    e.preventDefault();
    paint = !c.classList.contains('on');
    set(c.closest('.bk-cat-pane'), [c], paint);
  });
  box.addEventListener('mouseover', function (e) {
    if (paint === null) return;
    var c = e.target.closest('td.bk-cat');
    if (c && c.classList.contains('on') !== paint) set(c.closest('.bk-cat-pane'), [c], paint);
  });
  document.addEventListener('mouseup', function () { paint = null; });

  box.addEventListener('click', function (e) {
    var t = e.target.closest('.bk-cat-tab');
    if (t) { show(t.getAttribute('data-ses')); return; }
    var p = e.target.closest('.bk-cat-pane');
    if (!p) return;
    var a = e.target.closest('[data-copy]');
    if (a) { copy(p, a.getAttribute('data-copy')); return; }
    var b = e.target.closest('[data-cats], .bk-cat-h');
    if (!b) return;
    var cells = cellsOf(p);
    if (b.hasAttribute('data-cats')) { set(p, cells, b.getAttribute('data-cats') === '1'); return; }
    var row = b.getAttribute('data-row'), col = b.getAttribute('data-col');
    var list = cells.filter(function (c) { return row ? c.getAttribute('data-row') === row : c.getAttribute('data-col') === col; });
    set(p, list, !list.every(function (c) { return c.classList.contains('on'); }));
  });
})();
