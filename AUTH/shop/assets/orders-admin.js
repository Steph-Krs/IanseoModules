/* Points of sale — organiser page of the live orders (admin/orders.php).
   One card per stand and the list of orders, drawn from the JSON of the page (#shp-cfg) and
   refreshed every 10 seconds by ONE request (the filters travel in the query string). The list
   is not redrawn while the organiser is typing a refund or has just chosen a filter that has not
   come back yet. Every text comes from #shp-texts. */
(function () {
    'use strict';
    var Shp = window.Shp;
    if (!Shp || !Shp.cfg || !Shp.cfg.self) return;
    var C = Shp.cfg, T = Shp.t, esc = Shp.esc;
    var state = C.state || { stands: [], orders: [], total: 0, shown: 0 };
    var filters = C.filters || { stand: 0, status: 'open', pay: 'all', q: '' };
    var expanded = {};      // order id → true, rows whose detail is open
    var refunding = null;   // {id, idem}: refund panel open (the list is not redrawn meanwhile)
    var flashTimer = null;

    function $(id) { return document.getElementById(id); }
    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html !== undefined) e.innerHTML = html;
        return e;
    }
    function money(v) { return Shp.money(Number(v) || 0); }

    function flash(text, type) {
        var f = $('so-flash');
        if (!f) return;
        f.innerHTML = '';
        if (!text) return;
        var m = el('div', 'sa-msg sa-' + (type || 'ok'));
        m.setAttribute('role', type === 'err' ? 'alert' : 'status');
        m.textContent = text;
        f.appendChild(m);
        clearTimeout(flashTimer);
        flashTimer = setTimeout(function () { f.innerHTML = ''; }, 7000);
    }

    function query() {
        var p = [];
        if (filters.stand) p.push('stand=' + encodeURIComponent(filters.stand));
        if (filters.status && filters.status !== 'open') p.push('status=' + encodeURIComponent(filters.status));
        if (filters.pay && filters.pay !== 'all') p.push('pay=' + encodeURIComponent(filters.pay));
        if (filters.q) p.push('q=' + encodeURIComponent(filters.q));
        return p.length ? '?' + p.join('&') : '';
    }

    /* ---- Stands ---- */
    function minutes(n) { return T('ShRepMinutes', n); }
    function counter(label, n, warn) {
        return '<div class="sr-count' + (n > 0 && warn ? ' sr-warn' : '') + (n === 0 ? ' sr-zero' : '') + '"><b>' + n + '</b><span>' + esc(label) + '</span></div>';
    }
    function drawStands() {
        var box = $('so-stands');
        box.innerHTML = '';
        state.stands.forEach(function (s) {
            if (!s.active && !s.to_pay && !s.prep && !s.ready && !s.pre) return;
            var c = el('button', 'sr-stand' + (filters.stand === s.id ? ' sr-on' : '') + (s.open ? '' : ' sr-closed'));
            c.type = 'button';
            var wait = '';
            if (s.wait !== null) wait += '<div class="sr-wait' + (s.wait >= 10 ? ' sr-late' : '') + '">' + esc(T('ShRepWait')) + ' <b>' + esc(minutes(s.wait)) + '</b></div>';
            if (s.ready_wait !== null) wait += '<div class="sr-wait' + (s.ready_wait >= 10 ? ' sr-late' : '') + '">' + esc(T('ShRepReadyWait')) + ' <b>' + esc(minutes(s.ready_wait)) + '</b></div>';
            c.innerHTML = '<div class="sr-stand-head"><span class="sr-stand-name">' + esc(s.name) + '</span>'
                + '<span class="sr-tag ' + (s.open ? 'sr-tag-on' : '') + '">' + esc(T(s.open ? 'ShRepStandOpen' : 'ShRepStandClosed')) + '</span></div>'
                + '<div class="sr-counts">' + counter(T('ShRepToPay'), s.to_pay, true) + counter(T('ShRepPrep'), s.prep, false)
                + counter(T('ShRepReady'), s.ready, true) + (s.pre ? counter(T('ShRepPre'), s.pre, false) : '') + '</div>'
                + wait + '<div class="sr-staff">' + esc(T('ShRepStaffSeen', s.staff)) + '</div>';
            c.addEventListener('click', function () {
                filters.stand = filters.stand === s.id ? 0 : s.id;
                var sel = $('so-f-stand');
                if (sel) sel.value = String(filters.stand);
                reload();
            });
            box.appendChild(c);
        });
    }

    /* ---- Filters (built once, never redrawn: the organiser may be typing) ---- */
    function select(id, label, options, value, onchange) {
        var wrap = el('label', 'sa-f');
        wrap.appendChild(el('span', '', esc(label)));
        var sel = el('select');
        sel.id = id;
        options.forEach(function (o) {
            var op = el('option');
            op.value = o[0];
            op.textContent = o[1];
            sel.appendChild(op);
        });
        sel.value = String(value);
        sel.addEventListener('change', function () { onchange(sel.value); reload(); });
        wrap.appendChild(sel);
        return wrap;
    }
    function buildFilters() {
        var box = $('so-filters');
        var stands = [['0', T('ShRepAllStands')]];
        state.stands.forEach(function (s) { stands.push([String(s.id), s.name]); });
        box.appendChild(select('so-f-stand', T('ShRepFilterStand'), stands, filters.stand, function (v) { filters.stand = parseInt(v, 10) || 0; }));
        box.appendChild(select('so-f-status', T('ShRepFilterStatus'), [['open', T('ShRepStatusOpen')], ['placed', T('ShStPlaced')],
            ['preparing', T('ShStPreparing')], ['ready', T('ShStReady')], ['delivered', T('ShStDelivered')], ['cancelled', T('ShStCancelled')],
            ['all', T('ShRepStatusAll')]], filters.status, function (v) { filters.status = v; }));
        box.appendChild(select('so-f-pay', T('ShRepFilterPay'), [['all', T('ShRepPayAll')], ['unpaid', T('ShRepPayUnpaid')], ['paid', T('ShPayPaid')],
            ['tab', T('ShPayTab')], ['refunded', T('ShPayRefunded')]], filters.pay, function (v) { filters.pay = v; }));
        var wrap = el('label', 'sa-f sa-grow');
        wrap.appendChild(el('span', '', esc(T('ShRepFilterSearch'))));
        var q = el('input');
        q.type = 'text';
        q.id = 'so-f-q';
        q.maxLength = 40;
        q.value = filters.q;
        q.autocomplete = 'off';
        var timer = null;
        q.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { filters.q = q.value; reload(); }, 350);
        });
        wrap.appendChild(q);
        box.appendChild(wrap);
    }

    /* ---- Orders ---- */
    function act(body) {
        body.csrf = C.csrf;
        return Shp.api(C.self, body).then(function (res) {
            if (res && res.state) { state = res.state; draw(true); }
            if (res && res.error) flash(res.msg || T('ShErrNetwork'), 'err');
            else flash(T('ShRepDone'), 'ok');
            return res;
        }, function () { flash(T('ShErrNetwork'), 'err'); });
    }

    function actions(o) {
        var box = el('div', 'sr-acts');
        if (!C.can_write) return box;
        o.moves.forEach(function (to) {
            var b = el('button', 'sa-btn');
            b.type = 'button';
            b.textContent = T('ShRepMoveTo') + ' ' + T({ placed: 'ShStPlaced', preparing: 'ShStPreparing', ready: 'ShStReady', delivered: 'ShStDelivered' }[to]);
            b.addEventListener('click', function () { act({ act: 'status', id: o.id, to: to }); });
            box.appendChild(b);
        });
        if (o.can_cancel) {
            var c = el('button', 'sa-btn sa-danger');
            c.type = 'button';
            c.textContent = T('ShRepCancelOrder');
            c.addEventListener('click', function () {
                if (o.paid > 0.004) {
                    refunding = { id: o.id, idem: Shp.uuid() };
                    draw(true);
                } else if (window.confirm(T('ShRepCancelConfirm', o.number))) {
                    act({ act: 'cancel', id: o.id });
                }
            });
            box.appendChild(c);
        }
        return box;
    }

    function refundPanel(o) {
        var p = el('div', 'sr-refund');
        p.appendChild(el('h3', '', esc(T('ShRepCancelPaidTitle', o.number + ' — ' + money(o.paid)))));
        var m = el('select');
        m.id = 'so-refund-method';
        C.methods.forEach(function (x) { var op = el('option'); op.value = x.code; op.textContent = x.label; m.appendChild(op); });
        var lm = el('label', 'sa-f'); lm.appendChild(el('span', '', esc(T('ShRepRefundMethod')))); lm.appendChild(m);
        var r = el('input');
        r.type = 'text'; r.maxLength = 120; r.id = 'so-refund-reason';
        var lr = el('label', 'sa-f sa-grow'); lr.appendChild(el('span', '', esc(T('ShRepRefundReason')))); lr.appendChild(r);
        var row = el('div', 'sa-row'); row.appendChild(lm); row.appendChild(lr);
        p.appendChild(row);
        var bar = el('div', 'sa-bar');
        var go = el('button', 'sa-btn sa-danger'); go.type = 'button'; go.textContent = T('ShRepRefundGo');
        go.addEventListener('click', function () {
            var reason = r.value.replace(/\s+/g, ' ').trim();
            if (!reason) { r.focus(); return; }
            var idem = refunding.idem;
            refunding = null;
            act({ act: 'cancel', id: o.id, refund: { method: m.value, reason: reason, idem: idem } });
        });
        var back = el('button', 'sa-btn'); back.type = 'button'; back.textContent = T('ShRepBack');
        back.addEventListener('click', function () { refunding = null; draw(true); });
        bar.appendChild(go); bar.appendChild(back);
        p.appendChild(bar);
        return p;
    }

    function drawList() {
        var box = $('so-list');
        box.innerHTML = '';
        if (!state.orders.length) {
            box.appendChild(el('p', 'sa-muted', esc(T('ShRepNoOrders'))));
            return;
        }
        var wrap = el('div', 'sa-scroll');
        var table = el('table', 'sa-table sr-orders');
        table.innerHTML = '<thead><tr><th>' + esc(T('ShRepColOrder')) + '</th><th>' + esc(T('ShRepColStand')) + '</th><th>' + esc(T('ShRepColCustomer'))
            + '</th><th>' + esc(T('ShRepColStatus')) + '</th><th>' + esc(T('ShRepColPay')) + '</th><th class="sr-r">' + esc(T('ShRepColTotal'))
            + '</th><th>' + esc(T('ShRepColSince')) + '</th></tr></thead>';
        var body = el('tbody');
        state.orders.forEach(function (o) {
            var tr = el('tr', 'sr-row sr-st-' + o.status + (expanded[o.id] ? ' sr-open' : ''));
            tr.tabIndex = 0;
            tr.setAttribute('aria-expanded', expanded[o.id] ? 'true' : 'false');
            tr.innerHTML = '<td data-label="' + esc(T('ShRepColOrder')) + '"><b class="sr-num">' + esc(o.number) + '</b>'
                + (o.channel === 'preorder' ? ' <small>' + esc(T('ShRepPreorder')) + '</small>' : '') + '</td>'
                + '<td data-label="' + esc(T('ShRepColStand')) + '">' + esc(o.stand_name) + '</td>'
                + '<td data-label="' + esc(T('ShRepColCustomer')) + '">' + esc(o.customer) + '</td>'
                + '<td data-label="' + esc(T('ShRepColStatus')) + '"><span class="sr-pill sr-pill-' + esc(o.status) + '">' + esc(o.status_label) + '</span></td>'
                + '<td data-label="' + esc(T('ShRepColPay')) + '"><span class="sr-pay sr-pay-' + esc(o.pay_state) + '">' + esc(o.pay_label) + '</span></td>'
                + '<td class="sr-r" data-label="' + esc(T('ShRepColTotal')) + '">' + esc(money(o.total)) + '</td>'
                + '<td data-label="' + esc(T('ShRepColSince')) + '">' + esc(o.created) + '</td>';
            function toggle() { expanded[o.id] = !expanded[o.id]; if (!expanded[o.id] && refunding && refunding.id === o.id) refunding = null; draw(true); }
            tr.addEventListener('click', toggle);
            tr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
            body.appendChild(tr);
            if (expanded[o.id]) {
                var dr = el('tr', 'sr-detail');
                var td = el('td');
                td.colSpan = 7;
                td.appendChild(el('div', 'sr-lines', esc(o.lines !== '' ? o.lines : '—')
                    + (o.note !== '' ? '<br><small>' + esc(T('ShRepNote')) + ' ' + esc(o.note) + '</small>' : '')
                    + '<br><small>' + esc(o.mode_label) + '</small>'));
                td.appendChild(refunding && refunding.id === o.id ? refundPanel(o) : actions(o));
                dr.appendChild(td);
                body.appendChild(dr);
            }
        });
        table.appendChild(body);
        wrap.appendChild(table);
        box.appendChild(wrap);
    }

    function draw(force) {
        drawStands();
        // A refund being typed, or the focus in a field of the list, is not taken away by a refresh.
        var active = document.activeElement;
        var typing = refunding || (active && $('so-list') && $('so-list').contains(active) && active.tagName === 'INPUT');
        if (force || !typing) drawList();
        var foot = $('so-foot');
        if (foot) {
            var d = new Date();
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            foot.textContent = T('ShRepShown', state.shown + ' / ' + state.total) + ' · ' + T('ShRepUpdated', pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()));
        }
    }

    /* ---- Loading ---- */
    var poller = null;
    function fetchState() {
        return Shp.api(C.self + query(), null).then(function (res) {
            if (res && res.state) { state = res.state; draw(false); }
            else if (res && res.error) flash(res.msg || T('ShErrNetwork'), 'err');
        });
    }
    function reload() {
        expanded = {};
        refunding = null;
        if (poller) poller.now(); else fetchState();
    }

    buildFilters();
    draw(true);
    poller = Shp.poll(fetchState, C.poll || 10000, 30000);
})();
