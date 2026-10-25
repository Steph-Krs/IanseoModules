/**
 * Following the sheet through the video, frame after frame, whatever the camera does.
 *
 * The sheet's place is kept as a homography from the first frame ("the map") to the
 * current one. Each frame's keypoints (OcrOrb) are matched to those of the frame before,
 * whose scale and view are nearly the same, near where the motion so far puts them: the
 * camera may come closer, turn, go over the sheet, step by step. Some frames are kept as
 * keyframes, at every scale and place seen; when the frame before does not do (a jump, a
 * blurred frame, the sheet lost then found again), the frame is matched to them.
 *
 * Browser and Node alike, no dependency (OcrOrb given). Grey pictures are {w, h, d}.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.OcrFollow = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var POINTS = 600;        // keypoints per frame
    var NEAR = 0.12;         // reach of the matching to the frame before, share of the frame's longest side
    var TOL = 3.5;           // pixels within which a match agrees with the homography
    var MIN_IN = 20;         // matches agreeing, at least, for the frame to be placed
    var KEYS = 40;           // keyframes kept
    var KEY_SCALE = 1.3;     // a new keyframe: this much closer or further than any kept...
    var KEY_MOVE = 0.35;     // ...or its centre this far (share of its width) from theirs

    function create(env) {
        return {O: env.O, lens: env.lens || null, H: null, Hprev: null, last: null, keys: [], key: null, frames: 0, lost: 0};
    }

    function inv(H) {
        var a = H[0], b = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7], i = H[8];
        var A = e * i - f * h, B = -(d * i - f * g), C = d * h - e * g, det = a * A + b * B + c * C;
        if (Math.abs(det) < 1e-12) return null;
        return [A / det, -(b * i - c * h) / det, (b * f - c * e) / det, B / det, (a * i - c * g) / det, -(a * f - c * d) / det,
            C / det, -(a * h - b * g) / det, (a * e - b * d) / det];
    }

    /** How many frame pixels one map pixel covers, near the frame's centre. */
    function zoom(H, w, h) {
        var back = inv(H);
        if (!back) return 1;
        var O = {x: w / 2, y: h / 2}, c = apply(back, O.x, O.y), r = apply(back, O.x + 10, O.y);
        return 10 / Math.max(1e-9, Math.hypot(r.x - c.x, r.y - c.y));
    }

    function apply(H, x, y) {
        var z = H[6] * x + H[7] * y + H[8];
        return {x: (H[0] * x + H[1] * y + H[2]) / z, y: (H[3] * x + H[4] * y + H[5]) / z};
    }

    /** Keypoints of a frame, straightened from the lens when it is known. */
    function keypoints(fl, img) {
        var kp = fl.O.detect(img, POINTS);
        if (fl.lens) {
            kp.forEach(function (p) {
                var q = fl.lens.point(p.x, p.y);
                p.x = q.x;
                p.y = q.y;
            });
        }
        return kp;
    }

    /**
     * Matches of keypoints a (this frame) to b (an earlier one): with P (b -> a predicted),
     * only those of b it puts near each of a; without, all.
     */
    function matches(fl, a, b, P, w, h) {
        var O = fl.O;
        if (!P) return O.match(a, b, null, 64, 0.8);
        var R = NEAR * Math.max(w, h), cell = R, buckets = {}, qx = new Float32Array(b.length), qy = new Float32Array(b.length), i;
        for (i = 0; i < b.length; i++) {
            var q = apply(P, b[i].x, b[i].y);
            qx[i] = q.x;
            qy[i] = q.y;
            var key = Math.floor(q.y / cell) * 4096 + Math.floor(q.x / cell);
            (buckets[key] = buckets[key] || []).push(i);
        }
        var out = [], R2 = R * R;
        for (var k = 0; k < a.length; k++) {
            var p = a[k], cx = Math.floor(p.x / cell), cy = Math.floor(p.y / cell), best = 999, second = 999, bi = -1;
            for (var by = cy - 1; by <= cy + 1; by++) {
                for (var bx = cx - 1; bx <= cx + 1; bx++) {
                    var list = buckets[by * 4096 + bx];
                    if (!list) continue;
                    for (var j = 0; j < list.length; j++) {
                        var m = list[j], dx = qx[m] - p.x, dy = qy[m] - p.y;
                        if (dx * dx + dy * dy > R2) continue;
                        var dist = O.hamming(p.d, b[m].d);
                        if (dist < best) {
                            second = best;
                            best = dist;
                            bi = m;
                        } else if (dist < second) {
                            second = dist;
                        }
                    }
                }
            }
            if (bi >= 0 && best <= 64 && best < 0.85 * second) out.push({a: k, b: bi, dist: best});
        }
        return out;
    }

    /** Homography b -> a from the matches, or null. */
    function relate(fl, a, b, ms) {
        if (ms.length < MIN_IN) return null;
        var r = fl.O.ransac(ms.map(function (m) { return b[m.b]; }), ms.map(function (m) { return a[m.a]; }), TOL, 300);
        return r && r.n >= MIN_IN ? r : null;
    }

    /** The kept keyframe that sees the sheet from about where H does, the oldest first, or null. */
    function keyNear(fl, H, w, h) {
        var z = zoom(H, w, h), back = inv(H);
        if (!back) return null;
        var c = apply(back, w / 2, h / 2), span = w / z;
        for (var i = 0; i < fl.keys.length; i++) {
            var k = fl.keys[i], ratio = Math.max(z / k.zoom, k.zoom / z);
            if (ratio < KEY_SCALE && Math.hypot(c.x - k.c.x, c.y - k.c.y) < KEY_MOVE * span) return k;
        }
        return null;
    }

    function addKey(fl, kp, H, w, h) {
        var z = zoom(H, w, h), c = apply(inv(H), w / 2, h / 2), k = {kp: kp, H: H, zoom: z, c: c, used: fl.frames};
        fl.keys.push(k);
        if (fl.keys.length > KEYS) {
            // The one used least lately goes (never the first: the map's own frame).
            var worst = 1;
            for (var i = 2; i < fl.keys.length; i++) if (fl.keys[i].used < fl.keys[worst].used) worst = i;
            if (fl.keys[worst] !== fl.key) fl.keys.splice(worst, 1);
        }
        return k;
    }

    /** Place the frame against an earlier one (its keypoints and homography), predicted map -> frame by Hp. */
    function against(fl, kp, ref, Hp, w, h) {
        var back = inv(ref.H), P = Hp && back ? fl.O.mul(Hp, back) : null;
        var r = relate(fl, kp, ref.kp, matches(fl, kp, ref.kp, P, w, h));
        return r ? {r: r, H: fl.O.mul(r.H, ref.H)} : null;
    }

    /**
     * One frame (grey, the size kept for following). -> {ok, H (map -> frame pixels),
     * n (matches agreeing), how: 'start' | 'key' | 'step' | 'search' | 'lost'}
     *
     * The frame is placed against the current keyframe (errors do not pile up from frame
     * to frame), else against the frame before, else against every keyframe. Once the
     * view has gone far from the current keyframe, the keyframe kept for that view takes
     * over (the oldest: the sheet is put back where it was first seen), or the frame
     * becomes one.
     */
    function frame(fl, img) {
        fl.frames++;
        var w = img.w, h = img.h, kp = keypoints(fl, img), O = fl.O, got = null, how = 'key';
        if (!fl.keys.length) {
            if (kp.length < 4 * MIN_IN) return {ok: false, n: 0, how: 'lost'};
            var H0 = [1, 0, 0, 0, 1, 0, 0, 0, 1];
            fl.key = addKey(fl, kp, H0, w, h);
            fl.H = H0;
            fl.last = {kp: kp, H: H0};
            return {ok: true, H: H0, n: kp.length, how: 'start'};
        }
        // Where the motion so far puts the sheet.
        var Hp = null;
        if (fl.H) {
            var back = fl.Hprev && inv(fl.Hprev);
            Hp = back ? O.mul(O.mul(fl.H, back), fl.H) : fl.H;
        }
        if (Hp && fl.key) got = against(fl, kp, fl.key, Hp, w, h) || against(fl, kp, fl.key, fl.H, w, h);
        if (!got && Hp && fl.last) {
            how = 'step';
            got = against(fl, kp, fl.last, Hp, w, h) || against(fl, kp, fl.last, fl.H, w, h);
        }
        if (!got) {
            how = 'search';
            var ref = fl.H ? apply(inv(fl.H) || [1, 0, 0, 0, 1, 0, 0, 0, 1], w / 2, h / 2) : null;
            var order = fl.keys.slice().sort(function (p, q) {
                return ref ? Math.hypot(p.c.x - ref.x, p.c.y - ref.y) - Math.hypot(q.c.x - ref.x, q.c.y - ref.y) : 0;
            });
            for (var i = 0; i < order.length && !got; i++) {
                got = against(fl, kp, order[i], null, w, h);
                if (got) fl.key = order[i];
            }
        }
        if (!got) {
            fl.lost++;
            fl.Hprev = null;
            return {ok: false, n: 0, how: 'lost'};
        }
        var H = got.H;
        fl.lost = 0;
        fl.Hprev = fl.H;
        fl.H = H;
        fl.last = {kp: kp, H: H};
        fl.key.used = fl.frames;
        // Gone far from the keyframe: the one kept for this view, or this frame.
        var k = keyNear(fl, H, w, h);
        if (k !== fl.key) fl.key = k || addKey(fl, kp, H, w, h);
        return {ok: true, H: H, n: got.r.n, how: how};
    }

    return {create: create, frame: frame, inv: inv, zoom: zoom};
});
