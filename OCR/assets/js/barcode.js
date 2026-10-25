/**
 * Code 39 reader for the barcode the core prints under the scorecard grid
 * (ScorePDF::DrawScoreNew(), font "DOf9Barcode": "*<licence>-<div>-<class>-<distance>*").
 *
 * The bars are read along lines of the straightened frame, sampled in the full
 * photo, where they are finest. Browser and Node alike, no dependency.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.OcrBarcode = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /*
     * Each character is 5 bars and 4 spaces, 3 of the 9 wide. For letters and digits,
     * 2 bars and 1 space are wide: ten pairs of wide bars, in the same order for each
     * of the four groups, the group being given by the wide space. The four remaining
     * characters have three wide spaces and no wide bar.
     */
    var TABLE = (function () {
        var bars = [[1, 9], [3, 9], [1, 3], [5, 9], [1, 5], [3, 5], [7, 9], [1, 7], [3, 7], [5, 7]];
        var groups = [['1234567890', 4], ['ABCDEFGHIJ', 6], ['KLMNOPQRST', 8], ['UVWXYZ-. *', 2]];
        var t = {};
        groups.forEach(function (g) {
            for (var i = 0; i < 10; i++) {
                var bits = [0, 0, 0, 0, 0, 0, 0, 0, 0];
                bits[bars[i][0] - 1] = 1;
                bits[bars[i][1] - 1] = 1;
                bits[g[1] - 1] = 1;
                t[bits.join('')] = g[0].charAt(i);
            }
        });
        t['010101000'] = '$';
        t['010100010'] = '/';
        t['010001010'] = '+';
        t['000101010'] = '%';
        return t;
    })();

    /** Runs of dark and light along a line of grey values. */
    function runs(samples) {
        var n = samples.length, s = new Float32Array(n), i;
        for (i = 0; i < n; i++) {
            s[i] = (samples[Math.max(0, i - 1)] + 2 * samples[i] + samples[Math.min(n - 1, i + 1)]) / 4;
        }
        // Local threshold: half-way between the darkest and lightest of a window of
        // about two characters, so that a shadow across the code does not matter.
        var win = Math.max(12, Math.round(n / 18)), out = [], dark = null, len = 0;
        for (i = 0; i < n; i++) {
            var lo = 255, hi = 0;
            for (var k = Math.max(0, i - win); k <= Math.min(n - 1, i + win); k++) {
                if (s[k] < lo) lo = s[k];
                if (s[k] > hi) hi = s[k];
            }
            var d = hi - lo < 40 ? false : s[i] < (lo + hi) / 2;
            if (d === dark) {
                len++;
            } else {
                if (dark !== null) out.push({dark: dark, len: len});
                dark = d;
                len = 1;
            }
        }
        if (dark !== null) out.push({dark: dark, len: len});
        return out;
    }

    /**
     * Runs measured between edges: the extremes of the slope of the smoothed line, a
     * falling edge entering a bar, a rising one leaving it. Blur closes the narrow
     * space between two wide bars before the threshold sees it; the dip it leaves
     * still makes a pair of edges.
     */
    function edgeRuns(samples) {
        var n = samples.length, s = new Float32Array(n), g = new Float32Array(n), i;
        for (i = 0; i < n; i++) {
            var a = samples[Math.max(0, i - 2)], b = samples[Math.max(0, i - 1)], c = samples[i];
            var d = samples[Math.min(n - 1, i + 1)], e = samples[Math.min(n - 1, i + 2)];
            s[i] = (a + 4 * b + 6 * c + 4 * d + e) / 16;
        }
        var gmax = 0;
        for (i = 1; i < n - 1; i++) {
            g[i] = s[i + 1] - s[i - 1];
            if (Math.abs(g[i]) > gmax) gmax = Math.abs(g[i]);
        }
        if (gmax < 8) return [];
        var T = 0.12 * gmax, edges = [];
        for (i = 2; i < n - 2; i++) {
            var v = g[i];
            if (Math.abs(v) < T) continue;
            if (!(Math.abs(v) >= Math.abs(g[i - 1]) && Math.abs(v) > Math.abs(g[i + 1]))) continue;
            // Sub-sample position of the extreme (parabola through three slopes).
            var den = g[i - 1] - 2 * v + g[i + 1], pos = i + (den ? 0.5 * (g[i - 1] - g[i + 1]) / den : 0);
            var fall = v < 0, last = edges[edges.length - 1];
            if (last && last.fall === fall) {
                // Two edges the same way in a row: keep the stronger.
                if (Math.abs(v) > last.mag) edges[edges.length - 1] = {pos: pos, fall: fall, mag: Math.abs(v)};
            } else {
                edges.push({pos: pos, fall: fall, mag: Math.abs(v)});
            }
        }
        var out = [];
        for (i = 1; i < edges.length; i++) {
            // Between a falling edge and the next rising one lies a bar.
            out.push({dark: edges[i - 1].fall, len: edges[i].pos - edges[i - 1].pos});
        }
        return out;
    }

    /**
     * The character of 9 runs starting with a bar at index i, or null.
     * edge: 'first' or 'last' when the first or last bar may have merged with what
     * touches the code (the edge of the sheet, a line): that bar is then read narrow
     * when it is far too wide to be either.
     */
    function character(r, i, edge) {
        if (i + 9 > r.length || !r[i].dark) return null;
        var w = [], k;
        for (k = 0; k < 9; k++) w.push(r[i + k].len);
        if (edge) {
            var at = edge === 'first' ? 0 : 8, others = w.filter(function (v, j) { return j !== at; }).sort(function (a, b) { return a - b; });
            if (w[at] > 2 * others[7]) w[at] = others[0];
        }
        var order = w.map(function (v, j) { return j; }).sort(function (a, b) { return w[b] - w[a]; });
        // The three wide elements must stand clear of the six narrow ones. Blur makes
        // the narrow ones vary a lot among themselves, which does not matter, and
        // brings both kinds closer: the start and stop characters, the vote between
        // scanlines and the server's lookup of the code keep a misreading out.
        var wideMin = w[order[2]], narrowMax = w[order[3]], wideMax = w[order[0]];
        if (wideMin < 1.2 * narrowMax || wideMax > 2.4 * wideMin) return null;
        var bits = [0, 0, 0, 0, 0, 0, 0, 0, 0];
        bits[order[0]] = bits[order[1]] = bits[order[2]] = 1;
        var c = TABLE[bits.join('')];
        if (!c) return null;
        var narrow = 0;
        for (k = 3; k < 9; k++) narrow += w[order[k]];
        return {c: c, narrow: narrow / 6, wide: (w[order[0]] + w[order[1]] + w[order[2]]) / 3};
    }

    /** Text between a start and a stop asterisk, or null. */
    function decode(r) {
        for (var i = 0; i + 9 <= r.length; i++) {
            var start = character(r, i, 'first');
            if (!start || start.c !== '*') continue;
            var text = '', j = i + 10, ok = false;
            while (j + 9 <= r.length) {
                var gap = r[j - 1];
                if (gap.dark || gap.len > 0.8 * start.wide) break;
                var ch = character(r, j);
                // The stop character's last bar may run into whatever follows the code.
                if (!ch || (ch.c !== '*' && text.length >= 3)) {
                    var stop = character(r, j, 'last');
                    if (stop && stop.c === '*') ch = stop;
                }
                if (!ch) break;
                // Every character of one code has the same module width.
                if (ch.wide > 1.6 * start.wide || ch.wide < 0.6 * start.wide) break;
                if (ch.c === '*') { ok = true; break; }
                text += ch.c;
                j += 10;
            }
            if (ok && text.length >= 3) return text;
        }
        return null;
    }

    /** Most frequent text among several readings, with how many agree. */
    function vote(texts) {
        var count = {}, best = null;
        texts.forEach(function (t) {
            if (!t) return;
            count[t] = (count[t] || 0) + 1;
            if (!best || count[t] > count[best]) best = t;
        });
        return best ? {text: best, votes: count[best], reads: texts.filter(Boolean).length} : null;
    }

    /** Unsharp mask of a line: gives back to the narrow elements the contrast blur took. */
    function sharpen(s, sigma, alpha) {
        var n = s.length, out = new Float32Array(n), r = Math.ceil(3 * sigma), k = [], d, i;
        for (d = -r; d <= r; d++) k.push(Math.exp(-d * d / (2 * sigma * sigma)));
        var ks = k.reduce(function (a, b) { return a + b; }, 0);
        for (i = 0; i < n; i++) {
            var acc = 0;
            for (d = -r; d <= r; d++) acc += s[Math.min(n - 1, Math.max(0, i + d))] * k[d + r];
            out[i] = Math.max(0, Math.min(255, s[i] + alpha * (s[i] - acc / ks)));
        }
        return out;
    }

    /**
     * Reading of the strip under the body of one straightened frame.
     *
     * In the straightened frame the bars are upright, so the lines across them can be
     * averaged into one clean profile; it is read as it is and sharpened, by edges and
     * by threshold, on the whole height of the bars and on each half. Single lines are
     * read too. The most frequent text wins.
     */
    function strip(V, full, f) {
        var W = f.W, Hb = f.Hb, rowH = f.rowH, Hf = f.Hfull, u0 = -0.12 * W, u1 = 0.8 * W;
        var v0 = Hb + 0.12 * rowH, v1 = Hb + 0.88 * rowH, LINES = 21, k, i;
        // Two samples per photo pixel: the narrowest bars are only a few pixels wide.
        var n = Math.max(400, Math.round(2 * V.fullLength(Hf, u0, v0, u1, v0))), lines = [], texts = [];
        for (k = 0; k < LINES; k++) lines.push(V.scanline(full, Hf, u0, u1, v0 + (v1 - v0) * k / (LINES - 1), n));
        function mean(a, b) {
            var p = new Float32Array(n);
            for (k = a; k <= b; k++) for (i = 0; i < n; i++) p[i] += lines[k][i] / (b - a + 1);
            return p;
        }
        // Blur scale: a narrow element is about a sixteenth of a character, which is
        // about one nineteenth of the strip for a usual code; a few samples either way.
        var sigma = Math.max(1.5, n / 700);
        [mean(0, LINES - 1), mean(0, 10), mean(10, LINES - 1)].forEach(function (p) {
            [p, sharpen(p, sigma, 1), sharpen(p, sigma, 2)].forEach(function (q) {
                texts.push(decode(edgeRuns(q)));
                texts.push(decode(runs(q)));
            });
        });
        for (k = 0; k < LINES; k += 2) texts.push(decode(edgeRuns(lines[k])) || decode(runs(lines[k])));
        return vote(texts);
    }

    /**
     * Read the barcode under the grid of an analysed photo.
     *
     * The strip under the body is read in each orientation of the card, the most likely
     * first; the one where a code reads also tells which way up the card is.
     *
     * @param {Object} V The vision module.
     * @param {Object} full Grey photo at full resolution.
     * @param {Object} a Analysis of V.analyse().
     * @return {Object|null} {text, votes, turn}.
     */
    function find(V, full, a) {
        var ts = V.turns(a);
        for (var i = 0; i < ts.length; i++) {
            var f = V.frame(a, ts[i]);
            if (!f) continue;
            var r = strip(V, full, f);
            if (r) return {text: r.text, votes: r.votes, turn: ts[i]};
        }
        return null;
    }

    /**
     * As find(), on the frames of each way up given (plain: {turn, W, Hb, rowH, Hfull}, the most
     * likely first): a worker has no analysis.
     */
    function findIn(V, full, frames) {
        for (var i = 0; i < frames.length; i++) {
            var r = strip(V, full, frames[i]);
            if (r) return {text: r.text, votes: r.votes, turn: frames[i].turn};
        }
        return null;
    }

    return {find: find, findIn: findIn, decode: decode, runs: runs, edgeRuns: edgeRuns, TABLE: TABLE};
});
