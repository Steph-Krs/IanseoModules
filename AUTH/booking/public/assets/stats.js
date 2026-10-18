/* Archer statistics: the score chart of a combination (data from lib/stats.php, bk_stats_chart).
   Drawn here at the width of the screen, and drawn again on every zoom: drag across a period to
   zoom on it, pinch on a touch screen, Ctrl + wheel (or a touchpad pinch), the buttons; double
   click to see everything again. The scale of the scores follows the rounds shown. Tooltips on
   pointer, touch and keyboard focus (arrow keys move from one round to the next).
   Texts come from the page (competition names): written with textContent only. */
(function () {
    'use strict';
    var NS = 'http://www.w3.org/2000/svg';
    var DAY = 86400000;

    function el(tag, attrs, parent, text) {
        var e = document.createElementNS(NS, tag);
        for (var k in attrs) if (Object.prototype.hasOwnProperty.call(attrs, k)) e.setAttribute(k, attrs[k]);
        if (text !== undefined) e.textContent = text;
        if (parent) parent.appendChild(e);
        return e;
    }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    // A PHP date() format of the page ("d/m", "d.m.") for a day and month.
    function dayLabel(d, fmt) {
        var out = '';
        for (var i = 0; i < fmt.length; i++) {
            var c = fmt.charAt(i);
            out += c === 'd' ? pad2(d.getUTCDate()) : c === 'j' ? d.getUTCDate()
                : c === 'm' ? pad2(d.getUTCMonth() + 1) : c === 'n' ? (d.getUTCMonth() + 1) : c;
        }
        return out;
    }
    // A round step giving about n intervals over span.
    function step(span, n) {
        var s = [1, 2, 5, 10, 20, 25, 50, 100, 200, 500];
        for (var i = 0; i < s.length; i++) if (span / s[i] <= n) return s[i];
        return 1000;
    }

    [].forEach.call(document.querySelectorAll('.bk-chart[data-chart]'), function (fig) {
        var cfg;
        try { cfg = JSON.parse(fig.getAttribute('data-chart')); } catch (e) { return; }
        var pts = cfg.pts || [], lines = cfg.lines || [], tx = cfg.tx || {};
        var plot = fig.querySelector('.bk-chart-plot'), tip = fig.querySelector('.bk-chart-tip');
        var period = fig.querySelector('.bk-chart-period');
        if (!pts.length || !plot || !tip) return;

        var t0 = pts[0].t, t1 = pts[pts.length - 1].t;
        var pad = Math.max(20 * DAY, (t1 - t0) * 0.04);
        var full = [t0 - pad, t1 + pad], view = full.slice();
        var MIN = 4 * DAY;
        var svg, g, xOf, yOf, vis = [], cur = -1;

        function clampView(a, b) {
            var span = Math.max(MIN, Math.min(full[1] - full[0], b - a));
            if (a < full[0]) a = full[0];
            if (a + span > full[1]) a = full[1] - span;
            return [a, a + span];
        }
        function zoomed() { return view[0] > full[0] + 1 || view[1] < full[1] - 1; }

        function render() {
            var W = Math.max(280, plot.clientWidth || 600), narrow = W < 520;
            var H = narrow ? 260 : 300, L = 40, R = narrow ? 44 : 64, T = 18, B = 30;
            var pw = W - L - R, ph = H - T - B;
            g = { L: L, T: T, pw: pw, ph: ph };
            cur = -1;
            tip.hidden = true;

            // Rounds shown, and the scale of the scores they need. Seeing everything, the next
            // level and the highest earned stay in view; zoomed in, the rounds alone decide.
            vis = [];
            for (var i = 0; i < pts.length; i++) if (pts[i].t >= view[0] && pts[i].t <= view[1]) vis.push(i);
            var vals = vis.map(function (i) { return pts[i].v; });
            if (!vals.length) vals = pts.map(function (p) { return p.v; });
            var lo = Math.min.apply(null, vals), hi = Math.max.apply(null, vals);
            if (!zoomed()) lines.forEach(function (l) { if (l.key) { lo = Math.min(lo, l.v); hi = Math.max(hi, l.v); } });
            var span = Math.max(10, hi - lo);
            lo = Math.max(0, lo - span * 0.08); hi += span * 0.1;
            var st = step(hi - lo, ph < 220 ? 4 : 5);
            lo = Math.floor(lo / st) * st; hi = Math.ceil(hi / st) * st;
            xOf = function (t) { return L + (t - view[0]) / (view[1] - view[0]) * pw; };
            yOf = function (v) { return T + ph - (v - lo) / (hi - lo) * ph; };

            if (svg) svg.parentNode.removeChild(svg);
            svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', 'aria-label': tx.aria || '' });
            plot.appendChild(svg);
            var defs = el('defs', {}, svg), cid = 'bkc' + Math.random().toString(36).slice(2, 8);
            el('rect', { x: L, y: T - 8, width: pw, height: ph + 16 }, el('clipPath', { id: cid }, defs));

            for (var v = lo; v <= hi + 0.001; v += st) {
                el('line', { 'class': 'g', x1: L, x2: L + pw, y1: yOf(v), y2: yOf(v) }, svg);
                el('text', { 'class': 'ax', x: L - 7, y: yOf(v) + 4, 'text-anchor': 'end' }, svg, String(Math.round(v)));
            }
            ticks(svg, L, T, pw, ph);
            el('line', { 'class': 'base', x1: L, x2: L + pw, y1: T + ph, y2: T + ph }, svg);

            // Thresholds; labels in the right margin, the key ones placed first, never overlapping.
            var placed = [];
            lines.slice().sort(function (a, b) { return b.key - a.key; }).forEach(function (l) {
                if (l.v < lo || l.v > hi) return;
                var y = yOf(l.v), gr = el('g', { 'class': 'th th-' + l.s }, svg);
                el('title', {}, gr, l.n + ' — ' + l.v);
                el('line', { x1: L, x2: L + pw, y1: y, y2: y, stroke: l.c }, gr);
                if (placed.every(function (p) { return Math.abs(p - y) >= 13; })) {
                    placed.push(y);
                    el('rect', { x: L + pw + 6, y: y - 4.5, width: 9, height: 9, rx: 2, fill: l.c }, gr);
                    el('text', { 'class': 'tl', x: L + pw + 19, y: y + 4 }, gr, String(l.v));
                }
            });

            // The line through every round (clipped to the plot), the points shown, the record.
            var data = el('g', { 'clip-path': 'url(#' + cid + ')' }, svg);
            el('path', { 'class': 'ln', d: pts.map(function (p, i) { return (i ? 'L' : 'M') + xOf(p.t).toFixed(1) + ',' + yOf(p.v).toFixed(1); }).join(' ') }, data);
            vis.forEach(function (i) {
                var p = pts[i];
                el('circle', { 'class': 'pt' + (p.rec ? ' rec' : '') + (p.p ? ' pal' : ''), 'data-i': i,
                    cx: xOf(p.t), cy: yOf(p.v), r: p.rec ? 6.5 : 4.5 }, data);
            });
            var rec = pts.filter(function (p) { return p.rec; })[0];
            if (rec && rec.t >= view[0] && rec.t <= view[1]) {
                el('text', { 'class': 'rl', x: Math.min(Math.max(xOf(rec.t), L + 30), L + pw - 30),
                    y: Math.max(T + 2, yOf(rec.v) - 12), 'text-anchor': 'middle' }, data, (tx.rec || '{v}').replace('{v}', rec.v));
            }
            el('line', { 'class': 'cross', x1: 0, x2: 0, y1: T, y2: T + ph }, svg);
            el('rect', { 'class': 'brush', x: 0, y: T, width: 0, height: ph }, svg);
            vis.forEach(function (i) {
                var p = pts[i];
                var h = el('circle', { 'class': 'hit', tabindex: 0, 'data-i': i, cx: xOf(p.t), cy: yOf(p.v), r: 12,
                    'aria-label': p.v + ' — ' + p.n + ', ' + p.d }, svg);
                h.addEventListener('focus', function () { show(i); });
                h.addEventListener('blur', hide);
                h.addEventListener('keydown', keyMove);
            });
            bar();
        }

        // Dates: a tick every 1, 2 or 7 days, 1, 2, 3 or 6 months, 1, 2 or 5 years — as many as
        // the width holds (one per 64 px).
        function ticks(svg, L, T, pw, ph) {
            var room = Math.max(2, Math.floor(pw / 64)), span = view[1] - view[0];
            var steps = [[1, 'd'], [2, 'd'], [7, 'd'], [14, 'd'], [1, 'm'], [2, 'm'], [3, 'm'], [6, 'm'], [12, 'y'], [24, 'y'], [60, 'y']];
            var s = steps[steps.length - 1];
            for (var i = 0; i < steps.length; i++) {
                var len = steps[i][1] === 'd' ? steps[i][0] * DAY : steps[i][0] * 30.44 * DAY;
                if (span / len <= room) { s = steps[i]; break; }
            }
            var marks = [], d = new Date(view[0]);
            if (s[1] === 'd') {
                var t = Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
                for (; t <= view[1]; t += s[0] * DAY) if (t >= view[0]) marks.push([t, dayLabel(new Date(t), tx.day || 'd/m')]);
            } else {
                var y = d.getUTCFullYear(), m = d.getUTCMonth();
                for (var n = 0; n < 2400; n++) {
                    var tt = Date.UTC(y, m, 1);
                    if (tt > view[1]) break;
                    var ok = s[1] === 'y' ? (m === 0 && y % (s[0] / 12) === 0) : m % s[0] === 0;
                    if (tt >= view[0] && ok) marks.push([tt, s[1] === 'y' ? String(y) : pad2(m + 1) + '/' + pad2(y % 100)]);
                    if (++m > 11) { m = 0; y++; }
                }
            }
            marks.forEach(function (k) {
                var x = L + (k[0] - view[0]) / span * pw;
                el('line', { 'class': 'tk', x1: x, x2: x, y1: T + ph, y2: T + ph + 4 }, svg);
                el('text', { 'class': 'ax', x: x, y: T + ph + 18, 'text-anchor': 'middle' }, svg, k[1]);
            });
        }

        function bar() {
            [].forEach.call(fig.querySelectorAll('.bk-chart-b'), function (b) {
                var a = b.getAttribute('data-act');
                b.disabled = a === 'in' ? view[1] - view[0] <= MIN + 1
                    : a === 'prev' ? view[0] <= full[0] + 1
                    : a === 'next' ? view[1] >= full[1] - 1
                    : !zoomed();
            });
            if (period) {
                var a = new Date(view[0]), b = new Date(view[1]);
                period.textContent = view[1] - view[0] < 62 * DAY
                    ? dayLabel(a, tx.day || 'd/m') + '/' + a.getUTCFullYear() + ' – ' + dayLabel(b, tx.day || 'd/m') + '/' + b.getUTCFullYear()
                    : pad2(a.getUTCMonth() + 1) + '/' + a.getUTCFullYear() + ' – ' + pad2(b.getUTCMonth() + 1) + '/' + b.getUTCFullYear();
            }
        }

        function setView(a, b) { view = clampView(a, b); render(); }
        function zoomAt(factor, t) {
            var span = view[1] - view[0], ns = span * factor, k = (t - view[0]) / span;
            setView(t - k * ns, t - k * ns + ns);
        }

        function line(cls, text) {
            if (!text) return;
            var d = document.createElement('div');
            d.className = cls;
            d.textContent = text;
            tip.appendChild(d);
        }
        function show(i) {
            if (i === cur) return;
            cur = i;
            var p = pts[i];
            while (tip.firstChild) tip.removeChild(tip.firstChild);
            line('v', String(p.v));
            line('n', p.n);
            line('m', p.w);
            line('m', p.d);
            line('m', p.k);
            line('o', p.o);
            [].forEach.call(svg.querySelectorAll('.pt'), function (d) { d.classList.toggle('on', +d.getAttribute('data-i') === i); });
            var cross = svg.querySelector('.cross'), x = xOf(p.t), y = yOf(p.v);
            cross.setAttribute('x1', x);
            cross.setAttribute('x2', x);
            cross.classList.add('on');
            tip.hidden = false;
            var box = svg.getBoundingClientRect(), fb = fig.getBoundingClientRect();
            var px = box.left - fb.left + x / svg.viewBox.baseVal.width * box.width;
            var py = box.top - fb.top + y / svg.viewBox.baseVal.height * box.height;
            var w = tip.offsetWidth, h = tip.offsetHeight;
            tip.style.left = Math.max(0, Math.min(fb.width - w, px - w / 2)) + 'px';
            tip.style.top = (py - h - 14 < 0 ? py + 16 : py - h - 14) + 'px';
        }
        function hide() {
            cur = -1;
            tip.hidden = true;
            if (!svg) return;
            svg.querySelector('.cross').classList.remove('on');
            [].forEach.call(svg.querySelectorAll('.pt'), function (d) { d.classList.remove('on'); });
        }
        // Arrow keys on a focused round: the previous or next one, the view following it.
        function keyMove(ev) {
            if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
            ev.preventDefault();
            var i = +ev.target.getAttribute('data-i') + (ev.key === 'ArrowRight' ? 1 : -1);
            if (i < 0 || i >= pts.length) return;
            if (pts[i].t < view[0] || pts[i].t > view[1]) {
                var span = view[1] - view[0];
                setView(pts[i].t - span / 2, pts[i].t + span / 2);
            }
            var h = svg.querySelector('.hit[data-i="' + i + '"]');
            if (h) h.focus();
        }

        // Position of a pointer in the drawing's units, and the round nearest to it (the date
        // counts more than the score: aiming at a day is easier than at a line).
        function at(ev) {
            var box = svg.getBoundingClientRect(), vb = svg.viewBox.baseVal;
            return { x: (ev.clientX - box.left) / box.width * vb.width, y: (ev.clientY - box.top) / box.height * vb.height };
        }
        function nearest(p) {
            var best = -1, bd = Infinity;
            vis.forEach(function (i) {
                var dx = xOf(pts[i].t) - p.x, dy = (yOf(pts[i].v) - p.y) / 3, d = dx * dx + dy * dy;
                if (d < bd) { bd = d; best = i; }
            });
            return best;
        }
        function tAt(x) { return view[0] + (Math.min(Math.max(x, g.L), g.L + g.pw) - g.L) / g.pw * (view[1] - view[0]); }

        // Pointers: a drag selects a period, two fingers pinch, a tap shows the round under it.
        var down = {}, drag = null, pinch = null;
        function brush(a, b) {
            var r = svg.querySelector('.brush');
            if (a === null) { r.classList.remove('on'); return; }
            var x0 = Math.min(Math.max(Math.min(a, b), g.L), g.L + g.pw), x1 = Math.min(Math.max(Math.max(a, b), g.L), g.L + g.pw);
            r.setAttribute('x', x0);
            r.setAttribute('width', x1 - x0);
            r.classList.add('on');
        }
        plot.addEventListener('pointerdown', function (ev) {
            if (!svg || (ev.pointerType === 'mouse' && ev.button !== 0)) return;
            down[ev.pointerId] = at(ev);
            var ids = Object.keys(down);
            if (ids.length === 2) {
                var a = down[ids[0]], b = down[ids[1]];
                pinch = { d: Math.abs(a.x - b.x) || 1, c: tAt((a.x + b.x) / 2), v: view.slice() };
                drag = null;
                brush(null);
            } else if (ids.length === 1) {
                drag = { x: down[ev.pointerId].x, moved: false, id: ev.pointerId, type: ev.pointerType };
            }
        });
        plot.addEventListener('pointermove', function (ev) {
            if (!svg) return;
            var p = at(ev);
            if (down[ev.pointerId]) down[ev.pointerId] = p;
            if (pinch && Object.keys(down).length === 2) {
                var ids = Object.keys(down), a = down[ids[0]], b = down[ids[1]];
                var f = pinch.d / (Math.abs(a.x - b.x) || 1), span = (pinch.v[1] - pinch.v[0]) * f;
                var k = (pinch.c - pinch.v[0]) / (pinch.v[1] - pinch.v[0]);
                view = clampView(pinch.c - k * span, pinch.c - k * span + span);
                render();
                return;
            }
            if (drag && drag.id === ev.pointerId) {
                if (Math.abs(p.x - drag.x) > 6) { drag.moved = true; hide(); }
                if (drag.moved) { brush(drag.x, p.x); return; }
            }
            if (ev.pointerType === 'mouse' && !drag) {
                var i = nearest(p);
                if (i >= 0) show(i);
            }
        });
        function up(ev) {
            if (!svg) return;
            var p = at(ev);
            delete down[ev.pointerId];
            if (pinch) { if (!Object.keys(down).length) pinch = null; return; }
            if (!drag || drag.id !== ev.pointerId) return;
            var d = drag;
            drag = null;
            brush(null);
            if (d.moved && Math.abs(p.x - d.x) > 10) {
                var a = tAt(Math.min(p.x, d.x)), b = tAt(Math.max(p.x, d.x));
                setView(a, b);
            } else if (ev.type === 'pointerup') {
                var i = nearest(p);
                if (i >= 0) show(i);
            }
        }
        plot.addEventListener('pointerup', up);
        plot.addEventListener('pointercancel', up);
        plot.addEventListener('pointerleave', function (ev) { if (ev.pointerType === 'mouse' && !drag) hide(); });
        plot.addEventListener('dblclick', function () { setView(full[0], full[1]); });
        // Ctrl + wheel, and a touchpad pinch (which the browser sends as one): zoom around the pointer.
        plot.addEventListener('wheel', function (ev) {
            if (!ev.ctrlKey || !svg) return;
            ev.preventDefault();
            zoomAt(Math.exp(ev.deltaY * 0.01), tAt(at(ev).x));
        }, { passive: false });
        document.addEventListener('pointerdown', function (ev) { if (!fig.contains(ev.target)) hide(); });

        fig.addEventListener('click', function (ev) {
            var b = ev.target.closest && ev.target.closest('.bk-chart-b');
            if (!b || b.disabled) return;
            var a = b.getAttribute('data-act'), span = view[1] - view[0], mid = (view[0] + view[1]) / 2;
            if (a === 'in') zoomAt(0.5, mid);
            else if (a === 'out') zoomAt(2, mid);
            else if (a === 'prev') setView(view[0] - span / 2, view[1] - span / 2);
            else if (a === 'next') setView(view[0] + span / 2, view[1] + span / 2);
            else setView(full[0], full[1]);
        });

        var pending = false;
        window.addEventListener('resize', function () {
            if (pending) return;
            pending = true;
            window.requestAnimationFrame(function () { pending = false; render(); });
        });
        render();
    });
})();
