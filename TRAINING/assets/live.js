/*
 * Live page of a training: asks the server every few seconds to move the
 * simulated competitors, then shows the matches under way and the
 * qualification rankings. Texts come from window.TRN_T.
 */
(function () {
    'use strict';
    var EVERY = 10000;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        var T = window.TRN_T || {};
        var status = document.getElementById('trn-live-status');
        if (!status) return;
        var duels = document.getElementById('trn-live-duels');
        var qual = document.getElementById('trn-live-qual');
        var busy = false;
        var last = null;
        var pickBox = document.getElementById('trn-live-events');
        var storeKey = 'trn-live-events-' + (status.dataset.tour || '0');
        var hidden = {};
        try { hidden = JSON.parse(window.localStorage.getItem(storeKey) || '{}') || {}; } catch (e) { hidden = {}; }
        function save() {
            try { window.localStorage.setItem(storeKey, JSON.stringify(hidden)); } catch (e) { /* private window: kept for this page only */ }
        }
        function shown(key) { return !hidden[key]; }

        /* The events to tick, rebuilt only when the list of events changes. */
        function renderPicker(events) {
            var keys = events.map(function (e) { return e.key; }).join('|');
            if (!pickBox || pickBox.dataset.keys === keys) return;
            pickBox.dataset.keys = keys;
            pickBox.textContent = '';
            events.forEach(function (ev) {
                var lab = el('label');
                var box = el('input');
                box.type = 'checkbox';
                box.checked = shown(ev.key);
                box.addEventListener('change', function () {
                    if (box.checked) delete hidden[ev.key]; else hidden[ev.key] = 1;
                    save();
                    if (last) render(last);
                });
                lab.appendChild(box);
                lab.appendChild(document.createTextNode(' ' + ev.label));
                pickBox.appendChild(lab);
            });
        }
        Array.prototype.forEach.call(document.querySelectorAll('#trn-live-pick [data-all]'), function (btn) {
            btn.addEventListener('click', function () {
                var on = btn.dataset.all === '1';
                hidden = {};
                if (!on && last) last.events.forEach(function (ev) { hidden[ev.key] = 1; });
                save();
                Array.prototype.forEach.call(pickBox.querySelectorAll('input'), function (b) { b.checked = on; });
                if (last) render(last);
            });
        });
        function render(state) {
            var labels = {};
            (state.events || []).forEach(function (ev) { labels[ev.key] = ev.label; });
            renderDuels((state.duels || []).filter(function (d) { return shown(d.key); }));
            var q = {};
            Object.keys(state.qual || {}).forEach(function (k) { if (shown(k)) q[labels[k] || k] = state.qual[k]; });
            renderQual(q);
        }

        function t(key, a) {
            var s = T[key] || key;
            if (a === undefined) return s;
            if (typeof a !== 'object') return s.replace('{$a}', a);
            Object.keys(a).forEach(function (k) { s = s.replace('{$a[' + k + ']}', a[k]); });
            return s;
        }
        function el(tag, cls, text) {
            var e = document.createElement(tag);
            if (cls) e.className = cls;
            if (text !== undefined) e.textContent = text;
            return e;
        }
        function badge(sim) {
            if (!sim) return null;
            return el('span', 'trn-badge', sim === 2 ? t('LiveWaBadge') : t('LiveSimBadge'));
        }

        function renderDuels(list) {
            duels.textContent = '';
            if (!list.length) return;
            duels.appendChild(el('h2', '', t('LiveDuels')));
            list.forEach(function (ev) {
                var box = el('section', 'trn-step');
                box.appendChild(el('h3', '', ev.event));
                if (ev.over) box.appendChild(el('p', 'trn-note', t('LiveOver', ev.over)));
                if (ev.live.length) {
                    var tab = el('table', 'Tabella trn-table');
                    ev.live.forEach(function (m) {
                        var tr = el('tr');
                        [m.a, m.b].forEach(function (s, i) {
                            var td = el('td', i ? '' : 'Right');
                            td.appendChild(document.createTextNode(s.name + ' '));
                            var b = badge(s.sim ? 1 : 0);
                            if (b) td.appendChild(b);
                            tr.appendChild(td);
                            if (!i) tr.appendChild(el('td', 'Center', m.a.score + ' - ' + m.b.score));
                        });
                        tab.appendChild(tr);
                    });
                    box.appendChild(tab);
                }
                duels.appendChild(box);
            });
        }

        function renderQual(groups) {
            qual.textContent = '';
            var keys = Object.keys(groups);
            if (!keys.length) return;
            qual.appendChild(el('h2', '', t('LiveQual')));
            keys.forEach(function (cat) {
                var box = el('section', 'trn-step');
                box.appendChild(el('h3', '', cat));
                var tab = el('table', 'Tabella trn-table');
                var head = el('tr');
                [t('ColRank'), t('ColArcher'), t('FieldClub'), t('ColScore'), t('ColArrows')].forEach(function (h) { head.appendChild(el('th', '', h)); });
                tab.appendChild(head);
                groups[cat].forEach(function (a) {
                    var tr = el('tr', a.sim ? 'trn-sim' : 'trn-real');
                    tr.appendChild(el('td', 'Center', a.rank ? String(a.rank) : ''));
                    var name = el('td', '', a.name + ' ');
                    var b = badge(a.sim);
                    if (b) name.appendChild(b);
                    tr.appendChild(name);
                    tr.appendChild(el('td', '', a.club));
                    tr.appendChild(el('td', 'Right', String(a.score)));
                    tr.appendChild(el('td', 'Right', String(a.hits)));
                    tab.appendChild(tr);
                });
                box.appendChild(tab);
                qual.appendChild(box);
            });
        }

        function tick() {
            if (busy) return;
            busy = true;
            var body = new FormData();
            body.append('ajax', '1');
            body.append('csrf', status.dataset.csrf);
            fetch(window.location.pathname, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j.error) {
                        status.textContent = j.msg || t('LiveError');
                        status.className = 'trn-live-status trn-msg trn-err';
                        return;
                    }
                    status.className = 'trn-live-status trn-msg trn-ok';
                    status.textContent = t('LiveUpdated', { time: j.time, qual: j.tick.qual, duels: j.tick.duels });
                    last = j.state;
                    var pend = document.getElementById('trn-live-pending');
                    if (pend) {
                        pend.hidden = !j.state.pending;
                        pend.querySelector('span').textContent = t('SoPending', j.state.pending);
                    }
                    renderPicker(j.state.events || []);
                    render(j.state);
                })
                .catch(function () {
                    status.className = 'trn-live-status trn-msg trn-err';
                    status.textContent = t('LiveError');
                })
                .then(function () { busy = false; });
        }

        tick();
        window.setInterval(tick, EVERY);
    }
})();
