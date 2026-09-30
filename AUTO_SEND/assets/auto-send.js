/* Status panel and small helpers of the scheduled upload page.
   Every sentence arrives translated from api/state.php; this script only lays
   it out, with textContent so that a message relayed from ianseo.net is never
   read as markup. */

(function () {
    'use strict';

    var REFRESH_MS = 15000;
    var timer = null;

    function t(key) {
        return (window.AUS_T && window.AUS_T[key]) || key;
    }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }

    function badge(ok, text) {
        return el('span', 'aus-badge ' + (ok ? 'aus-badge-ok' : 'aus-badge-ko'), text);
    }

    function line(label, value) {
        var p = el('p', 'aus-line');
        p.appendChild(el('b', null, label + ' '));
        p.appendChild(document.createTextNode(value));
        return p;
    }

    function render(s) {
        var box = document.getElementById('aus-status');
        if (!box) return;
        box.textContent = '';
        if (!s || s.error) {
            box.appendChild(el('p', 'aus-msg aus-msg-err', (s && s.msg) || t('JsLoadError')));
            return;
        }

        var hb = el('p', 'aus-line');
        hb.appendChild(badge(s.heartbeat.ok, t('JsTask')));
        hb.appendChild(document.createTextNode(' ' + s.heartbeat.text));
        box.appendChild(hb);
        var task = document.getElementById('aus-task');
        if (task && !s.heartbeat.ok) task.open = true;

        var ph = el('p', 'aus-line');
        ph.appendChild(el('b', null, s.phaseText));
        if (s.simulation) {
            ph.appendChild(document.createTextNode(' '));
            ph.appendChild(el('span', 'aus-badge aus-badge-warn', t('JsSimulation')));
        }
        box.appendChild(ph);

        box.appendChild(line(t('JsLastSuccess'), s.lastSuccess || t('JsNever')));
        if (s.nextSend) box.appendChild(line(t('JsNextSend'), s.nextSend));
        if (s.failing) {
            var f = el('div', 'aus-msg aus-msg-err');
            f.appendChild(el('b', null, t('JsFailures') + ' ' + s.failures));
            f.appendChild(el('div', null, s.lastText));
            box.appendChild(f);
        }

        if (s.sessions.length) {
            var ss = el('p', 'aus-line');
            ss.appendChild(el('b', null, t('JsSessions') + ' '));
            s.sessions.forEach(function (x) {
                ss.appendChild(badge(x.open, x.key + ' ' + (x.open ? t('JsOpen') : t('JsClosed'))));
                ss.appendChild(document.createTextNode(' '));
            });
            box.appendChild(ss);
        }

        var actions = el('p', 'aus-line');
        var btn = el('button', 'aus-btn', s.sendNow ? t('JsSendNowPending') : t('JsSendNow'));
        btn.type = 'button';
        btn.disabled = !!s.sendNow;
        btn.addEventListener('click', sendNow);
        actions.appendChild(btn);
        actions.appendChild(el('span', 'aus-hint', ' ' + t('JsSendNowHint')));
        box.appendChild(actions);

        box.appendChild(el('h3', 'aus-h3', t('JsHistory')));
        if (!s.runs.length) {
            box.appendChild(el('p', 'aus-hint', t('JsNoRun')));
            return;
        }
        var wrap = el('div', 'aus-scroll');
        var table = el('table', 'aus-table');
        var head = el('tr');
        [t('JsWhen'), t('JsKind'), t('JsResult'), t('JsDuration'), t('JsSize'), t('JsDetail')].forEach(function (h) {
            head.appendChild(el('th', null, h));
        });
        table.appendChild(head);
        s.runs.forEach(function (r) {
            var tr = el('tr');
            tr.appendChild(el('td', 'aus-nowrap', r.when));
            tr.appendChild(el('td', 'aus-nowrap', r.kind));
            var res = el('td', 'aus-nowrap');
            res.appendChild(badge(r.ok, r.ok ? t('JsOk') : t('JsKo')));
            if (r.simulation) {
                res.appendChild(document.createTextNode(' '));
                res.appendChild(el('span', 'aus-badge aus-badge-warn', t('JsSimulation')));
            }
            tr.appendChild(res);
            tr.appendChild(el('td', 'aus-nowrap', r.seconds ? r.seconds + ' s' : ''));
            tr.appendChild(el('td', 'aus-nowrap', r.size));
            tr.appendChild(el('td', null, r.text));
            table.appendChild(tr);
        });
        wrap.appendChild(table);
        box.appendChild(wrap);
    }

    function load(body) {
        clearTimeout(timer);
        var opts = {credentials: 'same-origin'};
        if (body) {
            opts.method = 'POST';
            opts.body = body;
        }
        fetch(window.AUS.state, opts)
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () { render(null); })
            .then(function () { timer = setTimeout(function () { load(); }, REFRESH_MS); });
    }

    function sendNow() {
        var fd = new FormData();
        fd.append('action', 'sendnow');
        fd.append('csrf', window.AUS.csrf);
        load(fd);
    }

    function tick(list, on) {
        var boxes = document.querySelectorAll('#aus input[type=checkbox][name="' + list + '[]"]');
        Array.prototype.forEach.call(boxes, function (b) { b.checked = on; });
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('#aus [data-aus-all]'), function (b) {
            b.addEventListener('click', function () { tick(b.getAttribute('data-aus-all'), true); });
        });
        Array.prototype.forEach.call(document.querySelectorAll('#aus [data-aus-none]'), function (b) {
            b.addEventListener('click', function () { tick(b.getAttribute('data-aus-none'), false); });
        });
        if (window.AUS && document.getElementById('aus-status')) load();
    });
})();
