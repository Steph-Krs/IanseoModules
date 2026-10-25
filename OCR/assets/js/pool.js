/**
 * Workers that read a photo side by side (read-worker.js), the page left free meanwhile:
 * the work asked is cut into lots, each lot given to the next worker free. A worker reads as
 * the page does, with the same networks and the same analysis: the results are the page's.
 *
 *   var pool = OcrPool.create({url, models: {arrow, digit, number, text}, size});
 *   OcrPool.analyse(pool, img, opts).then(function (a) { … });               // as OcrVision.analyse
 *   OcrPool.header(pool, img, frame).then(function (read) { … });           // a card's header
 *   OcrPool.qr(pool, rgba, w, h).then(function (code) { … });               // the sheet's ISK code
 *   OcrPool.read(pool, img, Hf, items, opts).then(function (reads) { … });  // in items' order
 *
 * The photo is sent to every worker once (a copy each: no memory shared without the server's
 * isolation headers), so that fewer of them take a large one. A pool whose workers cannot
 * start or load their networks says so (its promises reject): the page then works on its own.
 * Without models, the workers only analyse (a pool of the live view's worker). cfg.onReady(i):
 * told when worker i is up.
 */
(function (root) {
    'use strict';

    var LOTS_PER_WORKER = 3;          // lots a read is cut into, per worker: the last ones even out
    var COPIES_MAX = 64 * 1024 * 1024; // bytes of the photo's copies at most, all workers together
    var ANALYSES_MAX = 2;              // whole pictures analysed at once at most (each takes much memory)
    var SHARES_MAX = 4;                // workers an analysis is shared among at most (each works the picture out)

    // Lots taken first: an analysis's and a header's (all else waits on them), then cells, then
    // the pictures counted for the lens searched for.
    var RANK = {plan: 0, job: 0, header: 0, lheader: 0, barcode: 1, qr: 1, cells: 1, analyse: 2};
    var NETS = {header: true, lheader: true, cells: true};   // lots that need the workers' networks

    function create(cfg) {
        var pool = {workers: [], lots: [], next: 1, imageId: 0, img: null, broken: false, holders: {}, onReady: cfg.onReady || null};
        var n = Math.max(1, cfg.size || 1);
        for (var i = 0; i < n; i++) {
            var w;
            try {
                w = new Worker(cfg.url);
            } catch (e) {
                pool.broken = true;
                break;
            }
            var slot = {w: w, busy: null, ready: false, img: 0};
            w.onmessage = message(pool, slot);
            w.onerror = fail(pool, slot);
            w.postMessage({init: {models: cfg.models || null}});
            pool.workers.push(slot);
        }
        if (!pool.workers.length) pool.broken = true;
        return pool;
    }

    function message(pool, slot) {
        return function (e) {
            var m = e.data;
            if (m.ready) {
                // Cells and headers may have waited for a worker with its networks.
                slot.ready = true;
                if (pool.onReady) pool.onReady(pool.workers.indexOf(slot));
                dispatch(pool);
                return;
            }
            if (m.error && m.id === undefined) return fail(pool, slot)();
            var lot = slot.busy;
            slot.busy = null;
            if (lot && lot.id === m.id) {
                if (m.error) lot.reject(new Error(m.error));
                else lot.resolve(lot.kind === 'header' ? {read: m.read, who: m.who} : lot.kind === 'cells' ? m.reads
                    : lot.kind === 'job' ? m.a : lot.kind === 'qr' || lot.kind === 'barcode' ? m.code : lot.kind === 'lheader' ? m.card : m);
            }
            dispatch(pool);
        };
    }

    /** A worker lost (it could not start, or its networks not load): its lot and the others wait on the rest. */
    function fail(pool, slot) {
        return function () {
            slot.dead = true;
            var lot = slot.busy;
            slot.busy = null;
            if (!pool.workers.some(function (s) { return !s.dead; })) {
                pool.broken = true;
                if (lot) lot.reject(new Error('workers lost'));
                pool.lots.splice(0).forEach(function (l) { l.reject(new Error('workers lost')); });
                return;
            }
            if (lot) pool.lots.unshift(lot);
            dispatch(pool);
        };
    }

    /** The workers that take a photo: all, but fewer for a large one (each gets a copy). */
    function users(pool, img) {
        var alive = pool.workers.filter(function (s) { return !s.dead; });
        return alive.slice(0, Math.max(1, Math.min(alive.length, Math.floor(COPIES_MAX / Math.max(1, img.w * img.h)))));
    }

    /**
     * The next lot to give out and the worker for it, among the free ones: the first lot of the
     * highest rank that one of them can take. Cells and headers go to a worker whose networks are
     * in, unless none has them yet (the others would only hold them); whole analyses, ANALYSES_MAX
     * at once. -> {at, slot}, or null.
     */
    function nextLot(pool, free) {
        var order = pool.lots.map(function (l, i) { return i; }).sort(function (p, q) { return RANK[pool.lots[p].kind] - RANK[pool.lots[q].kind] || p - q; });
        var analysing = pool.workers.filter(function (s) { return s.busy && s.busy.kind === 'analyse'; }).length;
        var anyReady = pool.workers.some(function (s) { return s.ready && !s.dead; });
        for (var j = 0; j < order.length; j++) {
            var lot = pool.lots[order[j]], kind = lot.kind, slot = null;
            if (kind === 'analyse' && analysing >= ANALYSES_MAX) continue;
            if (NETS[kind] && anyReady) slot = free.filter(function (s) { return s.ready; })[0] || null;
            else if (kind === 'plan' || kind === 'job') {
                // An analysis's work to the workers that have worked its picture out (as many as
                // it is shared among): another would have to do it again first.
                slot = free.filter(function (s) { return s.planKey === lot.key; })[0] || null;
                if (!slot && (pool.holders[lot.key] || 0) < lot.shares) slot = free.filter(function (s) { return !s.planKey; })[0] || free[0] || null;
            } else slot = free[0];
            if (slot) return {at: order[j], slot: slot};
        }
        return null;
    }

    /** A worker given an analysis's work: it holds that analysis's picture worked out. */
    function hold(pool, slot, key) {
        if (slot.planKey === key) return;
        if (slot.planKey && pool.holders[slot.planKey]) pool.holders[slot.planKey]--;
        slot.planKey = key;
        pool.holders[key] = (pool.holders[key] || 0) + 1;
    }

    function dispatch(pool) {
        var free = users(pool, pool.img || {w: 1, h: 1}).filter(function (s) { return !s.busy; });
        while (free.length && pool.lots.length) {
            var pick = nextLot(pool, free);
            if (!pick) break;
            var slot = pick.slot, lot = pool.lots.splice(pick.at, 1)[0], msg = {};
            free.splice(free.indexOf(slot), 1);
            if (lot.kind === 'plan' || lot.kind === 'job') hold(pool, slot, lot.key);
            // The photo first, to a worker that does not have it yet (messages keep their order).
            if (lot.img && slot.img !== lot.imageId) {
                slot.w.postMessage({image: {id: lot.imageId, w: lot.img.w, h: lot.img.h, d: lot.img.d}});
                slot.img = lot.imageId;
            }
            msg[lot.kind] = Object.assign({id: lot.id, image: lot.imageId}, lot.msg);
            slot.busy = lot;
            try {
                slot.w.postMessage(msg, lot.transfer || []);
            } catch (e) {
                slot.busy = null;
                lot.reject(e);
            }
        }
    }

    /** The photo the lots to come are of: sent again to the workers when it changes. */
    function photo(pool, img) {
        if (pool.img !== img) {
            pool.img = img;
            pool.imageId++;
        }
    }

    /** A lot queued. -> a promise of its result. (img: the photo it is of, sent before it.) */
    function push(pool, kind, msg, img, extra) {
        if (pool.broken) return Promise.reject(new Error('no workers'));
        if (img) photo(pool, img);
        return new Promise(function (resolve, reject) {
            pool.lots.push(Object.assign({id: pool.next++, kind: kind, img: img || null, imageId: img ? pool.imageId : 0, msg: msg,
                resolve: resolve, reject: reject}, extra || {}));
            dispatch(pool);
        });
    }

    /**
     * Cells of a photo read by the workers. -> a promise of their readings, in the order of
     * items (each as OcrRecognizer.readArrow or readNumber gives it).
     *
     * @param {Object} img The photo, grey ({w, h, d}); sent again when it changes.
     * @param {Array} Hf The homography of the cells' grid.
     * @param {Array} items The cells (plain objects: kind, u0, v0, u1, v1, allowed…).
     * @param {Object} opts The reading options, plain (seq: true for the sequence network).
     */
    function read(pool, img, Hf, items, opts) {
        if (pool.broken) return Promise.reject(new Error('no workers'));
        if (!items.length) return Promise.resolve([]);
        var n = users(pool, img).length, size = Math.max(1, Math.ceil(items.length / (n * LOTS_PER_WORKER))), parts = [];
        for (var i = 0; i < items.length; i += size) parts.push(push(pool, 'cells', {Hf: Hf, items: items.slice(i, i + size), opts: opts}, img));
        return Promise.all(parts).then(function (lists) { return [].concat.apply([], lists); });
    }

    /**
     * A card's printed header read (OcrText.readHeader), one way up, by the next worker free,
     * before any cells, and whose card it tells (OcrText.identify, among the archers given by
     * roster()). -> a promise of {read, who} (read null when the header is not found, who
     * null when not asked).
     *
     * @param {Object} frame The card's straightened frame that way up, plain: {W, Hb, rowH, Hfull}.
     * @param {Object} [whose] {few: enIds of the sheet's target, fewSession, session}.
     */
    function header(pool, img, frame, whose) {
        return push(pool, 'header', {frame: frame, whose: whose || null}, img);
    }

    /**
     * A live view's card: whose it is from its header in a frame (OcrLiveTasks.headerCard, the
     * archers given by roster()), by the next worker free. -> a promise of the card's result.
     */
    function liveHeader(pool, img, lens, card, few, session) {
        return push(pool, 'lheader', {lens: lens, card: card, few: few || [], session: session || 0}, img);
    }

    /** The competition's archers, for the headers' identification: sent to every worker (in order, before what follows). */
    function roster(pool, list) {
        if (pool.roster === list) return;
        pool.roster = list;
        pool.workers.forEach(function (s) { if (!s.dead) s.w.postMessage({roster: list}); });
    }

    /**
     * How many grids a picture shows once straightened by a lens, counted by the next worker
     * free (the picture copied to it), no further than want, and how many of them fit one of
     * the layouts. -> a promise of {grids, fitted, score} (score: of the fits, summed).
     */
    function grids(pool, img, lens, want, layouts) {
        return push(pool, 'analyse', {w: img.w, h: img.h, d: img.d, lens: lens, want: want || 0, layouts: layouts || []});
    }

    /**
     * A photo's grids found as OcrVision.analyse(img, opts) finds them (opts.all), the work
     * shared among workers: each works the picture out (its blots that may be tables), then the
     * tables are looked for in each blot, and in each part of a blot that cuts into several, a
     * worker each, the largest first; put together in the blots' order, as there. A blot found to
     * make a table has its parts left. -> a promise of the analysis.
     */
    function analyse(pool, img, opts) {
        var V = root.OcrVision;
        if (pool.broken || !V || !V.analysePlan || !opts.all || opts.trace) return Promise.reject(new Error('no workers'));
        photo(pool, img);
        var key = pool.imageId + ':' + JSON.stringify(opts), n = Math.min(SHARES_MAX, users(pool, img).length), ds = null;
        var plan = function (pass) {
            // The picture worked out by every worker taking part at once: the first done tells the blots.
            var asks = [];
            for (var i = 0; i < n; i++) asks.push(push(pool, 'plan', {key: key, opts: opts, pass: pass}, img, {key: key, shares: n}));
            asks.forEach(function (q) { q.catch(function () { return null; }); });
            return Promise.race(asks);
        };
        // Each blot's table, then (one not found) its parts', all asked at once: results by "c:k".
        // skip(box, c): a blot (and its parts) or a part left out.
        var jobs = function (pass, d, skip) {
            var list = [];
            d.cands.forEach(function (cand, c) {
                if (skip(cand, c)) return;
                list.push({c: c, k: -1, box: cand});
                if (d.cuts[c].length >= 2) d.cuts[c].forEach(function (part, k) { if (!skip(part, -1)) list.push({c: c, k: k, box: part}); });
            });
            var area = function (b) { return (b.x1 - b.x0) * (b.y1 - b.y0); }, got = {};
            list.sort(function (p, q) { return area(q.box) - area(p.box); });
            return Promise.all(list.map(function (j) {
                return push(pool, 'job', {key: key, opts: opts, pass: pass, c: j.c, k: j.k}, img, {key: key, shares: n, group: pass + ':' + j.c, part: j.k >= 0})
                    .then(function (a) {
                        got[j.c + ':' + j.k] = a;
                        // A blot that makes a table: its parts are not looked at.
                        if (a && j.k < 0) drop(pool, function (l) { return l.key === key && l.group === pass + ':' + j.c && l.part; });
                    });
            })).then(function () { return got; });
        };
        var unit = function (d, got, c) {
            var u = {a: got[c + ':-1'] || null, parts: null, grids: null};
            if (!u.a) {
                u.parts = d.cuts[c];
                if (u.parts.length >= 2) u.grids = u.parts.map(function (part, k) { return got[c + ':' + k] || null; });
            }
            return u;
        };
        var result = function (found, tried) {
            ds = ds || V.workCopy(img, opts);
            found.forEach(function (a) { a.work = ds.img; });
            return found.length ? {ok: true, grids: found, work: ds.img, scale: ds.scale}
                : {ok: false, reason: tried ? 'nolines' : 'nogrid', work: ds.img, scale: ds.scale};
        };
        var end = function () {
            drop(pool, function (l) { return l.key === key; });
            pool.workers.forEach(function (s) {
                if (s.planKey === key) s.planKey = null;
                if (!s.dead) s.w.postMessage({release: key});
            });
            delete pool.holders[key];
        };
        return plan(1).then(function (d1) {
            var firstJobs = jobs(1, d1, function () { return false; });
            // The second chance sure to be wanted (no count of grids to reach, or fewer grids than
            // wanted possible from the first pass's blots, their parts counted): worked out
            // meanwhile, and the blots lying on none of the first pass's looked at (the others wait
            // for its grids).
            var blots = d1.cands.map(function (c) { return {gridBox: c}; });
            var most = d1.cands.reduce(function (sum, c, i) { return sum + Math.max(1, d1.cuts[i].length); }, 0);
            var early = !opts.quick && (!opts.want || most < opts.want) ? plan(2).then(function (d2) {
                return jobs(2, d2, function (b) { return V.covered(blots, b); }).then(function (done) { return {d2: d2, done: done}; });
            }) : null;
            return firstJobs.then(function (got) {
                var found = [];
                d1.cands.forEach(function (cand, c) { found = V.joinUnit(found, unit(d1, got, c), false); });
                if (V.enough(opts, found)) return result(found, true);
                // The second chance: only where no grid was found (more so once others are joined).
                var first = found;
                return (early || plan(2).then(function (d2) { return {d2: d2, done: {}}; })).then(function (e) {
                    var d2 = e.d2, done = e.done;
                    return jobs(2, d2, function (b, c) { return V.covered(first, b) || c >= 0 && (c + ':-1') in done; }).then(function (more) {
                        var got2 = Object.assign({}, done, more);
                        d2.cands.forEach(function (cand, c) {
                            if (!V.covered(found, cand)) found = V.joinUnit(found, unit(d2, got2, c), true);
                        });
                        return result(found, d1.cands.length || d2.cands.length);
                    });
                });
            });
        }).then(function (a) {
            end();
            return a;
        }, function (e) {
            end();
            throw e;
        });
    }

    /**
     * The sheet's ISK code read (OcrQR.readRGBA) by the next worker free. -> a promise of the
     * code (null when none). The pixels (RGBA, a typed array) go to the worker: not the page's
     * any more.
     */
    function qr(pool, data, w, h) {
        return push(pool, 'qr', {w: w, h: h, d: data}, null, {transfer: [data.buffer]});
    }

    /**
     * A card's barcode looked for (OcrBarcode.findIn) by the next worker free, after the headers.
     * -> a promise of the code (null when none).
     *
     * @param {Array} frames The card's straightened frames, plain: [{turn, W, Hb, rowH, Hfull}].
     */
    function barcode(pool, img, frames) {
        return push(pool, 'barcode', {frames: frames}, img);
    }

    /** Lots not given out yet, left (they resolve with null). */
    function drop(pool, which) {
        pool.lots = pool.lots.filter(function (l) {
            if (!which(l)) return true;
            l.resolve(null);
            return false;
        });
    }

    root.OcrPool = {create: create, read: read, header: header, liveHeader: liveHeader, roster: roster, barcode: barcode, grids: grids,
        analyse: analyse, qr: qr};
})(typeof self !== 'undefined' ? self : this);
