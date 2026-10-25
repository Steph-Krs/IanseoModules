/**
 * Qualification page of the scorecard reader.
 *
 * A photo comes from the live camera, a file (on a phone: its own camera app) or a
 * drop, and may hold the whole sheet of a target: several scorecards side by side.
 * Every grid of it is found here, in the browser, and dealt with in turn. Whose
 * scorecard it is comes from the barcode text, or else from the target and the name
 * printed above the table (read here, against the competition's archers), or from
 * the target typed in; the server then gives the expected grid, the allowed values
 * and what ianseo holds. The grid of that archer's distance is fitted onto the photo,
 * every cell is cut out, read and checked. The photo itself never leaves the browser.
 *
 * Each grid of the photo has its own record (a "card" of the sheet); the page shows
 * one at a time, chosen in the sheet's bar or by a click on the photo. A card that
 * waits for the operator (a target to type in, a session to confirm) does not hold
 * up the others.
 */
// The core's page template loads scripts in the head, before the page exists.
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var root = document.getElementById('ocr');
    if (!root) return;
    var V = window.OcrVision, B = window.OcrBarcode, Lay = window.OcrLayout, T = window.OCR_T || {};
    var R = window.OcrRecognizer, S = window.OcrSolver, X = window.OcrText, Q = window.OcrQR;
    var tourCode = root.getAttribute('data-tour-code') || '';
    var api = root.getAttribute('data-api');
    var models = null, roster = null;
    var MAX_SIDE = 4000;
    // Confidence from which an arrow shows as sure. Real cards, read with the margin and
    // the sequence reader: 39 % of the arrows shown sure, 0.3 % of those wrong (0.98:
    // 43 %, 0.9 % wrong).
    var SURE = 0.998;
    var READ_SLICE = 60;    // ms of reading at a time, the page going on between

    function el(id) { return document.getElementById(id); }

    function t(key, a) {
        var s = T[key] !== undefined ? T[key] : key;
        if (a === undefined || a === null) return s;
        if (typeof a !== 'object') return s.split('{$a}').join(String(a));
        Object.keys(a).forEach(function (k) { s = s.split('{$a[' + k + ']}').join(String(a[k])); });
        return s;
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    // sheet: the cards of the photo; view: the one shown; gen: changes with each photo,
    // so that the work left over from the previous one stops.
    // qr: what the sheet's ISK code tells (session, distance, target), when there is one.
    var state = {full: null, source: null, sheet: [], view: null, stream: null, gen: 0, qr: null};

    // ----------------------------------------------------------------- options

    // For the module's development, each one ticked or not on the page; kept in this
    // browser. The reading's own settings are each mode's (readFlags). The development
    // tools hidden (no options on the page): the defaults, nothing sent to the server.
    var OPTION_KEY = 'ocrReadOptions', OPTION_DEFAULT = {marks: true, doubts: true}, optionBox = el('ocr-options');
    var readOpts = (function () {
        var o = Object.assign({}, OPTION_DEFAULT);
        if (!optionBox) return {marks: true};
        try {
            var saved = JSON.parse(window.localStorage.getItem(OPTION_KEY));
            if (saved && typeof saved === 'object') ['marks', 'record', 'doubts'].forEach(function (k) { if (k in saved) o[k] = !!saved[k]; });
        } catch (e) { /* storage refused: the defaults */ }
        return o;
    })();

    /**
     * The recognizer's settings the real cards read best with. Each cell reframed on its own
     * lines in the video only: more cells read surely there, nothing gained on a picture.
     */
    function readFlags() {
        var o = {own: true, cut: true, seq: true, mix: true};
        if (state.mode === 'video' && !state.frozen) o.reframe = true;
        return o;
    }

    /** The recognizer's options, with the sequence network when there is one. */
    function recognizerOpts(m) {
        var f = readFlags(), o = {own: f.own, cut: f.cut};
        if (f.reframe) o.reframe = true;
        if (m.number) {
            o.seq = {net: m.number, X: X};
            o.mix = true;
        }
        return o;
    }

    /** What is being looked at, drawn: always, but in the video (where it is an option). */
    function showMarks() {
        return state.mode !== 'video' || !!readOpts.marks;
    }

    function syncOptions() {
        Array.prototype.forEach.call(root.querySelectorAll('#ocr-options input[data-opt]'), function (b) {
            b.checked = !!readOpts[b.getAttribute('data-opt')];
        });
        // The legend tells only what is drawn.
        var marks = showMarks();
        Array.prototype.forEach.call(root.querySelectorAll('.ocr-legend-marks'), function (li) { li.hidden = !marks; });
    }

    syncOptions();
    if (optionBox) optionBox.addEventListener('change', function (e) {
        var k = e.target.getAttribute('data-opt');
        if (!k) return;
        readOpts[k] = e.target.checked;
        try {
            window.localStorage.setItem(OPTION_KEY, JSON.stringify(readOpts));
        } catch (err) { /* kept for this page only */ }
        syncOptions();
        if (k === 'record' && live) {
            if (readOpts.record) recStart();
            else recStop();
        }
    });

    // ------------------------------------------------------------------ status

    /** What concerns no card (the camera, a picture without any grid, the picture being looked at): the cards say the rest. */
    function status(lines) {
        var box = el('ocr-status');
        box.innerHTML = lines.map(function (l) {
            return '<div class="ocr-msg ocr-msg-' + (l.kind || 'info') + '">' + l.html + '</div>';
        }).join('');
        box.hidden = !lines.length;
    }

    function busyLine(text) {
        return {html: esc(text) + ' <span class="ocr-dots"><i></i><i></i><i></i></span>'};
    }

    /** A card's messages, shown when it is the one on view. */
    function show(ctx, msgs) {
        ctx.msgs = msgs;
        renderSheet();
    }

    // ---------------------------------------------------------------- capture

    function fromSource(src, w, h) {
        var s = Math.min(1, MAX_SIDE / Math.max(w, h));
        var cw = Math.round(w * s), ch = Math.round(h * s);
        var c = document.createElement('canvas');
        c.width = cw;
        c.height = ch;
        var ctx = c.getContext('2d', {willReadFrequently: true});
        ctx.drawImage(src, 0, 0, cw, ch);
        state.gen++;
        state.working = true;
        state.times = {at: performance.now()};
        state.source = c;
        state.full = V.fromRGBA(ctx.getImageData(0, 0, cw, ch).data, cw, ch);
        state.sheet = [];
        state.view = null;
        state.qr = state.qrOther = null;
        renderSheet();
        el('ocr-archer').innerHTML = '';
        el('ocr-cells').innerHTML = '';
        syncButtons();
        drawPhoto();
        status([busyLine(t('Processing'))]);
        // Let the browser paint the message before the long computation.
        setTimeout(analyse, 30);
    }

    function loadFile(file) {
        if (!file) return;
        var url = URL.createObjectURL(file), img = new Image();
        img.onload = function () {
            URL.revokeObjectURL(url);
            // The camera or a picture imported, not both.
            stopCamera();
            state.seed = null;
            state.seedLens = undefined;
            fromSource(img, img.naturalWidth, img.naturalHeight);
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            status([{kind: 'err', html: esc(t('ErrImage'))}]);
        };
        img.src = url;
    }

    el('ocr-file').addEventListener('change', function () {
        loadFile(this.files && this.files[0]);
        this.value = '';
    });

    var drop = el('ocr-drop');
    drop.addEventListener('dragover', function (e) {
        e.preventDefault();
        drop.classList.add('ocr-over');
    });
    drop.addEventListener('dragleave', function () { drop.classList.remove('ocr-over'); });
    drop.addEventListener('drop', function (e) {
        e.preventDefault();
        drop.classList.remove('ocr-over');
        loadFile(e.dataTransfer.files && e.dataTransfer.files[0]);
    });

    // The live camera needs a secure context; a phone on the local network over plain
    // http only gets its camera app through the file field.
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.isSecureContext) {
        el('ocr-modes').hidden = false;
    } else if (!(window.OcrSecure && window.OcrSecure.explain(el('ocr-secure'), t, 'CameraInsecure'))) {
        el('ocr-cam-hint').hidden = false;
    }

    // Three ways with the camera. Automatic: fixed above the table, the picture takes itself
    // once every grid of the sheet is seen sharply. Manual: the operator takes it. Video: read
    // as the video goes, the camera in hand. A picture taken is read as an imported one is,
    // the window showing it (folded away, for the cards to take the page) until the view goes
    // back to live for the next sheet.
    var MODE_KEY = 'ocrMode', MODES = ['auto', 'manual', 'video'];
    state.mode = null;
    state.frozen = false;
    state.folded = false;

    /** The window's parts and buttons, as the mode and the picture are. */
    function syncButtons() {
        var on = !!state.stream;
        Array.prototype.forEach.call(root.querySelectorAll('.ocr-mode'), function (b) {
            b.classList.toggle('ocr-mode-on', on && b.getAttribute('data-mode') === state.mode);
        });
        el('ocr-live-buttons').hidden = !on;
        el('ocr-shot').hidden = !on || state.mode !== 'manual' || state.frozen;
        el('ocr-back').hidden = !on || !state.frozen;
        el('ocr-live').hidden = !on || state.frozen;
        el('ocr-live-state').hidden = !on || state.frozen;
        el('ocr-canvas').hidden = !state.source || (on && !state.frozen);
        el('ocr-drop-hint').hidden = on || !!state.source;
        el('ocr-turn').hidden = el('ocr-mirror').hidden = !on && !state.source;
        if (on && !state.frozen) layoutLive();
    }

    function useMode(mode) {
        state.mode = mode;
        state.frozen = false;
        try {
            window.localStorage.setItem(MODE_KEY, mode);
        } catch (e) { /* asked again next time */ }
        fold(false);
        syncButtons();
        syncOptions();
        liveStart();
    }

    function startCamera(mode) {
        if (state.stream) {
            useMode(mode);
            return;
        }
        navigator.mediaDevices.getUserMedia({
            audio: false,
            // What the live view works on (LIVE_FULL): a finer stream is only reduced, after it has
            // been decoded and recorded (an old tablet's 8 Mpx closed the page).
            video: {facingMode: {ideal: 'environment'}, width: {ideal: LIVE_FULL}, height: {ideal: Math.round(LIVE_FULL * 9 / 16)}}
        }).then(function (stream) {
            state.stream = stream;
            var v = el('ocr-video');
            v.srcObject = stream;
            v.play();
            useMode(mode);
        }).catch(function (err) {
            status([{kind: 'err', html: esc(t('CameraError', err && err.message ? err.message : String(err)))}]);
        });
    }

    Array.prototype.forEach.call(root.querySelectorAll('.ocr-mode'), function (b) {
        b.addEventListener('click', function () { startCamera(b.getAttribute('data-mode')); });
    });
    // The mode used last stands out until one is chosen.
    (function () {
        var m = null;
        try {
            m = window.localStorage.getItem(MODE_KEY);
        } catch (e) { /* none */ }
        var b = MODES.indexOf(m) >= 0 && root.querySelector('.ocr-mode[data-mode="' + m + '"]');
        if (b) b.classList.add('ocr-btn-primary');
    })();

    function stopCamera() {
        if (state.stream) state.stream.getTracks().forEach(function (tr) { tr.stop(); });
        state.stream = null;
        state.mode = null;
        state.frozen = false;
        liveStop();
        syncButtons();
        syncOptions();
    }

    el('ocr-cam-stop').addEventListener('click', stopCamera);

    /**
     * The picture of the moment (the very frame the live view followed, lens undone as it
     * knows it), read as an imported one is, with what the live view knows of the sheet:
     * whose cards these are, what was read in their cells.
     */
    function shoot() {
        if (!live || !live.full.width) return;
        var wrap = el('ocr-drop');
        wrap.classList.add('ocr-flash');
        setTimeout(function () { wrap.classList.remove('ocr-flash'); }, 250);
        state.seed = liveKnowledge();
        state.seedLens = live.lv.lens;
        state.frozen = true;
        var ready = liveFits();
        if (rec) recLog({f: 'shot', mode: state.mode, from: ready ? 'live' : 'photo'});
        if (ready) {
            live.opened = ready.filter(function (d) { return d.ident; }).map(function (d) { return d.ident.archer.enId; }).sort().join(',');
            fromLive(ready);
        } else {
            fromSource(live.full, live.full.width, live.full.height);
        }
        // The cards take the page, all on view at once.
        fold(true);
    }

    /**
     * The live view's grids, when it has fitted every grid of the sheet, whole in the frame, and
     * identified one at least (each a different archer), the camera fixed: the picture is then
     * read from them, fitted on that very view, with no second analysis of it (a second or two
     * saved; the cards not identified wait for the operator, as they would on the picture,
     * whose headers the live view has read already). Null otherwise. (localStorage ocrShotFrom
     * = "photo": always analysed again, to compare.)
     */
    function liveFits() {
        if (!live || !live.lv.env.fixed) return null;
        try {
            if (window.localStorage.getItem('ocrShotFrom') === 'photo') return null;
        } catch (e) { /* as by default */ }
        var lv = live.lv, want = lv.expected || 0, W = live.full.width, H = live.full.height;
        var ready = window.OcrLive.drawing(lv).filter(function (d) { return d.fit; });
        if (!want || ready.length < want) return null;
        var ids = ready.filter(function (d) { return d.ident && d.card; }).map(function (d) { return d.card.archer.enId; });
        if (!ids.length || ids.some(function (id, i) { return ids.indexOf(id) !== i; })) return null;
        var whole = ready.every(function (d) {
            return d.outline.every(function (p) {
                var q = lv.lens ? V.lensBack(lv.lens, p.x, p.y) : p;
                return q.x >= 0 && q.y >= 0 && q.x <= W && q.y <= H;
            });
        });
        return whole ? ready : null;
    }

    el('ocr-shot').addEventListener('click', shoot);

    /**
     * Back to the live view, for the next sheet (the one read stays shown until then). Once the
     * sheet is written (auto), the next picture waits for the view to change: the sheet left
     * under the camera is not taken again.
     */
    function backToLive(auto) {
        if (!state.frozen) return;
        state.frozen = false;
        if (rec) recLog({f: 'back', auto: !!auto});
        if (live) {
            window.OcrLive.anew(live.lv);
            live.readyAt = 0;
            live.readySince = 0;
            live.opened = null;
            live.holdUntil = auto ? live.lv.restarts || 0 : undefined;
        }
        fold(false);
        syncButtons();
    }

    el('ocr-back').addEventListener('click', function () { backToLive(false); });

    /** The window folded away (the cards take the page) or shown. */
    function fold(b) {
        state.folded = !!b;
        el('ocr-capture').classList.toggle('ocr-folded', state.folded);
        var f = el('ocr-fold'), label = t(state.folded ? 'ViewUnfold' : 'ViewFold');
        f.title = label;
        f.setAttribute('aria-label', label);
        if (!state.folded) {
            drawPhoto();
            layoutLive();
        }
    }

    el('ocr-fold').addEventListener('click', function () { fold(!state.folded); });

    // The view turned by quarters (clockwise, four times: as it was) and mirrored, for a camera
    // mounted another way: what is shown only (kept in this browser), the reading never.
    var VIEW_KEY = 'ocrView';
    var viewT = (function () {
        try {
            var v = JSON.parse(window.localStorage.getItem(VIEW_KEY));
            if (v) return {rot: ((v.rot !== undefined ? +v.rot : v.turn ? 2 : 0) % 4 + 4) % 4, mirror: !!v.mirror};
        } catch (e) { /* as it comes */ }
        return {rot: 0, mirror: false};
    })();

    /**
     * Where a point of a w x h picture is shown, the picture turned and mirrored as asked:
     * [a, b, c, d, e, f], x' = a x + c y + e and y' = b x + d y + f (a canvas's setTransform).
     */
    function viewMatrix(w, h) {
        var r = viewT.rot, m = [[1, 0, 0, 1, 0, 0], [0, 1, -1, 0, h, 0], [-1, 0, 0, -1, w, h], [0, -1, 1, 0, 0, w]][r];
        if (viewT.mirror) m = [-m[0], m[1], -m[2], m[3], (r % 2 ? h : w) - m[4], m[5]];
        return m;
    }

    /** A w x h picture's size as shown (a quarter turn swaps them). */
    function viewSize(w, h) {
        return viewT.rot % 2 ? {w: h, h: w} : {w: w, h: h};
    }

    /** A small screen (a phone, a tablet held upright): the live view at half its height at most. */
    function smallScreen() {
        return window.matchMedia ? window.matchMedia('(max-width: 900px), (pointer: coarse)').matches : false;
    }

    /**
     * The live view's box: the video as large as the page lets it (its height at most half the
     * screen's on a small one, else 70 %), turned and mirrored; what is drawn over it covers
     * the box unturned (drawLive puts each point where it shows).
     */
    function layoutLive() {
        var wrap = el('ocr-live'), v = el('ocr-video'), area = el('ocr-drop');
        if (!wrap || wrap.hidden) return;
        var vw = v.videoWidth || 16, vh = v.videoHeight || 9, sz = viewSize(vw, vh);
        var maxW = Math.max(160, area.clientWidth - 4), maxH = Math.max(160, window.innerHeight * (smallScreen() ? 0.5 : 0.7));
        var k = Math.min(maxW / sz.w, maxH / sz.h), bw = Math.round(sz.w * k), bh = Math.round(sz.h * k);
        var iw = Math.round(vw * k), ih = Math.round(vh * k);
        wrap.style.width = bw + 'px';
        wrap.style.height = bh + 'px';
        v.style.width = iw + 'px';
        v.style.height = ih + 'px';
        v.style.left = Math.round((bw - iw) / 2) + 'px';
        v.style.top = Math.round((bh - ih) / 2) + 'px';
        v.style.transform = (viewT.mirror ? 'scaleX(-1) ' : '') + 'rotate(' + 90 * viewT.rot + 'deg)';
    }

    function applyView() {
        layoutLive();
        drawPhoto();
        try {
            window.localStorage.setItem(VIEW_KEY, JSON.stringify(viewT));
        } catch (e) { /* this page only */ }
    }

    el('ocr-turn').addEventListener('click', function () {
        viewT.rot = (viewT.rot + 1) % 4;
        applyView();
    });
    el('ocr-mirror').addEventListener('click', function () {
        viewT.mirror = !viewT.mirror;
        applyView();
    });
    el('ocr-video').addEventListener('loadedmetadata', layoutLive);
    el('ocr-video').addEventListener('resize', layoutLive);
    window.addEventListener('resize', layoutLive);

    // --------------------------------------------------------------- live view

    // The sheet followed through the video (OcrLive), the slow work in a worker
    // (live-worker.js): its grids drawn over the video, each in its colour with its archer
    // once known, the cells read for sure under a green veil. The camera may move, come
    // closer, turn: the sheet stays found.
    var LIVE_SIDE = 640;
    var LIVE_FULL = 1920;   // longest side of the frame the live view works on (a phone's 4K: four times the work, no better read)
    var COLOURS = ['#0254a8', '#04ac0b', '#e57b10', '#a0129e', '#c0392b', '#0aa6b8'];
    var SETUP_KEY = 'ocrSetup:' + tourCode;
    var SETUP = ['ocr-session', 'ocr-dist', 'ocr-expected'];
    var live = null;

    /** Grids per sheet, as the operator set it (0: not given). */
    function expectedGrids() {
        var s = el('ocr-expected');
        return s ? parseInt(s.value, 10) || 0 : 0;
    }

    /**
     * What the scorecards are, asked first: the session, the distance, the cards per sheet.
     * The capture and the reading show once all three are given (every reading depends on
     * them); kept for the competition in this browser, so that they are asked once.
     */
    function setupReady() {
        var ok = SETUP.every(function (id) { return !el(id) || el(id).value !== ''; });
        el('ocr-main').hidden = !ok;
        el('ocr-setup').hidden = ok;
        if (!ok) liveStop();
        return ok;
    }

    (function () {
        var kept = {};
        try {
            kept = JSON.parse(window.localStorage.getItem(SETUP_KEY) || '{}') || {};
        } catch (e) { /* storage refused: asked on every visit */ }
        SETUP.forEach(function (id) {
            var s = el(id);
            if (!s) return;
            if (kept[id] && s.querySelector('option[value="' + kept[id] + '"]')) s.value = kept[id];
            s.addEventListener('change', function () {
                if (id === 'ocr-session') sheetOfSession();
                var now = {};
                SETUP.forEach(function (k) { if (el(k)) now[k] = el(k).value; });
                try {
                    window.localStorage.setItem(SETUP_KEY, JSON.stringify(now));
                } catch (e) { /* kept for this page only */ }
                if (live && id === 'ocr-expected') live.lv.expected = expectedGrids();
                setupReady();
            });
        });
        if (!el('ocr-expected').value) sheetOfSession();
        setupReady();
    })();

    /** The archers per sheet the session's targets call for (its option's data-sheet), once a session is chosen. */
    function sheetOfSession() {
        var s = el('ocr-session'), e = el('ocr-expected'), o = s && s.options[s.selectedIndex];
        var n = o ? o.getAttribute('data-sheet') : null;
        if (n && e.querySelector('option[value="' + n + '"]') && e.value !== n) {
            e.value = n;
            if (live) live.lv.expected = expectedGrids();
        }
    }

    /** The size of the frame the live view works on, for the camera's: at most LIVE_FULL. */
    function liveSize(W, H) {
        var s = Math.min(1, LIVE_FULL / Math.max(W, H, 1));
        return {w: Math.round(W * s), h: Math.round(H * s)};
    }

    /** The camera's lens, remembered for this camera and size (the live view's): measured once only. */
    function lensKey(stream) {
        var tr = stream && stream.getVideoTracks()[0], st = tr && tr.getSettings ? tr.getSettings() : {};
        var z = liveSize(st.width || 0, st.height || 0);
        return 'ocrLens:' + (tr ? tr.label : '') + ':' + z.w + 'x' + z.h;
    }

    function storedLens(key) {
        try {
            var v = window.localStorage.getItem(key);
            return v === null ? undefined : JSON.parse(v);
        } catch (e) {
            return undefined;
        }
    }

    function storeLens(key, lens) {
        try {
            window.localStorage.setItem(key, JSON.stringify(lens));
        } catch (e) { /* measured again next time */ }
    }

    /** The scorecard layouts of the competition, fetched once. */
    var layouts = null;
    function loadLayouts() {
        if (layouts) return Promise.resolve(layouts);
        return fetch(api, {method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=layouts'})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) throw new Error(j.msg);
                layouts = j.layouts;
                return layouts;
            });
    }

    /** The reading options for the worker: flags only (it has its own networks). */
    function workerOpts() {
        var o = readFlags();
        if (!root.getAttribute('data-number-model')) {
            delete o.seq;
            delete o.mix;
        }
        return o;
    }

    function liveStart() {
        liveStop();
        if (!window.OcrLive || !window.Worker) return;
        var key = lensKey(state.stream), lens = storedLens(key);
        var worker;
        try {
            worker = new Worker(root.getAttribute('data-live-worker'));
        } catch (e) {
            return;
        }
        live = {worker: worker, key: key, small: document.createElement('canvas'), full: document.createElement('canvas'), names: {},
            bornAt: performance.now(), up: false};
        // The camera fixed above the table in the automatic mode: only the sheets move.
        live.lv = window.OcrLive.create({V: V, O: window.OcrOrb, F: window.OcrFollow, Lay: Lay, expected: expectedGrids(), opts: workerOpts(),
            lens: lens, layouts: [], session: currentSession(), sure: SURE, fixed: state.mode === 'auto',
            few: function () { return state.qr && roster ? qrArchers(roster) : []; },
            roster: function () { return roster || []; }});
        worker.onmessage = function (e) { liveResult(e.data); };
        recStart();
        Promise.all([loadRoster().catch(function () { return []; }), loadLayouts().catch(function () { return []; })]).then(function (r) {
            if (!live || live.worker !== worker) return;
            live.lv.env.layouts = r[1];
            liveInit(worker, r[0], lens);
        });
        liveNext();
    }

    function liveInit(worker, list, lens) {
        // The lens plain: the one the view keeps carries a table built for it (a function), which
        // no message takes (a restart threw, and the view stopped).
        var plain = lens ? {k: lens.k, cx: lens.cx, cy: lens.cy, R: lens.R} : lens;
        worker.onerror = function (e) {
            if (rec) recLog({f: 'worker', why: 'error', msg: String(e && e.message || e)});
            if (live && live.worker === worker) liveWorkerAgain('error');
        };
        worker.postMessage({init: {models: {arrow: root.getAttribute('data-arrow-model'), digit: root.getAttribute('data-digit-model'),
            text: root.getAttribute('data-text-model'), number: root.getAttribute('data-number-model')}, roster: list, lens: plain,
            helpers: smallDevice() ? 1 : HELPERS_MAX}});
    }

    var HELPERS_MAX = 3;   // helpers of the live view's worker at most (the cores allowing)

    /**
     * A tablet, a phone, or a computer of little memory (each worker holds the networks and a
     * copy of the frame): fewer workers. Without the memory told (Safari, Firefox), a touch
     * screen with four cores or fewer is taken for one (an iPad Air 2 closed the page).
     */
    function smallDevice() {
        var mem = navigator.deviceMemory;
        if (mem) return mem <= 4;
        return (navigator.maxTouchPoints || 0) > 1 && (navigator.hardwareConcurrency || 2) <= 4;
    }

    // The live view's worker started afresh when it cannot load its networks, or gives no
    // answer to a task for too long (seen once on a computer busy with other workers): the
    // view goes on from nothing rather than waiting for ever.
    var LIVE_STUCK = 40000;   // ms a task may take, at most (a first search for grids on a slow computer: 15 s and more)
    var LIVE_READY = 10000;   // ms the worker may take to load its networks, at most (2 s on a slow computer)
    var LIVE_AGAIN = 3;       // workers started afresh at most, per camera started

    function liveWorkerAgain(why) {
        if (!live || (live.again || 0) >= LIVE_AGAIN) return;
        live.again = (live.again || 0) + 1;
        if (rec) recLog({f: 'worker', why: why});
        live.worker.terminate();
        var worker;
        try {
            worker = new Worker(root.getAttribute('data-live-worker'));
        } catch (e) {
            return;
        }
        live.worker = worker;
        live.taskAt = 0;
        live.bornAt = performance.now();
        live.up = false;
        worker.onmessage = function (e) { liveResult(e.data); };
        live.lv.busy = null;
        window.OcrLive.anew(live.lv);
        liveInit(worker, roster || [], live.lv.lens);
    }

    function liveStop() {
        recStop();
        if (!live) return;
        live.worker.terminate();
        if (live.raf) cancelAnimationFrame(live.raf);
        live = null;
        var o = el('ocr-live-overlay');
        if (o) o.getContext('2d').clearRect(0, 0, o.width, o.height);
    }

    function liveNext() {
        if (!live) return;
        var v = el('ocr-video'), me = live;
        var go = function () { if (live === me) liveFrame(); };
        if (v.requestVideoFrameCallback) v.requestVideoFrameCallback(go);
        else live.raf = requestAnimationFrame(go);
    }

    /** One frame: followed, maybe a task for the worker, and drawn. */
    function liveFrame() {
        var v = el('ocr-video');
        if (v.videoWidth && !state.frozen) {
            var z = liveSize(v.videoWidth, v.videoHeight), W = z.w, H = z.h, s = Math.min(1, LIVE_SIDE / Math.max(W, H)), c = live.small, f = live.full;
            // The frame kept whole, the small one drawn from it: a task, and the cards opened
            // from the live view, get the very frame the sheet was followed on.
            if (f.width !== W || f.height !== H) {
                f.width = W;
                f.height = H;
            }
            var fg = f.getContext('2d', {willReadFrequently: true});
            fg.drawImage(v, 0, 0, W, H);
            c.width = Math.round(W * s);
            c.height = Math.round(H * s);
            var g = c.getContext('2d', {willReadFrequently: true});
            g.drawImage(f, 0, 0, c.width, c.height);
            var small = V.fromRGBA(g.getImageData(0, 0, c.width, c.height).data, c.width, c.height);
            var full = function () {
                return V.fromRGBA(fg.getImageData(0, 0, W, H).data, W, H);
            };
            var r = window.OcrLive.frame(live.lv, small, performance.now() / 1000, full, {w: W, h: H});
            // Another sheet: its cards are waited for afresh.
            if (r.anew) {
                live.readyAt = 0;
                if (rec) recLog({f: 'anew', why: r.anew});
            }
            if (rec) {
                recLog({f: 'frame', ok: r.ok, how: r.how, H: r.H ? r.H.map(function (x) { return +x.toPrecision(6); }) : null,
                    speed: Math.round(live.lv.speed || 0), task: r.task ? {id: r.task.id, type: r.task.type,
                        n: (r.task.items || r.task.cards || r.task.grids || []).length} : null});
            }
            if (r.task) {
                live.worker.postMessage(r.task, r.task.img ? [r.task.img.d.buffer] : []);
                live.taskAt = performance.now();
            } else if (live.taskAt && performance.now() - live.taskAt > LIVE_STUCK) {
                liveWorkerAgain('stuck');
            } else if (!live.up && performance.now() - live.bornAt > LIVE_READY) {
                liveWorkerAgain('not up');
            }
            drawLive(r);
        }
        liveNext();
    }

    /** A task's result from the worker. */
    function liveResult(res) {
        if (!live) return;
        if (res.ready) {
            live.up = true;
            if (rec) recLog({f: 'up', who: 'live', ms: Math.round(performance.now() - live.bornAt)});
            return;
        }
        if (res.error && res.id === undefined) {
            if (rec) recLog({f: 'worker', why: 'init', msg: String(res.error)});
            liveWorkerAgain('init');
            return;
        }
        live.taskAt = 0;
        // The live view's first search for grids done: the reading workers may start (not
        // sooner: on a computer of few cores they would slow that first search down).
        if (res.type === 'grids') {
            readPool();
            live.gridsView = live.lv.restarts || 0;
        }
        // More grids fitted in a frame than the cards per sheet chosen (whole tables, each
        // matching a layout of the competition): that many, before the picture is taken (the
        // live view keeps no more grids than chosen, so it would never see them).
        var want = expectedGrids();
        if (res.type === 'grids' && res.grids && want) {
            var sel = el('ocr-expected');
            if (res.grids.length > want && sel && sel.querySelector('option[value="' + res.grids.length + '"]')) {
                sel.value = String(res.grids.length);
                sel.dispatchEvent(new Event('change'));
                if (rec) recLog({f: 'expected', n: res.grids.length});
            }
        }
        var out = window.OcrLive.result(live.lv, res);
        if (rec) recLog(resultLog(res, out));
        if (out.lens !== undefined) storeLens(live.key, out.lens);
        if (out.reads) liveRefresh();
        (out.idents || []).forEach(function (g) {
            var a = g.ident.archer, me = live;
            fetch(api, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=card&enId=' + encodeURIComponent(a.enId) + '&dist=' + encodeURIComponent(currentDist())})
                .then(function (r2) { return r2.json(); })
                .then(function (j) {
                    if (live === me && !j.error && j.card) window.OcrLive.setCard(live.lv, g.id, j.card);
                }, function () { return null; });
        });
    }

    /** The grids followed over the video: outline, archer, cells read for sure. */
    function drawLive(r) {
        var v = el('ocr-video'), o = el('ocr-live-overlay'), box = el('ocr-live'), cw = box.clientWidth, ch = box.clientHeight, lv = live.lv;
        if (!o) return;
        if (o.width !== cw || o.height !== ch) {
            o.width = cw;
            o.height = ch;
        }
        // What is drawn is in the live view's frame (the video's, at most LIVE_FULL).
        var g = o.getContext('2d'), W = live.full.width || v.videoWidth, H = live.full.height || v.videoHeight;
        g.clearRect(0, 0, cw, ch);
        // The frame as shown in the box, turned and mirrored as the video is; what is drawn was
        // straightened from the lens: the lens is put back.
        var sz = viewSize(W, H), k = Math.min(cw / sz.w, ch / sz.h), ox = (cw - sz.w * k) / 2, oy = (ch - sz.h * k) / 2, M = viewMatrix(W, H);
        var at = function (p) {
            var q = lv.lens ? V.lensBack(lv.lens, p.x, p.y) : p;
            return {x: ox + (M[0] * q.x + M[2] * q.y + M[4]) * k, y: oy + (M[1] * q.x + M[3] * q.y + M[5]) * k};
        };
        var poly = function (pts) {
            g.beginPath();
            pts.forEach(function (p, j) {
                var q = at(p);
                if (j) g.lineTo(q.x, q.y);
                else g.moveTo(q.x, q.y);
            });
            g.closePath();
        };
        var drawn = r.ok ? window.OcrLive.drawing(lv) : [], sure = 0, cells = 0, names = [];
        // Hidden (folded away), nothing is drawn: the reading goes on all the same.
        var hide = state.folded || state.frozen;
        if (!hide && state.mode !== 'video') placesVeil(g, poly, W, H, drawn);
        drawn.forEach(function (d, i) {
            sure += d.sure.length;
            cells += d.cells || 0;
            if (d.ident) names.push(d.ident.archer.target + ' ' + d.ident.archer.name);
        });
        if (!hide) drawn.forEach(function (d, i) {
            var colour = COLOURS[i % COLOURS.length];
            // The cells the core prints grey (arrows' and ends' numbers): where the grid is
            // taken to lie, a line off shows at once.
            g.fillStyle = 'rgba(142, 68, 173, 0.28)';
            (d.marks || []).forEach(function (q) {
                poly(q);
                g.fill();
            });
            g.fillStyle = 'rgba(4, 172, 11, 0.38)';
            d.sure.forEach(function (q) {
                poly(q);
                g.fill();
            });
            // The ends not shot: not read.
            g.fillStyle = 'rgba(120, 124, 130, 0.35)';
            (d.empty || []).forEach(function (q) {
                poly(q);
                g.fill();
            });
            // A signed total left blank by the archer (it stays blank): told.
            g.fillStyle = 'rgba(241, 196, 15, 0.55)';
            (d.blankTotals || []).forEach(function (q) {
                poly(q);
                g.fill();
            });
            // A signed total other than the arrows give, not told wrong for sure: to check.
            g.strokeStyle = 'rgba(230, 126, 34, 1)';
            g.fillStyle = 'rgba(230, 126, 34, 0.4)';
            g.lineWidth = 3;
            (d.checkTotals || []).forEach(function (q) {
                poly(q);
                g.fill();
                g.stroke();
            });
            // Wrong for sure: an arrow ianseo holds otherwise, a total the arrows do not make.
            g.fillStyle = 'rgba(192, 57, 43, 0.5)';
            g.strokeStyle = 'rgba(192, 57, 43, 1)';
            g.lineWidth = 2;
            (d.wrong || []).forEach(function (q) {
                poly(q);
                g.fill();
                g.stroke();
            });
            // What is being looked at: cells read and still in doubt, cells and header the
            // worker reads now, the table put back on its lines.
            if (showMarks()) {
                g.fillStyle = 'rgba(229, 123, 16, 0.30)';
                (d.doubt || []).forEach(function (q) {
                    poly(q);
                    g.fill();
                });
                g.strokeStyle = 'rgba(2, 84, 168, 0.95)';
                g.lineWidth = 1.5;
                (d.reading || []).forEach(function (q) {
                    poly(q);
                    g.stroke();
                });
                if (d.header) {
                    g.setLineDash([6, 4]);
                    poly(d.header);
                    g.stroke();
                    g.setLineDash([]);
                }
            }
            poly(d.outline);
            g.strokeStyle = colour;
            g.lineWidth = 3;
            g.stroke();
            var text = d.ident ? d.ident.archer.target + ' ' + d.ident.archer.name.split(' ')[0] : '?';
            g.font = 'bold 15px sans-serif';
            var pts = d.outline.map(at), tw = g.measureText(text).width + 12, x0, y0;
            if (d.headed) {
                // On the header's side, by the name, however the sheet lies (a sheet turned
                // over would have it over the total row): just above the table's top left.
                var a = pts[0], b = pts[1], c = pts[3], vl = Math.hypot(a.x - c.x, a.y - c.y) || 1;
                var px = a.x + (a.x - c.x) / vl * 16, py = a.y + (a.y - c.y) / vl * 16;
                x0 = b.x >= a.x ? px : px - tw;
                y0 = py - 11;
            } else {
                var top = pts.reduce(function (p, q) { return q.x + q.y < p.x + p.y ? q : p; });
                x0 = top.x;
                y0 = top.y - 24;
            }
            g.fillStyle = colour;
            g.fillRect(x0, y0, tw, 22);
            g.fillStyle = '#fff';
            g.fillText(text, x0 + 6, y0 + 16);
        });
        if (rec && r.ok && !(lv.frames % 5)) {
            recLog({f: 'drawing', grids: drawn.map(function (d) {
                return {id: d.id, outline: d.outline.map(function (p) { return [Math.round(p.x), Math.round(p.y)]; }),
                    archer: d.ident ? d.ident.archer.enId : null, sure: d.sure.length, cells: d.cells || 0};
            })});
        }
        var box = el('ocr-live-state'), msg, waiting = false, ready = 0;
        if (r.ok && state.mode !== 'video') keepZones(drawn);
        if (r.ok && state.mode === 'video') waiting = autoOpen(r, drawn);
        else if (r.ok && state.mode === 'auto') ready = autoShot(drawn);
        if (r.ok && state.mode === 'auto' && !state.frozen) lensSearch(drawn);
        if (state.frozen) return;
        if (!r.ok) msg = t('LiveLostSheet');
        else if (state.mode !== 'video' && !drawn.length) msg = t(state.mode === 'auto' ? 'AutoPlace' : 'ManualPlace');
        else if (state.mode === 'auto') msg = t('AutoWait', {n: ready, want: lv.expected || drawn.length});
        else if (state.mode === 'manual') msg = t('ManualReady', {n: drawn.length, names: names.join(', ') || '-'});
        else if (!drawn.length) msg = t('LiveSearching');
        else if (waiting) msg = t('LiveStepBack');
        else msg = t(lv.env.fixed ? 'LiveFollowFixed' : 'LiveFollow', {n: drawn.length, names: names.join(', ') || '-', sure: sure, cells: cells});
        // More grids seen than the cards per sheet chosen (raised by itself, liveResult, when
        // the option exists): only that many are read.
        var seen = drawn.filter(function (d) { return d.fit; }).length;
        if (r.ok && lv.expected && seen > lv.expected) msg += ' ' + t('LiveMoreGrids', {n: seen, want: lv.expected});
        // Cells are read on still frames only: a camera on the move is told so (the camera
        // fixed: a sheet being handled).
        if (r.ok && drawn.length && !waiting && (lv.sharp || 0) < HOLD_SHARP) msg += ' ' + t(lv.env.fixed ? 'LiveHandled' : 'LiveHoldStill');
        if (box.textContent !== msg) box.textContent = msg;
        box.className = 'ocr-state' + (cells && sure === cells ? ' ocr-state-ok' : '');
    }

    // ------------------------------------------------- automatic picture

    var SHOT_STILL = 600;   // ms the view stays still, every grid of the sheet seen sharply, before the picture
    var SHOT_HEADER = 0.8;  // a card not identified yet: its header this wide at least (of the live view's least to read it)
    var SHOT_WAIT = 3000;   // ms the cards are waited for to be identified by the live view (their headers read best there)

    /** A grid drawn whole in the frame (its total row too), in the frame's own pixels. */
    function wholeIn(d) {
        var lv = live.lv, W = live.full.width, H = live.full.height, ok = true;
        d.fit.cells.forEach(function (c) {
            if (!ok) return;
            [[c.u0, c.v0], [c.u1, c.v0], [c.u1, c.v1], [c.u0, c.v1]].forEach(function (q) {
                var p = V.apply(d.fit.Hfull, q[0], q[1]), r = lv.lens ? V.lensBack(lv.lens, p.x, p.y) : p;
                if (!(r.x >= 0 && r.y >= 0 && r.x <= W && r.y <= H)) ok = false;
            });
        });
        return ok;
    }

    /** Height of a grid's arrow cells (their middle one), in full-frame pixels. */
    function cellPx(d) {
        var hs = d.fit.cells.filter(function (c) { return c.kind === 'arrow'; }).map(function (c) {
            var a = V.apply(d.fit.Hfull, (c.u0 + c.u1) / 2, c.v0), b = V.apply(d.fit.Hfull, (c.u0 + c.u1) / 2, c.v1);
            return Math.hypot(b.x - a.x, b.y - a.y);
        }).sort(function (x, y) { return x - y; });
        return hs.length ? hs[hs.length >> 1] : 0;
    }

    /**
     * A grid seen well enough for its header (target, name: the card identified already, or
     * wide enough) and its cells (the totals too) to be read.
     */
    function readable(d) {
        var o = d.outline, w = Math.hypot(o[1].x - o[0].x, o[1].y - o[0].y), z = window.OcrLive.sizes;
        return (d.ident || w >= SHOT_HEADER * z.header) && cellPx(d) >= z.cell && wholeIn(d);
    }

    /**
     * Automatic mode: every grid of the sheet seen whole and readable, the view still a
     * moment: the picture takes itself. -> how many grids are ready.
     */
    function autoShot(drawn) {
        var lv = live.lv, want = lv.expected || 0, now = performance.now();
        var n = drawn.filter(function (d) { return d.fit && readable(d); }).length;
        // The sheet just written, still there: not taken again until the view changes.
        if (live.holdUntil !== undefined && (lv.restarts || 0) <= live.holdUntil) return n;
        live.holdUntil = undefined;
        if (!want || n < want || !stillView()) {
            live.readySince = 0;
            return n;
        }
        if (!live.readySince) live.readySince = now;
        // Known: identified, and the card come back from the server (a moment later), for the
        // picture to be read from the live view's fits (liveFits).
        var known = drawn.filter(function (d) { return d.fit && d.ident && d.card; }).length >= want;
        if (now - live.readySince >= (known ? SHOT_STILL : SHOT_WAIT)) {
            live.readySince = 0;
            shoot();
        }
        return n;
    }

    // The lens searched for by the grids it lets fit: a camera whose bowing the plumb lines miss
    // (OcrVision.lensOf), fewer grids fitted than the sheet holds, the view still a while: the
    // frame of the moment straightened by a few lenses, side by side in the reading workers,
    // the one under which the most grids fit the competition's layouts kept (and remembered for
    // this camera). Measured on a webcam whose bowing was missed: one grid of three found
    // straight, three from k 0.2; on a wide-angle one, all three found from 0 to 0.6, but
    // fitted best near 0.5 (the outer tables' outline cut short by their bowed lines).
    var LENS_SEARCH_AFTER = 2500;   // ms the view stays still with too few grids before a search
    var LENS_KS = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6];   // lenses tried first; then the best's neighbours
    var LENS_STEP = 0.05;
    var LENS_SEARCHES = 3;          // searches that found no better lens, at most, per camera on the page

    function lensSearch(drawn) {
        var lv = live.lv, want = expectedGrids() || lv.expected || 0, now = performance.now(), P = readPool();
        // One lens kept is enough (the view starting afresh with it has no grid for a moment), and
        // none is searched for while a sheet is read (its cells go first).
        if (!want || !P || P.broken || live.lensBusy || live.lensKept || state.working || (live.lensFailed || 0) >= LENS_SEARCHES) return;
        var fitted = drawn.filter(function (d) { return d.fit; }).length;
        // Once per view: a sheet changed (a restart) may call for another search; not before the
        // view's first search for grids has come back (a slow computer's takes seconds).
        if (fitted >= want || !stillView() || live.lensView === (lv.restarts || 0) || live.gridsView !== (lv.restarts || 0)) {
            live.lensSince = 0;
            return;
        }
        if (!live.lensSince) live.lensSince = now;
        if (now - live.lensSince < LENS_SEARCH_AFTER) return;
        live.lensView = lv.restarts || 0;
        live.lensBusy = true;
        var W = live.full.width, H = live.full.height, me = live, R = Math.hypot(W / 2, H / 2);
        var img = V.fromRGBA(live.full.getContext('2d').getImageData(0, 0, W, H).data, W, H);
        var lensOf = function (k) { return k ? {k: k, cx: W / 2, cy: H / 2, R: R} : null; };
        var now0 = lv.lens ? lv.lens.k : 0, errors = [], tried = {};
        // The grids a lens lets fit the competition's layouts: a wide-angle camera shows every
        // table under several lenses, but only the right one gives their whole outline.
        var layouts = lv.env.layouts && lv.env.layouts.length ? lv.env.layouts : [{ends: 6, arrows: 6, golds: true}];
        var round = function (ks) {
            ks = ks.filter(function (k) { return k >= 0 && !tried[k.toFixed(3)]; });
            return Promise.all(ks.map(function (k) {
                // Plain lenses (the live view's own kept the same way, its centre and size).
                var lens = lv.lens && Math.abs(k - now0) < 1e-9 ? {k: lv.lens.k, cx: lv.lens.cx, cy: lv.lens.cy, R: lv.lens.R} : lensOf(k);
                return window.OcrPool.grids(P, img, lens, want, layouts).then(function (c) {
                    tried[k.toFixed(3)] = {k: k, fitted: c.fitted, score: c.score};
                }, function (e) {
                    errors.push(String(e && e.message || e));
                    tried[k.toFixed(3)] = {k: k, fitted: -1, score: 0};
                });
            }));
        };
        var better = function (a, b) {
            return a.fitted > b.fitted || a.fitted === b.fitted && a.score > b.score + 1e-9;
        };
        var bestOf = function () {
            var best = null;
            Object.keys(tried).forEach(function (key) { if (!best || better(tried[key], best)) best = tried[key]; });
            return best;
        };
        var here = {k: now0, fitted: drawn.filter(function (d) { return d.fit; }).length, score: 0};
        // The lens of now first: all the grids fitting with it, the view was only slow to find them.
        var enough = function () { var c = tried[now0.toFixed(3)]; return c && c.fitted >= want; };
        round([now0]).then(function () {
            return enough() ? null : round(LENS_KS);
        }).then(function () {
            var b = bestOf();
            return b && !enough() ? round([b.k - LENS_STEP, b.k + LENS_STEP]) : null;
        }).then(function () {
            if (live !== me) return;
            live.lensBusy = false;
            var best = bestOf(), cur = tried[now0.toFixed(3)] || here;
            if (rec) recLog({f: 'lens', tried: Object.keys(tried).map(function (key) { return [tried[key].k, tried[key].fitted]; }),
                kept: best && best.fitted > cur.fitted ? best.k : null, errors: errors});
            // Kept only for more grids fitted than with the lens of now (all fitting with it: no search).
            if (enough()) return;
            if (!best || best.fitted <= cur.fitted) {
                live.lensFailed = (live.lensFailed || 0) + 1;
                return;
            }
            // Started afresh with it, as when the lens is measured (the results of the tasks
            // given out before are left out).
            var lens = lensOf(best.k);
            live.lensKept = true;
            storeLens(live.key, lens);
            live.worker.postMessage({id: 0, type: 'setLens', lens: lens});
            window.OcrLive.setLens(live.lv, lens);
            window.OcrLive.anew(live.lv);
        });
    }

    /**
     * Where to lay the sheet's grids, until they are found: the view greyed but there. Where
     * the last whole sheet's lay, else the cards per sheet side by side.
     */
    function placesVeil(g, poly, W, H, drawn) {
        var lv = live.lv, want = lv.expected || 1;
        if (drawn.filter(function (d) { return d.fit; }).length >= want) return;
        var kept = storedZones(), zones = kept && kept.length === want ? kept.map(function (z) {
            return z.map(function (p) { return {x: p[0] * W, y: p[1] * H}; });
        }) : [];
        if (!zones.length) {
            for (var i = 0; i < want; i++) {
                var x0 = W * (0.05 + 0.9 * i / want) + 0.01 * W, x1 = W * (0.05 + 0.9 * (i + 1) / want) - 0.01 * W;
                zones.push([{x: x0, y: 0.1 * H}, {x: x1, y: 0.1 * H}, {x: x1, y: 0.9 * H}, {x: x0, y: 0.9 * H}]);
            }
        }
        var c = g.canvas;
        g.save();
        g.fillStyle = 'rgba(32, 38, 61, 0.38)';
        g.fillRect(0, 0, c.width, c.height);
        g.globalCompositeOperation = 'destination-out';
        g.fillStyle = '#000';
        zones.forEach(function (z) {
            poly(z);
            g.fill();
        });
        g.restore();
    }

    // ------------------------------------------------------- the sheet's places

    // Where the grids of a whole sheet lay (each fitted), kept in this browser for the
    // competition and the camera: the places shown, greyed around, until the next sheet's grids
    // are found. Kept by itself, once per view (a sheet changed, the view started afresh).
    var ZONES_KEY = 'ocrZones:' + tourCode + ':';

    function zonesKey() {
        return ZONES_KEY + (state.stream ? lensKey(state.stream) : '');
    }

    function storedZones() {
        if (!state.stream) return null;
        try {
            return JSON.parse(window.localStorage.getItem(zonesKey()) || 'null');
        } catch (e) {
            return null;
        }
    }

    function keepZones(drawn) {
        var lv = live.lv, want = lv.expected || 0, fitted = drawn.filter(function (d) { return d.fit; });
        if (!want || fitted.length !== want || !stillView() || live.zonesView === (lv.restarts || 0)) return;
        live.zonesView = lv.restarts || 0;
        var W = live.full.width, H = live.full.height;
        try {
            window.localStorage.setItem(zonesKey(), JSON.stringify(fitted.map(function (d) {
                return d.outline.map(function (p) { return [+(p.x / W).toFixed(4), +(p.y / H).toFixed(4)]; });
            })));
        } catch (e) { /* this page only */ }
    }

    /** The view still enough to be read (the camera fixed: nothing moving). */
    function stillView() {
        var lv = live.lv;
        return (lv.sharp || 0) >= (lv.env.fixed ? 1 : OPEN_SHARP);
    }

    /**
     * What the live view knows of the sheet in the frame of the moment, for the photo
     * taken now: each grid's outline (full frame pixels, lens undone), its archer, and
     * the views of its cells.
     */
    function liveKnowledge() {
        if (!live || !live.lv.H) return null;
        var drawn = window.OcrLive.drawing(live.lv);
        return live.lv.grids.map(function (gr, i) {
            var views = {};
            (gr.cells || []).forEach(function (x) { if (x.views.length) views[x.key] = {views: x.views, arrow: x.cell.kind === 'arrow'}; });
            return {outline: drawn[i] ? drawn[i].outline : null, archer: gr.ident ? gr.ident.archer : null, cells: views, draw: drawn[i] || null};
        }).filter(function (s) { return s.outline; });
    }

    /**
     * The live view's knowledge of the grid of a photo's card, if it followed it: the outline
     * holding its centre (the nearest outline's middle if several), one no other card of the
     * sheet has taken already.
     */
    function seedFor(ctx) {
        if (!state.seed || !ctx.analysis) return null;
        // The photo's grids are found on a copy scaled down: back to the frame's pixels, the
        // live view's (else a card's centre falls in the outline of the one beside it).
        var c = centre(ctx.analysis), s = ctx.analysis.scale || 1, best = null, near = Infinity;
        c = {x: c.x / s, y: c.y / s};
        state.seed.forEach(function (sd) {
            var q = sd.outline, sign = 0, mx = 0, my = 0;
            for (var i = 0; i < 4; i++) {
                var a = q[i], b = q[(i + 1) % 4], cr = (b.x - a.x) * (c.y - a.y) - (b.y - a.y) * (c.x - a.x);
                mx += a.x / 4;
                my += a.y / 4;
                if (cr !== 0) {
                    if (sign && (cr > 0) !== (sign > 0)) return;
                    sign = cr;
                }
            }
            var taken = state.sheet.some(function (o) { return o !== ctx && o.seedUsed === sd; });
            var d = Math.hypot(mx - c.x, my - c.y);
            if (!taken && d < near) {
                near = d;
                best = sd;
            }
        });
        if (best) ctx.seedUsed = best;
        return best;
    }

    /** Another card of the sheet given the same archer (two cards of a sheet never are). */
    function sameArcher(ctx) {
        if (!ctx || !ctx.card) return null;
        return state.sheet.find(function (o) { return o !== ctx && o.card && o.card.archer.enId === ctx.card.archer.enId; }) || null;
    }

    // ------------------------------------------------------- cards from live

    // Once the live view knows whose cards the sheet holds (and their layout), they open by
    // themselves, as a photo's do: the frame of the moment read whole, put together with
    // what the video showed of each cell, then brought up to date as the camera goes on.
    var REFRESH_EVERY = 1500;   // ms at least between two updates of the cards opened
    var OPEN_SHARP = 0.6;       // the camera that still, for the frame the cards open from
    var HOLD_SHARP = 0.5;       // ...and below which no cell is read (the operator is told)
    var OPEN_WAIT = 5000;       // ms the last cards of the sheet are waited for, before those known open

    /**
     * The cards opened when every grid expected is known (or, the last ones slow to come,
     * those known) and the frame shows them whole, the camera still; opened again as more
     * become known, unless the operator has corrected one. -> true while they wait for the
     * camera to step back.
     */
    function autoOpen(r, drawn) {
        var lv = live.lv, want = lv.expected || lv.grids.length, now = performance.now();
        var ready = drawn.filter(function (d) { return d.fit && d.ident && d.card; });
        if (!ready.length) return false;
        if (!live.readyAt) live.readyAt = now;
        if (ready.length < want && now - live.readyAt < OPEN_WAIT) return false;
        var ids = ready.map(function (d) { return d.ident.archer.enId; }), key = ids.slice().sort().join(',');
        if (live.opened === key) return false;
        // The cards open corrected by the operator stay, unless the camera has gone on to
        // another sheet (none of its archers).
        var same = live.opened && state.sheet.some(function (ctx) { return ctx.card && ids.indexOf(ctx.card.archer.enId) >= 0; });
        var corrected = same && state.sheet.some(function (ctx) { return ctx.reading && Object.keys(ctx.reading.forced).length; });
        if (corrected) return false;
        // In the frame's own pixels: the outlines are straightened from the lens, which
        // carries a corner by the frame's edge out of it.
        var W = live.full.width, H = live.full.height;
        var whole = ready.every(function (d) {
            return d.outline.every(function (p) {
                var q = lv.lens ? V.lensBack(lv.lens, p.x, p.y) : p;
                return q.x >= 0 && q.y >= 0 && q.x <= W && q.y <= H;
            });
        });
        if (!whole) return true;
        if ((lv.sharp || 0) < OPEN_SHARP) return false;
        live.opened = key;
        fromLive(ready);
        return false;
    }

    function fromLive(ready) {
        var lv = live.lv, W = live.full.width, H = live.full.height;
        var c = document.createElement('canvas');
        c.width = W;
        c.height = H;
        var g = c.getContext('2d', {willReadFrequently: true});
        g.drawImage(live.full, 0, 0);
        state.gen++;
        state.working = true;
        state.source = c;
        state.full = V.fromRGBA(g.getImageData(0, 0, W, H).data, W, H);
        state.seed = null;
        state.seedLens = undefined;
        state.qr = state.qrOther = null;
        // No header read on this frame yet: a card read again later tries every way (reread).
        state.heads = null;
        // The live view's cells are straight (lens undone): so is the frame.
        if (lv.lens) {
            var data = g.getImageData(0, 0, W, H);
            state.full = V.undistort(state.full, lv.lens);
            data.data.set(V.undistortRGBA(data.data, W, H, lv.lens));
            g.putImageData(data, 0, 0);
        }
        state.sheet = ready.map(liveCtx);
        state.view = state.sheet[0];
        status([]);
        el('ocr-archer').innerHTML = '';
        el('ocr-cells').innerHTML = '';
        drawPhoto();
        renderSheet();
        if (rec) recLog({f: 'open', cards: state.sheet.map(function (ctx) { return ctx.card ? ctx.card.archer.enId : null; })});
        state.sheet.forEach(function (ctx) { if (!ctx.card) show(ctx, [{kind: 'warn', html: esc(t('HeaderUnsure'))}]); });
        var gen = state.gen, settle = function (p) { return p.then(null, function () { return null; }); };
        // What ianseo holds now (the live view's cards may date from a while ago), then every
        // card read at once: their layouts are known, the reading workers share the cells.
        Promise.all(state.sheet.map(function (ctx) {
            if (!ctx.card) return null;
            return settle(fetchCard(ctx.card.archer.enId, ctx.card.dist).then(function (c) { if (c && gen === state.gen) ctx.card = c; }));
        })).then(function () {
            if (gen !== state.gen) return null;
            return Promise.all(state.sheet.map(function (ctx) { return ctx.card ? settle(liveCard(ctx)) : null; }));
        }).then(function () {
            if (gen !== state.gen) return;
            state.working = false;
            offerArchers();
            if (rec) recLog({f: 'read', from: 'live', cards: state.sheet.map(function (ctx) { return {enId: ctx.card ? ctx.card.archer.enId : null, colour: cardState(ctx).colour}; })});
            renderSheet();
        });
    }

    /**
     * A card of the sheet as the live view knows it (d: an entry of OcrLive.drawing(), fitted
     * and identified): its layout as fitted there, what the video read of its cells.
     */
    function liveCtx(d, i) {
        var gr = live.lv.grids.find(function (o) { return o.id === d.id; }), views = {};
        (gr ? gr.cells : []).forEach(function (x) { views[x.key] = {views: x.views, arrow: x.cell.kind === 'arrow'}; });
        // The rows one line over, each way (OcrLayout.fit), read when the reading is in doubt.
        var asFit = function (f) { return {ok: true, printed: f.printed, cells: f.cells, frame: {Hfull: f.Hfull}}; };
        var alts = (d.alts || []).map(asFit), liveFit = asFit(d.fit);
        liveFit.alt = alts[0] || null;
        liveFit.alt2 = alts[1] || null;
        // Not identified (yet): it waits for the operator.
        var known = !!(d.ident && d.card);
        var at = d.outline.reduce(function (m, q) { return {x: m.x + q.x / 4, y: m.y + q.y / 4}; }, {x: 0, y: 0});
        return {n: i, analysis: null, live: d.id, at: at, msgs: [], step: known ? 'busy' : 'wait', card: known ? d.card : null, seedCells: views,
            liveLocked: gr && gr.locked || {}, liveFit: liveFit,
            header: known ? {archer: d.ident.archer, turn: null, position: false, place: d.ident.status === 'place'} : null};
    }

    /** An archer's card as ianseo holds it now. -> a promise of the card (null when not found). */
    function fetchCard(enId, dist) {
        return fetch(api, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=card&enId=' + encodeURIComponent(enId) + '&dist=' + encodeURIComponent(dist)})
            .then(function (r) { return r.json(); })
            .then(function (j) { return j && !j.error && j.card ? j.card : null; });
    }

    /** A card the live view knows: its layout as matched there, its cells read. */
    function liveCard(ctx) {
        // Told apart from the name read, or only by its place beside a card identified; or the
        // operator's choice.
        var card = ctx.card, h = ctx.header, msgs = [!h ? {kind: 'warn', html: esc(t('CheckArcher'))} : h.place
            ? {kind: 'warn', html: esc(t('HeaderByPlace', {target: card.archer.target, name: card.archer.name}))}
            : {kind: 'ok', html: esc(t('HeaderRead', {target: card.archer.target, name: card.archer.name}))}];
        el('ocr-dist').value = String(card.dist);
        ctx.fit = ctx.liveFit;
        msgs.push({kind: 'ok', html: esc(t('GridFound', {lines: ctx.fit.printed.lines}))});
        if (currentSession() && card.archer.session !== currentSession()) {
            msgs.push({kind: 'warn', html: esc(t('OtherSession', {session: card.archer.session, chosen: currentSession()}))});
        }
        ctx.reading = null;
        show(ctx, msgs);
        if (ctx === state.view) render(ctx);
        return readCells(ctx, msgs);
    }

    /** The cards opened, their cells put together again with the views the video gave since. */
    function liveRefresh() {
        var now = performance.now();
        if (live.refreshAt && now - live.refreshAt < REFRESH_EVERY) return;
        // Not under the operator's hand.
        if (document.getElementById('ocr-palette')) return;
        live.refreshAt = now;
        state.sheet.forEach(function (ctx) {
            var rd = ctx.reading, changed = false;
            if (!ctx.seedCells || !rd || !rd.photo || ctx.step !== 'done') return;
            ctx.fit.cells.forEach(function (c) {
                var key = c.line + '.' + c.key, sd = ctx.seedCells[key], ph = rd.photo[key];
                if (!sd || !ph || !sd.views.length) return;
                var before = rd.fusedWith[key] || [];
                if (sd.views.length === before.length && sd.views.every(function (v) { return before.indexOf(v) >= 0; })) return;
                rd.fusedWith[key] = sd.views.slice();
                rd.reads[key] = window.OcrLive.fuse(sd.views.concat([ph]), c.kind === 'arrow' ? 'label' : 'value', c.kind === 'arrow' ? 0 : 6);
                changed = true;
            });
            var locks = Object.keys(ctx.liveLocked || {}).length;
            if (locks !== rd.locked) changed = true;
            rd.locked = locks;
            lockLive(ctx, rd.reads);
            if (!changed) return;
            var a = assemble(ctx.fit.printed, rd.reads);
            rd.lines = a.lines;
            rd.total = a.total;
            resolveReading(ctx);
        });
    }

    // ------------------------------------------------------------ trial record

    // Option "record": the camera's video and what the page made of it, sent to this
    // server piece by piece (api/trace.php), for the trial to be looked at again.
    var REC_SLICE = 2000;   // ms of video in each piece sent
    var REC_FLUSH = 2000;   // ms between two sends of the log
    var rec = null;

    function recPost(fields, part) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        if (part) fd.append('part', part, 'part.webm');
        fd.append('csrf', root.getAttribute('data-csrf') || '');
        return fetch(root.getAttribute('data-trace-api'), {method: 'POST', credentials: 'same-origin', body: fd})
            .then(function (r) { return r.json(); });
    }

    function recState(text, bad) {
        var b = el('ocr-rec');
        if (!b) return;
        b.hidden = !text;
        b.textContent = text || '';
        b.className = 'ocr-rec' + (bad ? ' ocr-rec-bad' : '');
    }

    function recStart() {
        recStop();
        if (!readOpts.record || !state.stream || !window.MediaRecorder || !root.getAttribute('data-trace-api')) return;
        var tr = state.stream.getVideoTracks()[0], st = tr && tr.getSettings ? tr.getSettings() : {};
        var meta = {camera: tr ? tr.label : '', width: st.width || 0, height: st.height || 0, agent: navigator.userAgent,
            options: readOpts, grids: expectedGrids(), dist: currentDist(), session: currentSession(),
            lens: live ? live.lv.lens : null};
        var me = rec = {id: null, seq: 0, lines: [], t0: performance.now(), failed: false};
        recPost({action: 'start', meta: JSON.stringify(meta)}).then(function (j) {
            if (rec !== me) return;
            if (j.error) throw new Error(j.msg);
            me.id = j.id;
            var type = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'].filter(function (m) {
                return MediaRecorder.isTypeSupported(m);
            })[0];
            me.mr = new MediaRecorder(state.stream, type ? {mimeType: type, videoBitsPerSecond: 6000000} : {});
            me.mr.ondataavailable = function (e) {
                if (!e.data || !e.data.size) return;
                recPost({action: 'video', id: me.id, seq: me.seq++}, e.data).then(function (r) {
                    if (r.error) recFail(me);
                }, function () { recFail(me); });
            };
            // The log's times count from the video's start.
            me.t0 = performance.now();
            me.mr.start(REC_SLICE);
            me.timer = setInterval(function () { recFlush(me); }, REC_FLUSH);
            recLog({f: 'start', lens: live ? live.lv.lens : null, expected: expectedGrids(), cores: navigator.hardwareConcurrency || null,
                pool: pool ? pool.workers.length : null});
            recState(t('RecOn'));
        }).catch(function () { recFail(me); });
    }

    function recFail(me) {
        if (me.failed) return;
        me.failed = true;
        if (rec === me) recState(t('RecFailed'), true);
    }

    /** One line of the trial's log: what happened and when (s since the video began). */
    function recLog(o) {
        if (!rec || !rec.id) return;
        o.t = Math.round(performance.now() - rec.t0) / 1000;
        rec.lines.push(JSON.stringify(o));
    }

    function recFlush(me) {
        if (!me.id || !me.lines.length) return;
        var text = me.lines.splice(0).join('\n') + '\n';
        recPost({action: 'log', id: me.id, lines: text}).then(function (r) {
            if (r.error) recFail(me);
        }, function () { recFail(me); });
    }

    function recStop() {
        if (!rec) return;
        var me = rec;
        rec = null;
        if (me.timer) clearInterval(me.timer);
        // The last piece of video comes before the recorder stops; the log after it.
        if (me.mr && me.mr.state !== 'inactive') {
            me.mr.onstop = function () { recFlush(me); };
            me.mr.stop();
        } else {
            recFlush(me);
        }
        recState('');
    }

    /** What a worker's result told, for the trial's log. */
    function resultLog(res, out) {
        var o = {f: 'result', id: res.id, type: res.type};
        if (res.error) o.error = res.error;
        if (res.type === 'grids') {
            o.found = (res.grids || []).length;
            o.known = live.lv.grids.length;
            if (res.helpersLate) o.helpersLate = true;
        } else if (res.type === 'header') {
            o.cards = (res.cards || []).map(function (c) {
                return {id: c.id, status: c.status, seen: c.seen || null, archer: c.archer ? c.archer.enId : null,
                    guess: c.guess ? {archer: c.guess.archer.enId, gap: +c.guess.gap.toFixed(2)} : null};
            });
        } else if (res.type === 'cells') {
            o.reads = (res.reads || []).map(function (r) {
                var top = r.reading && r.reading[0];
                return [r.card, r.key, top ? (top.label !== undefined ? top.label : top.value) : null, top ? +top.p.toFixed(3) : 0];
            });
        } else if (res.type === 'solve' && res.sol) {
            o.card = res.card;
            o.agreement = res.sol.agreement;
        }
        if (res.refined) o.refined = res.refined.map(function (r) { return [r.card, r.seen]; });
        if (out.anew) o.anew = out.anew;
        if (out.lens !== undefined) o.lens = out.lens;
        if (out.idents && out.idents.length) o.idents = out.idents.map(function (g) { return [g.id, g.ident.archer.enId, g.ident.status]; });
        return o;
    }

    // ---------------------------------------------------------------- analysis

    /** Centre of a grid in the working image. */
    function centre(a) {
        var q = a.quad;
        return {x: (q.tl.x + q.tr.x + q.br.x + q.bl.x) / 4, y: (q.tl.y + q.tr.y + q.br.y + q.bl.y) / 4};
    }

    /** The grids of a sheet as they are laid out: left to right, or top to bottom when stacked. */
    function order(grids) {
        return grids.slice().sort(function (p, q) {
            var bp = p.gridBox, bq = q.gridBox;
            var overlap = Math.min(bp.x1, bq.x1) - Math.max(bp.x0, bq.x0);
            var stacked = overlap > 0.5 * Math.min(bp.x1 - bp.x0, bq.x1 - bq.x0);
            return stacked ? centre(p).y - centre(q).y : centre(p).x - centre(q).x;
        });
    }

    /**
     * The browser's own barcode reader, where there is one (Chrome on Android among
     * others): faster and sturdier than ours. -> [{text, x, y}] in full-photo pixels.
     */
    function nativeBarcodes() {
        if (!('BarcodeDetector' in window)) return Promise.resolve([]);
        return window.BarcodeDetector.getSupportedFormats().then(function (formats) {
            if (formats.indexOf('code_39') < 0) return [];
            return new window.BarcodeDetector({formats: ['code_39']}).detect(state.source);
        }).then(function (found) {
            var scale = state.full.w / state.source.width, out = [];
            (found || []).forEach(function (b) {
                var text = String(b.rawValue || '').replace(/\*/g, '');
                if (text.indexOf('-') < 0 || !b.cornerPoints) return;
                var x = 0, y = 0;
                b.cornerPoints.forEach(function (p) {
                    x += p.x * scale / 4;
                    y += p.y * scale / 4;
                });
                out.push({text: text, x: x, y: y});
            });
            return out;
        }).catch(function () { return []; });
    }

    /**
     * The code printed under one grid, among those the browser read, and the way up it
     * gives: the code lies under the body of the grid, on its left.
     */
    function codeFor(a, codes) {
        var turns = V.turns(a);
        for (var i = 0; i < codes.length; i++) {
            for (var k = 0; k < turns.length; k++) {
                var f = V.frame(a, turns[k]), inv = f && V.invert(f.Hfull);
                if (!inv) continue;
                var q = V.apply(inv, codes[i].x, codes[i].y);
                if (q.y > f.Hb && q.y < f.Hb + 2 * f.rowH && q.x > -0.2 * f.W && q.x < 0.85 * f.W) {
                    return {text: codes[i].text, turn: turns[k]};
                }
            }
        }
        return null;
    }

    function analyse() {
        // A wide-angle webcam bows the lines: the picture is straightened first, the one
        // shown with it, so that the frames drawn on it match.
        // The live view's lens when the photo comes from it (measured already).
        var tm = state.times, t1 = performance.now(), lens = state.seedLens !== undefined ? state.seedLens : V.lensOf(state.full);
        if (lens) {
            var g = state.source.getContext('2d'), data = g.getImageData(0, 0, state.source.width, state.source.height);
            state.full = V.undistort(state.full, lens);
            data.data.set(V.undistortRGBA(data.data, data.width, data.height, lens));
            g.putImageData(data, 0, 0);
        }
        var t2 = performance.now(), gen = state.gen, found = analysis(state.full, {all: true, want: expectedGrids()});
        tm.lens = Math.round(t2 - t1);
        // The sheet's codes read here meanwhile (the workers analyse it).
        var P = poolUp(), far = P && window.OcrPool.qr ? function (d, w, h) { return window.OcrPool.qr(P, d, w, h); } : null;
        var codes = Promise.all([nativeBarcodes(), Q ? Q.read(state.source, far).catch(function () { return null; }) : null]);
        Promise.all([found, loadLayouts().then(null, function () { return []; })]).then(function (r) {
            if (gen !== state.gen) return;
            var a = r[0], grids = a.ok ? order(sheetGrids(a.grids, r[1], expectedGrids())) : [];
            tm.analyse = Math.round(performance.now() - t2);
            // Grids the live view had fitted and identified, that the picture's own analysis missed
            // (a bowed or soft picture: one look against the many of the live view): read from the
            // live view's fits, not left out.
            var missed = missedSeeds(grids);
            if (!grids.length && !missed.length) {
                state.working = false;
                drawPhoto();
                status([{kind: 'err', html: esc(t('GridNotFound')) + ' ' + researchButton()}]);
                return;
            }
            status([]);
            state.sheet = grids.map(function (g, i) {
                var c = centre(g), sc = g.scale || 1;
                return {n: i, analysis: g, msgs: [], step: 'busy', at: {x: c.x / sc, y: c.y / sc}};
            })
                .concat(missed.map(function (d, j) { return liveCtx(d, grids.length + j); }));
            state.view = state.sheet[0];
            drawPhoto();
            renderSheet();
            codes.then(function (r) {
                if (gen !== state.gen) return;
                useQR(r[1]);
                // Each card's barcode looked for by the workers, beside its header (identify()).
                state.sheet.forEach(function (ctx) {
                    if (ctx.analysis) ctx.barcodeP = barcodeOf(ctx.analysis, codeFor(ctx.analysis, r[0]));
                });
                if (rec) state.times.codes = Math.round(performance.now() - state.times.at);
                readSheet(gen);
            });
        });
    }

    /**
     * More grids found than the sheet holds (a keyboard's keys beside it, part of a table): the
     * ones that fit one of the competition's layouts best (as the live view keeps them), as many
     * as wanted.
     */
    function sheetGrids(grids, lays, want) {
        if (!want || grids.length <= want || !lays || !lays.length) return grids;
        var rated = grids.map(function (g) {
            var best = null, b = g.gridBox;
            lays.forEach(function (lay) {
                var f = Lay.fit(V, g, lay, null);
                if (f.ok && (!best || f.score > best.score)) best = f;
            });
            return {g: g, fits: best ? 1 : 0, score: best ? best.score : 0, area: (b.x1 - b.x0) * (b.y1 - b.y0)};
        });
        var keep = rated.slice().sort(function (p, q) { return q.fits - p.fits || q.score - p.score || q.area - p.area; }).slice(0, want);
        return grids.filter(function (g) { return keep.some(function (k) { return k.g === g; }); });
    }

    /**
     * A card's barcode (OcrBarcode.find): the one the browser read, else looked for by a reading
     * worker, else here. -> a promise of it (null when none).
     */
    function barcodeOf(a, native) {
        if (native) return Promise.resolve(native);
        var P = poolUp(), full = state.full, here = function () { return B.find(V, full, a); };
        if (!P || !window.OcrPool.barcode) return new Promise(function (done) { setTimeout(function () { done(here()); }, 0); });
        var frames = V.turns(a).map(function (turn) {
            var f = V.frame(a, turn);
            return f ? {turn: turn, W: f.W, Hb: f.Hb, rowH: f.rowH, Hfull: f.Hfull} : null;
        }).filter(Boolean);
        return window.OcrPool.barcode(P, full, frames).catch(here);
    }

    /**
     * A photo's grids (OcrVision.analyse): shared among the reading workers once they are up,
     * else found here. -> a promise of the analysis.
     */
    function analysis(img, opts) {
        var P = poolUp(), here = function () { return V.analyse(img, opts); };
        if (state.times) state.times.shared = !!(P && window.OcrPool.analyse);
        if (!P || !window.OcrPool.analyse) return new Promise(function (done) { setTimeout(function () { done(here()); }, 0); });
        // Workers that do not answer: the analysis done here (once, whichever comes first).
        return new Promise(function (done) {
            var over = false, end = function (a) {
                if (over) return;
                over = true;
                done(a);
            };
            var late = setTimeout(function () {
                if (state.times) state.times.late = true;
                end(here());
            }, POOL_WAIT);
            window.OcrPool.analyse(P, img, opts).then(function (a) {
                clearTimeout(late);
                end(a);
            }, function () {
                clearTimeout(late);
                end(here());
            });
        });
    }

    var POOL_WAIT = 20000;   // ms a photo's analysis waits for the workers at most

    /** The reading workers when one at least is up (its networks loaded), else null: the page works on its own. */
    function poolUp() {
        var P = readPool();
        return P && !P.broken && P.workers.some(function (s) { return s.ready; }) ? P : null;
    }

    /**
     * The sheet's ISK code, when it is of this competition: its session and distance
     * become the page's, and its target narrows down whose cards these are.
     */
    function useQR(qr) {
        state.qr = null;
        if (!qr) return;
        if (tourCode && qr.info.code !== tourCode) {
            state.qrOther = qr.info.code;
            return;
        }
        state.qr = qr.info;
        var d = el('ocr-dist'), s = el('ocr-session');
        if (qr.info.dist && d.querySelector('option[value="' + qr.info.dist + '"]')) d.value = String(qr.info.dist);
        if (qr.info.session && s && s.querySelector('option[value="' + qr.info.session + '"]')) s.value = String(qr.info.session);
    }

    /** The archers of the sheet's target, from its ISK code, letter by letter. */
    function qrArchers(list) {
        var q = state.qr;
        if (!q || !q.target) return [];
        return list.filter(function (a) { return a.session === q.session && parseInt(a.target, 10) === q.target; })
            .sort(function (p, r) { return p.target < r.target ? -1 : p.target > r.target ? 1 : 0; });
    }

    /**
     * The sheet's cards all at once (one waiting for the operator holds none of the others):
     * whose each is (their headers read side by side), their cards asked of ianseo, fitted in
     * the sheet's order (a card takes the way of those fitted before it), then read together
     * (the reading workers share their cells, a card's arithmetic done while the next is read).
     */
    function readSheet(gen) {
        var settle = function (p) { return Promise.resolve(p).then(null, function () { return null; }); };
        var t0 = performance.now(), whose = 0;
        state.heads = headsWay();
        var asked = state.sheet.map(function (ctx) { return settle(ctx.live ? null : identify(ctx)); });
        state.heads.seal();
        Promise.all(asked).then(function (params) {
            if (gen !== state.gen) return null;
            whose = performance.now() - t0;
            return Promise.all(state.sheet.map(function (ctx, i) {
                return settle(ctx.live ? fetchCard(ctx.card.archer.enId, ctx.card.dist) : params[i] && ask(ctx, params[i]));
            })).then(function (got) {
                if (gen !== state.gen) return null;
                // A card the live view had, the picture's analysis not: as ianseo holds it now, then read.
                return Promise.all(state.sheet.map(function (ctx, i) {
                    if (!ctx.live) return settle(got[i] && answer(ctx, got[i], params[i]));
                    if (got[i]) ctx.card = got[i];
                    return settle(liveCard(ctx));
                }));
            });
        }).then(function () {
            if (gen !== state.gen) return;
            state.working = false;
            offerArchers();
            if (rec) {
                var tm = state.times;
                recLog({f: 'read', cards: state.sheet.map(function (ctx) {
                    var w = writeState(ctx);
                    return {enId: ctx.card ? ctx.card.archer.enId : null, colour: cardState(ctx).colour, write: w ? w.done || w.kind : null, blocked: w && w.blocked || '',
                        cells: ctx.reading ? Math.round(ctx.reading.time * 1000) : null};
                }), ms: {lens: tm.lens, analyse: tm.analyse, shared: tm.shared, late: tm.late || false, codes: tm.codes, whose: Math.round(whose), all: Math.round(performance.now() - tm.at)}});
            }
            renderSheet();
        });
    }

    /**
     * The cards whose header could not be read are offered the archers left on the target of
     * the sheet's other cards (none typed any more: a click gives one).
     */
    function offerArchers() {
        var known = state.sheet.filter(function (c) { return c.card; });
        if (!known.length || !roster) return;
        var a = known[0].card.archer, target = parseInt(a.target, 10), used = known.map(function (c) { return c.card.archer.enId; });
        state.sheet.forEach(function (ctx) {
            if (ctx.card || ctx.step !== 'wait') return;
            ctx.pick = roster.filter(function (r) {
                return parseInt(r.target, 10) === target && r.session === a.session && used.indexOf(r.enId) < 0;
            });
        });
    }

    /** The live view's cards (fitted, identified) whose grid the picture's analysis did not find. -> their drawing entries. */
    function missedSeeds(grids) {
        if (!state.seed) return [];
        return state.seed.filter(function (sd) {
            var d = sd.draw;
            if (!d || !d.fit || !d.ident || !d.card) return false;
            return !grids.some(function (g) {
                var c = centre(g), s = g.scale || 1;
                return inside(sd.outline, {x: c.x / s, y: c.y / s});
            });
        }).map(function (sd) { return sd.draw; });
    }

    /** A point within a convex quadrilateral (its corners in order). */
    function inside(q, c) {
        var sign = 0;
        for (var i = 0; i < 4; i++) {
            var a = q[i], b = q[(i + 1) % 4], cr = (b.x - a.x) * (c.y - a.y) - (b.y - a.y) * (c.x - a.x);
            if (cr !== 0) {
                if (sign && (cr > 0) !== (sign > 0)) return false;
                sign = cr;
            }
        }
        return true;
    }

    /** Whose card a grid is. -> a promise of what to ask ianseo for it (null: it waits for the operator). */
    function identify(ctx) {
        ctx.header = null;
        // Followed live and known: no need to read it again.
        var seed = seedFor(ctx);
        ctx.seedCells = seed ? seed.cells : null;
        if (seed && seed.archer) {
            ctx.header = {archer: seed.archer, turn: null, position: false};
            return Promise.resolve({action: 'card', enId: seed.archer.enId, dist: currentDist()});
        }
        // Its header read, its barcode looked for meanwhile: a code read tells first.
        var gen = state.gen, reading = readHeader(ctx);
        return Promise.resolve(ctx.barcodeP || ctx.barcode || null).then(null, function () { return null; }).then(function (code) {
            if (gen !== state.gen) return null;
            if (code) {
                ctx.barcode = code;
                return {action: 'lookup', code: code.text, dist: currentDist()};
            }
            return reading.then(function (id) { return gen === state.gen && id !== undefined ? headerResult(ctx, id) : null; });
        });
    }

    /** The competition's archers as their scorecards print them, fetched once. */
    function loadRoster() {
        if (roster) return Promise.resolve(roster);
        return fetch(api, {method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=roster'})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) throw new Error(j.msg);
                roster = j.roster;
                return roster;
            });
    }

    /**
     * No barcode: the target and the name printed above the table tell whose card it
     * is, among the competition's archers, the target first and the name to check it.
     * Each way up is tried until one reads well.
     */
    /** A card's header read: whose card it tells (headerOf). -> a promise of it (undefined: the networks failed, told). */
    function readHeader(ctx) {
        show(ctx, [busyLine(t('ReadingHeader'))]);
        var gen = state.gen, way = state.heads ? state.heads.join() : null;
        return Promise.all([loadModels(), loadRoster()]).then(function (r) {
            return gen === state.gen ? headerOf(ctx.analysis, ctx.n, state.sheet.length, r[0].text, r[1], gen, way) : null;
        }).then(null, function () {
            if (way) way.tried();
            ctx.step = 'wait';
            show(ctx, [{kind: 'err', html: esc(t('ModelError'))}]);
            return undefined;
        });
    }

    /**
     * The way a picture's cards face, from their headers read side by side (headerOf): first()
     * gives the way of the first card read for sure, or null once every card's first try is
     * over without one.
     */
    function headsWay() {
        var w = {joined: 0, over: 0, sealed: false, turn: null, done: false, give: null};
        w.first = new Promise(function (r) { w.give = r; });
        var check = function () {
            if (w.done || w.turn === null && !(w.sealed && w.over >= w.joined)) return;
            w.done = true;
            w.give(w.turn);
        };
        // A card taking part: it tells once whether its first try read for sure (whatever happens).
        w.join = function () {
            var told = false;
            w.joined++;
            var tell = function (turn) {
                if (told) return;
                told = true;
                w.over++;
                if (turn !== null && w.turn === null) w.turn = turn;
                check();
            };
            return {first: w.first, found: tell, tried: function () { tell(null); }};
        };
        w.seal = function () {
            w.sealed = true;
            check();
        };
        return w;
    }

    /**
     * A grid's printed header read one way up: by a reading worker when there are (the sheet's
     * headers side by side), else here (the page painted first). -> a promise of the reading.
     */
    function headerRead(a, turn, net, list, few) {
        var f = V.frame(a, turn), P = readPool(), full = state.full;
        if (!f) return Promise.resolve({read: null, who: null});
        var here = function () {
            return new Promise(function (done) {
                setTimeout(function () { done({read: X.readHeader(V, net, full, f), who: null}); }, 20);
            });
        };
        if (!P || P.broken) return here();
        // Whose card, found by the worker too (the competition's archers sent it once).
        window.OcrPool.roster(P, list);
        var whose = {few: few.map(function (r) { return r.enId; }), fewSession: state.qr ? state.qr.session : 0, session: currentSession()};
        return window.OcrPool.header(P, full, {W: f.W, Hb: f.Hb, rowH: f.rowH, Hfull: f.Hfull}, whose).catch(here);
    }

    /**
     * Whose card a grid is, from its printed header, each way up until one reads well;
     * with the sheet's ISK code, among that target's archers first, then all of them
     * (the target may have changed since the sheets were printed). Nothing read on the
     * card itself: its place on the sheet (n of count) gives its letter, as long as the
     * sheet holds as many cards as the target has archers.
     */
    function headerOf(a, n, count, net, list, gen, way) {
        var rank = {ok: 3, session: 2, name: 1, unsure: 0}, best = null, few = qrArchers(list), firstTurn = null, turns = V.turns(a);
        var once = function (turn) {
            return headerRead(a, turn, net, list, few).then(function (got) {
                var read = got.read, id = got.who;
                if (!read) return false;
                if (firstTurn === null) firstTurn = turn;
                if (!id) {
                    id = few.length ? X.identify(net, read, few, state.qr.session) : null;
                    if (!id || id.status !== 'ok') id = X.identify(net, read, list, currentSession());
                }
                id.turn = turn;
                if (!best || rank[id.status] > rank[best.status]) best = id;
                return id.status === 'ok';
            });
        };
        var step = function (k) {
            if (k >= turns.length || gen !== state.gen) return null;
            return once(turns[k]).then(function (ok) { return ok ? null : step(k + 1); });
        };
        // The sheet's cards all face one way: the most likely way of each first, side by side;
        // a card not read then, the way another one was read (alone); none read, every other way.
        var first = !way ? step(0) : once(turns[0]).then(function (ok) {
            if (ok) way.found(turns[0]);
            else way.tried();
            return ok ? null : way.first.then(function (turn) {
                if (gen !== state.gen) return null;
                if (turn === null) return step(1);
                return turn === turns[0] ? null : once(turn);
            });
        }, function (e) {
            way.tried();
            throw e;
        });
        return Promise.resolve(first).then(function () {
            if ((!best || best.status === 'unsure') && few.length && few.length === count) {
                best = {status: 'position', archer: few[n], turn: firstTurn, read: best ? best.read : {}};
            }
            return best;
        });
    }

    /**
     * What the header told. A card of the chosen session opens; one found in another
     * session, or a name that is not that of the target's archer, waits for the
     * operator's word (a button, handled on the status area).
     */
    function headerResult(ctx, id) {
        if (!id || id.status === 'unsure') {
            ctx.step = 'wait';
            var seen = id && (id.read.target || id.read.name) ? ' ' + t('HeaderSeen', {target: id.read.target, name: id.read.name}) : '';
            show(ctx, [{kind: 'warn', html: esc(t('HeaderUnsure') + seen)}]);
            return null;
        }
        var a = id.archer;
        ctx.header = {archer: a, turn: id.turn, position: id.status === 'position'};
        if (id.status === 'ok' || id.status === 'position') return {action: 'card', enId: a.enId, dist: currentDist()};
        var text, label;
        if (id.status === 'session') {
            text = t('HeaderSession', {name: a.name, target: a.target, session: a.session, chosen: currentSession()});
            label = t('HeaderUseSession', a.session);
        } else {
            text = t('HeaderConflict', {target: id.target, name: a.name, other: a.target, session: a.session});
            label = t('HeaderUseArcher', a.name);
        }
        ctx.step = 'wait';
        show(ctx, [{kind: 'warn', html: esc(text) + ' <button type="button" class="ocr-btn" data-use="' + a.enId + '"'
            + (id.status === 'session' ? ' data-session="' + a.session + '"' : '') + '>' + esc(label) + '</button>'}]);
        return null;
    }

    el('ocr-status').addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('button[data-research]')) {
            researchSheet();
            return;
        }
        var b = e.target.closest && e.target.closest('button[data-use]'), ctx = state.view;
        if (!b || !ctx) return;
        var s = el('ocr-session');
        if (b.getAttribute('data-session') && s) s.value = b.getAttribute('data-session');
        lookup(ctx, {action: 'card', enId: b.getAttribute('data-use'), dist: currentDist()});
    });

    function currentDist() {
        return parseInt(el('ocr-dist').value, 10) || 1;
    }

    function currentSession() {
        var s = el('ocr-session');
        return s ? parseInt(s.value, 10) || 0 : 0;
    }

    el('ocr-find').addEventListener('submit', function (e) { e.preventDefault(); });

    function lookup(ctx, params) {
        return ask(ctx, params).then(function (j) { return j ? answer(ctx, j, params) : null; });
    }

    /** A card asked of ianseo. -> a promise of the answer (null: the photo changed, or no network, told). */
    function ask(ctx, params) {
        var gen = state.gen, body = Object.keys(params).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        ctx.step = 'busy';
        renderSheet();
        return fetch(api, {method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body})
            .then(function (r) { return r.json(); })
            .then(function (j) { return gen === state.gen ? j : null; }, function () {
                if (gen !== state.gen) return null;
                ctx.step = 'wait';
                show(ctx, [{kind: 'err', html: esc(t('ErrNetwork'))}]);
                return null;
            });
    }

    /** What ianseo answered for a card: the card fitted and read, or why not. */
    function answer(ctx, j, params) {
        if (j.error) {
            ctx.step = 'wait';
            show(ctx, [{kind: 'err', html: esc(j.msg || t('ErrRequest'))}]);
            return null;
        }
        // A card of the live view (no analysis of its own): read on the live view's fit.
        if (ctx.live && j.card) {
            ctx.card = j.card;
            ctx.candidates = null;
            ctx.pick = null;
            return liveCard(ctx);
        }
        if (j.candidates) {
            ctx.step = 'wait';
            ctx.candidates = {list: j.candidates, dist: j.dist || params.dist};
            ctx.card = ctx.fit = ctx.reading = null;
            show(ctx, []);
            if (ctx === state.view) render(ctx);
            return null;
        }
        return showCard(ctx, j.card);
    }

    el('ocr-archer').addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('button[data-en]'), ctx = state.view;
        if (!b || !ctx || !ctx.candidates) return;
        lookup(ctx, {action: 'card', enId: b.getAttribute('data-en'), dist: ctx.candidates.dist || currentDist()});
    });

    /**
     * The way the other cards of the photo face (header up), from their fits, one whose
     * barcode or header told it weighing more; null while unknown or at odds.
     */
    function sheetWay(ctx) {
        var sx = 0, sy = 0, all = 0;
        (state.sheet || []).forEach(function (o) {
            if (o === ctx || !o.analysis || !o.fit || !o.fit.ok) return;
            var u = Lay.upOf(V, o.analysis, o.fit.turn), k = o.barcode || o.header ? 3 : 1;
            if (!u) return;
            sx += k * u.x;
            sy += k * u.y;
            all += k;
        });
        var d = Math.hypot(sx, sy);
        return all && d >= 0.5 * all ? {x: sx / d, y: sy / d} : null;
    }

    function showCard(ctx, card) {
        ctx.card = card;
        ctx.candidates = null;
        el('ocr-dist').value = String(card.dist);
        var msgs = [];
        var byHeader = ctx.header && ctx.header.archer.enId === card.archer.enId;
        if (state.qr) msgs.push({kind: 'ok', html: esc(t('QrRead', {target: state.qr.target, session: state.qr.session, dist: state.qr.dist}))});
        else if (state.qrOther) msgs.push({kind: 'warn', html: esc(t('QrOther', state.qrOther))});
        if (ctx.barcode) msgs.push({kind: 'ok', html: esc(t('BarcodeRead', ctx.barcode.text))});
        else if (byHeader && ctx.header.position) msgs.push({kind: 'warn', html: esc(t('QrPosition', {target: card.archer.target, name: card.archer.name}))});
        else if (byHeader) msgs.push({kind: 'ok', html: esc(t('HeaderRead', {target: card.archer.target, name: card.archer.name}))});
        var s = Object.assign({dist: card.dist}, card.structure);
        var bt = ctx.barcode ? ctx.barcode.turn : byHeader ? ctx.header.turn : null, way = null;
        // All cards of a sheet face one way: unless its barcode or header told it, the card
        // takes the way of the others fitted (never the other way).
        if (bt === null && (way = sheetWay(ctx))) bt = Lay.turnFacing(V, ctx.analysis, way);
        var f = Lay.fit(V, ctx.analysis, s, bt);
        if (!f.ok && bt !== null && !way) f = Lay.fit(V, ctx.analysis, s, null);
        ctx.fit = f;
        var p = Lay.printed(s.ends, s.arrows, s.tourType);
        if (f.ok) {
            msgs.push({kind: 'ok', html: esc(t('GridFound', {lines: p.lines})) + (f.grand ? ' ' + esc(t('LayoutTotals')) : '')});
        } else {
            msgs.push({kind: 'err', html: esc(t('LayoutMismatch', {dist: card.dist, lines: p.lines, arrows: p.perLine}))});
        }
        if (!ctx.barcode && !byHeader) msgs.push({kind: 'warn', html: esc(t('CheckArcher'))});
        if (sameArcher(ctx)) msgs.push({kind: 'err', html: esc(t('SameArcher', card.archer.name))});
        // Whatever told whose card it is, say so when it is not of the session chosen.
        if (currentSession() && card.archer.session !== currentSession()) {
            msgs.push({kind: 'warn', html: esc(t('OtherSession', {session: card.archer.session, chosen: currentSession()}))});
        }
        ctx.reading = null;
        ctx.step = f.ok ? 'busy' : 'wait';
        show(ctx, msgs);
        if (ctx === state.view) render(ctx);
        return f.ok ? readCells(ctx, msgs) : null;
    }

    // ---------------------------------------------------------------- reading

    /** The reading networks, fetched once. */
    function loadModels() {
        var get = function (attr) {
            return fetch(root.getAttribute(attr), {credentials: 'same-origin'}).then(function (r) { return r.json(); });
        };
        var ready = models ? Promise.resolve(models) : Promise.all([get('data-arrow-model'), get('data-digit-model'), get('data-text-model')]).then(function (d) {
            models = {arrow: R.load(d[0]), digit: R.load(d[1]), text: X.load(d[2])};
            return models;
        });
        // The sequence network, when there is one.
        return ready.then(function (m) {
            if (m.number || !root.getAttribute('data-number-model')) return m;
            return get('data-number-model').then(function (d) {
                m.number = X.load(d);
                return m;
            }, function () { return m; });
        });
    }

    // Workers reading the photo's cells side by side (pool.js): the computer's cores but the
    // page's (and the live view's on a computer with more than four).
    var POOL_MAX = 8;
    var POOL_SMALL = 2;   // ...on a tablet, a phone, a computer of little memory
    var pool = null;

    function readPool() {
        if (pool || !window.OcrPool || !window.Worker || !root.getAttribute('data-read-worker')) return pool;
        var cores = navigator.hardwareConcurrency || 2, most = smallDevice() ? POOL_SMALL : POOL_MAX, born = performance.now();
        pool = window.OcrPool.create({url: root.getAttribute('data-read-worker'), size: Math.max(1, Math.min(most, cores - (cores > 4 ? 2 : 1))),
            onReady: function (i) { if (rec) recLog({f: 'up', who: 'pool ' + i, ms: Math.round(performance.now() - born)}); },
            models: {arrow: root.getAttribute('data-arrow-model'), digit: root.getAttribute('data-digit-model'),
                number: root.getAttribute('data-number-model') || null, text: root.getAttribute('data-text-model')}});
        if (rec) recLog({f: 'pool', cores: cores, size: pool.workers.length});
        return pool;
    }

    /** The reading options as a worker takes them (the networks are its own). */
    function plainOpts(o) {
        return {own: !!o.own, cut: !!o.cut, reframe: !!o.reframe, mix: !!o.mix, seq: !!o.seq};
    }

    /**
     * Cells of the photo read: by the workers when there are, else here a slice at a time
     * (the page going on between). -> a promise of their readings, in the list's order.
     */
    function readList(m, o, Hf, list, bound) {
        var items = list.map(function (c) { return c.kind === 'arrow' ? c : bound(c); }), img = state.full;
        var here = function () {
            return new Promise(function (resolve) {
                var out = [];
                var slice = function () {
                    var t0 = Date.now();
                    while (out.length < items.length && Date.now() - t0 < READ_SLICE) {
                        var c = items[out.length];
                        out.push(c.kind === 'arrow' ? R.readArrow(V, m.arrow, img, Hf, c, o) : R.readNumber(V, m.digit, img, Hf, c, 0, o));
                    }
                    if (out.length < items.length) setTimeout(slice, 0);
                    else resolve(out);
                };
                setTimeout(slice, 0);
            });
        };
        var P = readPool();
        if (!items.length) return Promise.resolve([]);
        if (!P || P.broken) return here();
        return window.OcrPool.read(P, img, Hf, items, plainOpts(o)).catch(here);
    }

    /**
     * Read every cell, then let the arithmetic checks choose the arrows: the written
     * sums and running totals decide between doubtful readings.
     */
    function readCells(ctx, msgs) {
        show(ctx, msgs.concat([busyLine(t('ReadingCells'))]));
        var fit = ctx.fit, card = ctx.card;
        return loadModels().then(function (m) {
            return new Promise(function (done) {
                var p = fit.printed, Hf = fit.frame.Hfull, byLine = {}, values = {}, reads = {};
                // The arrows that tell how many ends were shot first (the first two of each
                // end, the card's last two): the ends not shot are not read. (A card from the
                // live view is read whole: its views decide, not one frame.)
                var o = recognizerOpts(m), busy = 0, told = !!ctx.seedCells, locked = ctx.liveLocked || {};
                // The cells the live view read for sure are taken as it read them (lockLive): not read again.
                var queue = fit.cells.filter(function (c) { return !locked[c.line + '.' + c.key]; });
                var probes = Lay.shotEnds(p, function () { return 'written'; }).probes.concat([p.arrows * p.ends - 2, p.arrows * p.ends - 1]);
                var first = function (c) { return c.kind === 'arrow' && probes.indexOf(c.arrow) >= 0; };
                if (!told) queue = queue.filter(first).concat(queue.filter(function (c) { return !first(c); }));
                var opening = told ? 0 : queue.filter(first).length;
                fit.cells.forEach(function (c) { (byLine[c.line] = byLine[c.line] || {})[c.key] = c; });
                card.values.forEach(function (v) { values[v.p] = {n: v.n, g: v.g, x: v.x}; });
                // A number read among the values its cell can hold, with this archer's face.
                var bound = function (c) { return Object.assign({allowed: Lay.cellAllowed(c, p, card.values)}, c); };
                var finish = function () {
                    // What the live view read of these cells, put together with the photo's
                    // reading (as large as the photo shows it). The photo's own is kept: the
                    // live view's later views join it again (liveRefresh).
                    var photo = {}, fusedWith = {};
                    if (ctx.seedCells && window.OcrLive) {
                        fit.cells.forEach(function (c) {
                            var key = c.line + '.' + c.key, sd = ctx.seedCells[key];
                            if (!reads[key]) return;
                            var um = (c.u0 + c.u1) / 2, q = V.fullLength(Hf, um, c.v0, um, c.v1);
                            photo[key] = {q: q, reading: reads[key]};
                            if (!sd || !sd.views.length) return;
                            fusedWith[key] = sd.views.slice();
                            reads[key] = window.OcrLive.fuse(sd.views.concat([photo[key]]),
                                c.kind === 'arrow' ? 'label' : 'value', c.kind === 'arrow' ? 0 : 6);
                        });
                    }
                    lockLive(ctx, reads);
                    var a = assemble(p, reads);
                    ctx.reading = {reads: reads, lines: a.lines, total: a.total, values: values, forced: {}, msgs: msgs, time: busy / 1000,
                        photo: photo, fusedWith: fusedWith, locked: Object.keys(ctx.liveLocked || {}).length};
                    resolveReading(ctx);
                    // Read in doubt, the crossed cells on the wrong lines (OcrLayout.fit): the
                    // grid is likely a line off. Read again one line over; kept if sound, else
                    // the reading as found back. A card the live view knew too: what it read
                    // lies on the rows found, so the line over is read from the photo alone.
                    // One way, then (still in doubt) the other: the table's lines may tell up from
                    // down only barely.
                    if (fit.alt && !fit.altOf && ctx.reading.sol.agreement.low) {
                        var alts = [fit.alt, fit.alt2].filter(Boolean), base = ctx.reading, seedCells = ctx.seedCells, liveLocked = ctx.liveLocked;
                        ctx.seedCells = null;
                        ctx.liveLocked = {};
                        var tryAlt = function (n) {
                            var alt = alts[n];
                            alt.altOf = fit;
                            alt.ok = fit.ok;
                            ctx.fit = alt;
                            var back = function () {
                                var sound = ctx.fit === alt && ctx.reading && !ctx.reading.sol.agreement.low;
                                if (ctx.fit === alt && !sound && n + 1 < alts.length) return tryAlt(n + 1);
                                if (ctx.fit === alt && !sound) {
                                    ctx.fit = fit;
                                    ctx.reading = base;
                                    ctx.seedCells = seedCells;
                                    ctx.liveLocked = liveLocked;
                                    resolveReading(ctx);
                                }
                                if (ctx.fit === fit || ctx.fit === alt) sendDoubts(ctx);
                                done();
                            };
                            readCells(ctx, msgs).then(back, back);
                        };
                        tryAlt(0);
                        return;
                    }
                    if (!fit.altOf) sendDoubts(ctx);
                    done();
                };
                // The opening cells first (the ends not shot are then left out), then the rest:
                // by the workers side by side when there are, else here a slice at a time.
                var t0 = Date.now(), opened = queue.slice(0, opening), rest = queue.slice(opening);
                var take = function (list, got) { list.forEach(function (c, i) { reads[c.line + '.' + c.key] = got[i]; }); };
                readList(m, o, Hf, opened, bound).then(function (got) {
                    if (ctx.fit !== fit) return false;
                    take(opened, got);
                    var shot = opened.length ? shotEnds(fit, reads).ends : null;
                    if (shot !== null && shot < p.ends) {
                        rest = rest.filter(function (q) {
                            var off = q.line === 'total' ? shot === 0 : Math.floor((q.line - 1) * p.perLine / p.arrows) >= shot;
                            if (off) reads[q.line + '.' + q.key] = q.kind === 'arrow' ? [{label: '', p: 1}] : [{value: -1, p: 1}];
                            return !off;
                        });
                    }
                    return readList(m, o, Hf, rest, bound).then(function (got2) {
                        if (ctx.fit !== fit) return false;
                        take(rest, got2);
                        return true;
                    });
                }).then(function (ok) {
                    busy = Date.now() - t0;
                    if (ok) finish();
                    else done();
                }, function () { done(); });
            });
        }, function () {
            ctx.step = 'wait';
            show(ctx, msgs.concat([{kind: 'err', html: esc(t('ModelError'))}]));
        });
    }

    // Option "doubts": the cells a card's reading could not make sure of, sent to the page
    // of the cells to check (verify.php), each as a small picture with what was read.
    var DOUBT_H = 168;     // height of a cell's picture sent, in pixels
    var DOUBT_AROUND = 1;  // ...showing this many cells' width and height around it (its neighbours)
    var DOUBT_P = 0.9;     // a written number read less surely than this is in doubt
    var DOUBT_BLANK = 4;   // ...unless read as nothing written, this many times likelier than anything else
    var BLANK_P = 0.85;    // arrows of a card's first line read empty that surely: a card not shot (all of them)

    /** 40 hexadecimal characters from a text (five 32-bit FNV-1a hashes, seeded apart). */
    function uid(text) {
        var out = '';
        for (var s = 0; s < 5; s++) {
            var h = (0x811c9dc5 ^ (s * 0x9e3779b9)) >>> 0;
            for (var i = 0; i < text.length; i++) {
                h ^= text.charCodeAt(i);
                h = Math.imul(h, 0x01000193) >>> 0;
            }
            out += ('0000000' + h.toString(16)).slice(-8);
        }
        return out;
    }

    /**
     * A cell and its surroundings, cut out of the photo, as PNG (base64) and where the cell
     * lies in it (where it was read: on its own lines, cells reframed).
     */
    function doubtPicture(fit, c) {
        if (readFlags().reframe) c = R.reframe(V, state.full, fit.frame.Hfull, c);
        var h = c.v1 - c.v0, w = c.u1 - c.u0, mx = DOUBT_AROUND * w, my = DOUBT_AROUND * h;
        var k = DOUBT_H / (h + 2 * my), W = Math.max(8, Math.round((w + 2 * mx) * k));
        var g = V.crop(state.full, fit.frame.Hfull, c.u0 - mx, c.v0 - my, c.u1 + mx, c.v1 + my, W, DOUBT_H);
        var cv = document.createElement('canvas');
        cv.width = W;
        cv.height = DOUBT_H;
        var gc = cv.getContext('2d'), img = gc.createImageData(W, DOUBT_H);
        for (var i = 0; i < g.d.length; i++) {
            img.data[4 * i] = img.data[4 * i + 1] = img.data[4 * i + 2] = g.d[i];
            img.data[4 * i + 3] = 255;
        }
        gc.putImageData(img, 0, 0);
        return {png: cv.toDataURL('image/png').split(',')[1], box: [mx * k, my * k, (mx + w) * k, (my + h) * k]};
    }

    var DOUBTS_LATER = 1000;   // ms after a card is read: its cells' pictures cut out once the sheet is shown

    function sendDoubts(ctx) {
        if (!readOpts.doubts || !root.getAttribute('data-labels-api') || ctx.doubtsSent) return;
        var gen = state.gen;
        setTimeout(function () { if (gen === state.gen) sendDoubtsNow(ctx); }, DOUBTS_LATER);
    }

    function sendDoubtsNow(ctx) {
        var url = root.getAttribute('data-labels-api'), rd = ctx.reading;
        if (!readOpts.doubts || !url || ctx.doubtsSent || !rd || !rd.sol) return;
        // Written numbers agreeing too little with the arrows: the grid is likely set a row
        // or a column off, its cells cut out across two (the person checking found many).
        if (rd.sol.agreement.low) return;
        // A card not shot: nothing on it to check.
        if (notShot(ctx.fit, rd.reads)) return;
        ctx.doubtsSent = true;
        var items = [], p = ctx.fit.printed, card = ctx.card;
        ctx.fit.cells.forEach(function (c) {
            var key = c.line + '.' + c.key, r = rd.reads[key];
            if (!r || !r.length) return;
            var doubt;
            if (c.kind === 'arrow') {
                var a = rd.chosen[c.line + '.' + c.key.slice(1)];
                doubt = !a || !isSure(rd, a);
            } else {
                // Nothing written, clearly: no doubt of the reading (a count or a sum the
                // archer left blank). Checked by hand: 247 such cells out of 247 were blank.
                var blank = r[0].value === -1 && (!r[1] || r[0].p >= DOUBT_BLANK * r[1].p);
                var want = expected(rd.sol, p, c.line, c.key);
                // The total row is what the archer signs: it may differ from the arrows
                // (ianseo keeps both), in doubt only when read unsurely.
                doubt = !blank && (r[0].p < DOUBT_P || c.line !== 'total' && (want === null || r[0].value !== want));
            }
            if (!doubt) return;
            var pic = doubtPicture(ctx.fit, c);
            items.push({uid: uid([tourCode, card.archer.enId, card.dist, key].join('|')), kind: c.kind === 'arrow' ? 'arrow' : 'number',
                key: c.key, line: String(c.line), src: ctx.live ? 'live' : 'photo', enId: card.archer.enId, dist: card.dist,
                allowed: c.kind === 'arrow' ? null : Lay.cellAllowed(c, p, card.values),
                label: c.key === 'gold' ? card.structure.goldsLabel : c.key === 'xnine' ? card.structure.xnineLabel : '',
                reading: r.slice(0, 4).map(function (o) {
                    var v = c.kind === 'arrow' ? o.label : o.value === -1 ? '' : String(o.value);
                    return [v, o.p];
                }), box: pic.box, png: pic.png});
        });
        if (!items.length) return;
        var fd = new FormData();
        fd.append('action', 'add');
        fd.append('items', JSON.stringify(items));
        fd.append('csrf', root.getAttribute('data-csrf') || '');
        fetch(url, {method: 'POST', credentials: 'same-origin', body: fd}).then(function (r) { return r.json(); }).then(function (j) {
            if (j.error || !j.added || ctx.reading !== rd) return;
            rd.msgs = rd.msgs.concat([{kind: 'info', html: esc(t('DoubtsSent', j.added))}]);
            show(ctx, rd.msgs.concat(readingSummary(ctx)));
        }, function () { return null; });
    }

    /**
     * What the live view read for sure on a card it opened: kept as it is (the views put
     * together since, some taken moving, do not take a certainty back).
     */
    function lockLive(ctx, reads) {
        Object.keys(ctx.liveLocked || {}).forEach(function (key) {
            var lk = ctx.liveLocked[key];
            reads[key] = [lk.label !== undefined ? {label: lk.label, p: 1} : {value: lk.value, p: 1}];
        });
    }

    /**
     * How many ends of a card were shot, from its arrows read so far (OcrLayout.shotEnds):
     * {ends: null while unknown, probes: arrow indices that tell}.
     */
    function shotEnds(fit, reads) {
        var byArrow = {};
        fit.cells.forEach(function (c) { if (c.kind === 'arrow') byArrow[c.arrow] = c; });
        return Lay.shotEnds(fit.printed, function (i) {
            var c = byArrow[i], r = c && reads[c.line + '.' + c.key];
            if (!r) return null;
            var empty = r.filter(function (o) { return o.label === ''; })[0], pe = empty ? empty.p : 0;
            return pe >= BLANK_P ? 'empty' : pe <= 0.2 ? 'written' : null;
        });
    }

    /** A card not shot: nothing on it. */
    function notShot(fit, reads) {
        return shotEnds(fit, reads).ends === 0;
    }

    /** A card's printed lines and total row, from its cells' readings (an arrow not read: empty). */
    function assemble(p, reads) {
        var get = function (line, key) { return reads[line + '.' + key] || null; }, lines = [];
        for (var i = 1; i <= p.lines; i++) {
            var arrows = [];
            for (var j = 0; j < p.perLine; j++) {
                if (!reads[i + '.a' + j]) reads[i + '.a' + j] = [{label: '', p: 1}];
                arrows.push(reads[i + '.a' + j]);
            }
            lines.push({arrows: arrows, line: get(i, 'line'), end: get(i, 'end'), cum: get(i, 'cum'),
                gold: get(i, 'gold'), xnine: get(i, 'xnine')});
        }
        return {lines: lines, total: {cum: get('total', 'cum'), gold: get('total', 'gold'), xnine: get('total', 'xnine')}};
    }

    /**
     * Run the arithmetic checks again, the values the operator chose standing for
     * certain: one correction may settle the doubt on the other arrows of the end.
     */
    function resolveReading(ctx) {
        var rd = ctx.reading, p = ctx.fit.printed;
        var lines = rd.lines.map(function (ln, i) {
            var copy = Object.assign({}, ln);
            copy.arrows = ln.arrows.map(function (r, j) {
                var f = rd.forced[(i + 1) + '.' + j];
                return f === undefined ? r : [{label: f, p: 1}];
            });
            return copy;
        });
        rd.sol = S.solve(p, lines, rd.total, rd.values, ctx.card.structure.golds);
        rd.chosen = {};
        rd.sol.arrows.forEach(function (a) {
            if (rd.forced[a.line + '.' + a.index] !== undefined) a.forced = true;
            rd.chosen[a.line + '.' + a.index] = a;
        });
        ctx.step = 'done';
        show(ctx, rd.msgs.concat(readingSummary(ctx)));
    }

    /** Palette of the values the archer's target face allows, for one arrow cell. */
    function palette(td, ctx, line, index) {
        var old = document.getElementById('ocr-palette');
        if (old) old.parentNode.removeChild(old);
        var box = document.createElement('div');
        box.id = 'ocr-palette';
        box.className = 'ocr-palette';
        // The highest value first, as the core weighs them (X before the 10 it is worth, M last
        // of the values, whatever the discipline's face), blank at the end.
        var choices = ctx.card.values.slice().sort(function (p, q) { return (q.w || 0) - (p.w || 0) || q.n - p.n; })
            .map(function (v) { return v.p; }).concat(['']);
        choices.forEach(function (v) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'ocr-btn';
            b.textContent = v === '' ? t('Blank') : v;
            if (v === '') b.title = t('BlankHint');
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                ctx.reading.forced[line + '.' + index] = v;
                box.parentNode.removeChild(box);
                resolveReading(ctx);
            });
            box.appendChild(b);
        });
        td.appendChild(box);
    }

    document.addEventListener('click', function (e) {
        var box = document.getElementById('ocr-palette');
        if (box && !box.contains(e.target) && !(e.target.closest && e.target.closest('td.ocr-editable'))) {
            box.parentNode.removeChild(box);
        }
    });

    /** What the written number of a cell should be, from the arrows chosen. */
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
     * An arrow shown as sure: confident, on a card whose written numbers agree well
     * enough with the arrows (otherwise the photo is too poor, or the grid misplaced,
     * and every value is to be checked).
     */
    function isSure(rd, a) {
        return a.conf >= SURE && !rd.sol.agreement.low;
    }

    /** Arrows to check and arrows that differ from ianseo, on a card read. */
    function counts(ctx) {
        var rd = ctx.reading, card = ctx.card, p = ctx.fit.printed;
        var doubt = rd.sol.arrows.filter(function (a) { return !a.forced && !isSure(rd, a); }).length;
        var diff = card.ianseo.entered ? rd.sol.arrows.filter(function (a) {
            var held = card.ianseo.arrows[(a.line - 1) * p.perLine + a.index] || '';
            return held.replace('*', '') !== a.value;
        }).length : 0;
        return {doubt: doubt, diff: diff};
    }

    function readingSummary(ctx) {
        var rd = ctx.reading, card = ctx.card, out = [], c = counts(ctx);
        if (rd.sol.agreement.low) out.push({kind: 'err', html: esc(t('ReadUnsure'))});
        out.push({kind: c.doubt ? 'warn' : 'ok', summary: true, html: esc(t('ReadSummary', {n: rd.sol.arrows.length, doubt: c.doubt, total: rd.sol.total.cum}))});
        if (card.ianseo.entered) out.push({kind: c.diff ? 'warn' : 'ok', html: esc(t(c.diff ? 'ReadDiff' : 'ReadSame', c.diff))});
        // Mistakes on the sheet are only claimed from a reading that can be trusted.
        var sheet = rd.sol.agreement.low ? 0 : Object.keys(rd.sol.sheet).length;
        if (sheet) out.push({kind: 'err', html: esc(t('ReadSheetError', sheet))});
        if (rd.time !== undefined) out.push({kind: 'info', html: esc(t('ReadTime', rd.time.toFixed(1)))});
        return out;
    }

    // ------------------------------------------------------------------ views

    /** The card on view (the archers to choose from, when not identified; its messages are its column's) and all the cards. */
    function render(ctx) {
        renderArcher(ctx);
        drawPhoto();
        renderSheet();
    }

    function view(ctx) {
        state.view = ctx;
        render(ctx);
    }

    // ------------------------------------------------------------------ cards

    // All the cards of the sheet side by side, in the target's letter order (a place without
    // archer shown as such), for those who check them: each with its summary (a button
    // coloured as the card stands, its totals and what they tell, its alerts) and its cells.
    var LETTERS = 'ABCDEFGH';
    var ICONS = {
        eye: '<path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12z" fill="none" stroke="currentColor" stroke-width="2.2"/><circle cx="12" cy="12" r="3.4" fill="currentColor"/>',
        check: '<path d="M3.5 12.5l5.5 5.5L20.5 6" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>',
        cross: '<path d="M5 5l14 14M19 5L5 19" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"/>',
        warn: '<path d="M12 2.5L1.5 21h21L12 2.5z" fill="currentColor"/><path d="M12 9v5.5M12 17.5v.5" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>'
    };

    function icon(kind, tone, title) {
        return '<svg class="ocr-ico ocr-ico-' + tone + '" viewBox="0 0 24 24" role="img" aria-label="' + esc(title) + '"><title>'
            + esc(title) + '</title>' + ICONS[kind] + '</svg>';
    }

    /** The letter of a card's place on its target ('' when unknown). */
    function letterOf(ctx) {
        return ctx.card ? String(ctx.card.archer.target).replace(/^\d+/, '').toUpperCase() : '';
    }

    /**
     * The sheet's places in letter order: [{letter, ctx}] (ctx null: no card found there, the
     * archer of the place, if any, in archer). Cards not identified take the places left.
     */
    function placesOf() {
        var target = null, byLetter = {}, unknown = [], seq = readingOrder(), shift = null;
        seq.forEach(function (ctx, i) {
            var l = letterOf(ctx);
            if (ctx.card && target === null) target = parseInt(ctx.card.archer.target, 10);
            if (l && !byLetter[l]) {
                byLetter[l] = ctx;
                // The letters run with the places from the first card identified.
                if (shift === null) shift = LETTERS.indexOf(l) - i;
            } else unknown.push({ctx: ctx, i: i});
        });
        var held = {};
        if (target !== null && roster) {
            roster.forEach(function (a) {
                if (parseInt(a.target, 10) !== target || (currentSession() && a.session !== currentSession())) return;
                held[String(a.target).replace(/^\d+/, '').toUpperCase()] = a;
            });
        }
        var last = Math.max(expectedGrids(), state.sheet.length) - 1;
        Object.keys(byLetter).concat(Object.keys(held)).forEach(function (l) { last = Math.max(last, LETTERS.indexOf(l)); });
        // A card not identified: the letter of its place, else the first one left.
        var placed = {}, rest = [];
        unknown.forEach(function (u) {
            var k = u.i + (shift || 0), l = k >= 0 && k <= last ? LETTERS.charAt(k) : '';
            if (l && !byLetter[l] && !placed[l]) placed[l] = u.ctx;
            else rest.push(u.ctx);
        });
        var out = [];
        for (var i = 0; i <= last; i++) {
            var l = LETTERS.charAt(i), ctx = byLetter[l] || placed[l] || null;
            if (!ctx && rest.length) ctx = rest.shift();
            out.push({letter: l, target: target, ctx: ctx, archer: held[l] || null});
        }
        return out.concat(rest.map(function (ctx) { return {letter: '', target: target, ctx: ctx, archer: null}; }));
    }

    /**
     * The sheet's cards in its own reading order: along the cards' rows (A > B > C; two rows of
     * two for four, one above the other), whichever way the sheet lies, its way read from a card
     * fitted; as found when none is.
     */
    function readingOrder() {
        var ref = state.sheet.find(function (ctx) { return ctx.card && ctx.fit && ctx.fit.ok !== false && ctx.fit.frame && ctx.fit.frame.Hfull && ctx.at; });
        if (!ref) return state.sheet.slice();
        var H = ref.fit.frame.Hfull, o = V.apply(H, 0, 0), u = V.apply(H, 100, 0), v = V.apply(H, 0, 100);
        var rl = Math.hypot(u.x - o.x, u.y - o.y) || 1, dl = Math.hypot(v.x - o.x, v.y - o.y) || 1;
        var right = {x: (u.x - o.x) / rl, y: (u.y - o.y) / rl}, down = {x: (v.x - o.x) / dl, y: (v.y - o.y) / dl};
        // A card's height on the picture, to tell a row from the next.
        var hb = ref.fit.frame.Hb || (ref.fit.cells || []).reduce(function (m, c) { return Math.max(m, c.v1 || 0); }, 0);
        var tall = hb ? Math.hypot(V.apply(H, 0, hb).x - o.x, V.apply(H, 0, hb).y - o.y) : 0;
        var pos = state.sheet.map(function (ctx) {
            var c = ctx.at || {x: 0, y: 0};
            return {ctx: ctx, r: c.x * right.x + c.y * right.y, d: c.x * down.x + c.y * down.y};
        });
        var rows = expectedGrids() === 4 && tall > 0;
        return pos.sort(function (p, q) {
            if (rows && Math.abs(p.d - q.d) > 0.5 * tall) return p.d - q.d;
            return p.r - q.r;
        }).map(function (x) { return x.ctx; });
    }

    /** The totals' keys of a card: the score, then the counts its scorecard has. */
    function totalKeys(card) {
        return card.structure.golds ? ['cum', 'gold', 'xnine'] : ['cum', 'xnine'];
    }

    /** What ianseo holds for one of a card's totals (null: nothing entered). */
    function heldTotal(card, key) {
        var i = card.ianseo;
        if (!i.entered) return null;
        var v = key === 'cum' ? i.score : key === 'gold' ? i.gold : key === 'xnine' ? i.xnine : null;
        return v === undefined || v === '' ? null : +v;
    }

    /**
     * A written number of a card, as shown: read (as a person reads it, knowing what it should
     * be; on the total row as signed, blank too), calculated from the arrows, held by ianseo.
     * -> {read (-1 blank, null not read), calc, held, partial (ianseo lacks arrows it counts:
     * held is shown, not compared), sure, bad (a mistake on the sheet), forced, joined (an
     * end's total cell holding the hundreds of the running total beside it: no number of its
     * own)}
     */
    function numberOf(ctx, line, key) {
        var rd = ctx.reading, k = line + '.' + key, p = ctx.fit.printed, calc = expected(rd.sol, p, line, key);
        var split = rd.sol.split && line !== 'total' ? rd.sol.split[line] : null;
        if (split && key === 'end') return {read: -1, calc: calc, held: null, partial: false, sure: true, bad: null, joined: true};
        var held = null, partial = false;
        if (line === 'total') {
            held = heldTotal(ctx.card, key);
            partial = held !== null && !heldWhole(ctx.card);
        } else {
            var hl = ianseoLine(ctx.card, p, line);
            if (hl.any) {
                held = key === 'line' ? hl.line : key === 'end' ? hl.end : key === 'cum' ? hl.cum : key === 'gold' ? hl.gold : key === 'xnine' ? hl.xnine : null;
                partial = !!hl.partial[key];
            }
        }
        var f = rd.forcedNum && rd.forcedNum[k];
        if (f !== undefined) return {read: f, calc: calc, held: held, partial: partial, sure: true, forced: true, bad: null};
        // Written across its end's total cell and its own: read as the two together.
        var r = split && key === 'cum' ? split : rd.reads[k];
        if (!r || !r.length) return {read: null, calc: calc, held: held, partial: partial, sure: false, bad: null};
        var bad = rd.sol.agreement.low ? null : rd.sol.sheet[k] || null, shown = r[0].value, isCount = key === 'gold' || key === 'xnine';
        // A line's sum cell holding its end's total (what archers are often told), as the
        // checks take it: calculated and held as the end's.
        var endSum = key === 'line' && p.arrows > p.perLine ? expected(rd.sol, p, line, 'end') : null;
        if (!bad && endSum !== null && endSum !== calc && r.some(function (o) { return o.p >= 0.01 && o.value === endSum; })
            && !r.some(function (o) { return o.p >= 0.01 && o.value === calc; })) {
            calc = endSum;
            if (held !== null) {
                held = hl.end;
                partial = !!hl.partial.end;
            }
        }
        if (bad) shown = bad.read;
        else if (line !== 'total') {
            r.forEach(function (o) {
                if (o.p >= 0.01 && (o.value === -1 && isCount ? 0 : o.value) === calc) shown = o.value;
            });
        }
        var sure = !rd.sol.agreement.low && shown === r[0].value && r[0].p >= DOUBT_P;
        return {read: shown, calc: calc, held: held, partial: partial, sure: sure, bad: bad};
    }

    /** What ianseo holds of a number, to be compared with: null when nothing, or partial. */
    function heldCmp(n) {
        return n.partial ? null : n.held;
    }

    /** A number as it counts: a count left blank is none, a signed total left blank is 0. */
    function asCounted(n, key, signed) {
        if (n.read === null) return null;
        return n.read === -1 && (signed || key === 'gold' || key === 'xnine') ? 0 : n.read;
    }

    /** An arrow of a card, chosen by the checks: {a, sure, held ('' none)}. */
    function arrowOf(ctx, line, index) {
        var rd = ctx.reading, a = rd.chosen[line + '.' + index], p = ctx.fit.printed;
        var held = ctx.card.ianseo.entered ? (ctx.card.ianseo.arrows[(line - 1) * p.perLine + index] || '').replace('*', '') : '';
        return {a: a, sure: !!a && (a.forced || isSure(rd, a)), held: held, read: !!rd.reads[line + '.a' + index]};
    }

    /**
     * Where a card stands, for its summary: busy, its eye (ok: all arrows and totals read
     * surely; doubt: all read; bad: some not read; none: nothing read), its totals with what
     * they tell, its alerts, its colour (ok, doubt, bad).
     */
    function cardState(ctx) {
        if (!ctx || ctx.step === 'busy' || !ctx.card) return {colour: ctx && ctx.step === 'wait' ? 'doubt' : 'busy', eye: 'none', totals: [], alerts: 0};
        if (!ctx.reading || !ctx.fit || !ctx.fit.ok) return {colour: 'doubt', eye: 'none', totals: [], alerts: 0};
        var rd = ctx.reading, card = ctx.card, arrows = rd.sol.arrows, sureArrows = true, readAll = true, red = false, alerts = 0;
        arrows.forEach(function (a) {
            var x = arrowOf(ctx, a.line, a.index);
            if (!x.sure) sureArrows = false;
            if (!x.read) readAll = false;
            if (x.held !== '' && x.held !== a.value) {
                alerts++;
                if (x.sure) red = true;
            }
        });
        // Mistakes the checks find on the sheet's other numbers (sums, running totals).
        Object.keys(rd.sol.sheet || {}).forEach(function (k) { if (k.indexOf('total.') !== 0) alerts++; });
        if (!rd.sol.agreement.low && Object.keys(rd.sol.sheet || {}).some(function (k) { return k.indexOf('total.') !== 0; })) red = true;
        var nums = totalKeys(card).map(function (key) { return {key: key, n: numberOf(ctx, 'total', key)}; });
        nums.forEach(function (o) { if (o.n.read === null) readAll = false; });
        var sureAll = sureArrows && nums.every(function (o) { return o.n.sure; });
        var eye = !arrows.length ? 'none' : sureAll ? 'ok' : readAll ? 'doubt' : 'bad';
        var labels = {cum: t('TotScore'), gold: card.structure.goldsLabel, xnine: card.structure.xnineLabel};
        var totals = nums.map(function (o) {
            var n = o.n, v = asCounted(n, o.key, true), tone = sureAll ? 'bad' : 'doubt', kind, title;
            if (v === null) {
                kind = 'warn';
                tone = 'bad';
                title = t('EyeMissing');
            } else if (heldCmp(n) !== null && v !== n.held) {
                kind = 'warn';
                title = t(sureAll ? 'TotDbSure' : 'TotDbDoubt', {read: v, held: n.held});
            } else if (v !== n.calc) {
                kind = 'cross';
                title = t(sureAll ? 'TotCalcSure' : 'TotCalcDoubt', {read: v, want: n.calc});
            } else {
                kind = 'check';
                tone = sureAll ? 'ok' : 'doubt';
                title = t(sureAll ? 'TotOkSure' : 'TotOkDoubt');
            }
            if (kind !== 'check') {
                alerts++;
                if (sureArrows && n.sure) red = true;
            }
            return {key: o.key, label: labels[o.key], value: n.read === -1 && !n.forced ? t('TotBlank') : v, kind: kind, tone: tone, title: title};
        });
        return {colour: red ? 'bad' : sureAll ? 'ok' : 'doubt', eye: eye, totals: totals, alerts: alerts};
    }

    /** The cards side by side, as their places on the target are. */
    function renderSheet() {
        var bar = el('ocr-sheet');
        if (!bar) return;
        if (!state.sheet.length) {
            bar.hidden = true;
            bar.innerHTML = '';
            return;
        }
        var places = placesOf();
        bar.innerHTML = '';
        bar.style.setProperty('--ocr-cols', String(Math.max(1, places.length)));
        places.forEach(function (pl) { bar.appendChild(placeColumn(pl)); });
        bar.hidden = false;
        if (!el('ocr-cards-legend').childNodes.length) el('ocr-cards-legend').innerHTML = cardsLegend();
        el('ocr-cards-legend').hidden = false;
        el('ocr-auto').hidden = false;
        setTimeout(autoWrite, 0);
    }

    // ------------------------------------------------------------ into ianseo

    // A card's reading written into ianseo (api/score.php): imported when its distance holds
    // nothing yet, else what is entered validated, as the core's barcode check does, once the
    // reading is what is entered (the cells the operator corrected are written first). A
    // value read otherwise than ianseo holds is never validated: the operator types the right
    // one. Automatic, as ISK offers it: the whole sheet once every card is sure, or each card
    // as soon as it is. The sheet done, the view goes back to live for the next one.
    var AUTO_KEY = 'ocrAutoImport:' + tourCode;
    var autoImport = (function () {
        try {
            var v = JSON.parse(window.localStorage.getItem(AUTO_KEY));
            if (v) return {sheet: !!v.sheet, archer: !!v.archer};
        } catch (e) { /* none */ }
        return {sheet: false, archer: false};
    })();

    Array.prototype.forEach.call(root.querySelectorAll('#ocr-auto input[data-auto]'), function (b) {
        b.checked = !!autoImport[b.getAttribute('data-auto')];
        b.addEventListener('change', function () {
            var k = b.getAttribute('data-auto');
            autoImport[k] = b.checked;
            // One or the other, as with ISK.
            if (b.checked) autoImport[k === 'sheet' ? 'archer' : 'sheet'] = false;
            Array.prototype.forEach.call(root.querySelectorAll('#ocr-auto input[data-auto]'), function (o) { o.checked = !!autoImport[o.getAttribute('data-auto')]; });
            try {
                window.localStorage.setItem(AUTO_KEY, JSON.stringify(autoImport));
            } catch (e) { /* this page only */ }
            autoWrite();
        });
    });

    /**
     * A card's arrows as chosen (the operator's corrections in), as letters of the archer's
     * face; a value ianseo holds there as it is, in ianseo's letter (two may print alike).
     */
    function lettersOf(ctx) {
        var rd = ctx.reading, p = ctx.fit.printed, i = ctx.card.ianseo, byPrint = {}, out = '';
        ctx.card.values.forEach(function (v) { byPrint[v.p] = v.l; });
        for (var line = 1; line <= p.lines; line++) {
            for (var j = 0; j < p.perLine; j++) {
                var a = rd.chosen[line + '.' + j], v = a ? a.value : '', at = (line - 1) * p.perLine + j;
                var held = i.letters ? i.letters.charAt(at) : ' ';
                out += v === '' ? ' ' : held !== ' ' && held !== '' && i.arrows[at] === v ? held : byPrint[v] || '?';
            }
        }
        return out;
    }

    /** ianseo holds every arrow of the card's distance: what is there is to be validated, no longer imported. */
    function heldWhole(card) {
        return card.ianseo.entered > 0 && card.ianseo.entered === card.ianseo.arrows.length;
    }

    /**
     * The arrows ianseo holds otherwise than chosen, but those the operator corrected (his
     * value is the one written); with blanks, an arrow ianseo does not hold counts too (to
     * validate, every arrow is entered; an import completes what is).
     */
    function offArrows(ctx, blanks) {
        var rd = ctx.reading, p = ctx.fit.printed, held = ctx.card.ianseo.arrows, n = 0;
        for (var line = 1; line <= p.lines; line++) {
            for (var j = 0; j < p.perLine; j++) {
                var a = rd.chosen[line + '.' + j], h = (held[(line - 1) * p.perLine + j] || '').replace('*', '');
                if ((a && a.forced) || h === (a ? a.value : '')) continue;
                if (blanks || h !== '') n++;
            }
        }
        return n;
    }

    /**
     * What can be written of a card: {kind: 'import' | 'validate', blocked: why not yet}, or
     * {done} once written or confirmed in ianseo; null while it is not read. Imported while
     * ianseo does not hold every arrow (none, or some missing: the first whole reading of the
     * sheet), validated once it does (by a later reading).
     */
    function writeState(ctx) {
        if (!ctx || !ctx.card || !ctx.reading || !ctx.fit || !ctx.fit.ok || ctx.step !== 'done') return null;
        if (ctx.written) return {done: ctx.written};
        var i = ctx.card.ianseo, kind = heldWhole(ctx.card) ? 'validate' : 'import', st = cardState(ctx);
        // One archer for two cards of the sheet: one of them is someone else's, nothing written.
        if (sameArcher(ctx)) return {kind: kind, blocked: t('SameArcher', ctx.card.archer.name)};
        if (i.confirmed) return {done: 'validated'};
        if (ctx.writing) return {kind: kind, blocked: t('Writing')};
        if (st.eye === 'bad' || st.eye === 'none') return {kind: kind, blocked: t('EyeMissing')};
        var shot = !notShot(ctx.fit, ctx.reading.reads);
        var blank = totalKeys(ctx.card).some(function (k) { var n = numberOf(ctx, 'total', k); return shot && n.read === -1 && !n.forced; });
        if (blank) return {kind: kind, blocked: t('ImportNeedZero')};
        var off = offArrows(ctx, kind === 'validate');
        if (off) return {kind: kind, blocked: t('ValidateNeedEdit', off)};
        return {kind: kind};
    }

    /**
     * The arrows read in doubt that the operator may take as read in one click: read, not
     * blank, and not otherwise than ianseo holds (those stay to be corrected one by one).
     */
    function doubtArrows(ctx) {
        if (!ctx || !ctx.card || !ctx.reading || ctx.step !== 'done') return [];
        return ctx.reading.sol.arrows.filter(function (a) {
            var x = arrowOf(ctx, a.line, a.index);
            return x.read && !x.sure && a.value !== '' && (x.held === '' || x.held === a.value);
        });
    }

    /** The sheet's totals to set to what the arrows make, once every arrow is sure: [key]. */
    function totalsToSet(ctx) {
        if (!ctx || !ctx.card || !ctx.reading || ctx.step !== 'done') return [];
        var rd = ctx.reading;
        if (!rd.sol.arrows.length || !rd.sol.arrows.every(function (a) { return arrowOf(ctx, a.line, a.index).sure; })) return [];
        return totalKeys(ctx.card).filter(function (k) {
            var n = numberOf(ctx, 'total', k);
            return n.calc !== null && !n.forced && (!n.sure || asCounted(n, k, true) !== n.calc);
        });
    }

    /**
     * Quick checks of a card: its arrows in doubt taken as read, then its three totals as
     * the arrows make them (most of the time what was read is right, only unsure).
     */
    function bulkButtons(ctx) {
        var doubts = doubtArrows(ctx), totals = doubts.length ? [] : totalsToSet(ctx), out = '';
        if (doubts.length) {
            out += '<button type="button" class="ocr-btn" data-bulk="arrows" data-of="' + ctx.n + '" title="' + esc(t('ValidateDoubtsHint')) + '">'
                + esc(doubts.length === 1 ? t('ValidateDoubt1') : t('ValidateDoubts', doubts.length)) + '</button>';
        }
        if (totals.length) {
            out += '<button type="button" class="ocr-btn" data-bulk="totals" data-of="' + ctx.n + '" title="' + esc(t('ValidateTotalsHint')) + '">'
                + esc(t('ValidateTotals')) + '</button>';
        }
        return out;
    }

    /**
     * A card's actions: the quick checks while there are, then the writing into ianseo (or
     * what was written), so that the writing is offered on a card checked; with reread, the
     * card read again last (in the card's head, not on its Total row). '' for none.
     */
    function cardActions(ctx, reread) {
        var w = writeState(ctx), acts = w && w.done ? writeButton(ctx) : bulkButtons(ctx) || writeButton(ctx), again = reread ? rereadButton(ctx) : '';
        return acts || again ? '<div class="ocr-col-act">' + acts + again + '</div>' : '';
    }

    function writeButton(ctx) {
        var w = writeState(ctx);
        if (!w || !root.getAttribute('data-score-api')) return '';
        if (w.done) return '<span class="ocr-written">' + esc(t(w.done === 'imported' ? 'Imported' : 'Validated')) + '</span>';
        var label = t(w.kind === 'import' ? 'Import' : 'Validate');
        return '<button type="button" class="ocr-btn ocr-btn-primary" data-write="' + ctx.n + '"'
            + (w.blocked ? ' disabled title="' + esc(w.blocked) + '"' : '') + '>' + esc(label) + '</button>'
            + (ctx.writeError ? '<span class="ocr-msg ocr-msg-err">' + esc(ctx.writeError) + '</span>' : '');
    }

    /** One card written into ianseo, as writeState() allows. -> a promise (true when written). */
    function writeCard(ctx) {
        var w = writeState(ctx), url = root.getAttribute('data-score-api');
        if (!w || w.done || w.blocked || !url) return Promise.resolve(false);
        var rd = ctx.reading, p = ctx.fit.printed, zero = [], signed = [];
        Object.keys(rd.forcedNum || {}).forEach(function (k) {
            var m = {'total.cum': 'score', 'total.gold': 'gold', 'total.xnine': 'xnine'}[k];
            if (m && rd.forcedNum[k] === 0) zero.push(m);
        });
        ['cum', 'gold', 'xnine'].forEach(function (k) {
            var n = ctx.card.structure.golds || k !== 'gold' ? numberOf(ctx, 'total', k) : null;
            signed.push(n && n.read !== null ? n.read : -1);
        });
        var edited = Object.keys(rd.forced).map(function (k) {
            var q = k.split('.');
            return (+q[0] - 1) * p.perLine + +q[1];
        });
        var body = new URLSearchParams({action: w.kind, enId: String(ctx.card.archer.enId), dist: String(ctx.card.dist), arrows: lettersOf(ctx),
            zero: zero.join(','), edited: edited.join(','), signed: signed.join(','), csrf: root.getAttribute('data-csrf') || ''});
        ctx.writing = true;
        ctx.writeError = null;
        ctx.writeFailed = false;
        renderSheet();
        var end = function (j) {
            ctx.writing = false;
            // What ianseo holds now, refused or not: the card tells it from there.
            if (j && j.card) ctx.card.ianseo = j.card.ianseo;
            if (!j || j.error) {
                ctx.writeError = j && j.msg ? j.msg : t('ErrRequest');
                // Not tried again by itself: only the operator's click does.
                ctx.writeFailed = true;
            } else {
                ctx.written = w.kind === 'import' ? 'imported' : 'validated';
            }
            if (rec) recLog({f: 'write', kind: w.kind, enId: ctx.card.archer.enId, dist: ctx.card.dist, ok: !!(j && !j.error), msg: ctx.writeError || ''});
            renderSheet();
            sheetWritten();
            return !!(j && !j.error);
        };
        return fetch(url, {method: 'POST', credentials: 'same-origin', body: body})
            .then(function (r) { return r.json(); }).then(end, function () { return end(null); });
    }

    /**
     * A grid at a place of the target without archer (ianseo prints a card there, with no
     * name): nothing to write, it holds back neither the sheet nor its return to live.
     */
    function emptyPlace(pl) {
        return !!pl.ctx && !pl.ctx.card && pl.ctx.step !== 'busy' && !pl.archer && pl.target !== null && pl.letter !== '';
    }

    /** The sheet's cards that are to be written: all but those at a place without archer. */
    function sheetCards() {
        return placesOf().filter(function (pl) { return pl.ctx && !emptyPlace(pl); }).map(function (pl) { return pl.ctx; });
    }

    /** Automatic writing, as ticked: each card once sure, or the whole sheet once every card is. */
    function autoWrite() {
        if (!autoImport.sheet && !autoImport.archer) return;
        var ready = function (ctx) {
            var w = writeState(ctx);
            return w && !w.done && !w.blocked && !ctx.writeFailed && cardState(ctx).colour === 'ok';
        };
        if (autoImport.archer) {
            state.sheet.filter(ready).forEach(writeCard);
            return;
        }
        if (state.working || !state.sheet.length) return;
        var cards = sheetCards();
        var all = cards.length && cards.every(function (ctx) { var w = writeState(ctx); return w && (w.done || ready(ctx)); });
        if (all) cards.filter(ready).forEach(writeCard);
    }

    /** Every card of the sheet written (or confirmed): the view goes back to live for the next sheet. */
    function sheetWritten() {
        if (!state.frozen || !state.sheet.length || state.working) return;
        var cards = sheetCards();
        var done = cards.length && cards.every(function (ctx) { var w = writeState(ctx); return w && w.done; });
        if (done) backToLive(true);
    }

    function placeColumn(pl) {
        var col = document.createElement('div'), ctx = pl.ctx;
        col.className = 'ocr-col' + (ctx && ctx === state.view ? ' ocr-col-on' : '');
        if (ctx) col.setAttribute('data-card', String(ctx.n));
        var where = (pl.target !== null ? pl.target : '') + pl.letter;
        if (!ctx || emptyPlace(pl)) {
            // A card there all the same (printed empty) can be looked at, an archer given to it.
            // No grid found at the place: the photo searched again, closer.
            var tag = ctx ? 'button type="button" data-card="' + ctx.n + '"' : 'div', again = ctx ? '' : researchButton();
            col.innerHTML = '<' + tag + ' class="ocr-btn ocr-sum ocr-sum-none" title="' + esc(pl.archer ? t('PlaceNotFound', pl.archer.name) : t('PlaceEmpty')) + '">'
                + '<span class="ocr-sum-who"><b>' + esc(where) + '</b> ' + esc(pl.archer ? pl.archer.name : '---') + '</span>'
                + (again ? '<div class="ocr-sum-side"><div class="ocr-col-act">' + again + '</div></div>' : '') + '</' + tag.split(' ')[0] + '>';
            return col;
        }
        var st = cardState(ctx), a = ctx.card && ctx.card.archer;
        var who = a ? '<b>' + esc(a.target) + '</b> ' + esc(a.name.split(' ')[0]) : '<b>' + esc(where || '?') + '</b> ' + esc(t('SheetCard', ctx.n + 1));
        var eye = st.eye === 'none' ? '' : icon('eye', st.eye, t(st.eye === 'ok' ? 'EyeSure' : st.eye === 'doubt' ? 'EyeDoubt' : 'EyeMissing'));
        var tots = st.totals.map(function (o) {
            return '<span class="ocr-tot" title="' + esc(o.label + ' : ' + o.title) + '"><small>' + esc(o.label) + '</small> ' + esc(String(o.value))
                + icon(o.kind, o.tone, o.title) + '</span>';
        }).join('');
        if (st.totals.length) tots += '<span class="ocr-alerts' + (st.alerts ? '' : ' ocr-alerts-none') + '" title="' + esc(t('Alerts', st.alerts)) + '">' + st.alerts + '</span>';
        var side = cardActions(ctx, true);
        // What stands in the way (to identify, another session…): with the card, its whole width.
        var notes = (ctx.msgs || []).filter(function (m) { return (m.kind === 'err' || m.kind === 'warn') && !m.summary; });
        var noteBox = notes.length ? '<div class="ocr-col-notes">' + notes.map(function (m) { return '<div class="ocr-msg ocr-msg-' + m.kind + '">' + m.html + '</div>'; }).join('') + '</div>' : '';
        if (!ctx.card && ctx.pick && ctx.pick.length) {
            side += '<div class="ocr-col-act">' + ctx.pick.map(function (r) {
                return '<button type="button" class="ocr-btn" data-use="' + r.enId + '">' + esc(r.target + ' ' + r.name) + '</button>';
            }).join('') + '</div>';
        }
        // Not a button holding buttons: the part that shows the card is the button.
        col.innerHTML = '<div class="ocr-btn ocr-sum ocr-sum-' + st.colour + '"><button type="button" class="ocr-sum-main" data-card="' + ctx.n + '">'
            + '<span class="ocr-sum-who">' + who + eye + '</span>' + (tots ? '<span class="ocr-sum-tots">' + tots + '</span>' : '') + '</button>'
            + (side ? '<div class="ocr-sum-side">' + side + '</div>' : '') + noteBox + '</div>';
        var cells = cardCells(ctx);
        if (cells) col.appendChild(cells);
        return col;
    }

    // The cells' pictures, as tall as the bar says (kept in this browser), cut again at that size.
    var CROP_KEY = 'ocrCropSize';
    var cropH = (function () {
        try {
            var v = +window.localStorage.getItem(CROP_KEY);
            if (v >= 20 && v <= 50) return v;
        } catch (e) { /* as by default */ }
        return 30;
    })(), cropAgain = null;
    el('ocr-crop-size').value = String(cropH);
    el('ocr-sheet').style.setProperty('--ocr-crop-h', cropH + 'px');
    el('ocr-crop-size').addEventListener('input', function () {
        cropH = +this.value;
        el('ocr-sheet').style.setProperty('--ocr-crop-h', cropH + 'px');
        try {
            window.localStorage.setItem(CROP_KEY, String(cropH));
        } catch (e) { /* this page only */ }
        clearTimeout(cropAgain);
        cropAgain = setTimeout(renderSheet, 250);
    });

    // "Reread": a grid fitted is set a line down, then a line up (a line off: the header read
    // for cells, say); asked once more, or no grid fitted, or no archer told, the grid is looked
    // for again on the photo cut around the card. A large photo is analysed reduced: its part
    // nearly at full size, the card's neighbours mostly left out.
    var AROUND = 0.25;          // the card's box widened by this share of its size on each side
    var RESEARCH_SIDE = 3200;   // the whole photo searched again at this working size at most

    /** A card that can be read again: on a picture, not busy. */
    function rereadAble(ctx) {
        return !!(ctx && state.full && ctx.step !== 'busy' && !state.working && (ctx.analysis || ctx.liveFit));
    }

    /** A card's grid lost: none fitted, or whose card it is unknown. */
    function gridLost(ctx) {
        return !ctx.card || !ctx.fit || !ctx.fit.ok;
    }

    /** What the next "Reread" of a card does: 'search', 'down' or 'up'. */
    function rereadNext(ctx) {
        var r = ctx.regrid || 0;
        return gridLost(ctx) || r >= 2 ? 'search' : r ? 'up' : 'down';
    }

    function rereadButton(ctx) {
        if (!rereadAble(ctx)) return '';
        var hint = {search: 'RereadSearch', down: 'RereadDown', up: 'RereadUp'}[rereadNext(ctx)];
        return '<button type="button" class="ocr-btn ocr-reread" data-reread="' + ctx.n + '" title="' + esc(t(hint)) + '">' + esc(t('Reread')) + '</button>';
    }

    /** The photo searched again for the grids of the places without one. */
    function researchButton() {
        if (!state.full || state.working) return '';
        return '<button type="button" class="ocr-btn ocr-reread" data-research="1" title="' + esc(t('RereadSheet')) + '">' + esc(t('Reread')) + '</button>';
    }

    /**
     * A fit with its cells moved k rows (down for k > 0) in the grid's frame: each cell then
     * covers the row k lines over (the row's height the cells' own, line after line).
     */
    function shiftedFit(f, k) {
        var tops = {}, gaps = [], i;
        f.cells.forEach(function (c) { if (typeof c.line === 'number') tops[c.line] = c.v0; });
        var lines = Object.keys(tops).map(Number).sort(function (a, b) { return a - b; });
        for (i = 1; i < lines.length; i++) gaps.push((tops[lines[i]] - tops[lines[i - 1]]) / (lines[i] - lines[i - 1]));
        gaps.sort(function (a, b) { return a - b; });
        var step = gaps.length ? gaps[gaps.length >> 1] : 0;
        if (!step) return f;
        return {ok: f.ok, printed: f.printed, frame: f.frame, marks: f.marks, alt: null, alt2: null,
            cells: f.cells.map(function (c) { return Object.assign({}, c, {v0: c.v0 + k * step, v1: c.v1 + k * step}); })};
    }

    /** A card read again (see AROUND): its grid set a line down, a line up, else looked for again. */
    function reread(ctx) {
        if (!rereadAble(ctx)) return;
        if (rereadNext(ctx) === 'search') {
            research(ctx);
            return;
        }
        regrid(ctx, (ctx.regrid || 0) + 1);
    }

    /** A card's grid set a line over (r: 1 a line down, 2 a line up); its cells read again. */
    function regrid(ctx, r) {
        ctx.regrid = r;
        ctx.seedCells = null;
        ctx.liveLocked = {};
        ctx.reading = null;
        ctx.crops = null;
        if (ctx.analysis) {
            var st = Object.assign({dist: ctx.card.dist}, ctx.card.structure), k = [0, 1, -1][ctx.regrid];
            if (k) st.shift = k;
            var f = Lay.fit(V, ctx.analysis, st, ctx.fit ? ctx.fit.turn : null);
            ctx.fit = f;
            ctx.step = 'busy';
            renderSheet();
            return Promise.resolve(f.ok ? readCells(ctx, ctx.msgs || []) : null).then(function () {
                if (!f.ok) ctx.step = 'wait';
                renderSheet();
                drawPhoto();
            });
        }
        var base = ctx.liveBase || (ctx.liveBase = ctx.liveFit), kl = [0, 1, -1][ctx.regrid];
        ctx.liveFit = kl ? shiftedFit(base, kl) : base;
        ctx.step = 'busy';
        renderSheet();
        return Promise.resolve(liveCard(ctx)).then(function () {
            renderSheet();
            drawPhoto();
        });
    }

    /** A card's box on the photo (its pixels): its grid's outline, else its cells'. null when unknown. */
    function cardBox(ctx) {
        var pts = [], a = ctx.analysis, f = ctx.liveBase || ctx.liveFit;
        if (a) {
            var s = a.scale || 1;
            ['tl', 'tr', 'br', 'bl'].forEach(function (k) { pts.push({x: a.quad[k].x / s, y: a.quad[k].y / s}); });
        } else if (f && f.frame && f.frame.Hfull) {
            f.cells.forEach(function (c) { pts.push(V.apply(f.frame.Hfull, c.u0, c.v0), V.apply(f.frame.Hfull, c.u1, c.v1)); });
        }
        if (!pts.length) return null;
        var xs = pts.map(function (p) { return p.x; }), ys = pts.map(function (p) { return p.y; });
        return {x0: Math.min.apply(null, xs), y0: Math.min.apply(null, ys), x1: Math.max.apply(null, xs), y1: Math.max.apply(null, ys)};
    }

    /** Part of a grey picture, its box kept within it. -> {img, x0, y0} */
    function cutOut(full, b) {
        var x0 = Math.max(0, Math.floor(b.x0)), y0 = Math.max(0, Math.floor(b.y0));
        var x1 = Math.min(full.w, Math.ceil(b.x1)), y1 = Math.min(full.h, Math.ceil(b.y1)), w = x1 - x0, h = y1 - y0;
        var d = new full.d.constructor(w * h);
        for (var y = 0; y < h; y++) d.set(full.d.subarray((y0 + y) * full.w + x0, (y0 + y) * full.w + x1), y * w);
        return {img: {w: w, h: h, d: d}, x0: x0, y0: y0};
    }

    /**
     * A grid found on a part of the photo, put where that part lies on it: its frames (every
     * way, worked out first on the part's own working copy) and its outline moved by (ox, oy).
     */
    function movedGrid(g, ox, oy) {
        var s = g.scale || 1, q = g.quad, b = g.gridBox;
        var at = function (p) { return {x: p.x + ox * s, y: p.y + oy * s}; };
        for (var k = 0; k < 4; k++) {
            var f = V.frame(g, k), H = f && f.Hfull;
            if (H) f.Hfull = [H[0] + ox * H[6], H[1] + ox * H[7], H[2] + ox * H[8], H[3] + oy * H[6], H[4] + oy * H[7], H[5] + oy * H[8], H[6], H[7], H[8]];
        }
        g.quad = {tl: at(q.tl), tr: at(q.tr), br: at(q.br), bl: at(q.bl)};
        g.gridBox = Object.assign({}, b, {x0: b.x0 + ox * s, y0: b.y0 + oy * s, x1: b.x1 + ox * s, y1: b.y1 + oy * s});
        return g;
    }

    /** A card's grid looked for again on the photo cut around it (see AROUND), the card then read again. */
    function research(ctx) {
        var b = cardBox(ctx), gen = state.gen;
        if (!b) return null;
        var mx = AROUND * (b.x1 - b.x0), my = AROUND * (b.y1 - b.y0), mid = {x: (b.x0 + b.x1) / 2, y: (b.y0 + b.y1) / 2};
        var part = cutOut(state.full, {x0: b.x0 - mx, y0: b.y0 - my, x1: b.x1 + mx, y1: b.y1 + my});
        var before = ctx.step, msgs = ctx.msgs || [];
        ctx.step = 'busy';
        show(ctx, [busyLine(t('RereadSearching'))]);
        renderSheet();
        return analysis(part.img, {all: true}).then(function (a) {
            if (gen !== state.gen) return null;
            // The grid nearest the card's middle, one that fits its layout first.
            var st = ctx.card ? Object.assign({dist: ctx.card.dist}, ctx.card.structure) : null, best = null;
            (a.ok ? a.grids : []).forEach(function (g) {
                var c = centre(g), s = g.scale || 1, d = Math.hypot(c.x / s + part.x0 - mid.x, c.y / s + part.y0 - mid.y);
                var fits = !st || Lay.fit(V, g, st, null).ok ? 1 : 0;
                if (!best || fits > best.fits || fits === best.fits && d < best.d) best = {g: g, d: d, fits: fits};
            });
            if (!best) {
                ctx.step = before;
                ctx.regrid = 0;
                show(ctx, msgs.concat([{kind: 'warn', html: esc(t('RereadNone'))}]));
                return null;
            }
            var g = movedGrid(best.g, part.x0, part.y0), c = centre(g), s = g.scale || 1;
            ctx.analysis = g;
            ctx.at = {x: c.x / s, y: c.y / s};
            ctx.live = null;
            ctx.liveFit = ctx.liveBase = null;
            ctx.seedCells = null;
            ctx.liveLocked = {};
            ctx.regrid = 0;
            ctx.fit = ctx.reading = ctx.crops = null;
            drawPhoto();
            if (ctx.card) return showCard(ctx, ctx.card);
            if (!ctx.barcode) ctx.barcodeP = barcodeOf(g, null);
            return openCards([ctx]);
        }).then(function () {
            if (gen !== state.gen) return;
            offerArchers();
            renderSheet();
            drawPhoto();
        });
    }

    /**
     * The whole photo searched again, closer, for the places without a grid: the grids on none
     * of the cards there, of one of the competition's layouts, as many as the sheet lacks.
     */
    function researchSheet() {
        var full = state.full, gen = state.gen;
        if (!full || state.working) return;
        state.working = true;
        status([busyLine(t('RereadSearching'))]);
        renderSheet();
        var side = Math.min(RESEARCH_SIDE, Math.max(full.w, full.h));
        Promise.all([analysis(full, {all: true, workSide: side}), loadLayouts().then(null, function () { return []; })]).then(function (r) {
            if (gen !== state.gen) return null;
            var a = r[0], lays = r[1] || [], boxes = state.sheet.map(cardBox).filter(Boolean);
            var fresh = (a.ok ? a.grids : []).filter(function (g) {
                var c = centre(g), s = g.scale || 1, x = c.x / s, y = c.y / s;
                if (boxes.some(function (bx) { return x >= bx.x0 && x <= bx.x1 && y >= bx.y0 && y <= bx.y1; })) return false;
                return !lays.length || lays.some(function (lay) { return Lay.fit(V, g, lay, null).ok; });
            });
            fresh = order(sheetGrids(fresh, lays, Math.max(1, expectedGrids() - state.sheet.length)));
            state.working = false;
            if (!fresh.length) {
                status([{kind: 'warn', html: esc(t('RereadNone'))}]);
                return null;
            }
            status([]);
            var added = fresh.map(function (g) {
                var c = centre(g), s = g.scale || 1, ctx = {n: state.sheet.length, analysis: g, msgs: [], step: 'busy', at: {x: c.x / s, y: c.y / s}};
                ctx.barcodeP = barcodeOf(g, null);
                state.sheet.push(ctx);
                return ctx;
            });
            if (!state.view) state.view = added[0];
            drawPhoto();
            renderSheet();
            return openCards(added);
        }).then(function () {
            if (gen !== state.gen) return;
            state.working = false;
            offerArchers();
            renderSheet();
        });
    }

    /** Cards found afresh: whose each is, their cards asked of ianseo, then read (as readSheet does). */
    function openCards(list) {
        var gen = state.gen, settle = function (p) { return Promise.resolve(p).then(null, function () { return null; }); };
        var asked = list.map(function (ctx) { return settle(identify(ctx)); });
        return Promise.all(asked).then(function (params) {
            if (gen !== state.gen) return null;
            return Promise.all(list.map(function (ctx, i) { return settle(params[i] && lookup(ctx, params[i])); }));
        });
    }

    el('ocr-sheet').addEventListener('click', function (e) {
        var rr = e.target.closest && e.target.closest('button[data-reread]');
        if (rr) {
            reread(state.sheet[+rr.getAttribute('data-reread')]);
            return;
        }
        if (e.target.closest && e.target.closest('button[data-research]')) {
            researchSheet();
            return;
        }
        var b = e.target.closest && e.target.closest('button[data-card]');
        if (b) view(state.sheet[+b.getAttribute('data-card')]);
        var wb = e.target.closest && e.target.closest('button[data-write]');
        if (wb) writeCard(state.sheet[+wb.getAttribute('data-write')]);
        var bk = e.target.closest && e.target.closest('button[data-bulk]');
        if (bk) {
            var bc = state.sheet[+bk.getAttribute('data-of')];
            if (!bc || !bc.reading) return;
            if (bk.getAttribute('data-bulk') === 'arrows') {
                doubtArrows(bc).forEach(function (a) { bc.reading.forced[a.line + '.' + a.index] = a.value; });
                resolveReading(bc);
            } else {
                bc.reading.forcedNum = bc.reading.forcedNum || {};
                totalsToSet(bc).forEach(function (k) { bc.reading.forcedNum['total.' + k] = numberOf(bc, 'total', k).calc; });
            }
            renderSheet();
        }
        var z = e.target.closest && e.target.closest('button[data-set]');
        if (z) {
            var ctx = state.sheet[+z.getAttribute('data-of')], k = z.getAttribute('data-set'), v = z.getAttribute('data-value');
            if (!ctx || !ctx.reading) return;
            ctx.reading.forcedNum = ctx.reading.forcedNum || {};
            if (v === '') delete ctx.reading.forcedNum[k];
            else ctx.reading.forcedNum[k] = +v;
            renderSheet();
        }
        // The archer of another session, taken (its card's note).
        var u = e.target.closest && e.target.closest('button[data-use]'), col = u && u.closest('.ocr-col[data-card]');
        if (u && col) {
            var c2 = state.sheet[+col.getAttribute('data-card')], ss = el('ocr-session');
            if (!c2) return;
            if (u.getAttribute('data-session') && ss) ss.value = u.getAttribute('data-session');
            lookup(c2, {action: 'card', enId: u.getAttribute('data-use'), dist: currentDist()});
        }
    });

    /** What the cards' colours and signs tell. */
    function cardsLegend() {
        var item = function (html, text) { return '<li>' + html + ' ' + esc(t(text)) + '</li>'; };
        var box = function (c) { return '<span class="ocr-key ocr-key-sum ocr-sum-' + c + '"></span>'; };
        return '<summary>' + esc(t('CardsLegend')) + '</summary><ul class="ocr-legend">'
            + item(box('ok'), 'LegendCardOk') + item(box('doubt'), 'LegendCardDoubt') + item(box('bad'), 'LegendCardBad') + item(box('busy'), 'LegendCardBusy')
            + item(icon('eye', 'ok', '') + icon('eye', 'doubt', '') + icon('eye', 'bad', ''), 'LegendEye')
            + item(icon('check', 'ok', '') + icon('check', 'doubt', ''), 'LegendCheck')
            + item(icon('cross', 'bad', '') + icon('cross', 'doubt', ''), 'LegendCross')
            + item(icon('warn', 'bad', '') + icon('warn', 'doubt', ''), 'LegendWarn')
            + item('<span class="ocr-alerts">2</span>', 'LegendAlerts')
            + item('<span class="ocr-key ocr-key-crop-doubt"></span>', 'LegendCropDoubt')
            + item('<span class="ocr-key ocr-key-crop-run"></span>', 'LegendCropRun')
            + item('<span class="ocr-key ocr-key-crop-none"></span>', 'LegendCropNone')
            + item('<span class="ocr-read ocr-ok">26</span>/<small>26</small> <span class="ocr-held">26</span>', 'LegendNumbers')
            + '</ul>';
    }

    /** The archers to choose from, for the card on view when it is not identified. */
    function renderArcher(ctx) {
        var box = el('ocr-archer');
        if (!ctx || !ctx.candidates) {
            box.innerHTML = '';
            return;
        }
        var html = '<p>' + esc(t('Candidates')) + '</p><div class="ocr-buttons">';
        ctx.candidates.list.forEach(function (c) {
            html += '<button type="button" class="ocr-btn" data-en="' + c.enId + '">'
                + esc(c.target + ' - ' + c.name + ' (' + c.div + ' ' + c.cls + ')') + '</button>';
        });
        box.innerHTML = html + '</div>';
    }

    /** The bounds of a fitted table's cells, in its straightened frame. */
    function tableBox(fit) {
        var b = {u0: Infinity, v0: Infinity, u1: -Infinity, v1: -Infinity};
        fit.cells.forEach(function (c) {
            b.u0 = Math.min(b.u0, c.u0);
            b.v0 = Math.min(b.v0, c.v0);
            b.u1 = Math.max(b.u1, c.u1);
            b.v1 = Math.max(b.v1, c.v1);
        });
        return b;
    }

    function tableOutline(ctx, fit, s) {
        var b = tableBox(fit);
        ctx.beginPath();
        [[b.u0, b.v0], [b.u1, b.v0], [b.u1, b.v1], [b.u0, b.v1]].forEach(function (q, k) {
            var p = V.apply(fit.frame.Hfull, q[0], q[1]);
            if (k) ctx.lineTo(p.x * s, p.y * s);
            else ctx.moveTo(p.x * s, p.y * s);
        });
        ctx.closePath();
        ctx.stroke();
    }

    /** The outline of a grid in the photo, for the canvas (scale s). */
    function outline(ctx, a, s) {
        var f = V.frame(a, a.guess || 0);
        if (!f) return;
        ctx.beginPath();
        [[0, 0], [f.W, 0], [f.W, f.Hb], [0, f.Hb]].forEach(function (q, k) {
            var p = V.apply(f.Hfull, q[0], q[1]);
            if (k) ctx.lineTo(p.x * s, p.y * s);
            else ctx.moveTo(p.x * s, p.y * s);
        });
        ctx.closePath();
        ctx.stroke();
    }

    function drawPhoto() {
        var c = el('ocr-canvas'), src = state.source;
        if (!src) return;
        var s = Math.min(1, 1600 / Math.max(src.width, src.height)), w = Math.round(src.width * s), h = Math.round(src.height * s), sz = viewSize(w, h);
        c.width = sz.w;
        c.height = sz.h;
        syncButtons();
        // Folded away: drawn when unfolded.
        if (state.folded) return;
        // Turned and mirrored as the view is.
        var g = c.getContext('2d'), M = viewMatrix(w, h);
        g.setTransform(M[0], M[1], M[2], M[3], M[4], M[5]);
        g.drawImage(src, 0, 0, w, h);
        g.lineWidth = Math.max(1.5, w / 600);
        // Every grid of the sheet: in its cells once fitted, else its outline.
        state.sheet.forEach(function (ctx) {
            if (ctx.fit && ctx.fit.cells) photoCells(g, ctx.fit, s);
            else if (ctx.analysis) {
                g.strokeStyle = '#7d8183';
                outline(g, ctx.analysis, s);
            }
        });
        g.setTransform(1, 0, 0, 1, 0, 0);
    }

    /** A fitted grid on the picture: the cells the core prints grey, the cells read. */
    function photoCells(g, f, s) {
        var Hf = f.frame.Hfull;
        g.fillStyle = 'rgba(142, 68, 173, 0.28)';
        (f.marks || []).forEach(function (cell) {
            g.beginPath();
            [[cell.u0, cell.v0], [cell.u1, cell.v0], [cell.u1, cell.v1], [cell.u0, cell.v1]].forEach(function (q, k) {
                var p = V.apply(Hf, q[0], q[1]);
                if (k) g.lineTo(p.x * s, p.y * s);
                else g.moveTo(p.x * s, p.y * s);
            });
            g.closePath();
            g.fill();
        });
        f.cells.forEach(function (cell) {
            g.strokeStyle = cell.kind === 'arrow' ? '#0254a8' : cell.kind === 'count' ? '#e57b10' : '#04ac0b';
            g.beginPath();
            [[cell.u0, cell.v0], [cell.u1, cell.v0], [cell.u1, cell.v1], [cell.u0, cell.v1]].forEach(function (q, k) {
                var p = V.apply(Hf, q[0], q[1]);
                if (k) g.lineTo(p.x * s, p.y * s);
                else g.moveTo(p.x * s, p.y * s);
            });
            g.closePath();
            g.stroke();
        });
    }

    // A click on a grid of the photo shows that card.
    el('ocr-canvas').addEventListener('click', function (e) {
        if (state.sheet.length < 2 || !state.source) return;
        // Where the click falls on the picture, the view's turn and mirror undone.
        var c = el('ocr-canvas'), r = c.getBoundingClientRect();
        var cx =(e.clientX - r.left) / r.width * c.width, cy = (e.clientY - r.top) / r.height * c.height;
        var pw = viewT.rot % 2 ? c.height : c.width, ph = viewT.rot % 2 ? c.width : c.height, M = viewMatrix(pw, ph), det = M[0] * M[3] - M[1] * M[2];
        var dx = cx - M[4], dy = cy - M[5], u = (M[3] * dx - M[2] * dy) / det, vv = (-M[1] * dx + M[0] * dy) / det;
        var x = u / pw * state.source.width, y = vv / ph * state.source.height;
        state.sheet.some(function (ctx) {
            var f, b, inv, q;
            if (ctx.analysis) {
                f = V.frame(ctx.analysis, ctx.analysis.guess || 0);
                b = f && {u0: 0, v0: 0, u1: f.W, v1: f.Hb};
            } else if (ctx.fit) {
                f = ctx.fit.frame;
                b = tableBox(ctx.fit);
            }
            inv = f && V.invert(f.Hfull);
            if (!inv) return false;
            q = V.apply(inv, x, y);
            if (q.x < b.u0 || q.y < b.v0 || q.x > b.u1 || q.y > b.v1) return false;
            view(ctx);
            return true;
        });
    });

    /** Picture of one cell, cut out of the full photo, a little inside its borders. */
    function cellCanvas(ctx, cell, height) {
        var Hf = ctx.fit.frame.Hfull, du = (cell.u1 - cell.u0) * 0.08, dv = (cell.v1 - cell.v0) * 0.1;
        var aspect = (cell.u1 - cell.u0) / (cell.v1 - cell.v0), w = Math.max(8, Math.round(height * aspect));
        var g = V.crop(state.full, Hf, cell.u0 + du, cell.v0 + dv, cell.u1 - du, cell.v1 - dv, w, height);
        var c = document.createElement('canvas');
        c.width = w;
        c.height = height;
        var gc = c.getContext('2d'), img = gc.createImageData(w, height);
        for (var i = 0; i < g.d.length; i++) {
            img.data[4 * i] = img.data[4 * i + 1] = img.data[4 * i + 2] = g.d[i];
            img.data[4 * i + 3] = 255;
        }
        gc.putImageData(img, 0, 0);
        c.className = 'ocr-crop';
        return c;
    }

    /** What ianseo holds for one printed line: arrows, sums and counts as on the card. */
    function ianseoLine(card, p, line) {
        var byPrint = {};
        card.values.forEach(function (v) { byPrint[v.p] = v; });
        var arrows = card.ianseo.arrows, out = {arrows: [], line: 0, gold: 0, xnine: 0, any: false};
        for (var j = 0; j < p.perLine; j++) {
            var v = arrows[(line - 1) * p.perLine + j] || '';
            out.arrows.push(v);
            var d = byPrint[v.replace('*', '')];
            if (v !== '') out.any = true;
            if (d) {
                out.line += d.n;
                if (d.g) out.gold++;
                if (d.x) out.xnine++;
            }
        }
        var cum = 0, endSum = 0, perEnd = Math.max(1, p.arrows / p.perLine), gapCum = false, gapEnd = false;
        for (var k = 0; k < line * p.perLine && k < arrows.length; k++) {
            var e = byPrint[(arrows[k] || '').replace('*', '')];
            if (e) cum += e.n;
            else gapCum = true;
        }
        var first = line - ((line - 1) % perEnd);
        for (k = (first - 1) * p.perLine; k < line * p.perLine && k < arrows.length; k++) {
            var e2 = byPrint[(arrows[k] || '').replace('*', '')];
            if (e2) endSum += e2.n;
            else gapEnd = true;
        }
        out.cum = cum;
        out.end = endSum;
        // Some arrows the number counts not entered: what ianseo holds of it is partial.
        out.partial = {line: out.arrows.indexOf('') >= 0, end: gapEnd, cum: gapCum};
        out.partial.gold = out.partial.xnine = out.partial.line;
        return out;
    }

    /**
     * A cell's picture, its frame telling the reading (none: sure; orange: in doubt), cut once
     * per card's fit.
     */
    function cropOf(ctx, cell, height) {
        var key = cell.line + '.' + cell.key;
        if (!ctx.crops || ctx.crops.fit !== ctx.fit || ctx.crops.h !== height) ctx.crops = {fit: ctx.fit, h: height};
        if (!ctx.crops[key]) ctx.crops[key] = cellCanvas(ctx, cell, height);
        return ctx.crops[key];
    }

    /**
     * An arrow's cell: its value (no value held by ianseo: green when sure, black when in
     * doubt; held: green when the same, red framed when not, sure or not), a click offers the
     * values of the archer's face.
     */
    function arrowCell(ctx, td, cell, line, index) {
        var x = arrowOf(ctx, line, index), crop = cropOf(ctx, cell, cropH);
        if (!x.read) td.classList.add('ocr-notread');
        crop.classList.toggle('ocr-crop-doubt', x.read && !x.sure);
        td.appendChild(crop);
        if (!x.a) return;
        var s = document.createElement('span'), tone = x.held !== '' ? (x.held === x.a.value ? 'ok' : 'bad') : x.sure ? 'ok' : '';
        s.className = 'ocr-read' + (tone ? ' ocr-' + tone : '') + (x.a.forced ? ' ocr-forced' : '');
        s.textContent = x.a.value;
        s.title = x.a.forced ? t('TitleForced') : Math.round(x.a.conf * 100) + ' %';
        td.appendChild(s);
        if (x.held !== '') {
            var h = document.createElement('span');
            h.className = 'ocr-held';
            h.textContent = x.held;
            td.appendChild(h);
            if (x.held !== x.a.value) {
                td.classList.add('ocr-diff');
                td.title = t('TitleIanseo', x.held);
            }
        }
        td.classList.add('ocr-editable');
        td.addEventListener('click', function () { palette(td, ctx, line, index); });
    }

    /**
     * A written number's cell: read / calculated, and what ianseo holds (blue). Read: green
     * when it is what the arrows give (and what ianseo holds), red when not; calculated: red
     * when ianseo holds otherwise. Framed red: wrong for sure, or not what ianseo holds. A
     * signed total left blank: a button gives it 0 (the rule: blank, it counts 0, no recount).
     */
    function numberCell(ctx, td, cell, line, key) {
        var n = numberOf(ctx, line, key), crop = cropOf(ctx, cell, cropH), signed = line === 'total';
        if (n.read === null) td.classList.add('ocr-notread');
        // The sums, running totals and counts of the lines apart from the arrows and the
        // totals signed: those decide, these only check.
        crop.classList.toggle('ocr-crop-doubt', signed && n.read !== null && !n.sure);
        crop.classList.toggle('ocr-crop-run', !signed && n.read !== null && !n.sure);
        td.appendChild(crop);
        if (n.joined) {
            td.title = t('TitleJoined');
            return;
        }
        var v = asCounted(n, key, signed && n.forced), hc = heldCmp(n);
        var off = n.read !== null && n.read !== -1 && (v !== n.calc || (hc !== null && v !== hc));
        var s = document.createElement('span');
        s.className = 'ocr-read' + (n.read === null || n.read === -1 && !off ? '' : off ? ' ocr-bad' : ' ocr-ok');
        s.innerHTML = esc(n.read === null || n.read === -1 && !n.forced ? '' : String(v))
            + '/<small class="ocr-calc' + (hc !== null && n.calc !== hc ? ' ocr-bad' : '') + '">' + esc(n.calc === null ? '-' : String(n.calc)) + '</small>';
        td.appendChild(s);
        if (n.held !== null) {
            var h = document.createElement('span');
            h.className = 'ocr-held';
            h.textContent = n.held;
            if (n.partial) h.title = t('TitleIanseoPartial', n.held);
            td.appendChild(h);
        }
        if (n.bad && n.sure || n.read !== null && n.read !== -1 && hc !== null && v !== hc) {
            // Red kept for what is written into ianseo (the arrows, the Total row's signed
            // totals); orange for what only checks them (an end's sums, counts, running totals).
            td.classList.add('ocr-diff');
            if (!signed) td.classList.add('ocr-diff-check');
            td.title = n.bad ? t(signed ? 'TitleTotalWrong' : 'TitleSheet', {read: n.bad.read, want: n.bad.want}) : t('TitleIanseo', n.held);
        }
        if (!signed) return;
        var button = function (value, text, hint) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'ocr-btn ocr-zero';
            b.setAttribute('data-set', 'total.' + key);
            b.setAttribute('data-value', value === null ? '' : String(value));
            b.setAttribute('data-of', String(ctx.n));
            b.textContent = text;
            b.title = hint;
            td.appendChild(b);
        };
        if (n.forced) {
            button(null, t('Undo'), t('UndoHint'));
            return;
        }
        var blank = n.read === -1 && !notShot(ctx.fit, ctx.reading.reads);
        if (blank) {
            td.classList.add('ocr-blank-total');
            td.title = t('TitleTotalBlank');
            button(0, t('SetZero'), t('SetZeroHint'));
        }
        // What the arrows make, taken by the operator (a total misread, or the archer's mistake
        // kept right: the paper sheet to be corrected). Left blank where they make 0: the one button.
        if (n.calc !== null && !(blank && n.calc === 0) && (blank || v !== n.calc || !n.sure)) button(n.calc, '= ' + n.calc, t('SetCalcHint'));
    }

    /** A card's cells, as on the scorecard. */
    function cardCells(ctx) {
        if (!ctx || !ctx.fit || !ctx.fit.ok || !ctx.card || !ctx.reading) return null;
        var f = ctx.fit, card = ctx.card, p = f.printed, byLine = {};
        f.cells.forEach(function (c) { (byLine[c.line] = byLine[c.line] || {})[c.key] = c; });
        var keys = [];
        for (var j = 0; j < p.perLine; j++) keys.push('a' + j);
        if (p.perLine > 1) keys.push('line');
        if (p.arrows >= 6) keys.push('end');
        keys.push('cum');
        if (f.grand) keys.push('grand');
        if (card.structure.golds) keys.push('gold');
        keys.push('xnine');
        var heads = {line: t('ColLine'), end: t('ColEndTotal'), cum: t('ColRunning'), grand: t('ColGrand'),
            gold: card.structure.goldsLabel, xnine: card.structure.xnineLabel};
        var table = document.createElement('table');
        table.className = 'ocr-grid';
        // The ends' column untitled (its numbers tell), as narrow as they are.
        var head = '<tr><th></th>';
        keys.forEach(function (k) { head += '<th>' + esc(k.charAt(0) === 'a' ? String(+k.slice(1) + 1) : heads[k]) + '</th>'; });
        table.innerHTML = '<thead>' + head + '</tr></thead>';
        var tbody = document.createElement('tbody');
        var perEnd = Math.max(1, p.arrows / p.perLine);
        var row = function (line, title) {
            var tr = document.createElement('tr'), th = document.createElement('th'), merged = null;
            if (line === 'total') {
                // Written upwards: the column no wider than the ends' numbers.
                th.innerHTML = '<span class="ocr-vert">' + esc(title) + '</span>';
            } else {
                th.textContent = title;
            }
            tr.appendChild(th);
            keys.forEach(function (k) {
                var td = document.createElement('td'), cell = byLine[line] && byLine[line][k];
                // The total row's cells without a total, one after the other: one cell, the
                // card's actions in it (beside the totals, at the bottom of a long card).
                if (!cell && line === 'total') {
                    if (merged) {
                        merged.colSpan++;
                        return;
                    }
                    merged = td;
                    td.className = 'ocr-none ocr-acts';
                    td.innerHTML = cardActions(ctx);
                    tr.appendChild(td);
                    return;
                }
                merged = null;
                if (!cell) td.className = 'ocr-crossed';
                else if (k.charAt(0) === 'a') arrowCell(ctx, td, cell, line, +k.slice(1));
                else numberCell(ctx, td, cell, line, k);
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        };
        for (var line = 1; line <= p.lines; line++) row(line, (line - 1) % perEnd === 0 ? String(Math.floor((line - 1) / perEnd) + 1) : '');
        row('total', t('RowTotal'));
        table.appendChild(tbody);
        var wrap = document.createElement('div');
        wrap.className = 'ocr-scroll';
        wrap.appendChild(table);
        return wrap;
    }

    window.addEventListener('resize', function () { if (state.source) drawPhoto(); });

    // The reading workers start once the live view's are in (liveResult), or a while after the
    // page without a camera: their networks are in before the first photo. Not while the live
    // view's worker loads its own (it comes first; its first search for grids starts them).
    setTimeout(function () { if (!live || live.up) readPool(); }, 8000);
});
