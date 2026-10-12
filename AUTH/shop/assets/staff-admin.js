/* Points of sale and check-in desk — organiser page of the volunteers (admin/staff.php).
   Waiting requests and team are drawn from the JSON of the page (#shp-cfg). The page asks the
   server again every 3 seconds (15 when hidden) ONLY while an invitation runs or requests wait:
   otherwise nothing changes by itself and nothing is asked. "Seen … ago" is shown two minutes
   (it is not refreshed), then hidden. A redraw never moves the page: the reading position is
   kept, a card being edited is never redrawn under the organiser's fingers, and a card is only
   redrawn when what it shows changed. Every text comes from #shp-texts. */
(function () {
    'use strict';
    var Shp = window.Shp;
    if (!Shp || !Shp.cfg || !Shp.cfg.self) return;
    var C = Shp.cfg, T = Shp.t, esc = Shp.esc;
    var state = C.state || { pending: [], team: [], invite: 0 };
    var standById = {};
    C.stands.forEach(function (s) { standById[s.id] = s; });
    var presetByKey = {};
    C.presets.forEach(function (p) { presetByKey[p.key] = p; });
    var deskPerms = C.desk_perms || [];
    // Presets offered: those of the stands while there are stands; those of the registry and of the
    // judges while that post is open to the volunteers (two posts opened apart).
    var presets = C.presets.filter(function (p) {
        if (p.key === 'dos') return true;
        if (p.perms !== '') return C.shop_on || C.stands.length > 0;
        return p.desk.indexOf('equip') >= 0 ? !!C.judges_on : !!C.desk_on;
    });
    function noRightText() { return T(C.desk_on || C.judges_on ? 'DkErrNoRight' : 'ShStfErrNoStand'); }
    function presetOffered(k) { return presets.some(function (p) { return p.key === k; }); }
    function presetLabel(k) {
        if (k === 'custom') return T('ShStfPresetCustom');
        var p = presetByKey[k];
        return p ? p.label : '—';
    }

    function $(id) { return document.getElementById(id); }
    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html !== undefined) e.innerHTML = html;
        return e;
    }
    function btn(label, cls, fn) {
        var b = el('button', 'ss-btn' + (cls ? ' ' + cls : ''));
        b.type = 'button';
        b.textContent = label;
        b.addEventListener('click', fn);
        return b;
    }
    function amountText(v) { return Shp.money(Number(v) || 0); }
    function amountIn(v) { return (Number(v) || 0).toFixed(2).replace('.', C.dec || ','); }

    /* ---- Messages ---- */
    var flashTimer = null;
    function flash(text, type, ms) {
        var f = $('ss-flash');
        if (!f) return;
        f.innerHTML = '';
        if (!text) return;
        var m = el('div', 'ss-msg ss-' + (type || 'ok'));
        m.textContent = text;
        f.appendChild(m);
        clearTimeout(flashTimer);
        flashTimer = setTimeout(function () { f.innerHTML = ''; }, ms || 6000);
    }

    /* ---- Requests ---- */
    function post(body) {
        body.csrf = C.csrf;
        return Shp.api(C.self, body).then(function (res) {
            if (res && res.pending) { state = pick(res); render(); }
            if (res && res.error) flash(res.msg || T('ShErrNetwork'), 'err');
            return res;
        }, function () { flash(T('ShErrNetwork'), 'err'); return { error: 1 }; });
    }
    function pick(res) { return { pending: res.pending, team: res.team, invite: res.invite, officials: res.officials || [] }; }
    function refresh() {
        return Shp.api(C.self, null).then(function (res) {
            if (res && res.pending) { state = pick(res); render(); }
        });
    }

    /* Asking the server again only while something may change by itself: an invitation running
       (volunteers scan it), or requests waiting for a decision. */
    var poller = null;
    function syncPolling() {
        var need = (Number(state.invite) || 0) > 0 || (state.pending || []).length > 0;
        if (need && !poller) poller = Shp.poll(refresh, 3000, 15000);
        else if (!need && poller) { poller.stop(); poller = null; }
    }

    /* ---- Rights editor: an access and a function ----
       The access gives the rights: a preset × the stands ticked, or rights stand by stand, and the
       desk. The function is the one of ianseo's field crew (licensed volunteers only). They propose
       each other (user's choice, 2026-10-13): an access chosen while no function is set proposes
       its function; a function chosen while no access is set proposes its access; a field already
       set is never changed by the other one. Rights changed by hand turn the access into the
       matching preset or "custom" — never the function. */
    function rightsEditor(initial, opt) {
        // initial: {rights: [{stand, perms: []}], desk: [], access, refund_max, refund_total} or null (new volunteer)
        // opt: {licensee: bool, fn: ianseo function shown, fnId: its row in ianseo's field crew}
        opt = opt || {};
        var box = el('div', 'ss-ed');
        var grid = {};          // stand id → {perm → checkbox}
        var touched = false;    // the stand-by-stand grid was changed by hand: it wins
        var refundTouched = !!initial;

        var presetSel = el('select');
        var o0 = el('option'); o0.value = ''; o0.textContent = '—'; presetSel.appendChild(o0);
        presets.forEach(function (p) {
            var o = el('option'); o.value = p.key; o.textContent = p.label; presetSel.appendChild(o);
        });
        var oc = el('option'); oc.value = 'custom'; oc.textContent = T('ShStfPresetCustom'); presetSel.appendChild(oc);

        var head = el('div', 'ss-pair');
        var lab = el('label', 'ss-f'); lab.appendChild(el('span', '', esc(T('ShStfRole')))); lab.appendChild(presetSel);
        head.appendChild(lab);
        // The function, right of the access: ianseo's list, its groups apart.
        var fnSel = null;
        if (opt.licensee) {
            fnSel = el('select');
            var f0 = el('option'); f0.value = '0'; f0.textContent = '—'; fnSel.appendChild(f0);
            var grp = null;
            (C.functions || []).forEach(function (f) {
                if (grp !== null && grp !== f.group) { var sep = el('option'); sep.value = ''; sep.disabled = true; sep.textContent = '——'; fnSel.appendChild(sep); }
                grp = f.group;
                var o = el('option'); o.value = String(f.id); o.textContent = f.label; fnSel.appendChild(o);
            });
            fnSel.value = String(opt.fn || 0);
            if (!C.fn_edit) fnSel.disabled = true;   // the core's right on its field crew is missing
            var lf = el('label', 'ss-f'); lf.appendChild(el('span', '', esc(T('ShStfFunction')))); lf.appendChild(fnSel);
            head.appendChild(lf);
        }
        box.appendChild(head);

        var standsBox = el('fieldset', 'ss-stands');
        standsBox.appendChild(el('legend', '', esc(T('ShStfStands'))));
        var standChk = {};
        if (!C.stands.length) standsBox.appendChild(el('p', 'ss-muted', esc(T('ShStfNoStandSet'))));
        C.stands.forEach(function (s) {
            var l = el('label', 'ss-chk');
            var c = el('input'); c.type = 'checkbox'; c.value = s.id;
            standChk[s.id] = c;
            l.appendChild(c);
            l.appendChild(el('span', '', esc(s.name) + (s.active ? '' : ' <small>(' + esc(T('ShStfStandInactive')) + ')</small>')));
            standsBox.appendChild(l);
        });
        if (C.shop_on || C.stands.length) box.appendChild(standsBox);

        // Check-in desk: rights for the whole competition, in two posts opened apart — the registry
        // (documents, payments) and the judges (equipment). A post is shown while it is open to the
        // volunteers, or while the volunteer holds one of its rights.
        var deskChk = {};
        [['desk', 'DkRightsTitle', C.desk_on], ['judges', 'DkJudgesRightsTitle', C.judges_on]].forEach(function (post) {
            var mine = deskPerms.filter(function (p) { return p.post === post[0]; });
            var had = !!initial && (initial.desk || []).some(function (k) { return mine.some(function (p) { return p.key === k; }); });
            if (!mine.length || !(post[2] || had)) return;
            var fs = el('fieldset', 'ss-stands ss-desk');
            fs.appendChild(el('legend', '', esc(T(post[1]))));
            mine.forEach(function (p) {
                var l = el('label', 'ss-chk');
                var c = el('input'); c.type = 'checkbox'; c.value = p.key;
                c.addEventListener('change', function () { fromGrid(); });
                deskChk[p.key] = c;
                l.appendChild(c);
                l.appendChild(el('span', '', esc(p.label)));
                fs.appendChild(l);
            });
            box.appendChild(fs);
        });
        function deskList() {
            return deskPerms.filter(function (p) { return deskChk[p.key] && deskChk[p.key].checked; }).map(function (p) { return p.key; });
        }

        var refRow = el('div', 'ss-refund');
        var rMax = el('input'); rMax.type = 'text'; rMax.inputMode = 'decimal'; rMax.size = 6;
        var rTot = el('input'); rTot.type = 'text'; rTot.inputMode = 'decimal'; rTot.size = 6;
        [[T('ShStfRefundMax'), rMax], [T('ShStfRefundTotal'), rTot]].forEach(function (x) {
            var l = el('label', 'ss-f ss-f-small'); l.appendChild(el('span', '', esc(x[0]))); l.appendChild(x[1]); refRow.appendChild(l);
            x[1].addEventListener('input', function () { refundTouched = true; });
        });
        refRow.appendChild(el('small', 'ss-muted', esc(T('ShStfRefundZero'))));
        if (C.shop_on || C.stands.length || deskChk.pay) box.appendChild(refRow);

        var det = el('details', 'ss-fine');
        det.appendChild(el('summary', '', esc(T('ShStfFine'))));
        var tbl = el('div', 'ss-scroll');
        var t = '<table class="ss-grid"><thead><tr><th></th>';
        C.perms.forEach(function (p) { t += '<th>' + esc(p.label) + '</th>'; });
        tbl.innerHTML = t + '</tr></thead><tbody></tbody></table>';
        var tbody = tbl.querySelector('tbody');
        C.stands.forEach(function (s) {
            var tr = el('tr');
            tr.appendChild(el('th', '', esc(s.name)));
            grid[s.id] = {};
            C.perms.forEach(function (p) {
                var td = el('td');
                var c = el('input'); c.type = 'checkbox';
                c.setAttribute('aria-label', s.name + ' — ' + p.label);
                c.addEventListener('change', function () { touched = true; fromGrid(); });
                grid[s.id][p.key] = c;
                td.appendChild(c);
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        det.appendChild(tbl);
        if (C.stands.length) standsBox.appendChild(det);   // the fine tuning belongs to the stands

        // What shows follows what is ticked (user's choice): the fine tuning while a stand is, the
        // refund ceilings while a stand or the payments at the registry are.
        function syncShown() {
            var anyStand = C.stands.some(function (s) { return standChk[s.id].checked; });
            det.hidden = !anyStand;
            refRow.hidden = !(anyStand || (deskChk.pay && deskChk.pay.checked));
        }

        function presetPerms() { var p = presetByKey[presetSel.value]; return p ? p.perms.split(',') : null; }
        function toGrid() {
            var perms = presetPerms();
            if (!perms) return;
            C.stands.forEach(function (s) {
                C.perms.forEach(function (p) { grid[s.id][p.key].checked = standChk[s.id].checked && perms.indexOf(p.key) >= 0; });
            });
            var dk = presetByKey[presetSel.value].desk.split(',');
            Object.keys(deskChk).forEach(function (k) { deskChk[k].checked = dk.indexOf(k) >= 0; });
            if (!refundTouched) {
                var pr = presetByKey[presetSel.value];
                rMax.value = amountIn(pr.refund_max); rTot.value = amountIn(pr.refund_total);
            }
            syncShown();
        }
        // The grid changed by hand: stands ticked = stands with a right; the access shown is the
        // preset giving these rights (the one already shown when two give the same: chairman of
        // judges, judge), else "custom", else none. The director of shooting (no right yet) is
        // never guessed from an empty grid, only kept. The function is not touched.
        function fromGrid() {
            var common = null, uniform = true;
            C.stands.forEach(function (s) {
                var list = C.perms.filter(function (p) { return grid[s.id][p.key].checked; }).map(function (p) { return p.key; }).join(',');
                standChk[s.id].checked = list !== '';
                if (list === '') return;
                if (common === null) common = list; else if (common !== list) uniform = false;
            });
            syncShown();
            var dk = deskList().join(','), cur = presetSel.value, matches = [];
            if (uniform) presets.forEach(function (p) { if (p.key !== 'dos' && p.perms === (common || '') && p.desk === dk) matches.push(p.key); });
            if (matches.indexOf(cur) >= 0) return;
            var any = common !== null || dk !== '';
            presetSel.value = matches.length ? matches[0] : (any ? 'custom' : (cur === 'dos' ? 'dos' : ''));
        }
        function proposeFn() {
            if (!fnSel || fnSel.disabled || fnSel.value !== '0') return;
            var p = presetByKey[presetSel.value];
            if (p && p.fn) fnSel.value = String(p.fn);
        }
        // An access chosen: its rights. One without stand rights leaves no stand ticked; one with
        // stand rights goes to the stands ticked, or every active stand when none is.
        function applyPreset() {
            touched = false; refundTouched = false;
            var p = presetByKey[presetSel.value];
            if (p && p.perms === '') C.stands.forEach(function (s) { standChk[s.id].checked = false; });
            else if (p && !C.stands.some(function (s) { return standChk[s.id].checked; })) {
                C.stands.forEach(function (s) { standChk[s.id].checked = s.active; });
            }
            toGrid();
            syncShown();
        }
        presetSel.addEventListener('change', function () { applyPreset(); proposeFn(); });
        if (fnSel) fnSel.addEventListener('change', function () {
            if (presetSel.value !== '') return;   // an access is set: untouched
            var k = (C.fn_access || {})[fnSel.value];
            if (k && presetOffered(k)) { presetSel.value = k; applyPreset(); }
        });
        Object.keys(standChk).forEach(function (id) {
            standChk[id].addEventListener('change', function () {
                if (!touched || presetByKey[presetSel.value]) { toGrid(); return; }
                // Custom grid, or none: ticking a stand without rights gives it nothing; unticking clears it.
                if (!standChk[id].checked) C.perms.forEach(function (p) { grid[id][p.key].checked = false; });
                syncShown();
            });
        });

        // Initial values.
        if (initial) {
            initial.rights.forEach(function (r) {
                if (!grid[r.stand]) return;
                r.perms.forEach(function (p) { if (grid[r.stand][p]) grid[r.stand][p].checked = true; });
            });
            (initial.desk || []).forEach(function (k) { if (deskChk[k]) deskChk[k].checked = true; });
            touched = true;
            presetSel.value = initial.access && (initial.access === 'custom' || presetOffered(initial.access)) ? initial.access : '';
            fromGrid();
            rMax.value = amountIn(initial.refund_max); rTot.value = amountIn(initial.refund_total);
            if (presetSel.value === 'custom' && C.stands.length) det.open = true;
        } else {
            // A known function proposes its access; otherwise the usual access proposes its function.
            var k = opt.fn ? (C.fn_access || {})[opt.fn] : C.default_preset;
            presetSel.value = k && presetOffered(k) ? k : '';
            applyPreset();
            proposeFn();
        }

        box.value = function () {
            var rights = {}, any = false;
            C.stands.forEach(function (s) {
                var list = C.perms.filter(function (p) { return grid[s.id][p.key].checked; }).map(function (p) { return p.key; });
                if (list.length) { rights[s.id] = list.join(','); any = true; }
            });
            var dk = deskList();
            return { rights: rights, desk: dk.join(','), access: presetSel.value, any: any || dk.length > 0 || presetSel.value === 'dos',
                fn: fnSel && !fnSel.disabled ? fnSel.value : null, fn_was: opt.fnId || 0,
                refund_max: rMax.value, refund_total: rTot.value };
        };
        return box;
    }

    /* ---- Waiting requests ---- */
    var pendCards = {};   // id → {el, json}
    function agoText(min) { return min < 1 ? T('ShStfAgoNow') : T('ShStfAgo', min); }

    function pendingCard(p) {
        var card = el('div', 'ss-card ss-pend');
        card.appendChild(el('div', 'ss-head', '<b>' + esc(p.name || '—') + '</b> <span class="ss-kind">' + esc(p.kind_label)
            + (p.licence ? ' · ' + esc(p.licence) : '') + '</span> <span class="ss-ago" data-ago>' + esc(agoText(p.ago)) + '</span>'));
        card.appendChild(el('div', 'ss-code', esc(T('ShStfCode')) + ' <b>' + esc(p.code.split('').join(' ')) + '</b>'));
        card.appendChild(el('p', 'ss-muted', esc(T('ShStfCodeCheck'))));
        var ed = rightsEditor(null, { licensee: p.kind === 'LICENSEE', fn: p.fn, fnId: p.fn_id });
        card.appendChild(ed);
        var row = el('div', 'ss-btns');
        var ok = btn(T('ShStfApprove'), 'ss-btn-ok ss-btn-big', function () {
            var v = ed.value();
            if (!v.any) { flash(noRightText(), 'err'); return; }
            ok.disabled = true;
            post({ act: 'approve', id: p.id, rights: v.rights, desk: v.desk, access: v.access, fn: v.fn, fn_was: v.fn_was,
                refund_max: v.refund_max, refund_total: v.refund_total })
                .then(function (res) { ok.disabled = false; if (res && !res.error) flash(T('ShStfDone'), 'ok'); });
        });
        row.appendChild(ok);
        row.appendChild(btn(T('ShStfRefuse'), 'ss-btn-danger', function () {
            if (!window.confirm(T('ShStfRefuseConfirm', p.name))) return;
            post({ act: 'refuse', id: p.id });
        }));
        card.appendChild(row);
        return card;
    }

    function renderPending() {
        var box = $('ss-pending');
        var list = state.pending || [];
        $('ss-pending-count').textContent = list.length;
        $('ss-pending-count').className = 'ss-count' + (list.length ? ' on' : '');
        var seen = {};
        if (!list.length) {
            box.innerHTML = '<p class="ss-muted">' + esc(T('ShStfPendingNone')) + '</p>';
            pendCards = {};
        } else {
            var empty = box.querySelector('p.ss-muted');
            if (empty) empty.remove();
            list.forEach(function (p) {
                seen[p.id] = true;
                var c = pendCards[p.id];
                if (!c) { c = pendCards[p.id] = { el: pendingCard(p) }; box.appendChild(c.el); }
                else c.el.querySelector('[data-ago]').textContent = agoText(p.ago);   // the form stays as typed
            });
            Object.keys(pendCards).forEach(function (id) {
                if (!seen[id]) { pendCards[id].el.remove(); delete pendCards[id]; }
            });
        }
        var op = $('ss-ov-pend');
        if (op) op.textContent = list.length ? T('ShStfPendingWaiting', list.length) : '';
    }

    /* ---- Team ---- */
    var teamCards = {};   // id → {el, json, editing}
    // Access of a volunteer saved before accesses were kept: read from the rights.
    function accessOf(m) {
        if (m.access) return m.access;
        var common = null, uniform = true;
        m.rights.forEach(function (r) {
            var l = C.perms.filter(function (p) { return r.perms.indexOf(p.key) >= 0; }).map(function (p) { return p.key; }).join(',');
            if (common === null) common = l; else if (common !== l) uniform = false;
        });
        var dk = deskPerms.filter(function (p) { return (m.desk || []).indexOf(p.key) >= 0; }).map(function (p) { return p.key; }).join(',');
        if (common === null && dk === '') return '';
        var k = '';
        if (uniform) C.presets.forEach(function (p) { if (!k && p.key !== 'dos' && p.perms === (common || '') && p.desk === dk) k = p.key; });
        return k || 'custom';
    }
    function rightsSummary(m) {
        if (m.kind === 'ORGANISER') return '<p>' + esc(T('ShStfAllRights')) + '</p>';
        var html = '<p class="ss-acc">' + esc(T('ShStfAccessIs', presetLabel(accessOf(m))))
            + (m.fn_label ? ' · ' + esc(T('ShStfFunctionIs', m.fn_label)) : '') + '</p><ul class="ss-rights">';
        m.rights.forEach(function (r) {
            var s = standById[r.stand];
            var labels = C.perms.filter(function (p) { return r.perms.indexOf(p.key) >= 0; }).map(function (p) { return p.label; });
            html += '<li><b>' + esc(s ? s.name : r.stand) + '</b> : ' + esc(labels.join(', ')) + '</li>';
        });
        if (m.desk && m.desk.length) {
            [['desk', 'DkRightsShort'], ['judges', 'DkJudges']].forEach(function (post) {
                var dl = deskPerms.filter(function (p) { return p.post === post[0] && m.desk.indexOf(p.key) >= 0; }).map(function (p) { return p.label; });
                if (dl.length) html += '<li><b>' + esc(T(post[1])) + '</b> : ' + esc(dl.join(', ')) + '</li>';
            });
        }
        html += '</ul>';
        if (!m.rights.length) return html;
        if (m.refund_max > 0 && m.refund_total > 0) {
            html += '<p class="ss-muted">' + esc(T('ShStfRefundSummary', amountText(m.refund_max)) + ' — '
                + amountText(m.refunded) + ' / ' + amountText(m.refund_total)) + '</p>';
        } else {
            html += '<p class="ss-muted">' + esc(T('ShStfNoRefund')) + '</p>';
        }
        return html;
    }

    function teamCard(m) {
        var card = el('div', 'ss-card ss-member' + (m.status !== 'active' ? ' ss-' + m.status : ''));
        var badge = m.status === 'locked' ? '<span class="ss-badge ss-badge-warn">' + esc(T('ShStfStatusLocked')) + '</span>'
            : (m.status === 'revoked' ? '<span class="ss-badge ss-badge-off">' + esc(T('ShStfStatusRevoked')) + '</span>' : '');
        var seen = m.idle === null ? T('ShStfSeenNever') : T('ShStfSeenAgo', m.idle < 1 ? T('ShStfAgoNow') : T('ShStfAgo', m.idle));
        card.appendChild(el('div', 'ss-head', '<b>' + esc(m.name) + '</b> <span class="ss-kind">' + esc(m.kind_label)
            + (m.licence ? ' · ' + esc(m.licence) : '') + '</span> ' + badge
            + (m.status === 'revoked' ? '' : ' <span class="ss-ago ss-seen">' + esc(seen) + '</span>')));
        var body = el('div', 'ss-body', rightsSummary(m));
        card.appendChild(body);
        if (m.status === 'revoked') {
            // The function stays in ianseo's field crew (user's choice): said on the card.
            if (m.fn_label) card.appendChild(el('p', 'ss-msg ss-warn', esc(T('ShStfFnKept', m.fn_label))));
            // Cancel: the access back, with its rights. A volunteer without a licence lost their
            // password when it was withdrawn: the answer brings the reset QR code.
            var rb = el('div', 'ss-btns');
            rb.appendChild(btn(T('ShStfRestore'), 'ss-btn-ok', function () {
                post({ act: 'restore', id: m.id }).then(function (res) {
                    if (!res || res.error) return;
                    if (res.svg || res.url) showQr('reset', res);
                    else flash(T('ShStfRestoreDone'), 'ok');
                });
            }));
            card.appendChild(rb);
            return card;
        }

        var row = el('div', 'ss-btns');
        var entry = null;
        if (m.kind !== 'ORGANISER') {
            row.appendChild(btn(T('ShStfEditRights'), '', function () {
                entry = teamCards[m.id];
                if (entry) entry.editing = true;
                var ed = rightsEditor(m, { licensee: m.kind === 'LICENSEE', fn: m.fn, fnId: m.fn_id });
                body.innerHTML = '';
                body.appendChild(ed);
                var eb = el('div', 'ss-btns');
                eb.appendChild(btn(T('ShStfSave'), 'ss-btn-primary', function () {
                    var v = ed.value();
                    if (!v.any) { flash(noRightText(), 'err'); return; }
                    if (entry) entry.editing = false;
                    post({ act: 'update', id: m.id, rights: v.rights, desk: v.desk, access: v.access, fn: v.fn, fn_was: v.fn_was,
                        refund_max: v.refund_max, refund_total: v.refund_total })
                        .then(function (res) { if (res && !res.error) flash(T('ShStfDone'), 'ok'); redrawMember(m.id); });
                }));
                eb.appendChild(btn(T('ShStfCancel'), '', function () { if (entry) entry.editing = false; redrawMember(m.id); }));
                body.appendChild(eb);
                row.hidden = true;
            }));
        }
        if (m.kind === 'LOCAL') {
            row.appendChild(btn(T('ShStfResetPwd'), '', function () {
                post({ act: 'reset', id: m.id }).then(function (res) {
                    if (res && !res.error) showQr('reset', res);
                });
            }));
        }
        if (m.status === 'locked') {
            row.appendChild(btn(T('ShStfUnlock'), 'ss-btn-ok', function () { post({ act: 'unlock', id: m.id }); }));
        }
        var rv = btn(T('ShStfRevoke'), 'ss-btn-danger', function () {
            if (!window.confirm(T('ShStfRevokeConfirm', m.name))) return;
            post({ act: 'revoke', id: m.id }).then(function (res) { if (res && res.fn_note) flash(res.fn_note, 'warn', 15000); });
        });
        // Withdrawing takes the access, not the function of ianseo's field crew: said on the button.
        if (m.fn_label) { rv.classList.add('ss-btn-2l'); rv.appendChild(el('small', 'ss-btn-note', esc(T('ShStfRevokeKeepFn')))); }
        row.appendChild(rv);
        card.appendChild(row);
        return card;
    }

    function redrawMember(id) {
        var c = teamCards[id];
        if (!c) return;
        var m = null;
        (state.team || []).forEach(function (x) { if (x.id === Number(id)) m = x; });
        if (!m) return;
        var fresh = teamCard(m);
        c.el.replaceWith(fresh);
        c.el = fresh; c.json = JSON.stringify(Object.assign({}, m, { idle: null })); c.editing = false;
    }

    function renderTeam() {
        var box = $('ss-team');
        var active = [], revoked = [];
        (state.team || []).forEach(function (m) { (m.status === 'revoked' ? revoked : active).push(m); });
        if (!active.length && !revoked.length) {
            box.innerHTML = '<p class="ss-muted">' + esc(T('ShStfTeamNone')) + '</p>';
            teamCards = {};
            return;
        }
        // Cards being edited are kept as they are; the others are redrawn when they changed.
        var keep = {};
        Object.keys(teamCards).forEach(function (id) { if (teamCards[id].editing) keep[id] = teamCards[id]; });
        var next = {};
        var frag = document.createDocumentFragment();
        function place(m, parent) {
            // "Seen … ago" is not refreshed (hidden after two minutes): no redraw for it alone.
            var j = JSON.stringify(Object.assign({}, m, { idle: null })), c = teamCards[m.id];
            if (keep[m.id]) c = keep[m.id];
            else if (!c || c.json !== j) c = { el: teamCard(m), json: j, editing: false };
            next[m.id] = c;
            parent.appendChild(c.el);
        }
        active.forEach(function (m) { place(m, frag); });
        if (revoked.length) {
            var det = el('details', 'ss-revoked');
            var openBefore = box.querySelector('details.ss-revoked');
            if (openBefore && openBefore.open) det.open = true;
            det.appendChild(el('summary', '', esc(T('ShStfRevokedTitle', revoked.length))));
            revoked.forEach(function (m) { place(m, det); });
            frag.appendChild(det);
        }
        box.innerHTML = '';
        box.appendChild(frag);
        teamCards = next;
    }

    function renderRunning() {
        var r = $('ss-running');
        if (!r) return;
        var left = Number(state.invite) || 0;
        if (!left || !$('ss-overlay').hidden) { r.hidden = true; return; }
        r.hidden = false;
        r.innerHTML = '';
        r.appendChild(el('span', '', esc(T('ShStfInviteRunning', Math.ceil(left / 60)))));
        r.appendChild(btn(T('ShStfInviteNew'), 'ss-btn-primary', invite));
        r.appendChild(btn(T('ShStfInviteStop'), 'ss-btn-danger', stopInvite));
    }

    /* ---- Officials of ianseo's field crew without an access (beside the waiting requests) ----
       Redrawn only when the list changed, never while a card is being filled in it. */
    var offJson = '';
    function renderOfficials() {
        var box = $('ss-officials');
        if (!box) return;
        var list = state.officials || [];
        $('ss-off-count').textContent = list.length;
        if (box.querySelector('tr.ss-add-row')) return;
        var j = JSON.stringify(list);
        if (j === offJson) return;
        offJson = j;
        box.innerHTML = '';
        if (!list.length) { box.appendChild(el('p', 'ss-muted', esc(T('ShStfOfficialsNone')))); return; }
        var wrap = el('div', 'ss-scroll');
        var tbl = el('table', 'ss-table ss-find-table');
        tbl.innerHTML = '<thead><tr><th>' + esc(T('ShStfFindLicence')) + '</th><th>' + esc(T('ShStfFamilyName')) + '</th><th>'
            + esc(T('ShStfGivenName')) + '</th><th>' + esc(T('ShStfFunction')) + '</th><th></th></tr></thead>';
        var tb = el('tbody');
        list.forEach(function (r) {
            var tr = el('tr');
            tr.innerHTML = '<td>' + esc(r.licence) + '</td><td><b>' + esc(r.family) + '</b></td><td>' + esc(r.given) + '</td><td>'
                + esc(r.fn_label) + '</td>';
            var act = el('td', 'ss-find-act', teamBadge(r.team));
            act.appendChild(btn(T('ShStfOfficialsAdd'), 'ss-btn-primary', function () { openAdd(r, tr, 5, false); }));
            tr.appendChild(act);
            tb.appendChild(tr);
        });
        tbl.appendChild(tb);
        wrap.appendChild(tbl);
        box.appendChild(wrap);
    }

    function render() {
        // The page must not move under the reader: the reading position is put back.
        var y = window.pageYOffset;
        renderPending(); renderTeam(); renderRunning(); renderOfficials();
        if (window.pageYOffset !== y) window.scrollTo(0, y);
        syncPolling();
    }

    /* ---- Full-screen QR code ---- */
    var timer = null, ovKind = '';
    function showQr(kind, res) {
        ovKind = kind;
        var ov = $('ss-overlay');
        $('ss-ov-title').textContent = kind === 'reset' ? T('ShStfResetTitle2') : T('ShStfInviteTitle');
        $('ss-ov-name').textContent = kind === 'reset' ? (res.name || '') : '';
        $('ss-ov-qr').innerHTML = res.svg || '<p>' + esc(T('ShStfQrMissing')) + '</p>';
        $('ss-ov-stop').hidden = kind === 'reset';
        $('ss-ov-close').textContent = kind === 'reset' ? T('ShStfClose') : T('ShStfHide');
        $('ss-ov-pend').hidden = kind === 'reset';
        ov.hidden = false;
        document.documentElement.classList.add('ss-noscroll');
        var end = Date.now() + (Number(res.seconds) || 0) * 1000;
        clearInterval(timer);
        function tick() {
            var s = Math.max(0, Math.round((end - Date.now()) / 1000));
            var mm = Math.floor(s / 60), ss = s % 60;
            var txt = mm + ':' + (ss < 10 ? '0' : '') + ss;
            $('ss-ov-timer').textContent = s > 0 ? T(kind === 'reset' ? 'ShStfResetLeft' : 'ShStfInviteLeft', txt) : T('ShStfInviteOver');
            if (s <= 0) { clearInterval(timer); $('ss-ov-qr').innerHTML = ''; }
        }
        tick();
        timer = setInterval(tick, 1000);
        renderPending();
        $('ss-ov-close').focus();
    }
    function hideQr() {
        $('ss-overlay').hidden = true;
        document.documentElement.classList.remove('ss-noscroll');
        clearInterval(timer);
        $('ss-ov-qr').innerHTML = '';
        renderRunning();
    }
    function invite() {
        post({ act: 'invite' }).then(function (res) { if (res && !res.error) showQr('enrol', res); });
    }
    function stopInvite() {
        post({ act: 'invite_stop' }).then(function () { hideQr(); });
    }

    /* ---- A licensee added without the QR code: search, then rights, then confirm ----
       The licence file of the competition and the licensee-space accounts (admin/staff.php?find=).
       The rights are those of a waiting request; the card opens under the licensee's row. */
    var findSeq = 0;
    function find(q) {
        var box = $('ss-find');
        q = String(q || '').trim();
        if (q.length < 2) { box.hidden = true; box.innerHTML = ''; return; }
        var seq = ++findSeq;
        box.hidden = false;
        box.innerHTML = '<p class="ss-muted">' + esc(T('ShLoading')) + '</p>';
        Shp.api(C.self + '?find=' + encodeURIComponent(q), null).then(function (res) {
            if (seq !== findSeq) return;
            if (!res || res.error) { box.hidden = true; flash((res && res.msg) || T('ShErrNetwork'), 'err'); return; }
            renderFind(res);
        }, function () { flash(T('ShErrNetwork'), 'err'); });
    }
    function closeFind() {
        findSeq++;
        $('ss-find').hidden = true;
        $('ss-find').innerHTML = '';
    }
    function teamBadge(st) {
        if (st === 'active' || st === 'locked') return '<span class="ss-badge ss-badge-in">' + esc(T('ShStfFindInTeam')) + '</span>';
        if (st === 'pending') return '<span class="ss-badge ss-badge-warn">' + esc(T('ShStfFindPending')) + '</span>';
        if (st === 'revoked') return '<span class="ss-badge ss-badge-off">' + esc(T('ShStfStatusRevoked')) + '</span>';
        return '';
    }
    function renderFind(res) {
        var box = $('ss-find');
        box.innerHTML = '';
        var head = el('div', 'ss-find-head');
        head.appendChild(el('b', '', esc(T('ShStfFindTitle'))));
        head.appendChild(btn(T('ShStfClose'), '', closeFind));
        box.appendChild(head);
        if (!res.rows.length) { box.appendChild(el('p', 'ss-muted', esc(T('ShStfFindNone')))); return; }
        var wrap = el('div', 'ss-scroll');
        var tbl = el('table', 'ss-table ss-find-table');
        tbl.innerHTML = '<thead><tr><th>' + esc(T('ShStfFindLicence')) + '</th><th>' + esc(T('ShStfFindName')) + '</th><th>'
            + esc(T('ShStfFindClub')) + '</th><th>' + esc(T('ShStfFindBorn')) + '</th><th>' + esc(T('ShStfFindSpace'))
            + '</th><th></th></tr></thead>';
        var tb = el('tbody');
        res.rows.forEach(function (r) {
            var tr = el('tr');
            tr.innerHTML = '<td>' + esc(r.licence) + '</td><td><b>' + esc(r.family) + '</b> ' + esc(r.given) + '</td><td>'
                + esc([r.club, r.club_name].filter(Boolean).join(' — ')) + '</td><td>' + esc(r.born) + '</td><td>'
                + esc(T(r.account ? 'ShStfFindYes' : 'ShStfFindNo')) + '</td>';
            var act = el('td', 'ss-find-act', teamBadge(r.team));
            if (r.team !== 'active' && r.team !== 'locked') {
                act.appendChild(btn(T('ShStfFindAdd'), 'ss-btn-primary', function () { openAdd(r, tr, 6, true); }));
            }
            tr.appendChild(act);
            tb.appendChild(tr);
        });
        tbl.appendChild(tb);
        wrap.appendChild(tbl);
        box.appendChild(wrap);
        if (res.more) box.appendChild(el('p', 'ss-muted', esc(T('ShStfFindMore'))));
    }
    // The licensee's card under their row: who, the access and the function (as for a waiting
    // request; a function already in ianseo's field crew is shown and proposes its access), confirm.
    function openAdd(r, tr, cols, fromSearch) {
        var old = tr.parentNode.querySelector('tr.ss-add-row');
        if (old) old.remove();
        var row = el('tr', 'ss-add-row');
        var td = el('td');
        td.colSpan = cols;
        var card = el('div', 'ss-card ss-pend ss-add');
        var name = r.given + ' ' + r.family;
        card.appendChild(el('div', 'ss-head', '<b>' + esc(name) + '</b> <span class="ss-kind">' + esc(T('ShStfKindLicensee'))
            + ' · ' + esc(r.licence) + (r.club ? ' · ' + esc([r.club, r.club_name].filter(Boolean).join(' — ')) : '') + '</span>'));
        if (!r.account) card.appendChild(el('p', 'ss-msg ss-warn', esc(T('ShStfFindNoAccount'))));
        var ed = rightsEditor(null, { licensee: true, fn: r.fn || 0, fnId: r.fn_id || 0 });
        card.appendChild(ed);
        var b = el('div', 'ss-btns');
        var ok = btn(T('ShStfAddConfirm'), 'ss-btn-ok ss-btn-big', function () {
            var v = ed.value();
            if (!v.any) { flash(noRightText(), 'err'); return; }
            ok.disabled = true;
            post({ act: 'add', licence: r.licence, rights: v.rights, desk: v.desk, access: v.access, fn: v.fn, fn_was: v.fn_was,
                refund_max: v.refund_max, refund_total: v.refund_total })
                .then(function (res) {
                    ok.disabled = false;
                    if (!res || res.error) return;
                    flash(T('ShStfAdded', name), 'ok');
                    row.remove();
                    if (fromSearch) { closeFind(); $('ss-find-q').value = ''; }
                    offJson = '';
                    renderOfficials();
                });
        });
        b.appendChild(ok);
        b.appendChild(btn(T('ShStfCancel'), '', function () { row.remove(); }));
        card.appendChild(b);
        td.appendChild(card);
        row.appendChild(td);
        tr.parentNode.insertBefore(row, tr.nextSibling);
    }
    var ff = $('ss-find-form');
    if (ff) ff.addEventListener('submit', function (e) { e.preventDefault(); find($('ss-find-q').value); });

    var inv = $('ss-invite');
    if (inv) inv.addEventListener('click', invite);
    $('ss-ov-close').addEventListener('click', hideQr);
    $('ss-ov-stop').addEventListener('click', stopInvite);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('ss-overlay').hidden) hideQr(); });

    render();
    setTimeout(function () { var r = $('shpstf'); if (r) r.classList.add('ss-stale'); }, 120000);
})();
