/* Tariff rules of the competition page: adding / removing a category row, and the live preview.
   Scope: #bk-cfg. Texts and number format come from the page (BK_T, BK_CATN). */
var bkCatN = BK_CATN;
function bkAddCat() {
  var html = document.getElementById('bk-cat-tpl').innerHTML.replace(/__i__/g, 'n' + (bkCatN++));
  var wrap = document.createElement('div'); wrap.innerHTML = html.trim();
  document.getElementById('bk-cat-list').appendChild(wrap.firstElementChild);
}
document.addEventListener('click', function (e) {
  var del = e.target.closest && e.target.closest('.bk-cat-del');
  if (del) { e.preventDefault(); var row = del.closest('.bk-cat-row'); if (row) row.remove(); }
});
/* Live tariff preview: reads the form's settings and applies the same formula as the server
   (lib/pricing.php), with its labels (BK_T). */
(function () {
  // #bk-cfg and not "#bkadm form": the FIRST form of the page is the "Copy from…" one — the
  // preview used to read an always empty base fee there.
  var form = document.getElementById('bk-cfg'); if (!form) return;
  function num(v) { v = parseFloat(String(v == null ? '' : v).replace(',', '.')); return isNaN(v) ? 0 : v; }
  function val(id) { var e = document.getElementById(id); return e ? e.value : ''; }
  function selVals(sel) { var a = []; if (!sel) return a; for (var i = 0; i < sel.options.length; i++) if (sel.options[i].selected) a.push(sel.options[i].value); return a; }
  function eur(n, signed) {
    var p = Math.abs(n).toFixed(2).split('.');
    return (n < 0 ? '−' : (signed ? '+' : '')) + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, BK_T.th) + BK_T.dec + p[1] + ' ' + BK_T.cur;
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]; }); }
  function tx(t, a) { return String(t).split('{$a}').join(a); }

  function readConfig() {
    var feeEl = form.elements['fee'];
    var cfg = { base: num(feeEl ? feeEl.value : 0), cats: [], deps: {}, prov: {}, rank: {} };
    Array.prototype.forEach.call(document.querySelectorAll('#bk-cat-list .bk-cat-row'), function (row) {
      var label = (row.querySelector('input[name$="[label]"]') || {}).value || '';
      var price = (row.querySelector('input[name$="[price]"]') || {}).value || '';
      var divSel = row.querySelector('select[name*="[div]"]');
      var clsSel = row.querySelector('select[name*="[cls]"]');
      if (price === '' && !selVals(divSel).length && !selVals(clsSel).length) return;
      cfg.cats.push({ label: label, div: selVals(divSel), cls: selVals(clsSel), price: num(price) });
    });
    Array.prototype.forEach.call(form.querySelectorAll('input[name^="dep["]'), function (inp) {
      var m = inp.name.match(/dep\[(\d+)\]/); if (!m) return; var v = num(inp.value); if (v !== 0) cfg.deps[m[1]] = v;
    });
    cfg.prov = { dept: num((form.elements['prov_dept'] || {}).value), region: num((form.elements['prov_region'] || {}).value) };
    [2, 3].forEach(function (t) { var el = form.elements['rank[' + t + ']']; var v = el ? num(el.value) : 0; if (v !== 0) cfg.rank[t] = v; });
    return cfg;
  }

  function simulate() {
    if (!document.getElementById('sim-total')) return;
    var cfg = readConfig();
    var div = val('sim-div'), cls = val('sim-cls'), ses = val('sim-ses'), prov = val('sim-prov'), rank = parseInt(val('sim-rank') || '1', 10);
    var base = cfg.base, label = BK_T.base;
    for (var i = 0; i < cfg.cats.length; i++) {
      var c = cfg.cats[i];
      var okD = !c.div.length || c.div.indexOf(div) >= 0, okC = !c.cls.length || c.cls.indexOf(cls) >= 0;
      if (okD && okC) { base = c.price; label = c.label ? tx(BK_T.catNamed, c.label) : BK_T.cat; break; }
    }
    var lines = [[label, base, false]], total = base;
    if (ses && ses !== '0' && cfg.deps[ses] !== undefined) { lines.push([tx(BK_T.dep, ses), cfg.deps[ses], true]); total += cfg.deps[ses]; }
    var pd = prov === 'dept' ? cfg.prov.dept : (prov === 'region' ? cfg.prov.region : 0);
    if (pd) { lines.push([prov === 'dept' ? BK_T.dept : BK_T.region, pd, true]); total += pd; }
    var rd = 0, th = 0;
    for (var k in cfg.rank) { var kk = parseInt(k, 10); if (rank >= kk && kk > th) { th = kk; rd = cfg.rank[k]; } }
    if (rd) { lines.push([tx(BK_T.rank, rank), rd, true]); total += rd; }
    total = Math.max(0, total);
    var html = '';
    for (var j = 0; j < lines.length; j++) html += '<tr><td>' + esc(lines[j][0]) + '</td><td>' + eur(lines[j][1], lines[j][2]) + '</td></tr>';
    document.getElementById('sim-lines').innerHTML = html;
    document.getElementById('sim-total').textContent = eur(total, false);
  }
  form.addEventListener('input', simulate);
  form.addEventListener('change', simulate);
  simulate();
})();
