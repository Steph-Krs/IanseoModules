/*
 * public/stats-comp.php — the archer's statistics on one competition, drawn in the browser from the
 * figures the page carries (data-cs): points end by end (target by target on a course) against
 * the competition, the category and an archer picked in the list; success zone at the percentage
 * the archer sets (kept in this browser), on the face and on the spread of the arrows.
 */
(function () {
  var root = document.getElementById('bk-cs');
  if (!root) return;
  var D;
  try { D = JSON.parse(root.getAttribute('data-cs')); } catch (e) { return; }
  var T = D.t, S = D.series;
  var ORDER = ['me', 'comp', 'cat', 'pick'];   // colour follows the series, never its rank
  var shown = { me: true, comp: !!S.comp, cat: !!S.cat, pick: false };
  var NS = 'http://www.w3.org/2000/svg';

  function el(tag, attrs, parent) {
    var n = document.createElementNS(NS, tag);
    for (var k in attrs) n.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(n);
    return n;
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function fmt(v, dec) {
    if (v === null || v === undefined) return '—';
    var p = Number(v).toFixed(dec === undefined ? 1 : dec).split('.');
    return p[0].replace(/\B(?=(\d{3})+(?!\d))/g, T.thousands) + (p[1] ? T.dec + p[1] : '');
  }
  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function read(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function active() { return ORDER.filter(function (k) { return shown[k] && S[k]; }); }
  function valueLabel(v) { return v === 'M' ? T.miss : v; }

  // ---- Points end by end: every distance in a row, a gap between two distances. ----
  var slots = [];   // {d, e, x}
  D.dists.forEach(function (dd, i) {
    if (i > 0) slots.push(null);
    for (var e = 0; e < dd.n; e++) slots.push({ d: dd.d, e: e, label: dd.label });
  });
  function val(k, s) {
    var ends = S[k] && S[k].ends && S[k].ends[s.d];
    var v = ends ? ends[s.e] : null;
    return v === undefined ? null : v;
  }

  var chart = document.getElementById('bk-cs-chart');
  function drawChart() {
    var W = Math.max(280, chart.clientWidth || 600), H = 260, L = 38, R = 10, Tp = 12, B = 30;
    chart.innerHTML = '';
    var keys = active(), lo = Infinity, hi = -Infinity;
    slots.forEach(function (s) {
      if (!s) return;
      keys.forEach(function (k) { var v = val(k, s); if (v !== null) { lo = Math.min(lo, v); hi = Math.max(hi, v); } });
    });
    if (hi === -Infinity) return;
    var span = Math.max(1, hi - lo), yMin = Math.max(0, Math.floor(lo - span * 0.15)), yMax = Math.ceil(hi + span * 0.1);
    if (yMax === yMin) yMax = yMin + 1;
    var n = slots.length, step = (W - L - R) / Math.max(1, n - 1);
    function X(i) { return L + (n === 1 ? (W - L - R) / 2 : i * step); }
    function Y(v) { return Tp + (H - Tp - B) * (1 - (v - yMin) / (yMax - yMin)); }
    var svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': root.querySelector('h2').textContent }, chart);

    // Recessive grid and axes.
    var ticks = 4;
    for (var t = 0; t <= ticks; t++) {
      var v = yMin + (yMax - yMin) * t / ticks, y = Y(v);
      el('line', { x1: L, x2: W - R, y1: y, y2: y, class: 'bk-cs-grid' }, svg);
      el('text', { x: L - 6, y: y + 4, class: 'bk-cs-axis', 'text-anchor': 'end' }, svg).textContent = fmt(v, 0);
    }
    var every = Math.max(1, Math.ceil(n / Math.floor((W - L - R) / 26)));
    slots.forEach(function (s, i) {
      if (!s || (s.e % every !== 0 && s.e !== 0)) return;
      el('text', { x: X(i), y: H - B + 16, class: 'bk-cs-axis', 'text-anchor': 'middle' }, svg).textContent = s.e + 1;
    });
    // Lines (2 px), markers; the archer's own line drawn last, on top.
    keys.slice().reverse().forEach(function (k) {
      var cls = 'bk-cs-s-' + k, d = '', pen = false;
      slots.forEach(function (s, i) {
        var v = s ? val(k, s) : null;
        if (v === null) { pen = false; return; }
        d += (pen ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(v).toFixed(1);
        pen = true;
      });
      el('path', { d: d, class: 'bk-cs-line ' + cls }, svg);
      slots.forEach(function (s, i) {
        var v = s ? val(k, s) : null;
        if (v !== null && k === 'me') el('circle', { cx: X(i), cy: Y(v), r: 4, class: 'bk-cs-dot ' + cls }, svg);
      });
    });

    // Hover: crosshair and the values of the end under the pointer.
    var cross = el('line', { y1: Tp, y2: H - B, class: 'bk-cs-cross', visibility: 'hidden' }, svg);
    var tip = document.createElement('div');
    tip.className = 'bk-cs-tip'; tip.hidden = true;
    chart.appendChild(tip);
    var hit = el('rect', { x: L - step / 2, y: Tp, width: W - L - R + step, height: H - Tp - B, fill: 'transparent' }, svg);
    function at(evt) {
      var r = svg.getBoundingClientRect(), px = (evt.touches ? evt.touches[0].clientX : evt.clientX) - r.left;
      var i = Math.round((px - L) / (step || 1));
      if (i < 0 || i >= n || !slots[i]) { leave(); return; }
      var s = slots[i];
      cross.setAttribute('x1', X(i)); cross.setAttribute('x2', X(i)); cross.setAttribute('visibility', 'visible');
      var h = '<b>' + esc(T.end.replace('{$a}', s.e + 1)) + '</b>' + (D.dists.length > 1 ? '<br><small>' + esc(s.label) + '</small>' : '');
      keys.forEach(function (k) {
        h += '<div><i class="bk-cs-sw bk-cs-s-' + k + '"></i>' + esc(S[k].name) + ' : <b>' + fmt(val(k, s), k === 'me' || k === 'pick' ? 0 : 1) + '</b></div>';
      });
      tip.innerHTML = h; tip.hidden = false;
      var left = Math.min(Math.max(0, X(i) + 12), W - tip.offsetWidth - 4);
      if (X(i) + 12 + tip.offsetWidth > W) left = Math.max(0, X(i) - 12 - tip.offsetWidth);
      tip.style.left = left + 'px'; tip.style.top = '8px';
    }
    function leave() { cross.setAttribute('visibility', 'hidden'); tip.hidden = true; }
    hit.addEventListener('mousemove', at);
    hit.addEventListener('touchstart', at, { passive: true });
    hit.addEventListener('touchmove', at, { passive: true });
    hit.addEventListener('mouseleave', leave);
  }

  // Legend: always there with two series or more; identity never by colour alone.
  function drawLegend() {
    var box = document.getElementById('bk-cs-legend'), keys = active();
    box.innerHTML = keys.length < 2 ? '' : keys.map(function (k) {
      return '<span><i class="bk-cs-sw bk-cs-s-' + k + '"></i>' + esc(S[k].name)
        + (S[k].avg !== null ? ' <small>(' + fmt(S[k].avg, 2) + ' / ' + esc(T.pts) + ')</small>' : '') + '</span>';
    }).join('');
  }

  // ---- Success zone: the smallest value reached by at least p % of the archer's arrows. ----
  var pctIn = document.getElementById('bk-cs-pct'), pctOut = document.getElementById('bk-cs-pctv');
  var saved = parseInt(read('bk_cs_zone'), 10);
  if (saved >= 0 && saved <= 100) pctIn.value = saved;
  function zone(p) {
    var sp = S.me.spread, cum = 0;
    for (var i = 0; i < sp.length; i++) {
      cum += sp[i].p;
      if (cum >= p - 0.001) return { i: i, v: sp[i].v, w: sp[i].w, real: cum };
    }
    return sp.length ? { i: sp.length - 1, v: sp[sp.length - 1].v, w: sp[sp.length - 1].w, real: cum } : null;
  }

  // The face, what lies outside the zone faded. The kind comes from the core's target type
  // (lib/compstats.php, bk_cs_face_kind): full 10-ring face, reduced 5-X or 6-X face, triple spot,
  // field face, a 3D animal, or the beursault card.
  var WA = { 1: '#ffffff', 2: '#ffffff', 3: '#20263d', 4: '#20263d', 5: '#2f8fd8', 6: '#2f8fd8', 7: '#e2332d', 8: '#e2332d', 9: '#f5c400', 10: '#f5c400' };
  var FIELD = { 1: '#20263d', 2: '#20263d', 3: '#20263d', 4: '#20263d', 5: '#f5c400', 6: '#f5c400' };
  function zoneLimit(z, top) {
    if (z.v === 'X') return top + 1;
    if (z.v === 'M') return 0;
    return parseInt(z.v, 10) || 0;
  }
  // Rings $first to $top of a face of centre (cx, cy) and outer radius R; X = half the last ring.
  function rings(svg, cx, cy, R, first, top, colors, limit) {
    var n = top - first + 1, w = R / n;
    for (var k = first; k <= top; k++) {
      var inZone = k >= limit, dark = colors[k] === '#20263d';
      el('circle', { cx: cx, cy: cy, r: w * (top + 1 - k), fill: colors[k], 'fill-opacity': inZone ? 1 : 0.22,
        stroke: dark ? '#ffffff' : '#20263d', 'stroke-width': 0.6, 'stroke-opacity': inZone ? 0.8 : 0.3 }, svg);
    }
    el('circle', { cx: cx, cy: cy, r: w / 2, fill: 'none', stroke: '#20263d', 'stroke-width': 0.6 }, svg);
    if (limit > 0) {
      var rz = limit > top ? w / 2 : w * (top + 1 - Math.max(limit, first));
      el('circle', { cx: cx, cy: cy, r: rz, class: 'bk-cs-zline' }, svg);
    }
  }
  // A stag, as on a 3D course (drawing: assets/target-3d.png, dark lines on transparent; its
  // outline below, in the picture's pixels): body 5, vital zone 8, circles 10 and 11 laid over it;
  // antlers and hooves score nothing (M), they stay out of the tinted body.
  var DEER = {
    w: 440, h: 500, top: 120, bottom: 471,
    outline: 'M38.8 4.2L42.1 4.2L42.1 6.9L36.2 13.4L33 22.5L33.6 30.4L37.5 38.8L38.8 40.1L39.5 39.5L40.8 31.7'
      + 'L44.1 21.9L46.7 16.6L48.6 14.7L51.2 14.7L51.2 18.6L49.3 24.5L48 33L48 49.3L48.6 49.9L60.4 38.2'
      + 'L63 33.6L65.6 33.6L64.3 42.8L61 49.3L51.2 63L51.2 65.6L55.8 77.4L62.3 88.5L65 91.1L65.6 90.4'
      + 'L65 89.1L65 75.4L67.6 75.4L70.2 86.5L75.4 95.6L83.2 103.5L100.2 112.6L100.9 111.3L98.9 104.1'
      + 'L99.6 89.1L103.5 78L106.1 78L106.1 96.3L108.7 109.3L111.3 114.6L113.9 117.8L119.8 121.8'
      + 'L123.7 121.1L128.9 123.1L144.6 121.8L147.2 117.8L153.7 111.3L158.3 102.8L160.9 94.3L161.6 75.4'
      + 'L164.2 75.4L168.1 85.8L168.1 99.6L166.1 106.7L163.5 111.3L164.2 112.6L177.9 107.4L186.4 99.6'
      + 'L189 94.3L189.6 89.1L188.3 84.5L185.7 81.9L185.7 79.3L189.6 79.3L193.6 83.2L195.5 87.8'
      + 'L195.5 97.6L196.2 98.2L208.6 85.8L213.1 80L216.4 68.9L217.1 58.4L215.1 53.9L203.4 36.9'
      + 'L201.4 29.7L201.4 25.8L204 25.8L206.6 30.4L213.8 37.5L214.5 35.6L210.5 25.1L206 17.3L200.7 10.8'
      + 'L200.7 8.2L204.7 8.8L212.5 16L217.1 22.5L222.3 34.3L223.6 33L224.9 14L228.2 13.4L229.5 17.3'
      + 'L230.8 30.4L230.1 47.3L231.4 49.3L232.7 58.4L234 61.7L238.6 57.1L241.9 50.6L243.2 44.7'
      + 'L243.8 16.6L245.8 11.4L249.1 10.8L248.4 19.9L253 41.5L252.3 51.9L249.1 61L246.4 65L232.1 78.7'
      + 'L225.5 91.7L215.1 102.8L203.4 110.7L192.3 115.9L193.6 117.8L193.6 121.8L190.9 131.5L187.7 138.7'
      + 'L181.2 145.3L175.9 147.9L165.5 150.5L159 158.3L162.2 165.5L162.9 170.1L166.8 174.6L170.7 185.7'
      + 'L174 188.3L175.9 192.3L179.2 194.9L180.5 197.5L198.1 196.8L203.4 197.5L208.6 199.4L215.8 199.4'
      + 'L216.4 198.8L232.7 199.4L266.7 207.9L287.6 203.4L310.4 196.2L329.3 195.5L345 198.8L352.8 204'
      + 'L361.3 204L365.9 205.3L375 210.5L380.9 215.8L388.8 226.9L392 233.4L392.7 238L395.9 243.2'
      + 'L397.9 250.4L398.5 268L395.9 283L394 288.2L394 305.2L397.2 321.5L403.8 336.5L407.7 342.4'
      + 'L414.2 349.6L418.1 356.8L419.4 365.3L419.4 372.4L418.8 373.1L424 386.8L427.3 399.2L429.9 416.8'
      + 'L430.5 439L435.8 458L435.1 462.5L433.1 463.8L431.8 468.4L432.5 475.6L435.8 481.5L435.8 486.7'
      + 'L433.8 491.2L431.8 493.2L427.3 493.9L425.3 495.8L422.7 495.8L421.4 494.5L417.5 493.9L408.3 493.2'
      + 'L409 484.7L412.3 471.7L414.9 466.4L413.6 462.5L413.6 456.6L414.9 453.4L414.2 439.7L409 410.3'
      + 'L403.1 388.1L397.9 375.7L375.7 354.8L375 355.5L375 365.9L371.1 382.9L362.6 412.9L351.5 446.2'
      + 'L352.2 459.3L351.5 461.2L349.6 463.2L348.3 463.2L347 465.1L350.2 480.1L350.2 485.4L346.3 492.6'
      + 'L342.4 494.5L329.3 494.5L328.7 495.2L315 494.5L315.6 489.3L318.9 484.1L332 471L333.9 463.2'
      + 'L333.9 450.8L335.2 443.6L346.3 409.6L349.6 394L350.2 384.2L348.9 374.4L345 370.5L327.4 359.4'
      + 'L320.9 354.2L312.4 345.7L305.8 336.5L278.4 340.4L264.7 340.4L251 337.8L245.1 337.2L241.9 337.8'
      + 'L235.3 371.8L232.1 382.9L234 388.8L234 396.6L232.1 403.1L230.8 441.6L233.4 460.6L234 477.5'
      + 'L231.4 480.8L232.1 484.7L231.4 489.9L226.9 492.6L219 493.9L210.5 493.9L204 491.2L204 488'
      + 'L205.3 484.7L209.2 478.8L216.4 471.7L217.1 449.5L217.7 448.8L217.7 429.9L217.1 429.2L216.4 411.6'
      + 'L215.8 407L213.1 399.9L211.8 391.4L209.9 363.9L209.2 363.3L209.2 354.8L208.6 354.2L208.6 345'
      + 'L207.3 343.1L206.6 343.7L205.3 363.9L198.8 380.3L198.1 383.5L198.8 392L197.5 399.2L192.3 409.6'
      + 'L189.6 435.1L189 451.4L188.3 452.1L189 470.4L185.1 476.9L185.1 486L182.5 489.3L178.5 490.6'
      + 'L172.7 491.2L165.5 491.2L164.8 490.6L164.2 491.2L155.7 491.2L154.4 490.6L153.7 486L156.4 481.5'
      + 'L161.6 475.6L170.1 469.7L169.4 468.4L170.1 460.6L172.7 456.6L174 452.1L174.6 418.8L177.2 406.4'
      + 'L176.6 401.2L177.2 375.7L174 351.5L174 345.7L171.4 339.1L168.1 335.9L158.3 329.3L155 329.3'
      + 'L148.5 324.8L146.6 324.8L138.7 316.9L136.8 316.9L126.3 308.5L124.4 307.8L108.7 292.1L104.8 286.3'
      + 'L102.2 279.1L102.2 276.5L95 262.1L93 253.6L93 240.6L91.1 233.4L91.1 220.3L91.7 219.7L91.1 203.4'
      + 'L93.7 195.5L96.3 191.6L96.9 187L100.9 177.9L102.2 170.1L104.1 166.1L102.2 162.9L102.2 155'
      + 'L97.6 149.8L89.8 148.5L85.8 146.6L78.7 140L71.5 127.6L70.8 115.2L49.9 102.2L42.8 95L38.2 85.8'
      + 'L32.3 83.2L25.8 78L21.9 72.8L12.7 51.9L9.5 46.7L4.2 41.5L4.2 38.8L8.8 38.8L12.7 40.8L19.9 48.6'
      + 'L33 70.2L34.9 72.1L35.6 71.5L36.2 61.7L19.3 44.7L13.4 36.9L10.1 28.4L10.1 19.3L11.4 15.3'
      + 'L14.7 14.7L14 20.6L16 26.4L19.9 32.3L25.8 38.2L26.4 36.2L25.1 31.7L25.1 23.2L27.1 15.3L29.7 10.8'
      + 'L33 7.5Z',
    vital: 'M219 280 C219 262 241 254 263 256 C285 258 299 270 295 286 C291 302 271 300 255 304 C235 308 219 298 219 280 Z',
    cx: 265, cy: 278, r10: 11, r11: 4.5
  };
  function deer(box, limit) {
    var g = DEER, svg = el('svg', { width: 220, height: Math.round(220 * g.h / g.w), viewBox: '0 0 ' + g.w + ' ' + g.h, role: 'img', class: 'bk-cs-target' }, box);
    var op = function (v) { return limit <= v ? 1 : 0.22; };
    var clip = el('clipPath', { id: 'bk-cs-body' }, el('defs', {}, svg));
    el('rect', { x: 0, y: g.top, width: g.w, height: g.bottom - g.top }, clip);
    el('rect', { x: 0, y: 0, width: g.w, height: g.h, rx: 6, fill: '#fbf8f2' }, svg);
    el('path', { d: g.outline, fill: '#d8b98a', 'fill-opacity': 0.75 * op(5), 'clip-path': 'url(#bk-cs-body)' }, svg);
    if (D.deerImg) el('image', { href: D.deerImg, width: g.w, height: g.h }, svg);
    el('path', { d: g.vital, fill: '#b0824d', 'fill-opacity': 0.8 * op(8), stroke: '#3b2c1e', 'stroke-width': 1.2 }, svg);
    el('circle', { cx: g.cx, cy: g.cy, r: g.r10, fill: '#7a5530', 'fill-opacity': 0.9 * op(10), stroke: '#3b2c1e', 'stroke-width': 1.2 }, svg);
    el('circle', { cx: g.cx, cy: g.cy, r: g.r11, fill: '#4a3220', 'fill-opacity': op(11), stroke: '#3b2c1e', 'stroke-width': 1.2 }, svg);
    // The zone's edge: the outline of the smallest zone it still covers.
    var line = limit > 10 ? ['circle', { cx: g.cx, cy: g.cy, r: g.r11 }] : limit > 8 ? ['circle', { cx: g.cx, cy: g.cy, r: g.r10 }]
      : limit > 5 ? ['path', { d: g.vital }] : limit > 0 ? ['path', { d: g.outline, 'clip-path': 'url(#bk-cs-body)' }] : null;
    if (line) { line[1]['class'] = 'bk-cs-zline'; el(line[0], line[1], svg); }
  }
  // The beursault card (assets/target-beursault.png, dark lines on transparent, 320 px): zone 4 the
  // black centre, 3 up to the thick ring, then 2 and 1 to the edge; the zones tinted under it.
  var BSL = { size: 320, cx: 160, cy: 159.5, r: { 4: 13, 3: 47, 2: 94, 1: 156 } };
  function beursault(box, limit) {
    var g = BSL, svg = el('svg', { width: 220, height: 220, viewBox: '0 0 ' + g.size + ' ' + g.size, role: 'img', class: 'bk-cs-target' }, box);
    el('rect', { x: 0, y: 0, width: g.size, height: g.size, rx: 6, fill: '#ffffff' }, svg);
    [1, 2, 3, 4].forEach(function (v) {
      el('circle', { cx: g.cx, cy: g.cy, r: g.r[v], fill: v >= limit && limit > 0 ? '#f5c400' : '#ffffff', 'fill-opacity': 0.35 + v * 0.08 }, svg);
    });
    if (D.bslImg) el('image', { href: D.bslImg, width: g.size, height: g.size }, svg);
    if (limit > 0 && limit <= 4) el('circle', { cx: g.cx, cy: g.cy, r: g.r[limit], class: 'bk-cs-zline' }, svg);
  }
  // A zone down to the misses covers the whole picture: overlay its whole rectangle.
  function missZone(box) {
    var svg = box.querySelector('svg'), vb = svg && svg.viewBox && svg.viewBox.baseVal;
    if (!vb) return;
    el('rect', { x: vb.x + 1.5, y: vb.y + 1.5, width: vb.width - 3, height: vb.height - 3, rx: 6, class: 'bk-cs-zline bk-cs-zall' }, svg);
  }
  function drawFace(z) {
    var box = document.getElementById('bk-cs-face');
    box.innerHTML = '';
    if (!D.face || !z) return;
    drawFaceKind(box, z);
    if (z.v === 'M') missZone(box);
  }
  function drawFaceKind(box, z) {
    if (D.face === '3d') { deer(box, zoneLimit(z, 11)); return; }
    if (D.face === 'beursault') { beursault(box, zoneLimit(z, 4)); return; }
    if (D.face === 'tri') {
      // Three faces of rings 6 to 10, one above the other, on their card.
      var svg3 = el('svg', { width: 92, height: 240, viewBox: '0 0 92 240', role: 'img', class: 'bk-cs-target' }, box);
      el('rect', { x: 1, y: 1, width: 90, height: 238, rx: 3, fill: '#ffffff', stroke: '#c8cdd6' }, svg3);
      [41, 120, 199].forEach(function (cy) { rings(svg3, 46, cy, 38, 6, 10, WA, zoneLimit(z, 10)); });
      return;
    }
    var svg = el('svg', { width: 200, height: 200, viewBox: '0 0 200 200', role: 'img', class: 'bk-cs-target' }, box);
    if (D.face === 'field') rings(svg, 100, 100, 92, 1, 6, FIELD, zoneLimit(z, 6));
    else rings(svg, 100, 100, 92, D.face === 'wa5' ? 5 : (D.face === 'wa6' ? 6 : 1), 10, WA, zoneLimit(z, 10));
  }

  // Spread of the arrows by value, the archer and the series compared; the zone's values marked.
  function drawBars(z) {
    var box = document.getElementById('bk-cs-bars'), keys = active();
    var values = [];
    keys.forEach(function (k) {
      (S[k].spread || []).forEach(function (b) { if (!values.some(function (x) { return x.v === b.v; })) values.push({ v: b.v, w: b.w }); });
    });
    values.sort(function (a, b) { return b.w - a.w; });
    // A value nobody shown reached (0.0 % everywhere once rounded) takes no line.
    values = values.filter(function (b) {
      return keys.some(function (k) { return (S[k].spread || []).some(function (x) { return x.v === b.v && x.p >= 0.05; }); });
    });
    var html = '';
    values.forEach(function (b) {
      var inZ = z && b.w >= z.w;
      html += '<div class="bk-cs-brow' + (inZ ? ' in' : '') + '"><span class="bk-cs-bv">' + esc(valueLabel(b.v)) + '</span><span class="bk-cs-bs">';
      keys.forEach(function (k) {
        var p = 0;
        (S[k].spread || []).forEach(function (x) { if (x.v === b.v) p = x.p; });
        html += '<span class="bk-cs-b" title="' + esc(S[k].name) + ' : ' + fmt(p, 1) + ' %"><i class="bk-cs-s-' + k + '" style="width:' + Math.min(100, p) + '%"></i><em>' + fmt(p, 1) + ' %</em></span>';
      });
      html += '</span></div>';
    });
    box.innerHTML = html;
  }

  function drawZone() {
    var p = parseInt(pctIn.value, 10);
    pctOut.textContent = p + ' %';
    var z = zone(p), res = document.getElementById('bk-cs-zres');
    res.textContent = z ? T.zone.replace('{$a[pct]}', p).replace('{$a[ring]}', valueLabel(z.v)).replace('{$a[real]}', fmt(z.real, 1)) : T.zoneNone;
    drawFace(z);
    drawBars(z);
  }
  pctIn.addEventListener('input', function () { store('bk_cs_zone', pctIn.value); drawZone(); });

  // ---- Table: a column for the archer picked. ----
  function tableColumn() {
    var tb = document.getElementById('bk-cs-table');
    if (!tb) return;
    Array.prototype.forEach.call(tb.querySelectorAll('.bk-cs-pickcol'), function (c) { c.parentNode.removeChild(c); });
    Array.prototype.forEach.call(tb.querySelectorAll('tr.bk-cs-dist th'), function (c) {
      if (!c.getAttribute('data-span')) c.setAttribute('data-span', c.colSpan);
      c.colSpan = parseInt(c.getAttribute('data-span'), 10);
    });
    if (!shown.pick || !S.pick) return;
    var head = tb.querySelector('thead tr'), th = document.createElement('th');
    th.className = 'num bk-cs-pickcol'; th.textContent = S.pick.name; head.appendChild(th);
    var i = 0;
    var flat = slots.filter(function (s) { return !!s; });
    Array.prototype.forEach.call(tb.querySelectorAll('tbody tr'), function (tr) {
      if (tr.classList.contains('bk-cs-dist')) { var c = tr.querySelector('th'); c.colSpan = c.colSpan + 1; return; }
      var td = document.createElement('td'), s = flat[i++];
      td.className = 'num bk-cs-pickcol'; td.textContent = s ? fmt(val('pick', s), 0) : '—';
      tr.appendChild(td);
    });
  }

  function redraw() { drawLegend(); drawChart(); drawZone(); tableColumn(); }

  Array.prototype.forEach.call(root.querySelectorAll('input[data-series]'), function (c) {
    c.addEventListener('change', function () { shown[c.getAttribute('data-series')] = c.checked; redraw(); });
  });
  var pick = document.getElementById('bk-cs-pick');
  if (pick) pick.addEventListener('change', function () {
    if (!pick.value) { shown.pick = false; S.pick = null; redraw(); return; }
    var x = new XMLHttpRequest();
    x.open('GET', D.cmpUrl + encodeURIComponent(pick.value));
    x.onload = function () {
      var j = null;
      try { j = JSON.parse(x.responseText); } catch (e) {}
      if (!j || j.error !== 0) { alert(j && j.msg ? j.msg : T.err); return; }
      S.pick = j.series; shown.pick = true; redraw();
    };
    x.send();
  });
  var rt;
  window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(drawChart, 150); });
  redraw();
})();
