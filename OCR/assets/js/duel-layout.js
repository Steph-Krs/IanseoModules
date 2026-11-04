/**
 * The layout of one card of a duel's sheet, as the core's match printouts draw it
 * (Final/Individual and Final/Team PDFScoreMatch.php, Modules/RoundRobin/PdfScorecards.php),
 * and its fitting onto the lines found in a photo.
 *
 * Widths are in units of the card, 133.5 mm wide on every printout: 15 units for an
 * individual or a round robin card, 18 for a bracket's team card, one more for each count
 * column. Left to right: the end's number 1, the arrows 6 (10 on a team card) shared out, the
 * end's or set's total 3 (2.5), then in sets the set points 2 (three cells, 2 1 0 printed) and
 * the running set points 3 (2.5), in points the running total 5 (4.5); the counts 1 each.
 * The rows are all as high (9 mm at most): the round's title (with the Winner box), the
 * arrows' numbers, then one row an end; the title's and numbers' rows leave the end column out.
 * Under the table: the total's box (under the last column, against the table), and apart the
 * shoot-off (1 or 3 lines of boxes, with their totals on a team card of several arrows) and
 * the closest-to-the-centre box.
 *
 * Browser and Node alike; OcrLayout (layout.js) gives the fitting of a model onto lines.
 */
(function (root, factory) {
    var api = factory(typeof module === 'object' && module.exports ? require('./layout.js') : root.OcrLayout);
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.OcrDuelLayout = api;
})(typeof self !== 'undefined' ? self : this, function (Lay) {
    'use strict';

    var CARD_MM = 133.5;   // a card's width on the printout (an A4 landscape, two cards, 10 mm margins)
    var ROW_MM = 9;        // its rows' height (the printouts' ScoreCellHeight, at most: their usual)
    var COMP_MM = 3;       // a team card's column of its archers' letters, inside each arrow cell

    /** A bracket's team card (18 units); a round robin's team card is drawn as an individual one. */
    function teamCard(s) {
        return !!s.team && !s.robin;
    }

    /**
     * Columns of a card, left to right (units).
     *
     * @param {Object} s The card's spec (api/duel.php): {mode (1 sets, 0 points), arrows,
     *     golds, xnine, team, robin}.
     */
    function columns(s) {
        // direct: an older print's card in sets, the running total of the arrows ("Total (direct)")
        // after the set's total; the widths measured on 2018-2019 sheets, in the units of the end
        // numbers' column.
        var team = teamCard(s), old = s.mode && s.direct, A = team ? (old ? 9.3 : 10) : 6, T = team ? 2.5 : 3;
        var tl = old ? (team ? 2.5 : 2.4) : T, tr = old ? 1.2 : T, N = s.arrows, aw = A / N, cols = [], x = 1, j;
        cols.push({key: 'num', x0: 0, x1: 1});
        for (j = 0; j < N; j++) cols.push({key: 'a' + j, kind: 'arrow', index: j, x0: x + j * aw, x1: x + (j + 1) * aw});
        x += A;
        // The end's or set's total read as a qualification line's sum (key 'line'), the running
        // total as its running total: the same checks choose the arrows.
        cols.push({key: 'line', kind: 'sum', x0: x, x1: x + tl});
        x += tl;
        if (s.mode) {
            if (old) {
                cols.push({key: 'cum', kind: 'sum', x0: x, x1: x + tl});
                x += tl;
            }
            cols.push({key: 'pick', kind: 'pick', x0: x, x1: x + 2, picks: [x, x + 2 / 3, x + 4 / 3, x + 2]});
            x += 2;
            cols.push({key: 'run', kind: 'sum', x0: x, x1: x + tr});
            x += tr;
        } else {
            cols.push({key: 'cum', kind: 'sum', x0: x, x1: x + T + 2});
            x += T + 2;
        }
        if (s.golds) {
            cols.push({key: 'gold', kind: 'count', x0: x, x1: x + 1});
            x += 1;
        }
        if (s.xnine) {
            cols.push({key: 'xnine', kind: 'count', x0: x, x1: x + 1});
            x += 1;
        }
        return {cols: cols, width: x, A: A, T: T, aw: aw};
    }

    /** Length of the part of a line's segments that falls between a and b. */
    function within(segs, a, b) {
        return segs.reduce(function (s, g) { return s + Math.max(0, Math.min(b, g[1]) - Math.max(a, g[0])); }, 0);
    }

    function hits(m) {
        return m.filter(function (i) { return i >= 0; }).length;
    }

    /** Positions matched to found ones within tol: the index of each one's line, or -1. */
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

    /**
     * Score of a card's layout against the lines of a straightened frame (as
     * OcrLayout's evaluate() does for a qualification card).
     */
    function evaluate(frame, s) {
        var m = columns(s), units = m.width, W = frame.W, Hb = frame.Hb, E = s.ends, fit1D = Lay._fit1D;

        // Columns: the borders crossing the ends' rows (the set points' three cells too).
        var vl = frame.vlines.filter(function (l) { return within(l.segs, 0, Hb) >= 0.35 * Hb; });
        var vx = vl.map(function (l) { return l.u; });
        var required = [];
        m.cols.forEach(function (c) {
            if (required.indexOf(c.x0) < 0) required.push(c.x0);
            (c.picks || []).slice(1, 3).forEach(function (p) { required.push(p); });
        });
        required.push(units);
        var fc = fit1D(required, vx, 0.1, W / units);
        var colScore = (fc.hits - 0.4 * Math.max(0, vx.length - fc.hits)) / required.length;
        // A team card's arrows may carry their archers' letters (a print option): a line in each
        // arrow cell, COMP_MM from its right.
        var compU = teamCard(s) ? COMP_MM * units / CARD_MM : 0, comp = false;
        if (compU) {
            var splits = m.cols.filter(function (c) { return c.kind === 'arrow'; }).map(function (c) { return fc.a + fc.b * (c.x1 - compU); });
            comp = hits(match(splits, vx, 0.08 * fc.b)) >= Math.max(1, s.arrows / 2);
        }

        // Rows: the title's top to the last end's bottom, all long lines; the total's box under
        // the last column, a partial line under the table (the right way up only).
        var hl = frame.hlines.filter(function (l) { return l.cov >= 0.6 * W; });
        var hy = hl.map(function (l) { return l.v; });
        var rows = [];
        for (var i = 0; i <= E + 2; i++) rows.push(i);
        var py = frame.hlines.filter(function (l) { return l.cov >= 0.1 * W && l.cov < 0.6 * W; }).map(function (l) { return l.v; });
        var rowH = frame.rowH, anchors = [];
        // The table's left border starts at the first end, the arrows' borders at the numbers' row.
        function top(l) {
            var t = Infinity;
            l.segs.forEach(function (sg) { if (sg[1] - sg[0] >= 0.4 * rowH && sg[0] < t) t = sg[0]; });
            return t;
        }
        var arrowTops = [];
        required.forEach(function (x, k) {
            var l = fc.map[k] >= 0 ? vl[fc.map[k]] : null;
            if (!l) return;
            if (x === 0) {
                var t0 = top(l);
                if (isFinite(t0)) anchors.push({y: 2, v: t0});
            } else if (x > 1 && x < 1 + m.A) {
                arrowTops.push(top(l));
            }
        });
        arrowTops = arrowTops.filter(isFinite).sort(function (a, b) { return a - b; });
        if (arrowTops.length) anchors.push({y: 1, v: arrowTops[arrowTops.length >> 1]});
        var rowBonus = function (a, b) {
            var sc = 0;
            anchors.forEach(function (an) { if (Math.abs(a + b * an.y - an.v) < 0.3 * b) sc += 0.6; });
            if (py.some(function (v) { return Math.abs(v - (a + b * (E + 3))) < 0.2 * b; })) sc += 0.4;
            return sc;
        };
        var fr = fit1D(rows, hy, 0.22, rowH, rowBonus);
        var mp = match([E + 3].map(function (y) { return fr.a + fr.b * y; }), py, 0.2 * fr.b);
        var rowScore = (fr.hits - 0.4 * Math.max(0, hy.length - fr.hits)) / rows.length;

        var ux = {};
        required.forEach(function (x, k) { ux[x.toFixed(4)] = fc.map[k] >= 0 ? vx[fc.map[k]] : fc.a + fc.b * x; });
        var vy = rows.map(function (y, k) { return fr.map[k] >= 0 ? hy[fr.map[k]] : fr.a + fr.b * y; });
        return {
            spec: s, model: m, units: units, comp: comp, compU: compU,
            colScore: colScore, rowScore: rowScore, partials: hits(mp),
            score: Math.min(colScore, rowScore) + (colScore + rowScore) / 10 + 0.05 * hits(mp),
            u: function (x) {
                var k = x.toFixed(4);
                return k in ux ? ux[k] : fc.a + fc.b * x;
            },
            unit: fc.b, v: vy, rowStep: fr.b
        };
    }

    /**
     * A card's layout fitted onto an analysed photo, in each orientation of the card unless
     * one is given.
     *
     * @param {Object} V The vision module.
     * @param {Object} a Analysis of V.analyse().
     * @param {Object} s The card's spec.
     * @param {number} [turn] Orientation known (the sheet's other card, say).
     * @return {Object} The best, with ok when the photo matches it, its turn and its cells.
     */
    function fit(V, a, s, turn) {
        var ts = turn === undefined || turn === null ? V.turns(a) : [turn], best = null;
        ts.forEach(function (t) {
            var f = V.frame(a, t);
            if (!f) return;
            var e = evaluate(f, s);
            e.turn = t;
            e.frame = f;
            if (!best || e.score > best.score) best = e;
        });
        if (!best) return {ok: false};
        best.ok = best.colScore >= 0.7 && best.rowScore >= 0.7;
        best.printed = printed(s);
        if (s.shift) shiftRows(best, s.shift);
        best.cells = cells(best);
        best.marks = marks(best);
        return best;
    }

    /** The rows of a fit moved k lines (up for -1). -> the fit. */
    function shiftRows(e, k) {
        var v = e.v, step = e.rowStep;
        e.v = v.map(function (x, j) { return j + k >= 0 && j + k < v.length ? v[j + k] : v[0] + (j + k) * step; });
        e.shifted = k;
        return e;
    }

    /**
     * The card as the reading's arithmetic takes a printed card (OcrLayout.printed()): one
     * line an end.
     */
    function printed(s) {
        return {ends: s.ends, arrows: s.arrows, perLine: s.arrows, lines: s.ends, duel: true};
    }

    function cell(line, key, kind, u0, u1, v0, v1) {
        return {line: line, key: key, kind: kind, u0: u0, u1: u1, v0: v0, v1: v1};
    }

    /**
     * Every cell to read, in the straightened frame: each end's arrows and numbers (its total
     * 'line', the running total 'cum' or the running set points 'run'), the total's box ('cum',
     * or the set points 'pts', on the line 'total'; the counts' totals beside), the shoot-off's
     * boxes (lines 'so0'…, their totals 'st'), the Winner box and the closest box ('box'
     * cells), the set points' three cells ('pick': which is circled).
     */
    function cells(e) {
        var s = e.spec, m = e.model, u = e.u, v = e.v, E = s.ends, out = [];
        // The frame straightened: a millimetre as long down as across.
        var mm = e.unit * m.width / CARD_MM, vmm = mm;
        m.cols.forEach(function (c) {
            if (c.key === 'num') return;
            for (var i = 1; i <= E; i++) {
                var v0 = v[i + 1], v1 = v[i + 2], k;
                if (c.kind === 'arrow') {
                    // The archers' letters left out of a team card's arrow cell.
                    k = cell(i, c.key, 'arrow', u(c.x0), e.comp ? u(c.x1 - e.compU) : u(c.x1), v0, v1);
                    k.arrow = (i - 1) * s.arrows + c.index;
                } else if (c.kind === 'pick') {
                    k = cell(i, c.key, 'pick', u(c.x0), u(c.x1), v0, v1);
                    k.picks = c.picks.map(function (p) { return u(p); });
                } else {
                    k = cell(i, c.key, c.kind, u(c.x0), u(c.x1), v0, v1);
                }
                out.push(k);
            }
        });
        // The total's box, against the table under its last column; the counts' totals beside.
        var tv0 = v[E + 2], tv1 = tv0 + e.rowStep, last = m.cols.filter(function (c) { return c.key === (s.mode ? 'run' : 'cum'); })[0];
        out.push(cell('total', s.mode ? 'pts' : 'cum', 'sum', u(last.x0), u(last.x1), tv0, tv1));
        m.cols.forEach(function (c) {
            if (c.kind === 'count') out.push(cell('total', c.key, 'count', u(c.x0), u(c.x1), tv0, tv1));
        });
        // The shoot-off, under the table after a quarter of a row: its lines of boxes (a team
        // card's a row high, else three quarters), each line's total on a team card of several
        // arrows; the robin card's boxes half a millimetre apart.
        var team = teamCard(s), so = s.so, sw = so <= s.arrows ? m.aw : m.A / so, gap = s.robin ? 0.5 / (CARD_MM / m.width) : 0;
        var soH = team ? e.rowStep : 0.75 * e.rowStep, soTop = tv0 + 0.25 * e.rowStep;
        var uOf = function (x) { return u(1) + (x - 1) * e.unit; };
        for (var r = 0; r < s.soRows; r++) {
            var r0 = soTop + r * soH, x = 1;
            for (var j = 0; j < so; j++) {
                var x0 = x + gap, b = cell('so' + r, 's' + j, 'arrow', uOf(x0), uOf(x0 + sw - gap), r0, r0 + soH);
                b.so = r * so + j;
                out.push(b);
                x = x0 + sw - gap;
            }
            if (s.soTotal) {
                var aMM = m.aw * CARD_MM / m.width, tw = team ? Math.min(20, so < s.arrows ? Math.min(20, s.arrows - so) * aMM : m.T * CARD_MM / m.width)
                    : m.T * CARD_MM / m.width - 2;
                var t0 = x + 2 * m.width / CARD_MM;
                out.push(cell('so' + r, 'st', 'sum', uOf(t0), uOf(t0 + tw * m.width / CARD_MM), r0, r0 + soH));
            }
        }
        // The closest box: under the shoot-off (a team card just under it, else from the total's
        // box), half a box wide and half a row high, in its first box's right half.
        var cv0 = team ? soTop + s.soRows * soH + vmm : tv1 + (s.soRows === 1 ? 1 : 13 / 8) * e.rowStep;
        out.push(cell('closest', 'closest', 'box', uOf(1 + sw / 2), uOf(1 + sw), cv0, cv0 + 0.5 * e.rowStep));
        // The Winner box, in the title's row: 2 mm from its left and top, a row less 4 mm wide.
        var side = Math.max(2, ROW_MM - 4);
        out.push(cell('winner', 'winner', 'box', u(1) + 2 * mm, u(1) + (2 + side) * mm, v[0] + 2 * vmm, v[0] + (2 + side) * vmm));
        return out;
    }

    /** The cells the core prints grey, shown apart: the arrows' numbers, the ends' numbers. */
    function marks(e) {
        var s = e.spec, v = e.v, u = e.u, out = [];
        e.model.cols.forEach(function (c) {
            if (c.kind === 'arrow') out.push({u0: u(c.x0), u1: u(c.x1), v0: v[1], v1: v[2]});
        });
        for (var i = 1; i <= s.ends; i++) out.push({u0: u(0), u1: u(1), v0: v[i + 1], v1: v[i + 2]});
        return out;
    }

    /**
     * The numbers a cell can hold (as OcrLayout.cellAllowed()): an end's or set's total, the
     * running total after so many ends, the shoot-off's total; a count up to its arrows; the
     * running set points up to two an end, their total one more (the shoot-off's).
     */
    function cellAllowed(c, s, values) {
        var ns = values.map(function (v) { return v.n; });
        if (c.key === 'line') return Lay._sums(s.arrows, ns);
        if (c.key === 'cum') return Lay._sums((c.line === 'total' ? s.ends : c.line) * s.arrows, ns);
        if (c.key === 'pts') return [[0, 2 * s.ends + 1]];
        if (c.key === 'run') return [[0, Math.min(2 * c.line, 2 * s.ends)]];
        if (c.key === 'st') return Lay._sums(s.so, ns);
        if (c.key === 'gold' || c.key === 'xnine') {
            var counted = values.some(function (v) { return c.key === 'gold' ? v.g : v.x; });
            return [[0, counted ? (c.line === 'total' ? s.ends * s.arrows : s.arrows) : 0]];
        }
        return null;
    }

    return {columns: columns, evaluate: evaluate, fit: fit, cells: cells, printed: printed, cellAllowed: cellAllowed, shiftRows: shiftRows,
        CARD_MM: CARD_MM};
});
