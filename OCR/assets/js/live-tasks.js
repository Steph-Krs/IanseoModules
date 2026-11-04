/**
 * The slow work of the live view, away from the video: each task takes a frame (or what
 * an earlier task left) and gives its result, while the page goes on following the sheet
 * (OcrFollow) frame after frame. Run in a worker by live-worker.js; the bench runs them
 * directly.
 *
 * Tasks (msg.type):
 * - lens: the camera's lens, from one frame;
 * - grids: the grids of a frame, each with its straightened frames (one per way up);
 * - header: whose cards these are, from their printed header in a frame;
 * - fit: a grid matched to its card's layout (needs the grid's analysis, kept);
 * - cells: cells read in a frame;
 * - solve: the arithmetic checks of a card.
 * Frames are grey {w, h, d}, as taken (the lens is undone here). A frame's homographies
 * (Hfull) map a grid's straightened coordinates to the frame's pixels, straightened from
 * the lens.
 *
 * Browser and Node alike; the modules are given (env).
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.OcrLiveTasks = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var KEEP = 24;           // grid analyses kept for later fits
    var PULL = 0.01;         // weight of the prediction, when the grid's lines are looked for
    var SPAN = 0.75;         // share of the table its lines must span, for its perspective to be drawn from them

    /**
     * @param {Object} env {V, Lay, R, X, S, nets: {arrow, digit, text, number}}.
     */
    function create(env) {
        return {env: env, lens: null, roster: [], analyses: {}, order: []};
    }

    /**
     * The vision module seen through the lens: crops and lengths of a frame straightened
     * from the lens, read from the frame as taken, pixel by pixel (no need to straighten
     * the whole frame for a few cells).
     */
    function lensView(V, lens) {
        if (!lens) return V;
        var back = function (p) { return V.lensBack(lens, p.x, p.y); };
        var W = Object.create(V);
        W.apply = function (H, u, v) { return back(V.apply(H, u, v)); };
        W.crop = function (full, Hfull, u0, v0, u1, v1, outW, outH) {
            var g = V.makeGray(outW, outH);
            for (var y = 0; y < outH; y++) {
                var v = v0 + (v1 - v0) * (y + 0.5) / outH;
                for (var x = 0; x < outW; x++) {
                    var p = W.apply(Hfull, u0 + (u1 - u0) * (x + 0.5) / outW, v), s = V.sample(full, p.x, p.y);
                    g.d[y * outW + x] = isNaN(s) ? 255 : s;
                }
            }
            return g;
        };
        W.fullLength = function (Hfull, u0, v0, u1, v1) {
            var a = W.apply(Hfull, u0, v0), b = W.apply(Hfull, u1, v1);
            return Math.hypot(a.x - b.x, a.y - b.y);
        };
        W.scanline = function (full, Hfull, u0, u1, v, n) {
            var out = new Float32Array(n);
            for (var i = 0; i < n; i++) {
                var p = W.apply(Hfull, u0 + (u1 - u0) * i / (n - 1), v), s = V.sample(full, p.x, p.y);
                out[i] = isNaN(s) ? 255 : s;
            }
            return out;
        };
        return W;
    }

    /**
     * A cell put back on its own lines: around where the sheet's homography puts it, the
     * grid's lines are looked for (rows and columns of the window inked over most of its
     * span) and the cell is moved, by less than half its size, so that its borders sit on
     * them. The homography's small errors (a lens it does not model, a sheet not quite
     * flat) are taken out cell by cell.
     */
    function snap(Vl, R, img, it) {
        var h = it.v1 - it.v0, w = it.u1 - it.u0, m = 0.6 * h, ph = 48, k = ph / h;
        var W = Math.max(16, Math.round((w + 2 * m) * k)), Hh = Math.round((h + 2 * m) * k);
        var ink = R.inkMap(Vl.crop(img, it.Hfull, it.u0 - m, it.v0 - m, it.u1 + m, it.v1 + m, W, Hh));
        // A line of the grid runs unbroken across the window (a written stroke does not):
        // each column (row) scored by its longest run of ink, leaning by a pixel, gaps of
        // two bridged.
        var run = function (n, len, at) {
            var out = new Float32Array(n);
            for (var i = 0; i < n; i++) {
                var best = 0, cur = 0, gap = 0;
                for (var j = 0; j < len; j++) {
                    var on = at(i, j) || (i > 0 && at(i - 1, j)) || (i + 1 < n && at(i + 1, j));
                    if (on) {
                        cur += gap + 1;
                        gap = 0;
                    } else if (cur && gap < 2) {
                        gap++;
                    } else {
                        cur = 0;
                        gap = 0;
                    }
                    if (cur > best) best = cur;
                }
                out[i] = best;
            }
            return out;
        };
        var col = run(W, Hh, function (x, y) { return ink.d[y * W + x] >= 0.3; });
        var row = run(Hh, W, function (y, x) { return ink.d[y * W + x] >= 0.3; });
        // The shift that puts both borders on the darkest columns (rows), within reach.
        function best(prof, a, b, reach) {
            var bs = 0, bv = -1;
            for (var d = -reach; d <= reach; d++) {
                var s = 0;
                [a + d, b + d].forEach(function (p) {
                    for (var e = -1; e <= 1; e++) {
                        var q = Math.round(p) + e;
                        if (q >= 0 && q < prof.length) s += prof[q] * (e ? 0.5 : 1);
                    }
                });
                // Rather where the homography put it, other things being equal.
                s -= 0.02 * Math.abs(d) * prof.length;
                if (s > bv) {
                    bv = s;
                    bs = d;
                }
            }
            return bs;
        }
        var reach = Math.round(0.45 * Math.min(w, h) * k);
        var dx = best(col, m * k, (m + w) * k, reach), dy = best(row, m * k, (m + h) * k, reach);
        var du = dx / k, dv = dy / k, out = Object.assign({}, it);
        out.u0 += du;
        out.u1 += du;
        out.v0 += dv;
        out.v1 += dv;
        return out;
    }

    /**
     * What the page keeps of a fit: its cells, and where its table lies in the straightened
     * frame (left and right borders, top of the header row), for the header above it.
     */
    function fitOut(fit) {
        var table = {u0: fit.u(0), u1: fit.u(fit.units), v0: fit.v[0]};
        return {turn: fit.turn, printed: fit.printed, cells: fit.cells, marks: fit.marks || [], Hfull: fit.frame.Hfull, W: fit.frame.W, Hb: fit.frame.Hb,
            grey: fit.greyScores ? Math.max.apply(null, fit.greyScores) : null,
            table: table, lines: linesOf(fit.cells, table), alt: fit.alt ? fitOut(fit.alt) : null, alt2: fit.alt2 ? fitOut(fit.alt2) : null};
    }

    /**
     * The table's lines in its straightened frame: every column border (the number column's
     * left one included) and every row border from the header row's top to the last line's
     * bottom (the total row, under a few columns only, left out).
     */
    function linesOf(cells, table) {
        var us = [table.u0], vs = [table.v0], closing = [];
        cells.forEach(function (c) {
            // A match card's cells under its table (shoot-off, boxes) are no lines of it.
            if (typeof c.line !== 'number' && c.line !== 'total') return;
            us.push(c.u0, c.u1);
            if (c.line === 'total') return;
            vs.push(c.v0, c.v1);
            if (c.key === 'cum') closing.push(c.v1);
        });
        var uniq = function (a) {
            a.sort(function (x, y) { return x - y; });
            return a.filter(function (x, i) { return !i || x - a[i - 1] > 1e-6; });
        };
        vs = uniq(vs);
        // Lines between two lines of one end: not across the number column.
        var half = vs.slice(2, -1).filter(function (v) {
            return !closing.some(function (cv) { return Math.abs(cv - v) < 1e-6; });
        });
        return {us: uniq(us), vs: vs, half: half};
    }

    /**
     * A grid put back on its own lines in this frame. The table is cut out around where its
     * homography puts it, at about the frame's own resolution (a thin line sampled more
     * sparsely is lost); a line's pixels are darker than the paper a few pixels away on both
     * sides (whatever the light, and a grey fill is no line). Each border is looked for piece
     * by piece, between two crossing borders, near where it should be; a border is the
     * straight line through its pieces (a written stroke that looked like one left out); the
     * borders' meeting points give the correction (a homography, fitted on those that agree).
     * First the whole comb of borders is shifted to where it fits best, then the pieces are
     * looked for twice, nearer the second time. The header row and the number column are
     * part of it: they bound the table. far: how far (cells) the table may be from where
     * the homography puts it (the following's own error: a column or a row away, lines
     * alike would fit nearly as well). -> {Hfull corrected, seen, part: only part of the
     * table seen}, or null when too few borders were seen.
     */
    function refine(e, Vl, img, Hfull, lines, far) {
        var us = lines.us, vs = lines.vs, nu = us.length, nv = vs.length, i, j;
        if (nu < 4 || nv < 4) return null;
        var gaps = [];
        for (i = 1; i < nv; i++) gaps.push(vs[i] - vs[i - 1]);
        gaps.sort(function (x, y) { return x - y; });
        var ch = gaps[gaps.length >> 1];
        var cu = (us[0] + us[nu - 1]) / 2, cv = (vs[0] + vs[nv - 1]) / 2;
        var px = Vl.fullLength(Hfull, cu, cv, cu, cv + ch);
        if (!(px >= 8)) return null;
        var m = 0.6 * ch, k = Math.min(32, px) / ch;
        var u0 = us[0] - m, v0 = vs[0] - m, u1 = us[nu - 1] + m, v1 = vs[nv - 1] + m;
        var W = Math.round((u1 - u0) * k), H = Math.round((v1 - v0) * k), n = W * H;
        if (W < 40 || H < 40 || n > 1500000) return null;
        var g = Vl.crop(img, Hfull, u0, v0, u1, v1, W, H).d;
        var kx = W / (u1 - u0), ky = H / (v1 - v0);
        var toCrop = function (H3, u, v) {
            var q = e.O.apply(H3, u, v);
            return {x: (q.x - u0) * kx - 0.5, y: (q.y - v0) * ky - 0.5};
        };
        var hist = new Int32Array(256);
        for (i = 0; i < n; i++) hist[g[i] | 0]++;
        var pct = function (f) {
            for (var b = 0, c = 0; b < 256; b++) if ((c += hist[b]) >= f * n) return b;
            return 255;
        };
        var T = Math.max(8, 0.12 * (pct(0.85) - pct(0.02)));
        var vert = new Uint8Array(n), horz = new Uint8Array(n), x, y;
        for (y = 4; y < H - 4; y++) {
            for (x = 4; x < W - 4; x++) {
                var o = y * W + x, c = g[o] + T;
                if (c <= Math.max(g[o - 2], g[o - 3], g[o - 4]) && c <= Math.max(g[o + 2], g[o + 3], g[o + 4])) vert[o] = 1;
                if (c <= Math.max(g[o - 2 * W], g[o - 3 * W], g[o - 4 * W])
                    && c <= Math.max(g[o + 2 * W], g[o + 3 * W], g[o + 4 * W])) horz[o] = 1;
            }
        }
        // Share of a piece (a to b, moved by d across) lying on line pixels, within a pixel.
        var along = function (map, a, b, d, vertical) {
            var len = Math.max(1, Math.round(vertical ? b.y - a.y : b.x - a.x)), hit = 0, tot = 0, st = vertical ? 1 : W;
            for (var t = 0; t <= len; t++) {
                var xi = Math.round(a.x + (b.x - a.x) * t / len + (vertical ? d : 0));
                var yi = Math.round(a.y + (b.y - a.y) * t / len + (vertical ? 0 : d));
                if (xi < 1 || yi < 1 || xi >= W - 1 || yi >= H - 1) continue;
                tot++;
                var q = yi * W + xi;
                if (map[q] || map[q - st] || map[q + st]) hit++;
            }
            return tot >= 0.6 * (len + 1) ? hit / tot : 0;
        };
        // Where the piece lies within reach: the middle of the best stretch of shifts.
        var search = function (map, a, b, reach, vertical) {
            var sc = [], best = -1, bi = 0, d;
            for (d = -reach; d <= reach; d++) {
                sc.push(along(map, a, b, d, vertical));
                if (sc[sc.length - 1] > best) {
                    best = sc[sc.length - 1];
                    bi = sc.length - 1;
                }
            }
            if (best < 0.6) return null;
            var lo = bi, hi = bi;
            while (lo > 0 && sc[lo - 1] >= best - 0.1) lo--;
            while (hi < sc.length - 1 && sc[hi + 1] >= best - 0.1) hi++;
            return (lo + hi) / 2 - reach;
        };
        // Each piece found is a point of the picture lying on a line of the table: on its
        // column's u, or its row's v. The homography G taking the picture to the table that
        // puts each piece on its line is linear once multiplied out: least squares, the
        // pieces far from their line left out, again. No line needs to be straight across
        // the whole table (the lens leaves them a little bent), and a stroke taken for a
        // piece weighs as one point only. simple: a shift, a turn and one scale only (part
        // of the table seen: neither perspective nor stretch drawn from it).
        var cu2 = (us[0] + us[nu - 1]) / 2, cv2 = (vs[0] + vs[nv - 1]) / 2, S = Math.max(us[nu - 1] - us[0], vs[nv - 1] - vs[0]) / 2;
        var fitPieces = function (pieces, simple) {
            var keep = pieces, G = null, n = simple ? 4 : 8;
            for (var round = 0; round < 4; round++) {
                var A = new Float64Array(n * n), bb = new Float64Array(n);
                keep.forEach(function (pc) {
                    var x = (pc.x - cu2) / S, y = (pc.y - cv2) / S, t = (pc.t - (pc.col ? cu2 : cv2)) / S, r;
                    if (simple) r = pc.col ? [x, -y, 1, 0] : [y, x, 0, 1];
                    else r = pc.col ? [x, y, 1, 0, 0, 0, -t * x, -t * y] : [0, 0, 0, x, y, 1, -t * x, -t * y];
                    for (var i2 = 0; i2 < n; i2++) {
                        bb[i2] += r[i2] * t;
                        for (var j2 = 0; j2 < n; j2++) A[i2 * n + j2] += r[i2] * r[j2];
                    }
                });
                var gs = solve(A, bb, n);
                if (!gs) return null;
                G = simple ? [gs[0], -gs[1], gs[2], gs[1], gs[0], gs[3], 0, 0, 1] : [gs[0], gs[1], gs[2], gs[3], gs[4], gs[5], gs[6], gs[7], 1];
                var res = keep.map(function (pc) {
                    var x = (pc.x - cu2) / S, y = (pc.y - cv2) / S, z = G[6] * x + G[7] * y + G[8];
                    var w = pc.col ? (G[0] * x + G[1] * y + G[2]) / z : (G[3] * x + G[4] * y + G[5]) / z;
                    return Math.abs(w * S + (pc.col ? cu2 : cv2) - pc.t);
                });
                var lim = Math.max(0.05 * ch, 2.5 * res.slice().sort(function (x1, y1) { return x1 - y1; })[res.length >> 1]);
                var next = keep.filter(function (pc, z) { return res[z] <= lim; });
                if (next.length === keep.length) break;
                keep = next;
                if (keep.length < 12) return null;
            }
            if (lineCount(keep, true) < 3 || lineCount(keep, false) < 3) return null;
            // Back to the table's own units, then the other way: table -> picture.
            var Gt = mul3([S, 0, cu2, 0, S, cv2, 0, 0, 1], mul3(G, [1 / S, 0, -cu2 / S, 0, 1 / S, -cv2 / S, 0, 0, 1]));
            var H3 = Vl.invert(Gt);
            return H3 ? {H: H3, n: keep.length} : null;
        };
        var lineCount = function (pieces, col) {
            var seen = {};
            pieces.forEach(function (pc) { if (pc.col === col) seen[pc.i] = true; });
            return Object.keys(seen).length;
        };
        var pass = function (Hc, reach) {
            var pieces = [], r, gap, a, b, d;
            us.forEach(function (u, ci) {
                for (r = 0; r + 1 < nv; r++) {
                    gap = vs[r + 1] - vs[r];
                    if (gap < 0.3 * ch) continue;
                    a = toCrop(Hc, u, vs[r] + 0.2 * gap);
                    b = toCrop(Hc, u, vs[r + 1] - 0.2 * gap);
                    d = search(vert, a, b, reach, true);
                    if (d === null) continue;
                    pieces.push({x: u0 + ((a.x + b.x) / 2 + d + 0.5) / kx, y: v0 + ((a.y + b.y) / 2 + 0.5) / ky, t: u, col: true, i: ci});
                }
            });
            vs.forEach(function (v, ri) {
                for (r = 0; r + 1 < nu; r++) {
                    gap = us[r + 1] - us[r];
                    if (gap < 0.3 * ch) continue;
                    a = toCrop(Hc, us[r] + 0.2 * gap, v);
                    b = toCrop(Hc, us[r + 1] - 0.2 * gap, v);
                    d = search(horz, a, b, reach, false);
                    if (d === null) continue;
                    pieces.push({x: u0 + ((a.x + b.x) / 2 + 0.5) / kx, y: v0 + ((a.y + b.y) / 2 + d + 0.5) / ky, t: v, col: false, i: ri});
                }
            });
            if (pieces.length < 12 || lineCount(pieces, true) < 3 || lineCount(pieces, false) < 3) return null;
            var span = function (col) {
                var ts = pieces.filter(function (pc) { return pc.col === col; }).map(function (pc) { return pc.t; });
                return Math.max.apply(null, ts) - Math.min.apply(null, ts);
            };
            var part = span(true) < SPAN * (us[nu - 1] - us[0]) || span(false) < SPAN * (vs[nv - 1] - vs[0]);
            var fit = fitPieces(pieces, part);
            if (e.trace) e.trace.push({pieces: pieces.length, kept: fit ? fit.n : 0, part: part, cols: lineCount(pieces, true), rows: lineCount(pieces, false)});
            return fit ? {H: fit.H, seen: fit.n, part: part} : null;
        };
        // The whole comb first: the shift putting most borders on line pixels.
        var colP = new Float32Array(W), rowP = new Float32Array(H);
        var ya = Math.max(0, Math.round((vs[0] - v0) * ky)), yb = Math.min(H, Math.round((vs[nv - 1] - v0) * ky));
        var xa = Math.max(0, Math.round((us[0] - u0) * kx)), xb = Math.min(W, Math.round((us[nu - 1] - u0) * kx));
        for (y = ya; y < yb; y++) for (x = xa; x < xb; x++) {
            colP[x] += vert[y * W + x];
            rowP[y] += horz[y * W + x];
        }
        var combAt = function (prof, pos, d) {
            var sum = 0;
            pos.forEach(function (q) {
                var top = 0;
                for (var z = -3; z <= 3; z++) {
                    var at = Math.round(q + d) + z;
                    if (at >= 0 && at < prof.length && prof[at] > top) top = prof[at];
                }
                sum += top;
            });
            return sum;
        };
        // The shift of the comb putting most lines on line pixels. Rows are much alike: a
        // shift a row away scores nearly as well, so the prediction is given almost no weight.
        var comb = function (prof, pos, reach) {
            var bd = 0, bv = -1;
            for (var d = -reach; d <= reach; d++) {
                var sum = combAt(prof, pos, d) * (1 - PULL * Math.abs(d) / reach);
                if (sum > bv) {
                    bv = sum;
                    bd = d;
                }
            }
            return bd;
        };
        far = Math.round((far || 0.6) * ch * Math.min(kx, ky));
        var colAt = us.map(function (u) { return (u - u0) * kx - 0.5; }), rowAt = vs.map(function (v) { return (v - v0) * ky - 0.5; });
        var dx = comb(colP, colAt, far), dy = comb(rowP, rowAt, far);
        var tilt = Math.max(2, Math.min(far, Math.round(0.35 * ch * ky))), first = pass([1, 0, dx / kx, 0, 1, dy / ky, 0, 0, 1], tilt);
        if (!first) return null;
        // A row away scores nearly as well (rows are alike). The number column tells: its
        // cells span an end's lines, so the line between them stops at its border. Looked at
        // once the grid leans as it should (the number column is far from the middle).
        var half = lines.half || [];
        if (half.length >= 2) {
            var isHalf = function (v) {
                return half.some(function (hv) { return Math.abs(hv - v) < 1e-6; });
            };
            var numGap = us[1] - us[0], near = Math.max(1, Math.round(0.15 * ch * ky)), step = Math.round(ch * ky);
            var numScore = function (off) {
                var sc = 0;
                for (var r = 1; r < nv; r++) {
                    var pa = toCrop(first.H, us[0] + 0.2 * numGap, vs[r]), pb = toCrop(first.H, us[1] - 0.2 * numGap, vs[r]), top = 0;
                    for (var z = -near; z <= near; z++) top = Math.max(top, along(horz, pa, pb, off + z, false));
                    sc += isHalf(vs[r]) ? -top : top;
                }
                return sc;
            };
            // Two rows away has the same pattern: of the shifts that fit it, the one putting
            // most lines on lines.
            var cands = [0, -step, step].map(function (off) {
                return {off: off, num: numScore(off), comb: combAt(rowP, rowAt, dy + off)};
            });
            var top = Math.max.apply(null, cands.map(function (c4) { return c4.num; })), pick = null;
            if (e.trace) e.trace.push(cands);
            cands.forEach(function (c4) {
                if (c4.num >= top - 1 && (!pick || c4.comb > pick.comb)) pick = c4;
            });
            var shift = cands[0].num >= top - 1 && cands[0].comb >= pick.comb / 1.02 ? 0 : pick.off;
            if (shift) {
                first = pass([1, 0, dx / kx, 0, 1, (dy + shift) / ky, 0, 0, 1], tilt);
                if (!first) return null;
            }
        }
        var second = pass(first.H, Math.max(2, Math.round(0.15 * ch * ky)));
        var out = second || first;
        return {Hfull: mul3(Hfull, out.H), seen: out.seen, part: !!out.part};
    }

    /** The n unknowns of A x = b (n x n, row by row), by elimination; null if singular. */
    function solve(A, b, n) {
        var M = [], i, j, k;
        for (i = 0; i < n; i++) M.push(Array.prototype.slice.call(A, i * n, i * n + n).concat([b[i]]));
        for (k = 0; k < n; k++) {
            var piv = k;
            for (i = k + 1; i < n; i++) if (Math.abs(M[i][k]) > Math.abs(M[piv][k])) piv = i;
            if (Math.abs(M[piv][k]) < 1e-12) return null;
            var tmp = M[k];
            M[k] = M[piv];
            M[piv] = tmp;
            for (i = k + 1; i < n; i++) {
                var f = M[i][k] / M[k][k];
                for (j = k; j <= n; j++) M[i][j] -= f * M[k][j];
            }
        }
        var x = new Array(n);
        for (i = n - 1; i >= 0; i--) {
            var sum = M[i][n];
            for (j = i + 1; j < n; j++) sum -= M[i][j] * x[j];
            x[i] = sum / M[i][i];
        }
        return x;
    }

    function mul3(A, B) {
        var C = new Array(9);
        for (var i = 0; i < 3; i++) {
            for (var j = 0; j < 3; j++) C[i * 3 + j] = A[i * 3] * B[j] + A[i * 3 + 1] * B[3 + j] + A[i * 3 + 2] * B[6 + j];
        }
        return C;
    }

    function grey(m) {
        return m.d instanceof Uint8Array ? m : {w: m.w, h: m.h, d: new Uint8Array(m.d)};
    }

    var tasks = {
        lens: function (ctx, msg) {
            var l = ctx.env.V.lensOf(grey(msg.img));
            ctx.lens = l ? {k: l.k, cx: l.cx, cy: l.cy, R: l.R} : null;
            return {lens: ctx.lens};
        },

        roster: function (ctx, msg) {
            ctx.roster = msg.roster || [];
            return {n: ctx.roster.length};
        },

        // msg.analysed: the frame and its analysis made already (gridsFrame(), then the
        // analysis shared among workers), out as gridsFrame() left it.
        grids: function (ctx, msg) {
            var V = ctx.env.V, pre = msg.analysed, out = pre ? pre.out : {grids: []}, img = pre ? pre.img : gridsFrame(ctx, msg, out);
            if (!img) return out;
            var a = pre && pre.a || V.analyse(img, gridsOpts(msg));
            if (!a.ok) return out;
            var layouts = msg.layouts && msg.layouts.length ? msg.layouts : [{ends: 6, arrows: 6, golds: true}];
            var Lay = ctx.env.Lay, bls = [];
            // Only a grid that matches one of the competition's layouts is a whole one (an
            // outline found short of the table, or over its header, does not); the layout
            // tells its way up too. All cards of a sheet face one way: the way of the cards
            // known (msg.up), else the way most grids found face.
            var fitAt = function (g, i, turn) {
                var best = null;
                layouts.forEach(function (lay, k) {
                    // A match sheet's card (the duel page) by its own layout.
                    var fit = (lay.duel && ctx.env.DL ? ctx.env.DL : Lay).fit(V, g, lay, turn);
                    if (fit.ok && (!best || fit.score > best.score)) {
                        best = fit;
                        bls[i] = k;
                    }
                });
                return best;
            };
            var fits = a.grids.map(function (g, i) {
                if (!msg.up) return fitAt(g, i, null);
                var t = Lay.turnFacing(V, g, msg.up);
                return t === null ? null : fitAt(g, i, t);
            });
            if (!msg.up) fits = Lay.oneWay(V, a.grids, fits, function (i, t) { return fitAt(a.grids[i], i, t); });
            a.grids.forEach(function (g, i) {
                var best = fits[i], bl = bls[i];
                if (!best) return;
                var ref = msg.id + ':' + i;
                ctx.analyses[ref] = g;
                ctx.order.push(ref);
                if (ctx.order.length > KEEP) delete ctx.analyses[ctx.order.shift()];
                var q = g.quad, turns = [best.turn].concat(V.turns(g).filter(function (t) { return t !== best.turn; }));
                out.grids.push({ref: ref, quad: [q.tl, q.tr, q.br, q.bl], layout: bl, fit: fitOut(best),
                    turns: turns.map(function (t) {
                        var f = V.frame(g, t);
                        return f ? {turn: t, W: f.W, Hb: f.Hb, rowH: f.rowH, Hfull: f.Hfull} : null;
                    }).filter(Boolean)});
            });
            out.found = a.grids.length;
            return out;
        },

        // The lens known already (remembered for this camera).
        setLens: function (ctx, msg) {
            ctx.lens = msg.lens || null;
            return {};
        },

        // msg.headed: the cards' results found already (by the worker's helpers, headerCard()).
        header: function (ctx, msg) {
            if (msg.headed) return {cards: msg.headed};
            var img = grey(msg.img);
            return {cards: msg.cards.map(function (c) { return headerCard(ctx.env, ctx.lens, img, c, msg.few, msg.session, ctx.roster); })};
        },

        fit: function (ctx, msg) {
            var e = ctx.env, a = ctx.analyses[msg.ref];
            if (!a) return {ok: false};
            var fit = e.Lay.fit(e.V, a, msg.structure, msg.turn);
            if (!fit.ok && msg.turn !== null && msg.turn !== undefined) fit = e.Lay.fit(e.V, a, msg.structure, null);
            if (!fit.ok) return {ok: false};
            var o = fitOut(fit);
            o.ok = true;
            return o;
        },

        cells: function (ctx, msg) {
            var e = ctx.env, Vl = lensView(e.V, ctx.lens), img = grey(msg.img), o = Object.assign({}, msg.opts || {});
            if (o.seq && e.nets.number) o.seq = {net: e.nets.number, X: e.X};
            else delete o.seq;
            return {reads: msg.items.map(function (it) {
                // Each cell reframed on its own lines (the option) is more than moved.
                if (msg.snap !== false && !o.reframe) it = snap(Vl, e.R, img, it);
                var r = it.kind === 'arrow' ? e.R.readArrow(Vl, e.nets.arrow, img, it.Hfull, it, o)
                    : e.R.readNumber(Vl, e.nets.digit, img, it.Hfull, it, 6, o);
                return {card: it.card, key: it.key, reading: r};
            })};
        },

        // Grids put back on their lines, nothing read (their outline to follow the sheet).
        refine: function (ctx, msg) {
            var e = ctx.env, Vl = lensView(e.V, ctx.lens), img = grey(msg.img), out = [];
            msg.grids.forEach(function (g) {
                var r = e.O ? refine(e, Vl, img, g.Hfull, g.lines, g.far) : null;
                if (r) out.push({card: g.card, Hfull: r.Hfull, seen: r.seen});
            });
            return {refined: out};
        },

        solve: function (ctx, msg) {
            return {card: msg.card, sol: ctx.env.S.solve(msg.p, msg.lines, msg.total, msg.values, msg.golds)};
        }
    };

    /** One task. -> its result (with the task's id and type). */
    /**
     * Whose card one grid is, from its printed header in a frame (lens as taken), each way up
     * given until one reads well: among the target's archers (few) first, then the roster.
     * Its own function: the live view's worker reads a frame's cards side by side on helpers.
     *
     * @param {Object} env {V, X, nets: {text}}.
     */
    function headerCard(env, lens, img, c, few, session, roster) {
        var Vl = lensView(env.V, lens), net = env.nets.text, rank = {ok: 3, session: 2, name: 1, unsure: 0};
        var best = null, seen = null, guess = null;
        c.turns.some(function (f) {
            var read = env.X.readHeader(Vl, net, img, f);
            if (!read) return false;
            if (!seen) seen = {target: env.X.greedy(read.target, net), name: env.X.greedy(read.name, net)};
            var gu = env.X.guess(net, read, few && few.length ? few : roster);
            if (gu && (!guess || gu.gap < guess.gap)) guess = gu;
            var id = few && few.length ? env.X.identify(net, read, few, session) : null;
            if (!id || id.status !== 'ok') id = env.X.identify(net, read, roster, session);
            id.turn = f.turn;
            if (!best || rank[id.status] > rank[best.status]) best = id;
            return id.status === 'ok';
        });
        return {id: c.id, status: best ? best.status : 'none', archer: best && best.archer ? best.archer : null,
            turn: best ? best.turn : null, seen: seen, guess: guess};
    }

    /**
     * A grids task's frame: straightened from the lens, the lens measured first when asked (a
     * bowed frame is looked at again later, straight: the page starts following again with
     * the lens). Null once a lens is found (out tells it).
     */
    function gridsFrame(ctx, msg, out) {
        var V = ctx.env.V, img = grey(msg.img);
        if (msg.lens) {
            var l = V.lensOf(img);
            ctx.lens = l ? {k: l.k, cx: l.cx, cy: l.cy, R: l.R} : null;
            out.lensTried = true;
            out.lens = ctx.lens;
            if (ctx.lens) return null;
        }
        return ctx.lens ? V.undistort(img, ctx.lens) : img;
    }

    /** A grids task's analysis: every grid, no second chance once the sheet's are found. */
    function gridsOpts(msg) {
        return {all: true, want: msg.expected || 0};
    }

    function run(ctx, msg) {
        var t = tasks[msg.type], out = t ? t(ctx, msg) : {error: 'no task ' + msg.type};
        out.id = msg.id;
        out.type = msg.type;
        return out;
    }

    return {create: create, run: run, gridsFrame: gridsFrame, gridsOpts: gridsOpts, headerCard: headerCard, lensView: lensView, snap: snap, refine: refine, linesOf: linesOf};
});
