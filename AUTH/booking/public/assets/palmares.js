/* Import of the federation record (public/stats-import.php, lib/palmares.php).
   The Excel file exported from the extranet ("Mon palmarès", Export Excel .xlsx) is read HERE,
   in the archer's browser: unzipped (DecompressionStream), its two XML parts parsed, and only
   the rows sent to the server — never the file. The server shows what it would keep, then
   stores it on the archer's word. Every text shown goes through textContent. */
(function () {
    'use strict';

    var LIMIT = 30 * 1024 * 1024;   // unzipped size of one part: a record weighs well under 1 MB

    function u16(b, o) { return b[o] | (b[o + 1] << 8); }
    function u32(b, o) { return (b[o] | (b[o + 1] << 8) | (b[o + 2] << 16)) + b[o + 3] * 16777216; }

    /* Central directory of a zip archive: {name: {method, csize, usize, lho}}, or null. */
    function unzip(buf) {
        var b = new Uint8Array(buf), n = b.length, eocd = -1, i;
        for (i = n - 22; i >= Math.max(0, n - 22 - 65535); i--) {
            if (b[i] === 0x50 && b[i + 1] === 0x4b && b[i + 2] === 0x05 && b[i + 3] === 0x06) { eocd = i; break; }
        }
        if (eocd < 0) return null;
        var count = u16(b, eocd + 10), off = u32(b, eocd + 16), dec = new TextDecoder(), out = {};
        for (i = 0; i < count; i++) {
            if (off + 46 > n || u32(b, off) !== 0x02014b50) return null;
            var nl = u16(b, off + 28), xl = u16(b, off + 30), cl = u16(b, off + 32);
            out[dec.decode(b.subarray(off + 46, off + 46 + nl))] = {
                method: u16(b, off + 10), csize: u32(b, off + 20), usize: u32(b, off + 24), lho: u32(b, off + 42)
            };
            off += 46 + nl + xl + cl;
        }
        return { bytes: b, entries: out };
    }

    /* Text of one part of the archive (stored or deflated), or null when absent. */
    function part(z, name) {
        var e = z.entries[name];
        if (!e) return Promise.resolve(null);
        if (e.usize > LIMIT) return Promise.reject('big');
        var b = z.bytes, o = e.lho;
        if (u32(b, o) !== 0x04034b50) return Promise.reject('zip');
        var start = o + 30 + u16(b, o + 26) + u16(b, o + 28);
        var data = b.subarray(start, start + e.csize);
        if (e.method === 0) return Promise.resolve(new TextDecoder().decode(data));
        if (e.method !== 8) return Promise.reject('zip');
        var stream = new Blob([data]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
        return new Response(stream).arrayBuffer().then(function (ab) { return new TextDecoder().decode(ab); });
    }

    function xml(text) {
        var d = new DOMParser().parseFromString(text, 'application/xml');
        if (d.getElementsByTagName('parsererror').length) throw 'xml';
        return d;
    }

    function tags(node, name) { return node.getElementsByTagNameNS('*', name); }

    function texts(node) {
        var s = '', t = tags(node, 't');
        for (var i = 0; i < t.length; i++) s += t[i].textContent;
        return s;
    }

    /* Rows of the first sheet: [{A: …, B: …, C: …}], shared strings resolved. */
    function sheetRows(sheet, shared) {
        var ss = [], i, j;
        if (shared) {
            var si = tags(shared, 'si');
            for (i = 0; i < si.length; i++) ss.push(texts(si[i]));
        }
        var out = [], rows = tags(sheet, 'row');
        for (i = 0; i < rows.length; i++) {
            var row = {}, cs = tags(rows[i], 'c');
            for (j = 0; j < cs.length; j++) {
                var c = cs[j], col = (c.getAttribute('r') || '').replace(/\d+$/, ''), t = c.getAttribute('t');
                var v = tags(c, 'v')[0], val = v ? v.textContent : '';
                if (t === 's') val = ss[+val] || '';
                else if (t === 'inlineStr') val = texts(c);
                if (col) row[col] = String(val).trim();
            }
            out.push(row);
        }
        return out;
    }

    /* The record in those rows: {lic, rows: [{d, c, dt, ev, pl, sc, off, cnt}]}. Headings start
       with ">>" (discipline and season), rankings with ">", results with "[OFF] dd/mm/yyyy - …"
       (no "[OFF]": a round outside the official results). Team headings are left out. */
    function record(rows) {
        var lic = '', heading = '', cat = '', out = [];
        for (var i = 0; i < Math.min(5, rows.length) && !lic; i++) {
            var m = /\((\d{7}[A-Z])\)/.exec(rows[i].A || '');
            if (m) lic = m[1];
        }
        rows.forEach(function (r) {
            var a = r.A || '';
            if (/^>>/.test(a)) { heading = a.replace(/^>>\s*/, ''); cat = ''; return; }
            if (/^>/.test(a)) { cat = a.replace(/^>\s*/, ''); return; }
            if (!/^individuel\s/i.test(heading) || !cat) return;
            var x = /^(\[OFF\])?\s*(\d{2})\/(\d{2})\/(\d{4})\s*-\s*(.+)$/.exec(a);
            var sc = /(\d+)\s*pts/i.exec(r.C || '');
            if (!x || !sc) return;
            var pl = /^(\d+)/.exec(r.B || '');
            out.push({
                d: heading.slice(0, 100), c: cat.slice(0, 160), dt: x[4] + '-' + x[3] + '-' + x[2],
                ev: x[5].trim().slice(0, 200), pl: pl ? Math.min(65535, +pl[1]) : 0, sc: Math.min(999999, +sc[1]),
                off: x[1] ? 1 : 0, cnt: /non pris en compte/i.test(r.C || '') ? 0 : 1
            });
        });
        return { lic: lic, rows: out };
    }

    /* File → record, or a rejection naming the problem (a key of the page's texts). */
    function read(file) {
        return file.arrayBuffer().then(function (buf) {
            var b = new Uint8Array(buf, 0, Math.min(8, buf.byteLength));
            if (b[0] === 0xd0 && b[1] === 0xcf && b[2] === 0x11 && b[3] === 0xe0) throw 'PalErrXls';
            if (b[0] !== 0x50 || b[1] !== 0x4b) throw 'PalErrNotXlsx';
            if (typeof DecompressionStream === 'undefined') throw 'PalErrBrowser';
            var z = unzip(buf);
            if (!z) throw 'PalErrNotXlsx';
            var sheet = z.entries['xl/worksheets/sheet1.xml'] ? 'xl/worksheets/sheet1.xml'
                : Object.keys(z.entries).filter(function (k) { return /^xl\/worksheets\/sheet\d+\.xml$/.test(k); }).sort()[0];
            if (!sheet) throw 'PalErrNotXlsx';
            return Promise.all([part(z, sheet), part(z, 'xl/sharedStrings.xml')]);
        }).then(function (p) {
            var rec = record(sheetRows(xml(p[0]), p[1] ? xml(p[1]) : null));
            if (!rec.rows.length) throw 'PalErrEmpty';
            return rec;
        }, function (e) {
            throw typeof e === 'string' && /^Pal/.test(e) ? e : 'PalErrNotXlsx';
        });
    }

    // For the test bench: the reading without the page.
    window.BkPalmares = { read: read, record: record };

    var root = document.getElementById('bk-pal');
    if (!root) return;
    var cfg;
    try { cfg = JSON.parse(root.getAttribute('data-cfg') || '{}'); } catch (e) { return; }
    var T = cfg.t || {};
    var input = document.getElementById('bk-pal-file'), msg = document.getElementById('bk-pal-msg');
    var view = document.getElementById('bk-pal-preview'), current = null;

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = String(text);
        return e;
    }

    /* The placeholders of the module's texts (AUTH/lang-lib.php): {$a}, or {$a[key]}. */
    function fill(s, a) {
        return String(s).replace(/\{\$a(?:\[(\w+)\])?\}/g, function (m, k) {
            return k ? (a[k] !== undefined ? a[k] : '') : a;
        });
    }

    function say(text, kind) {
        msg.textContent = text || '';
        msg.className = 'bk-pal-msg' + (kind ? ' ' + kind : '');
        msg.hidden = !text;
    }

    function post(action, rec) {
        var fd = new FormData();
        fd.append('bk_csrf', cfg.csrf);
        fd.append('action', action);
        fd.append('data', JSON.stringify(rec));
        return fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function confTag(conf) {
        var sure = conf === 'exact' || conf === 'data';
        return el('span', 'bk-pal-conf ' + (sure ? 'ok' : (conf === 'probable' ? 'maybe' : 'no')),
            T[sure ? 'PalConfSure' : (conf === 'probable' ? 'PalConfMaybe' : 'PalConfNone')]);
    }

    function show(p) {
        while (view.firstChild) view.removeChild(view.firstChild);
        var t = p.total;
        view.appendChild(el('p', 'bk-pal-sum', fill(T.PalSummary, { n: t.rounds, known: t.known, badge: t.badge })));
        var table = el('table', 'bk-pal-table'), head = el('tr');
        [T.PalThSeason, T.PalThHeading, T.PalThRounds, T.PalThPlace].forEach(function (h) { head.appendChild(el('th', '', h)); });
        var thead = el('thead'); thead.appendChild(head); table.appendChild(thead);
        var body = el('tbody');
        p.groups.forEach(function (g) {
            var tr = el('tr');
            tr.appendChild(el('td', 'y', g.season));
            tr.appendChild(el('td', '', g.heading));
            var n = el('td', 'num', g.n);
            if (g.known > 0) n.appendChild(el('small', '', fill(T.PalKnown, g.known)));
            tr.appendChild(n);
            var cats = el('td', 'cats');
            g.cats.forEach(function (c) {
                var line = el('div', 'cat');
                line.appendChild(el('b', '', c.name));
                line.appendChild(el('span', 'w', [c.disc, c.where].filter(Boolean).join(' · ')));
                line.appendChild(confTag(c.conf));
                cats.appendChild(line);
            });
            tr.appendChild(cats);
            body.appendChild(tr);
        });
        table.appendChild(body);
        var wrap = el('div', 'bk-pal-scroll');
        wrap.appendChild(table);
        view.appendChild(wrap);
        view.appendChild(el('p', 'bk-hint', T.PalConfNote));
        var go = el('button', 'bk-btn bk-btn-primary', fill(T.PalDoImport, t.rounds));
        go.type = 'button';
        go.addEventListener('click', function () {
            go.disabled = true;
            say(T.PalSaving, '');
            post('import', current).then(function (r) {
                if (r.error) { go.disabled = false; say(r.msg, 'err'); return; }
                window.location.href = r.next;
            }, function () { go.disabled = false; say(T.PalErrNetwork, 'err'); });
        });
        var actions = el('p', 'bk-actions');
        actions.appendChild(go);
        view.appendChild(actions);
        view.hidden = false;
    }

    input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        view.hidden = true;
        current = null;
        if (!f) { say('', ''); return; }
        if (f.size > 8 * 1024 * 1024) { say(T.PalErrNotXlsx, 'err'); return; }
        say(T.PalReading, '');
        read(f).then(function (rec) {
            current = rec;
            return post('preview', rec).then(function (r) {
                if (r.error) { say(r.msg, 'err'); return; }
                say(r.skipped > 0 ? fill(T.PalSkipped, r.skipped) : '', '');
                show(r.preview);
            });
        }).catch(function (e) {
            say(typeof e === 'string' && T[e] ? T[e] : T.PalErrNetwork, 'err');
        });
    });
})();
