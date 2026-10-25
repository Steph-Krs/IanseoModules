/**
 * Keypoints of a picture and their matching, to follow a sheet through the video
 * whatever the camera does: moved, turned, brought closer, the sheet lost and found
 * again. ORB-like: FAST corners on a pyramid, oriented by their intensity centroid,
 * each described by 256 brightness comparisons in a smoothed patch turned to its
 * orientation (a binary descriptor, compared by Hamming distance); a homography is then
 * fitted to the matches by RANSAC and refined on its inliers.
 *
 * Browser and Node alike, no dependency. Grey pictures are {w, h, d}.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.OcrOrb = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var LEVELS = 4;          // pyramid levels
    var SCALE = Math.SQRT1_2; // size of each level against the one before
    var FAST_T = 18;         // brightness step of a FAST corner
    var EDGE = 20;           // pixels kept clear along the borders (the descriptor's turned patch)
    var RADIUS = 15;         // patch radius of the orientation and the descriptor
    var BINS = 32;           // orientations the comparison pattern is turned to

    // ------------------------------------------------------------- pictures

    function grey(w, h) {
        return {w: w, h: h, d: new Uint8Array(w * h)};
    }

    /** Bilinear reduction to a scale. */
    function scaled(g, s) {
        var w = Math.max(1, Math.round(g.w * s)), h = Math.max(1, Math.round(g.h * s)), out = grey(w, h);
        var fx = g.w / w, fy = g.h / h;
        for (var y = 0; y < h; y++) {
            var sy = Math.min(g.h - 1.001, (y + 0.5) * fy - 0.5), y0 = Math.max(0, Math.floor(sy)), ty = Math.max(0, sy - y0);
            for (var x = 0; x < w; x++) {
                var sx = Math.min(g.w - 1.001, (x + 0.5) * fx - 0.5), x0 = Math.max(0, Math.floor(sx)), tx = Math.max(0, sx - x0);
                var i = y0 * g.w + x0;
                out.d[y * w + x] = (g.d[i] * (1 - tx) + g.d[i + 1] * tx) * (1 - ty) + (g.d[i + g.w] * (1 - tx) + g.d[i + g.w + 1] * tx) * ty;
            }
        }
        return out;
    }

    /** Box mean over 5 x 5 (the descriptor compares smoothed pixels: noise would flip its bits). */
    function smooth(g) {
        var w = g.w, h = g.h, I = new Uint32Array((w + 1) * (h + 1)), out = grey(w, h), x, y;
        for (y = 0; y < h; y++) {
            var row = 0;
            for (x = 0; x < w; x++) {
                row += g.d[y * w + x];
                I[(y + 1) * (w + 1) + x + 1] = I[y * (w + 1) + x + 1] + row;
            }
        }
        for (y = 0; y < h; y++) {
            var y0 = Math.max(0, y - 2), y1 = Math.min(h, y + 3);
            for (x = 0; x < w; x++) {
                var x0 = Math.max(0, x - 2), x1 = Math.min(w, x + 3);
                var s = I[y1 * (w + 1) + x1] - I[y0 * (w + 1) + x1] - I[y1 * (w + 1) + x0] + I[y0 * (w + 1) + x0];
                out.d[y * w + x] = s / ((x1 - x0) * (y1 - y0));
            }
        }
        return out;
    }

    // ---------------------------------------------------------------- FAST

    var CIRCLE = [[0, -3], [1, -3], [2, -2], [3, -1], [3, 0], [3, 1], [2, 2], [1, 3], [0, 3], [-1, 3], [-2, 2], [-3, 1],
        [-3, 0], [-3, -1], [-2, -2], [-1, -3]];

    /**
     * FAST-9 corners of a picture: 9 contiguous pixels of the circle all brighter, or all
     * darker, than the centre by t. Scored by the sum of those differences, then only
     * the best of each 3 x 3 neighbourhood kept.
     */
    function fast(g, t) {
        var w = g.w, h = g.h, d = g.d, off = CIRCLE.map(function (c) { return c[1] * w + c[0]; });
        var score = new Float32Array(w * h), list = [], x, y, k;
        var b = new Int16Array(32);
        for (y = Math.max(3, EDGE); y < h - Math.max(3, EDGE); y++) {
            for (x = Math.max(3, EDGE); x < w - Math.max(3, EDGE); x++) {
                var i = y * w + x, c = d[i], hi = c + t, lo = c - t;
                // Quick rejection: of pixels 0, 4, 8, 12, three must be beyond the step.
                var p0 = d[i + off[0]], p4 = d[i + off[4]], p8 = d[i + off[8]], p12 = d[i + off[12]];
                var nb = (p0 > hi) + (p4 > hi) + (p8 > hi) + (p12 > hi), nd = (p0 < lo) + (p4 < lo) + (p8 < lo) + (p12 < lo);
                if (nb < 3 && nd < 3) continue;
                for (k = 0; k < 16; k++) {
                    var v = d[i + off[k]];
                    b[k] = b[k + 16] = v > hi ? 1 : v < lo ? -1 : 0;
                }
                var run = 0, best = 0, sign = 0;
                for (k = 0; k < 32; k++) {
                    if (b[k] !== 0 && b[k] === sign) run++;
                    else {
                        sign = b[k];
                        run = sign ? 1 : 0;
                    }
                    if (run > best) best = run;
                }
                if (best < 9) continue;
                var s = 0;
                for (k = 0; k < 16; k++) s += Math.max(0, Math.abs(d[i + off[k]] - c) - t);
                score[i] = s;
                list.push(i);
            }
        }
        var out = [];
        list.forEach(function (i) {
            var s = score[i];
            for (var dy = -1; dy <= 1; dy++) {
                for (var dx = -1; dx <= 1; dx++) {
                    if ((dx || dy) && score[i + dy * w + dx] > s) return;
                }
            }
            out.push({x: i % w, y: Math.floor(i / w), s: s});
        });
        return out;
    }

    // ---------------------------------------------------------- descriptors

    // 256 pairs of points within the patch, drawn once (seeded, Gaussian around the
    // centre as in BRIEF), then turned to each of BINS orientations.
    var PATTERN = (function () {
        var seed = 2463534242, pts = [];
        var rnd = function () {
            seed ^= seed << 13;
            seed ^= seed >>> 17;
            seed ^= seed << 5;
            return ((seed >>> 0) % 1000000) / 1000000;
        };
        var gauss = function () {
            var u = Math.max(1e-9, rnd()), v = rnd();
            return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
        };
        var clip = function (v) { return Math.max(-RADIUS + 2, Math.min(RADIUS - 2, v)); };
        for (var i = 0; i < 256; i++) pts.push([clip(gauss() * RADIUS / 2.5), clip(gauss() * RADIUS / 2.5), clip(gauss() * RADIUS / 2.5), clip(gauss() * RADIUS / 2.5)]);
        var turned = [];
        for (var b = 0; b < BINS; b++) {
            var a = 2 * Math.PI * b / BINS, ca = Math.cos(a), sa = Math.sin(a), p = new Int8Array(1024);
            pts.forEach(function (q, j) {
                p[4 * j] = Math.round(ca * q[0] - sa * q[1]);
                p[4 * j + 1] = Math.round(sa * q[0] + ca * q[1]);
                p[4 * j + 2] = Math.round(ca * q[2] - sa * q[3]);
                p[4 * j + 3] = Math.round(sa * q[2] + ca * q[3]);
            });
            turned.push(p);
        }
        return turned;
    })();

    /** Orientation of a keypoint: from the centre to the centroid of brightness of its disc. */
    function angle(g, x, y) {
        var m10 = 0, m01 = 0, w = g.w;
        for (var dy = -RADIUS; dy <= RADIUS; dy++) {
            var span = Math.floor(Math.sqrt(RADIUS * RADIUS - dy * dy)), row = (y + dy) * w + x;
            for (var dx = -span; dx <= span; dx++) {
                var v = g.d[row + dx];
                m10 += dx * v;
                m01 += dy * v;
            }
        }
        return Math.atan2(m01, m10);
    }

    function describe(sm, x, y, a) {
        var bin = ((Math.round(a / (2 * Math.PI) * BINS) % BINS) + BINS) % BINS, p = PATTERN[bin];
        var w = sm.w, c = y * w + x, out = new Uint32Array(8);
        for (var j = 0; j < 256; j++) {
            var u = sm.d[c + p[4 * j + 1] * w + p[4 * j]], v = sm.d[c + p[4 * j + 3] * w + p[4 * j + 2]];
            if (u < v) out[j >> 5] |= 1 << (j & 31);
        }
        return out;
    }

    /**
     * Keypoints of a picture, the strongest first, at most n, spread over it (the best
     * of each cell of a coarse grid first, so that one busy corner does not take them all).
     *
     * @return {Array} [{x, y (in the picture's pixels), level, angle, d: Uint32Array(8)}]
     */
    function detect(g, n) {
        n = n || 600;
        var out = [], level = g, s = 1;
        for (var l = 0; l < LEVELS; l++) {
            if (l) {
                // Each level from the one before: a gentle step, without aliasing.
                level = scaled(level, SCALE);
                s = level.w / g.w;
            }
            if (level.w < 2 * EDGE + 8 || level.h < 2 * EDGE + 8) break;
            var sm = smooth(level), pts = fast(level, FAST_T);
            // Spread: per cell of 32 x 32 pixels of the level, its strongest first.
            var cells = {};
            pts.forEach(function (p) {
                var key = Math.floor(p.y / 32) * 10000 + Math.floor(p.x / 32);
                (cells[key] = cells[key] || []).push(p);
            });
            var ranked = [];
            Object.keys(cells).forEach(function (k) {
                cells[k].sort(function (a, b) { return b.s - a.s; }).forEach(function (p, r) { p.rank = r; ranked.push(p); });
            });
            ranked.sort(function (a, b) { return a.rank - b.rank || b.s - a.s; });
            var share = Math.round(n * (l === 0 ? 0.4 : 0.6 / (LEVELS - 1)));
            ranked.slice(0, share).forEach(function (p) {
                var a = angle(sm, p.x, p.y);
                out.push({x: p.x / s, y: p.y / s, level: l, angle: a, d: describe(sm, p.x, p.y, a)});
            });
        }
        return out;
    }

    // ------------------------------------------------------------- matching

    function popcount(v) {
        v = v - ((v >>> 1) & 0x55555555);
        v = (v & 0x33333333) + ((v >>> 2) & 0x33333333);
        return (((v + (v >>> 4)) & 0x0F0F0F0F) * 0x01010101) >>> 24;
    }

    function hamming(a, b) {
        var s = 0;
        for (var i = 0; i < 8; i++) s += popcount(a[i] ^ b[i]);
        return s;
    }

    /**
     * Matches of keypoints a against b: for each of a, the nearest of b, kept when clearly
     * nearer than the second (ratio) and near enough. near(pa, pb), when given, restricts
     * the candidates (to those near where the motion puts them).
     *
     * @return {Array} [{a: index in a, b: index in b, dist}]
     */
    function match(a, b, near, maxDist, ratio) {
        maxDist = maxDist || 64;
        ratio = ratio || 0.8;
        var out = [];
        for (var i = 0; i < a.length; i++) {
            var best = 999, second = 999, bj = -1, da = a[i].d;
            for (var j = 0; j < b.length; j++) {
                if (near && !near(a[i], b[j])) continue;
                var dist = hamming(da, b[j].d);
                if (dist < best) {
                    second = best;
                    best = dist;
                    bj = j;
                } else if (dist < second) {
                    second = dist;
                }
            }
            if (bj >= 0 && best <= maxDist && best < ratio * second) out.push({a: i, b: bj, dist: best});
        }
        return out;
    }

    // ----------------------------------------------------------- homography

    /** Homography (h22 = 1) through 4 pairs, or the least-squares one through more. */
    function fitH(src, dst) {
        var n = src.length, A = new Float64Array(64), r = new Float64Array(8), k, a, b;
        // Normalised: centred and scaled, for a well-conditioned system.
        var norm = function (pts) {
            var mx = 0, my = 0, s = 0;
            pts.forEach(function (p) { mx += p.x; my += p.y; });
            mx /= n;
            my /= n;
            pts.forEach(function (p) { s += Math.hypot(p.x - mx, p.y - my); });
            s = Math.SQRT2 / Math.max(1e-9, s / n);
            return {mx: mx, my: my, s: s};
        };
        var ns = norm(src), nd = norm(dst);
        for (k = 0; k < n; k++) {
            var x = (src[k].x - ns.mx) * ns.s, y = (src[k].y - ns.my) * ns.s, u = (dst[k].x - nd.mx) * nd.s, v = (dst[k].y - nd.my) * nd.s;
            var rows = [[x, y, 1, 0, 0, 0, -u * x, -u * y, u], [0, 0, 0, x, y, 1, -v * x, -v * y, v]];
            rows.forEach(function (row) {
                for (a = 0; a < 8; a++) {
                    for (b = 0; b < 8; b++) A[a * 8 + b] += row[a] * row[b];
                    r[a] += row[a] * row[8];
                }
            });
        }
        var h = solve(A, r, 8);
        if (!h) return null;
        // Back from the normalised coordinates: H = Tdst^-1 . Hn . Tsrc.
        var Hn = [h[0], h[1], h[2], h[3], h[4], h[5], h[6], h[7], 1];
        var Ts = [ns.s, 0, -ns.s * ns.mx, 0, ns.s, -ns.s * ns.my, 0, 0, 1];
        var Ti = [1 / nd.s, 0, nd.mx, 0, 1 / nd.s, nd.my, 0, 0, 1];
        var H = mul(Ti, mul(Hn, Ts));
        if (!H[8]) return null;
        for (k = 0; k < 9; k++) H[k] /= H[8];
        return H;
    }

    function solve(A, b, n) {
        A = A.slice();
        b = b.slice();
        for (var i = 0; i < n; i++) {
            var p = i;
            for (var k = i + 1; k < n; k++) if (Math.abs(A[k * n + i]) > Math.abs(A[p * n + i])) p = k;
            if (Math.abs(A[p * n + i]) < 1e-12) return null;
            if (p !== i) {
                for (var c = 0; c < n; c++) {
                    var t = A[i * n + c];
                    A[i * n + c] = A[p * n + c];
                    A[p * n + c] = t;
                }
                var tb = b[i];
                b[i] = b[p];
                b[p] = tb;
            }
            for (k = i + 1; k < n; k++) {
                var f = A[k * n + i] / A[i * n + i];
                for (c = i; c < n; c++) A[k * n + c] -= f * A[i * n + c];
                b[k] -= f * b[i];
            }
        }
        var x = new Float64Array(n);
        for (i = n - 1; i >= 0; i--) {
            var s = b[i];
            for (var j = i + 1; j < n; j++) s -= A[i * n + j] * x[j];
            x[i] = s / A[i * n + i];
        }
        return x;
    }

    function mul(A, B) {
        var C = new Array(9);
        for (var i = 0; i < 3; i++) {
            for (var j = 0; j < 3; j++) C[i * 3 + j] = A[i * 3] * B[j] + A[i * 3 + 1] * B[3 + j] + A[i * 3 + 2] * B[6 + j];
        }
        return C;
    }

    function apply(H, x, y) {
        var z = H[6] * x + H[7] * y + H[8];
        return {x: (H[0] * x + H[1] * y + H[2]) / z, y: (H[3] * x + H[4] * y + H[5]) / z};
    }

    /**
     * Homography src -> dst through the matches that agree (RANSAC, then refitted on all
     * of them), or null.
     *
     * @param {Array} src, dst Points {x, y}, pairwise.
     * @param {number} tol Distance in dst pixels within which a match agrees.
     * @return {Object} {H, inliers: [indexes], n}
     */
    function ransac(src, dst, tol, iters) {
        var n = src.length;
        if (n < 8) return null;
        iters = iters || 400;
        var best = null, bestN = 0, seed = 12345, tol2 = tol * tol;
        var rnd = function (m) {
            seed = (seed * 1103515245 + 12345) & 0x7fffffff;
            return seed % m;
        };
        var count = function (H, keep) {
            var c = 0;
            for (var k = 0; k < n; k++) {
                var p = apply(H, src[k].x, src[k].y), dx = p.x - dst[k].x, dy = p.y - dst[k].y;
                if (dx * dx + dy * dy <= tol2) {
                    c++;
                    if (keep) keep.push(k);
                }
            }
            return c;
        };
        for (var it = 0; it < iters; it++) {
            var idx = [];
            while (idx.length < 4) {
                var r = rnd(n);
                if (idx.indexOf(r) < 0) idx.push(r);
            }
            var H = fitH(idx.map(function (k) { return src[k]; }), idx.map(function (k) { return dst[k]; }));
            if (!H || !sane(H)) continue;
            var c = count(H);
            if (c > bestN) {
                bestN = c;
                best = H;
                // Enough agreement found: fewer draws are needed.
                var share = c / n, need = Math.log(0.01) / Math.log(Math.max(1e-6, 1 - Math.pow(share, 4)));
                if (it > need) break;
            }
        }
        if (!best) return null;
        // Refit on the inliers, twice (the refit may take in a few more).
        for (var pass = 0; pass < 2; pass++) {
            var keep = [];
            count(best, keep);
            if (keep.length < 8) break;
            var H2 = fitH(keep.map(function (k) { return src[k]; }), keep.map(function (k) { return dst[k]; }));
            if (!H2 || !sane(H2)) break;
            best = H2;
        }
        var inl = [];
        count(best, inl);
        return {H: best, inliers: inl, n: inl.length};
    }

    /** A homography a sheet can take: not folded, not flattened to a line. */
    function sane(H) {
        var det = H[0] * H[4] - H[1] * H[3];
        return det > 1e-6 && Math.abs(H[6]) < 0.01 && Math.abs(H[7]) < 0.01;
    }

    return {detect: detect, match: match, ransac: ransac, fitH: fitH, apply: apply, mul: mul, hamming: hamming,
        _internal: {fast: fast, smooth: smooth, scaled: scaled}};
});
