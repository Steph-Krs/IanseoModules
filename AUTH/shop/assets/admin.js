/* Organiser pages of the points of sale: adding, removing, moving and duplicating lines of a
   form. The forms work as plain forms; this only saves round trips. Texts come from the page
   (data-confirm attributes), none is written here. */
(function () {
  'use strict';

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $all(sel, ctx) { return [].slice.call((ctx || document).querySelectorAll(sel)); }

  /* A fresh index for a new line, taken from the counter of its container. */
  function nextIndex(container) {
    var n = parseInt(container.getAttribute('data-next') || '0', 10) || 0;
    container.setAttribute('data-next', String(n + 1));
    return 'n' + n;
  }

  /* Content of a <template> as one element (a table row travels inside a table). */
  function fromTemplate(id, replacements) {
    var tpl = document.getElementById(id);
    if (!tpl) return null;
    var html = tpl.innerHTML;
    Object.keys(replacements).forEach(function (k) { html = html.split(k).join(replacements[k]); });
    var holder = document.createElement('div');
    holder.innerHTML = html.trim();
    var el = holder.firstElementChild;
    if (el && el.tagName === 'TABLE') el = el.querySelector('tr');
    return el;
  }

  /* First letter A-Z not used by the points of sale already listed. */
  function freeLetter() {
    var used = {};
    $all('[data-letter]').forEach(function (i) { used[i.value.toUpperCase()] = true; });
    for (var c = 65; c < 91; c++) { var l = String.fromCharCode(c); if (!used[l]) return l; }
    return '';
  }

  function addLine(btn) {
    var container = document.getElementById(btn.getAttribute('data-into'));
    if (!container) return;
    var el = fromTemplate(btn.getAttribute('data-add'), { '__i__': nextIndex(container) });
    if (!el) return;
    container.appendChild(el);
    var letter = $('[data-letter]', el);
    if (letter && !letter.value) letter.value = freeLetter();
    var first = $('input[type=text]:not([readonly])', el);
    if (first) first.focus();
  }

  function addVariant(btn) {
    var card = btn.closest('[data-prod]');
    var list = $('[data-var-list]', card);
    var m = /^p\[([^\]]+)\]/.exec($('input[name]', card).getAttribute('name'));
    if (!m || !list) return;
    var el = fromTemplate('var-tpl', { '__i__': m[1], '__v__': nextIndex(list) });
    if (!el) return;
    list.appendChild(el);
    var first = $('input[type=text]', el);
    if (first) first.focus();
  }

  /* Duplicate a product card: same fields, new index, no identity, no stock reference. */
  function duplicate(btn) {
    var card = btn.closest('[data-prod]');
    var container = card.parentNode;
    var idx = nextIndex(container);
    var copy = card.cloneNode(true);
    $all('[name]', copy).forEach(function (f) {
      var name = f.getAttribute('name').replace(/^p\[[^\]]+\]/, 'p[' + idx + ']');
      f.setAttribute('name', name);
      if (/\[id\]$/.test(name)) f.value = '0';
      if (/\[stock0\]$/.test(name)) f.value = '';
      if (/\[del\]$/.test(name)) f.value = '0';
    });
    $all('.sa-stats', copy).forEach(function (s) { s.parentNode.removeChild(s); });
    card.parentNode.insertBefore(copy, card.nextSibling);
    var name = $('input[name$="[name]"]', copy);
    if (name) name.focus();
  }

  function move(btn) {
    var row = btn.closest('[data-row]');
    if (!row) return;
    var dir = parseInt(btn.getAttribute('data-move'), 10);
    var sib = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
    while (sib && (!sib.hasAttribute('data-row') || sib.hidden)) sib = dir < 0 ? sib.previousElementSibling : sib.nextElementSibling;
    if (!sib) return;
    if (dir < 0) row.parentNode.insertBefore(row, sib); else row.parentNode.insertBefore(sib, row);
    row.scrollIntoView({ block: 'nearest' });
  }

  /* A line already saved is only flagged (the server deletes it, or refuses); a new one just goes. */
  function markDelete(btn) {
    var row = btn.closest(btn.getAttribute('data-mark-del'));
    if (!row) return;
    var id = $('input[name$="[id]"]', row);
    if (id && parseInt(id.value, 10) > 0) {
      var del = $('[data-del]', row);
      if (del) del.value = '1';
      row.hidden = true;
    } else {
      row.parentNode.removeChild(row);
    }
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('button, a, input[data-select]') : null;
    if (!t) return;
    if (t.hasAttribute('data-select')) { t.select(); return; }
    if (t.hasAttribute('data-confirm') && !window.confirm(t.getAttribute('data-confirm'))) {
      e.preventDefault();
      return;
    }
    if (t.hasAttribute('data-add')) { e.preventDefault(); addLine(t); }
    else if (t.hasAttribute('data-add-var')) { e.preventDefault(); addVariant(t); }
    else if (t.hasAttribute('data-dup')) { e.preventDefault(); duplicate(t); }
    else if (t.hasAttribute('data-move')) { e.preventDefault(); move(t); }
    else if (t.hasAttribute('data-mark-del')) { e.preventDefault(); markDelete(t); }
    else if (t.getAttribute('data-remove')) {
      e.preventDefault();
      var r = t.closest(t.getAttribute('data-remove'));
      if (r) r.parentNode.removeChild(r);
    }
  });

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.hasAttribute && f.hasAttribute('data-confirm') && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
  });

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.hasAttribute && t.hasAttribute('data-letter')) t.value = t.value.toUpperCase();
    if (t.hasAttribute && t.hasAttribute('data-option')) {
      var card = t.closest('[data-prod]');
      var has = t.value.trim() !== '';
      var vars = $('[data-vars]', card), simple = $('[data-simple-stock]', card);
      if (vars) vars.hidden = !has;
      if (simple) simple.hidden = has;
    }
  });
})();
