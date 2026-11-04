/**
 * Page of the cells to check (verify.php): a few dozen cells at a time, each with the
 * value read typed in already. The person checking corrects those that are wrong (or
 * clicks one of the other readings under the cell) and validates the lot with Enter.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var root = document.getElementById('ocr');
    if (!root || !document.getElementById('ocr-verify')) return;
    var api = root.getAttribute('data-api'), csrf = root.getAttribute('data-csrf');
    var T = window.OCR_T || {}, shown = [];
    var SHOW_H = 120;   // height of a cell's picture on the page, in pixels, when its place in it is not known
    var CELL_H = 72;    // ...else the height the cell itself is shown at (its neighbours around it)
    var MISFRAMED = '*'; // typed (a key of the number pad) for a cell cut out badly (across two cells, off the grid): no value to tell
    // Why a training set's cell is to check: X or 10 not told apart, its end held by the
    // database in another order than the sheet's, a value the reading does not see, or a sheet of
    // a competition the server does not hold read unsure.
    var WHY = {x10: 'VerifyWhyX10', order: 'VerifyWhyOrder', held: 'VerifyWhyHeld', read: 'VerifyWhyRead'};

    function el(id) { return document.getElementById(id); }

    function t(key, a) {
        var s = T[key] || key;
        if (a === undefined) return s;
        if (typeof a !== 'object') return s.split('{$a}').join(String(a));
        Object.keys(a).forEach(function (k) { s = s.split('{$a[' + k + ']}').join(String(a[k])); });
        return s;
    }

    function post(fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        fd.append('csrf', csrf);
        return fetch(api, {method: 'POST', credentials: 'same-origin', body: fd}).then(function (r) { return r.json(); });
    }

    /** The highest number a cell can hold (allowed: the numbers its archer's face can make), or null. */
    function highest(it) {
        if (it.allowed && it.allowed.length) return it.allowed[it.allowed.length - 1][1];
        return it.max === null || it.max === undefined ? null : it.max;
    }

    /** What a cell is on the card, and the highest number it can hold. */
    function role(it) {
        var k = it.key, total = it.line === 'total', name;
        // A count named as printed on the card ("10+X", "X", "9"…), when known.
        var count = function (key, def) { return it.label ? t(key, it.label) : t(def); };
        if (it.kind === 'arrow') name = t('VerifyRoleArrow');
        else if (k === 'line') name = t('VerifyRoleLine');
        else if (k === 'end') name = t('VerifyRoleEnd');
        else if (k === 'cum') name = t(total ? 'VerifyRoleTotal' : 'VerifyRoleCum');
        else if (k === 'gold' || k === 'xnine') {
            name = total ? count('VerifyRoleTotalCountOf', 'VerifyRoleTotalCount') : count('VerifyRoleCountOf', k === 'gold' ? 'VerifyRoleGold' : 'VerifyRoleXnine');
        } else name = t('VerifyNumber');
        var top = highest(it);
        return top === null ? name : name + ' · ' + t('VerifyMax', top);
    }

    /** A value the cell cannot hold: a number its archer's face cannot make, or above its highest. */
    function beyond(it, v) {
        if (!/^\d+$/.test(v)) return false;
        if (it.allowed) return !it.allowed.some(function (r) { return +v >= r[0] && +v <= r[1]; });
        return it.max !== null && it.max !== undefined && +v > it.max;
    }

    function count(j) {
        el('ocr-verify-count').textContent = j.pending || j.done ? t('VerifyCount', {pending: j.pending, done: j.done}) : t('VerifyNone');
    }

    /** One cell: its picture (its borders drawn), what was read typed in, the other readings. */
    function tile(it, i) {
        var box = document.createElement('div');
        box.className = 'ocr-verify-cell';
        var c = document.createElement('canvas'), img = new Image();
        img.onload = function () {
            var b = it.box && it.box.length === 4 ? it.box : null;
            var k = b && b[3] > b[1] ? CELL_H / (b[3] - b[1]) : SHOW_H / img.naturalHeight;
            c.width = Math.round(img.naturalWidth * k);
            c.height = Math.round(img.naturalHeight * k);
            var g = c.getContext('2d');
            g.imageSmoothingEnabled = true;
            g.drawImage(img, 0, 0, c.width, c.height);
            if (it.box && it.box.length === 4) {
                g.strokeStyle = 'rgba(2, 84, 168, 0.8)';
                g.lineWidth = 1.5;
                g.strokeRect(it.box[0] * k, it.box[1] * k, (it.box[2] - it.box[0]) * k, (it.box[3] - it.box[1]) * k);
            }
        };
        img.src = 'data:image/png;base64,' + it.png;
        // Where it comes from (to find the sheet again), then what it is on the card.
        // A training set's cell says where it is, and why its label is not sure.
        var from = it.target ? t('VerifyWhere', {target: it.target, dist: it.dist || '-'})
            : it.sheet ? t('VerifyTrainWhere', {sheet: it.sheet, card: it.card, end: it.end, arrow: +it.arrow + 1}) : '';
        var why = WHY[it.why] ? t(WHY[it.why]) : '';
        if (from || why) {
            var where = document.createElement('div');
            where.className = 'ocr-verify-where';
            where.textContent = from && why ? from + ' · ' + why : from || why;
            box.appendChild(where);
        }
        var label = document.createElement('div');
        label.className = 'ocr-verify-role';
        label.textContent = role(it);
        box.appendChild(label);
        box.appendChild(c);
        // The readings the cell can hold (the first typed in), unless none can.
        var reading = it.reading.filter(function (r) { return !beyond(it, r[0]); });
        if (!reading.length) reading = it.reading;
        var first = reading[0] ? reading[0][0] : '';
        var input = document.createElement('input');
        input.type = 'text';
        input.value = first;
        input.maxLength = 4;
        input.setAttribute('aria-label', t(it.kind === 'arrow' ? 'VerifyArrow' : 'VerifyNumber') + ' ' + (i + 1));
        var mark = function () {
            box.classList.toggle('ocr-verify-changed', input.value !== first);
            box.classList.toggle('ocr-verify-misframed', input.value === MISFRAMED);
            var odd = beyond(it, input.value.trim());
            box.classList.toggle('ocr-verify-odd', odd);
            input.title = odd ? t('VerifyBeyond', highest(it)) : '';
        };
        input.addEventListener('input', function () {
            input.value = input.value.toUpperCase();
            mark();
        });
        input.addEventListener('focus', function () { input.select(); });
        mark();
        box.appendChild(input);
        // The other readings, one click each.
        var alt = document.createElement('div');
        alt.className = 'ocr-verify-alt';
        reading.slice(1, 4).forEach(function (r) {
            var b = document.createElement('button');
            b.type = 'button';
            b.tabIndex = -1;
            b.textContent = r[0] === '' ? '·' : r[0];
            b.title = Math.round(r[1] * 100) + ' %';
            b.addEventListener('click', function () {
                input.value = r[0];
                mark();
                input.focus();
            });
            alt.appendChild(b);
        });
        box.appendChild(alt);
        // Cut out badly: said in one click (or "*"), the cell is left out of what is learnt.
        var off = document.createElement('button');
        off.type = 'button';
        off.tabIndex = -1;
        off.className = 'ocr-verify-off';
        off.textContent = t('VerifyMisframed');
        off.addEventListener('click', function () {
            input.value = input.value === MISFRAMED ? first : MISFRAMED;
            mark();
            input.focus();
        });
        box.appendChild(off);
        return {uid: it.uid, input: input};
    }

    function render(j) {
        count(j);
        var cells = el('ocr-verify-cells');
        cells.innerHTML = '';
        shown = (j.items || []).map(function (it, i) {
            var o = tile(it, i);
            cells.appendChild(o.input.parentNode);
            return o;
        });
        el('ocr-verify-ok').hidden = !shown.length;
        if (shown.length) shown[0].input.focus();
    }

    function fail(j) {
        el('ocr-verify-count').textContent = (j && j.msg) || t('ErrNetwork');
    }

    el('ocr-verify').addEventListener('submit', function (e) {
        e.preventDefault();
        if (!shown.length) return;
        var labels = shown.map(function (o) { return {uid: o.uid, value: o.input.value.trim()}; });
        el('ocr-verify-ok').disabled = true;
        post({action: 'save', labels: JSON.stringify(labels)}).then(function (j) {
            el('ocr-verify-ok').disabled = false;
            if (j.error) return fail(j);
            render(j);
        }, function () {
            el('ocr-verify-ok').disabled = false;
            fail(null);
        });
    });

    post({action: 'next'}).then(function (j) {
        if (j.error) return fail(j);
        render(j);
    }, function () { fail(null); });
});
