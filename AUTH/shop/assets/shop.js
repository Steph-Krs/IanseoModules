/* Customer pages of the points of sale: the shop (catalogue, basket, order sheet) and the
   tracking of the orders. Used by public/index.php (both) and public/order.php (tracking only).

   Everything the page says comes from the server (#shp-texts); the data are in #shp-cfg (what
   this page is, the addresses of the endpoints) and #shp-data (stands, products, orders).
   Nothing is trusted here: the server checks the basket again at the order.

   Orders are followed with ONE request per phone (api/status.php), every 10 s while the page is
   visible and 30 s hidden, and the page stops asking when nothing is left to follow. */
(function () {
    'use strict';
    var Shp = window.Shp;
    if (!Shp) return;

    function readJson(id) {
        var e = document.getElementById(id);
        if (!e) return {};
        try { return JSON.parse(e.textContent) || {}; } catch (x) { return {}; }
    }
    var cfg = Shp.cfg;
    var data = readJson('shp-data');
    var key = cfg.key;
    var urls = cfg.urls || {};
    var onShop = cfg.page !== 'orders';

    var stands = data.stands || [];
    var products = data.products || [];
    var orders = {};
    var cart = {};
    var current = 0;
    var view = 'shop';
    var STORE = 'shp-cart-' + key;

    function $(id) { return document.getElementById(id); }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }
    function clear(e) { while (e.firstChild) e.removeChild(e.firstChild); return e; }
    function t(k, a) { return Shp.t(k, a); }
    /** Text with several values: {$a[name]} replaced by obj.name. */
    function fmt(k, obj) {
        var s = String(Shp.t(k));
        Object.keys(obj || {}).forEach(function (n) { s = s.split('{$a[' + n + ']}').join(String(obj[n])); });
        return s;
    }
    function button(cls, text, onClick) {
        var b = el('button', cls, text);
        b.type = 'button';
        if (onClick) b.addEventListener('click', onClick);
        return b;
    }

    /* ---- Days and times of the competition (its local time, read as UTC so that nothing shifts) ---- */
    var when = cfg.when || { days: [], now: '', lead: 15 };
    var loadedAt = Date.now();
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function localNow() {
        var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(when.now || '');
        return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) + (Date.now() - loadedAt) : NaN;
    }
    /** A day for people, in the language of the page ("sam. 14/06"). */
    function dayLabel(ymd) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd || '');
        if (!m) return ymd;
        try {
            return new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])).toLocaleDateString(document.documentElement.lang || undefined,
                { weekday: 'short', day: 'numeric', month: 'numeric', timeZone: 'UTC' });
        } catch (e) { return m[3] + '/' + m[2]; }
    }
    /** A service time for people: "12:30", or "sam. 14/06 12:30" when it is not today. */
    function wantedLabel(w) {
        var m = /^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2})/.exec(w || '');
        if (!m) return '';
        var today = String(when.now || '').slice(0, 10);
        return (m[1] === today ? '' : dayLabel(m[1]) + ' ') + m[2] + ':' + m[3];
    }
    /** Quarter hours a day offers: from 06:00, or a little after now today, to 23:45. */
    function timesOf(ymd) {
        var out = [], start = 6 * 60, now = localNow(), today = String(when.now || '').slice(0, 10), m;
        if (ymd === today && isFinite(now)) {
            var d = new Date(now);
            start = Math.max(start, Math.ceil((d.getUTCHours() * 60 + d.getUTCMinutes() + 10) / 15) * 15);
        }
        for (m = start; m <= 23 * 60 + 45; m += 15) out.push(pad2(Math.floor(m / 60)) + ':' + pad2(m % 60));
        return out;
    }

    /* ================================================================== */
    /* Catalogue and basket                                                */
    /* ================================================================== */

    var byProduct = {};
    products.forEach(function (p) { byProduct[p.id] = p; });
    function standById(id) {
        for (var i = 0; i < stands.length; i++) if (stands[i].id === id) return stands[i];
        return null;
    }
    function variantOf(p, vid) {
        for (var i = 0; i < p.variants.length; i++) if (p.variants[i].id === vid) return p.variants[i];
        return null;
    }
    function canOrder(st) { return cfg.mode !== 'closed' && !!st && st.open; }

    function cartLoad() {
        var c = {};
        try { c = JSON.parse(window.localStorage.getItem(STORE) || '{}') || {}; } catch (e) { c = {}; }
        var out = {};
        Object.keys(c).forEach(function (sid) {
            if (!standById(parseInt(sid, 10))) return;
            Object.keys(c[sid] || {}).forEach(function (k) {
                var m = /^(\d+):(\d+)$/.exec(k), q = parseInt(c[sid][k], 10), p = m ? byProduct[parseInt(m[1], 10)] : null;
                if (!p || String(p.stand) !== String(sid) || !(q > 0)) return;
                var v = parseInt(m[2], 10);
                if (p.option !== '' ? !variantOf(p, v) : v !== 0) return;
                (out[sid] = out[sid] || {})[k] = Math.min(q, 99);
            });
        });
        return out;
    }
    function cartSave() {
        try { window.localStorage.setItem(STORE, JSON.stringify(cart)); } catch (e) { /* private mode */ }
    }
    function qtyOf(sid, pid, vid) { return (cart[sid] && cart[sid][pid + ':' + vid]) || 0; }
    function productQty(sid, pid) {
        var n = 0, c = cart[sid] || {};
        Object.keys(c).forEach(function (k) { if (k.indexOf(pid + ':') === 0) n += c[k]; });
        return n;
    }
    function setQty(sid, pid, vid, n) {
        var k = pid + ':' + vid;
        cart[sid] = cart[sid] || {};
        if (n > 0) cart[sid][k] = n; else delete cart[sid][k];
        if (!Object.keys(cart[sid]).length) delete cart[sid];
        cartSave();
    }
    function canAdd(p, v) {
        var avail = v ? v.available : p.available;
        var cap = v ? v.cap : p.cap;
        return avail && qtyOf(p.stand, p.id, v ? v.id : 0) < cap && productQty(p.stand, p.id) < p.maxper;
    }
    /** Lines of the basket of a stand, in a stable order: [{p, v, qty, price, name}]. */
    function cartLines(sid) {
        var out = [], c = cart[sid] || {};
        Object.keys(c).sort().forEach(function (k) {
            var m = /^(\d+):(\d+)$/.exec(k), p = byProduct[parseInt(m[1], 10)];
            if (!p) return;
            var v = variantOf(p, parseInt(m[2], 10));
            out.push({ p: p, v: v, qty: c[k], price: v ? v.price : p.price, name: p.name + (v ? ' — ' + v.label : '') });
        });
        return out;
    }
    function cartTotal(sid) {
        var n = 0, total = 0;
        cartLines(sid).forEach(function (l) { n += l.qty; total += l.qty * l.price; });
        return { count: n, total: Math.round(total * 100) / 100 };
    }

    /* ---- Shop view ---- */

    function renderTabs(root) {
        if (stands.length < 2) return;
        var bar = el('div', 'cus-stands');
        stands.forEach(function (s) {
            var n = cartTotal(s.id).count;
            var b = button('cus-stand' + (s.id === current ? ' on' : '') + (s.open ? '' : ' off'), s.name, function () {
                current = s.id;
                renderShop();
            });
            if (n > 0) b.appendChild(el('span', 'cus-count', String(n)));
            bar.appendChild(b);
        });
        root.appendChild(bar);
    }

    function qtyControl(p, v) {
        var st = standById(p.stand), sid = p.stand, vid = v ? v.id : 0;
        var wrap = el('div', 'cus-qty');
        wrap.setAttribute('data-p', p.id);
        wrap.setAttribute('data-v', vid);
        var n = qtyOf(sid, p.id, vid);
        var minus = button('cus-q cus-q-minus', '−', function () { change(p, v, -1); });
        minus.title = t('ShCusLess');
        minus.setAttribute('aria-label', t('ShCusLess') + ' — ' + p.name + (v ? ' ' + v.label : ''));
        minus.disabled = n < 1;
        var num = el('span', 'cus-q-n', String(n));
        num.setAttribute('aria-live', 'polite');
        var plus = button('cus-q cus-q-plus', '+', function () { change(p, v, 1); });
        plus.title = t('ShCusAdd');
        plus.setAttribute('aria-label', t('ShCusAdd') + ' — ' + p.name + (v ? ' ' + v.label : ''));
        plus.disabled = !canOrder(st) || !canAdd(p, v);
        wrap.appendChild(minus);
        wrap.appendChild(num);
        wrap.appendChild(plus);
        return wrap;
    }

    function itemRow(p, v) {
        var avail = v ? v.available : p.available;
        var row = el('div', 'cus-item' + (avail ? '' : ' off'));
        var txt = el('div', 'cus-item-txt');
        if (v) {
            txt.appendChild(el('b', 'cus-item-name', v.label));
        } else {
            txt.appendChild(el('b', 'cus-item-name', p.name));
            if (p.description) txt.appendChild(el('span', 'cus-desc', p.description));
        }
        var sub = el('span', 'cus-price', Shp.money(v ? v.price : p.price));
        txt.appendChild(sub);
        if (!avail) txt.appendChild(el('span', 'shp-badge shp-badge-off', t('ShCusSoldOut')));
        else if (p.low) txt.appendChild(el('span', 'cus-low', t('ShCusLow')));
        row.appendChild(txt);
        if (avail && canOrder(standById(p.stand))) row.appendChild(qtyControl(p, v));
        return row;
    }

    function renderProducts(root, st) {
        var list = products.filter(function (p) { return p.stand === st.id; });
        if (!list.length) { root.appendChild(el('p', 'shp-muted', t('ShCusEmptyStand'))); return; }
        var lastCat = null;
        list.forEach(function (p) {
            if (p.category !== lastCat) {
                lastCat = p.category;
                if (p.category !== '') root.appendChild(el('h2', 'cus-cat', p.category));
            }
            var card = el('div', 'cus-prod');
            if (p.option !== '') {
                var head = el('div', 'cus-prod-head');
                head.appendChild(el('b', 'cus-item-name', p.name));
                if (p.description) head.appendChild(el('span', 'cus-desc', p.description));
                head.appendChild(el('span', 'cus-opt', p.option));
                card.appendChild(head);
                p.variants.forEach(function (v) { card.appendChild(itemRow(p, v)); });
            } else {
                card.appendChild(itemRow(p, null));
            }
            if (p.maxper < 99) card.appendChild(el('p', 'shp-muted cus-max', t('ShCusMaxPer', p.maxper)));
            root.appendChild(card);
        });
    }

    function renderShop() {
        if (!onShop) return;
        var root = clear($('cus-shop'));
        if (!stands.length) {
            root.appendChild(el('p', 'shp-muted', t('ShCusEmptyStand')));
            renderBar();
            return;
        }
        if (!standById(current)) current = stands[0].id;
        var st = standById(current);
        renderTabs(root);
        if (cfg.mode !== 'closed' && !st.open) root.appendChild(el('div', 'shp-msg shp-msg-warn', t('ShCusClosedNow')));
        renderProducts(root, st);
        renderBar();
    }

    function change(p, v, delta) {
        var sid = p.stand, vid = v ? v.id : 0, n = qtyOf(sid, p.id, vid);
        if (delta > 0 && !canAdd(p, v)) return;
        setQty(sid, p.id, vid, Math.max(0, n + delta));
        // Only the product's own controls change (focus stays where the thumb is).
        var wraps = document.querySelectorAll('#cus-shop .cus-qty[data-p="' + p.id + '"]');
        Array.prototype.forEach.call(wraps, function (w) {
            var wv = parseInt(w.getAttribute('data-v'), 10);
            var variant = wv ? variantOf(p, wv) : null;
            var fresh = qtyControl(p, variant);
            w.parentNode.replaceChild(fresh, w);
            if (wv === vid) {
                var f = fresh.querySelector(delta > 0 ? '.cus-q-plus' : '.cus-q-minus');
                if (f && !f.disabled) f.focus();
                else { var o = fresh.querySelector(delta > 0 ? '.cus-q-minus' : '.cus-q-plus'); if (o && !o.disabled) o.focus(); }
            }
        });
        renderBar();
        var tabs = document.querySelectorAll('#cus-shop .cus-stand');
        if (tabs.length) {
            Array.prototype.forEach.call(tabs, function (b, i) {
                var s = stands[i], c = b.querySelector('.cus-count'), k = cartTotal(s.id).count;
                if (c && !k) b.removeChild(c);
                else if (c) c.textContent = String(k);
                else if (k) b.appendChild(el('span', 'cus-count', String(k)));
            });
        }
    }

    function renderBar() {
        var bar = $('cus-bar');
        if (!bar) return;
        var st = standById(current), s = cartTotal(current);
        clear(bar);
        if (view !== 'shop' || !st || !canOrder(st) || s.count < 1) { bar.classList.add('shp-hidden'); return; }
        bar.classList.remove('shp-hidden');
        var txt = el('span', 'cus-bar-txt');
        txt.appendChild(el('b', null, t('ShCusBasket')));
        txt.appendChild(document.createTextNode(' · ' + (s.count === 1 ? t('ShCusItemOne') : t('ShCusItemMany', s.count)) + ' · '));
        txt.appendChild(el('b', null, Shp.money(s.total)));
        bar.appendChild(txt);
        bar.appendChild(button('shp-btn shp-btn-primary cus-bar-btn', t('ShCusOrderBtn'), openSheet));
    }

    /* ================================================================== */
    /* Order sheet                                                         */
    /* ================================================================== */

    var sheet = null;

    function closeSheet() {
        var layer = $('cus-layer');
        if (layer) clear(layer);
        document.removeEventListener('keydown', sheetKey);
        sheet = null;
    }
    function sheetKey(e) { if (e.key === 'Escape' && sheet && !sheet.busy) closeSheet(); }

    function openSheet() {
        var sid = current, st = standById(sid);
        if (!st || !canOrder(st)) return;
        Shp.unlockAudio();
        var lines = cartLines(sid);
        if (!lines.length) return;
        sheet = { sid: sid, st: st, lines: lines, check: [], errors: {}, busy: false, idem: '', pay: st.pay[0], global: '',
            at: false, day: (when.days || [])[0] || '', time: '' };
        var layer = clear($('cus-layer'));
        var bg = el('div', 'shp-sheet-bg');
        bg.addEventListener('click', function () { if (sheet && !sheet.busy) closeSheet(); });
        var box = el('div', 'shp-sheet cus-sheet');
        box.id = 'cus-sheet';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.appendChild(el('p', 'shp-muted', t('ShLoading')));
        layer.appendChild(bg);
        layer.appendChild(box);
        document.addEventListener('keydown', sheetKey);
        var body = { k: key, stand: sid, lines: lines.map(function (l) { return { product: l.p.id, variant: l.v ? l.v.id : 0, qty: l.qty }; }) };
        Shp.api(urls.cart, body).then(function (res) {
            if (!sheet || sheet.sid !== sid) return;
            if (res && !res.error && res.lines) {
                sheet.check = res.lines;
                var changed = false;
                res.lines.forEach(function (r, i) {
                    if (!sheet.lines[i]) return;
                    if (!r.ok && r.msg) { sheet.errors[i] = { code: r.code, msg: r.msg, left: r.left }; changed = true; }
                    if (r.price && r.price !== sheet.lines[i].price) { sheet.lines[i].price = r.price; changed = true; }
                });
                if (changed) sheet.global = t('ShCusChanged');
                if (!res.open) sheet.global = t('ShCusClosedNow');
            } else if (res && res.msg) {
                sheet.global = res.msg;
            }
            renderSheet();
        }, function () {
            if (sheet) { sheet.global = t('ShErrNetwork'); renderSheet(); }
        });
    }

    function sheetTotal() {
        var n = 0;
        sheet.lines.forEach(function (l) { n += l.qty * l.price; });
        return Math.round(n * 100) / 100;
    }

    function payLabel(mode) {
        return t(mode === 'tab' ? 'ShModeTab' : (mode === 'pickup' ? 'ShModePickup' : 'ShModeNow'));
    }
    function payHint(mode) {
        return t(mode === 'tab' ? 'ShCusPayTabHint' : (mode === 'pickup' ? 'ShCusPayPickupHint' : 'ShCusPayNowHint'));
    }

    /** Can the order be sent, and if not what is missing: '' | 'login' | 'pseudo' | 'tab' | 'lines'. */
    function sheetBlock() {
        if (!sheet.lines.length) return 'lines';
        var me = cfg.customer;
        if (cfg.mode === 'preorder' && (!me || me.kind !== 'ARCHER')) return 'login';
        if (!me && !cfg.guests) return 'login';
        if (sheet.pay === 'tab' && !(cfg.tab && cfg.tab.allowed)) return 'tab';
        var inp = $('cus-pseudo');
        if (!me && (!inp || inp.value.replace(/\s+/g, ' ').trim().length < 2)) return 'pseudo';
        if (sheet.at && (!sheet.day || !sheet.time)) return 'when';
        return '';
    }

    /** Is a service time offered for this stand: a stand that prepares, or any pre-order. */
    function whenOffered() {
        return (cfg.mode === 'preorder' || sheet.st.mode === 'prep') && (when.days || []).length > 0;
    }

    function renderWhen(box) {
        box.appendChild(el('h2', null, t('ShCusWhen')));
        var grp = el('div', 'cus-pays');
        grp.setAttribute('role', 'radiogroup');
        [false, true].forEach(function (at) {
            var lb = el('label', 'cus-pay' + (sheet.at === at ? ' on' : ''));
            var r = el('input');
            r.type = 'radio'; r.name = 'cus-when'; r.checked = sheet.at === at;
            r.addEventListener('change', function () { sheet.at = at; renderSheet(); });
            lb.appendChild(r);
            var tx = el('span', 'cus-pay-txt');
            tx.appendChild(el('b', null, at ? t('ShCusWhenAt') : t(cfg.mode === 'preorder' ? 'ShCusWhenNoTime' : 'ShCusWhenAsap')));
            lb.appendChild(tx);
            grp.appendChild(lb);
        });
        box.appendChild(grp);
        if (!sheet.at) return;
        var row = el('div', 'cus-when-row');
        if (when.days.length > 1) {
            var dl = el('label', 'cus-when-f');
            dl.appendChild(el('span', null, t('ShCusWhenDay')));
            var ds = el('select');
            ds.id = 'cus-when-day';
            when.days.forEach(function (d) {
                var o = el('option', null, dayLabel(d));
                o.value = d;
                if (d === sheet.day) o.selected = true;
                ds.appendChild(o);
            });
            ds.addEventListener('change', function () { sheet.day = ds.value; sheet.time = ''; renderSheet(); });
            dl.appendChild(ds);
            row.appendChild(dl);
        }
        var tl = el('label', 'cus-when-f');
        tl.appendChild(el('span', null, t('ShCusWhenTime')));
        var ts = el('select');
        ts.id = 'cus-when-time';
        var none = el('option', null, '—');
        none.value = '';
        ts.appendChild(none);
        timesOf(sheet.day).forEach(function (h) {
            var o = el('option', null, h);
            o.value = h;
            if (h === sheet.time) o.selected = true;
            ts.appendChild(o);
        });
        ts.addEventListener('change', function () { sheet.time = ts.value; refreshSubmit(); });
        tl.appendChild(ts);
        row.appendChild(tl);
        box.appendChild(row);
    }

    function renderSheet() {
        var box = $('cus-sheet');
        if (!box || !sheet) return;
        var keepPseudo = $('cus-pseudo') ? $('cus-pseudo').value : null;
        var keepNote = $('cus-note') ? $('cus-note').value : '';
        clear(box);
        var me = cfg.customer;
        box.appendChild(el('h1', null, t('ShCusSheetTitle', sheet.st.name)));
        var live = el('div', 'cus-sheet-msg');
        live.id = 'cus-sheet-msg';
        live.setAttribute('aria-live', 'polite');
        if (sheet.global) live.appendChild(el('div', 'shp-msg shp-msg-warn', sheet.global));
        box.appendChild(live);

        var ul = el('ul', 'cus-lines');
        sheet.lines.forEach(function (l, i) {
            var li = el('li', 'cus-line' + (sheet.errors[i] ? ' bad' : ''));
            var row = el('div', 'cus-line-row');
            row.appendChild(el('span', 'cus-line-name', l.qty + ' × ' + l.name));
            row.appendChild(el('span', 'cus-line-amt', Shp.money(l.qty * l.price)));
            li.appendChild(row);
            var er = sheet.errors[i];
            if (er) {
                var m = el('div', 'cus-line-err');
                m.setAttribute('role', 'alert');
                m.appendChild(el('span', null, er.msg));
                var left = er.code === 'stock' ? parseInt(er.left, 10) || 0 : 0;
                m.appendChild(button('shp-btn cus-fix', left > 0 ? t('ShCusTakeLeft', left) : t('ShCusRemove'), function () { fixLine(i, left); }));
                li.appendChild(m);
            }
            ul.appendChild(li);
        });
        box.appendChild(ul);
        var tot = el('p', 'cus-total');
        tot.appendChild(el('span', null, t('ShCusTotal')));
        tot.appendChild(el('b', 'shp-total', Shp.money(sheetTotal())));
        box.appendChild(tot);
        if (cfg.mode === 'preorder') box.appendChild(el('p', 'shp-muted', t('ShCusPreorderRecap')));
        if (whenOffered()) renderWhen(box);

        /* Who */
        box.appendChild(el('h2', null, t('ShCusWho')));
        if (me && me.kind === 'ARCHER') {
            box.appendChild(el('p', null, t('ShCusOrderingAs', me.label)));
        } else {
            if (cfg.mode === 'preorder' || !cfg.guests) {
                box.appendChild(el('div', 'shp-msg shp-msg-info', t('ShCusLoginNeeded')));
            } else {
                var lab = el('label', null, t('ShCusPseudo'));
                lab.setAttribute('for', 'cus-pseudo');
                var inp = el('input');
                inp.type = 'text'; inp.id = 'cus-pseudo'; inp.maxLength = 30; inp.autocomplete = 'nickname';
                inp.placeholder = t('ShCusPseudoPh');
                inp.value = keepPseudo !== null ? keepPseudo : (me ? me.label : '');
                inp.addEventListener('input', refreshSubmit);
                box.appendChild(lab);
                box.appendChild(inp);
            }
            var a = el('a', 'shp-btn cus-login', t('ShCusLogin'));
            a.href = urls.login;
            box.appendChild(a);
        }

        /* Payment */
        box.appendChild(el('h2', null, t('ShCusPay')));
        var grp = el('div', 'cus-pays');
        grp.setAttribute('role', 'radiogroup');
        sheet.st.pay.forEach(function (mode) {
            var off = mode === 'tab' && !(cfg.tab && cfg.tab.allowed);
            var lb = el('label', 'cus-pay' + (sheet.pay === mode ? ' on' : '') + (off ? ' off' : ''));
            var r = el('input');
            r.type = 'radio'; r.name = 'cus-pay'; r.value = mode; r.checked = sheet.pay === mode;
            r.addEventListener('change', function () { sheet.pay = mode; renderSheet(); });
            lb.appendChild(r);
            var tx = el('span', 'cus-pay-txt');
            tx.appendChild(el('b', null, payLabel(mode)));
            tx.appendChild(el('span', 'shp-muted', off && cfg.tab && cfg.tab.msg ? cfg.tab.msg : payHint(mode)));
            lb.appendChild(tx);
            grp.appendChild(lb);
        });
        box.appendChild(grp);

        /* Note */
        var nl = el('label', null, t('ShCusNote'));
        nl.setAttribute('for', 'cus-note');
        var nt = el('textarea');
        nt.id = 'cus-note'; nt.rows = 2; nt.maxLength = 160; nt.placeholder = t('ShCusNotePh');
        nt.value = keepNote;
        box.appendChild(nl);
        box.appendChild(nt);

        /* Buttons */
        var foot = el('div', 'cus-sheet-foot');
        var go = button('shp-btn shp-btn-primary shp-btn-big shp-btn-block', t('ShCusOrderBtn'), submitOrder);
        go.id = 'cus-go';
        foot.appendChild(go);
        foot.appendChild(button('shp-btn shp-btn-block', t('ShClose'), closeSheet));
        box.appendChild(foot);
        refreshSubmit();
    }

    function refreshSubmit() {
        var go = $('cus-go');
        if (!go || !sheet) return;
        go.disabled = sheet.busy || sheetBlock() !== '' || Object.keys(sheet.errors).length > 0;
        if (sheet.busy) go.textContent = t('ShCusSending');
    }

    function fixLine(i, left) {
        var l = sheet.lines[i];
        if (!l) return;
        setQty(sheet.sid, l.p.id, l.v ? l.v.id : 0, left > 0 ? left : 0);
        if (left > 0) l.qty = left; else sheet.lines.splice(i, 1);
        // Errors are indexed by line: rebuild without the fixed one.
        var errs = {};
        Object.keys(sheet.errors).forEach(function (k) {
            var n = parseInt(k, 10);
            if (n < i) errs[n] = sheet.errors[n];
            else if (n > i && !(left > 0)) errs[n - 1] = sheet.errors[n];
            else if (n > i) errs[n] = sheet.errors[n];
        });
        sheet.errors = errs;
        sheet.idem = '';
        if (!sheet.lines.length) { closeSheet(); renderShop(); return; }
        renderSheet();
        renderShop();
    }

    function sheetMessage(text, type) {
        var live = $('cus-sheet-msg');
        if (!live) return;
        clear(live);
        if (text) live.appendChild(el('div', 'shp-msg shp-msg-' + (type || 'err'), text));
    }

    function submitOrder() {
        if (!sheet || sheet.busy || sheetBlock() !== '') return;
        var pseudo = $('cus-pseudo') ? $('cus-pseudo').value : '';
        var note = $('cus-note') ? $('cus-note').value : '';
        Shp.unlockAudio();
        if (!sheet.idem) sheet.idem = Shp.uuid();
        sheet.busy = true;
        sheetMessage('');
        refreshSubmit();
        var sid = sheet.sid;
        var body = {
            k: key, stand: sid, pay_mode: sheet.pay, note: note, pseudo: pseudo, idem: sheet.idem,
            wanted: sheet.at && whenOffered() ? sheet.day + ' ' + sheet.time : '',
            lines: sheet.lines.map(function (l) { return { product: l.p.id, variant: l.v ? l.v.id : 0, qty: l.qty }; })
        };
        Shp.send(urls.order, body).then(function (res) {
            if (!sheet) return;
            sheet.busy = false;
            if (res && !res.error) { orderDone(sid, res); return; }
            orderRefused(res || {});
        }, function () {
            if (!sheet) return;
            sheet.busy = false;
            sheetMessage(t('ShErrNetwork'));
            refreshSubmit();
        });
    }

    function orderDone(sid, res) {
        if (!cfg.customer && res.label) cfg.customer = { kind: 'GUEST', label: res.label };
        delete cart[sid];
        cartSave();
        closeSheet();
        Shp.toast(t('ShCusPlaced', res.number));
        renderShop();
        fetchStatus().then(function () { showView('orders'); highlight(res.order); }, function () { showView('orders'); });
    }

    function orderRefused(res) {
        var err = res.msg || t('ShErrInternal');
        var i = typeof res.line === 'number' ? res.line : -1;
        if (i >= 0 && sheet.lines[i]) {
            sheet.errors[i] = { code: res.code, msg: err, left: res.left };
            sheet.idem = '';
            renderSheet();
            return;
        }
        if (res.code === 'trust' && cfg.tab) cfg.tab = { show: true, allowed: false, msg: err };
        sheet.global = err;
        renderSheet();
        sheetMessage(err);
    }

    /* ================================================================== */
    /* Tracking                                                            */
    /* ================================================================== */

    var alerted = {};
    var poller = null;
    var blink = null;
    var baseTitle = document.title;

    function sortedOrders() {
        return Object.keys(orders).map(function (k) { return orders[k]; }).sort(function (a, b) { return b.id - a.id; });
    }

    function instruction(o) {
        var a = { stand: o.stand_name, number: o.number };
        var unpaid = o.pay_state === 'unpaid' || o.pay_state === 'partial';
        var suffix = o.pay_mode === 'tab' ? ' ' + t('ShCusSuffixTab') : (unpaid && o.pay_mode === 'pickup' ? ' ' + t('ShCusSuffixPickup') : '');
        if (o.status === 'cancelled') return t('ShCusInstrCancelled');
        if (o.status === 'delivered') return t('ShCusInstrDelivered');
        if (o.status === 'ready') return fmt('ShCusInstrReady', a) + suffix;
        if (o.status === 'preparing') return t('ShCusInstrPreparing');
        if (o.later && !o.to_pay) return t('ShCusInstrScheduled', wantedLabel(o.wanted)) + suffix;
        if (o.channel === 'preorder') return fmt('ShCusInstrPreorder', a);
        if (o.to_pay) return fmt('ShCusInstrToPay', a);
        if (o.stand_mode === 'direct') return fmt('ShCusInstrDirect', a) + suffix;
        return t('ShCusInstrQueue') + suffix;
    }

    function stepsOf(o) {
        return o.stand_mode === 'direct' ? ['placed', 'delivered'] : ['placed', 'preparing', 'ready', 'delivered'];
    }
    function stepLabel(s) {
        return t({ placed: 'ShStPlaced', preparing: 'ShStPreparing', ready: 'ShStReady', delivered: 'ShStDelivered' }[s]);
    }
    function payBadge(o) {
        var map = { unpaid: ['ShPayUnpaid', 'topay'], partial: ['ShPayPartial', 'topay'], paid: ['ShPayPaid', 'paid'],
            tab: ['ShPayTab', 'tab'], refunded: ['ShPayRefunded', 'off'] };
        var m = map[o.pay_state];
        return m ? el('span', 'shp-badge shp-badge-' + m[1], t(m[0])) : null;
    }

    function orderCard(o) {
        var card = el('article', 'cus-order cus-st-' + o.status);
        card.setAttribute('data-id', o.id);
        card.appendChild(el('div', 'shp-number', o.number));
        var head = el('p', 'cus-ostand');
        head.appendChild(el('b', null, o.stand_name));
        card.appendChild(head);
        if (o.status !== 'cancelled') {
            var ol = el('ol', 'cus-steps');
            var at = stepsOf(o).indexOf(o.status);
            stepsOf(o).forEach(function (s, i) {
                var li = el('li', i < at ? 'done' : (i === at ? 'on' : ''), stepLabel(s));
                if (i === at) li.setAttribute('aria-current', 'step');
                ol.appendChild(li);
            });
            card.appendChild(ol);
        } else {
            card.appendChild(el('span', 'shp-badge shp-badge-off', t('ShStCancelled')));
        }
        if (o.wanted && o.status !== 'cancelled' && o.status !== 'delivered') {
            card.appendChild(el('p', 'cus-wanted', t('ShCusWantedAt', wantedLabel(o.wanted))));
        }
        if ((o.status === 'placed' || o.status === 'preparing') && !o.to_pay && !o.later && (o.channel !== 'preorder' || o.wanted) && o.eta > 0) {
            card.appendChild(el('p', 'cus-eta', t('ShCusWait', t('ShEtaMin', o.eta))));
        }
        card.appendChild(el('p', 'cus-instr', instruction(o)));
        var ul = el('ul', 'cus-olines');
        o.lines.forEach(function (l) {
            var li = el('li');
            li.appendChild(el('span', null, l.qty + ' × ' + l.label));
            li.appendChild(el('span', null, Shp.money(l.amount)));
            ul.appendChild(li);
        });
        card.appendChild(ul);
        var foot = el('p', 'cus-ofoot');
        foot.appendChild(el('span', null, t('ShCusTotal')));
        foot.appendChild(el('b', null, Shp.money(o.total)));
        var pb = payBadge(o);
        if (pb && o.status !== 'cancelled') foot.appendChild(pb);
        card.appendChild(foot);
        if (o.can_cancel) card.appendChild(cancelControl(o));
        return card;
    }

    function cancelControl(o) {
        var box = el('div', 'cus-cancel');
        var ask = button('shp-btn shp-btn-danger shp-btn-block', t('ShCusCancel'), function () {
            clear(box);
            box.appendChild(el('p', 'cus-instr', t('ShCusCancelSure', o.number)));
            var row = el('div', 'shp-btn-row');
            row.appendChild(button('shp-btn shp-btn-danger', t('ShCusCancelYes'), function () { doCancel(o, box); }));
            row.appendChild(button('shp-btn', t('ShCusCancelNo'), function () { renderOrders(); }));
            box.appendChild(row);
        });
        box.appendChild(ask);
        return box;
    }

    function doCancel(o, box) {
        clear(box);
        box.appendChild(el('p', 'shp-muted', t('ShLoading')));
        Shp.api(urls.cancel, { k: key, order: o.id }).then(function (res) {
            if (res && !res.error) Shp.toast(t('ShCusCancelled', o.number));
            fetchStatus().then(function () {
                if (res && res.error) {
                    var card = document.querySelector('#cus-orders .cus-order[data-id="' + o.id + '"] .cus-cancel');
                    if (card) { clear(card); card.appendChild(el('div', 'shp-msg shp-msg-err', res.msg || t('ShErrInternal'))); }
                }
            });
        }, function () {
            clear(box);
            box.appendChild(el('div', 'shp-msg shp-msg-err', t('ShErrNetwork')));
        });
    }

    function renderOrders() {
        var root = $('cus-orders');
        if (!root) return;
        clear(root);
        var list = sortedOrders();
        var live = list.filter(function (o) { return o.active; });
        var past = list.filter(function (o) { return !o.active; });
        if (!list.length) {
            root.appendChild(el('p', 'shp-muted', t('ShCusNoOrders')));
            if (onShop) root.appendChild(button('shp-btn shp-btn-block', t('ShCusBackShop'), function () { showView('shop'); }));
        }
        if (live.some(function (o) { return o.watch; })) notifyBlock(root);
        live.forEach(function (o) { root.appendChild(orderCard(o)); });
        if (past.length) {
            var d = el('details', 'cus-past');
            d.appendChild(el('summary', null, t('ShCusPast') + ' (' + past.length + ')'));
            past.forEach(function (o) { d.appendChild(orderCard(o)); });
            root.appendChild(d);
        }
        var c = $('cus-count');
        if (c) {
            c.textContent = String(live.length);
            c.classList.toggle('shp-hidden', !live.length);
        }
    }

    /* ---- Notifications ---- */

    function pushCapable() {
        return !!(urls.push && cfg.sw && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window);
    }
    function standalone() {
        return window.navigator.standalone === true || !!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
    }
    /** What the page offers: notifications from the server (also phone locked), or from this page while it is open. */
    function notifyBlock(root) {
        var perm = 'Notification' in window ? window.Notification.permission : 'denied';
        if (pushCapable() && perm === 'default') {
            root.appendChild(button('shp-btn shp-btn-primary shp-btn-block cus-notif', t('ShCusPushBtn'), function () {
                try { window.Notification.requestPermission().then(function (p) { if (p === 'granted') subscribePush(); renderOrders(); }, renderOrders); } catch (e) { renderOrders(); }
            }));
        } else if (pushCapable() && perm === 'granted') {
            subscribePush();
            root.appendChild(el('p', 'shp-muted', t('ShCusPushOn')));
        } else if (/iPad|iPhone|iPod/.test(navigator.userAgent) && !standalone()) {
            root.appendChild(el('div', 'shp-msg shp-msg-info', t('ShCusPushIos')));
        } else if (perm === 'default') {
            root.appendChild(button('shp-btn shp-btn-block cus-notif', t('ShCusNotifBtn'), function () {
                try { window.Notification.requestPermission().then(renderOrders, renderOrders); } catch (e) { renderOrders(); }
            }));
        } else if (perm === 'granted') {
            root.appendChild(el('p', 'shp-muted', t('ShCusNotifOn')));
        }
    }
    function keyBytes(b64u) {
        var s = String(b64u).replace(/-/g, '+').replace(/_/g, '/'), raw, out, i;
        s += '===='.slice((s.length % 4) || 4);
        raw = window.atob(s);
        out = new Uint8Array(raw.length);
        for (i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }
    /** Subscribes this browser (once per page) and tells the server it belongs to this customer. */
    var pushAsked = false;
    function subscribePush() {
        if (pushAsked || !pushCapable()) return;
        pushAsked = true;
        Shp.api(urls.push + '?k=' + encodeURIComponent(key)).then(function (res) {
            if (!res || res.error || !res.key) return null;
            // A registration can only subscribe once its worker is active: wait for it.
            return navigator.serviceWorker.register(cfg.sw).then(function () { return navigator.serviceWorker.ready; }).then(function (reg) {
                return reg.pushManager.getSubscription().then(function (sub) {
                    return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(res.key) });
                });
            }).then(function (sub) {
                return sub ? Shp.api(urls.push, { k: key, action: 'subscribe', sub: sub.toJSON() }) : null;
            });
        }).then(function () {}, function () { pushAsked = false; });
    }

    function highlight(id) {
        var c = document.querySelector('#cus-orders .cus-order[data-id="' + id + '"]');
        if (c) { c.classList.add('fresh'); if (c.scrollIntoView) c.scrollIntoView({ block: 'start' }); }
    }

    /** Takes orders from the server into the page; announces the ones that just became ready. */
    function applyOrders(list) {
        var ready = [];
        list.forEach(function (o) {
            orders[o.id] = o;
            if (o.status !== 'ready') delete alerted[o.id];
            else if (!o.seen && !alerted[o.id]) { alerted[o.id] = true; ready.push(o); }
        });
        renderOrders();
        follow();
        if (ready.length) announce(ready);
    }

    function fetchStatus() {
        return Shp.api(urls.status + '?k=' + encodeURIComponent(key)).then(function (res) {
            if (res && !res.error) applyOrders(res.orders || []);
        });
    }

    /** One request per phone while something is followed; none otherwise. */
    function follow() {
        var n = 0;
        Object.keys(orders).forEach(function (k) { if (orders[k].watch) n++; });
        if (n > 0 && !poller) poller = Shp.poll(fetchStatus, 10000, 30000);
        else if (n === 0 && poller) { poller.stop(); poller = null; }
    }

    /* ---- "Ready" announcement: full screen, sound, vibration, title, notification ---- */

    var announced = [];
    function announce(list) {
        list.forEach(function (o) { announced.push(o); });
        var layer = $('cus-layer');
        var box = $('cus-alert');
        if (!box) {
            box = el('div', 'shp-alert');
            box.id = 'cus-alert';
            box.setAttribute('role', 'alertdialog');
            layer.appendChild(box);
        }
        clear(box);
        box.appendChild(el('h1', null, t('ShCusReadyTitle')));
        announced.forEach(function (o) {
            box.appendChild(el('div', 'shp-number', o.number));
            box.appendChild(el('p', null, t('ShCusReadyGo', o.stand_name)));
        });
        var ok = button('shp-btn shp-btn-big', t('ShCusReadyOk'), dismissAnnounce);
        box.appendChild(ok);
        ok.focus();
        Shp.beep(3);
        Shp.vibrate([300, 150, 300, 150, 300]);
        var nums = announced.map(function (o) { return o.number; }).join(' ');
        if (blink) clearInterval(blink);
        var flip = false;
        blink = setInterval(function () {
            flip = !flip;
            document.title = flip ? t('ShCusReadyTitle') + ' ' + nums : baseTitle;
        }, 1000);
        if (document.hidden && 'Notification' in window && window.Notification.permission === 'granted') {
            try { new window.Notification(t('ShCusNotifTitle'), { body: nums + ' — ' + t('ShCusReadyGo', announced[0].stand_name) }); } catch (e) { /* not allowed */ }
        }
    }

    function dismissAnnounce() {
        var box = $('cus-alert');
        if (box && box.parentNode) box.parentNode.removeChild(box);
        if (blink) { clearInterval(blink); blink = null; }
        document.title = baseTitle;
        announced.forEach(function (o) {
            if (orders[o.id]) orders[o.id].seen = true;
            Shp.api(urls.seen, { k: key, order: o.id }).then(function () {}, function () {});
        });
        announced = [];
    }

    /* ================================================================== */
    /* Views and start                                                     */
    /* ================================================================== */

    function showView(v) {
        if (!onShop) return;
        view = v;
        $('cus-shop').classList.toggle('shp-hidden', v !== 'shop');
        $('cus-orders').classList.toggle('shp-hidden', v !== 'orders');
        Array.prototype.forEach.call(document.querySelectorAll('#cus-nav .cus-nav-btn'), function (b) {
            var on = b.getAttribute('data-view') === v;
            b.classList.toggle('on', on);
            if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
        });
        renderBar();
        if (v === 'orders') fetchStatus().then(function () {}, function () {});
        window.scrollTo(0, 0);
    }

    function start() {
        // The first tap of the page unlocks the sound (phones refuse it before).
        document.addEventListener('click', Shp.unlockAudio, { once: true });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && blink) { clearInterval(blink); blink = null; document.title = baseTitle; }
        });
        (data.orders || []).forEach(function (o) { orders[o.id] = o; });
        if (onShop) {
            cart = cartLoad();
            cartSave();
            current = cfg.stand || 0;
            Array.prototype.forEach.call(document.querySelectorAll('#cus-nav .cus-nav-btn'), function (b) {
                b.addEventListener('click', function () { showView(b.getAttribute('data-view')); });
            });
            renderShop();
            // A basket sent just before the page was closed: the server answers once.
            Shp.flush(function (res, p) {
                if (res && !res.error && p.url === urls.order && p.body && p.body.stand) {
                    delete cart[p.body.stand];
                    cartSave();
                    renderShop();
                    fetchStatus().then(function () {}, function () {});
                }
            });
        }
        applyOrders(data.orders || []);
    }

    start();
})();
