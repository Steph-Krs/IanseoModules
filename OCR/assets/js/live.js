/**
 * The live view's sheet, on the page's side. Every frame of the video, the sheet is
 * followed (OcrFollow, on a small copy of the frame) and what is known of it is carried
 * along: its grids, whose cards they are, their cells and what has been read in them. The
 * slow work (lens, grids, headers, layouts, cells, checks) is given out as tasks, one at
 * a time, to be run away from the video (OcrLiveTasks, in a worker): the video never
 * waits. Each task is chosen for the frame of the moment: the grids while the sheet is
 * seen whole, a header once it shows large enough, the cells the camera comes close to.
 *
 * Coordinates: "map" = pixels of the follower's first frame (small copy, lens undone);
 * a grid's cells are in its straightened frame (u, v), carried to the map by Huv. A full
 * frame's pixels (lens undone) are the small copy's divided by s.
 *
 * Browser and Node alike, no dependency (the modules are given).
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.OcrLive = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var LENS_TRIES = 3;      // frames the lens is looked for on, at most
    var GRIDS_EVERY = 0.7;   // s between two searches of the grids, while some are missing
    var GRIDS_AGAIN = 3;     // ...and while a grid's fit is not confirmed yet
    var FITS = 8;            // fits kept per grid, to agree on
    var AGREE = 3;           // fits agreeing that confirm a grid
    var SWITCH = 2;          // fits more, agreeing on another place, for a grid to change its fit
    var WIDE = 1.35;         // zoom (against the map) up to which the sheet may be seen whole
    var HEADER_PX = 380;     // width of a card's header, in full-frame pixels, worth reading
    var HEADER_AGAIN = 1.25; // ...or that much wider than the last unsure try
    var HEADER_WAIT = 1;     // ...or s after it (twice as long after each further try, HEADER_CHECK at most:
                             // a card with no archer's name printed is not tried every second for ever)
    var VOTES = 2;           // unsure readings of a header giving the same archer, that are enough
    var GUESS_GAP = 4;       // ...each within this gap of the free reading (per character)
    var SHAKE = 160;         // pixels a second (small frame) from which the camera's motion blurs
    var CELL_PX = 14;        // height of a cell, in full-frame pixels, worth reading
    var GAIN = 1.2;          // a view worth reading: this much larger than the best so far
    var AGAIN = 0.85;        // ...or, for a cell in doubt, nearly as large: a second opinion
    var AGAIN_MAX = 3;       // ...that many at most, until a better view comes
    var PER_TASK = 14;       // cells read per task
    var VIEWS = 6;           // views kept per cell
    var EPS = 1e-3;          // probability of a value a view did not give
    var SOLVE_EVERY = 1.2;   // s at least between two checks of a card
    var SOLVE_NEW = 4;       // ...and cells read anew since the last one
    var SOLVE_LATE = 3;      // ...or s since then, any cell read anew
    var TRUST_NUMBERS = 8;   // written numbers that agree with the arrows, at least, for anything sure
    var REFINE_EVERY = 2;    // s between two times a grid is put back on its lines
    var REFINE_PX = 8;       // height of a cell, in full-frame pixels, for its lines to be seen
    var REFINE_SHARP = 0.3;  // ...and the camera that still, at least
    var REFINE_FAR = 0.3;    // cells the table is looked for around where the following puts it
    var REFINE_SHARE = 0.3;  // share of a correction taken (one frame's own error of the following left out)
    var REFINE_MOVE = 0.35;  // cells a correction may move the table by, at most (beyond, a
                             // column or a row away fits nearly as well: rather none)
    var LOST_RESTART = 2.5;  // s the sheet may stay lost before the view starts afresh (another sheet;
                             // the trials lost it 1.1 s at most while it stayed)
    var HEADER_CHECK = 8;    // s between two readings of an identified card's header (one card at a time)
    var HEADER_SOON = 3;     // ...once the view was disturbed since (the sheet lost a moment, the camera moving)
    var HEADER_RECHECK = 1;  // ...when the last one gave another archer
    var VERIFY_EVERY = 4;    // s at least between two searches of the grids asked by a header not found
    var CLOSE_EVERY = 8;     // ...and between two made close up, a header out of sight
    var CHECK_MISSES = 3;    // a card's header not found that many times in a row (on every card read lately)…
    var MISS_SPAN = 5;       // ...each within this many s of the one before: another sheet
    var CHECK_WINDOW = 6;    // s within which a card's header was read again, for it to count
    var AWAY = 2;            // searches in a row finding grids (two at least), none where the cards are: another sheet
    var GONE = 2;            // searches in a row finding no grid at all, the cards' place in the frame: the sheet taken away
    var GONE_SHARP = 0.5;    // ...on frames that still, at least (a blurred one may show none)
    var SAME_GRID = 0.5;     // a detection is a known grid whose table's centre is within this share of its width
    var CONFLICTS = 3;       // cells of a grid read surely otherwise than they were surely, within…
    var CONFLICT_SPAN = 4;   // ...s of each other: another sheet maybe (its header read again at once)
    var CONFLICT_P = 0.9;    // "surely", for that
    var SPOT_EVERY = 3;      // s between two looks at a few cells of a card read for sure (the sheet still the same?)
    var SPOT_CELLS = 3;      // ...that many
    var SPOT_SPAN = 10;      // ...the looks of that many s counted
    var CHECK_PX = 300;      // width of an identified card's header worth reading again (its archer known)
    var HEADER_TOP = 0.15;   // a header worth reading: its name and club lines in the frame (this share of the
                             // table's width above it; the target's box above them may be cut by the frame's edge)
    var HEADER_WHOLE = 0.25; // ...the whole of it: only then does a header not found tell of another sheet
    var PITCH = 1.06;        // tables side by side on a sheet: this many table widths apart (trials: 1.02 to 1.14)
    var PLACE_TOL = 0.3;     // ...a grid's place on the sheet within this share of a place of a slot
    var BLANK_P = 0.85;      // an arrow read empty that surely is empty (the ends shot told by a few: OcrLayout.shotEnds,
                             // six cells read empty together; empty cells read 0.91 to 0.95 on the trials)
    // A camera fixed over the table (env.fixed: only the sheets move). The view of the
    // moment is compared with the one before, on a copy of 4 x 4 pixel means: nothing to
    // follow, and a sheet changed, moved or taken away shows at once (trials: the same
    // sheet 27 s later 0.00 % of the pixels changed; another sheet 13 to 16 %).
    var STILL_DIFF = 25;     // grey levels a pixel of that copy changes by, to count
    var STILL_MOVE = 0.005;  // share of them, from one frame to the next: something moves (a hand, a sheet)
    var STILL_SETTLE = 0.5;  // s without anything moving: the view is still again
    var STILL_CHANGE = 0.03; // share of them against the view before: another view (a sheet changed or moved;
                             // the camera's exposure settling, brightness and contrast evened out, 1.5 % at most)
    var STILL_MARGIN = 0.12; // grids found: only their pixels compared, each grown by this share of its size
                             // (a hand or a thing passing beside the sheets then disturbs nothing)
    var FIXED_TASK = 40;     // cells read per task, the camera fixed (each cell once in a view, as from a photo)
    var CALIB_SIZE = 0.2;    // the first sheet's tables give their size: a table found this far from it is none
    var OVERLAP = 0.15;      // two grids covering this share of the smaller one: impossible on a sheet (the
                             // following or a fit gone wrong); twice in a row, the view starts afresh

    /**
     * @param {Object} env {V, O, F, Lay: the modules (Lay to bound the numbers read, may be
     *     left out); expected: grids per sheet (0: unknown);
     *     opts: reading options; lens: the camera's lens when known already (null: none,
     *     undefined: to be measured); session; few(): the archers of the sheet's target
     *     (ISK code); roster(): the competition's archers; sure: confidence of an arrow shown sure; refine: false to leave the
     *     grids where the following puts them, 'read' to read their cells there too}.
     */
    function create(env) {
        var lv = {env: env, lens: undefined, lensTries: 0, grids: [], nextId: 1, busy: null, gridsAt: -9, frames: 0,
            H: null, s: 1, t: 0, frameInfo: {}, expected: env.expected || 0, opts: env.opts || {}, epoch: 0, calib: env.calib || null};
        setLens(lv, env.lens);
        return lv;
    }

    /** The camera's lens: the follower starts again with it (its map drawn straight). */
    function setLens(lv, lens) {
        lv.lens = lens;
        var V = lv.env.V, point = lens ? function (x, y) {
            var k = lv.s, q = V.lensPoint(lens, x / k, y / k);
            return {x: q.x * k, y: q.y * k};
        } : null;
        lv.follow = lv.env.F.create({O: lv.env.O, lens: point ? {point: point} : null});
        lv.grids = [];
        lv.H = null;
    }

    /**
     * Another sheet: the view starts afresh from the frame of the moment (a new map, no
     * grid). The cards of the sheet left are kept aside (retired); the results of tasks
     * given out for it are left out (their frames forgotten).
     */
    function restart(lv, why) {
        lv.retired = (lv.retired || []).concat(lv.grids.filter(function (g) { return g.ident; })).slice(-12);
        setLens(lv, lv.lens);
        lv.frameInfo = {};
        lv.gridsAt = -9;
        lv.lostAt = undefined;
        lv.away = 0;
        lv.gone = 0;
        lv.overlaps = 0;
        lv.verifyPlace = false;
        lv.restarts = (lv.restarts || 0) + 1;
        lv.restarted = why;
        lv.epoch++;
    }

    // --------------------------------------------------------------- geometry

    function mul(A, B) {
        var C = new Array(9);
        for (var i = 0; i < 3; i++) {
            for (var j = 0; j < 3; j++) C[i * 3 + j] = A[i * 3] * B[j] + A[i * 3 + 1] * B[3 + j] + A[i * 3 + 2] * B[6 + j];
        }
        return C;
    }

    function scaling(k) {
        return [k, 0, 0, 0, k, 0, 0, 0, 1];
    }

    function apply(H, x, y) {
        var z = H[6] * x + H[7] * y + H[8];
        return {x: (H[0] * x + H[1] * y + H[2]) / z, y: (H[3] * x + H[4] * y + H[5]) / z};
    }

    /** Map -> full frame pixels (lens undone), for the frame now followed. */
    function mapToFull(lv) {
        return lv.H ? mul(scaling(1 / lv.s), lv.H) : null;
    }

    function inside(lv, pts, margin) {
        var w = lv.fullW, h = lv.fullH;
        return pts.every(function (p) { return p.x >= margin && p.y >= margin && p.x <= w - margin && p.y <= h - margin; });
    }

    // ---------------------------------------------------------------- tasks

    /** A task, its frame remembered (homography and scale) to read its result. */
    function task(lv, type, data, full) {
        var t = Object.assign({id: lv.nextId++, type: type}, data);
        if (full) t.img = full();
        lv.frameInfo[t.id] = {H: lv.H, s: lv.s, t: lv.t, grid: data.grid, sharp: lv.sharp, epoch: lv.epoch};
        lv.busy = t;
        return t;
    }

    /** The next task for this frame, or null; full() gives the full frame (grey) when asked. */
    function choose(lv, full) {
        var now = lv.t, fixed = !!lv.env.fixed;
        if (!lv.H) return null;
        // The camera fixed: nothing while a sheet is handled (being changed or moved).
        if (fixed && lv.sharp < 1) return null;
        var zoom = fixed ? 1 : lv.env.F.zoom(lv.H, lv.smallW, lv.smallH);
        // Grids, while some are missing and the sheet may be seen whole; the lens with
        // them while it is not known (measured on the grids' lines).
        // While a grid expected is missing: the grids, as often as the worker can.
        var want = lv.expected || 1, wide = zoom <= WIDE;
        var gridsTask = function (verify) {
            lv.gridsAt = now;
            var t = task(lv, 'grids', {expected: lv.expected, lens: lv.lens === undefined, layouts: lv.env.layouts || [], up: frameUp(lv)}, full);
            lv.frameInfo[t.id].verify = !!verify;
            return t;
        };
        // No grid known yet: looked for however close the camera came since the map's first
        // frame (taken moving, maybe further: its scale says nothing then); some missing:
        // while the sheet may be seen whole, or now and then.
        var missing = lv.grids.length < want || !lv.expected && lv.grids.length < 6 && now - lv.gridsAt > 3;
        // The camera fixed, the same view gives the same grids: once, then now and then (a
        // sheet with fewer cards than expected is read meanwhile).
        var every = fixed ? (lv.grids.length ? 2 * GRIDS_AGAIN : GRIDS_EVERY) : GRIDS_EVERY;
        if (missing && now - lv.gridsAt >= every && (wide || !lv.grids.length || now - lv.gridsAt >= GRIDS_AGAIN)) return gridsTask();
        var M = mapToFull(lv), sharp = lv.sharp;
        // Only frames still enough are read (a moving camera blurs); after a long wait,
        // the best of what comes.
        var idle = !fixed && now - (lv.readAt || 0) > 1.5;
        if (sharp < 0.5 && !idle) return null;
        lv.readAt = now;
        // Now and then, the header of a card identified, read again: another sheet put
        // where the last one was (a camera that stays where it is) follows on as the same.
        var due = function (g) {
            if (g.recheckAt) return now >= g.recheckAt;
            var last = g.checkedAt || g.identAt || 0;
            return now - last >= ((lv.disturbedAt || -99) > last ? HEADER_SOON : HEADER_CHECK);
        };
        var toCheck = fixed ? null : lv.grids.filter(function (g) { return g.ident && g.head && findable(g) && due(g); })
            .sort(function (a, b) { return (a.checkedAt || a.identAt || 0) - (b.checkedAt || b.identAt || 0); })
            .map(function (g) {
                var Hf = mul(M, g.head.Huv), w = g.head.W, band = function (top) {
                    return [[0, -top * w], [w, -top * w], [w, 0.02 * w], [0, 0.02 * w]].map(function (p) { return apply(Hf, p[0], p[1]); });
                };
                var c = band(HEADER_TOP), width = Math.hypot(c[1].x - c[0].x, c[1].y - c[0].y);
                return inside(lv, c, 4) && width >= CHECK_PX ? {g: g, width: width, Hfull: Hf, whole: inside(lv, band(HEADER_WHOLE), 0)} : null;
            }).filter(Boolean)[0];
        var checkTask = function () {
            var gc = toCheck.g;
            gc.checkedAt = now;
            gc.recheckAt = 0;
            var tc = task(lv, 'header', {cards: [{id: gc.id, width: toCheck.width,
                turns: [{turn: gc.head.turn, W: gc.head.W, Hb: null, rowH: null, Hfull: toCheck.Hfull}]}],
                session: lv.env.session || 0, few: lv.env.few ? lv.env.few() : []}, full);
            lv.frameInfo[tc.id].partial = !toCheck.whole;
            return tc;
        };
        // A header read again soon (the last reading gave another archer, or none): before
        // the cards not known yet, which may otherwise take every header task.
        if (toCheck && toCheck.g.recheckAt) return checkTask();
        // Headers: the cards not known yet, whose header shows large enough.
        var heads = [];
        lv.grids.forEach(function (g) {
            if (g.ident) return;
            // Fitted, the header lies above the table fitted (its way up known), unless it
            // was not found there twice (a grid fitted upside down): then every way up of
            // the outline found.
            var frames = g.head && (g.headerMiss || 0) < 2 ? [g.head] : g.turns;
            var turns = frames.map(function (f) {
                var Hf = mul(M, f.Huv), w = f.W;
                // Its name and club lines, at least, in the frame.
                var c = [[0, -HEADER_TOP * w], [w, -HEADER_TOP * w], [w, 0.02 * w], [0, 0.02 * w]].map(function (p) { return apply(Hf, p[0], p[1]); });
                var width = Math.hypot(c[1].x - c[0].x, c[1].y - c[0].y);
                return {turn: f.turn, W: f.W, Hb: f.Hb, rowH: f.rowH, Hfull: Hf, seen: inside(lv, c, 4), width: width};
            });
            // The ways up whose header band is in the frame, large enough. Tried and not
            // read: again when shown larger, or after a while (the camera may have steadied).
            var shown = turns.filter(function (f) { return f.seen && f.width >= HEADER_PX; });
            if (!shown.length) return;
            var width = Math.max.apply(null, shown.map(function (f) { return f.width; }));
            var wait = Math.min(HEADER_CHECK, HEADER_WAIT * Math.pow(2, Math.max(0, (g.headTries || 0) - 2)));
            if (g.tried && width < g.tried * HEADER_AGAIN && now - g.triedAt < wait) return;
            heads.push({id: g.id, turns: shown.map(function (f) { return {turn: f.turn, W: f.W, Hb: f.Hb, rowH: f.rowH, Hfull: f.Hfull}; }),
                width: width});
        });
        if (heads.length) {
            heads.forEach(function (h) {
                var g = grid(lv, h.id);
                g.tried = h.width;
                g.triedAt = now;
            });
            return task(lv, 'header', {cards: heads, session: lv.env.session || 0, few: fewFor(lv)}, full);
        }
        if (toCheck) return checkTask();
        // A header not found where it was: the grids looked for again, to see that the sheet
        // is still where it was.
        if (lv.verifyPlace && !fixed && now - lv.gridsAt >= GRIDS_EVERY) {
            lv.verifyPlace = false;
            lv.verifiedAt = now;
            return gridsTask();
        }
        // A card due whose header the frame cannot show (too small, out of the frame): the
        // grids looked for again instead, likewise, however close the camera seems (a view
        // following another sheet put in place is bent: its zoom says nothing).
        // Close up, not that often (the worker's time goes to the cells read close up), and
        // for the place only (its fits, of parts of tables, are left out).
        var unseen = fixed ? [] : lv.grids.filter(function (g) { return g.ident && findable(g) && due(g); });
        var closeOk = wide || sharp >= 0.7 && now - (lv.closeAt || -99) >= CLOSE_EVERY;
        if (unseen.length && closeOk && now - lv.gridsAt >= GRIDS_EVERY) {
            unseen.forEach(function (g) {
                g.checkedAt = now;
                g.recheckAt = 0;
            });
            if (!wide) lv.closeAt = now;
            return gridsTask(!wide);
        }
        // A card whose layout is not the one its grid was matched to: matched again.
        var toFit = lv.grids.find(function (g) { return g.card && !g.fitting && !g.refit && !sameLayout(g, g.card.structure); });
        if (toFit) {
            toFit.fitting = true;
            toFit.refit = true;
            return task(lv, 'fit', {ref: toFit.ref, structure: toFit.card.structure, turn: toFit.fit ? toFit.fit.turn : null, grid: toFit.id});
        }
        // Checks of the cards whose readings changed.
        var toSolve = lv.grids.find(function (g) {
            var since = now - (g.solvedAt || -9);
            return g.card && g.dirty && since >= SOLVE_EVERY && ((g.fresh || 0) >= SOLVE_NEW || since >= SOLVE_LATE);
        });
        if (toSolve) {
            toSolve.dirty = false;
            toSolve.fresh = 0;
            toSolve.solvedAt = now;
            return task(lv, 'solve', solveData(toSolve));
        }
        // The grids put back on their own lines now and then, for what is shown: the
        // following drifts (a lens it does not model, a sheet not quite flat), the table's
        // lines stay where they are. After the checks: the worker's time goes to reading.
        if (sharp >= REFINE_SHARP && !fixed) {
            var toRefine = lv.grids.filter(function (g) { return now - (g.refinedAt || -9) >= REFINE_EVERY && refinable(lv, M, g); });
            if (toRefine.length) {
                toRefine.forEach(function (g) { g.refinedAt = now; });
                var tr = task(lv, 'refine', {grids: toRefine.map(function (g) { return refineData(M, g); })}, full);
                lv.frameInfo[tr.id].refining = toRefine.map(function (g) { return g.id; });
                return tr;
            }
        }
        // Now and then, the grids again while a grid's fit is not confirmed by others yet
        // (one off by a row or a column stands alone).
        var unsettled = lv.grids.some(function (g) { return !g.pick || g.pick.agree < AGREE; });
        if (unsettled && wide && !fixed && now - lv.gridsAt >= GRIDS_AGAIN) return gridsTask();
        // Cells the frame shows large enough, those in doubt first.
        var cand = [];
        lv.grids.forEach(function (g) {
            if (!g.fit) return;
            var Hc = mul(M, g.fit.Huv), probes = g.shot.ends === null ? g.shot.probes : [];
            // Now and then, a few cells read for sure looked at again (not to change them: to
            // see that the sheet's writing is still the one read).
            var spot = !fixed && g.ident && sharp >= 0.7 && now - (g.spotAt || 0) >= SPOT_EVERY ? SPOT_CELLS : 0, spotted = 0;
            var order = g.cells.slice(), from = g.spotFrom || 0;
            order = order.slice(from % Math.max(1, order.length)).concat(order.slice(0, from % Math.max(1, order.length)));
            order.forEach(function (x) {
                var isSpot = false;
                if (g.sure[x.key]) {
                    if (spotted >= spot || x.cell.kind !== 'arrow') return;
                    isSpot = true;
                } else if (skipped(g, x)) {
                    // Read for sure: kept as it is. On an end not shot: not read.
                    return;
                }
                var c = x.cell, m = 0.5 * (c.v1 - c.v0);
                var pts = [[c.u0 - m, c.v0 - m], [c.u1 + m, c.v0 - m], [c.u1 + m, c.v1 + m], [c.u0 - m, c.v1 + m]].map(function (p) { return apply(Hc, p[0], p[1]); });
                if (!inside(lv, pts, 2)) return;
                var um = (c.u0 + c.u1) / 2, a = apply(Hc, um, c.v0), b = apply(Hc, um, c.v1), size = Math.hypot(a.x - b.x, a.y - b.y);
                if (size < CELL_PX) return;
                if (isSpot) {
                    spotted++;
                    g.spotAt = now;
                    g.spotFrom = g.cells.indexOf(x) + 1;
                    cand.push({g: g, x: x, Hc: Hc, q: size * sharp, spot: true, rank: 30});
                    return;
                }
                // A view's worth: larger, and sharper (the camera still). The camera fixed:
                // the view stays the same, each cell is read once in it...
                var q = size * sharp;
                var doubt = !g.sure[x.key];
                // ...and those still in doubt once the card is checked, a few more times (each
                // frame's noise is its own).
                if (fixed && x.epoch === lv.epoch && (!doubt || !g.sol || (x.again || 0) >= AGAIN_MAX)) return;
                if (!fixed && !(q >= GAIN * x.best || (doubt && q >= AGAIN * x.best && x.lastTask !== lv.lastCells && (x.again || 0) < AGAIN_MAX))) return;
                var kindRank = c.kind === 'arrow' || c.line === 'total' ? 2 : 1;
                // The arrows that tell how many ends were shot, first.
                var probe = c.kind === 'arrow' && probes.indexOf(c.arrow) >= 0 ? 20 : 0;
                cand.push({g: g, x: x, Hc: Hc, q: q, rank: probe + (doubt ? 10 : 0) + kindRank + Math.min(2, q / Math.max(1, x.best))});
            });
        });
        if (cand.length) {
            cand.sort(function (a, b) { return b.rank - a.rank; });
            var items = cand.slice(0, fixed ? FIXED_TASK : PER_TASK).map(function (o) {
                o.x.epoch = lv.epoch;
                if (!o.spot) {
                    o.x.pendingQ = o.q;
                    o.x.again = o.q >= GAIN * o.x.best ? 0 : (o.x.again || 0) + 1;
                }
                var c = o.x.cell;
                return {card: o.g.id, key: o.x.key, kind: c.kind, line: c.line, u0: c.u0, u1: c.u1, v0: c.v0, v1: c.v1, Hfull: o.Hc, q: o.q,
                    allowed: allowedOf(lv, o.g, c), spot: !!o.spot};
            });
            var t = task(lv, 'cells', {items: items, opts: lv.opts, snap: lv.env.snap !== false}, full);
            lv.lastCells = t.id;
            return t;
        }
        return null;
    }

    /** The numbers a cell can hold, once the card (its archer's face) is known; else null. */
    function allowedOf(lv, g, c) {
        var Lay = lv.env.Lay;
        if (!Lay || !g.card || c.kind === 'arrow') return null;
        return Lay.cellAllowed(c, g.fit.printed, g.card.values);
    }

    /** A fitted grid whose lines this frame shows: a good part of its table, large enough. */
    function refinable(lv, M, g) {
        if (lv.env.refine === false || !g.fit || !g.fit.lines) return false;
        var L = g.fit.lines, us = L.us, vs = L.vs, Hc = mul(M, g.fit.Huv), n = 0;
        var um = (us[0] + us[us.length - 1]) / 2, a = apply(Hc, um, vs[1]), b = apply(Hc, um, vs[2]);
        if (Math.hypot(a.x - b.x, a.y - b.y) < REFINE_PX) return false;
        for (var i = 0; i < 5; i++) {
            for (var j = 0; j < 5; j++) {
                var p = apply(Hc, us[0] + (us[us.length - 1] - us[0]) * (i + 0.5) / 5, vs[0] + (vs[vs.length - 1] - vs[0]) * (j + 0.5) / 5);
                if (p.x >= 0 && p.y >= 0 && p.x < lv.fullW && p.y < lv.fullH) n++;
            }
        }
        return n >= 6;
    }

    function refineData(M, g) {
        return {card: g.id, Hfull: mul(M, g.fit.Huv), lines: g.fit.lines, far: REFINE_FAR};
    }

    /**
     * Grids put back on their lines in a task's frame: their homographies carried to the map,
     * for what is shown (g.view). Read there too only when asked: on the trial videos, the
     * cells were read better where the fits agree, each one snapped to its own lines. A
     * correction moving the table by more than REFINE_MOVE cells is rather a line taken for
     * another: left out. -> how many were.
     */
    function refined(lv, info, list) {
        if (!info.H || !list || !list.length) return 0;
        var back = mul(inv(info.H), scaling(info.s)), n = 0;
        list.forEach(function (r) {
            var g = grid(lv, r.card);
            if (!g || !g.fit || !g.fit.lines) return;
            var was = shownPose(g), Huv = mul(back, r.Hfull), L = g.fit.lines, us = L.us, vs = L.vs;
            var u0 = us[0], u1 = us[us.length - 1], v0 = vs[0], v1 = vs[vs.length - 1];
            var a = apply(was, u0, vs[1]), b = apply(was, u0, vs[2]), cell = Math.hypot(a.x - b.x, a.y - b.y);
            var moved = [[u0, v0], [u1, v0], [u1, v1], [u0, v1]].reduce(function (m, c) {
                var p = apply(was, c[0], c[1]), q = apply(Huv, c[0], c[1]);
                return Math.max(m, Math.hypot(p.x - q.x, p.y - q.y));
            }, 0);
            if (!(moved <= REFINE_MOVE * cell)) return;
            // Each frame has its own error of the following: the table is moved part of the
            // way only, its corners between where they were and where this frame saw them.
            if (REFINE_SHARE < 1) {
                var cs = [[u0, v0], [u1, v0], [u1, v1], [u0, v1]], src = [], dst = [];
                cs.forEach(function (c) {
                    var p = apply(was, c[0], c[1]), q = apply(Huv, c[0], c[1]);
                    src.push({x: c[0], y: c[1]});
                    dst.push({x: p.x + REFINE_SHARE * (q.x - p.x), y: p.y + REFINE_SHARE * (q.y - p.y)});
                });
                Huv = lv.env.O.fitH(src, dst) || Huv;
            }
            g.view = Huv;
            if (lv.env.refine === 'read') {
                g.fit.Huv = Huv;
                if (g.head) g.head.Huv = mul(Huv, [1, 0, g.headTable.u0, 0, 1, g.headTable.v0, 0, 0, 1]);
            }
            g.refines = (g.refines || 0) + 1;
            n++;
        });
        return n;
    }

    /**
     * The header of a card identified, read again: does it show another sheet? The same
     * other archer read (or best guessed) VOTES times (one reading alone may be a blurred
     * header misread); or no header found, in a row, on every card whose header reads.
     * After a reading that does not give the card's archer, the header is read again soon.
     */
    function anotherSheet(lv, g, c, partial) {
        var mine = g.ident.archer.enId;
        g.checkAt = lv.t;
        if (c.archer && c.archer.enId === mine || c.guess && c.guess.archer.enId === mine) {
            g.checkMiss = 0;
            g.missAt = -99;
            g.checkOther = {};
            g.checkOkAt = lv.t;
            return false;
        }
        var id = c.archer && c.status !== 'unsure' && c.status !== 'none' ? c.archer.enId
            : c.guess && c.guess.gap <= GUESS_GAP ? c.guess.archer.enId : null;
        if (id !== null) {
            // Another archer: told again soon, before the view starts afresh.
            g.recheckAt = lv.t + HEADER_RECHECK;
            var o = g.checkOther = g.checkOther || {};
            o[id] = (o[id] || 0) + 1;
            return o[id] >= VOTES;
        }
        // A header not found, part of it out of the frame: that says nothing.
        if (partial) return false;
        // A header not found: the grids are looked for again, their place tells (AWAY; not
        // more often than VERIFY_EVERY), and the header read again soon, a few times. Not
        // found in a row on every card whose header was read (those read lately; a card
        // told by its place is left out, its header hard to find even on its own sheet), no
        // card read right meanwhile: another sheet, put in the same place.
        g.checkMiss = lv.t - (g.missAt || -99) <= MISS_SPAN ? (g.checkMiss || 0) + 1 : 1;
        if (g.checkMiss === 1) g.missFrom = lv.t;
        g.missAt = lv.t;
        if (g.checkMiss < CHECK_MISSES) g.recheckAt = lv.t + HEADER_RECHECK;
        if (lv.t - (lv.verifiedAt || -99) >= VERIFY_EVERY) lv.verifyPlace = true;
        var read = lv.grids.filter(function (o) { return o.ident && findable(o) && lv.t - (o.checkAt || -99) <= CHECK_WINDOW; });
        return read.length > 0 && read.every(function (o) {
            return o.checkMiss >= CHECK_MISSES && lv.t - o.missAt <= MISS_SPAN && !((o.checkOkAt || 0) >= o.missFrom);
        });
    }

    /** A card whose header can be read: identified by it, or found once since. */
    function findable(g) {
        return g.ident.status !== 'place' || !!g.checkOkAt;
    }

    /**
     * The archers a header is read among: the sheet's target's from ISK's code; else, once
     * a card of the sheet is identified, the archers of its target and session not given
     * to a grid yet (a few names to tell apart, not every archer's); else none (all).
     */
    function fewFor(lv) {
        var qr = lv.env.few ? lv.env.few() : [];
        return qr.length ? qr : sameTarget(lv);
    }

    function sameTarget(lv) {
        var known = lv.grids.filter(function (g) { return g.ident; }), taken = {};
        if (!known.length || !lv.env.roster) return [];
        var a0 = known[0].ident.archer, num = parseInt(a0.target, 10);
        known.forEach(function (g) { taken[g.ident.archer.enId] = true; });
        return lv.env.roster().filter(function (a) {
            return parseInt(a.target, 10) === num && a.session === a0.session && !taken[a.enId];
        });
    }

    /**
     * The archer a grid's place on the sheet gives, from the nearest card identified: the
     * core prints a target's cards in letter order, side by side (A, B, C… along the
     * tables' width, the sheet held either way up), or for four cards two by two (A, B
     * above C, D). Only for a grid on a place of that layout, PITCH widths apart, and an
     * archer of that target there not given to a grid yet. -> archer or null
     */
    function byPlace(lv, g) {
        if (!g.spot) return null;
        var square = twoByTwo(lv), best = null;
        lv.grids.forEach(function (o) {
            if (!o.ident || !o.spot) return;
            var b = o.spot, dx = g.spot.c.x - b.c.x, dy = g.spot.c.y - b.c.y;
            // Along the table's width, and down the sheet (the way the table reads).
            var along = (dx * b.ax.x + dy * b.ax.y) / (PITCH * b.w), down = (-dx * b.ax.y + dy * b.ax.x) / b.h;
            var k = Math.round(along), from = o.ident.archer.target, letter = from.charCodeAt(from.length - 1) - 65, to;
            if (Math.abs(along - k) > PLACE_TOL) return;
            if (Math.abs(down) <= 0.5) {
                if (!k) return;
                to = letter + k;
                if (square && Math.floor(to / 2) !== Math.floor(letter / 2)) return;
            } else if (square && Math.abs(down) > 0.6) {
                var col = letter % 2 + k, row = Math.floor(letter / 2) + (down > 0 ? 1 : -1);
                if (col < 0 || col > 1 || row < 0 || row > 1) return;
                to = row * 2 + col;
            } else {
                return;
            }
            if (to < 0 || best && Math.abs(along) + Math.abs(down) >= best.d) return;
            best = {d: Math.abs(along) + Math.abs(down), target: parseInt(from, 10) + String.fromCharCode(65 + to)};
        });
        if (!best) return null;
        return sameTarget(lv).filter(function (a) { return a.target === best.target; })[0] || null;
    }

    /** Four cards per sheet, some of them one above the other: laid out two by two. */
    function twoByTwo(lv) {
        if (lv.expected !== 4) return false;
        return lv.grids.some(function (a) {
            return a.spot && lv.grids.some(function (b) {
                if (!b.spot || a === b) return false;
                var dx = b.spot.c.x - a.spot.c.x, dy = b.spot.c.y - a.spot.c.y;
                return Math.abs(-dx * a.spot.ax.y + dy * a.spot.ax.x) > 0.6 * a.spot.h;
            });
        });
    }

    /**
     * Grids identified by their place on the sheet (byPlace), unless their header was read
     * leaning to another archer. -> those grids
     */
    function byPlaces(lv) {
        var out = [];
        lv.grids.forEach(function (g) {
            if (g.ident) return;
            var a = byPlace(lv, g), guess = g.lastGuess;
            if (!a || guess && guess.archer.enId !== a.enId && guess.gap <= GUESS_GAP) return;
            g.ident = {status: 'place', archer: a, turn: null};
            g.identAt = lv.t;
            out.push(g);
        });
        return out;
    }

    /** Where a fitted grid is shown: put back on its lines when it was, else as fitted. */
    function shownPose(g) {
        return g.view || g.fit.Huv;
    }

    function sameLayout(g, st) {
        var p = g.fit && g.fit.printed;
        return !!p && p.ends === st.ends && p.arrows === st.arrows;
    }

    function grid(lv, id) {
        for (var i = 0; i < lv.grids.length; i++) if (lv.grids[i].id === id) return lv.grids[i];
        return null;
    }

    // --------------------------------------------------------------- frames

    /**
     * One frame of the video.
     *
     * @param {Object} small The frame, grey, at the following size.
     * @param {number} t Its time, in seconds.
     * @param {Function} full () -> the same frame, grey, as large as it comes (asked only
     *     when a task needs it).
     * @param {Object} fullSize {w, h} of the full frame.
     * @return {Object} {ok, H (map -> small frame), task (to give to the worker, or null)}
     */
    function frame(lv, small, t, full, fullSize) {
        lv.frames++;
        lv.t = t;
        lv.smallW = small.w;
        lv.smallH = small.h;
        lv.fullW = fullSize.w;
        lv.fullH = fullSize.h;
        lv.s = small.w / fullSize.w;
        var r = lv.env.fixed ? still(lv, small, t) : lv.env.F.frame(lv.follow, small);
        // How fast the sheet goes by: a frame taken moving is blurred.
        if (!lv.env.fixed && r.ok && lv.H && lv.lastT !== undefined && t > lv.lastT) {
            var a = apply(r.H, 0, 0), b = apply(lv.H, 0, 0), v = Math.hypot(a.x - b.x, a.y - b.y) / (t - lv.lastT);
            lv.speed = lv.speed === undefined ? v : 0.5 * lv.speed + 0.5 * v;
        }
        // How sharp a frame taken now is (1: still), for what is read and for the page to tell.
        lv.sharp = lv.env.fixed ? r.sharp : 1 / (1 + Math.pow((lv.speed || 0) / SHAKE, 2));
        if (!r.ok || lv.sharp < 0.5) lv.disturbedAt = t;
        lv.lastT = t;
        lv.H = r.ok ? r.H : null;
        lv.how = r.how;
        // Lost for a while: rather another sheet than the one followed, come back. The
        // view starts afresh (a frame showing nothing to follow waits for one that does).
        if (r.ok) lv.lostAt = undefined;
        else if (lv.lostAt === undefined) lv.lostAt = t;
        else if (t - lv.lostAt >= LOST_RESTART && !lv.env.fixed && lv.follow.keys.length) restart(lv, 'lost');
        // Started afresh (here, or on a header read another archer's): told once.
        var anew = lv.restarted || null;
        lv.restarted = null;
        var next = lv.busy ? null : choose(lv, full);
        return {ok: r.ok, H: lv.H, how: r.how, task: next, anew: anew};
    }

    /** A grey picture as 4 x 4 pixel means. */
    function tiny(img) {
        var w = Math.floor(img.w / 4), h = Math.floor(img.h / 4), out = new Float32Array(w * h);
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var s = 0;
                for (var j = 0; j < 4; j++) for (var i = 0; i < 4; i++) s += img.d[(y * 4 + j) * img.w + x * 4 + i];
                out[y * w + x] = s / 16;
            }
        }
        return out;
    }

    /**
     * Share of the pixels of two such pictures that differ (by more than STILL_DIFF, their
     * brightness and contrast evened out), among those of the mask when one is given (1 = compared).
     */
    function changed(a, b, mask) {
        if (!a || !b || a.length !== b.length) return 1;
        // The camera's own exposure changes the whole view's brightness and contrast (a
        // thing passing beside the sheets will make it): evened out first (b fitted to a,
        // a = gain * b + offset, least squares), only what changed in place counts.
        var k = 0, ma = 0, mb = 0, i;
        for (i = 0; i < a.length; i++) {
            if (mask && !mask[i]) continue;
            k++;
            ma += a[i];
            mb += b[i];
        }
        if (!k) return 0;
        ma /= k;
        mb /= k;
        var cov = 0, vb = 0;
        for (i = 0; i < a.length; i++) {
            if (mask && !mask[i]) continue;
            cov += (a[i] - ma) * (b[i] - mb);
            vb += (b[i] - mb) * (b[i] - mb);
        }
        var gain = vb > 0 ? cov / vb : 1, offset = ma - gain * mb, n = 0;
        for (i = 0; i < a.length; i++) {
            if (mask && !mask[i]) continue;
            if (Math.abs(a[i] - gain * b[i] - offset) > STILL_DIFF) n++;
        }
        return n / k;
    }

    /**
     * The pixels of such a picture (w x h, the small frame's 4 x 4 means) that the grids found
     * cover, each grown by STILL_MARGIN; null while none is found. Kept while they stay.
     */
    function gridsMask(lv, w, h) {
        var quads = lv.grids.filter(function (g) { return g.quad; }).map(function (g) { return g.quad; });
        if (!quads.length) return null;
        var key = w + 'x' + h + ':' + quads.map(function (q) {
            return q.map(function (p) { return Math.round(p.x) + ',' + Math.round(p.y); }).join(';');
        }).join('|');
        if (lv.maskKey === key) return lv.mask;
        // The map is the small frame with the lens undone; the pictures compared are not.
        var V = lv.env.V, lens = lv.lens, k = lv.s;
        var raw = quads.map(function (q) {
            var c = centre(q);
            return q.map(function (p) {
                var x = c.x + (p.x - c.x) * (1 + STILL_MARGIN), y = c.y + (p.y - c.y) * (1 + STILL_MARGIN);
                if (lens && V.lensBack) {
                    var r = V.lensBack(lens, x / k, y / k);
                    x = r.x * k;
                    y = r.y * k;
                }
                return {x: x / 4, y: y / 4};
            });
        });
        var mask = new Uint8Array(w * h), n = 0;
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var pt = {x: x + 0.5, y: y + 0.5};
                if (raw.some(function (q) { return inQuad(q, pt); })) {
                    mask[y * w + x] = 1;
                    n++;
                }
            }
        }
        lv.maskKey = key;
        lv.mask = n ? mask : null;
        return lv.mask;
    }

    /**
     * A frame of a fixed camera: the map is the frame itself (lens undone). Something
     * moving (a hand, a sheet handled): nothing is read. Still again: the view is
     * compared with the one before, and another view (a sheet changed, moved, taken away)
     * starts afresh. Once grids are found, only their pixels count.
     * -> as OcrFollow.frame, with sharp (1 still, 0 moving).
     */
    function still(lv, small, t) {
        // Grids found: only their pixels (a thing passing beside the sheets is no sheet handled).
        var now = tiny(small), mask = gridsMask(lv, Math.floor(small.w / 4), Math.floor(small.h / 4));
        var moving = lv.prevTiny ? changed(now, lv.prevTiny, mask) > STILL_MOVE : true;
        lv.prevTiny = now;
        if (moving) {
            lv.movedAt = t;
            lv.handled = true;
        }
        var settled = t - (lv.movedAt === undefined ? -99 : lv.movedAt) >= STILL_SETTLE;
        if (settled && lv.handled) {
            lv.handled = false;
            // Another view: another sheet, or the sheet moved (touched, the camera knocked):
            // read afresh either way.
            if (lv.ref && changed(now, lv.ref, mask) > STILL_CHANGE) restart(lv, 'changed');
            lv.ref = now;
        }
        return {ok: true, H: [1, 0, 0, 0, 1, 0, 0, 0, 1], n: 0, how: 'fixed', sharp: settled ? 1 : 0};
    }

    // -------------------------------------------------------------- results

    function inv(H) {
        var a = H[0], b = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7], i = H[8];
        var A = e * i - f * h, B = -(d * i - f * g), C = d * h - e * g, det = a * A + b * B + c * C;
        return [A / det, -(b * i - c * h) / det, (b * f - c * e) / det, B / det, (a * i - c * g) / det, -(a * f - c * d) / det,
            C / det, -(a * h - b * g) / det, (a * e - b * d) / det];
    }

    /** A task's result. -> what changed: {grids, idents: [grid], cards, reads} */
    function result(lv, res) {
        var info = lv.frameInfo[res.id] || {};
        delete lv.frameInfo[res.id];
        if (lv.busy && lv.busy.id === res.id) lv.busy = null;
        var out = {type: res.type};
        if (res.type === 'grids') {
            if (res.lensTried && lv.lens === undefined) {
                lv.lensTries++;
                if (res.lens) {
                    // Start again, straight: the grids just found were seen bowed.
                    setLens(lv, res.lens);
                    out.restart = true;
                    out.lens = res.lens;
                    return out;
                }
                // No bowing on a frame that shows the grids: the lens is straight.
                if (res.grids.length || lv.lensTries >= LENS_TRIES) {
                    lv.lens = null;
                    out.lens = null;
                }
            }
            if (!info.H) return out;
            // Full frame of then -> map.
            var back = mul(inv(info.H), scaling(info.s));
            // Grids found (two at least: one may be a part fitted wrong), none where the cards
            // identified are, twice in a row: the sheet was moved or changed under a camera
            // following the scene around it (or keeping to the print all sheets share).
            var known = lv.grids.filter(function (g) { return g.ident; });
            // No grid at all where the cards identified lie whole in the frame, a still
            // frame, twice in a row: the sheet was taken away (the next one comes).
            if (known.length && !res.grids.length && (info.sharp || 0) >= GONE_SHARP) {
                var Mt = mul(scaling(1 / info.s), info.H), shown = known.every(function (g) {
                    return inside(lv, g.quad.map(function (p) { return apply(Mt, p.x, p.y); }), 0);
                });
                lv.gone = shown ? (lv.gone || 0) + 1 : 0;
                if (lv.gone >= GONE) {
                    restart(lv, 'gone');
                    out.anew = 'gone';
                    return out;
                }
            } else if (res.grids.length) {
                lv.gone = 0;
            }
            if (known.length && res.grids.length >= Math.min(2, lv.expected || 2)) {
                var there = res.grids.some(function (rg) {
                    var q = rg.quad.map(function (p) { return apply(back, p.x, p.y); });
                    return !!sameGrid(known, spotOf({fit: rg.fit, back: back}), q);
                });
                lv.away = there ? 0 : (lv.away || 0) + 1;
                if (lv.away >= AWAY) {
                    restart(lv, 'moved');
                    out.anew = 'moved';
                    return out;
                }
            }
            if (info.verify) {
                out.grids = lv.grids.length;
                return out;
            }
            res.grids.forEach(function (rg) {
                var mapQuad = rg.quad.map(function (p) { return apply(back, p.x, p.y); });
                // The same grid: one holds the other's centre. The camera fixed and the
                // first sheet's tables measured: a table of another size is none (part of
                // one, two run together).
                var spot = spotOf({fit: rg.fit, back: back}), g;
                if (lv.env.fixed && lv.calib && spot && Math.abs(spot.w / lv.calib.w - 1) > CALIB_SIZE) return;
                g = sameGrid(lv.grids, spot, mapQuad);
                if (!g) {
                    if (!placeFor(lv, spot)) return;
                    g = {id: lv.nextId++, ident: null, card: null, fit: null, cells: [], sure: {}, seen: 0, area: 0};
                    lv.grids.push(g);
                }
                g.seen++;
                // Every fit found is kept; the grid takes the one most of the others agree
                // with (a fit off by a column or a row, on an outline short of the table,
                // stands alone). Changing it starts the grid's readings afresh.
                var cand = candOf(rg, back, mapQuad);
                g.fits = (g.fits || []).concat([cand]).slice(-FITS);
                // Fits facing the other way than the sheet's cards: none of them.
                var su = sheetUp(lv), same = su && g.fits.filter(function (f) {
                    var u = upMap(f);
                    return !u || u.x * su.x + u.y * su.y > 0;
                });
                if (same && same.length) g.fits = same;
                choosePick(g);
            });
            order(lv);
            // The camera fixed: the first sheet whose grids expected are all fitted gives the
            // tables' size, for the sheets after it (its calibration).
            if (lv.env.fixed && !lv.calib && lv.expected && lv.grids.length === lv.expected) {
                var ws = lv.grids.map(function (g) { return g.pick ? spotOf(g.pick) : null; }).filter(Boolean)
                    .map(function (sp) { return sp.w; }).sort(function (a, b) { return a - b; });
                if (ws.length === lv.expected) {
                    lv.calib = {w: ws[Math.floor(ws.length / 2)]};
                    out.calib = lv.calib;
                }
            }
            // Two grids over each other cannot be: the following, or a fit, has gone wrong.
            var over = lv.grids.some(function (g, i) {
                return lv.grids.some(function (o, j) { return j > i && g.quad && o.quad && overlapShare(g.quad, o.quad) >= OVERLAP; });
            });
            lv.overlaps = over ? (lv.overlaps || 0) + 1 : 0;
            if (lv.overlaps >= 2) {
                restart(lv, 'overlap');
                out.anew = 'overlap';
                return out;
            }
            out.grids = lv.grids.length;
            out.idents = byPlaces(lv);
            return out;
        }
        if (res.type === 'header') {
            out.idents = [];
            var other = res.cards.some(function (c) {
                var g = grid(lv, c.id);
                return g && g.ident && anotherSheet(lv, g, c, info.partial);
            });
            if (other) {
                restart(lv, 'header');
                out.anew = 'header';
                return out;
            }
            res.cards.forEach(function (c) {
                var g = grid(lv, c.id);
                if (!g || g.ident) return;
                if (c.status === 'none') g.headerMiss = (g.headerMiss || 0) + 1;
                g.headTries = (g.headTries || 0) + 1;
                g.lastGuess = c.guess || null;
                // The archer its place on the sheet gives, and the name read leaning that way:
                // that is enough (the few left on the target to tell apart).
                var placed = byPlace(lv, g);
                if (placed && c.guess && c.guess.archer.enId === placed.enId && c.guess.gap <= GUESS_GAP) {
                    c.archer = placed;
                    c.status = 'votes';
                }
                // Unsure, but the same archer best twice: that is enough.
                if ((!c.archer || c.status === 'unsure' || c.status === 'none') && c.guess && c.guess.gap <= GUESS_GAP) {
                    var id = c.guess.archer.enId;
                    g.votes = g.votes || {};
                    g.votes[id] = (g.votes[id] || 0) + 1;
                    if (g.votes[id] >= VOTES) {
                        c.archer = c.guess.archer;
                        c.status = 'votes';
                    }
                }
                if (!c.archer || c.status === 'unsure' || c.status === 'none') return;
                // An archer already given to another grid of the sheet: not twice.
                if (lv.grids.some(function (o) { return o !== g && o.ident && o.ident.archer.enId === c.archer.enId; })) return;
                g.ident = {status: c.status, archer: c.archer, turn: c.turn};
                g.identAt = lv.t;
                out.idents.push(g);
                // Of another target than the cards known: the sheet may have changed under
                // them (or an archer was moved), their headers read again at once.
                var num = parseInt(c.archer.target, 10);
                lv.grids.forEach(function (o) {
                    if (o !== g && o.ident && parseInt(o.ident.archer.target, 10) !== num) o.recheckAt = lv.t;
                });
            });
            out.idents = out.idents.concat(byPlaces(lv));
            return out;
        }
        if (res.type === 'fit') {
            var gf = grid(lv, info.grid);
            if (!gf) return out;
            gf.fitting = false;
            if (!res.ok) {
                // Another try with the next analysis of the grid.
                gf.fitFailed = (gf.fitFailed || 0) + 1;
                return out;
            }
            setFit(gf, res, gf.refBack);
            out.fitted = gf;
            return out;
        }
        if (res.type === 'refine') {
            out.refined = refined(lv, info, res.refined);
            // The lines of no card identified found, twice in a row on still frames: the
            // sheet may have been taken away; the grids are looked for at once (GONE).
            var asked = (info.refining || []).filter(function (id) { var g = grid(lv, id); return g && g.ident; });
            if (asked.length && (info.sharp || 0) >= GONE_SHARP) {
                var got = (res.refined || []).some(function (r) { return asked.indexOf(r.card) >= 0; });
                lv.noLines = got ? 0 : (lv.noLines || 0) + 1;
                if (lv.noLines >= 2) {
                    lv.noLines = 0;
                    lv.verifyPlace = true;
                }
            }
            return out;
        }
        if (res.type === 'cells') {
            out.reads = 0;
            res.reads.forEach(function (r) {
                var g = grid(lv, r.card);
                if (!g || !g.fit) return;
                var x = g.cells.find(function (c) { return c.key === r.key; });
                if (!x) return;
                if (g.sure[x.key] && g.locked[x.key]) {
                    spotChecked(lv, g, x, r.reading);
                    return;
                }
                var q = x.pendingQ || 1;
                // Read surely otherwise than it was surely: a few such on a grid in a while,
                // and another sheet is likely in its place (its header is read again at once,
                // the grids looked for again).
                var was = x.reading && x.reading[0], top = r.reading && r.reading[0], field = x.cell.kind === 'arrow' ? 'label' : 'value';
                if (was && top && was.p >= CONFLICT_P && top.p >= CONFLICT_P && was[field] !== top[field]) {
                    g.conflicts = lv.t - (g.conflictAt || -99) <= CONFLICT_SPAN ? (g.conflicts || 0) + 1 : 1;
                    g.conflictAt = lv.t;
                    if (g.conflicts >= CONFLICTS && g.ident) {
                        g.conflicts = 0;
                        g.recheckAt = lv.t;
                        lv.verifyPlace = true;
                    }
                }
                // The camera fixed: a reading surely other than the one kept is of another view
                // (a sheet moved, or another): it replaces the old ones rather than mixing.
                if (lv.env.fixed && was && top && was.p >= CONFLICT_P && top.p >= CONFLICT_P && was[field] !== top[field]) x.views = [];
                x.views.push({q: q, reading: r.reading});
                if (x.views.length > VIEWS) {
                    x.views.sort(function (a, b) { return b.q - a.q; });
                    x.views.length = VIEWS;
                }
                x.best = Math.max(x.best, q);
                x.lastTask = res.id;
                x.reading = fuse(x.views, x.cell.kind === 'arrow' ? 'label' : 'value', x.cell.kind === 'arrow' ? 0 : 6);
                g.reads[x.key] = x.reading;
                g.dirty = true;
                g.fresh = (g.fresh || 0) + 1;
                g.shot = shotOf(lv, g);
                g.blank = g.shot.ends === 0;
                // Every cell read (the camera fixed: in this view).
                g.complete = g.cells.every(function (c) { return c.reading && (!lv.env.fixed || c.epoch === lv.epoch) || skipped(g, c); });
                out.reads++;
            });
            return out;
        }
        if (res.type === 'solve') {
            var gs = grid(lv, res.card);
            if (!gs) return out;
            gs.sol = res.sol;
            // Written numbers that do not add up with the arrows, all cells read: the grid
            // is likely fitted a row or a column off. The rows one line over first, when its
            // crossed cells tell so; then its next fit.
            if (res.sol.agreement.low && gs.complete && gs.altFit && !gs.altFrom) {
                gs.altFrom = gs.fit;
                setFit(gs, gs.altFit, gs.pick ? gs.pick.back : gs.refBack);
                out.refitted = gs;
                return out;
            }
            if (res.sol.agreement.low && gs.complete) {
                gs.bad = (gs.bad || []).concat([gs.pick.anchors]);
                var was = gs.pick;
                choosePick(gs);
                if (gs.pick !== was) {
                    out.refitted = gs;
                    return out;
                }
            }
            markSure(lv, gs);
            out.solved = gs;
            return out;
        }
        return out;
    }

    /** The way from a fit's bottom to its header, on the map (a unit vector); null if unknown. */
    function upMap(cand) {
        var f = cand && cand.fit && cand.turns.find(function (t) { return t.turn === cand.fit.turn; });
        if (!f) return null;
        var p = apply(f.Huv, f.W / 2, 0), q = apply(f.Huv, f.W / 2, f.Hb), d = Math.hypot(p.x - q.x, p.y - q.y);
        return d ? {x: (p.x - q.x) / d, y: (p.y - q.y) / d} : null;
    }

    /**
     * The way the sheet's cards face, on the map (all face one way: header up, total row
     * down), from the grids known, an identified one weighing more; null while unknown or
     * at odds.
     */
    function sheetUp(lv) {
        var sx = 0, sy = 0, all = 0;
        lv.grids.forEach(function (g) {
            var u = upMap(g.pick), k = g.ident ? 3 : 1;
            if (!u) return;
            sx += k * u.x;
            sy += k * u.y;
            all += k;
        });
        var d = Math.hypot(sx, sy);
        return all && d >= 0.5 * all ? {x: sx / d, y: sy / d} : null;
    }

    /** sheetUp() in the frame of now, for the grids looked for in it. */
    function frameUp(lv) {
        var up = sheetUp(lv), g = lv.grids.find(function (o) { return o.quad; });
        if (!up || !g || !lv.H) return null;
        var c = centre(g.quad), p = apply(lv.H, c.x, c.y), q = apply(lv.H, c.x + 50 * up.x, c.y + 50 * up.y), d = Math.hypot(q.x - p.x, q.y - p.y);
        return d ? {x: (q.x - p.x) / d, y: (q.y - p.y) / d} : null;
    }

    /** A detection as a fit of a grid, on the map. */
    function candOf(rg, back, mapQuad) {
        var cand = {fit: rg.fit, back: back, quad: mapQuad, c: centre(mapQuad), area: quadArea(mapQuad), ref: rg.ref, layout: rg.layout,
            turns: rg.turns.map(function (f) { return {turn: f.turn, W: f.W, Hb: f.Hb, rowH: f.rowH, Huv: mul(back, f.Hfull)}; })};
        cand.anchors = anchors(cand);
        return cand;
    }

    /**
     * Room for a grid found where no grid is known yet. The cards per sheet given, the sheet
     * holds no more, laid out as printed: side by side (two to four), or two by two (four).
     * A grid out of line with the others is no card of the sheet; full, a new grid in line
     * takes the place of one that is not (an identified one stays).
     */
    function placeFor(lv, spot) {
        var n = lv.expected;
        if (!n || !spot) return true;
        var without = function (but) { return lv.grids.filter(function (o) { return o !== but && o.spot; }); };
        var stray = lv.grids.filter(function (o) { return !o.ident && o.spot && !inLine(n, without(o), o.spot); })[0];
        if (lv.grids.length < n) return inLine(n, without(stray), spot);
        if (!stray || !inLine(n, without(stray), spot)) return false;
        lv.grids.splice(lv.grids.indexOf(stray), 1);
        return true;
    }

    /**
     * Is a grid where the sheet's layout puts a card, against the others? Measured along
     * the table's own width (the sheet may be held sideways): a card beside another is
     * level with it and a table's width or so away.
     */
    function inLine(n, list, spot) {
        return list.every(function (o) {
            var b = o.spot, ratio = spot.area / Math.max(1, b.area);
            if (ratio < 0.5 || ratio > 2) return false;
            var dx = b.c.x - spot.c.x, dy = b.c.y - spot.c.y;
            var along = Math.abs(dx * spot.ax.x + dy * spot.ax.y), across = Math.abs(-dx * spot.ax.y + dy * spot.ax.x);
            var w = Math.min(spot.w, b.w), h = Math.max(spot.h, b.h);
            var beside = along > 0.6 * w && across < 0.5 * h, below = along < 0.5 * Math.max(spot.w, b.w) && across > 0.6 * Math.min(spot.h, b.h);
            var corner = along > 0.6 * w && across > 0.6 * Math.min(spot.h, b.h);
            return beside || (n === 4 && (below || corner));
        });
    }

    /** Where a fit puts the table on the map: centre, area, the way of its width, its size. */
    function spotOf(cand) {
        var tb = cand.fit.table, H = mul(cand.back, cand.fit.Hfull);
        if (!tb) return null;
        var v1 = Math.max.apply(null, cand.fit.cells.map(function (c) { return c.v1; }));
        var a = apply(H, tb.u0, tb.v0), b = apply(H, tb.u1, tb.v0), d = apply(H, tb.u0, v1), e = apply(H, tb.u1, v1);
        var w = Math.hypot(b.x - a.x, b.y - a.y), q = [a, b, e, d];
        return {c: centre(q), area: quadArea(q), ax: {x: (b.x - a.x) / w, y: (b.y - a.y) / w}, w: w, h: Math.hypot(d.x - a.x, d.y - a.y)};
    }

    /**
     * The grid a detection is, among these: the one whose table its fit puts nearly in the
     * same place (centres within half a table's width, sizes alike). Not by the outlines:
     * one found around two tables run together holds the centre of either, and would mix
     * their fits. Without a table on either side, by the outlines.
     */
    function sameGrid(list, spot, quad) {
        var best = null, bd = Infinity;
        list.forEach(function (o) {
            if (spot && o.spot) {
                var d = Math.hypot(o.spot.c.x - spot.c.x, o.spot.c.y - spot.c.y), ratio = spot.area / Math.max(1, o.spot.area);
                if (d < SAME_GRID * Math.min(o.spot.w, spot.w) && ratio > 0.5 && ratio < 2 && d < bd) {
                    best = o;
                    bd = d;
                }
            } else if (!best && (inQuad(o.quad, centre(quad)) || inQuad(quad, o.c))) {
                best = o;
            }
        });
        return best;
    }

    function quadArea(q) {
        var a = 0;
        for (var i = 0; i < 4; i++) a += q[i].x * q[(i + 1) % 4].y - q[(i + 1) % 4].x * q[i].y;
        return Math.abs(a) / 2;
    }

    /** The area two quads share (convex: one clipped by the other), over the smaller one's. */
    function overlapShare(a, b) {
        var ccw = function (q) {
            var s = 0;
            for (var i = 0; i < q.length; i++) s += q[i].x * q[(i + 1) % q.length].y - q[(i + 1) % q.length].x * q[i].y;
            return s < 0 ? q.slice().reverse() : q;
        };
        var clip = ccw(b), out = a.slice();
        for (var i = 0; i < clip.length && out.length; i++) {
            var p = clip[i], q = clip[(i + 1) % clip.length], inp = out;
            var side = function (r) { return (q.x - p.x) * (r.y - p.y) - (q.y - p.y) * (r.x - p.x) >= 0; };
            var cut = function (r, t) {
                var d = (r.x - t.x) * (p.y - q.y) - (r.y - t.y) * (p.x - q.x);
                if (!d) return r;
                var k = ((r.x - p.x) * (p.y - q.y) - (r.y - p.y) * (p.x - q.x)) / d;
                return {x: r.x + k * (t.x - r.x), y: r.y + k * (t.y - r.y)};
            };
            out = [];
            for (var j = 0; j < inp.length; j++) {
                var r = inp[j], t = inp[(j + 1) % inp.length];
                if (side(t)) {
                    if (!side(r)) out.push(cut(r, t));
                    out.push(t);
                } else if (side(r)) {
                    out.push(cut(r, t));
                }
            }
        }
        var area = 0;
        for (var k = 0; k < out.length; k++) area += out[k].x * out[(k + 1) % out.length].y - out[(k + 1) % out.length].x * out[k].y;
        return Math.abs(area) / 2 / Math.max(1, Math.min(quadArea(a), quadArea(b)));
    }

    function inQuad(q, p) {
        var sign = 0;
        for (var i = 0; i < 4; i++) {
            var a = q[i], b = q[(i + 1) % 4], cr = (b.x - a.x) * (p.y - a.y) - (b.y - a.y) * (p.x - a.x);
            if (cr !== 0) {
                if (sign && (cr > 0) !== (sign > 0)) return false;
                sign = cr;
            }
        }
        return true;
    }

    /**
     * Where a fit puts the grid, on the map: the middles of its first and last cells, and
     * the height of a cell (the scale of the agreement).
     */
    function anchors(cand) {
        var cells = cand.fit.cells, H = mul(cand.back, cand.fit.Hfull), first = cells[0], last = cells[cells.length - 1];
        var mid = function (c) { return apply(H, (c.u0 + c.u1) / 2, (c.v0 + c.v1) / 2); };
        var a = apply(H, first.u0, first.v0), b = apply(H, first.u0, first.v1);
        return {p: mid(first), q: mid(last), cell: Math.hypot(a.x - b.x, a.y - b.y)};
    }

    function near(a, b) {
        var tol = 0.5 * a.cell;
        return Math.hypot(a.p.x - b.p.x, a.p.y - b.p.y) < tol && Math.hypot(a.q.x - b.q.x, a.q.y - b.q.y) < tol;
    }

    /**
     * The grid takes the fit most of its others agree with, leaving out those whose
     * readings did not add up. Changing it starts the grid's readings afresh.
     */
    function choosePick(g) {
        var fits = g.fits.filter(function (f) { return !(g.bad || []).some(function (b) { return near(f.anchors, b); }); });
        if (!fits.length) return;
        var pick = consensus(fits);
        if (g.pick && near(pick.anchors, g.pick.anchors)) {
            g.pick.agree = pick.agree;
            return;
        }
        // Changing the fit throws away what was read: only for one clearly better agreed,
        // or when the one kept was found wrong.
        var keep = g.pick && !(g.bad || []).some(function (b) { return near(g.pick.anchors, b); });
        if (keep) {
            var now = fits.filter(function (f) { return near(f.anchors, g.pick.anchors); }).length;
            if (pick.agree < now + SWITCH) return;
        }
        g.pick = pick;
        g.spot = spotOf(pick);
        g.quad = pick.quad;
        g.c = pick.c;
        g.area = pick.area;
        g.turns = pick.turns;
        g.ref = pick.ref;
        g.refBack = pick.back;
        g.layout = pick.layout;
        g.tried = 0;
        g.altFrom = null;
        setFit(g, pick.fit, pick.back);
    }

    /** The fit the most others agree with (their first and last cells within half a cell). */
    function consensus(fits) {
        var best = null, bn = -1;
        fits.forEach(function (f) {
            var n = 0, tol = 0.5 * f.anchors.cell;
            fits.forEach(function (o) {
                if (Math.hypot(o.anchors.p.x - f.anchors.p.x, o.anchors.p.y - f.anchors.p.y) < tol
                    && Math.hypot(o.anchors.q.x - f.anchors.q.x, o.anchors.q.y - f.anchors.q.y) < tol) n++;
            });
            if (n > bn) {
                bn = n;
                best = f;
            }
        });
        best.agree = bn;
        return best;
    }

    /** A grid's layout matched: its cells, carried to the map (readings start afresh). */
    function setFit(g, fit, back) {
        g.fit = {Huv: mul(back, fit.Hfull), printed: fit.printed, turn: fit.turn, lines: fit.lines || null};
        // The rows one line over, when the crossed cells tell so (OcrLayout.fit): tried once
        // if the reading as fitted is in doubt.
        g.altFit = fit.alt || null;
        g.altFit2 = fit.alt2 || null;
        g.altBack = back;
        g.marks = fit.marks || [];
        g.grey = fit.grey === undefined ? null : fit.grey;
        g.refinedAt = -9;
        g.view = null;
        // The header's frame: the table's top left as origin, its width as W.
        var tb = fit.table;
        g.head = tb ? {turn: fit.turn, W: tb.u1 - tb.u0, Huv: mul(g.fit.Huv, [1, 0, tb.u0, 0, 1, tb.v0, 0, 0, 1])} : null;
        g.headTable = tb || null;
        g.headerMiss = 0;
        g.cells = fit.cells.map(function (c) {
            return {cell: c, key: c.line + '.' + c.key, views: [], best: 0, reading: null};
        });
        g.arrowCells = {};
        g.cells.forEach(function (x) { if (x.cell.kind === 'arrow') g.arrowCells[x.cell.arrow] = x; });
        g.reads = {};
        g.complete = false;
        g.sure = {};
        g.pending = {};
        unlock(g);
        g.shot = {ends: null, probes: []};
        g.sol = null;
    }

    /**
     * How many ends of a grid were shot, from a few of its arrow cells (OcrLayout.shotEnds:
     * the first two arrows of an end empty, checked on the next end's and on the card's
     * last two). An arrow is empty or written once its reading says so surely.
     */
    function shotOf(lv, g) {
        var Lay = lv.env.Lay;
        if (!Lay || !Lay.shotEnds) return {ends: g.fit.printed.ends, probes: []};
        return Lay.shotEnds(g.fit.printed, function (i) {
            var x = g.arrowCells[i];
            if (!x || !x.reading) return null;
            var empty = x.reading.filter(function (o) { return o.label === ''; })[0], pe = empty ? empty.p : 0;
            return pe >= BLANK_P ? 'empty' : pe <= 0.2 ? 'written' : null;
        });
    }

    /**
     * A cell not to read: on an end not shot (or, nothing shot, in the total row), unless it
     * is one of the arrows that tell (read again on better views, those only).
     */
    function skipped(g, x) {
        var ends = g.shot.ends, c = x.cell, p = g.fit.printed;
        if (ends === null || ends >= p.ends) return false;
        if (c.kind === 'arrow' && g.shot.probes.indexOf(c.arrow) >= 0) return false;
        if (c.line === 'total') return ends === 0;
        return Math.floor((c.line - 1) * p.perLine / p.arrows) >= ends;
    }

    function centre(q) {
        return {x: (q[0].x + q[1].x + q[2].x + q[3].x) / 4, y: (q[0].y + q[1].y + q[2].y + q[3].y) / 4};
    }

    /** The grids as laid out on the sheet: left to right, or top to bottom when stacked. */
    function order(lv) {
        lv.grids.sort(function (a, b) { return Math.abs(a.c.x - b.c.x) > Math.abs(a.c.y - b.c.y) ? a.c.x - b.c.x : a.c.y - b.c.y; });
    }

    /** The card of a grid identified (from the server): its structure, values, ianseo's arrows. */
    /**
     * Cells shown wrong for sure: an arrow read for sure that ianseo holds otherwise (an
     * entry mistake), and a running total or total the archer wrote that the arrows, all
     * read for sure up to it, do not make (an addition mistake on the sheet), its number
     * read surely. Nothing while the card as a whole is in doubt.
     */
    function wrongCells(g) {
        var out = [], held = g.card && g.card.ianseo ? g.card.ianseo.arrows || [] : [];
        if (g.sol && g.sol.agreement && g.sol.agreement.low) return out;
        g.cells.forEach(function (x) {
            var c = x.cell, mine = g.locked && g.locked[x.key];
            if (c.kind !== 'arrow' || !g.sure[x.key] || !mine) return;
            var h = (held[c.arrow] || '').replace('*', '');
            if (h !== '' && h !== mine.label) out.push(x);
        });
        var sheet = g.sol && g.sol.sheet ? g.sol.sheet : {}, p = g.fit.printed;
        Object.keys(sheet).forEach(function (k) {
            var x = g.cells.find(function (o) { return o.key === k; }), top = x && x.reading && x.reading[0];
            if (!top || top.value !== sheet[k].read || top.p < CONFLICT_P) return;
            var upTo = x.cell.line === 'total' ? Infinity : x.cell.line * p.perLine;
            var all = g.cells.every(function (o) {
                return o.cell.kind !== 'arrow' || o.cell.arrow >= upTo || g.sure[o.key] || skipped(g, o);
            });
            if (all) out.push(x);
        });
        return out;
    }

    function setCard(lv, id, card) {
        var g = grid(lv, id);
        if (g) g.card = card;
    }

    /** Readings of a cell from its views: a weighted geometric mean, the best view weighing most. */
    function fuse(views, field, keep) {
        var sorted = views.slice().sort(function (a, b) { return b.q - a.q; }), best = sorted[0].q, acc = {};
        sorted.forEach(function (v) { v.reading.forEach(function (o) { acc[o[field]] = 0; }); });
        sorted.forEach(function (v, k) {
            var w = (v.q / best) / (k + 1), by = {};
            v.reading.forEach(function (o) { by[o[field]] = o.p; });
            Object.keys(acc).forEach(function (l) { acc[l] += w * Math.log(Math.max(EPS, by[l] || 0)); });
        });
        var top = -Infinity, total = 0, out = [];
        Object.keys(acc).forEach(function (l) { top = Math.max(top, acc[l]); });
        Object.keys(acc).forEach(function (l) {
            var o = {p: Math.exp(acc[l] - top)};
            o[field] = field === 'value' ? +l : l;
            total += o.p;
            out.push(o);
        });
        out.forEach(function (o) { o.p /= total; });
        out.sort(function (a, b) { return b.p - a.p; });
        return keep ? out.slice(0, keep) : out;
    }

    /** What the checks need of a card: its printed lines' readings. */
    function solveData(g) {
        var p = g.fit.printed, r = g.reads, lines = [], values = {};
        g.card.values.forEach(function (v) { values[v.p] = {n: v.n, g: v.g, x: v.x}; });
        // An arrow not read yet: any value of the face, none more likely.
        var unknown = g.card.values.map(function (v) { return {label: v.p, p: 1 / (g.card.values.length + 1)}; })
            .concat([{label: '', p: 1 / (g.card.values.length + 1)}]);
        // A value read for sure stands as it is; the ends not shot are empty.
        var get = function (key) {
            var lk = g.locked[key];
            if (lk) return [lk.label !== undefined ? {label: lk.label, p: 1} : {value: lk.value, p: 1}];
            return r[key] || null;
        };
        var shot = g.shot.ends === null ? p.ends : g.shot.ends, none = [{label: '', p: 1}];
        for (var i = 1; i <= p.lines; i++) {
            var arrows = [], off = Math.floor((i - 1) * p.perLine / p.arrows) >= shot;
            for (var j = 0; j < p.perLine; j++) arrows.push(off ? none : get(i + '.a' + j) || unknown);
            lines.push(off ? {arrows: arrows, line: null, end: null, cum: null, gold: null, xnine: null}
                : {arrows: arrows, line: get(i + '.line'), end: get(i + '.end'), cum: get(i + '.cum'), gold: get(i + '.gold'),
                    xnine: get(i + '.xnine')});
        }
        return {card: g.id, p: p, lines: lines, total: {cum: get('total.cum'), gold: get('total.gold'),
            xnine: get('total.xnine')}, values: values, golds: g.card.structure.golds};
    }

    /**
     * What the checks make sure: arrows confident enough, numbers the arrows confirm. Sure
     * on two checks in a row with the same value, a cell stays so, its value kept (g.locked:
     * a later view, taken moving, does not take it back; it is not read again). The card as
     * a whole in doubt (its numbers agree too little): its certainties were none, all go.
     */
    function markSure(lv, g) {
        var sol = g.sol, p = g.fit.printed, SURE = lv.env.sure || 0.998;
        if (sol.agreement.low) {
            g.sure = {};
            g.pending = {};
            unlock(g);
            return;
        }
        // Nothing is sure until enough written numbers agree with the arrows (a card barely
        // read would be sure of arrows never seen); an arrow, only once its own cell and
        // its line's sum are read.
        var trusted = sol.agreement.n >= TRUST_NUMBERS, now = {};
        if (trusted) {
            sol.arrows.forEach(function (a) {
                var k = a.line + '.a' + a.index;
                if (a.conf >= SURE && g.reads[k] && g.reads[a.line + '.line']) now[k] = {label: a.value};
            });
            g.cells.forEach(function (x) {
                var c = x.cell, r = x.reading;
                if (c.kind === 'arrow' || !r || !r[0]) return;
                var want = expected(sol, p, c.line, c.key);
                var v = r[0].value === -1 && (c.key === 'gold' || c.key === 'xnine') ? 0 : r[0].value;
                if (want !== null && v === want && r[0].p >= 0.9) now[x.key] = {value: r[0].value};
            });
        }
        var sure = Object.assign({}, g.sure), before = g.pending || {};
        Object.keys(now).forEach(function (k) {
            var a = before[k], b = now[k];
            // The camera fixed, the card read whole: the next check would see the same.
            var once = lv.env.fixed && g.complete;
            if (sure[k] || !once && (!a || a.label !== b.label || a.value !== b.value)) return;
            sure[k] = true;
            g.locked[k] = b;
        });
        g.pending = now;
        g.sure = sure;
    }

    /**
     * A cell read for sure, looked at again: read surely otherwise, the sheet's writing may
     * not be the same. Two such lately: the header read again at once (it tells; a cell read
     * from a poor view does read otherwise now and then, on the same sheet too).
     */
    function spotChecked(lv, g, x, reading) {
        var top = reading && reading[0], kept = g.locked[x.key];
        g.spots = (g.spots || []).filter(function (o) { return lv.t - o.t <= SPOT_SPAN; });
        if (!top || top.p < CONFLICT_P) return;
        g.spots.push({t: lv.t, differs: top.label !== kept.label});
        if (g.spots.filter(function (o) { return o.differs; }).length >= 2) {
            g.spots = [];
            g.recheckAt = lv.t;
        }
    }

    /** No value kept any more (in place: the cards opened from the live view hold them). */
    function unlock(g) {
        if (!g.locked) g.locked = {};
        Object.keys(g.locked).forEach(function (k) { delete g.locked[k]; });
    }

    function expected(sol, p, line, key) {
        if (line === 'total') return key === 'cum' ? sol.total.cum : key === 'gold' ? sol.total.gold : key === 'xnine' ? sol.total.xnine : null;
        var perEnd = Math.max(1, Math.round(p.arrows / p.perLine)), e = Math.floor((line - 1) / perEnd), li = (line - 1) % perEnd;
        var en = sol.ends[e];
        if (!en) return null;
        if (key === 'line') return en.lines[li].sum;
        if (key === 'end') return en.sum;
        if (key === 'cum') return en.cum;
        if (key === 'gold') return en.lines[li].g;
        if (key === 'xnine') return en.lines[li].x;
        return null;
    }

    /**
     * What to draw over this frame, in full-frame pixels (lens undone: the page puts the
     * lens back): each grid's outline, its archer, its cells read for sure, those read but
     * in doubt, those (or the header) the worker reads now; and, fitted, its card and its
     * layout as matched here (fit: {printed, cells, Hfull}).
     */
    function drawing(lv) {
        var M = mapToFull(lv);
        if (!M) return [];
        var busy = lv.busy || {}, now = {};
        (busy.items || []).forEach(function (it) { now[it.card + '|' + it.key] = true; });
        var heads = {};
        (busy.cards || []).forEach(function (c) { heads[c.id] = true; });
        return lv.grids.map(function (g) {
            var out = {id: g.id, outline: g.quad.map(function (p) { return apply(M, p.x, p.y); }), ident: g.ident, sure: [], doubt: [],
                reading: [], card: g.card || null};
            if (g.fit) {
                var Hc = mul(M, shownPose(g));
                var quad = function (c) { return [[c.u0, c.v0], [c.u1, c.v0], [c.u1, c.v1], [c.u0, c.v1]].map(function (p) { return apply(Hc, p[0], p[1]); }); };
                out.fit = {printed: g.fit.printed, cells: g.cells.map(function (x) { return x.cell; }), Hfull: Hc};
                // The rows one line over, each way, for a picture read from these fits (not once
                // the live view has taken one).
                out.alts = g.altFrom ? [] : [g.altFit, g.altFit2].filter(Boolean).map(function (a) {
                    return {printed: a.printed, cells: a.cells, Hfull: mul(M, mul(g.altBack, a.Hfull))};
                });
                out.empty = [];
                out.marks = (g.marks || []).map(quad);
                out.grey = g.grey;
                g.cells.forEach(function (x) {
                    if (now[g.id + '|' + x.key]) out.reading.push(quad(x.cell));
                    else if (skipped(g, x)) out.empty.push(quad(x.cell));
                    else if (x.reading && !g.sure[x.key]) out.doubt.push(quad(x.cell));
                });
                if (heads[g.id] && g.head) {
                    var Hh = mul(M, g.head.Huv), w = g.head.W;
                    out.header = [[0, -HEADER_WHOLE * w], [w, -HEADER_WHOLE * w], [w, 0], [0, 0]].map(function (p) { return apply(Hh, p[0], p[1]); });
                }
                // The table as fitted (the outline found may stop short of it).
                var cs = g.cells.map(function (x) { return x.cell; }).filter(function (c) { return c.line !== 'total'; });
                if (cs.length && g.head) {
                    var tb = g.headTable, v1 = Math.max.apply(null, cs.map(function (c) { return c.v1; }));
                    out.outline = [[tb.u0, tb.v0], [tb.u1, tb.v0], [tb.u1, v1], [tb.u0, v1]].map(function (p) { return apply(Hc, p[0], p[1]); });
                    // Its first corner is the table's top left as printed (the header's side).
                    out.headed = true;
                }
                g.cells.forEach(function (x) {
                    if (!g.sure[x.key]) return;
                    var c = x.cell;
                    out.sure.push([[c.u0, c.v0], [c.u1, c.v0], [c.u1, c.v1], [c.u0, c.v1]].map(function (p) { return apply(Hc, p[0], p[1]); }));
                });
                var wrongs = wrongCells(g);
                out.wrong = wrongs.map(function (x) { return quad(x.cell); });
                out.wrongKeys = {};
                wrongs.forEach(function (x) { out.wrongKeys[x.key] = true; });
                // The totals the archer signs, left blank on a card shot: told (they stay blank).
                out.blankTotals = g.shot.ends === 0 ? [] : g.cells.filter(function (x) {
                    var top = x.reading && x.reading[0];
                    return x.cell.line === 'total' && top && top.value === -1 && top.p >= CONFLICT_P;
                }).map(function (x) { return quad(x.cell); });
                // ...and those read otherwise than the arrows give, not told wrong for sure:
                // to check (a total misread, or wrong on the sheet), never taken silently.
                var wrongKeys = out.wrongKeys || {};
                out.checkTotals = !g.sol || g.sol.agreement.low || g.shot.ends === 0 ? [] : g.cells.filter(function (x) {
                    var top = x.reading && x.reading[0], want = g.sol.total[x.cell.key];
                    return x.cell.line === 'total' && top && top.value !== -1 && want !== undefined && top.value !== want && !wrongKeys[x.key];
                }).map(function (x) { return quad(x.cell); });
                out.cells = g.cells.length;
            }
            return out;
        });
    }

    /** The tables' width on the map, the middle one of the grids fitted (a calibration's); 0 without. */
    function tablesWidth(lv) {
        var ws = lv.grids.map(function (g) { return g.pick ? spotOf(g.pick) : null; }).filter(Boolean)
            .map(function (sp) { return sp.w; }).sort(function (a, b) { return a - b; });
        return ws.length ? ws[Math.floor(ws.length / 2)] : 0;
    }

    return {create: create, frame: frame, result: result, setCard: setCard, drawing: drawing, fuse: fuse, setLens: setLens,
        // Another sheet comes (the picture of this one taken): the view starts afresh.
        anew: function (lv) { restart(lv, 'next'); },
        tablesWidth: tablesWidth, sizes: {header: HEADER_PX, cell: CELL_PX}};
});
