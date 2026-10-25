/**
 * The layout of a qualification scorecard, as ScorePDF::DrawScoreNew() draws it, and
 * its fitting onto the lines found in a photo.
 *
 * Widths are in cells (CellW): the table is always perLine + 5 cells wide, and its
 * rows are CellH high (header, one row per printed line, total row). Only one option
 * of the core's printout changes the grid: the grand total column of "Complete +
 * totals" (PrintTotalColumns), which narrows the arrow and total cells. It cannot be
 * known in advance, so both layouts are tried against the photo.
 *
 * Browser and Node alike, no dependency.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.OcrLayout = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /**
     * How the core prints the ends of a distance: six-arrow ends are split over two
     * lines of three when there are at most seven of them, and one-arrow ends are
     * grouped three (or five) to a line.
     */
    function printed(ends, arrows, tourType) {
        var perLine = arrows, lines = ends;
        if (perLine === 6 && lines <= 7) {
            perLine = 3;
            lines *= 2;
        }
        if (perLine === 1 && lines % 3 === 0) {
            perLine = 3;
            lines /= 3;
        } else if (perLine === 1 && lines % 5 === 0 && tourType !== 49) {
            perLine = 5;
            lines /= 5;
        }
        return {ends: ends, arrows: arrows, perLine: perLine, lines: lines};
    }

    /** Does printed line i (1-based) close an end, i.e. carry the end and running totals? */
    function closesEnd(p, i) {
        return (p.perLine * i) % p.arrows === 0;
    }

    /**
     * Columns of the grid, left to right, for one variant of the printout.
     *
     * @param {Object} p Output of printed().
     * @param {boolean} grand With the grand total column (PrintTotalColumns).
     * @param {boolean} golds Two count columns (10 and X); one when both labels are equal.
     */
    function columns(p, grand, golds) {
        var A = p.arrows, N = p.perLine;
        var arW = grand ? 0.9 : 1, totW = grand ? 1 : 1.4, countW = golds ? 0.7 : 1.4;
        var cols = [{key: 'num', x0: 0, x1: 0.8}], x = 0.8, j;
        for (j = 0; j < N; j++) {
            var w = arW + (N === 1 ? totW : 0);
            cols.push({key: 'a' + j, kind: 'arrow', index: j, x0: x, x1: x + w});
            x += w;
        }
        if (N > 1) {
            var lw = totW * (A >= 6 ? 0.75 : 1);
            cols.push({key: 'line', kind: 'sum', x0: x, x1: x + lw});
            x += lw;
        }
        // On the line that closes an end: the end total (six arrows and more only) and
        // the running total. On the other lines: one crossed-out cell.
        var ew = totW * (A >= 6 ? 1.25 : 1);
        cols.push({key: 'endcum', kind: 'sum', x0: x, x1: x + ew, split: A >= 6 ? x + totW * 0.5 : null});
        x += ew;
        if (grand) {
            cols.push({key: 'grand', kind: 'sum', x0: x, x1: x + 1.1});
            x += 1.1;
        }
        if (golds) {
            cols.push({key: 'gold', kind: 'count', x0: x, x1: x + countW});
            x += countW;
        }
        cols.push({key: 'xnine', kind: 'count', x0: x, x1: x + countW});
        x += countW;
        return {cols: cols, width: x};
    }

    /** Length of the part of a line's segments that falls between a and b. */
    function within(segs, a, b) {
        return segs.reduce(function (s, g) { return s + Math.max(0, Math.min(b, g[1]) - Math.max(a, g[0])); }, 0);
    }

    /**
     * Match predicted positions to detected ones (both in cells), within tol.
     * Returns, for each predicted position, the index of its detected line or -1.
     */
    function match(pred, found, tol) {
        var used = {};
        return pred.map(function (x) {
            var best = -1, bd = tol;
            found.forEach(function (f, i) {
                var d = Math.abs(f - x);
                if (d <= bd && !used[i]) {
                    bd = d;
                    best = i;
                }
            });
            if (best >= 0) used[best] = true;
            return best;
        });
    }

    function hits(m) {
        return m.filter(function (i) { return i >= 0; }).length;
    }

    /**
     * Linear map found ~ a + b * model that puts the most model positions within tol
     * (in model units) of a found position. Tried from every pair of correspondences,
     * then refined by least squares on the matches. b must stay near bHint.
     * The frame's outer lines are usually the table's, but a missed one moves them by
     * a row or a column: the map absorbs that.
     */
    function fit1D(model, found, tol, bHint, bonus) {
        var best = null, i, j, p, q;
        function consider(a, b) {
            var pred = model.map(function (x) { return a + b * x; }), m = match(pred, found, tol * b);
            var res = 0;
            m.forEach(function (k, n) { if (k >= 0) res += Math.abs(found[k] - pred[n]); });
            var h = hits(m), s = h + (bonus ? bonus(a, b) : 0);
            if (!best || s > best.s || (s === best.s && res < best.res)) best = {a: a, b: b, map: m, hits: h, res: res, s: s};
        }
        for (i = 0; i < found.length; i++) {
            for (j = i + 1; j < found.length; j++) {
                for (p = 0; p < model.length; p++) {
                    for (q = p + 1; q < model.length; q++) {
                        var b = (found[j] - found[i]) / (model[q] - model[p]);
                        if (b > 0.6 * bHint && b < 1.6 * bHint) consider(found[i] - b * model[p], b);
                    }
                }
            }
        }
        if (!best) return {a: 0, b: bHint, map: model.map(function () { return -1; }), hits: 0, res: 0};
        // Least squares on the matched pairs.
        var n = 0, sx = 0, sy = 0, sxx = 0, sxy = 0;
        best.map.forEach(function (k, idx) {
            if (k < 0) return;
            n++;
            sx += model[idx];
            sy += found[k];
            sxx += model[idx] * model[idx];
            sxy += model[idx] * found[k];
        });
        if (n >= 2 && n * sxx - sx * sx > 1e-9) {
            var bb = (n * sxy - sx * sy) / (n * sxx - sx * sx);
            consider((sy - bb * sx) / n, bb);
        }
        return best;
    }

    /** Score of one variant of the layout against the lines of a straightened frame. */
    function evaluate(frame, p, grand, golds) {
        var m = columns(p, grand, golds), units = m.width, W = frame.W, Hb = frame.Hb, L = p.lines;
        var N1 = p.perLine;   // index in m.cols of the last arrow column

        // Columns: borders crossing the body, and the end/running total split (on some lines only).
        var vl = frame.vlines.filter(function (l) { return within(l.segs, 0, Hb) >= 0.35 * Hb; });
        var vx = vl.map(function (l) { return l.u; });
        var required = [0], optional = [];
        m.cols.forEach(function (c) {
            if (c.x0 > 0) required.push(c.x0);
            if (c.split) optional.push(c.split);
        });
        required.push(units);
        var fc = fit1D(required, vx, 0.12, W / units);
        var mo = match(optional.map(function (x) { return fc.a + fc.b * x; }), vx, 0.12 * fc.b);
        var extra = vx.length - fc.hits - hits(mo);
        var colScore = (fc.hits - 0.4 * Math.max(0, extra)) / required.length;

        // Rows: the lines across the table, header top to body bottom; then two partial
        // lines that only the right alignment and the right way up both find: the
        // half-height line of the header over the totals, the bottom of the total row.
        var hl = frame.hlines.filter(function (l) { return l.cov >= 0.6 * W; });
        var hy = hl.map(function (l) { return l.v; });
        var rows = [];
        for (var i = 0; i <= L + 1; i++) rows.push(i);
        var partial = frame.hlines.filter(function (l) { return l.cov >= 0.12 * W && l.cov < 0.6 * W; });
        var py = partial.map(function (l) { return l.v; });

        // Where the header is, from the columns: the arrow columns' lines start at the
        // header top, the table's left border only at the first end. Evenly spaced rows
        // alone cannot tell a missed header line from a missed body line.
        var rowH = frame.rowH, anchors = [];
        function top(l) {
            var t = Infinity;
            l.segs.forEach(function (s) { if (s[1] - s[0] >= 0.4 * rowH && s[0] < t) t = s[0]; });
            return t;
        }
        var arrowTops = [];
        required.forEach(function (x, k) {
            var l = fc.map[k] >= 0 ? vl[fc.map[k]] : null;
            if (!l) return;
            if (x === 0) {
                var t0 = top(l);
                if (isFinite(t0)) anchors.push({y: 1, v: t0});
            } else if (x <= m.cols[N1].x1 + 1e-6) {
                arrowTops.push(top(l));
            }
        });
        arrowTops = arrowTops.filter(isFinite).sort(function (x, y) { return x - y; });
        if (arrowTops.length) anchors.push({y: 0, v: arrowTops[Math.floor(arrowTops.length / 2)]});
        var rowBonus = function (a, b) {
            var s = 0;
            anchors.forEach(function (an) { if (Math.abs(a + b * an.y - an.v) < 0.3 * b) s += 0.6; });
            [0.5, L + 2].forEach(function (y) {
                if (py.some(function (v) { return Math.abs(v - (a + b * y)) < 0.2 * b; })) s += 0.4;
            });
            return s;
        };
        var fr = fit1D(rows, hy, 0.22, rowH, rowBonus);
        var mp = match([0.5, L + 2].map(function (y) { return fr.a + fr.b * y; }), py, 0.2 * fr.b);
        var rowScore = (fr.hits - 0.4 * Math.max(0, hy.length - fr.hits)) / rows.length;

        var ux = {};
        required.forEach(function (x, k) { ux[x.toFixed(4)] = fc.map[k] >= 0 ? vx[fc.map[k]] : fc.a + fc.b * x; });
        optional.forEach(function (x, k) { ux[x.toFixed(4)] = mo[k] >= 0 ? vx[mo[k]] : fc.a + fc.b * x; });
        var vy = rows.map(function (y, k) { return fr.map[k] >= 0 ? hy[fr.map[k]] : fr.a + fr.b * y; });

        return {
            grand: grand, golds: golds, model: m, printed: p, units: units,
            colScore: colScore, rowScore: rowScore, partials: hits(mp),
            score: Math.min(colScore, rowScore) + (colScore + rowScore) / 10 + 0.05 * hits(mp),
            u: function (x) {
                var k = x.toFixed(4);
                return k in ux ? ux[k] : fc.a + fc.b * x;
            },
            v: vy, rowStep: fr.b,
            // What the table's lines say of the rows put k lines further (as fit1D weighs them).
            rowEvidence: function (k) {
                var a = fr.a + k * fr.b;
                return hits(match(rows.map(function (y) { return a + fr.b * y; }), hy, 0.22 * fr.b)) + rowBonus(a, fr.b);
            }
        };
    }

    /**
     * Fit the expected layout of an archer's distance onto an analysed photo.
     *
     * Both variants of the printout, in each orientation of the card unless the barcode
     * already told which way up it is.
     *
     * @param {Object} V The vision module.
     * @param {Object} a Analysis of V.analyse().
     * @param {Object} s {ends, arrows, golds (two count columns), tourType, dist}.
     * @param {number} [turn] Orientation known from the barcode.
     * @return {Object} Best variant, with ok when the photo matches it, its turn and its cells.
     */
    function fit(V, a, s, turn) {
        var p = printed(s.ends, s.arrows, s.tourType || 0);
        var ts = turn === undefined || turn === null ? V.turns(a) : [turn];
        var best = null;
        ts.forEach(function (t) {
            var f = V.frame(a, t);
            if (!f) return;
            [false, true].forEach(function (grand) {
                var e = evaluate(f, p, grand, s.golds !== false);
                e.turn = t;
                e.frame = f;
                if (!best || e.score > best.score) best = e;
            });
        });
        if (!best) return {ok: false};
        best.ok = best.colScore >= 0.7 && best.rowScore >= 0.7;
        best.first = s.dist === 1;
        // A shift of the rows asked for (s.shift: a bench), else told by the cells the core
        // crosses out (the end and running total's cell on the lines that do not close an
        // end): a grid set a line off has its sums agree all the same, its crossed cells
        // tell. Not taken here: given as best.alt, to read when the reading as found is
        // sound (on cards the layouts do not model, the crosses are looked for in the wrong
        // place, and a reading in doubt is told so anyway).
        if (s.shift) shiftRows(best, s.shift);
        // The grey cells the core prints tell a grid set a line off, and which way: the rows
        // are moved at once (unless a shift was asked for).
        best.greyScores = greyScores(V, a.work, best);
        var kg = s.shift === undefined ? greyShift(best.greyScores) : 0;
        if (kg) shiftRows(best, kg);
        best.cells = cells(best);
        best.marks = marks(best);
        best.splitScores = splitScores(best);
        var k = s.shift === undefined ? crossShift(best) : 0;
        if (k) {
            best.alt = shiftRows(Object.assign({}, best), k);
            best.alt.cells = cells(best.alt);
            best.alt.marks = marks(best.alt);
            // The crosses alternate line by line, and the table's lines may tell up from down
            // only barely: the other way, to read when the first is no better.
            best.alt2 = shiftRows(Object.assign({}, best), -k);
            best.alt2.cells = cells(best.alt2);
            best.alt2.marks = marks(best.alt2);
        }
        return best;
    }

    /** The rows of a fit moved k lines (up for -1). -> the fit. */
    function shiftRows(e, k) {
        var v = e.v, step = e.rowStep;
        e.v = v.map(function (x, j) { return j + k >= 0 && j + k < v.length ? v[j + k] : v[0] + (j + k) * step; });
        e.shifted = k;
        return e;
    }

    // Crossed cells on the lines that do not close an end, none on those that do: on the
    // photos, the rows found score +0.10 to +0.45, a line off as much below 0.
    var CROSS_GAIN = 0.15;   // a shift of the rows putting the crosses this much better where printed…
    var CROSS_MIN = 0.06;    // ...and where printed for sure (the rows found putting them elsewhere)

    /** How far both diagonals of a frame's rectangle lie on the grid's mask (0-1, the lesser). */
    function crossed(mask, u0, v0, u1, v1) {
        var mx = 0.15 * (u1 - u0), my = 0.15 * (v1 - v0), n = 24, hit = [0, 0];
        u0 += mx;
        u1 -= mx;
        v0 += my;
        v1 -= my;
        for (var i = 0; i < n; i++) {
            var t = i / (n - 1);
            [[u0 + t * (u1 - u0), v0 + t * (v1 - v0)], [u0 + t * (u1 - u0), v1 - t * (v1 - v0)]].forEach(function (q, d) {
                var x = Math.round(q[0]) + mask.ox, y = Math.round(q[1]) + mask.oy, on = false;
                for (var dy = -1; dy <= 1 && !on; dy++) {
                    for (var dx = -1; dx <= 1 && !on; dx++) {
                        var xx = x + dx, yy = y + dy;
                        if (xx >= 0 && yy >= 0 && xx < mask.w && yy < mask.h && mask.d[yy * mask.w + xx]) on = true;
                    }
                }
                if (on) hit[d]++;
            });
        }
        return Math.min(hit[0], hit[1]) / n;
    }

    /**
     * Rows one line up (-1) or down (+1) that put the crossed cells where the core prints
     * them, clearly better than the rows found; else 0.
     */
    function crossShift(e) {
        var p = e.printed, m = e.frame && e.frame.mask, col = e.model.cols.filter(function (c) { return c.key === 'endcum'; })[0];
        if (!m || !col || p.arrows <= p.perLine) return 0;
        var u0 = e.u(col.x0), u1 = e.u(col.x1), L = p.lines, v = e.v, step = e.rowStep;
        var at = function (j) { return j >= 0 && j < v.length ? v[j] : v[0] + j * step; };
        var score = function (k) {
            var s = 0, n = 0;
            for (var i = 1; i <= L; i++) {
                var c = crossed(m, u0, at(i + k), u1, at(i + k + 1));
                s += closesEnd(p, i) ? -c : c;
                n++;
            }
            return s / n;
        };
        var s0 = score(0), best = 0, bs = s0;
        e.crossScores = [score(-1), s0, score(1)];
        // The crosses alternate line by line: a line up or down puts them alike; which, the
        // table's lines tell (then the crosses at the ends).
        var ev = {'-1': e.rowEvidence ? e.rowEvidence(-1) : 0, '1': e.rowEvidence ? e.rowEvidence(1) : 0};
        [-1, 1].forEach(function (k) {
            var s = score(k);
            if (!best || ev[k] > ev[best] || ev[k] === ev[best] && s > bs) {
                if (s > s0) {
                    bs = s;
                    best = k;
                }
            }
        });
        // The crosses where the rows found put them: nothing to do. Elsewhere, only when
        // they clearly read so.
        return best && s0 < 0 && bs >= CROSS_MIN && bs - s0 >= CROSS_GAIN ? best : 0;
    }

    /** How far a vertical line of the frame (at u, from v0 to v1) lies on the grid's mask (0-1). */
    function ruled(mask, u, v0, v1) {
        var my = 0.2 * (v1 - v0), n = 12, hit = 0;
        for (var i = 0; i < n; i++) {
            var x = Math.round(u) + mask.ox, y = Math.round(v0 + my + (v1 - v0 - 2 * my) * i / (n - 1)) + mask.oy, on = false;
            for (var dx = -1; dx <= 1 && !on; dx++) {
                var xx = x + dx;
                if (xx >= 0 && y >= 0 && xx < mask.w && y < mask.h && mask.d[y * mask.w + xx]) on = true;
            }
            if (on) hit++;
        }
        return hit / n;
    }

    /**
     * The line that splits the end and running totals, printed on the lines that close an
     * end only (the others have that cell crossed out): for the rows found and one line up
     * and down, how far the lines say so (-1 to 1); null without such a split.
     */
    function splitScores(e) {
        var p = e.printed, m = e.frame && e.frame.mask, col = e.model.cols.filter(function (c) { return c.key === 'endcum'; })[0];
        if (!m || !col || !col.split || p.arrows <= p.perLine) return null;
        var u = e.u(col.split), L = p.lines, v = e.v, step = e.rowStep;
        var at = function (j) { return j >= 0 && j < v.length ? v[j] : v[0] + j * step; };
        return [-1, 0, 1].map(function (k) {
            var s = 0;
            for (var i = 1; i <= L; i++) {
                var r = ruled(m, u, at(i + k), at(i + k + 1));
                s += closesEnd(p, i) ? r : -r;
            }
            return s / L;
        });
    }

    // greyScores() of the rows found, on the photos (CAM2: 266 grids, 32 a line off): +0.10
    // to +0.25 where the core prints the grey cells, about 0 or below a line off; a grid a
    // line off scores at most 0.05 as found and 0.09 or more a line over. 30 of the 32 told
    // the right way, none of the grids read soundly moved.
    var GREY_MIN = 0.07;     // the rows a line over, at least this...
    var GREY_AT = 0.06;      // ...the rows found at most this (a sound grid scores more)...
    var GREY_GAIN = 0.06;    // ...this much better than as found...
    var GREY_SIDE = 0.02;    // ...and than the other way
    var GREY_MAX = 0.3;      // beyond, no print's grey (a shadow, the sheet's edge): nothing told

    /** The shift (-1, 1) the grey cells clearly tell, else 0. */
    function greyShift(g) {
        if (!g || g.some(function (x) { return Math.abs(x) > GREY_MAX; })) return 0;
        var k = g[0] > g[2] ? 0 : 2, o = 2 - k;
        return g[k] >= GREY_MIN && g[1] <= GREY_AT && g[k] - g[1] >= GREY_GAIN && g[k] - g[o] >= GREY_SIDE ? k - 1 : 0;
    }

    /** Background grey of a part of the frame: the 80th percentile of its inner points (ink left out). */
    function background(V, img, H, u0, v0, u1, v1, nu) {
        var s = [], mu = 0.2 * (u1 - u0), mv = 0.2 * (v1 - v0), i, j;
        for (i = 0; i < nu; i++) {
            for (j = 0; j < 4; j++) {
                var p = V.apply(H, u0 + mu + (u1 - u0 - 2 * mu) * i / (nu - 1), v0 + mv + (v1 - v0 - 2 * mv) * j / 3), g = V.sample(img, p.x, p.y);
                if (!isNaN(g)) s.push(g);
            }
        }
        if (s.length < 4) return NaN;
        s.sort(function (x, y) { return x - y; });
        return s[Math.floor(0.8 * (s.length - 1))];
    }

    /**
     * The core fills grey the header row's arrow cells and the end numbers' column, the
     * header's distance cell lighter: for the rows found and one line up and down, how far
     * the photo shows that (header row darker than the first line under it over the arrows,
     * the first line darker than the header in the numbers' column).
     */
    function greyScores(V, img, e) {
        var f = e.frame, p = e.printed, L = p.lines, v = e.v, step = e.rowStep;
        if (!f || !f.H || !img) return null;
        var cols = e.model.cols, num = cols[0], arrows = cols.filter(function (c) { return c.kind === 'arrow'; });
        var au0 = e.u(arrows[0].x0), au1 = e.u(arrows[arrows.length - 1].x1), nu0 = e.u(num.x0), nu1 = e.u(num.x1);
        var at = function (j) { return j >= 0 && j < v.length ? v[j] : j < 0 ? v[0] + j * step : v[v.length - 1] + (j - v.length + 1) * step; };
        var na = 4 * arrows.length + 2, memo = {};
        var band = function (j, which) {
            var key = which + j;
            if (!(key in memo)) {
                memo[key] = which === 'a' ? background(V, img, f.H, au0, at(j), au1, at(j + 1), na)
                    : background(V, img, f.H, nu0, at(j), nu1, at(j + 1), 4);
            }
            return memo[key];
        };
        var body = [];
        for (var j = 1; j <= L; j++) body.push(band(j, 'a'));
        body = body.filter(function (x) { return !isNaN(x); }).sort(function (x, y) { return x - y; });
        if (!body.length) return null;
        var white = body[body.length >> 1], dark = function (j, which) {
            var b = band(j, which);
            return isNaN(b) ? 0 : 1 - b / white;
        };
        return [-1, 0, 1].map(function (k) {
            return dark(k, 'a') - dark(k + 1, 'a') + dark(k + 1, 'n') - dark(k, 'n');
        });
    }

    /**
     * Every cell to read, in the straightened frame: arrows and totals of each printed
     * line, and the total row. Crossed-out cells are left out.
     */
    function cells(e) {
        var p = e.printed, out = [], L = p.lines, v = e.v, u = e.u;
        e.model.cols.forEach(function (c) {
            if (c.key === 'num') return;
            for (var i = 1; i <= L; i++) {
                var row = {line: i, v0: v[i], v1: v[i + 1]}, closes = closesEnd(p, i);
                if (c.key === 'endcum') {
                    if (!closes) continue;
                    if (c.split) {
                        out.push(cell(row, 'end', 'sum', u(c.x0), u(c.split)));
                        out.push(cell(row, 'cum', 'sum', u(c.split), u(c.x1)));
                    } else {
                        out.push(cell(row, 'cum', 'sum', u(c.x0), u(c.x1)));
                    }
                } else if (c.key === 'grand') {
                    // At the first distance the grand total is the running total: the
                    // core crosses its cells out on a blank card.
                    if (closes && !e.first) out.push(cell(row, 'grand', 'sum', u(c.x0), u(c.x1)));
                } else {
                    var k = cell(row, c.key, c.kind, u(c.x0), u(c.x1));
                    if (c.kind === 'arrow') k.arrow = (i - 1) * p.perLine + c.index;
                    out.push(k);
                }
            }
        });
        // Total row, under the running total and the count columns.
        var tv0 = v[L + 1], tv1 = v[L + 1] + e.rowStep;
        e.model.cols.forEach(function (c) {
            var row = {line: 'total', v0: tv0, v1: tv1};
            if (c.key === 'endcum') out.push(cell(row, 'cum', 'sum', u(c.split || c.x0), u(c.x1)));
            if ((c.key === 'grand' && !e.first) || c.key === 'gold' || c.key === 'xnine') out.push(cell(row, c.key, c.kind === 'count' ? 'count' : 'sum', u(c.x0), u(c.x1)));
        });
        return out;
    }

    /**
     * The cells the core prints grey, shown apart: the arrows' numbers over the table, the
     * ends' numbers down its left (one cell per end, over its lines).
     */
    function marks(e) {
        var p = e.printed, v = e.v, u = e.u, out = [], num = e.model.cols[0], per = p.lines / p.ends;
        e.model.cols.forEach(function (c) {
            if (c.kind === 'arrow') out.push({u0: u(c.x0), u1: u(c.x1), v0: v[0], v1: v[1]});
        });
        var step = per >= 1 ? per : 1;
        for (var i = 1; i + step <= p.lines + 1; i += step) out.push({u0: u(num.x0), u1: u(num.x1), v0: v[i], v1: v[i + step]});
        return out;
    }

    function cell(row, key, kind, u0, u1) {
        return {line: row.line, key: key, kind: kind, u0: u0, u1: u1, v0: row.v0, v1: row.v1};
    }

    /**
     * The numbers a cell can hold, as intervals [[from, to]…] (null when nothing bounds
     * it: an arrow, the grand total). A sum is one that so many arrows of the archer's
     * own face can make (a line's, an end's, the running total after the arrows shot so
     * far): a face scoring 6 to 10 makes no 1 to 5 with three arrows, nor 11. A line's sum
     * cell, on an end shot over several lines, may hold the end's total instead (archers
     * write it there). A count is up to the arrows it counts, none when the face has no
     * such value.
     *
     * @param {Object} c A cell (cells()).
     * @param {Object} p Output of printed().
     * @param {Array} values The values of the archer's face at that distance: [{n, g, x}].
     */
    function cellAllowed(c, p, values) {
        var all = c.line === 'total', arrows = all ? p.ends * p.arrows : c.line * p.perLine;
        if (c.key === 'gold' || c.key === 'xnine') {
            var counted = values.some(function (v) { return c.key === 'gold' ? v.g : v.x; });
            return [[0, counted ? (all ? arrows : p.perLine) : 0]];
        }
        var ns = values.map(function (v) { return v.n; });
        if (c.key === 'line' && p.arrows > p.perLine) return union(sums(p.perLine, ns), sums(p.arrows, ns));
        var n = c.key === 'line' ? p.perLine : c.key === 'end' ? p.arrows : c.key === 'cum' ? arrows : 0;
        return n ? sums(n, ns) : null;
    }

    /** Two lists of intervals as one. */
    function union(a, b) {
        var all = a.concat(b).map(function (r) { return r.slice(); }).sort(function (x, y) { return x[0] - y[0]; }), out = [];
        all.forEach(function (r) {
            var last = out[out.length - 1];
            if (last && r[0] <= last[1] + 1) last[1] = Math.max(last[1], r[1]);
            else out.push(r);
        });
        return out;
    }

    var sumsKept = {};

    /** The totals n arrows can make from these values, as intervals. */
    function sums(n, ns) {
        var key = n + ':' + ns.slice().sort(function (a, b) { return a - b; }).join(',');
        if (sumsKept[key]) return sumsKept[key];
        var top = Math.max.apply(null, ns.concat([0])), can = new Uint8Array(n * top + 1);
        can[0] = 1;
        for (var k = 0; k < n; k++) {
            var next = new Uint8Array(can.length);
            for (var s = 0; s < can.length; s++) {
                if (!can[s]) continue;
                for (var i = 0; i < ns.length; i++) if (s + ns[i] < can.length) next[s + ns[i]] = 1;
            }
            can = next;
        }
        var out = [];
        for (var v = 0; v < can.length; v++) {
            if (!can[v]) continue;
            if (out.length && out[out.length - 1][1] === v - 1) out[out.length - 1][1] = v;
            else out.push([v, v]);
        }
        return (sumsKept[key] = out);
    }

    /**
     * How many ends of a card were shot, from a few of its arrow cells: an end whose first
     * two arrows are empty was not entered, nor any after it. The first two arrows of the
     * first end empty, those of the next end and the card's last two too: nothing was shot
     * (six cells tell). Likewise after any end.
     *
     * @param {Object} p Output of printed().
     * @param {Function} state (arrow index, 0-based) -> 'empty', 'written' or null (not read
     *     surely yet).
     * @return {Object} {ends: ends shot, or null while unknown; probes: arrow indices to read
     *     to tell (first those still unknown)}
     */
    function shotEnds(p, state) {
        var A = p.arrows, E = p.ends, total = A * E, last = [total - 2, total - 1].filter(function (i) { return i >= 0; });
        var two = function (e) { return [e * A, e * A + 1].filter(function (i) { return i < (e + 1) * A; }); };
        var is = function (list, what) { return list.every(function (i) { return state(i) === what; }); };
        var known = function (list) { return list.every(function (i) { return state(i) !== null; }); };
        var probes = [];
        for (var e = 0; e < E; e++) {
            var first = two(e);
            probes = probes.concat(first);
            if (!known(first)) return {ends: null, probes: probes.concat(last)};
            if (!is(first, 'empty')) continue;
            // Not entered: told by the next end and the card's last arrows too.
            var check = (e + 1 < E ? two(e + 1) : []).concat(last.filter(function (i) { return i >= e * A + 2; }));
            probes = probes.concat(check);
            if (!known(check)) return {ends: null, probes: probes};
            return {ends: is(check, 'empty') ? e : E, probes: probes};
        }
        return {ends: E, probes: probes};
    }

    /** Can a cell hold this number? (allowed: cellAllowed()'s, null: any) */
    function canHold(allowed, v) {
        if (!allowed) return true;
        for (var i = 0; i < allowed.length; i++) if (v >= allowed[i][0] && v <= allowed[i][1]) return true;
        return false;
    }

    /**
     * The way from a grid's bottom to its header in the image (a unit vector), its frame
     * turned t: the cards of a sheet all face one way (header up, total row down).
     */
    function upOf(V, a, t) {
        var q = V._internal.turnQuad(a.quad, t);
        var x = (q.tl.x + q.tr.x - q.bl.x - q.br.x) / 2, y = (q.tl.y + q.tr.y - q.bl.y - q.br.y) / 2, d = Math.hypot(x, y);
        return d ? {x: x / d, y: y / d} : null;
    }

    /** The turn of a grid facing the way up given (a vector of the image), within 45 degrees; else null. */
    function turnFacing(V, a, up) {
        var best = null, bd = Math.SQRT1_2;
        [0, 1, 2, 3].forEach(function (t) {
            var u = upOf(V, a, t), d = u ? u.x * up.x + u.y * up.y : -1;
            if (d > bd) {
                bd = d;
                best = t;
            }
        });
        return best;
    }

    /**
     * Fits of the grids of one picture made to face one way: the way most of them face
     * (weighed by their scores, and w(i) if given), the others fitted again that way, or
     * dropped (null) when they cannot be. -> the fits, in order.
     */
    function oneWay(V, grids, fits, refit, w) {
        var sx = 0, sy = 0;
        fits.forEach(function (f, i) {
            var u = f && f.ok ? upOf(V, grids[i], f.turn) : null;
            if (!u) return;
            var k = Math.max(0.1, f.score) * (w ? w(i) : 1);
            sx += k * u.x;
            sy += k * u.y;
        });
        var d = Math.hypot(sx, sy);
        if (!d) return fits;
        var up = {x: sx / d, y: sy / d};
        return fits.map(function (f, i) {
            if (!f || !f.ok) return f;
            var u = upOf(V, grids[i], f.turn);
            if (u && u.x * up.x + u.y * up.y > Math.SQRT1_2) return f;
            var t = turnFacing(V, grids[i], up), g = t === null ? null : refit(i, t);
            return g && g.ok ? g : null;
        });
    }

    return {printed: printed, closesEnd: closesEnd, columns: columns, fit: fit, cellAllowed: cellAllowed, canHold: canHold, shotEnds: shotEnds,
        upOf: upOf, turnFacing: turnFacing, oneWay: oneWay, _evaluate: evaluate};
});
