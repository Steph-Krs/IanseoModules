/**
 * Image analysis of a photographed ianseo scorecard: finds the grid, straightens it,
 * and gives the position of every line of the table.
 *
 * Runs in the browser and, unchanged, in Node for the test bench: it only works on
 * plain typed arrays and has no dependency. A grey image is {w, h, d: Uint8Array}.
 *
 * The method rests on one property of the scorecards drawn by ScorePDF::DrawScoreNew():
 * every border of the table touches another one, so the whole grid is a single
 * connected blot of ink, apart from the header underlines, the text and the barcode.
 * 1. adaptive threshold, then the largest grid-like connected component;
 * 2. the two families of lines of that component (Hough transform), and in each family
 *    the two outermost long lines: header top, body bottom, left and right borders;
 * 3. the homography that maps those four lines to a rectangle;
 * 4. in that straightened frame, the lines are horizontal and vertical runs of ink,
 *    found by projection, with their extents.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.OcrVision = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // ------------------------------------------------------------------ images

    function makeGray(w, h, d) {
        return {w: w, h: h, d: d || new Uint8Array(w * h)};
    }

    /** Grey image from canvas RGBA pixels. Blue or red ink stays dark enough. */
    function fromRGBA(data, w, h) {
        var g = makeGray(w, h), d = g.d, n = w * h, i, j;
        for (i = 0, j = 0; i < n; i++, j += 4) {
            d[i] = (data[j] * 77 + data[j + 1] * 150 + data[j + 2] * 29) >> 8;
        }
        return g;
    }

    /** Summed-area table, (w + 1) x (h + 1). */
    function integral(g) {
        var w = g.w, h = g.h, W = w + 1, I = new Float64Array(W * (h + 1)), x, y, row;
        for (y = 0; y < h; y++) {
            row = 0;
            for (x = 0; x < w; x++) {
                row += g.d[y * w + x];
                I[(y + 1) * W + x + 1] = I[y * W + x + 1] + row;
            }
        }
        return I;
    }

    /** Area-average reduction so that the longer side is at most maxSide. */
    function downscale(g, maxSide) {
        var s = Math.min(1, maxSide / Math.max(g.w, g.h));
        if (s >= 1) return {img: g, scale: 1};
        var W = Math.max(1, Math.round(g.w * s)), H = Math.max(1, Math.round(g.h * s));
        var I = integral(g), IW = g.w + 1, out = makeGray(W, H), X, Y;
        var fx = g.w / W, fy = g.h / H;
        for (Y = 0; Y < H; Y++) {
            var y0 = Math.floor(Y * fy), y1 = Math.max(y0 + 1, Math.floor((Y + 1) * fy));
            for (X = 0; X < W; X++) {
                var x0 = Math.floor(X * fx), x1 = Math.max(x0 + 1, Math.floor((X + 1) * fx));
                var sum = I[y1 * IW + x1] - I[y0 * IW + x1] - I[y1 * IW + x0] + I[y0 * IW + x0];
                out.d[Y * W + X] = sum / ((x1 - x0) * (y1 - y0));
            }
        }
        return {img: out, scale: W / g.w};
    }

    /**
     * Bilinear enlargement so that the longer side is at least minSide. A thumbnail
     * holds no more detail once enlarged, but the module's tolerances, in pixels, are
     * those of a photo of usual size.
     */
    function upscale(g, minSide) {
        var s = minSide / Math.max(g.w, g.h);
        if (s <= 1) return {img: g, scale: 1};
        var W = Math.round(g.w * s), H = Math.round(g.h * s), out = makeGray(W, H), x, y;
        for (y = 0; y < H; y++) {
            for (x = 0; x < W; x++) {
                var v = sample(g, Math.min(g.w - 1, (x + 0.5) / s - 0.5), Math.min(g.h - 1, (y + 0.5) / s - 0.5));
                out.d[y * W + x] = isNaN(v) ? 255 : v;
            }
        }
        return {img: out, scale: W / g.w};
    }

    /** Bilinear sample, NaN outside the image. */
    function sample(g, x, y) {
        if (x < 0 || y < 0 || x > g.w - 1 || y > g.h - 1) return NaN;
        var x0 = Math.min(g.w - 2, Math.floor(x)), y0 = Math.min(g.h - 2, Math.floor(y));
        if (x0 < 0) x0 = 0;
        if (y0 < 0) y0 = 0;
        var fx = x - x0, fy = y - y0, i = y0 * g.w + x0, d = g.d;
        return d[i] * (1 - fx) * (1 - fy) + d[i + 1] * fx * (1 - fy)
            + d[i + g.w] * (1 - fx) * fy + d[i + g.w + 1] * fx * fy;
    }

    /**
     * Darkest of four directional means (5 pixels along, across nothing): noise drops
     * by half while a thin line keeps its darkness along its own direction, where a
     * round blur would wash it out.
     */
    function lineSmooth(g) {
        var w = g.w, h = g.h, out = makeGray(w, h), d = g.d, o = out.d, w2 = 2 * w, x, y;
        for (y = 2; y < h - 2; y++) {
            for (x = 2; x < w - 2; x++) {
                // Along, down, and the two diagonals (sums of whole grey levels: exact in any order).
                var i = y * w + x, v = d[i];
                var a = d[i - 2] + d[i - 1] + v + d[i + 1] + d[i + 2];
                var b = d[i - w2] + d[i - w] + v + d[i + w] + d[i + w2];
                var c = d[i - w2 - 2] + d[i - w - 1] + v + d[i + w + 1] + d[i + w2 + 2];
                var e = d[i + w2 - 2] + d[i + w - 1] + v + d[i - w + 1] + d[i - w2 + 2];
                if (b < a) a = b;
                if (c < a) a = c;
                if (e < a) a = e;
                o[i] = a / 5;
            }
        }
        for (y = 0; y < h; y++) {
            for (x = 0; x < w; x++) {
                if (y < 2 || y >= h - 2 || x < 2 || x >= w - 2) out.d[y * w + x] = d[y * w + x];
            }
        }
        return out;
    }

    /**
     * Running maximum over 2r + 1 values along one axis (van Herk / Gil-Werman: three
     * comparisons per value whatever r is).
     */
    function maxRun(src, dst, n, count, step, lineStep, r) {
        var k = 2 * r + 1, m = n + k, p = new Float32Array(m), g = new Float32Array(m), hh = new Float32Array(m), line, i, b, e;
        // The line padded with -1 on both sides, then the running maxima block by block (k
        // values each): from the block's start forwards, from its end backwards.
        for (i = 0; i < r; i++) p[i] = -1;
        for (i = r + n; i < m; i++) p[i] = -1;
        for (line = 0; line < count; line++) {
            var base = line * lineStep;
            for (i = 0; i < n; i++) p[r + i] = src[base + i * step];
            for (b = 0; b < m; b += k) {
                e = Math.min(m, b + k);
                g[b] = p[b];
                for (i = b + 1; i < e; i++) g[i] = Math.max(g[i - 1], p[i]);
                hh[e - 1] = p[e - 1];
                for (i = e - 2; i >= b; i--) hh[i] = Math.max(hh[i + 1], p[i]);
            }
            for (i = 0; i < n; i++) dst[base + i * step] = Math.max(hh[i], g[i + 2 * r]);
        }
    }

    /**
     * Ink mask. A pixel is ink when it is darker by a fraction k than the white of the
     * paper around it, taken as the local maximum (smoothed). Unlike a threshold on the
     * local mean, a thin line stays ink next to a dense black area (the barcode, bold
     * text); shadows and uneven light are followed; and the light grey fills of the
     * header cells stay paper.
     *
     * @param {number} k Least depth, as a fraction of the white.
     * @param {number} [rel] ... or of the contrast with the darkest ink around (0.4).
     * @param {number} [floor] Least depth in grey levels (10).
     * @param {Object} [paper] paperOf(g, radius), when several thresholds are taken on g.
     */
    function binarize(g, radius, k, rel, floor, paper) {
        rel = rel === undefined ? 0.4 : rel;
        floor = floor === undefined ? 10 : floor;
        var w = g.w, h = g.h, n = w * h, x, y, pp = paper || paperOf(g, radius);
        var I = pp.I, J = pp.J, W = w + 1, m = new Uint8Array(n);
        for (y = 0; y < h; y++) {
            var y0 = Math.max(0, y - radius), y1 = Math.min(h, y + radius + 1);
            for (x = 0; x < w; x++) {
                var x0 = Math.max(0, x - radius), x1 = Math.min(w, x + radius + 1), area = (x1 - x0) * (y1 - y0);
                var white = (I[y1 * W + x1] - I[y0 * W + x1] - I[y1 * W + x0] + I[y0 * W + x0]) / area;
                var dark = (J[y1 * W + x1] - J[y0 * W + x1] - J[y1 * W + x0] + J[y0 * W + x0]) / area;
                var depth = white - g.d[y * w + x];
                if (depth >= floor && depth >= Math.min(k * white, rel * (white - dark))) m[y * w + x] = 1;
            }
        }
        return m;
    }

    /**
     * What binarize() compares each pixel with, apart (the same for every threshold on an
     * image): the paper's white around it and the darkest ink over a wider area, as
     * summed-area tables.
     */
    function paperOf(g, radius) {
        var w = g.w, h = g.h, n = w * h, a = new Float32Array(n), b = new Float32Array(n), i;
        maxRun(g.d, a, w, h, 1, w, radius);
        maxRun(a, b, h, w, w, 1, radius);
        // Smooth the maximum, which is blocky, with a box of the same size.
        var bg = makeGray(w, h);
        for (i = 0; i < n; i++) bg.d[i] = b[i];
        // Darkest ink over a wider area: a washed-out photo (overexposed, or a whole
        // page seen from afar) has grey lines only, and they must still count.
        var inv = new Float32Array(n), R = 4 * radius;
        for (i = 0; i < n; i++) inv[i] = 255 - g.d[i];
        maxRun(inv, a, w, h, 1, w, R);
        maxRun(a, b, h, w, w, 1, R);
        var dk = makeGray(w, h);
        for (i = 0; i < n; i++) dk.d[i] = 255 - b[i];
        return {I: integral(bg), J: integral(dk)};
    }

    /**
     * Grow a mask by one pixel (3 x 3). Thin printed lines, blurred and noisy, come out
     * of the threshold dotted; growing them joins the dots, so that the grid stays one
     * blot and its lines long runs.
     */
    function dilate(mask, w, h) {
        var out = new Uint8Array(w * h), x, y;
        for (y = 0; y < h; y++) {
            for (x = 0; x < w; x++) {
                if (!mask[y * w + x]) continue;
                for (var dy = -1; dy <= 1; dy++) {
                    var yy = y + dy;
                    if (yy < 0 || yy >= h) continue;
                    for (var dx = -1; dx <= 1; dx++) {
                        var xx = x + dx;
                        if (xx >= 0 && xx < w) out[yy * w + xx] = 1;
                    }
                }
            }
        }
        return out;
    }

    // ------------------------------------------------------- connected blots

    /** 8-connected components of a mask. Labels start at 1; 0 is background. */
    function components(mask, w, h) {
        var labels = new Int32Array(w * h), cap = 1024, parent = new Int32Array(cap), next = 1, x, y, i;

        function find(a) {
            while (parent[a] !== a) {
                parent[a] = parent[parent[a]];
                a = parent[a];
            }
            return a;
        }
        function union(a, b) {
            a = find(a);
            b = find(b);
            if (a < b) parent[b] = a;
            else if (b < a) parent[a] = b;
        }

        for (y = 0; y < h; y++) {
            for (x = 0; x < w; x++) {
                i = y * w + x;
                if (!mask[i]) continue;
                var best = 0, n;
                // Already visited neighbours: left, and the three above.
                if (x > 0 && (n = labels[i - 1])) best = n;
                if (y > 0) {
                    if (x > 0 && (n = labels[i - w - 1])) { if (best) union(best, n); else best = n; }
                    if ((n = labels[i - w])) { if (best) union(best, n); else best = n; }
                    if (x < w - 1 && (n = labels[i - w + 1])) { if (best) union(best, n); else best = n; }
                }
                if (!best) {
                    if (next >= cap) {
                        var grown = new Int32Array(cap * 2);
                        grown.set(parent);
                        parent = grown;
                        cap *= 2;
                    }
                    parent[next] = next;
                    best = next++;
                }
                labels[i] = best;
            }
        }

        var compact = new Int32Array(next), count = 0, stats = [];
        for (i = 1; i < next; i++) {
            var r = find(i);
            if (!compact[r]) {
                compact[r] = ++count;
                stats.push({label: count, area: 0, x0: w, y0: h, x1: -1, y1: -1});
            }
            compact[i] = compact[r];
        }
        for (y = 0; y < h; y++) {
            for (x = 0; x < w; x++) {
                i = y * w + x;
                if (!labels[i]) continue;
                var l = compact[labels[i]], s = stats[l - 1];
                labels[i] = l;
                s.area++;
                if (x < s.x0) s.x0 = x;
                if (x > s.x1) s.x1 = x;
                if (y < s.y0) s.y0 = y;
                if (y > s.y1) s.y1 = y;
            }
        }
        // Border pixels (a 4-neighbour outside the blot), for the mean stroke width.
        stats.forEach(function (s) { s.border = 0; });
        for (y = 0; y < h; y++) {
            for (x = 0; x < w; x++) {
                i = y * w + x;
                var lab = labels[i];
                if (!lab) continue;
                if (x === 0 || x === w - 1 || y === 0 || y === h - 1
                    || labels[i - 1] !== lab || labels[i + 1] !== lab || labels[i - w] !== lab || labels[i + w] !== lab) {
                    stats[lab - 1].border++;
                }
            }
        }
        stats.forEach(function (s) { s.thickness = 2 * s.area / Math.max(1, s.border); });
        return {labels: labels, stats: stats};
    }

    /**
     * The blot that looks like a scorecard grid: big, mostly empty inside its box, and
     * made of thin strokes. The last test rules out the edge of the sheet against a
     * darker table, which the adaptive threshold also turns into a large thin ring,
     * but as wide as the threshold's window.
     * A blot running along two edges of the photo is the table or the background, not
     * a card the operator framed. When several cards are in the picture, the one under
     * the centre comes first.
     *
     * @param {boolean} [several] Several cards may share the photo: smaller grids count.
     * @return {Array} Candidates, the most likely first.
     */
    function gridCandidates(stats, w, h, several) {
        var cx = w / 2, cy = h / 2, out = [];
        stats.forEach(function (s) {
            var bw = s.x1 - s.x0 + 1, bh = s.y1 - s.y0 + 1;
            if (bw < (several ? 0.08 : 0.18) * w || bh < (several ? 0.08 : 0.12) * h) return;
            var fill = s.area / (bw * bh);
            // A small or low-resolution photo makes the grown lines, and the writing that
            // touches them, fill much of the box: solid objects are told apart by thickness.
            if (fill > 0.6 || fill < 0.01 || s.thickness > 12) return;
            var edges = (s.x0 <= 2) + (s.y0 <= 2) + (s.x1 >= w - 3) + (s.y1 >= h - 3);
            if (edges >= 2) return;
            s.score = bw * bh * (cx >= s.x0 && cx <= s.x1 && cy >= s.y0 && cy <= s.y1 ? 2 : 1);
            out.push(s);
        });
        out.sort(function (a, b) { return b.score - a.score; });
        return out.slice(0, several ? 8 : 4);
    }

    // ------------------------------------------------------------------ lines

    /**
     * Hough votes of points for angles thetas (radians), rho counted from (cx, cy)
     * in bins of rhoStep pixels.
     */
    function houghAccumulate(xs, ys, n, cx, cy, thetas, rhoStep, maxRho) {
        var nt = thetas.length, nr = Math.ceil(2 * maxRho / rhoStep) + 1;
        var acc = new Int32Array(nt * nr), cos = new Float64Array(nt), sin = new Float64Array(nt), off = maxRho / rhoStep, t, i, b;
        // Divided by the bin's size beforehand: the same as after, for bins of one or two
        // pixels (a power of two divides exactly).
        for (t = 0; t < nt; t++) {
            cos[t] = Math.cos(thetas[t]) / rhoStep;
            sin[t] = Math.sin(thetas[t]) / rhoStep;
        }
        for (i = 0; i < n; i++) {
            var x = xs[i] - cx, y = ys[i] - cy;
            for (t = 0, b = 0; t < nt; t++, b += nr) acc[b + Math.round(x * cos[t] + y * sin[t] + off)]++;
        }
        return {acc: acc, nt: nt, nr: nr, rhoStep: rhoStep, maxRho: maxRho};
    }

    function range(a, b, step) {
        var out = [];
        for (var v = a; v <= b + 1e-9; v += step) out.push(v);
        return out;
    }

    /**
     * The two dominant directions of the points: the angles where the votes are the
     * most concentrated (sum of squares), the second one about 90 degrees from the first.
     */
    function dominantAngles(xs, ys, n, cx, cy, maxRho) {
        var stride = Math.max(1, Math.floor(n / 25000)), sx = [], sy = [], i;
        for (i = 0; i < n; i += stride) {
            sx.push(xs[i]);
            sy.push(ys[i]);
        }
        var thetas = range(0, Math.PI - Math.PI / 360, Math.PI / 360);
        var H = houghAccumulate(sx, sy, sx.length, cx, cy, thetas, 2, maxRho);
        var energy = thetas.map(function (_, t) {
            var e = 0, base = t * H.nr;
            for (var r = 0; r < H.nr; r++) {
                var v = H.acc[base + r];
                e += v * v;
            }
            return e;
        });
        var t1 = 0;
        energy.forEach(function (e, t) { if (e > energy[t1]) t1 = t; });
        var t2 = -1, n2 = thetas.length;
        for (var dt = 60; dt <= 120; dt++) {
            var t = (t1 + dt * 2) % n2;
            if (t2 < 0 || energy[t] > energy[t2]) t2 = t;
        }
        return [thetas[t1], thetas[t2]];
    }

    // ------------------------------------------------------------------ lens

    /**
     * Radial distortion of the lens. A wide-angle webcam bows the lines of a sheet, the
     * more so the further from the centre of the picture: the outer lines of a grid are
     * then no longer straight, and the lines found for its edges are inner ones. The
     * model: a point at r from the centre (r over half the diagonal) is seen at r', with
     * r = r' (1 + k r'^2). k is found by the plumb-line method: each long line of the
     * grids is followed step by step along its dark ridge (writing across it does not
     * pull it away), and k is the one that makes those tracks the straightest.
     *
     * @return {Object|null} {k, cx, cy, R} in pixels of the photo, null when straight.
     */
    function lensOf(full) {
        var ds = downscale(full, 1600), work = ds.img, w = work.w, h = work.h, i, x, y;
        var ink = binarize(lineSmooth(work), Math.max(6, Math.round(Math.max(w, h) / 130)), 0.15);
        var cc = components(dilate(ink, w, h), w, h), cands = gridCandidates(cc.stats, w, h, true);
        if (!cands.length) return null;
        var cx0 = w / 2, cy0 = h / 2, R = Math.hypot(cx0, cy0), tracks = [];
        cands.slice(0, 6).forEach(function (g) {
            var px = [], py = [];
            for (y = g.y0; y <= g.y1; y++) {
                for (x = g.x0; x <= g.x1; x++) {
                    i = y * w + x;
                    if (ink[i] && cc.labels[i] === g.label) {
                        px.push(x);
                        py.push(y);
                    }
                }
            }
            if (px.length < 500) return;
            var stride = Math.max(1, Math.floor(px.length / 40000)), sx = [], sy = [];
            for (i = 0; i < px.length; i += stride) {
                sx.push(px[i]);
                sy.push(py[i]);
            }
            var gx = (g.x0 + g.x1) / 2, gy = (g.y0 + g.y1) / 2, size = Math.max(g.x1 - g.x0, g.y1 - g.y0);
            var maxRho = Math.hypot(g.x1 - g.x0, g.y1 - g.y0) / 2 + 4, B = Math.max(5, 0.02 * size);
            dominantAngles(sx, sy, sx.length, gx, gy, maxRho).forEach(function (t) {
                var lines = familyLines(sx, sy, sx.length, gx, gy, maxRho, t), top = 0;
                lines.forEach(function (l) { if (l.span > top) top = l.span; });
                lines.forEach(function (l) {
                    if (l.span < 0.6 * top) return;
                    var tr = trackLine(work, l, B);
                    if (tr.x.length >= 10) {
                        tracks.push({x: tr.x.map(function (v) { return v - cx0; }), y: tr.y.map(function (v) { return v - cy0; })});
                    }
                });
            });
        });
        if (tracks.length < 4) return null;
        var spread = 0;
        tracks.forEach(function (tk) { for (var j = 0; j < tk.x.length; j++) spread += Math.hypot(tk.x[j], tk.y[j]); });
        // Mean square distance of the tracks to their own straight lines once undistorted
        // by k, the whole kept to the same size (a cloud only shrunk would look straighter).
        function bend(k) {
            var after = 0, us = tracks.map(function (tk) {
                var ux = [], uy = [];
                for (var j = 0; j < tk.x.length; j++) {
                    var f = 1 + k * (tk.x[j] * tk.x[j] + tk.y[j] * tk.y[j]) / (R * R);
                    ux.push(tk.x[j] * f);
                    uy.push(tk.y[j] * f);
                    after += Math.hypot(ux[j], uy[j]);
                }
                return {x: ux, y: uy};
            });
            var back = spread / after, sum = 0, n = 0;
            us.forEach(function (u) {
                for (var j = 0; j < u.x.length; j++) {
                    u.x[j] *= back;
                    u.y[j] *= back;
                }
                var l = fitLine(u.x, u.y);
                for (j = 0; j < u.x.length; j++) {
                    var d = l.nx * u.x[j] + l.ny * u.y[j] - l.c;
                    sum += Math.min(d * d, 16);
                    n++;
                }
            });
            return sum / n;
        }
        var best = 0, b0 = bend(0), bestB = b0, k, bk;
        for (k = -0.1; k <= 0.4001; k += 0.025) {
            bk = bend(k);
            if (bk < bestB) {
                bestB = bk;
                best = k;
            }
        }
        for (k = best - 0.02; k <= best + 0.02001; k += 0.005) {
            bk = bend(k);
            if (bk < bestB) {
                bestB = bk;
                best = k;
            }
        }
        var s = 1 / ds.scale, out = {k: best, cx: cx0 * s, cy: cy0 * s, R: R * s, before: Math.sqrt(b0), after: Math.sqrt(bestB), lines: tracks.length};
        // Not worth it when the lines were nearly straight already.
        return Math.abs(best) < 0.02 || b0 < 0.3 || bestB > 0.7 * b0 ? null : out;
    }

    /**
     * A line of the grid followed along its dark ridge, from its middle outwards, the
     * offset across it changing by at most two pixels per step: where the ridge is not
     * darker than around it (a crossing line, a blot, the end), the step is skipped.
     * -> {x: [], y: []} points of the ridge, in pixels of the image.
     */
    function trackLine(g, l, B) {
        var dx = -l.ny, dy = l.nx, n = 24, span = l.t1 - l.t0, out = {x: [], y: []};
        var t0 = l.t0 - 0.08 * span, step = (span * 1.16) / (n - 1);
        function at(t, d) {
            var bx = l.nx * l.c + dx * t + l.nx * d, by = l.ny * l.c + dy * t + l.ny * d, v = 0, m = 0;
            for (var a = -2; a <= 2; a += 2) {
                var s = sample(g, bx + dx * a, by + dy * a);
                if (!isNaN(s)) {
                    v += s;
                    m++;
                }
            }
            return m ? v / m : NaN;
        }
        function ridge(t, guess) {
            var vals = [], best = null, bv = Infinity, sum = 0, d, j = 0;
            for (d = -B; d <= B; d += 0.5) {
                var v = at(t, d);
                if (isNaN(v)) return null;
                vals.push(v);
                sum += v;
                if (Math.abs(d - guess) <= 2 && v < bv) {
                    bv = v;
                    best = d;
                }
            }
            var mean = sum / vals.length, dark = 0;
            for (j = 0; j < vals.length; j++) if (vals[j] < mean - 12) dark++;
            if (best === null || mean - bv < 15 || dark > 0.4 * vals.length) return null;
            return best;
        }
        var mid = Math.floor(n / 2);
        [[mid, n, 1], [mid - 1, -1, -1]].forEach(function (run) {
            var guess = 0;
            for (var i = run[0]; i !== run[1]; i += run[2]) {
                var t = t0 + i * step, d = ridge(t, guess);
                if (d === null) continue;
                guess = d;
                out.x.push(l.nx * l.c + dx * t + l.nx * d);
                out.y.push(l.ny * l.c + dy * t + l.ny * d);
            }
        });
        return out;
    }

    /** For every distance to the centre r (over R), r' / r: tabulated by Newton's method. */
    function lensTable(k) {
        var N = 4096, RMAX = 1.6, tab = new Float64Array(N + 1);
        for (var i = 0; i <= N; i++) {
            var r = RMAX * i / N, q = r;
            for (var it = 0; it < 8; it++) q -= (q * (1 + k * q * q) - r) / (1 + 3 * k * q * q);
            tab[i] = r > 0 ? q / r : 1;
        }
        return function (rr) { return tab[Math.min(N, Math.round(rr / RMAX * N))]; };
    }

    /** The grey photo as a lens without distortion would have taken it (lensOf()). */
    function undistort(full, lens) {
        var w = full.w, h = full.h, out = makeGray(w, h), f = lensTable(lens.k);
        for (var y = 0; y < h; y++) {
            var dy = y - lens.cy;
            for (var x = 0; x < w; x++) {
                var dx = x - lens.cx, m = f(Math.sqrt(dx * dx + dy * dy) / lens.R);
                var v = sample(full, lens.cx + dx * m, lens.cy + dy * m);
                out.d[y * w + x] = isNaN(v) ? 255 : v;
            }
        }
        return out;
    }

    /** The same for canvas pixels (RGBA), nearest neighbour: for the picture shown. */
    function undistortRGBA(data, w, h, lens) {
        var out = new Uint8ClampedArray(w * h * 4), f = lensTable(lens.k);
        for (var y = 0; y < h; y++) {
            var dy = y - lens.cy;
            for (var x = 0; x < w; x++) {
                var dx = x - lens.cx, m = f(Math.sqrt(dx * dx + dy * dy) / lens.R);
                var sx = Math.round(lens.cx + dx * m), sy = Math.round(lens.cy + dy * m), o = (y * w + x) * 4;
                if (sx < 0 || sy < 0 || sx >= w || sy >= h) {
                    out[o] = out[o + 1] = out[o + 2] = out[o + 3] = 255;
                    continue;
                }
                var s = (sy * w + sx) * 4;
                out[o] = data[s];
                out[o + 1] = data[s + 1];
                out[o + 2] = data[s + 2];
                out[o + 3] = 255;
            }
        }
        return out;
    }

    /** Where a point of the undistorted picture was in the photo as taken (to draw on it). */
    function lensBack(lens, x, y) {
        if (!lens.back) lens.back = lensTable(lens.k);
        var dx = x - lens.cx, dy = y - lens.cy, m = lens.back(Math.sqrt(dx * dx + dy * dy) / lens.R);
        return {x: lens.cx + dx * m, y: lens.cy + dy * m};
    }

    /** Where a point of the photo as taken lands in the undistorted one. */
    function lensPoint(lens, x, y) {
        var dx = x - lens.cx, dy = y - lens.cy, f = 1 + lens.k * (dx * dx + dy * dy) / (lens.R * lens.R);
        return {x: lens.cx + dx * f, y: lens.cy + dy * f};
    }

    /** Total least squares line through points: {nx, ny, c} with nx*x + ny*y = c. */
    function fitLine(px, py) {
        var n = px.length, mx = 0, my = 0, i;
        if (n < 2) return null;
        for (i = 0; i < n; i++) {
            mx += px[i];
            my += py[i];
        }
        mx /= n;
        my /= n;
        var sxx = 0, syy = 0, sxy = 0;
        for (i = 0; i < n; i++) {
            var dx = px[i] - mx, dy = py[i] - my;
            sxx += dx * dx;
            syy += dy * dy;
            sxy += dx * dy;
        }
        // Direction = main eigenvector of the scatter matrix; the normal is across it.
        var a = 0.5 * Math.atan2(2 * sxy, sxx - syy);
        var nx = -Math.sin(a), ny = Math.cos(a);
        return {nx: nx, ny: ny, c: nx * mx + ny * my};
    }

    /**
     * Lines of one family: peaks of a fine Hough transform around the family's angle,
     * each refined by a least-squares fit on the pixels near it, with its extent.
     */
    function familyLines(xs, ys, n, cx, cy, maxRho, theta) {
        var step = Math.PI / 720, span = Math.PI / 15;
        var thetas = range(theta - span, theta + span, step);
        var H = houghAccumulate(xs, ys, n, cx, cy, thetas, 1, maxRho);
        var nt = H.nt, nr = H.nr, acc = H.acc, sm = new Float32Array(nt * nr), t, r;
        for (t = 0; t < nt; t++) {
            for (r = 1; r < nr - 1; r++) {
                var b = t * nr + r;
                sm[b] = acc[b - 1] + 2 * acc[b] + acc[b + 1];
            }
        }
        var peak = 0;
        for (var i = 0; i < sm.length; i++) if (sm[i] > peak) peak = sm[i];
        // The strongest bin left, again and again, its neighbours cleared each time: the bins
        // strong enough taken the strongest first (the first of equal ones), each one kept
        // unless near one kept before. (No vote, no peak.)
        // The bins sorted as numbers, each its votes and place in one key (whole votes times
        // 2^24, plus the place counted down: equal votes, the first place last in the order).
        var peaks = [], floor = 0.12 * peak, nc = 0, kept = [], TOP = 16777216;
        for (i = 0; peak > 0 && i < sm.length; i++) if (sm[i] >= floor) nc++;
        var keys = new Float64Array(nc);
        for (i = 0, nc = 0; peak > 0 && i < sm.length; i++) if (sm[i] >= floor) keys[nc++] = sm[i] * TOP + (TOP - 1 - i);
        keys.sort();
        for (var c = nc - 1; c >= 0 && peaks.length < 90; c--) {
            var at = TOP - 1 - keys[c] % TOP, pt = Math.floor(at / nr), pr = at % nr, near = false;
            for (var j = 0; j < kept.length && !near; j += 2) near = Math.abs(kept[j] - pt) <= 6 && Math.abs(kept[j + 1] - pr) <= 5;
            if (near) continue;
            kept.push(pt, pr);
            peaks.push({theta: thetas[pt], rho: pr * H.rhoStep - maxRho});
        }

        var lines = [], ix = lineIndex(xs, ys, n, theta);
        peaks.forEach(function (p) {
            var nx = Math.cos(p.theta), ny = Math.sin(p.theta), c = p.rho + nx * cx + ny * cy;
            var line = refineLine(xs, ys, n, {nx: nx, ny: ny, c: c}, 2.5, ix);
            if (line) line = refineLine(xs, ys, n, line, 1.6, ix);
            if (line && line.span >= 25) lines.push(line);
        });
        return dedupe(lines);
    }

    /**
     * The points ranged along a family's normal (theta), so that a line of the family looks
     * only at those near it instead of all: a counting sort by their place along the normal,
     * one-pixel bins, with how far they spread across it (for the bounds of nearLine()).
     */
    function lineIndex(xs, ys, n, theta) {
        var nx = Math.cos(theta), ny = Math.sin(theta), p = new Float64Array(n), lo = Infinity, hi = -Infinity, qlo = Infinity, qhi = -Infinity, i;
        for (i = 0; i < n; i++) {
            var v = nx * xs[i] + ny * ys[i], q = nx * ys[i] - ny * xs[i];
            p[i] = v;
            if (v < lo) lo = v;
            if (v > hi) hi = v;
            if (q < qlo) qlo = q;
            if (q > qhi) qhi = q;
        }
        if (!n) return null;
        var nb = Math.floor(hi - lo) + 1, start = new Int32Array(nb + 1), order = new Int32Array(n), b;
        for (i = 0; i < n; i++) start[Math.floor(p[i] - lo) + 1]++;
        for (b = 0; b < nb; b++) start[b + 1] += start[b];
        var at = start.slice(0, nb);
        for (i = 0; i < n; i++) order[at[Math.floor(p[i] - lo)]++] = i;
        return {nx: nx, ny: ny, lo: lo, nb: nb, start: start, order: order, qlo: qlo, qhi: qhi};
    }

    /**
     * The points that may lie within band of line l (all that do, and some more), in their
     * own order (sorted: the sums of a fit then come out as over all the points); null to
     * look at all of them. A line of normal n at an angle d from the index's: n.x = cos d p +
     * sin d q, with q within the points' spread across, bounds p.
     */
    function nearLine(ix, l, band, sorted) {
        if (!ix) return null;
        var cd = l.nx * ix.nx + l.ny * ix.ny, sd = l.ny * ix.nx - l.nx * ix.ny;
        if (cd < 0.5) return null;
        var s0 = Math.min(sd * ix.qlo, sd * ix.qhi), s1 = Math.max(sd * ix.qlo, sd * ix.qhi), m = band + 1;
        var b0 = Math.max(0, Math.floor((l.c - m - s1) / cd - ix.lo)), b1 = Math.min(ix.nb - 1, Math.floor((l.c + m - s0) / cd - ix.lo));
        if (b1 < b0) return new Int32Array(0);
        var out = ix.order.slice(ix.start[b0], ix.start[b1 + 1]);
        return sorted ? out.sort() : out;
    }

    /** Re-fit a line on the points within band pixels of it, and measure its extent. */
    function refineLine(xs, ys, n, l, band, ix) {
        var px = [], py = [], i, k, near = nearLine(ix, l, band, false), m = near ? near.length : n;
        for (k = 0; k < m; k++) {
            i = near ? near[k] : k;
            if (Math.abs(l.nx * xs[i] + l.ny * ys[i] - l.c) <= band) {
                px.push(xs[i]);
                py.push(ys[i]);
            }
        }
        if (px.length < 10) return null;
        var f = fitLine(px, py);
        // Keep the normal pointing the same way as before, so offsets compare.
        if (f.nx * l.nx + f.ny * l.ny < 0) {
            f.nx = -f.nx;
            f.ny = -f.ny;
            f.c = -f.c;
        }
        var dx = -f.ny, dy = f.nx, ts = new Float64Array(px.length);
        for (i = 0; i < px.length; i++) ts[i] = dx * px[i] + dy * py[i];
        var ext = extent(ts, 3, 4);
        f.t0 = ext.t0;
        f.t1 = ext.t1;
        f.span = ext.t1 - ext.t0;
        f.support = px.length;
        // Share of the extent where there is ink, pixel by pixel: a printed line is
        // continuous, a line drawn across the barcode's bars is ink one pixel in two.
        var occ = new Uint8Array(Math.max(1, Math.ceil(f.span) + 1)), filled = 0;
        for (i = 0; i < ts.length; i++) {
            var b = Math.floor(ts[i] - f.t0);
            if (b >= 0 && b < occ.length && !occ[b]) {
                occ[b] = 1;
                filled++;
            }
        }
        f.fill = filled / occ.length;
        // Ink beside the line, per pixel of band, against ink on it: a printed line has
        // paper on both sides, a line across the barcode has as much ink beside as on it.
        var on = 0, side = 0, beside = nearLine(ix, f, 6, false), mb = beside ? beside.length : n;
        for (k = 0; k < mb; k++) {
            i = beside ? beside[k] : k;
            var t = dx * xs[i] + dy * ys[i];
            if (t < f.t0 || t > f.t1) continue;
            var d = Math.abs(f.nx * xs[i] + f.ny * ys[i] - f.c);
            if (d <= 1.6) on++;
            else if (d >= 3.5 && d <= 6) side++;
        }
        f.edge = (side / 5) / Math.max(1, on / 3.2);
        return f;
    }

    /** Longest stretch of positions with no gap wider than maxGap bins of size bin. */
    function extent(ts, bin, maxGap) {
        var lo = Infinity, hi = -Infinity, i;
        for (i = 0; i < ts.length; i++) {
            if (ts[i] < lo) lo = ts[i];
            if (ts[i] > hi) hi = ts[i];
        }
        var nb = Math.floor((hi - lo) / bin) + 1, occ = new Uint8Array(nb);
        for (i = 0; i < ts.length; i++) occ[Math.floor((ts[i] - lo) / bin)] = 1;
        var best = {t0: lo, t1: lo, len: -1}, start = -1, last = -1;
        for (i = 0; i <= nb; i++) {
            if (i < nb && occ[i]) {
                if (start < 0 || i - last > maxGap) {
                    if (start >= 0 && last - start > best.len) best = {t0: lo + start * bin, t1: lo + (last + 1) * bin, len: last - start};
                    start = i;
                }
                last = i;
            }
        }
        if (start >= 0 && last - start > best.len) best = {t0: lo + start * bin, t1: lo + (last + 1) * bin, len: last - start};
        return best;
    }

    /** Peaks of the Hough transform can find one ink line twice: keep the better one. */
    function dedupe(lines) {
        lines.sort(function (a, b) { return b.support - a.support; });
        var kept = [];
        lines.forEach(function (l) {
            var dup = kept.some(function (k) {
                // Distance between the two lines at both ends of the shorter one.
                var dx = -l.ny, dy = l.nx;
                var ax = l.nx * l.c + dx * l.t0, ay = l.ny * l.c + dy * l.t0;
                var bx = l.nx * l.c + dx * l.t1, by = l.ny * l.c + dy * l.t1;
                return Math.abs(k.nx * ax + k.ny * ay - k.c) < 4 && Math.abs(k.nx * bx + k.ny * by - k.c) < 4;
            });
            if (!dup) kept.push(l);
        });
        return kept;
    }

    function intersect(a, b) {
        var det = a.nx * b.ny - a.ny * b.nx;
        if (Math.abs(det) < 1e-9) return null;
        return {x: (a.c * b.ny - a.ny * b.c) / det, y: (a.nx * b.c - a.c * b.nx) / det};
    }

    /** The two outermost lines among the long, continuous and isolated ones of a family, in offset order. */
    var LONE = 3.2;   // an outer line this many times further from the next than the median gap (option lone)

    function outerLines(lines, lone) {
        var maxSpan = 0;
        function clean(l) { return l.fill >= 0.7 && l.edge <= 0.5; }
        lines.forEach(function (l) { if (clean(l) && l.span > maxSpan) maxSpan = l.span; });
        var longOnes = lines.filter(function (l) { return l.span >= 0.55 * maxSpan && clean(l); });
        if (longOnes.length < 2) return null;
        // Same orientation of the normals, then sort by offset.
        var ref = longOnes[0];
        longOnes.forEach(function (l) {
            if (l.nx * ref.nx + l.ny * ref.ny < 0) {
                l.nx = -l.nx;
                l.ny = -l.ny;
                l.c = -l.c;
                var t0 = -l.t1;
                l.t1 = -l.t0;
                l.t0 = t0;
            }
        });
        longOnes.sort(function (a, b) { return a.c - b.c; });
        // With lone: an outer line much further from the next than the lines are from one
        // another is no border of the table (a match card's signature line, under its
        // shoot-off, joined to it by a signature).
        while (lone && longOnes.length >= 4) {
            var gaps = [];
            for (var i = 1; i < longOnes.length; i++) gaps.push(longOnes[i].c - longOnes[i - 1].c);
            var med = gaps.slice().sort(function (a, b) { return a - b; })[gaps.length >> 1];
            if (gaps[gaps.length - 1] > LONE * med) longOnes.pop();
            else if (gaps[0] > LONE * med) longOnes.shift();
            else break;
        }
        return {first: longOnes[0], last: longOnes[longOnes.length - 1], count: longOnes.length, span: maxSpan};
    }

    // ----------------------------------------------------------- homography

    /** Solve A x = b (8 x 8) by Gaussian elimination with partial pivoting. */
    function solve(A, b) {
        var n = b.length, i, j, k;
        for (i = 0; i < n; i++) {
            var p = i;
            for (k = i + 1; k < n; k++) if (Math.abs(A[k][i]) > Math.abs(A[p][i])) p = k;
            var tmp = A[i]; A[i] = A[p]; A[p] = tmp;
            var tb = b[i]; b[i] = b[p]; b[p] = tb;
            if (Math.abs(A[i][i]) < 1e-12) return null;
            for (k = i + 1; k < n; k++) {
                var f = A[k][i] / A[i][i];
                for (j = i; j < n; j++) A[k][j] -= f * A[i][j];
                b[k] -= f * b[i];
            }
        }
        var x = new Array(n);
        for (i = n - 1; i >= 0; i--) {
            var s = b[i];
            for (j = i + 1; j < n; j++) s -= A[i][j] * x[j];
            x[i] = s / A[i][i];
        }
        return x;
    }

    /** Homography mapping the four src points onto the four dst points. */
    function homography(src, dst) {
        var A = [], b = [], i;
        for (i = 0; i < 4; i++) {
            var x = src[i].x, y = src[i].y, u = dst[i].x, v = dst[i].y;
            A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]);
            b.push(u);
            A.push([0, 0, 0, x, y, 1, -v * x, -v * y]);
            b.push(v);
        }
        var h = solve(A, b);
        return h ? [h[0], h[1], h[2], h[3], h[4], h[5], h[6], h[7], 1] : null;
    }

    function apply(H, x, y) {
        var w = H[6] * x + H[7] * y + H[8];
        return {x: (H[0] * x + H[1] * y + H[2]) / w, y: (H[3] * x + H[4] * y + H[5]) / w};
    }

    /** Inverse of a 3 x 3 homography (row-major array of 9). */
    function invert(H) {
        var a = H[0], b = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7], i = H[8];
        var A = e * i - f * h, B = -(d * i - f * g), C = d * h - e * g;
        var det = a * A + b * B + c * C;
        if (Math.abs(det) < 1e-12) return null;
        return [A / det, -(b * i - c * h) / det, (b * f - c * e) / det,
                B / det, (a * i - c * g) / det, -(a * f - c * d) / det,
                C / det, -(a * h - b * g) / det, (a * e - b * d) / det];
    }

    /** H followed by a uniform scale of the result (working image -> full image). */
    function scaleAfter(H, s) {
        return [H[0] * s, H[1] * s, H[2] * s, H[3] * s, H[4] * s, H[5] * s, H[6], H[7], H[8]];
    }

    function dist(a, b) {
        return Math.hypot(a.x - b.x, a.y - b.y);
    }

    // ------------------------------------------------- straightened analysis

    /**
     * Positions where a profile has a local peak above min, at least gap apart.
     * A peak wider than maxWidth at half its height is a band of ink (the barcode, a
     * row of bold text), not a line, and is dropped.
     */
    function profilePeaks(p, min, gap, maxWidth) {
        var n = p.length, sm = new Float64Array(n), i, out = [];
        for (i = 2; i < n - 2; i++) sm[i] = (p[i - 2] + 2 * p[i - 1] + 3 * p[i] + 2 * p[i + 1] + p[i + 2]) / 9;
        var order = [];
        for (i = 1; i < n - 1; i++) if (sm[i] >= min && sm[i] >= sm[i - 1] && sm[i] >= sm[i + 1]) order.push(i);
        order.sort(function (a, b) { return sm[b] - sm[a]; });
        var taken = [];
        order.forEach(function (i) {
            if (taken.some(function (t) { return Math.abs(t - i) < gap; })) return;
            taken.push(i);
            var lo = i, hi = i;
            while (lo > 0 && sm[lo - 1] >= sm[i] / 2) lo--;
            while (hi < n - 1 && sm[hi + 1] >= sm[i] / 2) hi++;
            if (hi - lo + 1 > maxWidth) return;
            // Sub-pixel position: centroid of the raw profile around the peak.
            var s = 0, sw = 0;
            for (var k = Math.max(0, i - 3); k <= Math.min(n - 1, i + 3); k++) {
                s += k * p[k];
                sw += p[k];
            }
            out.push({pos: sw ? s / sw : i, height: sm[i]});
        });
        out.sort(function (a, b) { return a.pos - b.pos; });
        return out;
    }

    /** Merge intervals [a, b] closer than gap. */
    function mergeIntervals(iv, gap) {
        iv.sort(function (a, b) { return a[0] - b[0]; });
        var out = [];
        iv.forEach(function (s) {
            var last = out[out.length - 1];
            if (last && s[0] <= last[1] + gap) last[1] = Math.max(last[1], s[1]);
            else out.push([s[0], s[1]]);
        });
        return out;
    }

    function coverage(segs) {
        return segs.reduce(function (a, s) { return a + s[1] - s[0]; }, 0);
    }

    /**
     * Lines of the straightened grid mask: rows of ink runs give the horizontal lines,
     * columns the vertical ones. Each line comes with its position and its extents.
     * Coordinates are those of the straightened frame: (0, 0) is the corner of the header
     * top line and the left border, (W, Hb) the corner of the body bottom and the right
     * border; the mask covers [-mx, W + mx] x [-my, Hb + my2].
     */
    function frameLines(R) {
        var w = R.w, h = R.h, m = R.d, ox = R.ox, oy = R.oy, x, y, i, GAP = 3;
        var T = Math.max(4, Math.round(0.006 * R.W));
        // Ink of a thin horizontal stroke: paper T pixels above or below it. A band of
        // ink (the barcode, bold text) is ink on both sides and does not count, while
        // the line that borders it still does.
        var hm = new Uint8Array(w * h), vm = new Uint8Array(w * h);
        for (y = 0; y < h; y++) {
            for (x = 0; x < w; x++) {
                i = y * w + x;
                if (!m[i]) continue;
                if (!(y >= T && m[i - T * w] && y + T < h && m[i + T * w])) hm[i] = 1;
                if (!(x >= T && m[i - T] && x + T < w && m[i + T])) vm[i] = 1;
            }
        }
        m = hm;
        var Lh = Math.max(16, Math.round(0.035 * R.W));
        var ph = new Float64Array(h), runsH = [];
        for (y = 0; y < h; y++) {
            var rows = [];
            for (x = 0; x < w;) {
                if (!m[y * w + x]) { x++; continue; }
                // A run goes on through holes of up to GAP pixels: dotted lines stay whole.
                var s = x, last = x;
                while (x < w && (m[y * w + x] ? (last = x, true) : x - last <= GAP)) x++;
                x = last + 1;
                if (x - s >= Lh) {
                    rows.push([s - ox, x - ox]);
                    ph[y] += x - s;
                }
            }
            runsH.push(rows);
        }
        var hp = profilePeaks(ph, 0.06 * R.W, 5, Math.max(12, 0.03 * R.W));
        var hlines = hp.map(function (p) {
            var c = Math.round(p.pos), iv = [];
            for (y = Math.max(0, c - 3); y <= Math.min(h - 1, c + 3); y++) iv = iv.concat(runsH[y]);
            var segs = mergeIntervals(iv, 0.012 * R.W);
            return {v: p.pos - oy, segs: segs, cov: coverage(segs)};
        });

        // Row height: spacing of the lines that cross (nearly) the whole table.
        var full = hlines.filter(function (l) { return l.cov >= 0.7 * R.W; }).map(function (l) { return l.v; });
        var diffs = [];
        for (i = 1; i < full.length; i++) diffs.push(full[i] - full[i - 1]);
        diffs.sort(function (a, b) { return a - b; });
        var rowH = diffs.length ? diffs[Math.floor(diffs.length / 2)] : R.Hb / 10;

        var Lv = Math.max(8, Math.round(0.7 * rowH));
        m = vm;
        var pv = new Float64Array(w), runsV = [];
        for (x = 0; x < w; x++) {
            var cols = [];
            for (y = 0; y < h;) {
                if (!m[y * w + x]) { y++; continue; }
                var s2 = y, last2 = y;
                while (y < h && (m[y * w + x] ? (last2 = y, true) : y - last2 <= GAP)) y++;
                y = last2 + 1;
                if (y - s2 >= Lv) {
                    cols.push([s2 - oy, y - oy]);
                    pv[x] += y - s2;
                }
            }
            runsV.push(cols);
        }
        var vp = profilePeaks(pv, 1.5 * rowH, 5, Math.max(12, 0.03 * R.W));
        var vlines = vp.map(function (p) {
            var c = Math.round(p.pos), iv = [];
            for (x = Math.max(0, c - 3); x <= Math.min(w - 1, c + 3); x++) iv = iv.concat(runsV[x]);
            var segs = mergeIntervals(iv, 0.25 * rowH);
            return {u: p.pos - ox, segs: segs, cov: coverage(segs)};
        });
        return {hlines: hlines, vlines: vlines, rowH: rowH};
    }

    /** Nearest-neighbour warp of the grid's mask into the straightened frame. */
    function warpMask(mask, mw, mh, H, W, Hb, mx, my, my2) {
        var ox = Math.round(mx), oy = Math.round(my);
        var w = Math.round(W + 2 * mx), h = Math.round(Hb + my + my2), out = new Uint8Array(w * h), x, y;
        for (y = 0; y < h; y++) {
            var v = y - oy;
            for (x = 0; x < w; x++) {
                var u = x - ox, d = H[6] * u + H[7] * v + H[8];
                var sx = Math.round((H[0] * u + H[1] * v + H[2]) / d), sy = Math.round((H[3] * u + H[4] * v + H[5]) / d);
                if (sx >= 0 && sy >= 0 && sx < mw && sy < mh && mask[sy * mw + sx]) out[y * w + x] = 1;
            }
        }
        return {w: w, h: h, d: out, ox: ox, oy: oy, W: W, Hb: Hb};
    }

    /**
     * Straighten the grid seen through the quad tl, tr, br, bl (working-image points),
     * and find its lines.
     */
    function straighten(work, gridMask, quad, scaleToFull) {
        var W = (dist(quad.tl, quad.tr) + dist(quad.bl, quad.br)) / 2;
        var Hb = (dist(quad.tl, quad.bl) + dist(quad.tr, quad.br)) / 2;
        var H = homography(
            [{x: 0, y: 0}, {x: W, y: 0}, {x: W, y: Hb}, {x: 0, y: Hb}],
            [quad.tl, quad.tr, quad.br, quad.bl]
        );
        if (!H) return null;
        var R = warpMask(gridMask, work.w, work.h, H, W, Hb, 0.08 * W, 0.12 * Hb, 0.3 * Hb);
        var lines = frameLines(R);
        return {
            W: W, Hb: Hb, H: H, Hfull: scaleAfter(H, 1 / scaleToFull), quad: quad,
            hlines: lines.hlines, vlines: lines.vlines, rowH: lines.rowH, mask: R
        };
    }

    /** Rotate the corners of a quad a quarter turn, k times (frame turned clockwise). */
    function turnQuad(q, k) {
        var c = [q.tl, q.tr, q.br, q.bl];
        k = ((k % 4) + 4) % 4;
        return {tl: c[(0 + k) % 4], tr: c[(1 + k) % 4], br: c[(2 + k) % 4], bl: c[(3 + k) % 4]};
    }

    /**
     * Which way up the card is, from the grid alone: rows are the family of evenly
     * spaced long lines, and the total row hangs below the body, outside the frame.
     * Returns the number of quarter turns to apply to the quad.
     */
    function orientationGuess(frame) {
        function evenness(pos) {
            if (pos.length < 4) return Infinity;
            var d = [];
            for (var i = 1; i < pos.length; i++) d.push(pos[i] - pos[i - 1]);
            var mean = d.reduce(function (a, b) { return a + b; }, 0) / d.length;
            var v = d.reduce(function (a, b) { return a + (b - mean) * (b - mean); }, 0) / d.length;
            return Math.sqrt(v) / mean;
        }
        var fullH = frame.hlines.filter(function (l) { return l.cov >= 0.7 * frame.W; }).map(function (l) { return l.v; });
        var fullV = frame.vlines.filter(function (l) { return l.cov >= 0.6 * frame.Hb; }).map(function (l) { return l.u; });
        var turn = 0;
        if (evenness(fullV) < evenness(fullH) && fullV.length >= 6) turn = 1;
        // Lines outside the frame, beyond each side.
        var below = 0, above = 0, left = 0, right = 0, rowH = frame.rowH;
        frame.hlines.forEach(function (l) {
            if (l.v > frame.Hb + 0.4 * rowH) below += l.cov;
            if (l.v < -0.4 * rowH) above += l.cov;
        });
        frame.vlines.forEach(function (l) {
            if (l.u > frame.W + 0.4 * rowH) right += l.cov;
            if (l.u < -0.4 * rowH) left += l.cov;
        });
        if (turn === 0) return above > below ? 2 : 0;
        // Turned a quarter clockwise, the card shows its bottom, hence its total row, on the left.
        return left > right ? 1 : 3;
    }

    /**
     * Full analysis of a grey photo.
     *
     * @param {Object} full The photo, grey, at full resolution.
     * @param {Object} [opts] workSide (longest side of the working copy); all: every
     *                 grid of the photo (several scorecards side by side) instead of one;
     *                 quick: with all, no second chance once a grid is found; want: nor once
     *                 that many are (the cards per sheet).
     * @return {Object} {ok, reason, frame, work, scale, ...}, or with all {ok, grids: [...]}
     */
    function analyse(full, opts) {
        opts = opts || {};
        var P = analysePlan(full, opts), found = [], c, u;
        for (c = 0; c < P.cands.length; c++) {
            u = analyseUnit(P, 1, c);
            if (u.a && !opts.all) return u.a;
            found = joinUnit(found, u, false);
        }
        if (enough(opts, found)) return analysed(P, found, false);
        secondPlan(P);
        for (c = 0; c < P.more.length; c++) {
            if (covered(found, P.more[c])) continue;
            u = analyseUnit(P, 2, c, found);
            if (u.a && !opts.all) return u.a;
            found = joinUnit(found, u, true);
        }
        return analysed(P, found, P.cands.length || P.more.length);
    }

    /**
     * The picture's part of an analysis: its working copy, its ink and blots, the blots that
     * may be tables (cands). The same on every worker given the same picture.
     */
    function analysePlan(full, opts) {
        var ds = workCopy(full, opts), work = ds.img, w = work.w, h = work.h;
        var smooth = lineSmooth(work), radius = Math.max(6, Math.round(Math.max(w, h) / 130)), paper = paperOf(smooth, radius);
        var ink = binarize(smooth, radius, 0.15, undefined, undefined, paper);
        var cc = components(dilate(ink, w, h), w, h);
        var P = {opts: opts, work: work, scale: ds.scale, smooth: smooth, radius: radius, paper: paper,
            passes: {1: {ink: ink, cc: cc}}, cands: gridCandidates(cc.stats, w, h, opts.all), more: null};
        if (opts.trace) opts.trace.push({stage: 'candidates', n: P.cands.length, all: cc.stats.length, biggest: biggest(cc.stats)});
        return P;
    }

    /** The working copy of a photo (a thumbnail enlarged, a large photo reduced). */
    function workCopy(full, opts) {
        var minSide = opts.minSide === undefined ? 1200 : opts.minSide;
        return Math.max(full.w, full.h) < minSide ? upscale(full, minSide) : downscale(full, opts.workSide || 1600);
    }

    /**
     * Second chance, for a poor camera (an old webcam: soft, noisy, washed out). Its thin
     * printed lines come out too pale for the first threshold, while the band of darker table
     * along the edge of the sheet passes it and joins the writing into one thick blot. A
     * lower threshold, keeping only strokes darker than what lies on both sides of them,
     * finds the lines and leaves the band out. Its blots (more) only add grids where none
     * was found.
     */
    function secondPlan(P) {
        if (P.more) return P;
        var w = P.work.w, h = P.work.h;
        var faint = binarize(P.smooth, P.radius, FAINT.k, FAINT.rel, FAINT.floor, P.paper);
        var thin = thinStrokes(P.smooth, faint), cc2 = components(dilate(thin, w, h), w, h);
        P.passes[2] = {ink: thin, cc: cc2};
        P.more = gridCandidates(cc2.stats, w, h, P.opts.all);
        if (P.opts.trace) P.opts.trace.push({stage: 'thin', n: P.more.length, biggest: biggest(cc2.stats)});
        return P;
    }

    /**
     * One candidate blot of a pass (1, or 2: the second chance) looked at: its table (a),
     * else, every grid wanted, the parts it cuts into where its ink thins out (several tables
     * run together, writing between them joining their lines) and each part's table. Parts
     * lying on a grid found already (found, second pass) are left out.
     */
    function analyseUnit(P, pass, c, found) {
        var q = P.passes[pass], cand = pass === 1 ? P.cands[c] : P.more[c], opts = P.opts, w = P.work.w;
        var tryPart = function (part) { return tryGrid(P.work, q.ink, q.cc, part, P.scale, opts.trace, opts.lone); };
        var u = {a: tryPart(cand), parts: null, grids: null};
        if (u.a || !opts.all) return u;
        u.parts = splitBlot(q.cc, w, cand);
        if (found) u.parts = u.parts.filter(function (part) { return !covered(found, part); });
        if (opts.trace) opts.trace.push({stage: 'split', parts: u.parts.map(function (b) { return [b.x0, b.y0, b.x1, b.y1]; })});
        if (u.parts.length >= 2) u.grids = u.parts.map(tryPart);
        return u;
    }

    /** The parts each candidate blot of a pass cuts into (splitBlot), for the jobs of OcrPool.analyse. */
    function analyseCuts(P, pass) {
        var q = P.passes[pass], w = P.work.w;
        if (!q.cuts) q.cuts = (pass === 1 ? P.cands : P.more).map(function (cand) { return splitBlot(q.cc, w, cand); });
        return q.cuts;
    }

    /** One table looked for (tryGrid): in candidate c of a pass, or (k >= 0) in the k-th part it cuts into. */
    function analyseJob(P, pass, c, k) {
        var q = P.passes[pass], cand = pass === 1 ? P.cands[c] : P.more[c];
        return tryGrid(P.work, q.ink, q.cc, k >= 0 ? analyseCuts(P, pass)[c][k] : cand, P.scale, null, P.opts.lone);
    }

    /** The grids found with a candidate's: its table, or its parts' when they all make like tables. */
    function joinUnit(found, u, filter) {
        if (u.a) return found.concat([u.a]);
        if (!u.parts) return found;
        var grids = u.parts.map(function (part, i) { return {part: part, g: u.grids ? u.grids[i] : null}; });
        if (filter) grids = grids.filter(function (x) { return !covered(found, x.part); });
        return found.concat(splitGrids(grids.map(function (x) { return x.g; })));
    }

    /** No second chance needed (see analyse()). */
    function enough(opts, found) {
        return found.length > 0 && (!opts.all || opts.quick || opts.want && found.length >= opts.want);
    }

    /** A blot on a grid found already. */
    function covered(found, box) {
        return found.some(function (g) { return overlap(g.gridBox, box) > 0.3; });
    }

    function analysed(P, found, tried) {
        if (found.length) return {ok: true, grids: found, work: P.work, scale: P.scale};
        return {ok: false, reason: tried ? 'nolines' : 'nogrid', work: P.work, scale: P.scale};
    }

    var FAINT = {k: 0.06, rel: 0.15, floor: 6};

    // A blot of tables run together: cut where its ink, counted across it, falls below this
    // share of its middle count, over a gap this share of its length at least, each part this
    // share of it at least.
    var SPLIT_LOW = 0.3, SPLIT_GAP = 0.015, SPLIT_PART = 0.15;
    var SPLIT_SAME = 1.6;   // ...its parts' grids all fitted, the largest at most this times the smallest
    var SPLIT_RUN = 3;      // a table's line, told from writing: a run of ink this many gaps long

    /**
     * The parts of a blot holding several tables (side by side, or two by two): cut where
     * its own ink thins out across it, columns first, then rows. [] when it holds one.
     */
    function splitBlot(cc, w, s) {
        var out = splitBy(cc, w, s, false);
        // Writing across the gap (notes in the margin between two tables) fills it: the
        // tables' own lines only then, which run long across them and not across the gap.
        return out.length > 1 ? out : splitBy(cc, w, s, true);
    }

    function splitBy(cc, w, s, lines) {
        var out = [];
        // A part may still hold two tables: its own count tells (the gap is deeper there).
        var cols = [];
        blotCuts(cc, w, s, true, lines).forEach(function (p) { cols = cols.concat(blotCuts(cc, w, p, true, lines)); });
        cols.forEach(function (p) { out = out.concat(blotCuts(cc, w, p, false, lines)); });
        return out.length > 1 ? out : [];
    }

    /**
     * The grids of a blot's parts, kept only when every part is one and all are of a size
     * (tables side by side): a table cut in pieces gives none.
     */
    function splitGrids(grids) {
        if (grids.length < 2) return [];
        if (grids.some(function (g) { return !g; })) return [];
        var areas = grids.map(function (g) { return (g.gridBox.x1 - g.gridBox.x0) * (g.gridBox.y1 - g.gridBox.y0); });
        return Math.max.apply(null, areas) <= SPLIT_SAME * Math.min.apply(null, areas) ? grids : [];
    }

    /**
     * Where a blot can be cut across (alongX: by columns), from its ink counted across it; with
     * lines, only the ink of runs at least SPLIT_RUN gaps long the other way (a table's lines,
     * not handwriting).
     */
    function blotCuts(cc, w, s, alongX, lines) {
        var a0 = alongX ? s.x0 : s.y0, a1 = alongX ? s.x1 : s.y1, n = a1 - a0 + 1, prof = new Float32Array(n), x, y;
        var gap = Math.max(4, Math.round(SPLIT_GAP * n)), long = SPLIT_RUN * gap, mine = function (x, y) { return cc.labels[y * w + x] === s.label; };
        if (!lines) {
            for (y = s.y0; y <= s.y1; y++) {
                for (x = s.x0; x <= s.x1; x++) if (mine(x, y)) prof[(alongX ? x : y) - a0]++;
            }
        } else {
            // Runs the other way: rows of the blot for a cut by columns, columns for one by rows.
            var b0 = alongX ? s.y0 : s.x0, b1 = alongX ? s.y1 : s.x1;
            for (var b = b0; b <= b1; b++) {
                var start = -1;
                for (var a = a0; a <= a1 + 1; a++) {
                    var on = a <= a1 && (alongX ? mine(a, b) : mine(b, a));
                    if (on && start < 0) start = a;
                    if (!on && start >= 0) {
                        if (a - start >= long) for (var k = start; k < a; k++) prof[k - a0]++;
                        start = -1;
                    }
                }
            }
        }
        // Smoothed over a gap's width: a stroke crossing the gap does not close it.
        var part = SPLIT_PART * n, cuts = [], run = -1, i, sum = 0, sm = new Float32Array(n);
        for (i = 0; i < n + gap; i++) {
            if (i < n) sum += prof[i];
            if (i >= gap) sum -= prof[i - gap];
            var at = i - (gap >> 1);
            if (at >= 0 && at < n) sm[at] = sum / gap;
        }
        prof = sm;
        var sorted = Array.prototype.slice.call(prof).sort(function (p, q) { return p - q; }), low = SPLIT_LOW * sorted[n >> 1];
        for (i = 0; i <= n; i++) {
            if (i < n && prof[i] < low) {
                if (run < 0) run = i;
                continue;
            }
            // The smoothing narrows a gap by its own width: a third of it is left of the narrowest.
            if (run > 0 && i < n && i - run >= Math.max(3, gap / 3)) cuts.push([run, i]);
            run = -1;
        }
        var parts = [], from = 0;
        cuts.concat([[n, n]]).forEach(function (cut) {
            if (cut[0] - from >= part) parts.push([from, cut[0] - 1]);
            from = cut[1];
        });
        if (parts.length < 2) return [s];
        return parts.map(function (r) {
            var q = Object.assign({}, s);
            if (alongX) {
                q.x0 = a0 + r[0];
                q.x1 = a0 + r[1];
            } else {
                q.y0 = a0 + r[0];
                q.y1 = a0 + r[1];
            }
            return q;
        });
    }

    /** Share of the smaller of two boxes that the other covers. */
    function overlap(p, q) {
        var w = Math.min(p.x1, q.x1) - Math.max(p.x0, q.x0), h = Math.min(p.y1, q.y1) - Math.max(p.y0, q.y0);
        if (w <= 0 || h <= 0) return 0;
        return w * h / Math.min((p.x1 - p.x0) * (p.y1 - p.y0), (q.x1 - q.x0) * (q.y1 - q.y0));
    }

    /** The largest blots, for a trace. */
    function biggest(stats) {
        return stats.slice().sort(function (p, q) {
            return (q.x1 - q.x0) * (q.y1 - q.y0) - (p.x1 - p.x0) * (p.y1 - p.y0);
        }).slice(0, 5).map(function (s) {
            return {box: [s.x0, s.y0, s.x1, s.y1], area: s.area, fill: +(s.area / ((s.x1 - s.x0 + 1) * (s.y1 - s.y0 + 1))).toFixed(3),
                thick: +s.thickness.toFixed(1)};
        });
    }

    /**
     * The ink pixels that are darker than both sides of them, at some distance up to 7
     * pixels, in one of four directions: lines and handwriting, not the edge of a dark
     * area (one side of which is dark at every distance).
     */
    function thinStrokes(g, ink) {
        var w = g.w, h = g.h, d = g.d, out = new Uint8Array(w * h), x, y, k, s;
        var dirs = [[1, 0], [0, 1], [1, 1], [1, -1]], steps = [2, 3, 5, 7];
        for (y = 7; y < h - 7; y++) {
            for (x = 7; x < w - 7; x++) {
                var i = y * w + x;
                if (!ink[i]) continue;
                var v = d[i], keep = false;
                for (k = 0; k < 4 && !keep; k++) {
                    for (s = 0; s < 4 && !keep; s++) {
                        var o = (dirs[k][1] * w + dirs[k][0]) * steps[s];
                        if (Math.min(d[i + o], d[i - o]) - v >= 8) keep = true;
                    }
                }
                if (keep) out[i] = 1;
            }
        }
        return out;
    }

    /**
     * Lines, quad and frame of one candidate blot, or null when they do not make a
     * table: at least four long lines each way in the straightened frame.
     */
    function tryGrid(work, ink, cc, grid, scale, trace, lone) {
        var w = work.w, h = work.h;
        function fail(stage, info) {
            if (trace) trace.push({stage: stage, box: [grid.x0, grid.y0, grid.x1, grid.y1], info: info});
            return null;
        }
        // The grown blot decides what belongs to the grid; the line fits use the ink
        // itself, which is thinner and so more precise.
        var gridMask = new Uint8Array(w * h), xs = [], ys = [], i, x, y;
        for (y = grid.y0; y <= grid.y1; y++) {
            for (x = grid.x0; x <= grid.x1; x++) {
                i = y * w + x;
                if (cc.labels[i] === grid.label) {
                    gridMask[i] = 1;
                    if (ink[i]) {
                        xs.push(x);
                        ys.push(y);
                    }
                }
            }
        }
        // Thick lines give many points for nothing: thin the cloud for the transform.
        var stride = Math.max(1, Math.floor(xs.length / 120000)), px = [], py = [];
        for (i = 0; i < xs.length; i += stride) {
            px.push(xs[i]);
            py.push(ys[i]);
        }
        var cx = (grid.x0 + grid.x1) / 2, cy = (grid.y0 + grid.y1) / 2;
        var maxRho = Math.hypot(grid.x1 - grid.x0, grid.y1 - grid.y0) / 2 + 4;
        var angles = dominantAngles(px, py, px.length, cx, cy, maxRho);
        var fam = angles.map(function (t) { return familyLines(px, py, px.length, cx, cy, maxRho, t); });

        // Family whose normal is the most vertical holds the image's horizontal lines.
        var rowsFirst = Math.abs(Math.sin(angles[0])) >= Math.abs(Math.sin(angles[1]));
        var rows = outerLines(fam[rowsFirst ? 0 : 1]), cols = outerLines(fam[rowsFirst ? 1 : 0]);
        // lone, across the longer lines only: a match card is wider than tall, its signature line
        // runs along its rows; across its columns, a wide total column is no lone line.
        if (lone && rows && cols) {
            if (rows.span >= cols.span) rows = outerLines(fam[rowsFirst ? 0 : 1], true);
            else cols = outerLines(fam[rowsFirst ? 1 : 0], true);
        }
        if (!rows || !cols) {
            return fail('outer', fam.map(function (f) {
                return f.map(function (l) { return Math.round(l.span) + '/' + l.fill.toFixed(2) + '/' + l.edge.toFixed(2); }).slice(0, 14).join(' ');
            }));
        }

        var c = [intersect(rows.first, cols.first), intersect(rows.first, cols.last),
                 intersect(rows.last, cols.first), intersect(rows.last, cols.last)];
        if (c.some(function (p) { return !p; })) return fail('corners');
        // Name the corners as seen in the image: top-left is the smallest x + y, and so on.
        var pts = c.slice();
        var tl = pts.reduce(function (a, b) { return a.x + a.y <= b.x + b.y ? a : b; });
        var br = pts.reduce(function (a, b) { return a.x + a.y >= b.x + b.y ? a : b; });
        var rest = pts.filter(function (p) { return p !== tl && p !== br; });
        if (rest.length !== 2) return null;
        var tr = rest[0].x - rest[0].y >= rest[1].x - rest[1].y ? rest[0] : rest[1];
        var bl = tr === rest[0] ? rest[1] : rest[0];
        var quad = {tl: tl, tr: tr, br: br, bl: bl};

        var a = {ok: true, work: work, scale: scale, gridMask: gridMask, gridBox: grid, families: fam,
            quad: quad, frames: {}};
        var f0 = frame(a, 0);
        if (!f0) return fail('frame');
        var longH = f0.hlines.filter(function (l) { return l.cov >= 0.6 * f0.W; }).length;
        var longV = f0.vlines.filter(function (l) { return l.cov >= 0.35 * f0.Hb; }).length;
        if (longH < 4 || longV < 4) return fail('lines', {h: longH, v: longV, W: Math.round(f0.W), Hb: Math.round(f0.Hb), rowH: f0.rowH});
        a.guess = orientationGuess(f0);
        return a;
    }

    /**
     * The straightened frame of an analysis, the card turned k quarter turns from the
     * way it appears in the photo. Computed once per orientation.
     */
    function frame(a, k) {
        k = ((k % 4) + 4) % 4;
        if (!(k in a.frames)) a.frames[k] = straighten(a.work, a.gridMask, turnQuad(a.quad, k), a.scale);
        return a.frames[k];
    }

    /** Orientations to try, the most likely first. */
    function turns(a) {
        var g = a.guess || 0;
        return [g, (g + 2) % 4, (g + 1) % 4, (g + 3) % 4];
    }

    /**
     * Grey values along a line of the straightened frame, read from the full photo.
     * Used for the barcode, whose bars are finer than the working copy shows.
     */
    function scanline(full, Hfull, u0, u1, v, n) {
        var out = new Float32Array(n);
        for (var i = 0; i < n; i++) {
            var u = u0 + (u1 - u0) * i / (n - 1), p = apply(Hfull, u, v), s = sample(full, p.x, p.y);
            out[i] = isNaN(s) ? 255 : s;
        }
        return out;
    }

    /**
     * Straightened copy of a quad of the straightened frame, from the full photo:
     * the picture of one cell, out of the frame's corners u0, v0 to u1, v1.
     */
    function crop(full, Hfull, u0, v0, u1, v1, outW, outH) {
        var g = makeGray(outW, outH), x, y;
        for (y = 0; y < outH; y++) {
            var v = v0 + (v1 - v0) * (y + 0.5) / outH;
            for (x = 0; x < outW; x++) {
                var u = u0 + (u1 - u0) * (x + 0.5) / outW, p = apply(Hfull, u, v), s = sample(full, p.x, p.y);
                g.d[y * outW + x] = isNaN(s) ? 255 : s;
            }
        }
        return g;
    }

    /** Length in full-photo pixels of a segment of the straightened frame. */
    function fullLength(Hfull, u0, v0, u1, v1) {
        return dist(apply(Hfull, u0, v0), apply(Hfull, u1, v1));
    }

    return {
        makeGray: makeGray, fromRGBA: fromRGBA, downscale: downscale, binarize: binarize,
        components: components, homography: homography, apply: apply, invert: invert, sample: sample,
        analyse: analyse, frame: frame, turns: turns, scanline: scanline, crop: crop, fullLength: fullLength,
        analysePlan: analysePlan, secondPlan: secondPlan, analyseUnit: analyseUnit, analyseCuts: analyseCuts, analyseJob: analyseJob, workCopy: workCopy,
        joinUnit: joinUnit, enough: enough, covered: covered,
        lensOf: lensOf, undistort: undistort, undistortRGBA: undistortRGBA, lensPoint: lensPoint, lensBack: lensBack,
        _internal: {integral: integral, fitLine: fitLine, intersect: intersect, profilePeaks: profilePeaks,
            frameLines: frameLines, orientationGuess: orientationGuess, turnQuad: turnQuad,
            lineSmooth: lineSmooth, thinStrokes: thinStrokes, dilate: dilate}
    };
});
