/**
 * Series capture of scorecards.
 *
 * The camera stays on. A few times a second a reduced frame is analysed; when a grid
 * is found, large enough, and still over two frames, a full-resolution photo is taken
 * (the camera's own still when the browser offers it, the video frame otherwise), its
 * barcode is read, and it is sent to the server in the background. The same card is
 * not taken twice: a new photo needs the card to have left the field, or the content
 * of the straightened table to have changed (another card put in its place).
 */
// The core's page template loads scripts in the head, before the page exists.
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var root = document.getElementById('ocr');
    if (!root || !document.getElementById('ocr-start')) return;
    var V = window.OcrVision, T = window.OCR_T || {};
    var api = root.getAttribute('data-api'), csrf = root.getAttribute('data-csrf');

    var DET_SIDE = 960;     // side of the frames analysed while waiting for a card
    var TICK = 150;         // pause between two analyses, ms
    var STABLE = 2;         // frames a card must stay still before it is taken
    var GONE = 2;           // frames without a card before it counts as removed
    // Mean difference, in grey levels, of 64-pixel thumbnails of the whole frame:
    // a card held still stays near 1 (more for a shaking hand), a moving one is over 10.
    var STILL = 4;          // between two frames: the camera and the card are still
    var MOVED = 8;          // since the last photo: the card was moved or replaced
    var CHANGE = 0.75;      // difference of table pictures that makes another card

    function el(id) { return document.getElementById(id); }

    function t(key, a) {
        var s = T[key] !== undefined ? T[key] : key;
        if (a === undefined || a === null) return s;
        if (typeof a !== 'object') return s.split('{$a}').join(String(a));
        Object.keys(a).forEach(function (k) { s = s.split('{$a[' + k + ']}').join(String(a[k])); });
        return s;
    }

    var video = el('ocr-video'), overlay = el('ocr-overlay'), det = document.createElement('canvas');
    var st = {stream: null, track: null, still: null, running: false, busy: false, timer: 0, prev: null,
        stable: 0, gone: 0, removed: true, lastSig: null, lastThumb: null, audio: null, wake: null,
        uploads: Promise.resolve(), pending: 0, taken: 0, holdUntil: 0};

    // Over plain http there is no live camera: the phone's own camera and its gallery
    // remain, so they become the main buttons.
    if (window.OcrSecure && window.OcrSecure.explain(el('ocr-secure'), t, 'CaptureInsecure')) {
        el('ocr-start').hidden = true;
        el('ocr-photo-label').classList.add('ocr-btn-primary');
    }

    /**
     * Status line. A message about a photo (taken, failed) stays a moment: the
     * watching goes on meanwhile and would otherwise replace it at once.
     */
    function say(text, kind, hold) {
        var now = Date.now();
        if (!hold && now < st.holdUntil) return;
        st.holdUntil = hold ? now + hold : 0;
        var s = el('ocr-state');
        s.textContent = text;
        s.className = 'ocr-state' + (kind ? ' ocr-state-' + kind : '');
    }
    say(t('CaptureIdle'));

    // ----------------------------------------------------------------- camera

    el('ocr-start').addEventListener('click', function () {
        navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {facingMode: {ideal: 'environment'}, width: {ideal: 3840}, height: {ideal: 2160}}
        }).then(function (stream) {
            st.stream = stream;
            st.track = stream.getVideoTracks()[0];
            st.still = null;
            if ('ImageCapture' in window) {
                try { st.still = new window.ImageCapture(st.track); } catch (e) { st.still = null; }
            }
            video.srcObject = stream;
            video.hidden = overlay.hidden = false;
            el('ocr-start').hidden = true;
            el('ocr-stop').hidden = el('ocr-shot-now').hidden = false;
            try {
                st.audio = st.audio || new (window.AudioContext || window.webkitAudioContext)();
            } catch (e) { st.audio = null; }
            if (navigator.wakeLock) navigator.wakeLock.request('screen').then(function (w) { st.wake = w; }).catch(function () {});
            return video.play();
        }).then(function () {
            st.running = true;
            st.removed = true;
            say(t('CaptureWaiting'));
            loop();
        }).catch(function (err) {
            say(t('CameraError', err && err.message ? err.message : String(err)), 'err');
        });
    });

    function stop() {
        st.running = false;
        clearTimeout(st.timer);
        if (st.stream) st.stream.getTracks().forEach(function (tr) { tr.stop(); });
        st.stream = st.track = st.still = null;
        if (st.wake) st.wake.release().catch(function () {});
        st.wake = null;
        video.hidden = overlay.hidden = true;
        el('ocr-start').hidden = false;
        el('ocr-stop').hidden = el('ocr-shot-now').hidden = true;
        say(t('CaptureIdle'));
    }
    el('ocr-stop').addEventListener('click', stop);

    document.addEventListener('visibilitychange', function () {
        if (document.hidden && st.running) stop();
    });

    // ---------------------------------------------------------------- watching

    function loop() {
        clearTimeout(st.timer);
        if (st.running) st.timer = setTimeout(tick, TICK);
    }

    function tick() {
        if (!st.running) return;
        if (st.busy || !video.videoWidth) return loop();
        var vw = video.videoWidth, vh = video.videoHeight, s = Math.min(1, DET_SIDE / Math.max(vw, vh));
        var w = Math.round(vw * s), h = Math.round(vh * s);
        det.width = w;
        det.height = h;
        var ctx = det.getContext('2d', {willReadFrequently: true});
        ctx.drawImage(video, 0, 0, w, h);
        var gray = V.fromRGBA(ctx.getImageData(0, 0, w, h).data, w, h);
        // Stillness from the whole frame: the outer lines found may change from one
        // frame to the next, a thumbnail of the picture does not.
        var thumb = V.downscale(gray, 64).img.d;
        var still = st.prev ? meanDiff(thumb, st.prev) < STILL : false;
        st.prev = thumb;
        var a = V.analyse(gray, {workSide: DET_SIDE, minSide: 0});
        // A portrait card in a landscape frame, header and margins around the table,
        // leaves the table about a tenth of the picture: anything much smaller is a
        // card seen from too far to be read.
        var found = a.ok && area(a.quad) >= 0.04 * w * h;
        draw(found ? a.quad : null, w, still ? '#04ac0b' : '#e57b10');

        if (!found) {
            st.stable = 0;
            if (++st.gone >= GONE) st.removed = true;
            say(t('CaptureWaiting'));
            return loop();
        }
        st.gone = 0;
        // A card found on STABLE frames in a row, the picture not moving between them.
        st.stable = still ? st.stable + 1 : 0;
        if (st.stable < STABLE - 1) {
            say(t('CaptureHold'));
            return loop();
        }
        // Another card once the last one has left the field (the hand hides it while
        // cards are swapped), or has been moved or replaced, or reads clearly different.
        var sig = signature(a);
        var other = st.removed || !st.lastThumb || meanDiff(thumb, st.lastThumb) > MOVED
            || !st.lastSig || difference(sig, st.lastSig) > CHANGE;
        if (!other) {
            say(t('CaptureSame'), 'ok');
            return loop();
        }
        take(a, sig, s, det).then(loop, loop);
    }

    function area(q) {
        var p = [q.tl, q.tr, q.br, q.bl], s = 0;
        for (var i = 0; i < 4; i++) s += p[i].x * p[(i + 1) % 4].y - p[(i + 1) % 4].x * p[i].y;
        return Math.abs(s) / 2;
    }

    function meanDiff(a, b) {
        if (a.length !== b.length) return Infinity;
        var d = 0;
        for (var i = 0; i < a.length; i++) d += Math.abs(a[i] - b[i]);
        return d / a.length;
    }

    /**
     * Small normalised picture of the grid's box: two cards differ by the writing on
     * them. The box of the grid's blot is steadier from frame to frame than the
     * straightened frame, whose outer lines may change.
     */
    function signature(a) {
        var b = a.gridBox, out = [], sum = 0, sq = 0, i, j;
        for (j = 0; j < 24; j++) {
            for (i = 0; i < 32; i++) {
                var v = V.sample(a.work, b.x0 + (i + 0.5) / 32 * (b.x1 - b.x0), b.y0 + (j + 0.5) / 24 * (b.y1 - b.y0));
                if (isNaN(v)) v = 255;
                out.push(v);
                sum += v;
                sq += v * v;
            }
        }
        var n = out.length, mean = sum / n, sd = Math.sqrt(Math.max(1, sq / n - mean * mean));
        return out.map(function (v) { return (v - mean) / sd; });
    }

    function difference(a, b) {
        var d = 0;
        for (var i = 0; i < a.length; i++) d += Math.abs(a[i] - b[i]);
        return d / a.length;
    }

    function draw(q, w, colour) {
        var cw = video.clientWidth, ch = video.clientHeight;
        if (overlay.width !== cw || overlay.height !== ch) {
            overlay.width = cw;
            overlay.height = ch;
        }
        var ctx = overlay.getContext('2d');
        ctx.clearRect(0, 0, cw, ch);
        if (!q) return;
        var k = cw / w;
        ctx.strokeStyle = colour;
        ctx.lineWidth = 4;
        ctx.beginPath();
        [q.tl, q.tr, q.br, q.bl].forEach(function (p, i) {
            if (i) ctx.lineTo(p.x * k, p.y * k);
            else ctx.moveTo(p.x * k, p.y * k);
        });
        ctx.closePath();
        ctx.stroke();
    }

    // ------------------------------------------------------------------ taking

    function signal() {
        var wrap = video.parentNode;
        wrap.classList.add('ocr-flash');
        setTimeout(function () { wrap.classList.remove('ocr-flash'); }, 250);
        if (navigator.vibrate) navigator.vibrate(60);
        if (st.audio && el('ocr-sound').checked) {
            var o = st.audio.createOscillator(), g = st.audio.createGain();
            o.frequency.value = 1320;
            g.gain.value = 0.15;
            o.connect(g);
            g.connect(st.audio.destination);
            o.start();
            o.stop(st.audio.currentTime + 0.09);
        }
    }

    function frameBlob() {
        var c = document.createElement('canvas');
        c.width = video.videoWidth;
        c.height = video.videoHeight;
        c.getContext('2d').drawImage(video, 0, 0);
        return new Promise(function (resolve) { c.toBlob(resolve, 'image/jpeg', 0.92); });
    }

    /** A still in another format (some cameras give PNG) becomes a JPEG, the format kept. */
    function asJpeg(blob) {
        if (blob.type === 'image/jpeg') return Promise.resolve(blob);
        return createImageBitmap(blob).then(function (bmp) {
            var c = document.createElement('canvas');
            c.width = bmp.width;
            c.height = bmp.height;
            c.getContext('2d').drawImage(bmp, 0, 0);
            return new Promise(function (resolve) { c.toBlob(resolve, 'image/jpeg', 0.92); });
        });
    }

    /** The camera's own still, at the sensor's resolution, or the video frame. */
    function shoot() {
        if (!st.still) return frameBlob().then(function (b) { return {blob: b, source: 'video'}; });
        return st.still.takePhoto().then(function (b) {
            if (!b || !b.size) return frameBlob().then(function (f) { return {blob: f, source: 'video'}; });
            return asJpeg(b).then(function (j) { return {blob: j, source: 'photo'}; });
        }, function () {
            return frameBlob().then(function (b) { return {blob: b, source: 'video'}; });
        });
    }

    // The barcode of each photo is read away from the page, which goes on watching.
    var worker = null, jobs = {}, jobId = 0;
    try {
        worker = new Worker(root.getAttribute('data-worker'));
        worker.onmessage = function (e) {
            var done = jobs[e.data.id];
            delete jobs[e.data.id];
            if (done) done(e.data);
        };
    } catch (e) { worker = null; }

    /** Barcode of a photo taken: {text, turn}, text '' when none was read. */
    function readBarcode(blob) {
        if (!worker) return Promise.resolve({text: '', turn: null});
        return createImageBitmap(blob).then(function (bmp) {
            return new Promise(function (resolve) {
                var id = ++jobId;
                jobs[id] = resolve;
                worker.postMessage({id: id, bitmap: bmp}, [bmp]);
                setTimeout(function () {
                    if (jobs[id]) {
                        delete jobs[id];
                        resolve({text: '', turn: null});
                    }
                }, 15000);
            });
        }).catch(function () { return {text: '', turn: null}; });
    }

    /** Does a still still show a grid? The camera may have fired after the card left. */
    function showsGrid(blob) {
        return createImageBitmap(blob).then(function (bmp) {
            var k = Math.min(1, DET_SIDE / Math.max(bmp.width, bmp.height)), c = document.createElement('canvas');
            c.width = Math.round(bmp.width * k);
            c.height = Math.round(bmp.height * k);
            var ctx = c.getContext('2d', {willReadFrequently: true});
            ctx.drawImage(bmp, 0, 0, c.width, c.height);
            return V.analyse(V.fromRGBA(ctx.getImageData(0, 0, c.width, c.height).data, c.width, c.height),
                {workSide: DET_SIDE, minSide: 0}).ok;
        }).catch(function () { return false; });
    }

    /**
     * Take the card in front of the camera: the video frame of the moment it was seen
     * still, and the camera's own still when it shows the card too (it is finer).
     * The signal comes once the photo is secured, so the card may then go.
     */
    function take(a, sig, s, detCanvas) {
        st.busy = true;
        st.force = false;
        say(t('CaptureTaking'), 'ok', 600);
        var thumb = thumbnail(detCanvas), quad = null;
        if (a) {
            quad = {};
            ['tl', 'tr', 'br', 'bl'].forEach(function (k) { quad[k] = [Math.round(a.quad[k].x / s), Math.round(a.quad[k].y / s)]; });
        }
        var frame = frameBlob();
        var still = st.still ? shoot().then(function (shot) {
            if (shot.source !== 'photo') return null;
            return showsGrid(shot.blob).then(function (ok) { return ok ? shot : null; });
        }).catch(function () { return null; }) : Promise.resolve(null);
        return Promise.all([frame, still]).then(function (r) {
            var shot = r[1] || {blob: r[0], source: 'video'};
            st.lastSig = sig;
            st.lastThumb = st.prev;
            st.removed = false;
            st.stable = 0;
            st.busy = false;
            signal();
            var n = ++st.taken;
            say(t('CaptureTaken', n), 'ok', 1500);
            readBarcode(shot.blob).then(function (code) {
                send(shot.blob, {source: shot.source, quad: quad, barcode: code.text, turn: code.turn}, thumb, n);
            });
        }).catch(function (err) {
            st.busy = false;
            say(t('CaptureError', err && err.message ? err.message : String(err)), 'err', 4000);
        });
    }

    // A card the watching does not catch can still be taken by hand.
    el('ocr-shot-now').addEventListener('click', function () {
        if (st.running && !st.busy && det.width) take(null, null, 1, det);
    });

    function thumbnail(src) {
        var c = document.createElement('canvas'), k = 120 / Math.max(src.width, src.height);
        c.width = Math.round(src.width * k);
        c.height = Math.round(src.height * k);
        c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
        c.className = 'ocr-thumb';
        return c;
    }

    /** Uploads go one after the other, while the camera goes on watching. */
    function send(blob, meta, thumb, n) {
        st.pending++;
        if (meta.barcode) say(t('CaptureTakenCode', {n: n, code: meta.barcode}), 'ok', 1500);
        var box = el('ocr-thumbs');
        box.insertBefore(thumb, box.firstChild);
        while (box.children.length > 12) box.removeChild(box.lastChild);
        thumb.classList.add('ocr-thumb-pending');
        st.uploads = st.uploads.then(function () {
            var fd = new FormData();
            fd.append('action', 'store');
            fd.append('csrf', csrf);
            fd.append('meta', JSON.stringify(meta));
            fd.append('photo', blob, 'scorecard.jpg');
            return fetch(api, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); });
        }).then(function (j) {
            st.pending--;
            thumb.classList.remove('ocr-thumb-pending');
            if (j.error) {
                thumb.classList.add('ocr-thumb-failed');
                say(t('CaptureError', j.msg || ''), 'err', 4000);
                return;
            }
            thumb.title = j.name;
            el('ocr-count').textContent = j.count;
        }).catch(function () {
            st.pending--;
            thumb.classList.remove('ocr-thumb-pending');
            thumb.classList.add('ocr-thumb-failed');
            say(t('CaptureError', t('ErrNetwork')), 'err', 4000);
        });
    }

    // ------------------------------------------------------- photos from files

    /**
     * Photos taken with the phone's camera app, or chosen many at once: each is
     * turned into a JPEG if need be, its barcode read, then sent, one after the other.
     */
    var files = {chain: Promise.resolve(), total: 0, done: 0};

    function fileThumb(blob) {
        return createImageBitmap(blob).then(function (bmp) {
            var c = document.createElement('canvas'), k = 120 / Math.max(bmp.width, bmp.height);
            c.width = Math.max(1, Math.round(bmp.width * k));
            c.height = Math.max(1, Math.round(bmp.height * k));
            c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
            c.className = 'ocr-thumb';
            return c;
        });
    }

    function addFiles(list) {
        Array.prototype.forEach.call(list || [], function (file) {
            files.total++;
            files.chain = files.chain.then(function () {
                return asJpeg(file).then(function (blob) {
                    return Promise.all([fileThumb(blob), readBarcode(blob)]).then(function (r) {
                        var n = ++st.taken;
                        send(blob, {source: 'file', quad: null, barcode: r[1].text, turn: r[1].turn}, r[0], n);
                    });
                }).catch(function () {
                    say(t('CaptureError', t('ErrImage')), 'err', 3000);
                }).then(function () {
                    files.done++;
                    say(t('CaptureQueue', {done: files.done, total: files.total}), 'ok', 1500);
                });
            });
        });
    }

    el('ocr-photo').addEventListener('change', function () {
        addFiles(this.files);
        this.value = '';
    });
    el('ocr-files').addEventListener('change', function () {
        addFiles(this.files);
        this.value = '';
    });

    // ------------------------------------------------------------- the stock

    el('ocr-clear').addEventListener('click', function () {
        if (!window.confirm(t('CaptureClearConfirm'))) return;
        var fd = new FormData();
        fd.append('action', 'clear');
        fd.append('csrf', csrf);
        fetch(api, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) return say(j.msg || t('ErrRequest'), 'err');
                el('ocr-count').textContent = '0';
                el('ocr-thumbs').innerHTML = '';
            }).catch(function () { say(t('ErrNetwork'), 'err'); });
    });

    window.addEventListener('beforeunload', function (e) {
        if (st.pending > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
});
