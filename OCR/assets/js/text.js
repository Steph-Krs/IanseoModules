/**
 * Printed text of a scorecard's header: the archer's name, the target and the
 * category, as ScorePDF::DrawScoreNew() prints them above the table, in Helvetica.
 *
 * The header is placed from the table. Two lines run under the name and under the
 * club, 6.25 mm apart on every card whatever its print size: they give the scale;
 * their left end, the room taken by a flag; their right end, the width of a column
 * (the name's cell stops 1.6 columns short of the table's right edge, where the box
 * of the target begins 1.4 columns short).
 *
 * Each band is cut out through the photo's homography, scaled to the network's
 * height and read as a sequence of columns (CTC). The reading is not free: the
 * strings the competition can print (its archers' names, targets, categories) are
 * scored against it, and the most likely one is kept.
 *
 * Browser and Node alike, no dependency.
 */
(function (root, factory) {
    var node = typeof module === 'object' && module.exports;
    var api = factory(node ? require('./nn.js') : root.OcrNN);
    if (node) module.exports = api;
    else root.OcrText = api;
})(typeof self !== 'undefined' ? self : this, function (NN) {
    'use strict';

    var UNDERLINES = 6.25;    // mm between the name's and the club's underlines
    var DIP = 4;              // grey levels a row of underline is darker than its neighbours
    var LEANS = [0.013, -0.013, 0.026, -0.026, 0.044, -0.044]; // slopes a header frame is tried at, none found level
    // Acceptance, in log-likelihood: how far below the free reading of its band a string
    // may score, and how far ahead of the next one it must be (bench: test/header.js).
    // Name gaps are per character; the name alone (target unreadable) must read better.
    var GAP_TARGET = 30, MARGIN_TARGET = 8, GAP_NAME = 3, GAP_NAME_ALONE = 1.5, MARGIN_NAME = 0.7;
    var SPECIAL = {'ß': 'ss', 'æ': 'ae', 'œ': 'oe', 'ø': 'o', 'đ': 'd', 'ł': 'l', 'ı': 'i', 'þ': 'th', 'ð': 'd'};

    // ------------------------------------------------------------------ model

    function floats(b64) {
        var bin = typeof atob === 'function' ? atob(b64) : Buffer.from(b64, 'base64').toString('binary');
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return new Float32Array(bytes.buffer);
    }

    function load(doc) {
        return {
            height: doc.height, stride: doc.stride, chars: doc.chars,
            layers: doc.layers.map(function (l) {
                var o = Object.assign({}, l);
                if (l.W) o.W = floats(l.W);
                if (l.b) o.b = floats(l.b);
                return o;
            })
        };
    }

    /**
     * Network over one band (ink in [0, 1], height = net.height).
     * -> {T, C, lp}: log-probabilities of blank (0) and every character, per column.
     */
    function run(net, img) {
        var C = 1, H = img.h, W = img.w, x = Float32Array.from(img.d), seq = false;
        net.layers.forEach(function (l) {
            var out, ci, y, xx, i, k;
            if (l.type === 'conv2d') {
                x = NN.conv3(x, l.cin, l.cout, H, W, l.W, l.b);
                C = l.cout;
            } else if (l.type === 'relu') {
                for (i = 0; i < x.length; i++) if (x[i] < 0) x[i] = 0;
            } else if (l.type === 'pool') {
                var H2 = Math.floor(H / l.kh), W2 = Math.floor(W / l.kw);
                out = new Float32Array(C * H2 * W2);
                for (ci = 0; ci < C; ci++) {
                    for (y = 0; y < H2; y++) {
                        for (xx = 0; xx < W2; xx++) {
                            var m = -Infinity;
                            for (var py = 0; py < l.kh; py++) {
                                for (var px = 0; px < l.kw; px++) {
                                    var v = x[(ci * H + y * l.kh + py) * W + xx * l.kw + px];
                                    if (v > m) m = v;
                                }
                            }
                            out[(ci * H2 + y) * W2 + xx] = m;
                        }
                    }
                }
                x = out;
                H = H2;
                W = W2;
            } else if (l.type === 'columns') {
                // [C][H][W] -> [C * H][W]: the layout is already that one.
                C = C * H;
                H = 1;
                seq = true;
            } else if (l.type === 'conv1d') {
                x = NN.conv1(x, l.cin, l.cout, W, l.k, l.W, l.b);
                C = l.cout;
            } else if (l.type === 'logsoftmax') {
                for (xx = 0; xx < W; xx++) {
                    var mx = -Infinity, sum = 0;
                    for (k = 0; k < C; k++) mx = Math.max(mx, x[k * W + xx]);
                    for (k = 0; k < C; k++) sum += Math.exp(x[k * W + xx] - mx);
                    var lz = mx + Math.log(sum);
                    for (k = 0; k < C; k++) x[k * W + xx] -= lz;
                }
            }
        });
        if (!seq) throw new Error('text model without columns');
        // Transposed: lp[t * C + k].
        var lp = new Float32Array(W * C);
        for (var t = 0; t < W; t++) for (var c = 0; c < C; c++) lp[t * C + c] = x[c * W + t];
        return {T: W, C: C, lp: lp};
    }

    // ---------------------------------------------------------------- strings

    /** The text as the network spells it: lower case, no accents, its characters only. */
    function fold(s, chars) {
        var out = '';
        String(s).toLowerCase().normalize('NFKD').replace(/\p{Mn}/gu, '').split('').forEach(function (c) {
            c = SPECIAL[c] || c;
            for (var i = 0; i < c.length; i++) if (chars.indexOf(c[i]) >= 0) out += c[i];
        });
        return out.split(/\s+/).filter(Boolean).join(' ');
    }

    function lse(a, b) {
        if (a === -Infinity) return b;
        if (b === -Infinity) return a;
        var m = Math.max(a, b);
        return m + Math.log(Math.exp(a - m) + Math.exp(b - m));
    }

    /**
     * Log-probability that a band reads text (CTC forward). With freePrefix, whatever
     * comes before the text costs nothing: the label before the name ("Archer:" in the
     * print's language, perhaps not in Latin letters) need not be known.
     */
    function score(out, net, text, freePrefix) {
        var lab = [];
        for (var i = 0; i < text.length; i++) {
            var k = net.chars.indexOf(text[i]);
            if (k >= 0) lab.push(k + 1);
        }
        var S = 2 * lab.length + 1, T = out.T, C = out.C, lp = out.lp;
        if (!lab.length) {
            var only = 0;
            for (var t0 = 0; t0 < T; t0++) only += lp[t0 * C];
            return only;
        }
        var prev = new Float64Array(S).fill(-Infinity), cur = new Float64Array(S);
        prev[0] = lp[0];
        prev[1] = lp[lab[0]];
        for (var t = 1; t < T; t++) {
            var row = t * C;
            for (var s = 0; s < S; s++) {
                var sym = s % 2 ? lab[(s - 1) >> 1] : 0, a = prev[s];
                if (s > 0) a = lse(a, prev[s - 1]);
                if (s > 1 && sym !== 0 && sym !== lab[(s - 3) >> 1]) a = lse(a, prev[s - 2]);
                if (freePrefix && s < 2) a = lse(a, 0);
                cur[s] = a + lp[row + sym];
            }
            var tmp = prev;
            prev = cur;
            cur = tmp;
        }
        return lse(prev[S - 1], prev[S - 2]);
    }

    /**
     * Log-probability of the best free reading (every column its likeliest output):
     * what any string could score at most, give or take.
     */
    function free(out) {
        var s = 0;
        for (var t = 0; t < out.T; t++) {
            var m = -Infinity;
            for (var k = 0; k < out.C; k++) if (out.lp[t * out.C + k] > m) m = out.lp[t * out.C + k];
            s += m;
        }
        return s;
    }

    /** Most likely characters, column by column (for the bench and the trace). */
    function greedy(out, net) {
        var s = '', last = 0;
        for (var t = 0; t < out.T; t++) {
            var best = 0, bv = -Infinity;
            for (var k = 0; k < out.C; k++) if (out.lp[t * out.C + k] > bv) { bv = out.lp[t * out.C + k]; best = k; }
            if (best !== last && best !== 0) s += net.chars[best - 1];
            last = best;
        }
        return s;
    }

    // ---------------------------------------------------------------- header

    /** Grey crop -> ink in [0, 1]: white = 90th percentile, as the training did. */
    function inkBand(g) {
        var n = g.d.length, sorted = Array.prototype.slice.call(g.d).sort(function (a, b) { return a - b; });
        var white = sorted[Math.min(n - 1, Math.floor(0.9 * n))], dark = sorted[Math.floor(0.01 * n)];
        var den = Math.max(30, white - dark), d = new Float32Array(n);
        for (var i = 0; i < n; i++) d[i] = Math.max(0, Math.min(1, (white - g.d[i]) / den));
        return {w: g.w, h: g.h, d: d};
    }

    /**
     * A rectangle of the straightened frame, as the network sees it: height px high,
     * width in proportion. Sampled finer then averaged down, like a scan.
     */
    function band(V, full, Hfull, u0, v0, u1, v1, height) {
        var w = Math.max(8, Math.round((u1 - u0) / (v1 - v0) * height));
        var src = V.fullLength(Hfull, u0, (v0 + v1) / 2, u0, (v0 + v1) / 2 + (v1 - v0)) / height;
        var k = Math.max(1, Math.min(4, Math.round(src)));
        var g = V.crop(full, Hfull, u0, v0, u1, v1, w * k, height * k);
        if (k > 1) {
            var r = {w: w, h: height, d: new Uint8Array(w * height)};
            for (var y = 0; y < height; y++) {
                for (var x = 0; x < w; x++) {
                    var s = 0;
                    for (var yy = 0; yy < k; yy++) for (var xx = 0; xx < k; xx++) s += g.d[(y * k + yy) * w * k + x * k + xx];
                    r.d[y * w + x] = s / (k * k);
                }
            }
            g = r;
        }
        return inkBand(g);
    }

    /**
     * Where the header is, from the two underlines above the table of a frame.
     * -> {s (frame units per mm), club, name (v of the underlines), left, right (u of
     * their ends), cw (column width)} or null.
     */
    function locate(V, full, f) {
        // Down into the table too: the top of the frame found may be a line of the header.
        var W = f.W, v0 = -0.45 * W, v1 = 0.15 * W, x, y, b;
        var ow = Math.round(Math.min(1400, Math.max(500, V.fullLength(f.Hfull, 0, 0, W, 0))));
        var oh = Math.round(ow * (v1 - v0) / W);
        var g = V.crop(full, f.Hfull, 0, v0, W, v1, ow, oh);
        // Means of rows over narrow blocks of columns. The underlines are dotted and
        // grey: blurred, they hardly pass a threshold, but each row of one is darker
        // than the rows a little above and below it, in every block along its length.
        // Words make such dips in some blocks only.
        var bw = Math.max(4, Math.round(ow / 100)), nb = Math.floor(ow / bw), P = new Float32Array(nb * oh);
        for (y = 0; y < oh; y++) {
            for (b = 0; b < nb; b++) {
                var sum = 0;
                for (x = b * bw; x < (b + 1) * bw; x++) sum += g.d[y * ow + x];
                P[b * oh + y] = sum / bw;
            }
        }
        var k = Math.max(2, Math.round(0.004 * ow));
        function dip(bb, yy) {
            if (yy - 2 * k < 0 || yy + 2 * k >= oh) return 0;
            var o = bb * oh, up = Math.max(P[o + yy - k], P[o + yy - 2 * k]), dn = Math.max(P[o + yy + k], P[o + yy + 2 * k]);
            return Math.min(up, dn) - P[o + yy];
        }
        function dipAt(bb, yy) {
            return Math.max(dip(bb, yy), dip(bb, yy - 1), dip(bb, yy + 1));
        }
        // The middle third of the width lies on both underlines, whatever the flag.
        var b0 = Math.round(0.3 * nb), b1 = Math.round(0.62 * nb), need = 0.8 * (b1 - b0), L = new Float32Array(oh);
        for (y = 0; y < oh; y++) {
            var c = 0;
            for (b = b0; b < b1; b++) if (dip(b, y) >= DIP) c++;
            L[y] = c;
        }
        // Lines: runs of such rows, thin (a band of bold text is thick).
        var lines = [], run = null;
        for (y = 0; y <= oh; y++) {
            if (y < oh && L[y] >= need) {
                if (run) run.y1 = y;
                else run = {y0: y, y1: y};
            } else if (run) {
                if (run.y1 - run.y0 + 1 <= 3 * k) {
                    var cy = (run.y0 + run.y1) / 2;
                    lines.push({y: Math.round(cy), v: v0 + (v1 - v0) * (cy + 0.5) / oh});
                }
                run = null;
            }
        }
        lines.sort(function (p, q) { return q.v - p.v; });
        // The club's underline, and the name's 6.25 mm above it: the table is then 55 to
        // 200 mm wide, and its top line lies 1.25 mm under the club's (unless a row of
        // earlier distances' totals is printed between). Of the pairs that fit, the one
        // with that top line, then the lowest.
        var found = null;
        for (var i = 0; i + 1 < lines.length; i++) {
            for (var j = i + 1; j < Math.min(lines.length, i + 3); j++) {
                var club = lines[i], name = lines[j], s = (club.v - name.v) / UNDERLINES;
                if (W / s < 55 || W / s > 200) continue;
                var top = lines.some(function (l) { return Math.abs(l.v - club.v - 1.25 * s) <= 0.35 * s; });
                var rank = (top ? 2 : 0) + (j === i + 1 ? 1 : 0);
                if (found && rank <= found.rank) continue;
                // Ends of the name's underline: blocks along its row that still dip.
                var left = b0, right = b1 - 1, miss = 0;
                for (b = b0 - 1; b >= 0; b--) {
                    if (dipAt(b, name.y) >= DIP) { left = b; miss = 0; } else if (++miss > 2) break;
                }
                miss = 0;
                for (b = b1; b < nb; b++) {
                    if (dipAt(b, name.y) >= DIP) { right = b; miss = 0; } else if (++miss > 2) break;
                }
                var uL = left * bw / ow * W, uR = (right + 1) * bw / ow * W;
                // The name's cell stops 1.6 columns short of the edge, or 2.7 when the grand
                // total box stands between it and the target's: the table is a whole number
                // of columns wide (6 to 11), which tells.
                var best = null;
                [1.6, 2.7].forEach(function (back) {
                    var cw = (W - uR) / back, cols = W / cw, off = Math.abs(cols - Math.round(cols));
                    if (Math.round(cols) < 6 || Math.round(cols) > 11 || cw * 1.4 < 6 * s || cw > 30 * s) return;
                    if (!best || off < best.off) best = {cw: cw, off: off, totalBox: back > 2};
                });
                if (!best) continue;
                found = {rank: rank, s: s, club: club.v, name: name.v, left: uL, right: uR, cw: best.cw, totalBox: best.totalBox};
            }
        }
        return found;
    }

    /**
     * The three bands of a header, read by the network.
     * -> {geometry, name, target, cat: {T, C, lp}} or null when the header is not found.
     */
    function readHeader(V, net, full, f) {
        var g = locate(V, full, f);
        // Not found level: the frame given may lean a little (a video's following, a
        // degree or two off, and the underlines no longer run along a row): tried again
        // leaning, the frame kept for the bands then.
        for (var i = 0; !g && i < LEANS.length; i++) {
            var a = LEANS[i], c = f.W / 2, Hs = [f.Hfull[0] + f.Hfull[1] * a, f.Hfull[1], f.Hfull[2] - f.Hfull[1] * a * c,
                f.Hfull[3] + f.Hfull[4] * a, f.Hfull[4], f.Hfull[5] - f.Hfull[4] * a * c,
                f.Hfull[6] + f.Hfull[7] * a, f.Hfull[7], f.Hfull[8] - f.Hfull[7] * a * c];
            var fl = Object.assign({}, f, {Hfull: Hs});
            g = locate(V, full, fl);
            if (g) f = fl;
        }
        if (!g) return null;
        var s = g.s, W = f.W, box = 1.4 * g.cw, h = net.height, out = {geometry: g};
        out.name = run(net, band(V, full, f.Hfull, g.left - 0.5 * s, g.name - 7.5 * s, g.right + 0.5 * s, g.name + 0.5 * s, h));
        // Left of the target's box, the grand total box (when printed) has a border
        // and a number: the crop starts just inside the target's box then.
        var tx = g.totalBox ? W - box + 0.3 * s : W - box - 1.0 * s, cx = g.totalBox ? W - box + 0.3 * s : W - box - 0.8 * s;
        out.target = run(net, band(V, full, f.Hfull, tx, g.club - 12.25 * s, W + 0.6 * s, g.club - 4.15 * s, h));
        out.cat = run(net, band(V, full, f.Hfull, cx, g.club - 4.15 * s, W + 0.6 * s, g.club + 1.65 * s, h));
        return out;
    }

    /**
     * Log-likelihood of a name in the name's band: after the colon of its label, or
     * straight after whatever comes first, the colon being small and often lost.
     */
    function nameScore(net, read, name) {
        return Math.max(score(read.name, net, ': ' + name, true), score(read.name, net, name, true));
    }

    /**
     * Log-likelihood of one archer's header: the target, the name after its label's
     * colon, the category. -> {target, name, cat, total}
     */
    function scoreArcher(net, read, a) {
        var t = score(read.target, net, fold(a.target, net.chars), false);
        var n = nameScore(net, read, fold(a.name, net.chars));
        var c = a.cat ? score(read.cat, net, fold(a.cat, net.chars), false) : 0;
        return {target: t, name: n, cat: c, total: t + n + c};
    }

    /**
     * How far an archer's scores fall below the free readings of the bands: small when
     * the text is theirs, even blurred; large when the bands hold something else (a
     * crop off its place, another card). -> {target, name, cat}
     */
    function gaps(read, s) {
        if (!read.free) read.free = {target: free(read.target), name: free(read.name), cat: free(read.cat)};
        return {target: read.free.target - s.target, name: read.free.name - s.name, cat: read.free.cat - s.cat};
    }

    /**
     * Whose card it is, as an operator would tell: the target first (big print, read
     * reliably), then the name to check it among the archers of that target, the
     * session being the one chosen on the page (0 for any).
     *
     * -> {status, archer, target, alternatives, read}
     *    status 'ok'       the target and the name agree, in the chosen session
     *           'session'  they agree, but in another session (archer = that one)
     *           'name'     the name read belongs to an archer of another target
     *           'unsure'   nothing reads well enough: the target is to be typed in
     *    read: what the bands say, read freely (for the operator)
     */
    /**
     * The archer whose target and name fit a reading best, sure or not, with how far below
     * the free reading they fall (per character, as identify() measures it): one reading
     * alone may not tell, several giving the same archer do.
     */
    function guess(net, read, roster) {
        if (!read.free) read.free = {target: free(read.target), name: free(read.name), cat: free(read.cat)};
        var top = null, bv = Infinity;
        roster.forEach(function (a) {
            var txt = fold(a.name, net.chars), tg = fold(a.target, net.chars);
            var v = (read.free.name - nameScore(net, read, txt)) / (txt.length + 2)
                + (read.free.target - score(read.target, net, tg, false)) / (tg.length + 2);
            if (v < bv) {
                bv = v;
                top = a;
            }
        });
        return top ? {archer: top, gap: bv} : null;
    }

    function identify(net, read, roster, session) {
        var out = {status: 'unsure', archer: null, alternatives: [],
            read: {target: greedy(read.target, net), name: greedy(read.name, net), cat: greedy(read.cat, net)}};
        if (!read.free) read.free = {target: free(read.target), name: free(read.name), cat: free(read.cat)};
        var byTarget = {};
        roster.forEach(function (a) { (byTarget[a.target] = byTarget[a.target] || []).push(a); });
        var targets = Object.keys(byTarget).map(function (t) {
            return {target: t, s: score(read.target, net, fold(t, net.chars), false)};
        }).sort(function (p, q) { return q.s - p.s; });
        if (!targets.length) return out;
        // A name's gap, per character: a short name fits a poor reading too easily.
        var name = function (a) {
            if (a._n === undefined) {
                var txt = fold(a.name, net.chars);
                a._n = (read.free.name - nameScore(net, read, txt)) / (txt.length + 2);
            }
            return a._n;
        };
        var inSession = function (a) { return !session || a.session === session; };
        var t1 = targets[0], sure = read.free.target - t1.s <= GAP_TARGET
            && (targets.length < 2 || t1.s - targets[1].s >= MARGIN_TARGET);
        out.target = t1.target;
        out.gaps = {target: read.free.target - t1.s, margin: targets.length > 1 ? t1.s - targets[1].s : Infinity};
        if (sure) {
            var on = byTarget[t1.target].filter(function (a) { return name(a) <= GAP_NAME; })
                .sort(function (p, q) { return name(p) - name(q); });
            var here = on.filter(inSession);
            if (here.length) {
                out.status = 'ok';
                out.archer = here[0];
                return clean(out, roster);
            }
            if (on.length) {
                out.status = 'session';
                out.archer = on[0];
                out.alternatives = on.slice(1);
                return clean(out, roster);
            }
        }
        // The name read, on its own, among all the archers.
        var byName = roster.filter(function (a) { return name(a) <= GAP_NAME_ALONE; }).sort(function (p, q) { return name(p) - name(q); });
        if (byName.length && (byName.length < 2 || name(byName[1]) - name(byName[0]) >= MARGIN_NAME)) {
            // A target read for sure but whose archers do not have this name: a conflict
            // for the operator to settle. Otherwise the name alone tells.
            out.status = sure ? 'name' : inSession(byName[0]) ? 'ok' : 'session';
            out.archer = byName[0];
        }
        return clean(out, roster);
    }

    function clean(out, roster) {
        if (out.archer) out.gaps.name = out.archer._n;
        roster.forEach(function (a) { delete a._n; });
        return out;
    }

    return {load: load, run: run, fold: fold, score: score, free: free, greedy: greedy, guess: guess, locate: locate,
        readHeader: readHeader, scoreArcher: scoreArcher, gaps: gaps, identify: identify,
        _internal: {band: band, inkBand: inkBand}};
});
