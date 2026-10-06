/* Points of sale — helpers shared by the phone pages (customers and volunteers).
 *
 * Shp.api(url, body)   JSON request with the X-Shp header (required by every endpoint);
 *                      GET when body is null. Resolves with the JSON envelope {error, code,
 *                      msg, …}; rejects with {network: true} when the server cannot be reached.
 * Shp.send(url, body)  a WRITE that must not be lost nor done twice: body.idem is set once
 *                      (UUID), the request is sent again with the SAME key until the server
 *                      answers, and kept in the browser storage so that a reload resends it.
 * Shp.poll(fn, ms, hiddenMs)  repeats fn while the page lives, slower when hidden.
 * Shp.t(key, a)        text from the page's JSON block #shp-texts ({$a} replaced).
 * Shp.money(n)         amount with the separators and currency of #shp-cfg.
 * Shp.toast(text, undo) message with an "Undo" button for 5 seconds.
 * Shp.beep(), Shp.vibrate(), Shp.unlockAudio()  signals (sound needs a first tap).
 *
 * No text for people is written here: they all come from the page (#shp-texts).
 */
(function () {
    'use strict';
    var Shp = window.Shp = window.Shp || {};

    function readJson(id) {
        var el = document.getElementById(id);
        if (!el) return {};
        try { return JSON.parse(el.textContent) || {}; } catch (e) { return {}; }
    }
    Shp.texts = readJson('shp-texts');
    Shp.cfg = readJson('shp-cfg');

    Shp.t = function (key, a) {
        var s = Shp.texts[key];
        if (s === undefined || s === null) return key;
        if (a !== undefined && a !== null) s = String(s).split('{$a}').join(String(a));
        return String(s);
    };

    Shp.uuid = function () {
        if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
        var b = new Uint8Array(16), h = [], i;
        window.crypto.getRandomValues(b);
        b[6] = (b[6] & 15) | 64;
        b[8] = (b[8] & 63) | 128;
        for (i = 0; i < 16; i++) h.push((b[i] + 256).toString(16).slice(1));
        return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' + h.slice(6, 8).join('') + '-'
            + h.slice(8, 10).join('') + '-' + h.slice(10).join('');
    };

    Shp.money = function (n) {
        var dec = Shp.cfg.dec || ',', th = Shp.cfg.thousands || ' ', cur = Shp.cfg.currency || '€';
        var neg = n < 0, parts = Math.abs(Number(n) || 0).toFixed(2).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, th);
        return (neg ? '−' : '') + parts[0] + dec + parts[1] + ' ' + cur;
    };

    Shp.esc = function (s) {
        return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    Shp.api = function (url, body) {
        var opt = { method: body === null || body === undefined ? 'GET' : 'POST', credentials: 'same-origin',
            headers: { 'X-Shp': '1', 'Accept': 'application/json' }, cache: 'no-store' };
        if (opt.method === 'POST') {
            opt.headers['Content-Type'] = 'application/json';
            opt.body = JSON.stringify(body);
        }
        return fetch(url, opt).then(function (r) {
            return r.text().then(function (txt) {
                try { return JSON.parse(txt); } catch (e) { return { error: 1, code: 'bad_response', msg: Shp.t('ShErrNetwork'), status: r.status }; }
            });
        }, function () { return Promise.reject({ network: true }); });
    };

    /* ---- Connection banner ---- */
    var offlineEl = null;
    Shp.offline = function (on) {
        if (on && !offlineEl) {
            offlineEl = document.createElement('div');
            offlineEl.className = 'shp-offline';
            offlineEl.setAttribute('role', 'status');
            offlineEl.textContent = Shp.t('ShOffline');
            (document.getElementById('shp') || document.body).appendChild(offlineEl);
        } else if (!on && offlineEl) {
            offlineEl.remove();
            offlineEl = null;
        }
    };

    /* ---- Writes sent again until answered ---- */
    var STORE = 'shp-pending-' + (Shp.cfg.scope || 'x');
    function pendingRead() {
        try { return JSON.parse(window.localStorage.getItem(STORE) || '[]') || []; } catch (e) { return []; }
    }
    function pendingWrite(list) {
        try { window.localStorage.setItem(STORE, JSON.stringify(list)); } catch (e) { /* private mode */ }
    }
    function pendingDrop(idem) {
        pendingWrite(pendingRead().filter(function (p) { return p.body.idem !== idem; }));
    }

    Shp.send = function (url, body) {
        body = body || {};
        if (!body.idem) body.idem = Shp.uuid();
        var list = pendingRead();
        list.push({ url: url, body: body, at: Date.now() });
        pendingWrite(list);
        var delay = 1000, started = Date.now();
        return new Promise(function (resolve, reject) {
            (function attempt() {
                Shp.api(url, body).then(function (res) {
                    Shp.offline(false);
                    pendingDrop(body.idem);
                    resolve(res);
                }, function () {
                    Shp.offline(true);
                    if (Date.now() - started > 120000) { reject({ network: true }); return; }
                    setTimeout(attempt, delay);
                    delay = Math.min(delay * 2, 15000);
                });
            })();
        });
    };

    /** Resends what a previous page left unanswered (same keys: the server does it once). */
    Shp.flush = function (onDone) {
        var list = pendingRead().filter(function (p) { return Date.now() - p.at < 3600000; });
        pendingWrite(list);
        list.forEach(function (p) {
            Shp.api(p.url, p.body).then(function (res) { pendingDrop(p.body.idem); if (onDone) onDone(res, p); }, function () {});
        });
    };

    /* ---- Polling ---- */
    Shp.poll = function (fn, ms, hiddenMs) {
        var timer = null, stopped = false;
        function next() {
            if (stopped) return;
            timer = setTimeout(run, document.hidden ? (hiddenMs || ms * 3) : ms);
        }
        function run() {
            if (stopped) return;
            Promise.resolve().then(fn).then(function () { Shp.offline(false); next(); }, function (e) {
                if (e && e.network) Shp.offline(true);
                next();
            });
        }
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !stopped) { clearTimeout(timer); run(); }
        });
        run();
        return { stop: function () { stopped = true; clearTimeout(timer); }, now: function () { clearTimeout(timer); run(); } };
    };

    /* ---- Toast with undo ---- */
    var toastEl = null, toastTimer = null;
    Shp.toast = function (text, undo, ms) {
        if (toastEl) { toastEl.remove(); clearTimeout(toastTimer); }
        toastEl = document.createElement('div');
        toastEl.className = 'shp-toast';
        toastEl.setAttribute('role', 'status');
        var span = document.createElement('span');
        span.textContent = text;
        toastEl.appendChild(span);
        if (undo) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = Shp.t('ShUndo');
            b.addEventListener('click', function () { hide(); undo(); });
            toastEl.appendChild(b);
        }
        (document.getElementById('shp') || document.body).appendChild(toastEl);
        function hide() { if (toastEl) { toastEl.remove(); toastEl = null; } clearTimeout(toastTimer); }
        toastTimer = setTimeout(hide, ms || 5000);
        return hide;
    };

    /* ---- Signals ---- */
    var audio = null;
    Shp.unlockAudio = function () {
        if (audio) return;
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        try { audio = new Ctx(); if (audio.state === 'suspended') audio.resume(); } catch (e) { audio = null; }
    };
    Shp.beep = function (times) {
        if (!audio) return;
        var n = times || 2, i;
        for (i = 0; i < n; i++) {
            var o = audio.createOscillator(), g = audio.createGain(), t = audio.currentTime + i * 0.35;
            o.frequency.value = 880;
            g.gain.setValueAtTime(0.0001, t);
            g.gain.exponentialRampToValueAtTime(0.3, t + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, t + 0.25);
            o.connect(g); g.connect(audio.destination);
            o.start(t); o.stop(t + 0.26);
        }
    };
    Shp.vibrate = function (pattern) {
        if (navigator.vibrate) { try { navigator.vibrate(pattern || [200, 100, 200]); } catch (e) { /* not allowed */ } }
    };
})();
