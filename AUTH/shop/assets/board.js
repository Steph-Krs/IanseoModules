/* Public screen of the orders (public/board.php), three columns as in a fast-food restaurant:
   received (state of the payment, service time), being prepared (time announced), ready (a new
   one blinks); every order with the name its customer recognises ("Stéphane K", a nickname). Each title shows how many
   orders its column holds, and a note appears when the screen does not show them all. Asks
   api/board.php every 5 s; no text but the ones the server sent. */
(function () {
    'use strict';
    var Shp = window.Shp;
    if (!Shp) return;
    function col(id) {
        return { list: document.getElementById(id), n: document.getElementById(id + '-n'), more: document.getElementById(id + '-more') };
    }
    var waitCol = col('cb-wait');
    var prepCol = col('cb-prep');
    var readyCol = col('cb-ready');
    var seen = {};
    var first = true;
    var PAY = { paid: 'ShCusBoardPaid', topay: 'ShCusBoardToPay', pickup: 'ShCusBoardPickup', tab: 'ShCusBoardTab' };

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }
    function empty(box) {
        while (box.firstChild) box.removeChild(box.firstChild);
    }

    /* Clears a column, writes its count, shows the note when the list is cut; false when empty. */
    function start(c, list, total) {
        empty(c.list);
        total = typeof total === 'number' ? total : list.length;
        if (c.n) { c.n.textContent = String(total); c.n.hidden = false; }
        if (c.more) c.more.hidden = total <= list.length;
        if (list.length) return true;
        c.list.appendChild(el('span', 'cus-board-none', Shp.t('ShCusBoardNone')));
        return false;
    }

    /* Number of an order, then the name its customer recognises. */
    function head(o) {
        var row = el('div', 'cus-board-row');
        row.appendChild(el('span', 'cus-board-sm', o.n));
        if (o.who) row.appendChild(el('span', 'cus-board-who', o.who));
        return row;
    }

    function fillWaiting(list, total) {
        if (!start(waitCol, list, total)) return;
        list.forEach(function (o) {
            var row = head(o);
            var tags = el('span', 'cus-board-tags');
            if (o.at) tags.appendChild(el('span', 'cus-board-at', Shp.t('ShCusBoardAt', o.at)));
            if (PAY[o.pay]) tags.appendChild(el('span', 'cus-board-pay is-' + o.pay, Shp.t(PAY[o.pay])));
            row.appendChild(tags);
            waitCol.list.appendChild(row);
        });
    }

    function fillPreparing(list, total) {
        if (!start(prepCol, list, total)) return;
        list.forEach(function (o) {
            var row = head(o);
            if (o.eta !== null && o.eta !== undefined) row.appendChild(el('span', 'cus-board-eta', Shp.t('ShPosMinutes', o.eta)));
            prepCol.list.appendChild(row);
        });
    }

    function fillReady(list, total) {
        if (!start(readyCol, list, total)) return;
        list.forEach(function (o) {
            var tile = el('span', 'cus-board-num' + (!first && !seen[o.n] ? ' fresh' : ''));
            tile.appendChild(el('span', 'cus-board-num-n', o.n));
            // The line is kept when there is no name, so that every tile has the same height.
            tile.appendChild(el('span', 'cus-board-name', o.who || '\u00a0'));
            readyCol.list.appendChild(tile);
        });
    }

    function refresh() {
        return Shp.api(Shp.cfg.api).then(function (res) {
            if (!res || res.error) return;
            var count = res.count || {};
            fillWaiting(res.waiting || [], count.waiting);
            fillPreparing(res.preparing || [], count.preparing);
            fillReady(res.ready || [], count.ready);
            (res.ready || []).forEach(function (o) { seen[o.n] = true; });
            first = false;
        });
    }

    /* Keeps the screen awake where the browser allows it; silent where it does not. */
    function wake() {
        if (!navigator.wakeLock || document.hidden) return;
        try { navigator.wakeLock.request('screen').then(function () {}, function () {}); } catch (e) { /* not allowed */ }
    }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) wake(); });
    wake();

    Shp.poll(refresh, 5000, 15000);
})();
