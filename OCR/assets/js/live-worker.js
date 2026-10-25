/**
 * The live view's worker: the slow tasks (OcrLiveTasks) away from the page, which goes
 * on following the sheet in the video meanwhile.
 *
 * Message in: {init: {models: {arrow, digit, text, number}: URLs, roster, layouts, helpers (at
 * most, a small device: fewer)}} once,
 * then the tasks ({id, type, img?, …}). Message out: each task's result; {ready: true}
 * once the networks are loaded, {error} when they cannot be.
 *
 * A frame's grids are looked for by helpers of this worker side by side (OcrPool.analyse, on
 * read-worker.js without networks) when the computer has the cores: the same grids, sooner.
 */
// With this worker's version (the newest of the module's scripts): never an older copy.
importScripts.apply(self, ['vision.js', 'layout.js', 'nn.js', 'recognizer.js', 'text.js', 'solver.js', 'orb.js', 'live-tasks.js', 'pool.js']
    .map(function (f) { return f + self.location.search; }));

var HELPERS_MAX = 3;      // helpers at most; the page's thread and this one keep a core each
var HELPERS_WAIT = 20000; // ms a frame's analysis waits for them at most: later, it is done here and they are left

var ctx = null, queue = [], helpers = null;

/** The helpers that share a frame's analysis, when the computer has cores to spare (else none). */
function startHelpers(most, textModel) {
    var cores = self.navigator && self.navigator.hardwareConcurrency || 2, n = Math.min(most || HELPERS_MAX, HELPERS_MAX, cores - 2);
    if (n < 1 || typeof Worker === 'undefined' || !self.OcrPool) return null;
    try {
        var pool = self.OcrPool.create({url: 'read-worker.js' + self.location.search, models: {text: textModel}, size: n});
        return pool.broken ? null : pool;
    } catch (e) {
        return null;
    }
}

function get(url) {
    return fetch(url, {credentials: 'same-origin'}).then(function (r) { return r.json(); });
}

function post(msg) {
    var out;
    try {
        out = self.OcrLiveTasks.run(ctx, msg);
    } catch (e) {
        out = {id: msg.id, type: msg.type, error: String(e && e.message || e)};
    }
    self.postMessage(out);
}

/** A frame's cards (two or more) read by the helpers side by side, one each (here should they fail). */
function headers(msg) {
    var T = self.OcrLiveTasks, img = msg.img && !(msg.img.d instanceof Uint8Array) ? {w: msg.img.w, h: msg.img.h, d: new Uint8Array(msg.img.d)} : msg.img;
    // The lens plain (the one kept here may carry a table built for it, which cannot be sent).
    var L = ctx.lens, lens = L ? {k: L.k, cx: L.cx, cy: L.cy, R: L.R} : null;
    self.OcrPool.roster(helpers, ctx.roster);
    Promise.all(msg.cards.map(function (c) {
        return self.OcrPool.liveHeader(helpers, img, lens, c, msg.few, msg.session).then(null, function () {
            return T.headerCard(ctx.env, ctx.lens, img, c, msg.few, msg.session, ctx.roster);
        });
    })).then(function (cards) { post(Object.assign({}, msg, {headed: cards})); }, function () { post(msg); });
}

/** A task run; a frame's grids looked for, its cards' headers read, by the helpers (here should they fail). */
function run(msg) {
    if (msg.type === 'header' && helpers && !helpers.broken && msg.cards && msg.cards.length > 1) return headers(msg);
    if (msg.type !== 'grids' || !helpers || helpers.broken) return post(msg);
    var T = self.OcrLiveTasks, out = {grids: []}, img;
    try {
        img = T.gridsFrame(ctx, msg, out);
    } catch (e) {
        return self.postMessage({id: msg.id, type: msg.type, error: String(e && e.message || e)});
    }
    var done = false, go = function (a) {
        if (done) return;
        done = true;
        post(Object.assign({}, msg, {analysed: {img: img, a: a, out: out}}));
    };
    if (!img) return go(null);
    // Helpers that do not answer (seen once on a browser, never again after a reload): the
    // analysis done here, and the helpers no longer asked.
    var late = setTimeout(function () {
        if (done) return;
        helpers.broken = true;
        out.helpersLate = true;
        go(null);
    }, HELPERS_WAIT);
    self.OcrPool.analyse(helpers, img, T.gridsOpts(msg)).then(function (a) {
        clearTimeout(late);
        go(a);
    }, function () {
        clearTimeout(late);
        go(null);
    });
}

self.onmessage = function (e) {
    var msg = e.data;
    if (msg.init) {
        var m = msg.init.models;
        // Whatever fails in starting (a network not loaded, nor built): told, for the page to start afresh.
        Promise.all([get(m.arrow), get(m.digit), get(m.text), m.number ? get(m.number) : null]).then(function (d) {
            var R = self.OcrRecognizer, X = self.OcrText;
            ctx = self.OcrLiveTasks.create({V: self.OcrVision, Lay: self.OcrLayout, R: R, X: X, S: self.OcrSolver, O: self.OcrOrb,
                nets: {arrow: R.load(d[0]), digit: R.load(d[1]), text: X.load(d[2]), number: d[3] ? X.load(d[3]) : null}});
            self.OcrLiveTasks.run(ctx, {id: 0, type: 'roster', roster: msg.init.roster || []});
            if (msg.init.lens !== undefined) self.OcrLiveTasks.run(ctx, {id: 0, type: 'setLens', lens: msg.init.lens});
            helpers = startHelpers(msg.init.helpers, m.text);
            self.postMessage({ready: true});
            queue.splice(0).forEach(run);
        }).catch(function (err) {
            ctx = null;
            self.postMessage({error: String(err && err.message || err)});
        });
        return;
    }
    // Tasks sent before the networks are in wait for them.
    if (!ctx) queue.push(msg);
    else run(msg);
};
