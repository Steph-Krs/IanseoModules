/**
 * Reading of the handwritten cells cut out of a scorecard.
 *
 * Two small convolutional networks, trained offline (assets/model/*.json):
 * - arrow: a whole arrow cell, 28 x 48, into M, 1-9, 10, X or empty;
 * - digit: one character, 28 x 28 as MNIST, into 0-9 or junk.
 * A sum, a running total or a count is cut into characters (connected blots of ink)
 * read one by one; a few readings of the whole number come out, each with its
 * probability, for the arithmetic checks to choose from.
 *
 * Every cell becomes first an "ink map", 0 = paper, 1 = ink, the form the networks
 * were trained on. Browser and Node alike, no dependency.
 */
(function (root, factory) {
    var node = typeof module === 'object' && module.exports;
    var api = factory(node ? require('./nn.js') : root.OcrNN);
    if (node) module.exports = api;
    else root.OcrRecognizer = api;
})(typeof self !== 'undefined' ? self : this, function (NN) {
    'use strict';

    // ------------------------------------------------------------- networks

    function floats(b64) {
        var bin = typeof atob === 'function' ? atob(b64) : Buffer.from(b64, 'base64').toString('binary');
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return new Float32Array(bytes.buffer);
    }

    /** A network from its JSON export (train/cnn.py). */
    function load(doc) {
        return {
            h: doc.input[0], w: doc.input[1], labels: doc.labels,
            layers: doc.layers.map(function (l) {
                var o = {type: l.type, cin: l.cin, cout: l.cout, nin: l.nin, nout: l.nout};
                if (l.W) o.W = floats(l.W);
                if (l.b) o.b = floats(l.b);
                return o;
            })
        };
    }

    /** Forward pass, channels first as in numpy; returns the class probabilities. */
    function run(net, input) {
        var x = input, c = 1, h = net.h, w = net.w;
        net.layers.forEach(function (l) {
            var out, i, k, y, xx;
            if (l.type === 'conv') {
                x = NN.conv3(x, l.cin, l.cout, h, w, l.W, l.b);
                c = l.cout;
            } else if (l.type === 'relu') {
                out = new Float32Array(x.length);
                for (i = 0; i < x.length; i++) out[i] = x[i] > 0 ? x[i] : 0;
                x = out;
            } else if (l.type === 'pool') {
                var h2 = Math.floor(h / 2), w2 = Math.floor(w / 2);
                out = new Float32Array(c * h2 * w2);
                for (k = 0; k < c; k++) {
                    for (y = 0; y < h2; y++) {
                        for (xx = 0; xx < w2; xx++) {
                            var p = k * h * w + 2 * y * w + 2 * xx;
                            out[(k * h2 + y) * w2 + xx] = Math.max(x[p], x[p + 1], x[p + w], x[p + w + 1]);
                        }
                    }
                }
                h = h2;
                w = w2;
                x = out;
            } else if (l.type === 'dense') {
                x = NN.dense(x, l.nin, l.nout, l.W, l.b);
            }
        });
        var m = -Infinity, s = 0, probs = new Array(x.length), i2;
        for (i2 = 0; i2 < x.length; i2++) m = Math.max(m, x[i2]);
        for (i2 = 0; i2 < x.length; i2++) s += (probs[i2] = Math.exp(x[i2] - m));
        for (i2 = 0; i2 < x.length; i2++) probs[i2] /= s;
        return probs;
    }

    // ------------------------------------------------------------- ink maps

    /**
     * Ink map of a grey cell: paper white from a high percentile, ink from a low
     * one, never less than 60 grey levels apart so that an empty cell stays empty.
     */
    function inkMap(g, rect) {
        var n = g.w * g.h, vals = g.d, white, dark, x, y, ns;
        // Whole grey levels (a crop): the two ranks read off a histogram, the very values a sort gives.
        if ((g.d instanceof Uint8Array || g.d instanceof Uint8ClampedArray) && n) {
            var hist = new Int32Array(256);
            ns = 0;
            if (rect) {
                for (y = rect.y0; y < rect.y1; y++) {
                    for (x = rect.x0; x < rect.x1; x++) {
                        hist[g.d[y * g.w + x]]++;
                        ns++;
                    }
                }
            } else {
                for (x = 0; x < n; x++) hist[g.d[x]]++;
                ns = n;
            }
            if (ns) {
                white = rankOf(hist, Math.floor(0.85 * (ns - 1)));
                dark = rankOf(hist, Math.floor(0.02 * (ns - 1)));
            }
        }
        if (white === undefined) {
            if (rect) {
                vals = [];
                for (y = rect.y0; y < rect.y1; y++) for (x = rect.x0; x < rect.x1; x++) vals.push(g.d[y * g.w + x]);
            }
            var sorted = Array.prototype.slice.call(vals).sort(function (a, b) { return a - b; });
            ns = sorted.length;
            white = sorted[Math.floor(0.85 * (ns - 1))];
            dark = sorted[Math.floor(0.02 * (ns - 1))];
        }
        var den = Math.max(60, white - dark), out = new Float32Array(n);
        for (var i = 0; i < n; i++) {
            var v = (white - g.d[i]) / den;
            out[i] = v < 0.08 ? 0 : v > 1 ? 1 : v;
        }
        return {w: g.w, h: g.h, d: out};
    }

    /** Average k x k blocks (the training cells are drawn at twice the size, then reduced). */
    function reduce(m, k) {
        var w = Math.floor(m.w / k), h = Math.floor(m.h / k), out = new Float32Array(w * h), x, y, i, j;
        for (y = 0; y < h; y++) {
            for (x = 0; x < w; x++) {
                var s = 0;
                for (j = 0; j < k; j++) for (i = 0; i < k; i++) s += m.d[(y * k + j) * m.w + x * k + i];
                out[y * w + x] = s / (k * k);
            }
        }
        return {w: w, h: h, d: out};
    }

    /** Bilinear resize of an ink map. */
    function resize(m, w, h) {
        var out = new Float32Array(w * h), x, y;
        for (y = 0; y < h; y++) {
            var sy = Math.min(m.h - 1, Math.max(0, (y + 0.5) * m.h / h - 0.5)), y0 = Math.floor(sy), y1 = Math.min(m.h - 1, y0 + 1), fy = sy - y0;
            for (x = 0; x < w; x++) {
                var sx = Math.min(m.w - 1, Math.max(0, (x + 0.5) * m.w / w - 0.5)), x0 = Math.floor(sx), x1 = Math.min(m.w - 1, x0 + 1), fx = sx - x0;
                out[y * w + x] = m.d[y0 * m.w + x0] * (1 - fx) * (1 - fy) + m.d[y0 * m.w + x1] * fx * (1 - fy)
                    + m.d[y1 * m.w + x0] * (1 - fx) * fy + m.d[y1 * m.w + x1] * fx * fy;
            }
        }
        return {w: w, h: h, d: out};
    }

    // ------------------------------------------------------- reading options
    //
    // Options of the reading, each off unless asked (opts of readArrow / readNumber):
    // - cubic: cells sampled with a cubic kernel rather than a bilinear one;
    // - sharpen: an unsharp mask at the scale of the photo's own pixels;
    // - own: the cell cut out with a margin, the grid's lines taken out, and each stroke
    //   given to the cell that holds most of it: what runs over the border is read with
    //   its cell, what the neighbour's writing pushes in is left out;
    // - cut: two touching digits parted along the cheapest way down through the ink
    //   rather than a straight column, each part framed on its own ink.

    /** Keys cubic kernel (a = -0.5) weights of the four samples around fraction t. */
    function cubicWeights(t, out) {
        var t2 = t * t, t3 = t2 * t;
        out[0] = -0.5 * t3 + t2 - 0.5 * t;
        out[1] = 1.5 * t3 - 2.5 * t2 + 1;
        out[2] = -1.5 * t3 + 2 * t2 + 0.5 * t;
        out[3] = 0.5 * t3 - 0.5 * t2;
    }

    function cubicSample(V, g, x, y, wx, wy) {
        var x0 = Math.floor(x) - 1, y0 = Math.floor(y) - 1;
        if (x0 < 0 || y0 < 0 || x0 + 3 > g.w - 1 || y0 + 3 > g.h - 1) return V.sample(g, x, y);
        cubicWeights(x - x0 - 1, wx);
        cubicWeights(y - y0 - 1, wy);
        var s = 0;
        for (var j = 0; j < 4; j++) {
            var r = (y0 + j) * g.w + x0;
            s += wy[j] * (wx[0] * g.d[r] + wx[1] * g.d[r + 1] + wx[2] * g.d[r + 2] + wx[3] * g.d[r + 3]);
        }
        return s < 0 ? 0 : s > 255 ? 255 : s;
    }

    /** V.crop() with the cubic kernel. */
    function cubicCrop(V, full, Hfull, u0, v0, u1, v1, outW, outH) {
        var g = V.makeGray(outW, outH), wx = new Float64Array(4), wy = new Float64Array(4);
        for (var y = 0; y < outH; y++) {
            var v = v0 + (v1 - v0) * (y + 0.5) / outH;
            for (var x = 0; x < outW; x++) {
                var p = V.apply(Hfull, u0 + (u1 - u0) * (x + 0.5) / outW, v), s = cubicSample(V, full, p.x, p.y, wx, wy);
                g.d[y * outW + x] = isNaN(s) ? 255 : s;
            }
        }
        return g;
    }

    /** Separable Gaussian blur of a grey picture (float result). */
    function blur(g, sigma) {
        var r = Math.max(1, Math.ceil(2.5 * sigma)), k = [], s = 0, i, x, y;
        for (i = -r; i <= r; i++) s += (k[i + r] = Math.exp(-i * i / (2 * sigma * sigma)));
        k = k.map(function (v) { return v / s; });
        var w = g.w, h = g.h, tmp = new Float32Array(w * h), out = new Float32Array(w * h);
        for (y = 0; y < h; y++) {
            for (x = 0; x < w; x++) {
                var a = 0;
                for (i = -r; i <= r; i++) a += k[i + r] * g.d[y * w + Math.min(w - 1, Math.max(0, x + i))];
                tmp[y * w + x] = a;
            }
        }
        for (y = 0; y < h; y++) {
            for (x = 0; x < w; x++) {
                var b = 0;
                for (i = -r; i <= r; i++) b += k[i + r] * tmp[Math.min(h - 1, Math.max(0, y + i)) * w + x];
                out[y * w + x] = b;
            }
        }
        return out;
    }

    /** Unsharp mask; zoom is how many output pixels one pixel of the photo covers. */
    function sharpen(V, g, zoom) {
        var b = blur(g, Math.max(0.8, 0.8 * zoom)), out = V.makeGray(g.w, g.h);
        for (var i = 0; i < g.d.length; i++) {
            var v = g.d[i] + 1.2 * (g.d[i] - b[i]);
            out.d[i] = v < 0 ? 0 : v > 255 ? 255 : v;
        }
        return out;
    }

    /** Grey picture of a part of the straightened frame, with the sampling options. */
    function picture(V, full, Hfull, u0, v0, u1, v1, w, h, opts) {
        var g = opts && opts.cubic ? cubicCrop(V, full, Hfull, u0, v0, u1, v1, w, h) : V.crop(full, Hfull, u0, v0, u1, v1, w, h);
        if (opts && opts.sharpen) {
            var um = (u0 + u1) / 2, len = V.fullLength(Hfull, um, v0, um, v1);
            g = sharpen(V, g, len > 0 ? h / len : 1);
        }
        return g;
    }

    /** Grey picture of a cell of the straightened frame, slightly inside its borders. */
    function cellPicture(V, full, Hfull, cell, w, h, inset, opts) {
        var du = (cell.u1 - cell.u0) * inset, dv = (cell.v1 - cell.v0) * inset;
        return picture(V, full, Hfull, cell.u0 + du, cell.v0 + dv, cell.u1 - du, cell.v1 - dv, w, h, opts);
    }

    // ------------------------------------------------------- strokes owned

    var MARGIN = 0.45;      // around the cell, in cell heights: where its strokes may run
    // A grid line runs over most of the window (1.9 cells long): a stroke of the cell never
    // does, a tall 1 included. At most LINE_MAX of the window's height thick, and broken
    // for LINE_GAP pixels at most.
    var LINE_SHARE = 0.6, LINE_MAX = 0.08, LINE_GAP = 3;

    /**
     * Longest unbroken stretch of ink of each column (or row) of an ink map, a line of the
     * grid being allowed to lean by two pixels and to fade for a few.
     */
    function stretches(m, vertical) {
        var w = m.w, h = m.h, n = vertical ? w : h, len = vertical ? h : w, out = new Float32Array(n);
        for (var i = 0; i < n; i++) {
            var best = 0, cur = 0, gap = 0;
            for (var j = 0; j < len; j++) {
                var on = false;
                for (var k = Math.max(0, i - 2); k <= Math.min(n - 1, i + 2) && !on; k++) {
                    if ((vertical ? m.d[j * w + k] : m.d[k * w + j]) >= 0.3) on = true;
                }
                if (on) {
                    cur += gap + 1;
                    gap = 0;
                } else if (cur && gap < LINE_GAP) {
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
    }

    /**
     * Grid lines out of an ink map: a column (row) along which the ink runs unbroken over
     * most of the window is a line (writing, the next cells' figures or a shaded row
     * never do); its pixels go, except where a stroke crosses it (ink on both sides).
     * A band too thick to be a line (a shaded row) is left alone.
     */
    function unline(m) {
        var w = m.w, h = m.h, x, y, thick = LINE_MAX * h;
        function bands(prof, n, len) {
            var out = [], i = 0;
            while (i < n) {
                if (prof[i] < LINE_SHARE * len) {
                    i++;
                    continue;
                }
                var a = i;
                while (i + 1 < n && prof[i + 1] >= LINE_SHARE * len) i++;
                if (i - a + 1 <= thick) out.push([a, i]);
                i++;
            }
            return out;
        }
        var d = m.d, out = Float32Array.from(d);
        bands(stretches(m, true), w, h).forEach(function (b) {
            for (y = 0; y < h; y++) {
                var l = b[0] - 2, r = b[1] + 2;
                var crossed = l >= 0 && r < w && d[y * w + l] >= 0.3 && d[y * w + r] >= 0.3;
                if (!crossed) for (x = b[0]; x <= b[1]; x++) out[y * w + x] = 0;
            }
        });
        bands(stretches(m, false), h, w).forEach(function (b) {
            for (x = 0; x < w; x++) {
                var t = b[0] - 2, u = b[1] + 2;
                var crossed = t >= 0 && u < h && d[t * w + x] >= 0.3 && d[u * w + x] >= 0.3;
                if (!crossed) for (y = b[0]; y <= b[1]; y++) out[y * w + x] = 0;
            }
        });
        return {w: w, h: h, d: out};
    }

    /**
     * The strokes of the window that belong to the cell (rect): those with most of their
     * ink inside it, kept whole, with the faint edge around them. -> ink map and the box
     * of what was kept.
     */
    /** The value of rank k (from 0, the smallest first) of a histogram of whole grey levels. */
    function rankOf(hist, k) {
        for (var v = 0, c = 0; v < 256; v++) {
            c += hist[v];
            if (c > k) return v;
        }
        return 255;
    }

    function owned(m, rect) {
        var bl = blots(m, 0.25), keep = new Uint8Array(bl.list.length + 1), box = {x0: rect.x0, y0: rect.y0, x1: rect.x1 - 1, y1: rect.y1 - 1};
        var inside = new Float64Array(bl.list.length + 1), i, x, y, w = m.w;
        for (y = 0; y < m.h; y++) {
            for (x = 0; x < w; x++) {
                var id = bl.labels[y * w + x];
                if (id && x >= rect.x0 && x < rect.x1 && y >= rect.y0 && y < rect.y1) inside[id] += m.d[y * w + x];
            }
        }
        bl.list.forEach(function (b) {
            if (inside[b.id] < 0.5 * b.mass) return;
            keep[b.id] = true;
            box.x0 = Math.min(box.x0, b.x0);
            box.y0 = Math.min(box.y0, b.y0);
            box.x1 = Math.max(box.x1, b.x1);
            box.y1 = Math.max(box.y1, b.y1);
        });
        var out = new Float32Array(m.d.length);
        for (y = 0; y < m.h; y++) {
            for (x = 0; x < w; x++) {
                i = y * w + x;
                var lab = bl.labels[i];
                if (lab) {
                    if (keep[lab]) out[i] = m.d[i];
                    continue;
                }
                // Below the level: kept next to a kept stroke (its soft edge).
                for (var dy = -1; dy <= 1 && !out[i]; dy++) {
                    for (var dx = -1; dx <= 1; dx++) {
                        var nx = x + dx, ny = y + dy;
                        if (nx >= 0 && ny >= 0 && nx < w && ny < m.h && keep[bl.labels[ny * w + nx]]) {
                            out[i] = m.d[i];
                            break;
                        }
                    }
                }
            }
        }
        return {m: {w: w, h: m.h, d: out}, box: box};
    }

    /**
     * Ink of a cell with what belongs to it (option own): the cell (inset as for the
     * plain reading) sampled at w x h, inside a window MARGIN cell heights wider all
     * round. The ink levels are taken from the cell itself, as without the option.
     * -> {m: window ink map, rect: the cell in it, box: the cell and its strokes}
     */
    function ownInk(V, full, Hfull, cell, w, h, inset, opts) {
        var cw = cell.u1 - cell.u0, ch = cell.v1 - cell.v0, du = cw * inset, dv = ch * inset;
        var iu = cw - 2 * du, iv = ch - 2 * dv, mu = MARGIN * ch, mv = MARGIN * ch;
        var px = Math.round(mu * w / iu), py = Math.round(mv * h / iv);
        var W = w + 2 * px, H = h + 2 * py, uPer = iu / w, vPer = iv / h;
        var g = picture(V, full, Hfull, cell.u0 + du - px * uPer, cell.v0 + dv - py * vPer,
            cell.u1 - du + px * uPer, cell.v1 - dv + py * vPer, W, H, opts);
        var rect = {x0: px, y0: py, x1: px + w, y1: py + h};
        var o = owned(unline(inkMap(g, rect)), rect);
        return {m: o.m, rect: rect, box: o.box};
    }

    /** Part of an ink map, [x0, x1) x [y0, y1). */
    function part(m, x0, y0, x1, y1) {
        var w = x1 - x0, h = y1 - y0, out = new Float32Array(w * h);
        for (var y = 0; y < h; y++) for (var x = 0; x < w; x++) out[y * w + x] = m.d[(y0 + y) * m.w + x0 + x];
        return {w: w, h: h, d: out};
    }

    // ------------------------------------------------------------ reframing

    var FRAME_MARGIN = 0.5;  // window looked into around a cell, share of its smaller side
    var FRAME_PX = 48;       // ...drawn at that many pixels for the cell's height
    var FRAME_REACH = 0.35;  // a border moved by this share of the cell's size at most
    var FRAME_SIZE = 0.2;    // ...the cell's width (height) changed by this share at most
    var FRAME_LINE = 0.6;    // a line of the grid: inked over this share of the window at least

    /**
     * Per column (vertical) or row of an ink map, its longest run of ink, leaning by a
     * pixel, gaps of two bridged: a line of the grid runs across the whole window, a
     * written stroke over part of it.
     */
    function inkRuns(m, vertical) {
        var n = vertical ? m.w : m.h, len = vertical ? m.h : m.w, out = new Float32Array(n);
        var at = vertical ? function (i, j) { return m.d[j * m.w + i] >= 0.3; } : function (i, j) { return m.d[i * m.w + j] >= 0.3; };
        for (var i = 0; i < n; i++) {
            var best = 0, cur = 0, gap = 0;
            for (var j = 0; j < len; j++) {
                if (at(i, j) || (i > 0 && at(i - 1, j)) || (i + 1 < n && at(i + 1, j))) {
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
    }

    /** The lines of a profile (runs as long as a line's), each at the middle of its width: [{at, s}]. */
    function profileLines(prof, span) {
        var need = FRAME_LINE * span, out = [], from = -1;
        for (var i = 0; i <= prof.length; i++) {
            var on = i < prof.length && prof[i] >= need;
            if (on && from < 0) from = i;
            if (!on && from >= 0) {
                var s = 0, sw = 0, top = 0;
                for (var j = from; j < i; j++) {
                    s += j * prof[j];
                    sw += prof[j];
                    top = Math.max(top, prof[j]);
                }
                out.push({at: s / sw, s: top / span});
                from = -1;
            }
        }
        return out;
    }

    /**
     * The two borders of a cell along one axis on lines found near them: [shift of the
     * first, shift of the second] in window pixels, or null. Both on a line when two fit
     * (the size kept within FRAME_SIZE); else the one found moves the cell whole.
     */
    function bordersOn(lines, a0, b0, size) {
        var reach = FRAME_REACH * size, best = null;
        var near = function (p) { return lines.filter(function (l) { return Math.abs(l.at - p) <= reach; }); };
        var A = near(a0), B = near(b0);
        A.forEach(function (a) {
            B.forEach(function (b) {
                if (Math.abs(b.at - a.at - size) > FRAME_SIZE * size) return;
                var score = a.s + b.s - (Math.abs(a.at - a0) + Math.abs(b.at - b0)) / size;
                if (!best || score > best.score) best = {score: score, d: [a.at - a0, b.at - b0]};
            });
        });
        if (best) return best.d;
        // One border only on a line: the cell moved as a whole (the other has none near).
        [[A, a0], [B, b0]].forEach(function (side) {
            side[0].forEach(function (l) {
                var score = l.s - Math.abs(l.at - side[1]) / size - 1;
                if (!best || score > best.score) best = {score: score, d: [l.at - side[1], l.at - side[1]]};
            });
        });
        return best ? best.d : null;
    }

    /**
     * A cell put on its own lines, each border on its own: where the frame's homography
     * puts it is only about right (a lens not quite undone, a sheet not flat, a camera
     * moving), so the grid's lines are looked for in a window around it, and each border
     * set on the one nearest. The cell's size changes little; a border with no line near
     * stays as it was. -> the cell (a copy, moved) or the same cell.
     */
    function reframe(V, full, Hfull, cell) {
        var w = cell.u1 - cell.u0, h = cell.v1 - cell.v0;
        if (!(w > 0 && h > 0)) return cell;
        var m = FRAME_MARGIN * Math.min(w, h), k = FRAME_PX / h;
        var W = Math.max(16, Math.round((w + 2 * m) * k)), H = Math.max(16, Math.round((h + 2 * m) * k));
        var ink = inkMap(V.crop(full, Hfull, cell.u0 - m, cell.v0 - m, cell.u1 + m, cell.v1 + m, W, H));
        var x = bordersOn(profileLines(inkRuns(ink, true), H), m * k, (m + w) * k, w * k);
        var y = bordersOn(profileLines(inkRuns(ink, false), W), m * k, (m + h) * k, h * k);
        if (!x && !y) return cell;
        var out = Object.assign({}, cell);
        if (x) {
            out.u0 = cell.u0 + x[0] / k;
            out.u1 = cell.u1 + x[1] / k;
        }
        if (y) {
            out.v0 = cell.v0 + y[0] / k;
            out.v1 = cell.v1 + y[1] / k;
        }
        return out;
    }

    // ---------------------------------------------------------------- arrows

    /** What the arrow network sees of a cell: its ink, sampled finer then halved. */
    function arrowInput(V, net, full, Hfull, cell, opts) {
        if (opts && opts.own) {
            var o = ownInk(V, full, Hfull, cell, net.w * 2, net.h * 2, 0.03, opts);
            return reduce(part(o.m, o.rect.x0, o.rect.y0, o.rect.x1, o.rect.y1), 2);
        }
        return reduce(inkMap(cellPicture(V, full, Hfull, cell, net.w * 2, net.h * 2, 0.03, opts)), 2);
    }

    /**
     * Probabilities of each arrow label for one cell.
     *
     * @return {Array} [{label, p}], the most likely first.
     */
    function readArrow(V, net, full, Hfull, cell, opts) {
        if (opts && opts.reframe) cell = reframe(V, full, Hfull, cell);
        var p = run(net, arrowInput(V, net, full, Hfull, cell, opts).d);
        return net.labels.map(function (l, i) { return {label: l, p: p[i]}; }).sort(function (a, b) { return b.p - a.p; });
    }

    // --------------------------------------------------------------- numbers

    /** Connected blots of an ink map above a level, with their boxes. */
    function blots(m, level) {
        var w = m.w, h = m.h, lab = new Int32Array(w * h), out = [], stack = [], i;
        for (i = 0; i < w * h; i++) {
            if (lab[i] || m.d[i] < level) continue;
            var b = {x0: w, y0: h, x1: -1, y1: -1, area: 0, mass: 0, id: out.length + 1};
            lab[i] = b.id;
            stack.push(i);
            while (stack.length) {
                var p = stack.pop(), px = p % w, py = (p - px) / w;
                b.area++;
                b.mass += m.d[p];
                if (px < b.x0) b.x0 = px;
                if (px > b.x1) b.x1 = px;
                if (py < b.y0) b.y0 = py;
                if (py > b.y1) b.y1 = py;
                for (var dy = -1; dy <= 1; dy++) {
                    for (var dx = -1; dx <= 1; dx++) {
                        var nx = px + dx, ny = py + dy;
                        if (nx < 0 || ny < 0 || nx >= w || ny >= h) continue;
                        var q = ny * w + nx;
                        if (!lab[q] && m.d[q] >= level) {
                            lab[q] = b.id;
                            stack.push(q);
                        }
                    }
                }
            }
            out.push(b);
        }
        return {list: out, labels: lab};
    }

    /**
     * MNIST-like input of one character: its box scaled into 20 x 20, centred in 28 x 28.
     * With within(x, y), only those pixels of the blot, framed on their own ink.
     */
    function charInput(m, lab, b, x0, x1, within) {
        var y0 = b.y0, y1 = b.y1, x, y, p;
        if (within) {
            var tx0 = x1 + 1, tx1 = x0 - 1, ty0 = y1 + 1, ty1 = y0 - 1;
            for (y = y0; y <= y1; y++) {
                for (x = x0; x <= x1; x++) {
                    p = y * m.w + x;
                    if (m.d[p] < 0.3 || b.ids.indexOf(lab[p]) < 0 || !within(x, y)) continue;
                    if (x < tx0) tx0 = x;
                    if (x > tx1) tx1 = x;
                    if (y < ty0) ty0 = y;
                    if (y > ty1) ty1 = y;
                }
            }
            if (tx1 >= tx0) {
                x0 = tx0;
                x1 = tx1;
                y0 = ty0;
                y1 = ty1;
            }
        }
        var bw = x1 - x0 + 1, bh = y1 - y0 + 1, sub = new Float32Array(bw * bh);
        for (y = 0; y < bh; y++) {
            for (x = 0; x < bw; x++) {
                p = (y0 + y) * m.w + x0 + x;
                sub[y * bw + x] = b.ids.indexOf(lab[p]) >= 0 && (!within || within(x0 + x, y0 + y)) ? m.d[p] : 0;
            }
        }
        var s = 20 / Math.max(bw, bh), tw = Math.max(1, Math.round(bw * s)), th = Math.max(1, Math.round(bh * s));
        var r = resize({w: bw, h: bh, d: sub}, tw, th), out = new Float32Array(28 * 28);
        var ox = Math.floor((28 - tw) / 2), oy = Math.floor((28 - th) / 2);
        for (y = 0; y < th; y++) for (x = 0; x < tw; x++) out[(oy + y) * 28 + ox + x] = r.d[y * tw + x];
        return out;
    }

    /** Thinnest column of the middle part of [x0, x1]: where two touching digits meet. */
    function thinnest(m, y0, y1, x0, x1) {
        var w = x1 - x0 + 1, best = -1, bv = Infinity;
        for (var x = x0 + Math.floor(w * 0.3); x <= x1 - Math.floor(w * 0.3); x++) {
            var s = 0;
            for (var y = y0; y <= y1; y++) s += m.d[y * m.w + x];
            if (s < bv) { bv = s; best = x; }
        }
        return best < 0 ? x0 + Math.floor(w / 2) : best;
    }

    /**
     * Cheapest way down through a blot, between x0 and x1 (option cut): one column per
     * row, moving at most one column from a row to the next, the ink it crosses as its
     * cost. Where two digits touch or overlap, it goes round the one to reach the gap.
     * -> the column of each row, from b.y0.
     */
    function cutPath(m, lab, b, x0, x1) {
        var rows = b.y1 - b.y0 + 1, n = x1 - x0 + 1;
        if (n < 3) return null;
        var cost = new Float64Array(rows * n), from = new Int8Array(rows * n), r, k, d;
        for (r = 0; r < rows; r++) {
            for (k = 0; k < n; k++) {
                var p = (b.y0 + r) * m.w + x0 + k, ink = b.ids.indexOf(lab[p]) >= 0 ? m.d[p] : 0;
                // Off the middle costs a little: the cut is not to shave a digit's side.
                var c = ink * 10 + 0.02 * Math.abs(k - (n - 1) / 2) / n;
                if (r === 0) {
                    cost[k] = c;
                    continue;
                }
                var best = Infinity, bd = 0;
                for (d = -1; d <= 1; d++) {
                    var q = k + d;
                    if (q < 0 || q >= n) continue;
                    var v = cost[(r - 1) * n + q] + (d ? 0.15 : 0);
                    if (v < best) {
                        best = v;
                        bd = d;
                    }
                }
                cost[r * n + k] = best + c;
                from[r * n + k] = bd;
            }
        }
        var end = 0;
        for (k = 1; k < n; k++) if (cost[(rows - 1) * n + k] < cost[(rows - 1) * n + end]) end = k;
        var path = new Int32Array(rows);
        for (r = rows - 1, k = end; r >= 0; r--) {
            path[r] = x0 + k;
            k += from[r * n + k];
        }
        return path;
    }

    /** The pieces of a blot split along paths (each the column of every row): inputs. */
    function pathPieces(m, lab, c, paths) {
        var bounds = [null].concat(paths, [null]), out = [];
        for (var i = 0; i + 1 < bounds.length; i++) {
            (function (left, right) {
                out.push(charInput(m, lab, c, c.x0, c.x1, function (x, y) {
                    return (!left || x > left[y - c.y0]) && (!right || x < right[y - c.y0]);
                }));
            })(bounds[i], bounds[i + 1]);
        }
        return out;
    }

    /**
     * Characters of a number cell, left to right, each blot with its possible cuts.
     *
     * Blots overlapping in x are one character (a 5 drawn in two strokes); blots
     * along the borders spanning the cell are what is left of the grid; specks are
     * dropped. A blot rather wide may be two digits written touching, a very wide
     * one three: each blot comes with its readings as one, two or three characters,
     * each with a prior from its shape, and the arithmetic checks choose.
     *
     * @param {Object} m Ink map.
     * @param {Object} [o] {w, h: the cell's size in m (all of m otherwise), clean: no grid
     *     lines left in m (option own), cut: option cut}.
     * @return {Array} Per blot: [{pieces: [MNIST-like input…], prior}].
     */
    function blotGroups(m, o) {
        o = o || {};
        var bl = blots(m, 0.3), H = o.h || m.h, W = o.w || m.w, kept = [];
        bl.list.forEach(function (b) {
            var bw = b.x1 - b.x0 + 1, bh = b.y1 - b.y0 + 1;
            if (b.area < 0.004 * W * H || bh < 0.2 * H) return;
            if (!o.clean) {
                var left = b.x0 <= 1, right = b.x1 >= m.w - 2, top = b.y0 <= 1, bottom = b.y1 >= m.h - 2;
                // What is left of a vertical or horizontal line of the grid.
                if ((left || right) && bh >= 0.9 * H && bw < 0.1 * W) return;
                if ((top || bottom) && bw >= 0.5 * W && bh < 0.18 * H) return;
            }
            b.ids = [b.id];
            kept.push(b);
        });
        kept.sort(function (a, b) { return a.x0 - b.x0; });
        var chars = [];
        kept.forEach(function (b) {
            var last = chars[chars.length - 1];
            var overlap = last ? Math.min(last.x1, b.x1) - Math.max(last.x0, b.x0) : -1;
            var small = last && Math.min(last.area, b.area) < 0.4 * Math.max(last.area, b.area);
            if (last && overlap > 0.5 * Math.min(last.x1 - last.x0, b.x1 - b.x0) && small) {
                last.x0 = Math.min(last.x0, b.x0);
                last.x1 = Math.max(last.x1, b.x1);
                last.y0 = Math.min(last.y0, b.y0);
                last.y1 = Math.max(last.y1, b.y1);
                last.area += b.area;
                last.ids = last.ids.concat(b.ids);
            } else {
                chars.push(b);
            }
        });
        return chars.map(function (c) {
            var cw = c.x1 - c.x0 + 1, ch = c.y1 - c.y0 + 1, aspect = cw / ch;
            // A single digit is about 0.2 (a 1) to 0.85 wide for 1 high, two touching 1 to 1.7.
            var two = 1 / (1 + Math.exp(-(aspect - 0.95) * 8)), three = 1 / (1 + Math.exp(-(aspect - 1.75) * 8));
            var alts = [{pieces: [charInput(m, bl.labels, c, c.x0, c.x1)], prior: Math.max(0.02, 1 - two)}];
            var mid = function (a, b) { return [a + Math.floor((b - a + 1) * 0.25), b - Math.floor((b - a + 1) * 0.25)]; };
            if (two > 0.02) {
                var prior2 = Math.max(0.02, two * (1 - three)), r = mid(c.x0, c.x1), path = o.cut && cutPath(m, bl.labels, c, r[0], r[1]);
                if (path) {
                    alts.push({pieces: pathPieces(m, bl.labels, c, [path]), prior: prior2});
                } else {
                    var cut = thinnest(m, c.y0, c.y1, c.x0, c.x1);
                    alts.push({pieces: [charInput(m, bl.labels, c, c.x0, cut), charInput(m, bl.labels, c, cut + 1, c.x1)], prior: prior2});
                }
            }
            if (three > 0.02) {
                var prior3 = Math.max(0.02, three);
                var r1 = mid(c.x0, c.x0 + Math.floor(cw * 0.6)), r2 = mid(c.x0 + Math.floor(cw * 0.4), c.x1);
                var p1 = o.cut && cutPath(m, bl.labels, c, r1[0], r1[1]), p2 = o.cut && cutPath(m, bl.labels, c, r2[0], r2[1]);
                if (p1 && p2) {
                    alts.push({pieces: pathPieces(m, bl.labels, c, [p1, p2]), prior: prior3});
                } else {
                    var c1 = thinnest(m, c.y0, c.y1, c.x0, c.x0 + Math.floor(cw * 0.55));
                    var c2 = thinnest(m, c.y0, c.y1, c.x0 + Math.floor(cw * 0.45), c.x1);
                    if (c2 > c1 + 2) {
                        alts.push({pieces: [charInput(m, bl.labels, c, c.x0, c1), charInput(m, bl.labels, c, c1 + 1, c2),
                            charInput(m, bl.labels, c, c2 + 1, c.x1)], prior: prior3});
                    }
                }
            }
            return alts;
        });
    }

    /**
     * Characters of a number cell, left to right, each blot read as one character
     * (kept for the bench's pictures).
     */
    function characters(m, o) {
        return blotGroups(m, o).map(function (alts) { return alts[0].pieces[0]; });
    }

    /**
     * The ink of a number cell, 56 pixels high, and how to split it (blotGroups' o).
     * With the option own, the cell and the strokes that run out of it.
     */
    function numberInk(V, full, Hfull, cell, opts) {
        var aspect = (cell.u1 - cell.u0) / (cell.v1 - cell.v0), h = 56, w = Math.max(28, Math.round(h * aspect));
        var cut = !!(opts && opts.cut);
        if (opts && opts.own) {
            var o = ownInk(V, full, Hfull, cell, w, h, 0.04, opts), b = o.box;
            return {m: part(o.m, b.x0, b.y0, b.x1 + 1, b.y1 + 1), o: {w: w, h: h, clean: true, cut: cut}};
        }
        return {m: inkMap(cellPicture(V, full, Hfull, cell, w, h, 0.04, opts)), o: {cut: cut}};
    }

    // ------------------------------------------------- numbers as sequences

    var SEQ_MAX = 1500;     // values scored by the sequence reader: 0 to SEQ_MAX - 1
    var MIX_SURE = 0.97;    // sequence reading from which the characters are not read too
    var BLANK_SURE = 0.9;   // ...or that sure of a blank cell (the characters would read its specks as digits)

    /** What the sequence network sees of a number cell: its ink, net.height high. */
    function seqInput(V, net, full, Hfull, cell, opts) {
        var m = numberInk(V, full, Hfull, cell, opts).m;
        return resize(m, Math.max(8, Math.round(m.w * net.height / m.h)), net.height);
    }

    function lse(a, b) {
        if (a === -Infinity) return b;
        if (b === -Infinity) return a;
        return a > b ? a + Math.log1p(Math.exp(b - a)) : b + Math.log1p(Math.exp(a - b));
    }

    /**
     * Readings of a number cell by the sequence network (option seq): the cell read
     * whole, column by column (CTC, as the printed header), digits touching or running
     * into each other as they are; every value up to SEQ_MAX is scored, a zero also
     * as a dash, nothing written as -1.
     *
     * @param {Object} seq {net: the network (OcrText.load), X: the OcrText module}.
     */
    /**
     * CTC scores of every value below SEQ_MAX, sharing the work of their common prefixes:
     * a number's forward variables only need its prefix's last two states (its last
     * character, and the blank after it). -> {value: log-probability}, '' as -1.
     */
    function seqValues(out, chars, allowed) {
        var T = out.T, C = out.C, lp = out.lp, res = {}, t;
        // With the values the cell can hold, only the prefixes of one of them are followed (the
        // readings are bounded to them afterwards: the others would be scored for nothing).
        var may = !allowed ? function () { return true; } : function (v) {
            if (v === 0) return allowed.some(function (r) { return r[0] <= 0 && r[1] >= 0; });
            for (var m = 1; v * m < SEQ_MAX; m *= 10) {
                var lo = v * m, hi = Math.min(SEQ_MAX - 1, (v + 1) * m - 1);
                if (allowed.some(function (r) { return r[0] <= hi && r[1] >= lo; })) return true;
            }
            return false;
        };
        var blank = new Float64Array(T);
        blank[0] = lp[0];
        for (t = 1; t < T; t++) blank[t] = blank[t - 1] + lp[t * C];
        res[-1] = blank[T - 1];
        var none = new Float64Array(T).fill(-Infinity);
        // Extend a prefix (last character state A of label la, trailing blank B) by k.
        function extend(A, B, la, k, first) {
            var X = new Float64Array(T), D = new Float64Array(T);
            X[0] = first ? lp[k] : -Infinity;
            D[0] = -Infinity;
            for (t = 1; t < T; t++) {
                var a = lse(X[t - 1], B[t - 1]);
                if (k !== la) a = lse(a, A[t - 1]);
                X[t] = a + lp[t * C + k];
                D[t] = lse(D[t - 1], X[t - 1]) + lp[t * C];
            }
            return {X: X, D: D};
        }
        var digit = function (d) { return chars.indexOf(String(d)) + 1; };
        function walk(node, value, label) {
            res[value] = lse(node.X[T - 1], node.D[T - 1]);
            if (value === 0) return;
            for (var d = 0; d <= 9; d++) {
                var v = value * 10 + d;
                if (v >= SEQ_MAX) break;
                if (!may(v)) continue;
                var k = digit(d);
                walk(extend(node.X, node.D, label, k, false), v, k);
            }
        }
        for (var d = 0; d <= 9; d++) if (may(d)) walk(extend(none, blank, -1, digit(d), true), d, digit(d));
        // A zero written as a dash.
        var dash = chars.indexOf('-') + 1;
        if (dash > 0) {
            var n = extend(none, blank, -1, dash, true);
            res[0] = lse(res[0], lse(n.X[T - 1], n.D[T - 1]));
        }
        return res;
    }

    function readSeq(V, seq, full, Hfull, cell, opts) {
        var out = seq.X.run(seq.net, seqInput(V, seq.net, full, Hfull, cell, opts)), sc = seqValues(out, seq.net.chars, cell.allowed);
        var best = -Infinity, total = 0, s = Object.keys(sc).map(function (v) {
            best = Math.max(best, sc[v]);
            return {value: +v, s: sc[v]};
        });
        s.forEach(function (o) { total += (o.p = Math.exp(o.s - best)); });
        return s.map(function (o) { return {value: o.value, p: o.p / total}; }).sort(function (a, b) { return b.p - a.p; });
    }

    /**
     * Readings of a number cell, the most likely first: [{value, p}]; value -1 when
     * nothing is written. By characters (below); with the option seq by the sequence
     * network, or both averaged with the option mix. A cell's allowed numbers (when given,
     * OcrLayout.cellAllowed: [[from, to]…]) bound the values kept: three arrows of a face
     * scoring 6 to 10 make no 4, nor 31.
     */
    function readNumber(V, net, full, Hfull, cell, keep, opts) {
        keep = keep || 6;
        if (opts && opts.reframe) cell = reframe(V, full, Hfull, cell);
        if (!(opts && opts.seq && opts.seq.net)) return bounded(readChars(V, net, full, Hfull, cell, 32, opts), cell.allowed).slice(0, keep);
        var seq = bounded(readSeq(V, opts.seq, full, Hfull, cell, opts), cell.allowed);
        // Mixed only where the sequence reader hesitates: elsewhere the characters, twice
        // as long to read, would not change its answer.
        if (!opts.mix || seq[0].p >= MIX_SURE || seq[0].value === -1 && seq[0].p >= BLANK_SURE) return seq.slice(0, keep);
        var by = {};
        seq.slice(0, 20).forEach(function (o) { by[o.value] = 0.5 * o.p; });
        bounded(readChars(V, net, full, Hfull, cell, 32, opts), cell.allowed).slice(0, 20)
            .forEach(function (o) { by[o.value] = (by[o.value] || 0) + 0.5 * o.p; });
        return Object.keys(by).map(function (v) { return {value: +v, p: by[v]}; })
            .sort(function (a, b) { return b.p - a.p; }).slice(0, keep);
    }

    /**
     * Readings left with the values a cell can hold (nothing written always can), their
     * probabilities shared again. None left: the readings as they were (the cell is
     * likely not the one it is taken for).
     */
    function bounded(list, allowed) {
        if (!allowed) return list;
        var kept = list.filter(function (o) {
            return o.value === -1 || allowed.some(function (r) { return o.value >= r[0] && o.value <= r[1]; });
        }), total = 0;
        if (!kept.length) return list;
        kept.forEach(function (o) { total += o.p; });
        return kept.map(function (o) { return {value: o.value, p: o.p / total}; });
    }

    /**
     * Readings of a number cell character by character. Each character keeps its three
     * best digits; the numbers they make are ranked by the product of their
     * probabilities.
     */
    function readChars(V, net, full, Hfull, cell, keep, opts) {
        var ink = numberInk(V, full, Hfull, cell, opts);
        var groups = blotGroups(ink.m, ink.o);
        if (!groups.length) return [{value: -1, p: 0.97}];
        var junk = net.labels.indexOf('junk'), beams = [{digits: '', p: 1}];
        function options(input) {
            var probs = run(net, input), cand = [];
            for (var d = 0; d < 10; d++) cand.push({d: String(d), p: probs[d]});
            cand.sort(function (a, b) { return b.p - a.p; });
            cand = cand.slice(0, 3);
            // A blot read as junk may be dropped rather than read as a digit.
            if (junk >= 0) cand.push({d: '', p: probs[junk]});
            return cand;
        }
        groups.forEach(function (alts) {
            // Every way of cutting the blot, each piece read: the readings of the blot.
            var readings = [];
            alts.forEach(function (alt) {
                var part = [{d: '', p: alt.prior}];
                alt.pieces.forEach(function (input) {
                    // The piece read once, whatever came before it.
                    var next = [], opt = options(input);
                    part.forEach(function (pr) {
                        opt.forEach(function (o) { next.push({d: pr.d + o.d, p: pr.p * o.p}); });
                    });
                    part = next.sort(function (a, b) { return b.p - a.p; }).slice(0, 12);
                });
                readings = readings.concat(part);
            });
            var next = [];
            beams.forEach(function (bm) {
                readings.forEach(function (o) { next.push({digits: bm.digits + o.d, p: bm.p * o.p}); });
            });
            beams = next.sort(function (a, b) { return b.p - a.p; }).slice(0, 32);
        });
        var byValue = {};
        beams.forEach(function (bm) {
            var v = bm.digits === '' ? -1 : parseInt(bm.digits, 10);
            byValue[v] = (byValue[v] || 0) + bm.p;
        });
        var total = 0, out = Object.keys(byValue).map(function (v) {
            total += byValue[v];
            return {value: +v, p: byValue[v]};
        });
        out.forEach(function (o) { o.p /= total || 1; });
        return out.sort(function (a, b) { return b.p - a.p; }).slice(0, keep);
    }

    return {load: load, run: run, inkMap: inkMap, readArrow: readArrow, readNumber: readNumber, reframe: reframe,
        _internal: {characters: characters, reduce: reduce, resize: resize, arrowInput: arrowInput, numberInk: numberInk,
            blotGroups: blotGroups, ownInk: ownInk, seqInput: seqInput, seqValues: seqValues}};
});
