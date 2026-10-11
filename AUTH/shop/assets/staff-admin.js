/* Points of sale and check-in desk — organiser page of the volunteers (admin/staff.php).
   Waiting requests and team are drawn from the JSON of the page (#shp-cfg) and refreshed every
   3 seconds (15 when the page is hidden). A card being edited is never redrawn under the
   organiser's fingers. Every text comes from #shp-texts. */
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
    // Presets offered: those of the stands while there are stands, those of the desk while it is on.
    var presets = C.presets.filter(function (p) { return p.perms === '' ? !!C.desk_on : (C.shop_on || C.stands.length > 0); });
    function noRightText() { return T(C.desk_on ? 'DkErrNoRight' : 'ShStfErrNoStand'); }

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
    function flash(text, type) {
        var f = $('ss-flash');
        if (!f) return;
        f.innerHTML = '';
        if (!text) return;
        var m = el('div', 'ss-msg ss-' + (type || 'ok'));
        m.textContent = text;
        f.appendChild(m);
        clearTimeout(flashTimer);
        flashTimer = setTimeout(function () { f.innerHTML = ''; }, 6000);
    }

    /* ---- Requests ---- */
    function post(body) {
        body.csrf = C.csrf;
        return Shp.api(C.self, body).then(function (res) {
            if (res && res.pending) { state = res; render(); }
            if (res && res.error) flash(res.msg || T('ShErrNetwork'), 'err');
            return res;
        }, function () { flash(T('ShErrNetwork'), 'err'); return { error: 1 }; });
    }
    function refresh() {
        return Shp.api(C.self, null).then(function (res) {
            if (res && res.pending) { state = res; render(); }
        });
    }

    /* ---- Rights editor: role preset × stands ticked, or rights stand by stand; desk rights ---- */
    function rightsEditor(initial) {
        // initial: {rights: [{stand, perms: []}], desk: [], refund_max, refund_total} or null (new request)
        var box = el('div', 'ss-ed');
        var grid = {};          // stand id → {perm → checkbox}
        var touched = false;    // the stand-by-stand grid was changed by hand: it wins
        var refundTouched = !!initial;

        var presetSel = el('select');
        presets.forEach(function (p) {
            var o = el('option'); o.value = p.key; o.textContent = p.label; presetSel.appendChild(o);
        });
        var oc = el('option'); oc.value = ''; oc.textContent = T('ShStfPresetCustom'); presetSel.appendChild(oc);

        var lab = el('label', 'ss-f'); lab.appendChild(el('span', '', esc(T('ShStfRole')))); lab.appendChild(presetSel);
        box.appendChild(lab);

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

        // Check-in desk: rights for the whole competition.
        var deskChk = {};
        if (C.desk_on || (initial && initial.desk && initial.desk.length)) {
            var deskBox = el('fieldset', 'ss-stands ss-desk');
            deskBox.appendChild(el('legend', '', esc(T('DkRightsTitle'))));
            deskPerms.forEach(function (p) {
                var l = el('label', 'ss-chk');
                var c = el('input'); c.type = 'checkbox'; c.value = p.key;
                c.addEventListener('change', function () { fromGrid(); });
                deskChk[p.key] = c;
                l.appendChild(c);
                l.appendChild(el('span', '', esc(p.label)));
                deskBox.appendChild(l);
            });
            box.appendChild(deskBox);
        }
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
        if (C.shop_on || C.stands.length) box.appendChild(refRow);

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
        if (C.stands.length) box.appendChild(det);

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
        }
        // The grid changed by hand: stands ticked = stands with a right; preset shown when uniform
        // (same rights on every stand ticked, and the same desk rights as the preset).
        function fromGrid() {
            var common = null, uniform = true;
            C.stands.forEach(function (s) {
                var list = C.perms.filter(function (p) { return grid[s.id][p.key].checked; }).map(function (p) { return p.key; }).join(',');
                standChk[s.id].checked = list !== '';
                if (list === '') return;
                if (common === null) common = list; else if (common !== list) uniform = false;
            });
            var match = '', dk = deskList().join(',');
            if (uniform) presets.forEach(function (p) { if (p.perms === (common || '') && p.desk === dk) match = p.key; });
            presetSel.value = match;
        }
        presetSel.addEventListener('change', function () { touched = false; refundTouched = false; toGrid(); });
        Object.keys(standChk).forEach(function (id) {
            standChk[id].addEventListener('change', function () {
                if (!touched || presetSel.value !== '') { toGrid(); return; }
                // Custom grid: ticking a stand without rights gives it nothing; unticking clears it.
                if (!standChk[id].checked) C.perms.forEach(function (p) { grid[id][p.key].checked = false; });
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
            fromGrid();
            rMax.value = amountIn(initial.refund_max); rTot.value = amountIn(initial.refund_total);
            if (presetSel.value === '' && C.stands.length) det.open = true;
        } else {
            presetSel.value = presetByKey[C.default_preset] && presets.indexOf(presetByKey[C.default_preset]) >= 0
                ? C.default_preset : (presets[0] ? presets[0].key : '');
            C.stands.forEach(function (s) { standChk[s.id].checked = s.active; });
            toGrid();
        }

        box.value = function () {
            var rights = {}, any = false;
            C.stands.forEach(function (s) {
                var list = C.perms.filter(function (p) { return grid[s.id][p.key].checked; }).map(function (p) { return p.key; });
                if (list.length) { rights[s.id] = list.join(','); any = true; }
            });
            var dk = deskList();
            return { rights: rights, desk: dk.join(','), any: any || dk.length > 0, refund_max: rMax.value, refund_total: rTot.value };
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
        var ed = rightsEditor(null);
        card.appendChild(ed);
        var row = el('div', 'ss-btns');
        var ok = btn(T('ShStfApprove'), 'ss-btn-ok ss-btn-big', function () {
            var v = ed.value();
            if (!v.any) { flash(noRightText(), 'err'); return; }
            ok.disabled = true;
            post({ act: 'approve', id: p.id, rights: v.rights, desk: v.desk, refund_max: v.refund_max, refund_total: v.refund_total })
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
    function rightsSummary(m) {
        if (m.kind === 'ORGANISER') return '<p>' + esc(T('ShStfAllRights')) + '</p>';
        var html = '<ul class="ss-rights">';
        m.rights.forEach(function (r) {
            var s = standById[r.stand];
            var labels = C.perms.filter(function (p) { return r.perms.indexOf(p.key) >= 0; }).map(function (p) { return p.label; });
            html += '<li><b>' + esc(s ? s.name : r.stand) + '</b> : ' + esc(labels.join(', ')) + '</li>';
        });
        if (m.desk && m.desk.length) {
            var dl = deskPerms.filter(function (p) { return m.desk.indexOf(p.key) >= 0; }).map(function (p) { return p.label; });
            html += '<li><b>' + esc(T('DkRightsShort')) + '</b> : ' + esc(dl.join(', ')) + '</li>';
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
            + (m.status === 'revoked' ? '' : ' <span class="ss-ago">' + esc(seen) + '</span>')));
        var body = el('div', 'ss-body', rightsSummary(m));
        card.appendChild(body);
        if (m.status === 'revoked') return card;

        var row = el('div', 'ss-btns');
        var entry = null;
        if (m.kind !== 'ORGANISER') {
            row.appendChild(btn(T('ShStfEditRights'), '', function () {
                entry = teamCards[m.id];
                if (entry) entry.editing = true;
                var ed = rightsEditor(m);
                body.innerHTML = '';
                body.appendChild(ed);
                var eb = el('div', 'ss-btns');
                eb.appendChild(btn(T('ShStfSave'), 'ss-btn-primary', function () {
                    var v = ed.value();
                    if (!v.any) { flash(noRightText(), 'err'); return; }
                    if (entry) entry.editing = false;
                    post({ act: 'update', id: m.id, rights: v.rights, desk: v.desk, refund_max: v.refund_max, refund_total: v.refund_total })
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
        row.appendChild(btn(T('ShStfRevoke'), 'ss-btn-danger', function () {
            if (!window.confirm(T('ShStfRevokeConfirm', m.name))) return;
            post({ act: 'revoke', id: m.id });
        }));
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
        c.el = fresh; c.json = JSON.stringify(m); c.editing = false;
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
            var j = JSON.stringify(m), c = teamCards[m.id];
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

    function render() { renderPending(); renderTeam(); renderRunning(); }

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

    var inv = $('ss-invite');
    if (inv) inv.addEventListener('click', invite);
    $('ss-ov-close').addEventListener('click', hideQr);
    $('ss-ov-stop').addEventListener('click', stopInvite);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('ss-overlay').hidden) hideQr(); });

    render();
    Shp.poll(refresh, 3000, 15000);
})();
