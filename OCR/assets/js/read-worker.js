/**
 * One of the workers that read a photo side by side (OcrPool, pool.js): the same reader and
 * the same analysis as the page (OcrRecognizer, OcrVision), on the photo the page sent it, for
 * the work asked.
 *
 * Messages in, in order: {init: {models: {arrow, digit, number, text}: URLs}} once (null: the
 * analysis only), {image: {id, w, h, d}} for each photo, then as often as needed {cells: {id,
 * image, Hf, items, opts}}, {header: {id, image, frame}} (a card's printed header, one way up),
 * {roster: [archers]} (the competition's, for the headers: once, or again when it changes),
 * {barcode: {id, image, frames}} (a card's barcode, each way up), {lheader: {id, image, lens, card,
 * few, session}} (a live view's card, OcrLiveTasks.headerCard, among the archers of roster), {plan: {id, image, key, opts, pass}} (the photo worked out: its blots that may be tables, and
 * the parts each cuts into), {job: {id, image, key, opts, pass, c, k}} (a table looked for in a
 * blot, or in one of its parts), {release: key} (the photo's analysis done with), {qr: {id, w,
 * h, d}} (the ISK code of a picture, RGBA), and {analyse: {id, w, h, d, lens, want, layouts}}
 * (a picture's grids counted, straightened by a lens, and those fitting one of the layouts).
 * Out: {ready: true} once the networks are loaded (or {error}), {id, reads} for each lot of
 * cells, {id, read, who} for a header (who: whose card it is, when asked: {few, fewSession,
 * session}, as the page's headerOf finds it), {id, cands, cuts} for a plan, {id, a} for a job (its work
 * left out: the page has it), {id, code} for an ISK code or a barcode, {id, card} for a live
 * view's card, {id, grids, fitted, score} for a
 * count (or {id, error}). Cells and headers sent before the networks are in wait for them (and
 * what comes after them, in order); the rest is done at once.
 */
// With this worker's version (the newest of the module's scripts): never an older copy.
importScripts.apply(self, ['vision.js', 'layout.js', 'nn.js', 'recognizer.js', 'text.js', 'barcode.js', 'live-tasks.js']
    .map(function (f) { return f + self.location.search; }));

var nets = null, waiting = [], image = {id: null, img: null}, plan = {key: null, P: null}, roster = [];

function get(url) {
    return fetch(url, {credentials: 'same-origin'}).then(function (r) { return r.json(); });
}

/** The photo's analysis worked out (kept for its jobs, one photo at a time), its pass ready. */
function planOf(q) {
    var V = self.OcrVision;
    if (q.image !== image.id) throw new Error('photo not received');
    if (plan.key !== q.key) plan = {key: q.key, P: V.analysePlan(image.img, q.opts)};
    if (q.pass === 2) V.secondPlan(plan.P);
    return plan.P;
}

function handle(msg) {
    var V = self.OcrVision, q = msg.plan || msg.job || msg.header || msg.cells || msg.analyse || msg.qr || msg.barcode || msg.lheader;
    if (msg.image) {
        image = {id: msg.image.id, img: V.makeGray(msg.image.w, msg.image.h, msg.image.d)};
        return;
    }
    if (msg.release) {
        if (plan.key === msg.release) plan = {key: null, P: null};
        return;
    }
    if (msg.roster) {
        roster = msg.roster;
        return;
    }
    if (!q) return;
    try {
        if (msg.plan) {
            var P = planOf(q);
            self.postMessage({id: q.id, cands: q.pass === 2 ? P.more : P.cands, cuts: V.analyseCuts(P, q.pass)});
        } else if (msg.job) {
            var a = V.analyseJob(planOf(q), q.pass, q.c, q.k);
            if (a) a.work = null;
            self.postMessage({id: q.id, a: a}, a ? [a.gridMask.buffer] : []);
        } else if (msg.lheader) {
            if (q.image !== image.id) throw new Error('photo not received');
            if (!nets.text) throw new Error('no header network');
            self.postMessage({id: q.id, card: self.OcrLiveTasks.headerCard({V: V, X: self.OcrText, nets: {text: nets.text}}, q.lens, image.img,
                q.card, q.few, q.session, roster)});
        } else if (msg.barcode) {
            if (q.image !== image.id) throw new Error('photo not received');
            self.postMessage({id: q.id, code: self.OcrBarcode.findIn(V, image.img, q.frames)});
        } else if (msg.qr) {
            // The code reader, loaded the first time it is needed.
            if (!self.OcrQR) importScripts('vendor/jsQR.js' + self.location.search, 'qr.js' + self.location.search);
            self.postMessage({id: q.id, code: self.OcrQR.readRGBA(q.d, q.w, q.h)});
        } else if (msg.analyse) {
            // How many grids a picture shows once straightened by this lens (the lens searched for).
            var img0 = V.makeGray(q.w, q.h, q.d), g = V.analyse(q.lens ? V.undistort(img0, q.lens) : img0, {all: true, want: q.want || 0});
            var fitted = 0, score = 0;
            (g.ok ? g.grids : []).forEach(function (a) {
                var best = null;
                (q.layouts || []).forEach(function (lay) {
                    var f = self.OcrLayout.fit(V, a, lay, null);
                    if (f.ok && (!best || f.score > best.score)) best = f;
                });
                if (best) {
                    fitted++;
                    score += best.score;
                }
            });
            self.postMessage({id: q.id, grids: g.ok ? g.grids.length : 0, fitted: fitted, score: score});
        } else if (msg.header) {
            if (q.image !== image.id) throw new Error('photo not received');
            if (!nets.text) throw new Error('no header network');
            var X = self.OcrText, read = X.readHeader(V, nets.text, image.img, q.frame), who = null;
            // Whose card: among the sheet's target's archers (its ISK code) first, then all.
            if (read && q.whose) {
                var few = q.whose.few.length ? roster.filter(function (r) { return q.whose.few.indexOf(r.enId) >= 0; }) : [];
                who = few.length ? X.identify(nets.text, read, few, q.whose.fewSession) : null;
                if (!who || who.status !== 'ok') who = X.identify(nets.text, read, roster, q.whose.session);
            }
            self.postMessage({id: q.id, read: read, who: who});
        } else {
            if (q.image !== image.id) throw new Error('photo not received');
            if (!nets.arrow) throw new Error('no reading network');
            var R = self.OcrRecognizer, o = Object.assign({}, q.opts || {});
            // The sequence network cannot be sent: each worker has its own.
            if (o.seq && nets.number) o.seq = {net: nets.number, X: self.OcrText};
            else delete o.seq;
            self.postMessage({id: q.id, reads: q.items.map(function (it) {
                return it.kind === 'arrow' ? R.readArrow(V, nets.arrow, image.img, q.Hf, it, o)
                    : R.readNumber(V, nets.digit, image.img, q.Hf, it, 0, o);
            })});
        }
    } catch (e) {
        self.postMessage({id: q.id, error: String(e && e.message || e)});
    }
}

self.onmessage = function (e) {
    var msg = e.data;
    if (msg.init) {
        var m = msg.init.models, R = self.OcrRecognizer, X = self.OcrText;
        if (!m) {
            nets = {};
            self.postMessage({ready: true});
            waiting.splice(0).forEach(handle);
            return;
        }
        Promise.all([m.arrow ? get(m.arrow) : null, m.digit ? get(m.digit) : null, m.number ? get(m.number) : null, m.text ? get(m.text) : null]).then(function (d) {
            nets = {arrow: d[0] ? R.load(d[0]) : null, digit: d[1] ? R.load(d[1]) : null, number: d[2] ? X.load(d[2]) : null, text: d[3] ? X.load(d[3]) : null};
            self.postMessage({ready: true});
            waiting.splice(0).forEach(handle);
        }, function (err) {
            self.postMessage({error: String(err && err.message || err)});
        });
        return;
    }
    // The analysis needs no network: done at once, unless cells or headers already wait.
    if (!nets && (msg.cells || msg.header || waiting.length)) waiting.push(msg);
    else handle(msg);
};
