/* Check-in desk (desk/index.php) — search, an archer's file, the judges' view.
 *
 * Phone first (one column: the file replaces the list, "Back" returns to it); from 900 px the
 * list and the file sit side by side. Everything comes from the JSON endpoints of desk/api/;
 * every write goes through Shp.send (idempotency key, resent until answered) and answers the
 * archer's fresh file. Buttons follow the volunteer's rights (C.rights); the server checks them
 * again. Every text comes from #shp-texts.
 *
 * QR code button: only where the browser reads QR codes itself (BarcodeDetector — Chrome on
 * Android) and may open the camera (HTTPS). Elsewhere, an iPhone among them, no button: a
 * scanner typing into the search field works everywhere.
 */
(function () {
    'use strict';
    var Shp = window.Shp;
    var root = document.getElementById('desk');
    if (!Shp || !root || !Shp.cfg || !Shp.cfg.search) return;
    var C = Shp.cfg, T = Shp.t;
    var rights = C.rights || [];
    function can(p) { return rights.indexOf(p) >= 0; }

    var ui = { view: 'search', q: '', res: null, file: null, todo: null, filters: { session: '', division: '', 'class': '', event: '' },
        locked: false, seq: 0 };
    var els = {};

    function h(tag, props, kids) {
        var el = document.createElement(tag), k, v;
        for (k in (props || {})) {
            v = props[k];
            if (v === null || v === undefined || v === false) continue;
            if (k === 'class') el.className = v;
            else if (k === 'text') el.textContent = v;
            else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
            else if (k === 'disabled') el.disabled = true;
            else el.setAttribute(k, v === true ? '' : String(v));
        }
        (kids || []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return el;
    }
    function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }
    function wide() { return window.matchMedia && window.matchMedia('(min-width: 900px)').matches; }
    function num(v) { return String(v).replace('.', C.dec || ','); }
    function stateClass(s) { return s === 1 ? 'dk-ok' : (s === 2 ? 'dk-ko' : 'dk-none'); }
    function stateText(s) { return T(s === 1 ? 'DkStateOk' : (s === 2 ? 'DkStateKo' : 'DkStateNone')); }
    function badge(label, s) { return h('span', { class: 'dk-badge ' + stateClass(s), text: label + ' : ' + stateText(s) }); }

    /* Two taps to confirm (the first one changes the label for 4 s). */
    function confirmBtn(label, sureLabel, cls, fire) {
        var timer = null;
        var b = h('button', { type: 'button', class: 'shp-btn ' + (cls || ''), text: label });
        b.addEventListener('click', function () {
            if (b.getAttribute('data-sure') === '1') { clearTimeout(timer); fire(b); return; }
            b.setAttribute('data-sure', '1');
            b.textContent = sureLabel;
            b.classList.add('dk-sure');
            timer = setTimeout(function () { b.removeAttribute('data-sure'); b.textContent = label; b.classList.remove('dk-sure'); }, 4000);
        });
        return b;
    }

    /* ---- Access lost (withdrawn, signed out, desk switched off): the page stops ---- */
    var LOCK_CODES = ['signed_out', 'expired', 'revoked', 'refused', 'pending', 'locked', 'desk_off', 'shop_off', 'forbidden'];
    function check(res) {
        if (res && res.error && LOCK_CODES.indexOf(res.code) >= 0) { lock(res.msg); return false; }
        return true;
    }
    function lock(msg) {
        ui.locked = true;
        if (todoPoll) todoPoll.stop();
        clear(root);
        root.appendChild(h('div', { class: 'shp-main' }, [h('div', { class: 'shp-card' }, [
            h('h1', { text: C.title }),
            h('div', { class: 'shp-msg shp-msg-warn', role: 'alert', text: msg || T('ShStfErrRevoked') }),
            h('p', {}, [h('a', { class: 'shp-btn shp-btn-primary shp-btn-block', href: C.login, text: T('ShStfLoginAgain') })])
        ])]));
    }
    function fail(res) {
        if (!check(res)) return;
        Shp.toast((res && res.msg) || T('ShErrNetwork'), null, 6000);
    }

    /* ---- Frame ---- */
    function build() {
        clear(root);
        els.top = h('div', { class: 'shp-top dk-top' }, [
            h('span', { class: 'shp-top-title' }, [h('b', { text: C.title }), h('small', { class: 'dk-tour', text: C.tour || '' })]),
            C.till ? h('a', { class: 'dk-toplink', href: C.till, text: T('DkTill') }) : null,
            h('span', { class: 'dk-who', text: C.me || '' }),
            confirmBtn(T('ShPosQuit'), T('ShPosConfirm'), 'dk-quit', function () {
                Shp.api(C.logout, {}).then(function () { window.location.replace(C.login); }, function () { Shp.toast(T('ShErrNetwork')); });
            })
        ]);
        els.side = h('div', { class: 'dk-side' });
        els.pane = h('div', { class: 'dk-pane' });
        els.body = h('div', { class: 'dk-body' }, [els.side, els.pane]);
        root.appendChild(els.top);
        root.appendChild(els.body);
        if (can('equip')) {
            els.tabs = h('nav', { class: 'shp-tabs dk-tabs' });
            root.appendChild(els.tabs);
            root.classList.add('dk-with-tabs');
        }
        renderTabs();
        renderSide();
        renderPane();
    }

    function renderTabs() {
        if (!els.tabs) return;
        clear(els.tabs);
        [['search', T('DkTabSearch')], ['todo', T('DkTabTodo')]].forEach(function (t) {
            els.tabs.appendChild(h('button', { type: 'button', class: 'shp-tab' + (ui.view === t[0] ? ' on' : ''), text: t[1],
                onclick: function () { setView(t[0]); } }));
        });
    }

    function setView(v) {
        ui.view = v;
        closeFile();
        renderTabs();
        renderSide();
        if (v === 'todo') loadTodo(); else if (els.input) els.input.focus();
    }

    function openedFile(on) { root.classList.toggle('dk-has-file', !!on); }
    function closeFile() { ui.file = null; openedFile(false); renderPane(); }

    /* ---- Search ---- */
    var searchTimer = null;
    function renderSide() {
        clear(els.side);
        if (ui.view === 'todo') { renderTodo(); return; }
        els.input = h('input', { type: 'search', class: 'dk-q', placeholder: T('DkSearchPh'), 'aria-label': T('DkSearchPh'),
            autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', enterkeyhint: 'search', value: ui.q });
        els.input.addEventListener('input', function () {
            ui.q = els.input.value;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { search(els.input.value, false); }, 350);
        });
        els.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimer); search(els.input.value, true); }
        });
        var bar = h('div', { class: 'dk-searchbar' }, [els.input]);
        if (scanReady) bar.appendChild(h('button', { type: 'button', class: 'shp-btn dk-scan', text: T('DkScan'), onclick: scan }));
        els.side.appendChild(bar);
        els.side.appendChild(h('p', { class: 'shp-muted dk-help', text: T('DkSearchHelp') }));
        els.results = h('div', { class: 'dk-results', 'aria-live': 'polite' });
        els.side.appendChild(els.results);
        renderResults();
    }

    function search(q, submitted) {
        q = String(q || '').trim();
        ui.q = q;
        var seq = ++ui.seq;
        if (q.length < 2) { ui.res = null; renderResults(); return; }
        Shp.api(C.search + '?q=' + encodeURIComponent(q), null).then(function (res) {
            if (seq !== ui.seq) return;
            if (!res || res.error) { fail(res); return; }
            ui.res = res;
            renderResults();
            // One archer: their file at once — after a scan or a confirmed search; side by side on
            // a computer, while typing too (the list stays in view).
            if (res.rows.length === 1 && (submitted || res.scan || wide())) openFile(res.rows[0].account);
        }, function () { Shp.offline(true); });
    }

    function placeText(p) {
        var s = p.session > 0 ? T('DkSession') + ' ' + p.session : '';
        if (p.target) s += (s ? ' · ' : '') + T('DkTarget') + ' ' + p.target;
        return s;
    }

    function renderResults() {
        if (!els.results) return;
        clear(els.results);
        var res = ui.res;
        if (!res) return;
        if (!res.rows.length) { els.results.appendChild(h('p', { class: 'shp-muted', text: T('DkNoResult') })); return; }
        res.rows.forEach(function (r) {
            var on = ui.file && ui.file.account === r.account;
            els.results.appendChild(h('button', { type: 'button', class: 'dk-row' + (on ? ' on' : ''), onclick: function () { openFile(r.account); } }, [
                h('span', { class: 'dk-row-main' }, [
                    h('b', { text: r.family + ' ' + r.given }),
                    h('small', { text: [r.licence, r.club].filter(Boolean).join(' · ') }),
                    h('small', { text: r.places.map(placeText).filter(Boolean).join(' / ') })
                ]),
                h('span', { class: 'dk-row-st' }, [
                    h('span', { class: 'dk-dot ' + stateClass(r.reg), title: T('DkRegTitle') + ' : ' + stateText(r.reg), text: T('DkRegTitle') }),
                    h('span', { class: 'dk-dot ' + stateClass(r.equip), title: T('DkEquipTitle') + ' : ' + stateText(r.equip), text: T('DkEquipTitle') })
                ])
            ]));
        });
        if (res.more) els.results.appendChild(h('p', { class: 'shp-muted', text: T('DkMoreResults') }));
    }

    /* ---- QR code of the back number (camera) ---- */
    var scanReady = false;
    function scanCheck() {
        if (!('BarcodeDetector' in window) || !window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
        try {
            window.BarcodeDetector.getSupportedFormats().then(function (f) {
                if (f.indexOf('qr_code') < 0) return;
                scanReady = true;
                if (ui.view === 'search' && !ui.locked) renderSide();
            }, function () {});
        } catch (e) { /* not available */ }
    }
    function scan() {
        var detector, stream = null, stopped = false, timer = null;
        try { detector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { Shp.toast(T('DkScanFail')); return; }
        var video = h('video', { class: 'dk-video', playsinline: true, muted: true, autoplay: true });
        video.muted = true;   // the attribute alone does not allow the autoplay everywhere
        var ov = h('div', { class: 'dk-overlay', role: 'dialog', 'aria-modal': 'true', 'aria-label': T('DkScanTitle') }, [
            h('div', { class: 'dk-ov-box' }, [
                h('h2', { text: T('DkScanTitle') }), video, h('p', { class: 'shp-muted', text: T('DkScanHelp') }),
                h('button', { type: 'button', class: 'shp-btn shp-btn-block', text: T('ShClose'), onclick: function () { stop(); } })
            ])
        ]);
        root.appendChild(ov);
        function stop() {
            stopped = true;
            clearTimeout(timer);
            if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
            ov.remove();
        }
        function tick() {
            if (stopped) return;
            detector.detect(video).then(function (codes) {
                if (stopped) return;
                if (codes && codes.length && codes[0].rawValue) {
                    var v = String(codes[0].rawValue).trim();
                    stop();
                    if (els.input) els.input.value = v;
                    search(v, true);
                    return;
                }
                timer = setTimeout(tick, 250);
            }, function () { timer = setTimeout(tick, 400); });
        }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
            if (stopped) { s.getTracks().forEach(function (t) { t.stop(); }); return; }
            stream = s;
            video.srcObject = s;
            var p = video.play();
            if (p && p.then) p.then(tick, tick); else tick();
        }, function () { stop(); Shp.toast(T('DkScanFail'), null, 6000); });
    }

    /* ---- An archer's file ---- */
    function openFile(account) {
        Shp.api(C.file + '?a=' + encodeURIComponent(account), null).then(function (res) {
            if (!res || res.error) { fail(res); return; }
            showFile(res.file);
        }, function () { Shp.offline(true); });
    }
    function showFile(file) {
        ui.file = file;
        openedFile(!!file);
        renderPane();
        renderResults();
        if (!wide()) window.scrollTo(0, 0);
    }

    function write(body, okText) {
        body.account = ui.file.account;
        return Shp.send(C.act, body).then(function (res) {
            if (!res || res.error) { fail(res); return res; }
            if (res.file) showFile(res.file);
            Shp.toast(okText || T('DkSaved'));
            if (ui.view === 'todo') loadTodo();
            return res;
        }, function () { Shp.toast(T('ShErrNetwork'), null, 6000); });
    }

    function section(title, kids, cls) {
        return h('section', { class: 'shp-card dk-sec' + (cls ? ' ' + cls : '') }, [h('h2', { text: title })].concat(kids));
    }

    function decisionBlock(division, state, okLabel, koLabel, perm) {
        var box = h('div', { class: 'dk-decide' });
        box.appendChild(h('p', { class: 'dk-state ' + stateClass(state), text: stateText(state) }));
        if (!can(perm)) return box;
        var reason = null;
        var row = h('div', { class: 'dk-btns' }, [
            state === 1 ? null : h('button', { type: 'button', class: 'shp-btn shp-btn-ok', text: okLabel,
                onclick: function (e) { e.currentTarget.disabled = true; write({ act: 'decide', division: division, decision: 'ok' }); } }),
            state === 2 ? null : h('button', { type: 'button', class: 'shp-btn shp-btn-danger', text: koLabel, onclick: function () {
                if (reason) return;
                reason = h('textarea', { class: 'dk-text', rows: '2', maxlength: '500', placeholder: T('DkReasonPh'), 'aria-label': T('DkReason') });
                box.appendChild(h('div', { class: 'dk-reason' }, [reason, h('button', { type: 'button', class: 'shp-btn shp-btn-danger shp-btn-block',
                    text: koLabel, onclick: function (e) {
                        e.currentTarget.disabled = true;
                        write({ act: 'decide', division: division, decision: 'ko', note: reason.value });
                    } })]));
                reason.focus();
            } }),
            state ? confirmBtn(T('DkUndo'), T('DkUndoSure'), 'dk-undo', function () { write({ act: 'decide', division: division, decision: 'undo' }); }) : null
        ]);
        box.appendChild(row);
        return box;
    }

    function renderPane() {
        clear(els.pane);
        var f = ui.file;
        if (!f) {
            // Shown beside the list on a computer only (CSS): never redrawn on a resize, which the
            // phone's keyboard causes while a note is being typed.
            els.pane.appendChild(h('div', { class: 'dk-empty shp-muted', text: T('DkSearchHelp') }));
            return;
        }
        var kids = [];
        kids.push(h('button', { type: 'button', class: 'shp-btn dk-back', text: '← ' + T('DkBack'), onclick: closeFile }));

        // Identity
        var idLines = [h('h1', { class: 'dk-name', text: f.family + ' ' + f.given })];
        var facts = [];
        if (f.licence) facts.push([T('DkLicence'), f.licence]);
        if (f.club_code || f.club_name) facts.push([T('DkClub'), [f.club_code, f.club_name].filter(Boolean).join(' — ')]);
        if (f.dob) facts.push([T('DkBorn'), f.dob]);
        idLines.push(h('dl', { class: 'dk-facts' }, [].concat.apply([], facts.map(function (x) { return [h('dt', { text: x[0] }), h('dd', { text: x[1] })]; }))));
        idLines.push(h('p', { class: 'dk-lic dk-lic-' + f.licence_info.state, text: f.licence_info.text }));
        if (f.status9) idLines.push(h('div', { class: 'shp-msg shp-msg-warn', text: T('DkStatus9') }));
        kids.push(h('section', { class: 'shp-card dk-sec dk-id' }, idLines));

        // Entries
        var entries = section(T('DkEntries'), f.entries.map(function (e) {
            return h('div', { class: 'dk-entry' }, [
                h('div', { class: 'dk-entry-head' }, [
                    h('b', { text: e.session_label }),
                    h('span', { class: 'dk-target', text: e.target ? T('DkTarget') + ' ' + e.target : T('DkNoTarget') })
                ]),
                e.start ? h('small', { text: e.start }) : null,
                h('div', { text: e.division_label + ' — ' + e.class_label }),
                e.events.length ? h('small', { text: T('DkEvents') + ' : ' + e.events.join(', ') }) : null,
                h('small', { class: 'dk-status', text: T('DkStatus') + ' : ' + e.status_label })
            ]);
        }));
        // A judge (equipment only) gets the equipment right under the identity.
        var judge = can('equip') && !can('reg');
        if (!judge) kids.push(entries);

        // Documents (registry)
        kids.push(section(T('DkRegTitle'), [decisionBlock('', f.reg, T('DkRegOk'), T('DkRegKo'), 'reg')], 'dk-sec-reg'));

        // Payments
        if (f.pay) kids.push(section(T('DkPayTitle'), payBlock(f.pay)));

        // Equipment, weapon by weapon
        f.equip.forEach(function (d) {
            var list = d.powers.length ? h('ul', { class: 'dk-powers' }, d.powers.map(function (p) {
                return h('li', {}, [h('b', { text: num(p.power) + ' ' + T('DkPowerUnit') }), ' — ' + p.when + ' — ' + p.by]);
            })) : h('p', { class: 'shp-muted', text: T('DkPowerNone') });
            var block = [decisionBlock(d.division, d.state, T('DkEquipOk'), T('DkEquipKo'), 'equip'), h('h3', { text: T('DkPowerTitle') }), list];
            if (can('equip')) {
                var inp = h('input', { type: 'text', inputmode: 'decimal', class: 'dk-power', placeholder: T('DkPowerPh'), 'aria-label': T('DkPowerPh'), maxlength: '5' });
                var add = function () {
                    var v = inp.value.trim();
                    if (!v) { inp.focus(); return; }
                    write({ act: 'power', division: d.division, power: v });
                };
                inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(); } });
                block.push(h('div', { class: 'dk-inline' }, [inp, h('span', { class: 'dk-unit', text: T('DkPowerUnit') }),
                    h('button', { type: 'button', class: 'shp-btn shp-btn-primary', text: T('DkPowerAdd'), onclick: add })]));
            }
            var sec = section(T('DkEquipTitle') + (f.equip.length > 1 || d.label ? ' — ' + d.label : ''), block, 'dk-sec-equip');
            if (judge) kids.splice(2 + f.equip.indexOf(d), 0, sec); else kids.push(sec);
        });
        if (judge) kids.splice(2 + f.equip.length, 0, entries);

        // Notes
        var notes = f.notes.length ? h('ul', { class: 'dk-notes' }, f.notes.map(function (n) {
            return h('li', {}, [h('p', { text: n.text }), h('small', { text: (n.post === 'equip' ? T('DkPostEquip') : T('DkPostReg')) + ' — ' + n.by + ' — ' + n.when })]);
        })) : h('p', { class: 'shp-muted', text: T('DkNotesNone') });
        var noteKids = [notes];
        if (can('reg') || can('equip')) {
            var ta = h('textarea', { class: 'dk-text', rows: '2', maxlength: '500', placeholder: T('DkNotePh'), 'aria-label': T('DkNotePh') });
            var post = can('reg') ? 'reg' : 'equip';
            var postSel = null;
            if (can('reg') && can('equip')) {
                postSel = h('select', { class: 'dk-post', 'aria-label': T('DkNotesTitle') }, [
                    h('option', { value: 'reg', text: T('DkPostReg') }), h('option', { value: 'equip', text: T('DkPostEquip') })]);
            }
            noteKids.push(h('div', { class: 'dk-noteform' }, [ta, h('div', { class: 'dk-inline' }, [postSel,
                h('button', { type: 'button', class: 'shp-btn shp-btn-primary', text: T('DkNoteAdd'), onclick: function () {
                    if (!ta.value.trim()) { ta.focus(); return; }
                    write({ act: 'note', post: postSel ? postSel.value : post, text: ta.value });
                } })])]));
        }
        kids.push(section(T('DkNotesTitle'), noteKids));

        // History of the decisions
        if (f.history.length) {
            var kindKey = { ok: 'DkKindOk', ko: 'DkKindKo', undo: 'DkKindUndo' };
            var divLabel = {};
            f.equip.forEach(function (d) { divLabel[d.division] = d.label; });
            kids.push(h('details', { class: 'shp-card dk-sec dk-history' }, [h('summary', { text: T('DkHistory') }),
                h('ul', {}, f.history.map(function (x) {
                    var what = (x.division === '' ? T('DkRegTitle') : T('DkEquipTitle') + ' — ' + (divLabel[x.division] || x.division))
                        + ' : ' + T(kindKey[x.kind] || 'DkKindUndo');
                    return h('li', {}, [h('b', { text: what }), h('small', { text: ' — ' + x.when + ' — ' + x.by }),
                        x.text ? h('p', { text: x.text }) : null]);
                }))]));
        }
        kids.forEach(function (k) { els.pane.appendChild(k); });
    }

    function payBlock(p) {
        var rows = [];
        if (p.whole) rows.push([T('DkPayReg'), Shp.money(p.reg)]);
        if (p.shop > 0.004 || !p.whole) rows.push([T('DkPayShop'), Shp.money(p.shop)]);
        rows.push([T('DkPayPaid'), Shp.money(p.paid)]);
        var left = Math.round(p.remaining * 100) / 100;
        var box = [h('dl', { class: 'dk-facts dk-money' }, [].concat.apply([], rows.map(function (x) {
            return [h('dt', { text: x[0] }), h('dd', { text: x[1] })];
        })).concat([h('dt', { class: 'dk-left', text: T('DkPayLeft') }), h('dd', { class: 'dk-left', text: Shp.money(Math.max(0, left)) })]))];
        if (left <= 0.004) { box.push(h('p', { class: 'dk-state dk-ok', text: T('DkPayNothing') })); return box; }
        if (!can('pay')) return box;
        var amount = h('input', { type: 'text', inputmode: 'decimal', class: 'dk-amount', value: num(left.toFixed(2)), 'aria-label': T('DkPayAmount') });
        box.push(h('label', { class: 'dk-inline' }, [h('span', { text: T('DkPayAmount') }), amount]));
        box.push(h('div', { class: 'dk-btns dk-methods' }, (C.methods || []).map(function (m) {
            return confirmBtn(m.label, T('DkPayConfirm'), 'dk-method', function (b) {
                b.disabled = true;
                write({ act: 'pay', method: m.code, amount: amount.value.trim() }, T('DkPayDone'));
            });
        })));
        return box;
    }

    /* ---- Judges' view: equipment not checked yet ---- */
    var todoPoll = null;
    function loadTodo() {
        var f = ui.filters, q = [];
        Object.keys(f).forEach(function (k) { if (f[k] !== '') q.push(encodeURIComponent(k) + '=' + encodeURIComponent(f[k])); });
        return Shp.api(C.todo + (q.length ? '?' + q.join('&') : ''), null).then(function (res) {
            if (!res || res.error) { fail(res); return; }
            ui.todo = res;
            if (ui.view === 'todo') renderTodo();
        });
    }
    function renderTodo() {
        if (ui.view !== 'todo') return;
        clear(els.side);
        var d = ui.todo;
        if (!d) { els.side.appendChild(h('p', { class: 'shp-muted dk-pad', text: T('ShLoading') })); return; }
        var dims = [['session', 'DkFilterSession', 'DkAllSessions'], ['division', 'DkFilterWeapon', 'DkAllWeapons'],
            ['class', 'DkFilterClass', 'DkAllClasses'], ['event', 'DkFilterEvent', 'DkAllEvents']];
        var filters = h('div', { class: 'dk-filters' });
        dims.forEach(function (dm) {
            var opts = d.facets[dm[0]] || [];
            if (!opts.length && ui.filters[dm[0]] === '') return;   // nobody left with any value of it
            var sel = h('select', { 'aria-label': T(dm[1]) }, [h('option', { value: '', text: T(dm[2]) })]
                .concat(opts.map(function (o) {
                    return h('option', { value: o[0], selected: ui.filters[dm[0]] === o[0] ? true : null, text: o[1] + ' (' + o[2] + ')' });
                })));
            sel.addEventListener('change', function () { ui.filters[dm[0]] = sel.value; loadTodo(); });
            filters.appendChild(h('label', { class: 'dk-filter' }, [sel]));
        });
        els.side.appendChild(filters);
        els.side.appendChild(h('p', { class: 'dk-count', text: T('DkTodoLeft', d.rows.length) + (d.rows.length !== d.left ? ' ' + T('DkOf', d.left) : '') }));
        var list = h('div', { class: 'dk-results' });
        if (!d.rows.length) list.appendChild(h('p', { class: 'shp-muted', text: T('DkTodoNone') }));
        d.rows.forEach(function (r) {
            var on = ui.file && ui.file.account === r.account;
            list.appendChild(h('button', { type: 'button', class: 'dk-row' + (on ? ' on' : ''), onclick: function () { openFile(r.account); } }, [
                h('span', { class: 'dk-tgt', text: r.target || '—' }),
                h('span', { class: 'dk-row-main' }, [
                    h('b', { text: r.family + ' ' + r.given }),
                    h('small', { text: [r.club, r.club_name].filter(Boolean).join(' ') }),
                    h('small', { text: r.division_label + ' — ' + r.class_label + (r.session > 0 ? ' · ' + T('DkSession') + ' ' + r.session : '') })
                ])
            ]));
        });
        els.side.appendChild(list);
    }

    build();
    scanCheck();
    if (can('equip')) {
        todoPoll = Shp.poll(function () { return ui.view === 'todo' ? loadTodo() : null; }, 20000, 60000);
    }
    if (C.open) openFile(C.open);
    else if (els.input && wide()) els.input.focus();
})();
