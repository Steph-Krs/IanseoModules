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
 * Shp.topbar(bar, opt) top bar of the volunteer pages: post selector, the page's own element,
 *                      the volunteer's name opening a small menu (archer space, sign out).
 * [data-shp-logout]    a "sign out" button of a plain page (data-to: where to go then).
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

    /* ---- Top bar of the volunteer pages (till, registry, judges) ----
       Phone first, one line: the selector of the posts open to the volunteer (bar.posts: the
       stands, the registry, the judges — lib/posts.php), an element of the page (opt.extra), and
       the name: its menu gives the competition, the way back (archer space, or the organiser's
       page) and signing out. opt.local(key) returns true when the page switches by itself (another
       stand of the till); otherwise the post's address opens. The menu lives outside the bar: a
       page redrawing its bar does not close it. */
    var menuEl = null;
    function menuClose() {
        if (!menuEl) return;
        menuEl.hidden = true;
        var b = document.querySelector('.shp-who[aria-expanded="true"]');
        if (b) b.setAttribute('aria-expanded', 'false');
    }
    function menuOpen(bar, who) {
        if (!menuEl) {
            menuEl = document.createElement('div');
            menuEl.className = 'shp-menu';
            menuEl.setAttribute('role', 'menu');
            (document.getElementById('shp') || document.body).appendChild(menuEl);
            document.addEventListener('click', function (e) {
                if (menuEl.hidden || menuEl.contains(e.target) || (e.target.closest && e.target.closest('.shp-who'))) return;
                menuClose();
            });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') menuClose(); });
        }
        while (menuEl.firstChild) menuEl.removeChild(menuEl.firstChild);
        var head = document.createElement('div');
        head.className = 'shp-menu-head';
        var b = document.createElement('b'); b.textContent = bar.full || bar.name || '';
        var sm = document.createElement('small'); sm.textContent = bar.tour || '';
        head.appendChild(b); head.appendChild(sm);
        menuEl.appendChild(head);
        if (bar.back) {
            var a = document.createElement('a');
            a.className = 'shp-menu-item';
            a.setAttribute('role', 'menuitem');
            a.href = bar.back;
            a.textContent = bar.back_label || '';
            menuEl.appendChild(a);
        }
        var out = document.createElement('button');
        out.type = 'button';
        out.className = 'shp-menu-item shp-menu-out';
        out.setAttribute('role', 'menuitem');
        out.textContent = Shp.t('ShPosQuit');
        out.addEventListener('click', function () {
            out.disabled = true;
            Shp.api(bar.logout, {}).then(function () { window.location.replace(bar.login || bar.back || '/'); },
                function () { out.disabled = false; Shp.toast(Shp.t('ShErrNetwork')); });
        });
        menuEl.appendChild(out);
        menuEl.hidden = false;
        who.setAttribute('aria-expanded', 'true');
    }
    Shp.topbar = function (bar, opt) {
        opt = opt || {};
        bar = bar || {};
        var top = document.createElement('div');
        top.className = 'shp-top shp-bar';
        var posts = bar.posts || [];
        if (posts.length > 1) {
            var sel = document.createElement('select');
            sel.className = 'shp-postsel';
            sel.setAttribute('aria-label', Shp.t('ShStfPost'));
            posts.forEach(function (p) {
                var o = document.createElement('option');
                o.value = p.key;
                o.textContent = p.label;
                if (p.key === bar.post) o.selected = true;
                sel.appendChild(o);
            });
            sel.addEventListener('change', function () {
                var p = posts.filter(function (x) { return x.key === sel.value; })[0];
                if (!p || (opt.local && opt.local(p.key))) return;
                window.location.href = p.url;
            });
            top.appendChild(sel);
        } else {
            var cur = posts.filter(function (x) { return x.key === bar.post; })[0];
            var t = document.createElement('span');
            t.className = 'shp-top-title';
            t.textContent = cur ? cur.label : (opt.title || '');
            top.appendChild(t);
        }
        if (opt.extra) top.appendChild(opt.extra);
        var who = document.createElement('button');
        who.type = 'button';
        who.className = 'shp-who';
        who.setAttribute('aria-haspopup', 'menu');
        who.setAttribute('aria-expanded', menuEl && !menuEl.hidden ? 'true' : 'false');
        who.textContent = bar.name || bar.full || '';
        who.addEventListener('click', function () {
            if (menuEl && !menuEl.hidden) menuClose(); else menuOpen(bar, who);
        });
        top.appendChild(who);
        return top;
    };

    /* ---- "Sign out" of a plain page (no post open, …) ---- */
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-shp-logout]') : null;
        if (!b) return;
        b.disabled = true;
        Shp.api(b.getAttribute('data-shp-logout'), {}).then(function () { window.location.replace(b.getAttribute('data-to') || '/'); },
            function () { b.disabled = false; });
    });
})();
