/* Points of sale — the volunteers' till (staff/index.php). Draws Sell, Orders, Stock and Cash
   from the state of one stand (staff/api/sync.php, asked every 4 s) and sends the actions of the
   volunteer. Needs shp.js (Shp.api, Shp.send, Shp.poll, Shp.toast, Shp.t, Shp.money…).

   Rules kept here:
   - every write that moves money, stock or an order carries a key made ONCE when its screen opens
     and sent with Shp.send: the same key is sent again after a network cut, the server does it once;
   - a button that sends is disabled until the answer (no double tap);
   - nothing is decided here: the server checks the right of the volunteer on the stand, the
     ceilings of refunds, the stock. Hidden buttons are only comfort;
   - no text for people is written in this file: they all come from #shp-texts. */
(function () {
    'use strict';
    var Shp = window.Shp;
    var root = document.getElementById('pos');
    if (!Shp || !root) return;

    var C = Shp.cfg;
    var T = Shp.t;
    var FATAL = ['signed_out', 'pending', 'refused', 'revoked', 'expired', 'shop_off'];
    var S = readState();
    S.at = Date.now();
    var ui = {
        tab: '', filter: 'queue', cat: '', ticket: [], ticketOpen: false, sheet: null, alertOn: false,
        psearch: '', report: null, reportAt: 0, accounts: null, accountQ: '', locked: false, seen: null, head: ''
    };
    var els = {};
    var poller = null;

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    function readState() {
        var el = document.getElementById('shp-state');
        try { return JSON.parse(el.textContent) || {}; } catch (e) { return {}; }
    }

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

    function store(key, value) {
        try {
            if (value === undefined) return window.localStorage.getItem('shp-' + key + '-' + C.scope);
            window.localStorage.setItem('shp-' + key + '-' + C.scope, value);
        } catch (e) { /* private mode: the till works without */ }
        return null;
    }

    function stand() {
        var i;
        for (i = 0; i < (S.stands || []).length; i++) if (S.stands[i].id === S.stand) return S.stands[i];
        return null;
    }

    function can(perm) {
        var st = stand();
        return !!st && (st.perms.indexOf(perm) >= 0 || st.perms.indexOf('manage') >= 0);
    }

    function money(n) { return Shp.money(n); }

    function parseMoney(s) {
        var n = parseFloat(String(s || '').replace(/\s/g, '').replace(',', '.'));
        return isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : 0;
    }

    function cents(n) { return Math.round(n * 100); }

    function limitText(max, left) {
        return T('ShPosRefundMax', money(max)) + ' ' + T('ShPosRefundLeft', money(left || 0));
    }

    function findOrder(id) {
        var lists = ['to_pay', 'queue', 'ready', 'preorders', 'recent'], i, j;
        for (i = 0; i < lists.length; i++) {
            for (j = 0; j < (S[lists[i]] || []).length; j++) if (S[lists[i]][j].id === id) return S[lists[i]][j];
        }
        return null;
    }

    function methodLabel(code) {
        var i;
        for (i = 0; i < (S.methods || []).length; i++) if (S.methods[i].code === code) return S.methods[i].label;
        return code;
    }

    function statusLabel(s) {
        var k = { placed: 'ShStPlaced', preparing: 'ShStPreparing', ready: 'ShStReady', delivered: 'ShStDelivered', cancelled: 'ShStCancelled' };
        return k[s] ? T(k[s]) : s;
    }

    /* Minutes since a local date-time of the competition ("YYYY-MM-DD HH:MM:SS"), by the clock of the server. */
    function parseLocal(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/.exec(s || '');
        return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]) : NaN;
    }
    function minutesSince(since) {
        var now = parseLocal(S.now) + (Date.now() - S.at), t = parseLocal(since);
        return isFinite(now) && isFinite(t) ? Math.max(0, Math.floor((now - t) / 60000)) : 0;
    }
    function minutesUntil(when) {
        var now = parseLocal(S.now) + (Date.now() - S.at), t = parseLocal(when);
        return isFinite(now) && isFinite(t) ? Math.ceil((t - now) / 60000) : 0;
    }
    function ageText(since) {
        var m = minutesSince(since);
        return m < 1 ? T('ShPosNow') : T('ShPosMinutes', m);
    }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    /* Service time asked, for people: "12:30" today, "14/06 12:30" another day. */
    function wantedText(w) {
        if (!w) return '';
        var today = String(S.now || '').slice(0, 10), m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(w);
        if (!m) return '';
        return (w.slice(0, 10) === today ? '' : m[3] + '/' + m[2] + ' ') + m[4] + ':' + m[5];
    }
    /* Quarter hours from a little after now to the end of the day (local time of the competition). */
    function timeChoices() {
        var now = parseLocal(S.now) + (Date.now() - S.at), out = [], d, m;
        if (!isFinite(now)) return out;
        d = new Date(now);
        m = Math.ceil((d.getUTCHours() * 60 + d.getUTCMinutes() + 10) / 15) * 15;
        for (m = Math.max(m, 6 * 60); m <= 23 * 60 + 45; m += 15) out.push(pad2(Math.floor(m / 60)) + ':' + pad2(m % 60));
        return out;
    }

    /* "Ready in N min" with −5 −2 +2 +5: the time announced to the customer. onChange gets the new value. */
    function waitControl(value, onChange, compact) {
        var box = h('div', { class: 'pos-wait' + (compact ? ' is-compact' : '') });
        value = Math.max(0, parseInt(value, 10) || 0);
        function paint() {
            clear(box);
            box.appendChild(h('span', { class: 'pos-wait-txt' }, [h('span', { class: 'shp-muted', text: T('ShPosWaitLabel') + ' ' }), h('b', { text: T('ShPosMinutes', value) })]));
            [-5, -2, 2, 5].forEach(function (d) {
                box.appendChild(h('button', { type: 'button', class: 'shp-btn pos-wait-btn', text: T('ShPosMinutes', (d > 0 ? '+' : '−') + Math.abs(d)),
                    'aria-label': T(d > 0 ? 'ShPosWaitMore' : 'ShPosWaitLess', Math.abs(d)), disabled: d < 0 && value <= 0 ? true : null,
                    onclick: function (ev) { ev.stopPropagation(); value = Math.max(0, Math.min(240, value + d)); paint(); onChange(value); } }));
            });
        }
        paint();
        return box;
    }

    /* Waiting time of an order, sent a moment after the last tap (several taps = one request). */
    var waitTimers = {};
    function sendWait(o, minutes) {
        o.wait = minutes;
        o.wait_set = true;
        clearTimeout(waitTimers[o.id]);
        waitTimers[o.id] = setTimeout(function () {
            post(C.order, { order: o.id, action: 'wait', minutes: minutes }, true).then(function (res) {
                if (res && res.error) note(res.msg || T('ShPosUnknown'));
                refresh();
            });
        }, 700);
    }
    function tickTimers() {
        var list = root.querySelectorAll('[data-since]'), i;
        for (i = 0; i < list.length; i++) list[i].textContent = ageText(list[i].getAttribute('data-since'));
    }

    /* Message of the server for a failed request; fatal ones (access withdrawn) lock the till. */
    function failed(res) {
        if (res && res.error && FATAL.indexOf(res.code) >= 0) { lock(res.msg || T('ShStfErrRevoked')); return true; }
        return false;
    }

    function note(text) { Shp.toast(text, null, 4000); }

    /* One write: resolves with the answer, or null after a message when the server cannot be reached. */
    function post(url, body, keep) {
        return (keep ? Shp.api(url, body) : Shp.send(url, body)).then(function (res) {
            if (failed(res)) return null;
            return res;
        }, function () { note(T('ShErrNetwork')); return null; });
    }

    function refresh() { if (poller) poller.now(); }

    function confirmBtn(label, sureLabel, cls, fire) {
        var timer = null;
        var b = h('button', { type: 'button', class: 'shp-btn ' + (cls || ''), text: label });
        b.addEventListener('click', function () {
            if (b.getAttribute('data-sure') === '1') { clearTimeout(timer); fire(b); return; }
            b.setAttribute('data-sure', '1');
            b.textContent = sureLabel;
            b.classList.add('pos-sure');
            timer = setTimeout(function () { b.removeAttribute('data-sure'); b.textContent = label; b.classList.remove('pos-sure'); }, 4000);
        });
        return b;
    }

    /* ------------------------------------------------------------------ */
    /* Locked screen                                                       */
    /* ------------------------------------------------------------------ */

    function lock(msg) {
        ui.locked = true;
        if (poller) poller.stop();
        Shp.offline(false);
        clear(root);
        root.appendChild(h('div', { class: 'shp-card pos-lock' }, [
            h('h1', { text: C.title }),
            h('div', { class: 'shp-msg shp-msg-err', role: 'alert', text: msg }),
            h('a', { class: 'shp-btn shp-btn-primary shp-btn-block', href: C.login, text: T('ShStfLoginAgain') })
        ]));
    }

    /* ------------------------------------------------------------------ */
    /* Frame: top bar, body, tabs, sheet                                   */
    /* ------------------------------------------------------------------ */

    function buildFrame() {
        clear(root);
        els.top = h('div', { class: 'shp-top pos-top' });
        els.main = h('div', { class: 'shp-main pos-main' });
        els.tabs = h('nav', { class: 'shp-tabs', 'aria-label': T('ShPosStand') });
        els.ticket = h('div', { class: 'pos-ticket shp-hidden' });
        els.sheet = h('div', { class: 'pos-sheetbox' });
        [els.top, els.main, els.ticket, els.tabs, els.sheet].forEach(function (e) { root.appendChild(e); });
    }

    function availableTabs() {
        var out = [];
        if (can('sell')) out.push('sell');
        if (can('sell') || can('prepare') || can('cash')) out.push('orders');
        if (can('stock')) out.push('stock');
        if (can('cash') || can('refund')) out.push('cash');
        return out;
    }

    function renderTop() {
        clear(els.top);
        var st = stand(), kids = [];
        if ((S.stands || []).length > 1) {
            var sel = h('select', { class: 'pos-standsel', 'aria-label': T('ShPosStand') });
            S.stands.forEach(function (s) {
                sel.appendChild(h('option', { value: s.id, text: s.name, selected: s.id === S.stand ? true : null }));
            });
            sel.addEventListener('change', function () {
                S.stand = parseInt(sel.value, 10);
                store('stand', String(S.stand));
                resetStandData();
                if (poller) poller.now();
                renderAll();
            });
            kids.push(sel);
        } else if (st) {
            kids.push(h('span', { class: 'shp-top-title', text: st.name }));
        }
        if (st) {
            var openTxt = st.open ? T('ShPosOpen') : T('ShPosClosed');
            if (can('manage')) {
                kids.push(h('button', {
                    type: 'button', class: 'pos-open ' + (st.open ? 'on' : 'off'), text: openTxt,
                    'aria-label': st.open ? T('ShPosCloseIt') : T('ShPosOpenIt'),
                    onclick: function () { setOpen(!st.open); }
                }));
            } else {
                kids.push(h('span', { class: 'pos-open ' + (st.open ? 'on' : 'off'), text: openTxt }));
            }
        }
        kids.push(h('span', { class: 'pos-who', text: (S.me && S.me.name) || '' }));
        kids.push(confirmBtn(T('ShPosQuit'), T('ShPosConfirm'), 'pos-quit', logout));
        kids.forEach(function (k) { els.top.appendChild(k); });
    }

    /* Another stand: nothing of the previous one stays on screen until the server has answered. */
    function resetStandData() {
        ui.ticket = [];
        ui.report = null;
        ui.accounts = null;
        ui.seen = null;
        ['catalog', 'to_pay', 'queue', 'ready', 'preorders', 'recent'].forEach(function (k) { S[k] = []; });
        S.hash = '';
    }

    function setOpen(open) {
        var st = stand();
        if (!st) return;
        st.open = open;
        renderTop();
        renderBody();
        post(C.stand, { stand: st.id, open: open ? 1 : 0 }).then(refresh);
    }

    function logout() {
        Shp.api(C.logout, {}).then(function () { window.location.replace(C.login); }, function () { note(T('ShErrNetwork')); });
    }

    function renderTabs() {
        clear(els.tabs);
        var tabs = availableTabs();
        if (tabs.indexOf(ui.tab) < 0) ui.tab = tabs[0] || '';
        var names = { sell: 'ShPosTabSell', orders: 'ShPosTabOrders', stock: 'ShPosTabStock', cash: 'ShPosTabCash' };
        tabs.forEach(function (t) {
            var b = h('button', { type: 'button', class: 'shp-tab' + (t === ui.tab ? ' on' : ''), text: T(names[t]),
                onclick: function () { ui.tab = t; store('tab', t); renderAll(); } });
            if (t === 'orders') {
                var n = (S.to_pay || []).length + (S.queue || []).length + (S.ready || []).length;
                if (n > 0) b.appendChild(h('span', { class: 'shp-count', text: String(n) }));
            }
            els.tabs.appendChild(b);
        });
    }

    function renderBody() {
        var scrollY = window.scrollY;
        clear(els.main);
        if (!stand()) {
            els.main.appendChild(h('div', { class: 'shp-msg shp-msg-warn', text: T('ShPosNoStand') }));
            renderTicket();
            return;
        }
        if (!ui.tab) {
            els.main.appendChild(h('div', { class: 'shp-msg shp-msg-warn', text: T('ShPosNoRight') }));
            renderTicket();
            return;
        }
        if (ui.tab === 'sell') renderSell();
        else if (ui.tab === 'orders') renderOrders();
        else if (ui.tab === 'stock') renderStock();
        else renderCash();
        renderTicket();
        tickTimers();
        window.scrollTo(0, scrollY);
    }

    function renderAll() {
        renderTop();
        renderTabs();
        renderBody();
        renderSheetLive();
    }

    /* ------------------------------------------------------------------ */
    /* Bottom sheet                                                        */
    /* ------------------------------------------------------------------ */

    function openSheet(kind, data) {
        ui.sheet = { kind: kind, data: data || {}, busy: false };
        drawSheet();
    }

    function closeSheet() {
        if (ui.sheet && ui.sheet.timer) clearTimeout(ui.sheet.timer);
        ui.sheet = null;
        clear(els.sheet);
    }

    function drawSheet() {
        clear(els.sheet);
        if (!ui.sheet) return;
        var sh = ui.sheet, body = h('div', { class: 'shp-sheet', role: 'dialog', 'aria-modal': 'true' });
        var bg = h('div', { class: 'shp-sheet-bg', onclick: function () { if (!sh.busy && !sh.sticky) closeSheet(); } });
        els.sheet.appendChild(bg);
        els.sheet.appendChild(body);
        sh.body = body;
        var draw = { variant: sheetVariant, pay: sheetPay, orderpay: sheetPay, accountpay: sheetPay, order: sheetOrder,
            refund: sheetRefund, qty: sheetQty, done: sheetDone }[sh.kind];
        if (draw) draw(body, sh);
        var first = body.querySelector('input[autofocus-pos]');
        if (first) first.focus();
    }

    /* A background update must not wipe what the volunteer is typing: only the order sheet is redrawn. */
    function renderSheetLive() {
        if (ui.sheet && ui.sheet.kind === 'order') drawSheet();
        else if (ui.sheet && ui.sheet.kind === 'done' && ui.sheet.data.order) { /* stays as it is */ }
    }

    function sheetHead(title, sh) {
        return h('div', { class: 'pos-sheethead' }, [
            h('h2', { text: title }),
            h('button', { type: 'button', class: 'shp-btn pos-x', text: T('ShClose'), disabled: sh.busy ? true : null, onclick: closeSheet })
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Sell                                                                */
    /* ------------------------------------------------------------------ */

    function ticketTotal() {
        var t = 0;
        ui.ticket.forEach(function (l) { t += cents(l.unit) * l.qty; });
        return t / 100;
    }
    function ticketCount() {
        var n = 0;
        ui.ticket.forEach(function (l) { n += l.qty; });
        return n;
    }
    function ticketQtyOf(productId) {
        var n = 0;
        ui.ticket.forEach(function (l) { if (l.product === productId) n += l.qty; });
        return n;
    }

    function priceText(p) {
        if (!p.option || !p.variants.length) return money(p.price);
        var min = p.variants[0].price, same = true;
        p.variants.forEach(function (v) { if (v.price !== min) same = false; if (v.price < min) min = v.price; });
        return same ? money(min) : T('ShPosFrom', money(min));
    }

    function addToTicket(p, v) {
        var vid = v ? v.id : 0, i, line = null, stock = v ? v.stock : p.stock;
        for (i = 0; i < ui.ticket.length; i++) if (ui.ticket[i].product === p.id && ui.ticket[i].variant === vid) line = ui.ticket[i];
        var qty = (line ? line.qty : 0) + 1;
        if (stock !== null && stock !== undefined && qty > stock) { note(T('ShPosOnlyLeft', stock)); return; }
        if (p.maxper > 0 && ticketQtyOf(p.id) + 1 > p.maxper) { note(T('ShPosMaxPer', p.maxper)); return; }
        if (line) line.qty = qty;
        else ui.ticket.push({ product: p.id, variant: vid, qty: 1, label: v ? p.name + ' — ' + v.label : p.name, unit: v ? v.price : p.price });
        Shp.unlockAudio();
        renderBody();
    }

    function renderSell() {
        var st = stand(), cats = [], products = (S.catalog || []).filter(function (p) { return p.onsite; });
        if (!st.open) els.main.appendChild(h('div', { class: 'shp-msg shp-msg-info', text: T('ShPosClosedNote') }));
        products.forEach(function (p) { if (p.category && cats.indexOf(p.category) < 0) cats.push(p.category); });
        if (cats.length > 1) {
            var bar = h('div', { class: 'pos-pills', role: 'tablist' });
            [''].concat(cats).forEach(function (c) {
                bar.appendChild(h('button', { type: 'button', class: 'pos-pill' + (ui.cat === c ? ' on' : ''), text: c === '' ? T('ShPosAll') : c,
                    onclick: function () { ui.cat = c; renderBody(); } }));
            });
            els.main.appendChild(bar);
        } else {
            ui.cat = '';
        }
        var shown = products.filter(function (p) { return ui.cat === '' || p.category === ui.cat; });
        if (!shown.length) {
            els.main.appendChild(h('p', { class: 'shp-muted', text: T('ShPosNothingToSell') }));
            return;
        }
        var grid = h('div', { class: 'shp-grid pos-grid' });
        shown.forEach(function (p) {
            var qty = ticketQtyOf(p.id), kids = [h('b', { text: p.name }), h('span', { class: 'shp-price', text: priceText(p) })];
            if (!p.available) kids.push(h('span', { class: 'shp-low', text: T('ShPosOut') }));
            else if (!p.option && p.stock !== null && p.low) kids.push(h('span', { class: 'shp-low', text: T('ShPosOnlyLeft', p.stock) }));
            if (qty > 0) kids.push(h('span', { class: 'pos-qty', text: '×' + qty }));
            grid.appendChild(h('button', { type: 'button', class: 'shp-tile' + (p.available ? '' : ' off'), 'aria-disabled': p.available ? null : 'true',
                onclick: function () {
                    if (!p.available) return;
                    if (p.option) openSheet('variant', { product: p.id }); else addToTicket(p, null);
                } }, kids));
        });
        els.main.appendChild(grid);
    }

    function sheetVariant(body, sh) {
        var p = null;
        (S.catalog || []).forEach(function (x) { if (x.id === sh.data.product) p = x; });
        if (!p) { closeSheet(); return; }
        body.appendChild(sheetHead(p.name + ' — ' + p.option, sh));
        body.appendChild(h('p', { class: 'shp-muted', text: T('ShPosChooseVariant') }));
        var list = h('div', { class: 'pos-stack' });
        p.variants.forEach(function (v) {
            var kids = [h('span', { class: 'pos-grow', text: v.label }), h('b', { text: money(v.price) })];
            if (!v.available) kids.splice(1, 0, h('span', { class: 'shp-low', text: T('ShPosOut') }));
            else if (v.stock !== null && p.alert !== null && v.stock <= p.alert) kids.splice(1, 0, h('span', { class: 'shp-low', text: T('ShPosOnlyLeft', v.stock) }));
            list.appendChild(h('button', { type: 'button', class: 'shp-btn pos-row', disabled: v.available ? null : true,
                onclick: function () { closeSheet(); addToTicket(p, v); } }, kids));
        });
        body.appendChild(list);
    }

    function renderTicket() {
        clear(els.ticket);
        var n = ticketCount();
        els.ticket.classList.toggle('shp-hidden', n === 0 || ui.tab !== 'sell' || !stand());
        els.main.classList.toggle('pos-has-ticket', n > 0 && ui.tab === 'sell');
        if (n === 0 || ui.tab !== 'sell') return;
        var bar = h('div', { class: 'pos-ticketbar' }, [
            h('button', { type: 'button', class: 'pos-ticketsum', 'aria-expanded': ui.ticketOpen ? 'true' : 'false',
                onclick: function () { ui.ticketOpen = !ui.ticketOpen; renderTicket(); } }, [
                h('span', { text: T('ShPosItems', n) }),
                h('b', { class: 'shp-total', text: money(ticketTotal()) })
            ]),
            h('button', { type: 'button', class: 'shp-btn shp-btn-primary shp-btn-big', text: T('ShPosCollect'), onclick: startSale })
        ]);
        if (ui.ticketOpen) {
            var lines = h('div', { class: 'pos-lines' });
            ui.ticket.forEach(function (l, i) {
                lines.appendChild(h('div', { class: 'pos-line' }, [
                    h('span', { class: 'pos-grow', text: l.label }),
                    h('button', { type: 'button', class: 'shp-btn pos-step', text: '−', 'aria-label': T('ShPosLess'), onclick: function () { changeQty(i, -1); } }),
                    h('b', { class: 'pos-n', text: String(l.qty) }),
                    h('button', { type: 'button', class: 'shp-btn pos-step', text: '+', 'aria-label': T('ShPosMore'), onclick: function () { changeQty(i, 1); } }),
                    h('span', { class: 'pos-amt', text: money(l.unit * l.qty) })
                ]));
            });
            lines.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-danger', text: T('ShPosClear'),
                onclick: function () { ui.ticket = []; ui.ticketOpen = false; renderBody(); } }));
            els.ticket.appendChild(lines);
        }
        els.ticket.appendChild(bar);
    }

    function changeQty(i, d) {
        var l = ui.ticket[i], p = null;
        if (!l) return;
        (S.catalog || []).forEach(function (x) { if (x.id === l.product) p = x; });
        if (d > 0 && p) {
            var stock = l.variant ? null : p.stock;
            if (l.variant) p.variants.forEach(function (v) { if (v.id === l.variant) stock = v.stock; });
            if (stock !== null && stock !== undefined && l.qty + 1 > stock) { note(T('ShPosOnlyLeft', stock)); return; }
            if (p.maxper > 0 && ticketQtyOf(p.id) + 1 > p.maxper) { note(T('ShPosMaxPer', p.maxper)); return; }
        }
        l.qty += d;
        if (l.qty < 1) ui.ticket.splice(i, 1);
        if (!ui.ticket.length) ui.ticketOpen = false;
        renderBody();
    }

    /* ---- Collecting a sale ---- */

    function startSale() {
        if (!ui.ticket.length) return;
        openSheet('pay', {
            mode: 'sale', total: ticketTotal(), idem: Shp.uuid(), customer: null, label: '', search: false, results: [], q: '',
            step: 'choose', given: '', error: '', wait: S.eta_next || 0, wanted: ''
        });
    }

    function startOrderPay(order, thenDeliver) {
        var st = stand();
        openSheet('orderpay', { mode: 'order', order: order.id, number: order.number, total: order.due, idem: Shp.uuid(),
            tabOk: order.kind === 'ARCHER' && S.tab_on && order.pay_state === 'unpaid', deliver: !!thenDeliver, step: 'choose', given: '', error: '',
            waitable: !!st && st.mode === 'prep' && !order.wanted && order.status === 'placed' && !thenDeliver,
            wait: order.wait !== null && order.wait !== undefined ? order.wait : (S.eta_next || 0) });
    }

    function startAccountPay(acc) {
        openSheet('accountpay', { mode: 'account', account: acc.account, name: acc.name, total: acc.remaining, idem: Shp.uuid(),
            step: 'choose', given: '', error: '' });
    }

    function cashSuggestions(total) {
        var out = [];
        [5, 10, 20, 50, 100, 200].forEach(function (s) {
            var v = Math.ceil(total / s - 1e-9) * s;
            if (v > total + 0.004 && out.indexOf(v) < 0) out.push(v);
        });
        return out.slice(0, 4);
    }

    function sheetPay(body, sh) {
        var d = sh.data, st = stand();
        var title = d.mode === 'order' ? T('ShPosCollectOrder', d.number) : (d.mode === 'account' ? T('ShPosAccountPay', d.name) : T('ShPosTicket'));
        body.appendChild(sheetHead(title, sh));
        body.appendChild(h('div', { class: 'pos-bigtotal' }, [h('span', { class: 'shp-muted', text: T('ShPosTotal') }), h('b', { text: money(d.total) })]));
        if (d.error) body.appendChild(h('div', { class: 'shp-msg shp-msg-err', role: 'alert', text: d.error }));
        if (sh.busy) {
            body.appendChild(h('p', { class: 'shp-muted pos-center', 'aria-live': 'polite', text: T('ShPosSending') }));
            return;
        }
        if (d.step === 'cash') { payCashStep(body, sh); return; }

        if (d.mode === 'sale') sheetCustomer(body, sh);
        if (st && st.mode === 'prep' && (d.mode === 'sale' || d.waitable)) sheetWhen(body, sh);

        var ways = h('div', { class: 'pos-ways' }), n = 0;
        if (d.mode === 'account' || can('cash')) {
            (S.methods || []).forEach(function (m) {
                n++;
                ways.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-big pos-way' + (m.code === 'cash' ? ' shp-btn-primary' : ''), text: m.label,
                    onclick: function () {
                        if (m.code === 'cash') { d.step = 'cash'; d.method = 'cash'; drawSheet(); } else submitPay(sh, { mode: 'now', method: m.code });
                    } }));
            });
        }
        var tabOk = d.mode === 'sale' ? (d.customer && S.tab_on && d.customer.tab.allow) : (d.mode === 'order' && d.tabOk);
        if (tabOk) {
            n++;
            ways.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-big pos-way', text: T('ShPosPayTab'),
                onclick: function () { submitPay(sh, { mode: 'tab', method: 'tab' }); } }));
        }
        if (d.mode === 'sale' && st && st.mode === 'prep') {
            n++;
            ways.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-big pos-way', text: T('ShPosPayPickup'),
                onclick: function () { submitPay(sh, { mode: 'pickup', method: '' }); } }));
        }
        body.appendChild(n ? ways : h('div', { class: 'shp-msg shp-msg-warn', text: T('ShPosNoPayWay') }));
    }

    function payCashStep(body, sh) {
        var d = sh.data, total = d.total;
        var out = h('div', { class: 'pos-change', 'aria-live': 'polite' });
        function paintChange() {
            var g = d.given === '' ? total : parseMoney(d.given), df = cents(g) - cents(total);
            clear(out);
            if (df >= 0) {
                out.className = 'pos-change ok';
                out.appendChild(h('span', { text: T('ShPosGiveBack') }));
                out.appendChild(h('b', { text: money(df / 100) }));
            } else {
                out.className = 'pos-change warn';
                out.appendChild(h('span', { text: T('ShPosMissing') }));
                out.appendChild(h('b', { text: money(-df / 100) }));
            }
            okBtn.disabled = df < 0;
        }
        var input = h('input', { type: 'text', inputmode: 'decimal', 'autofocus-pos': '1', 'aria-label': T('ShPosReceived'),
            placeholder: T('ShPosExact'), value: d.given, autocomplete: 'off',
            oninput: function () { d.given = input.value; paintChange(); } });
        var okBtn = h('button', { type: 'button', class: 'shp-btn shp-btn-primary shp-btn-big shp-btn-block', text: T('ShPosConfirmCash'),
            onclick: function () {
                var g = d.given === '' ? total : parseMoney(d.given);
                if (cents(g) < cents(total)) return;
                d.change = Math.round(cents(g) - cents(total)) / 100;
                submitPay(sh, { mode: 'now', method: 'cash' });
            } });
        var quick = h('div', { class: 'pos-quick' }, [h('button', { type: 'button', class: 'shp-btn', text: T('ShPosExact'),
            onclick: function () { d.given = ''; input.value = ''; paintChange(); } })]);
        cashSuggestions(total).forEach(function (v) {
            quick.appendChild(h('button', { type: 'button', class: 'shp-btn', text: money(v),
                onclick: function () { d.given = String(v); input.value = String(v); paintChange(); } }));
        });
        body.appendChild(h('label', { text: T('ShPosReceived') }));
        body.appendChild(input);
        body.appendChild(quick);
        body.appendChild(out);
        body.appendChild(okBtn);
        body.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-block pos-gap', text: T('ShPosBack'),
            onclick: function () { d.step = 'choose'; drawSheet(); } }));
        paintChange();
    }

    /* When the order should be ready: a waiting time, or (a sale at the counter) a time asked by the customer. */
    function sheetWhen(body, sh) {
        var d = sh.data, box = h('div', { class: 'pos-when' });
        if (!d.wanted) box.appendChild(waitControl(d.wait, function (v) { d.wait = v; }, false));
        if (d.mode === 'sale') {
            var sel = h('select', { class: 'pos-wantsel', 'aria-label': T('ShPosWantedAsk') });
            sel.appendChild(h('option', { value: '', text: T('ShPosWantedNone') }));
            timeChoices().forEach(function (t) { sel.appendChild(h('option', { value: t, text: T('ShPosWantedAt', t), selected: d.wanted === t ? true : null })); });
            sel.addEventListener('change', function () { d.wanted = sel.value; drawSheet(); });
            box.appendChild(h('label', { class: 'pos-wantlab' }, [h('span', { text: T('ShPosWantedAsk') }), sel]));
        }
        body.appendChild(box);
    }

    function sheetCustomer(body, sh) {
        var d = sh.data, box = h('div', { class: 'pos-customer' });
        if (d.customer) {
            box.appendChild(h('div', { class: 'pos-chip' }, [
                h('span', { class: 'pos-grow', text: T('ShPosOnAccountOf', d.customer.name + (d.customer.club ? ' (' + d.customer.club + ')' : '')) }),
                h('button', { type: 'button', class: 'shp-btn', text: T('ShPosRemove'), onclick: function () { d.customer = null; drawSheet(); } })
            ]));
            if (d.customer.tab.msg) box.appendChild(h('div', { class: 'shp-msg ' + (d.customer.tab.allow ? 'shp-msg-warn' : 'shp-msg-err'), text: d.customer.tab.msg }));
        } else {
            var label = h('input', { type: 'text', maxlength: '60', placeholder: T('ShPosCustomerPh'), value: d.label, autocomplete: 'off',
                'aria-label': T('ShPosCustomer'), oninput: function () { d.label = label.value; } });
            box.appendChild(h('label', { text: T('ShPosCustomer') }));
            box.appendChild(label);
            if (S.tab_on) {
                if (!d.search) {
                    box.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-block pos-gap', text: T('ShPosLicensee'),
                        onclick: function () { d.search = true; drawSheet(); var i = sh.body.querySelector('.pos-q'); if (i) i.focus(); } }));
                } else {
                    var results = h('div', { class: 'pos-stack pos-results' });
                    var timer = null, q = h('input', { type: 'search', class: 'pos-q', placeholder: T('ShPosSearchPh'), value: d.q, autocomplete: 'off',
                        'aria-label': T('ShPosLicensee'),
                        oninput: function () { d.q = q.value; clearTimeout(timer); timer = setTimeout(function () { searchLicensees(sh, results); }, 300); } });
                    box.appendChild(q);
                    box.appendChild(results);
                    paintResults(sh, results);
                }
            }
        }
        body.appendChild(box);
    }

    function searchLicensees(sh, results) {
        var d = sh.data, q = d.q.trim(), st = stand();
        if (q.length < 2) { d.results = []; paintResults(sh, results); return; }
        Shp.api(C.search + '?stand=' + st.id + '&q=' + encodeURIComponent(q), null).then(function (res) {
            if (failed(res)) return;
            if (d.q.trim() !== q) return;   // a newer search is under way
            d.results = res.rows || [];
            d.searched = true;
            paintResults(sh, results);
        }, function () { /* the banner already says the connection is lost */ });
    }

    function paintResults(sh, results) {
        var d = sh.data;
        clear(results);
        if (d.searched && d.q.trim().length >= 2 && !d.results.length) results.appendChild(h('p', { class: 'shp-muted', text: T('ShPosNoResult') }));
        d.results.forEach(function (r) {
            results.appendChild(h('button', { type: 'button', class: 'shp-btn pos-row', onclick: function () { d.customer = r; d.search = false; drawSheet(); } }, [
                h('span', { class: 'pos-grow', text: r.name }), h('span', { class: 'shp-muted', text: r.club })
            ]));
        });
    }

    function submitPay(sh, way) {
        var d = sh.data;
        if (sh.busy) return;
        sh.busy = true;
        d.error = '';
        drawSheet();
        var done;
        if (d.mode === 'sale') {
            var lines = ui.ticket.map(function (l) { return { product: l.product, variant: l.variant, qty: l.qty }; });
            var cust = d.customer ? { kind: 'ARCHER', licence: d.customer.licence } : { kind: 'COUNTER', label: d.label };
            var prep = !!stand() && stand().mode === 'prep';
            done = post(C.sell, { stand: S.stand, lines: lines, customer: cust, pay: way, idem: d.idem,
                wanted: prep ? (d.wanted || '') : '', wait: prep && !d.wanted ? d.wait : '' }).then(function (res) {
                if (!res) return fail(sh, '');
                if (res.error) { sh.busy = false; d.error = res.msg || T('ShPosUnknown'); d.idem = Shp.uuid(); drawSheet(); refresh(); return; }
                ui.ticket = [];
                ui.ticketOpen = false;
                finishSale(res, way, d);
            });
        } else if (d.mode === 'order') {
            done = post(C.pay, { order: d.order, method: way.method, idem: d.idem, then_deliver: d.deliver ? 1 : 0, wait: d.waitable ? d.wait : '' }).then(function (res) {
                if (!res) return fail(sh, '');
                if (res.error) { sh.busy = false; d.error = res.msg || T('ShPosUnknown'); drawSheet(); refresh(); return; }
                applyOrder(res.order);
                var shown = { number: d.number, change: way.method === 'cash' ? d.change : 0, text: T('ShPosCollected', d.number), order: res.order };
                if (res.deliver_error) shown.warn = res.deliver_error.msg;
                showDone(shown);
                refresh();
            });
        } else {
            done = post(C.accounts, { account: d.account, method: way.method, idem: d.idem, stand: S.stand }).then(function (res) {
                if (!res) return fail(sh, '');
                if (res.error) { sh.busy = false; d.error = res.msg || T('ShPosUnknown'); drawSheet(); return; }
                ui.accounts = null;
                ui.report = null;
                showDone({ number: '', change: way.method === 'cash' ? d.change : 0, text: T('ShPosAccountPaid', d.name) });
                if (ui.tab === 'cash') loadCash();
            });
        }
        return done;
    }

    /* The request could not be sent for good (two minutes without a network): nothing is known. */
    function fail(sh, msg) {
        sh.busy = false;
        sh.data.error = msg || T('ShErrNetwork');   // the key is kept: a late arrival is still done once
        drawSheet();
    }

    function finishSale(res, way, d) {
        renderBody();   // the ticket is empty now: it must not stay on screen behind the confirmation
        var text = T('ShPosSaleNumber');
        var shown = { number: res.number, order: res.order_row || null, orderId: res.order, change: way.method === 'cash' ? d.change : 0, text: text,
            undo: true, total: res.total };
        if (res.pay_error) {
            shown.warn = T('ShPosPayFailed', res.pay_error.msg);
            shown.collect = res.order_row;
        }
        showDone(shown);
        refresh();
    }

    function showDone(info) {
        openSheet('done', info);
        ui.sheet.sticky = true;
        if (!info.change && !info.warn) {
            ui.sheet.timer = setTimeout(function () { if (ui.sheet && ui.sheet.kind === 'done') closeSheet(); }, 6000);
        }
    }

    function sheetDone(body, sh) {
        var d = sh.data;
        body.appendChild(h('div', { class: 'pos-done' }, [
            h('p', { class: 'shp-muted', text: d.text }),
            d.number ? h('div', { class: 'shp-number', text: d.number }) : null,
            d.change > 0 ? h('div', { class: 'pos-change ok' }, [h('span', { text: T('ShPosChangeToGive') }), h('b', { text: money(d.change) })]) : null,
            d.warn ? h('div', { class: 'shp-msg shp-msg-warn', role: 'alert', text: d.warn }) : null
        ]));
        var row = h('div', { class: 'shp-btn-row' });
        if (d.collect) row.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-primary', text: T('ShPosCollectNow'),
            onclick: function () { closeSheet(); startOrderPay(d.collect, false); } }));
        if (d.undo && d.orderId) {
            row.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-danger', text: T('ShPosUndoSale'), onclick: function (ev) {
                var b = ev.currentTarget;
                b.disabled = true;
                post(C.order, { order: d.orderId, action: 'undo' }).then(function (res) {
                    if (!res) { b.disabled = false; return; }
                    if (res.error) { b.disabled = false; note(res.msg || T('ShPosUnknown')); return; }
                    closeSheet();
                    note(T('ShPosUndone'));
                    refresh();
                });
            } }));
        }
        row.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-primary shp-btn-big', text: T('ShPosOk'), onclick: closeSheet }));
        body.appendChild(row);
    }

    /* ------------------------------------------------------------------ */
    /* Orders                                                              */
    /* ------------------------------------------------------------------ */

    function payBadge(o) {
        if (o.status === 'cancelled') return h('span', { class: 'shp-badge shp-badge-off', text: T('ShStCancelled') });
        var map = { paid: ['shp-badge-paid', T('ShPayPaid')], tab: ['shp-badge-tab', T('ShPayTab')], partial: ['shp-badge-topay', T('ShPayPartial')],
            unpaid: ['shp-badge-topay', T('ShPosToCollect')], refunded: ['shp-badge-off', T('ShPayRefunded')] };
        var m = map[o.pay_state] || map.unpaid;
        return h('span', { class: 'shp-badge ' + m[0], text: m[1] });
    }

    function needsPay(o) {
        return (o.pay_mode === 'now' || o.pay_mode === 'pickup') && o.pay_state !== 'paid' && o.status !== 'cancelled';
    }

    /* The main button of an order: what the volunteer does next, or null when their rights stop here. */
    function nextAction(o) {
        var st = stand();
        if (!st || o.status === 'cancelled') return null;
        var handOver = can('sell') || can('prepare');
        if (o.status === 'delivered') {
            return needsPay(o) && can('cash') ? { label: T('ShPosCollect'), run: function () { startOrderPay(o, false); } } : null;
        }
        if (needsPay(o) && o.pay_mode === 'now' && o.status === 'placed') {
            return can('cash') ? { label: T('ShPosCollect'), run: function () { startOrderPay(o, false); } } : null;
        }
        if (st.mode === 'prep' && o.status === 'placed') return can('prepare') ? { label: T('ShPosStart'), run: function () { moveOrder(o, 'preparing'); } } : null;
        if (o.status === 'preparing') return can('prepare') ? { label: T('ShPosReady'), run: function () { moveOrder(o, 'ready'); } } : null;
        if (!handOver) return null;
        if (needsPay(o)) {
            return can('cash') ? { label: T('ShPosCollectHand'), run: function () { startOrderPay(o, true); } } : null;
        }
        return { label: T('ShPosHandOver'), run: function () { moveOrder(o, 'delivered'); } };
    }

    function previousStatus(o) {
        var st = stand();
        if (!st) return '';
        if (st.mode === 'direct') return o.status === 'delivered' ? 'placed' : '';
        return { delivered: 'ready', ready: 'preparing', preparing: 'placed' }[o.status] || '';
    }

    function canGoBack(o) {
        var prev = previousStatus(o);
        if (!prev) return false;
        return o.status === 'delivered' ? (can('sell') || can('prepare')) : can('prepare');
    }

    /* An order changed by this phone: put it in the list it belongs to now, without waiting for the next answer. */
    function applyOrder(o) {
        if (!o) return;
        var lists = ['to_pay', 'queue', 'ready', 'preorders', 'recent'], k;
        lists.forEach(function (n) { S[n] = (S[n] || []).filter(function (x) { return x.id !== o.id; }); });
        if (o.status === 'delivered' && needsPay(o)) k = 'to_pay';
        else if (o.status === 'delivered' || o.status === 'cancelled') k = 'recent';
        else if (o.channel === 'preorder' && !o.wanted) k = 'preorders';
        else if (o.status === 'ready') k = 'ready';
        else if (o.status === 'placed' && o.pay_mode === 'now' && o.pay_state !== 'paid') k = 'to_pay';
        else if (o.status === 'placed' && o.wanted && minutesUntil(o.wanted) > (S.lead || 15)) k = 'preorders';
        else k = 'queue';
        if (k === 'recent') { S.recent.unshift(o); S.recent = S.recent.slice(0, 12); } else {
            S[k].push(o);
            S[k].sort(function (a, b) { var x = a.wanted || a.since || '', y = b.wanted || b.since || ''; return x < y ? -1 : (x > y ? 1 : a.id - b.id); });
        }
    }

    function moveOrder(o, to, btn, quiet) {
        var was = o.status;
        if (btn) btn.disabled = true;
        post(C.order, { order: o.id, action: 'status', to: to }).then(function (res) {
            if (btn) btn.disabled = false;
            if (!res) return;
            if (res.error) {
                if (res.code === 'pay_first') { startOrderPay(o, to === 'delivered'); return; }
                note(res.code === 'changed' ? T('ShPosChanged', statusLabel(res.status || '')) : (res.msg || T('ShPosUnknown')));
                refresh();
                return;
            }
            if (res.order) applyOrder(res.order);
            if (!quiet) {
                Shp.toast(statusLabel(to) + ' — ' + o.number, function () { moveOrder(res.order || o, was, null, true); });
            }
            refresh();
            renderAll();
        });
    }

    function orderCard(o) {
        var na = nextAction(o), who = o.label || '';
        var meta = [];
        if (who) meta.push(who);
        if (o.channel === 'preorder') meta.push(T('ShPosPreorder'));
        else if (o.channel === 'online') meta.push(T('ShPosOnline'));
        var lines = o.lines.map(function (l) { return l.qty + '× ' + l.label; }).join(', ');
        var card = h('div', { class: 'pos-card' + (o.status === 'ready' ? ' is-ready' : '') + (o.status === 'cancelled' ? ' is-off' : ''), role: 'button', tabindex: '0',
            onclick: function (ev) { if (ev.target.closest && ev.target.closest('button')) return; openSheet('order', { order: o.id }); },
            onkeydown: function (ev) { if (ev.key === 'Enter') openSheet('order', { order: o.id }); } }, [
            h('div', { class: 'pos-cardtop' }, [
                h('div', { class: 'shp-number', text: o.number }),
                h('div', { class: 'pos-cardside' }, [payBadge(o), h('span', { class: 'shp-muted pos-age', 'data-since': o.since, text: ageText(o.since) })])
            ]),
            o.wanted ? h('div', { class: 'pos-wanted', text: T('ShPosWantedAt', wantedText(o.wanted)) }) : null,
            meta.length ? h('div', { class: 'pos-meta', text: meta.join(' · ') }) : null,
            h('div', { class: 'pos-lineslist', text: lines }),
            o.note ? h('div', { class: 'pos-note', text: T('ShPosNote') + ' ' + o.note }) : null
        ]);
        var wbox = orderWait(o, true);
        if (wbox) card.appendChild(wbox);
        if (na) {
            card.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-primary shp-btn-big shp-btn-block pos-main-act', text: na.label,
                onclick: function (ev) { ev.stopPropagation(); na.run(); } }));
        }
        return card;
    }

    /* Waiting time of an order to prepare: buttons for those who take or prepare orders, the time alone for the others. */
    function orderWait(o, compact) {
        var st = stand();
        if (!st || st.mode !== 'prep' || (o.status !== 'placed' && o.status !== 'preparing') || o.wait === null || o.wait === undefined) return null;
        if (o.status === 'placed' && o.wanted && minutesUntil(o.wanted) > (S.lead || 15)) return null;
        if (can('prepare') || can('sell') || can('cash')) return waitControl(o.wait, function (v) { sendWait(o, v); }, compact);
        return h('div', { class: 'pos-wait is-compact' }, [h('span', { class: 'shp-muted', text: T('ShPosWaitLabel') + ' ' }), h('b', { text: T('ShPosMinutes', o.wait) })]);
    }

    var FILTERS = [['to_pay', 'ShPosFilterToPay'], ['queue', 'ShPosFilterQueue'], ['ready', 'ShPosFilterReady'], ['preorders', 'ShPosFilterLater'], ['recent', 'ShPosFilterRecent']];

    function renderOrders() {
        els.main.appendChild(h('button', { type: 'button', class: 'pos-alertbtn' + (ui.alertOn ? ' on' : ''), 'aria-pressed': ui.alertOn ? 'true' : 'false',
            text: ui.alertOn ? T('ShPosAlertOn') : T('ShPosAlertOff'),
            onclick: function () {
                ui.alertOn = !ui.alertOn;
                store('alert', ui.alertOn ? '1' : '0');
                if (ui.alertOn) { Shp.unlockAudio(); Shp.beep(1); }
                renderBody();
            } }));
        var bar = h('div', { class: 'pos-pills', role: 'tablist' });
        FILTERS.forEach(function (f) {
            var n = (S[f[0]] || []).length;
            bar.appendChild(h('button', { type: 'button', role: 'tab', class: 'pos-pill' + (ui.filter === f[0] ? ' on' : ''),
                'aria-selected': ui.filter === f[0] ? 'true' : 'false',
                text: T(f[1]) + (f[0] === 'recent' ? '' : ' (' + n + ')'),
                onclick: function () { ui.filter = f[0]; renderBody(); } }));
        });
        els.main.appendChild(bar);
        if (ui.filter === 'preorders') {
            var s = h('input', { type: 'search', id: 'pos-psearch', placeholder: T('ShPosSearchOrders'), value: ui.psearch, autocomplete: 'off',
                'aria-label': T('ShPosSearchOrders'),
                oninput: function () { ui.psearch = s.value; paintOrderList(); } });
            els.main.appendChild(s);
        }
        els.olist = h('div', { class: 'pos-orders', 'aria-live': 'polite' });
        els.main.appendChild(els.olist);
        paintOrderList();
    }

    function paintOrderList() {
        if (!els.olist) return;
        clear(els.olist);
        var list = (S[ui.filter] || []).slice();
        if (ui.filter === 'preorders' && ui.psearch.trim() !== '') {
            var q = ui.psearch.trim().toLowerCase();
            list = list.filter(function (o) { return (o.label + ' ' + o.number).toLowerCase().indexOf(q) >= 0; });
        }
        if (!list.length) { els.olist.appendChild(h('p', { class: 'shp-muted', text: T('ShPosNoOrders') })); return; }
        list.forEach(function (o) { els.olist.appendChild(orderCard(o)); });
        tickTimers();
    }

    /* ---- Order detail ---- */

    function sheetOrder(body, sh) {
        var o = findOrder(sh.data.order);
        if (!o) { closeSheet(); return; }
        body.appendChild(sheetHead(T('ShPosOrderTitle', o.number), sh));
        body.appendChild(h('div', { class: 'pos-detailtop' }, [
            h('div', { class: 'shp-number', text: o.number }),
            h('div', { class: 'pos-cardside' }, [h('span', { class: 'shp-badge shp-badge-tab', text: statusLabel(o.status) }), payBadge(o)])
        ]));
        if (o.label) body.appendChild(h('p', { class: 'pos-meta', text: o.label }));
        if (o.wanted) body.appendChild(h('p', { class: 'pos-wanted', text: T('ShPosWantedAt', wantedText(o.wanted)) }));
        if (o.note) body.appendChild(h('p', { class: 'pos-note', text: T('ShPosNote') + ' ' + o.note }));
        var wd = orderWait(o, false);
        if (wd) body.appendChild(wd);

        var editable = o.status !== 'delivered' && o.status !== 'cancelled' && can('sell');
        var lines = h('div', { class: 'pos-stack' });
        o.lines.forEach(function (l) {
            var row = h('div', { class: 'pos-line' }, [h('span', { class: 'pos-grow', text: l.qty + '× ' + l.label }), h('b', { text: money(l.amount) })]);
            if (editable) {
                row.appendChild(confirmBtn(T('ShPosRemoveLine'), T('ShPosConfirm'), 'pos-small', function (b) { removeLine(sh, o, l, b); }));
            }
            lines.appendChild(row);
        });
        body.appendChild(lines);
        body.appendChild(h('div', { class: 'pos-sums' }, [
            h('div', {}, [h('span', { text: T('ShPosTotal') }), h('b', { text: money(o.total) })]),
            h('div', {}, [h('span', { text: T('ShPosPaidAmount') }), h('b', { text: money(o.paid) })]),
            o.pay_state === 'tab' ? null : h('div', {}, [h('span', { text: T('ShPosRest') }), h('b', { text: money(o.due) })])
        ]));
        if (sh.data.error) body.appendChild(h('div', { class: 'shp-msg shp-msg-err', role: 'alert', text: sh.data.error }));

        var acts = h('div', { class: 'pos-stack' }), na = nextAction(o);
        if (na) acts.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-primary shp-btn-big shp-btn-block', text: na.label, onclick: function () { closeSheet(); na.run(); } }));
        if (o.status !== 'cancelled' && o.due > 0.004 && o.pay_state !== 'tab' && can('cash') && !(na && na.label === T('ShPosCollect'))) {
            acts.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-block', text: T('ShPosCollect'), onclick: function () { closeSheet(); startOrderPay(o, false); } }));
        }
        if (o.paid > 0.004 && can('refund')) {
            acts.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-block', text: T('ShPosRefund'),
                onclick: function () { startRefund(o, '', null); } }));
        }
        if (o.status !== 'delivered' && o.status !== 'cancelled' && can('sell')) {
            if (o.paid > 0.004) {
                if (can('refund')) acts.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-danger shp-btn-block', text: T('ShPosRefundCancel'),
                    onclick: function () { startRefund(o, 'order', null); } }));
                else acts.appendChild(h('p', { class: 'shp-msg shp-msg-info', text: T('ShPosAskManager') }));
            } else {
                acts.appendChild(confirmBtn(T('ShPosCancelOrder'), T('ShPosConfirm'), 'shp-btn-danger shp-btn-block', function (b) { cancelOrder(sh, o, b); }));
            }
        }
        if (canGoBack(o)) {
            var prev = previousStatus(o);
            acts.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-block', text: T('ShPosBackTo', statusLabel(prev)),
                onclick: function () { closeSheet(); moveOrder(o, prev); } }));
        }
        body.appendChild(acts);
    }

    function removeLine(sh, o, l, btn) {
        btn.disabled = true;
        post(C.order, { order: o.id, action: 'cancel_line', line: l.id, qty: 1 }, true).then(function (res) {
            if (!res) { btn.disabled = false; return; }
            if (res.error) {
                btn.disabled = false;
                if (res.code === 'refund_first' && can('refund')) { startRefund(o, 'line', l); return; }
                sh.data.error = res.code === 'refund_first' ? T('ShPosAskManager') : (res.msg || T('ShPosUnknown'));
                drawSheet();
                return;
            }
            if (res.order) applyOrder(res.order);
            sh.data.error = '';
            if (res.status === 'cancelled') { closeSheet(); note(T('ShPosOrderCancelled', o.number)); } else drawSheet();
            refresh();
        });
    }

    function cancelOrder(sh, o, btn) {
        btn.disabled = true;
        post(C.order, { order: o.id, action: 'cancel' }).then(function (res) {
            if (!res) { btn.disabled = false; return; }
            if (res.error) {
                btn.disabled = false;
                sh.data.error = res.code === 'refund_first' ? T('ShPosAskManager') : (res.msg || T('ShPosUnknown'));
                drawSheet();
                return;
            }
            closeSheet();
            note(T('ShPosOrderCancelled', o.number));
            refresh();
        });
    }

    /* ---- Refund ---- */

    function startRefund(o, then, line) {
        var amount = o.paid;
        if (then === 'line' && line) amount = Math.min(o.paid, Math.max(0, Math.round((o.paid - (o.total - line.unit)) * 100) / 100));
        var lim = '';
        if (S.me.refund_max !== null && S.me.refund_max !== undefined) {
            lim = limitText(S.me.refund_max, S.me.refund_left);
        } else {
            lim = T('ShPosRefundNoLimit');
        }
        openSheet('refund', { order: o.id, number: o.number, paid: o.paid, then: then, line: line ? line.id : 0, amount: String(amount).replace('.', ','),
            method: 'cash', reason: '', idem: Shp.uuid(), limit: lim, confirm: false, error: '' });
    }

    function sheetRefund(body, sh) {
        var d = sh.data;
        body.appendChild(sheetHead(T('ShPosRefundTitle', d.number), sh));
        body.appendChild(h('p', { class: 'shp-muted', text: d.limit }));
        if (d.error) body.appendChild(h('div', { class: 'shp-msg shp-msg-err', role: 'alert', text: d.error }));
        if (sh.busy) { body.appendChild(h('p', { class: 'shp-muted pos-center', text: T('ShPosSending') })); return; }
        var amt = parseMoney(d.amount);
        if (d.confirm) {
            body.appendChild(h('div', { class: 'shp-msg shp-msg-warn', role: 'alert', text: T('ShPosRefundSure', money(amt)) }));
            body.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-danger shp-btn-big shp-btn-block', text: T('ShPosRefundYes', money(amt)),
                onclick: function () { sendRefund(sh, amt); } }));
            body.appendChild(h('button', { type: 'button', class: 'shp-btn shp-btn-block pos-gap', text: T('ShPosEdit'),
                onclick: function () { d.confirm = false; drawSheet(); } }));
            return;
        }
        var input = h('input', { type: 'text', inputmode: 'decimal', value: d.amount, autocomplete: 'off', 'aria-label': T('ShPosRefundAmount'),
            oninput: function () { d.amount = input.value; ok.disabled = !(parseMoney(d.amount) > 0 && d.reason.trim() !== ''); } });
        var reason = h('input', { type: 'text', maxlength: '120', value: d.reason, placeholder: T('ShPosReasonPh'), autocomplete: 'off', 'aria-label': T('ShPosRefundReason'),
            oninput: function () { d.reason = reason.value; ok.disabled = !(parseMoney(d.amount) > 0 && d.reason.trim() !== ''); } });
        var ok = h('button', { type: 'button', class: 'shp-btn shp-btn-primary shp-btn-big shp-btn-block', text: T('ShPosRefund'),
            disabled: !(amt > 0 && d.reason.trim() !== '') ? true : null,
            onclick: function () { d.amount = input.value; d.reason = reason.value; if (parseMoney(d.amount) > 0 && d.reason.trim() !== '') { d.confirm = true; drawSheet(); } } });
        body.appendChild(h('label', { text: T('ShPosRefundAmount') }));
        body.appendChild(input);
        body.appendChild(h('label', { text: T('ShPosRefundMethod') }));
        var ways = h('div', { class: 'pos-pills' });
        (S.methods || []).forEach(function (m) {
            ways.appendChild(h('button', { type: 'button', class: 'pos-pill' + (d.method === m.code ? ' on' : ''), text: m.label,
                onclick: function () { d.amount = input.value; d.reason = reason.value; d.method = m.code; drawSheet(); } }));
        });
        body.appendChild(ways);
        body.appendChild(h('label', { text: T('ShPosRefundReason') }));
        body.appendChild(reason);
        var chips = h('div', { class: 'pos-pills' });
        [T('ShPosReasonMistake'), T('ShPosReasonLeft'), T('ShPosReasonSoldOut')].forEach(function (r) {
            chips.appendChild(h('button', { type: 'button', class: 'pos-pill', text: r,
                onclick: function () { d.amount = input.value; d.reason = r; drawSheet(); } }));
        });
        body.appendChild(chips);
        body.appendChild(ok);
    }

    function sendRefund(sh, amt) {
        var d = sh.data;
        if (sh.busy) return;
        sh.busy = true;
        drawSheet();
        post(C.refund, { order: d.order, amount: amt, method: d.method, reason: d.reason, idem: d.idem, then: d.then, line: d.line, qty: 1 }).then(function (res) {
            if (!res) { sh.busy = false; d.error = T('ShErrNetwork'); d.confirm = false; drawSheet(); return; }
            if (res.refund_left !== undefined && S.me) S.me.refund_left = res.refund_left;
            if (res.error) { sh.busy = false; d.confirm = false; d.error = res.msg || T('ShPosUnknown'); drawSheet(); refresh(); return; }
            if (res.order) applyOrder(res.order);
            closeSheet();
            note(res.cancel_error ? res.cancel_error.msg : T('ShPosRefunded', d.number));
            refresh();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Stock                                                               */
    /* ------------------------------------------------------------------ */

    function stockLine(p, v) {
        var stock = v ? v.stock : p.stock, sw = v ? v.switch : p.switch;
        var low = stock !== null && p.alert !== null && stock <= p.alert;
        var name = v ? v.label : p.name;
        var info = stock === null ? T('ShPosUnlimited') : T('ShPosLeft', stock);
        var row = h('div', { class: 'pos-stockrow' + (low ? ' is-low' : '') + (v ? ' is-variant' : '') }, [
            h('div', { class: 'pos-grow' }, [h('b', { text: name }), h('div', { class: 'pos-stockinfo', text: info + (low ? ' — ' + T('ShPosLowStock') : '') })]),
        ]);
        var acts = h('div', { class: 'pos-stockacts' });
        acts.appendChild(h('button', { type: 'button', class: 'shp-btn pos-switch ' + (sw ? 'on' : 'off'), 'aria-pressed': sw ? 'true' : 'false',
            text: sw ? T('ShPosAvailable') : T('ShPosSoldOut'),
            onclick: function (ev) {
                var b = ev.currentTarget;
                b.disabled = true;
                if (v) v.switch = !sw; else p.switch = !sw;
                post(C.stock, { product: p.id, variant: v ? v.id : 0, action: 'switch', on: sw ? 0 : 1 }).then(function (res) {
                    if (res && res.error) note(res.msg || T('ShPosUnknown'));
                    refresh();
                    renderBody();
                });
            } }));
        if (stock !== null) {
            acts.appendChild(h('button', { type: 'button', class: 'shp-btn', text: T('ShPosRestock'), onclick: function () { openSheet('qty', { product: p.id, variant: v ? v.id : 0, name: p.name + (v ? ' — ' + v.label : ''), action: 'restock', qty: 1, idem: Shp.uuid(), error: '' }); } }));
            acts.appendChild(h('button', { type: 'button', class: 'shp-btn', text: T('ShPosLoss'), onclick: function () { openSheet('qty', { product: p.id, variant: v ? v.id : 0, name: p.name + (v ? ' — ' + v.label : ''), action: 'loss', qty: 1, idem: Shp.uuid(), error: '' }); } }));
        }
        row.appendChild(acts);
        return row;
    }

    function renderStock() {
        els.main.appendChild(h('h2', { class: 'pos-h', text: T('ShPosStockTitle') }));
        var list = h('div', { class: 'pos-stack' });
        (S.catalog || []).forEach(function (p) {
            if (p.option && p.variants.length) {
                list.appendChild(h('div', { class: 'pos-stockgroup', text: p.name + ' — ' + p.option }));
                p.variants.forEach(function (v) { list.appendChild(stockLine(p, v)); });
            } else {
                list.appendChild(stockLine(p, null));
            }
        });
        els.main.appendChild(list);
    }

    function sheetQty(body, sh) {
        var d = sh.data, restock = d.action === 'restock';
        body.appendChild(sheetHead(T(restock ? 'ShPosQtyTitleRestock' : 'ShPosQtyTitleLoss', d.name), sh));
        if (d.error) body.appendChild(h('div', { class: 'shp-msg shp-msg-err', role: 'alert', text: d.error }));
        if (sh.busy) { body.appendChild(h('p', { class: 'shp-muted pos-center', text: T('ShPosSending') })); return; }
        var input = h('input', { type: 'number', inputmode: 'numeric', min: '1', max: '9999', value: String(d.qty), 'aria-label': T('ShPosQty'),
            oninput: function () { d.qty = parseInt(input.value, 10) || 0; ok.disabled = d.qty < 1; } });
        var quick = h('div', { class: 'pos-quick' });
        [1, 5, 10, 20].forEach(function (n) {
            quick.appendChild(h('button', { type: 'button', class: 'shp-btn', text: '+' + n, onclick: function () { d.qty = (parseInt(input.value, 10) || 0) + n; input.value = String(d.qty); ok.disabled = false; } }));
        });
        var ok = h('button', { type: 'button', class: 'shp-btn shp-btn-primary shp-btn-big shp-btn-block', text: restock ? T('ShPosAddStock') : T('ShPosRemoveStock'),
            onclick: function () {
                d.qty = parseInt(input.value, 10) || 0;
                if (d.qty < 1 || sh.busy) return;
                sh.busy = true;
                drawSheet();
                post(C.stock, { product: d.product, variant: d.variant, action: d.action, qty: d.qty, idem: d.idem }).then(function (res) {
                    if (!res) { sh.busy = false; d.error = T('ShErrNetwork'); drawSheet(); return; }
                    if (res.error) { sh.busy = false; d.error = res.msg || T('ShPosUnknown'); d.idem = Shp.uuid(); drawSheet(); return; }
                    closeSheet();
                    note(T('ShPosStockSaved', res.stock));
                    refresh();
                });
            } });
        body.appendChild(h('label', { text: T('ShPosQty') }));
        body.appendChild(input);
        body.appendChild(quick);
        body.appendChild(ok);
    }

    /* ------------------------------------------------------------------ */
    /* Cash                                                                */
    /* ------------------------------------------------------------------ */

    function renderCash() {
        els.cash = h('div', { class: 'pos-cash' });
        els.main.appendChild(els.cash);
        paintCash();
        if (!ui.report || Date.now() - ui.reportAt > 20000) loadCash();
    }

    function loadCash() {
        if (!stand()) return;
        Shp.api(C.report + '?stand=' + S.stand, null).then(function (res) {
            if (failed(res)) return;
            if (res.error) return;
            ui.report = res.report;
            ui.reportAt = Date.now();
            if (ui.tab === 'cash') paintCash();
        }, function () { /* offline banner */ });
        if (can('cash')) loadAccounts();
    }

    function sumBlock(title, r) {
        var box = h('div', { class: 'shp-card pos-sumcard' }, [h('h2', { text: title })]);
        if (!r.rows.length) box.appendChild(h('p', { class: 'shp-muted', text: T('ShPosCashNone') }));
        r.rows.forEach(function (x) {
            var detail = T('ShPosCashCount', x.count) + (x.out > 0 ? ' · ' + T('ShPosCashGiven', money(x.out)) : '');
            box.appendChild(h('div', { class: 'pos-sumrow' }, [h('div', { class: 'pos-grow' }, [h('b', { text: methodLabel(x.method) }), h('div', { class: 'shp-muted', text: detail })]), h('b', { class: 'pos-sumnet', text: money(x.net) })]));
        });
        box.appendChild(h('div', { class: 'pos-sumrow pos-sumtotal' }, [h('span', { class: 'pos-grow', text: T('ShPosCashNet') }), h('b', { text: money(r.total) })]));
        return box;
    }

    function paintCash() {
        if (!els.cash) return;
        clear(els.cash);
        var r = ui.report;
        if (!r) { els.cash.appendChild(h('p', { class: 'shp-muted', text: T('ShLoading') })); return; }
        els.cash.appendChild(sumBlock(T('ShPosCashMine'), r.mine));
        var lim = r.refund_max === null ? T('ShPosRefundNoLimit') : limitText(r.refund_max, r.refund_left);
        els.cash.appendChild(h('div', { class: 'shp-card' }, [h('h2', { text: T('ShPosCashRefunds') }), h('p', { class: 'shp-muted', text: lim })]));
        if (r.stand) els.cash.appendChild(sumBlock(T('ShPosCashStand'), r.stand));
        if (can('cash')) {
            var box = h('div', { class: 'shp-card' }, [h('h2', { text: T('ShPosCashAccounts') })]);
            var s = h('input', { type: 'search', value: ui.accountQ, placeholder: T('ShPosSearchAccount'), autocomplete: 'off', 'aria-label': T('ShPosSearchAccount') });
            var timer = null;
            s.addEventListener('input', function () { ui.accountQ = s.value; clearTimeout(timer); timer = setTimeout(loadAccounts, 300); });
            box.appendChild(s);
            els.accounts = h('div', { class: 'pos-stack pos-gap' });
            box.appendChild(els.accounts);
            els.cash.appendChild(box);
            paintAccounts();
        }
    }

    function loadAccounts() {
        Shp.api(C.accounts + '?stand=' + S.stand + '&q=' + encodeURIComponent(ui.accountQ.trim()), null).then(function (res) {
            if (failed(res) || res.error) return;
            ui.accounts = res.rows || [];
            paintAccounts();
        }, function () { /* offline banner */ });
    }

    function paintAccounts() {
        if (!els.accounts) return;
        clear(els.accounts);
        if (ui.accounts === null) { els.accounts.appendChild(h('p', { class: 'shp-muted', text: T('ShLoading') })); return; }
        if (!ui.accounts.length) { els.accounts.appendChild(h('p', { class: 'shp-muted', text: T('ShPosNoAccount') })); return; }
        ui.accounts.forEach(function (a) {
            els.accounts.appendChild(h('div', { class: 'pos-account' }, [
                h('div', { class: 'pos-grow' }, [h('b', { text: a.name }), h('div', { class: 'shp-muted', text: (a.club ? a.club + ' · ' : '') + (a.whole ? T('ShPosAccountWhole') : '') }),
                    h('div', { class: 'pos-sumnet', text: T('ShPosAccountLeft', money(a.remaining)) })]),
                h('button', { type: 'button', class: 'shp-btn shp-btn-primary', text: T('ShPosAccountBtn'), onclick: function () { startAccountPay(a); } })
            ]));
        });
    }

    /* ------------------------------------------------------------------ */
    /* State and polling                                                   */
    /* ------------------------------------------------------------------ */

    /* New orders from phones since the last answer: sound and vibration when the volunteer asked for them. */
    function watchNewOrders(st) {
        var ids = {}, fresh = 0;
        (st.to_pay || []).concat(st.queue || []).forEach(function (o) {
            if (o.channel === 'online') { ids[o.id] = 1; if (ui.seen && !ui.seen[o.id]) fresh++; }
        });
        ui.seen = ids;
        if (fresh > 0 && ui.alertOn) {
            Shp.beep(2);
            Shp.vibrate();
            note(T('ShPosNewOrder', fresh));
        }
    }

    function applyState(st) {
        var prevStand = S.stand;
        watchNewOrders(st);
        S = st;
        S.at = Date.now();
        if (prevStand !== S.stand) { ui.ticket = []; ui.seen = null; }
        if (S.stand) store('stand', String(S.stand));
        renderAll();
    }

    function poll() {
        return Shp.api(C.sync + '?stand=' + (S.stand || 0) + '&since=' + encodeURIComponent(S.hash || ''), null).then(function (res) {
            if (failed(res) || !res) return;
            if (res.error) return;
            if (res.same) { S.now = res.now; S.at = Date.now(); return; }
            applyState(res.state);
        });
    }

    function start() {
        if (C.key && window.history && window.history.replaceState && !/[?&]k=/.test(window.location.search)) {
            try { window.history.replaceState(null, '', window.location.pathname + '?k=' + encodeURIComponent(C.key)); } catch (e) { /* not allowed */ }
        }
        ui.alertOn = store('alert') === '1';
        var saved = parseInt(store('stand') || '0', 10), i, switched = false;
        if (saved !== S.stand) {
            for (i = 0; i < (S.stands || []).length; i++) if (S.stands[i].id === saved) { S.stand = saved; resetStandData(); switched = true; }
        }
        ui.tab = store('tab') || '';
        buildFrame();
        renderAll();
        if (!switched) {   // what is already there is not new; after a switch the first answer only records
            ui.seen = {};
            (S.to_pay || []).concat(S.queue || []).forEach(function (o) { if (o.channel === 'online') ui.seen[o.id] = 1; });
        }
        document.addEventListener('click', function () { Shp.unlockAudio(); }, { once: true });
        Shp.flush(function () { refresh(); });
        poller = Shp.poll(poll, 4000, 15000);
        setInterval(tickTimers, 15000);
    }

    start();
})();
