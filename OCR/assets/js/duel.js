/**
 * The duel page's own part of the reading (duels.php): what a match sheet asks that a
 * qualification sheet does not, the rest being qualification.js's (the same capture,
 * analysis, cells read, cards shown). Given to that script as window.OcrKind, which hands
 * it its own functions (init(env)) and asks it at a few points.
 *
 *  - The round being shot is chosen first (its matches fetched from api/duel.php), today's
 *    and those not over listed, as the core's scorecard printouts list them.
 *  - A sheet is one match: its cards fitted with the round's layouts (duel-layout.js), the
 *    left one the match's first side; whose match, from the sheet's barcode when it reads,
 *    else from the names printed above the cards (and the event under them), scored against
 *    the round's matches.
 *  - Each card's arrows chosen by the qualification's checks (its end's or set's totals, its
 *    running total), then the match worked out from both: set points and their running total,
 *    the total, the shoot-off, the winner, against what was circled, written and ticked.
 *  - Written into ianseo and confirmed as the core's barcode check confirms a match.
 */
(function () {
    'use strict';

    var DL = window.OcrDuelLayout;
    // A match card's signature line, under its shoot-off and joined to it by a signature, is no
    // border of its table (OcrVision: lone).
    var K = {name: 'duel', setupIds: ['ocr-tour'], analyseOpts: {lone: true}};
    var env = null;
    var cache = {key: null, list: null, wait: null};

    var GAP_NAME = 3;       // a name read this far below its band's free reading, per character, at most…
    var MARGIN_MATCH = 0.7; // …and the match this far ahead of the next one
    var CAT_WEIGHT = 0.3;   // the event's line and the round's title, beside the names (two teams of a club in two
                            // events; the same teams there and back in a round robin)
    var BOX_INK = 0.06;     // a box ticked: this share of its inside dark, at least
    var PICK_INK = 0.025;   // a set point marked: so much more ink round or over its digit, at least
    var PICK_RATIO = 1.8;   // ...and this many times more than the next digit
    var EMPTY_INK = 0.01;   // a cell this little dark inside its borders: nothing written

    // ------------------------------------------------------------------ setup

    K.init = function (e) {
        env = e;
        ['ocr-today', 'ocr-unfinished'].forEach(function (id) {
            env.el(id).addEventListener('change', toursReload);
        });
        // A match chosen by the operator for a card not identified.
        env.el('ocr-sheet').addEventListener('change', function (ev) {
            var s = ev.target.closest && ev.target.closest('select[data-duel-pick]');
            if (s && s.value !== '') pickMatch(env.state.sheet[+s.getAttribute('data-duel-pick')], s.value);
        });
        env.el('ocr-sheet').addEventListener('click', function (ev) {
            var b = ev.target.closest && ev.target.closest('td[data-duel-box]');
            if (b) toggleBox(env.state.sheet[+b.getAttribute('data-of')], b.getAttribute('data-duel-box'));
        });
        if (env.el('ocr-tour').value) matches();
    };

    /** The round chosen again: its matches fetched afresh. */
    K.setupChanged = function (id) {
        if (id !== 'ocr-tour') return;
        cache = {key: null, list: null, wait: null};
        if (env.el('ocr-tour').value) matches();
    };

    function post(body) {
        return fetch(env.api, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body})
            .then(function (r) { return r.json(); });
    }

    /** The rounds listed again, as the boxes say (today's, those not over); the one chosen kept if still there. */
    function toursReload() {
        var sel = env.el('ocr-tour'), keep = sel.value;
        post('action=tours&today=' + (env.el('ocr-today').checked ? 1 : 0) + '&unfinished=' + (env.el('ocr-unfinished').checked ? 1 : 0)).then(function (j) {
            if (!j || j.error) return;
            sel.innerHTML = '<option value="">' + env.esc(env.t('Choose')) + '</option>' + j.tours.map(function (o) {
                return '<option value="' + env.esc(o.key) + '">' + env.esc(o.label) + '</option>';
            }).join('');
            sel.value = j.tours.some(function (o) { return o.key === keep; }) ? keep : '';
            if (sel.value !== keep) K.setupChanged('ocr-tour');
            env.setupReady();
        });
    }

    /** The round's matches (api/duel.php), fetched once a round. -> a promise of the list. */
    function matches() {
        var key = env.el('ocr-tour').value;
        if (!key) return Promise.resolve([]);
        if (cache.key === key && cache.list) return Promise.resolve(cache.list);
        if (cache.key === key && cache.wait) return cache.wait;
        cache = {key: key, list: null, wait: null};
        cache.wait = post('action=matches&tour=' + encodeURIComponent(key)).then(function (j) {
            if (cache.key !== key) return matches();
            cache.list = j && !j.error ? j.matches : [];
            cache.wait = null;
            return cache.list;
        }, function () {
            cache.wait = null;
            return [];
        });
        return cache.wait;
    }

    function specKey(s) {
        return [s.mode, s.ends, s.arrows, s.so, s.soRows, s.soTotal, s.golds, s.xnine, s.team, s.robin].join('|');
    }

    /** The layouts a round's sheets can have: one a kind of match (sets or points, ends, arrows…). */
    function specsOf(list) {
        var seen = {}, out = [];
        list.forEach(function (m) {
            var k = specKey(m.spec);
            if (seen[k]) return;
            seen[k] = true;
            out.push(Object.assign({duel: true}, m.spec));
        });
        return out;
    }

    K.layouts = function () {
        return matches().then(specsOf);
    };

    K.fit = function (a, spec, turn) {
        return DL.fit(env.V, a, spec, turn);
    };

    /** The best of the round's layouts on a grid (null when none fits). */
    function bestFit(a, specs, turn) {
        var best = null;
        specs.forEach(function (s) {
            var f = K.fit(a, s, turn);
            if (f && f.ok && (!best || f.score > best.score)) best = f;
        });
        return best;
    }

    // --------------------------------------------------------------- the sheet

    /** A millimetre of the printout, in a fit's frame units. */
    function mmOf(f) {
        return f.unit * f.model.width / DL.CARD_MM;
    }

    /**
     * The sheet read: its cards fitted, whose match told, then their cells read (each card's
     * own checks, then the match's). codes: those the browser read on the picture.
     */
    K.readSheet = function (gen, codes) {
        var st = env.state;
        return matches().then(function (list) {
            if (gen !== st.gen) return null;
            var specs = specsOf(list);
            st.sheet.forEach(function (ctx) {
                ctx.match = null;
                ctx.side = null;
                ctx.card = null;
                ctx.dfit = ctx.analysis ? bestFit(ctx.analysis, specs, null) : null;
            });
            // All cards of a sheet face one way: one fitted another way is fitted again that way.
            var ref = st.sheet.filter(function (c) { return c.dfit; }).sort(function (p, q) { return q.dfit.score - p.dfit.score; })[0];
            if (ref) {
                st.sheet.forEach(function (ctx) {
                    if (ctx.dfit && ctx.dfit.turn !== ref.dfit.turn && ctx.analysis) ctx.dfit = bestFit(ctx.analysis, specs, ref.dfit.turn) || ctx.dfit;
                });
            }
            var cards = byPlace(st.sheet.filter(function (c) { return c.dfit; }));
            st.sheet.forEach(function (ctx) {
                if (!ctx.dfit) {
                    ctx.step = 'wait';
                    env.show(ctx, [{kind: 'err', html: env.esc(env.t('LayoutMismatchDuel'))}]);
                }
            });
            if (!cards.length) return null;
            cards.forEach(function (ctx) { env.show(ctx, [env.busyLine(env.t('ReadingHeader'))]); });
            return identify(cards, list, codes || []).then(function (id) {
                if (gen !== st.gen) return null;
                if (!id) {
                    cards.forEach(function (ctx) {
                        ctx.step = 'wait';
                        env.show(ctx, [{kind: 'warn', html: env.esc(env.t('DuelUnknown')) + ' ' + pickSelect(ctx, list)}]);
                    });
                    return null;
                }
                return Promise.all(cards.map(function (ctx, i) { return open(ctx, id.match, id.sides[i], id.by); }));
            });
        }).then(function () {
            if (gen !== st.gen) return;
            st.working = false;
            env.renderSheet();
            env.drawPhoto();
        });
    };

    /** A sheet's cards in their places: along the cards' row, left first (the frame tells which way). */
    function byPlace(cards) {
        if (cards.length < 2) return cards;
        var H = cards[0].dfit.frame.Hfull, V = env.V, o = V.apply(H, 0, 0), u = V.apply(H, 100, 0);
        var d = Math.hypot(u.x - o.x, u.y - o.y) || 1, rx = (u.x - o.x) / d, ry = (u.y - o.y) / d;
        return cards.slice().sort(function (p, q) {
            var a = p.at || {x: 0, y: 0}, b = q.at || {x: 0, y: 0};
            return (a.x * rx + a.y * ry) - (b.x * rx + b.y * ry);
        });
    }

    /**
     * A card given its match and side: as ianseo holds it, fitted with its match's layout,
     * then read. -> a promise, settled once read.
     */
    function open(ctx, m, side, by) {
        ctx.match = m;
        ctx.side = side;
        ctx.card = cardOf(m, side);
        var f = ctx.dfit && specKey(ctx.dfit.spec) === specKey(m.spec) ? ctx.dfit : null;
        if (!f && ctx.analysis) {
            f = K.fit(ctx.analysis, m.spec, ctx.dfit ? ctx.dfit.turn : null);
            if (!f.ok && ctx.dfit) f = K.fit(ctx.analysis, m.spec, null);
        }
        ctx.fit = f;
        var s = m.sides[side], msgs = [{kind: 'ok', html: env.esc(env.t(by === 'barcode' ? 'DuelByBarcode' : by === 'operator' ? 'DuelPicked' : 'DuelByHeader',
            {phase: m.phase, event: m.eventName, name: s.name}))}];
        if (!f || !f.ok) {
            ctx.step = 'wait';
            env.show(ctx, msgs.concat([{kind: 'err', html: env.esc(env.t('LayoutMismatchDuel'))}]));
            return null;
        }
        ctx.step = 'busy';
        ctx.reading = null;
        ctx.seedCells = null;
        ctx.liveLocked = {};
        return env.readCells(ctx, msgs);
    }

    /** One side of a match as a card of the reading (the shape qualification.js reads and shows). */
    function cardOf(m, side) {
        var s = m.sides[side], entered = s.arrows.filter(function (v) { return v !== ''; }).length;
        return {
            archer: {enId: 'd:' + m.id + ':' + side, name: s.name, target: s.target, club: s.club, div: '', cls: '', session: 0},
            values: m.values,
            structure: Object.assign({goldsLabel: '10', xnineLabel: 'X'}, m.spec),
            ianseo: {arrows: s.arrows, letters: s.letters, entered: entered, score: s.score, gold: 0, xnine: 0,
                confirmed: m.confirmed, signed: false},
            duel: {match: m, side: side}
        };
    }

    /** The other card of a card's match, on the sheet. */
    function partnerOf(ctx) {
        return env.state.sheet.find(function (o) { return o !== ctx && o.match && ctx.match && o.match.id === ctx.match.id; }) || null;
    }

    /** A match chosen by the operator for a card (its side: as the sheet's other card, else its place). */
    function pickMatch(ctx, id) {
        if (!ctx) return;
        matches().then(function (list) {
            var m = list.find(function (x) { return x.id === id; });
            if (!m) return;
            var cards = byPlace(env.state.sheet.filter(function (c) { return c.dfit; }));
            var other = partnerOf(ctx) || cards.find(function (c) { return c !== ctx && c.match && c.match.id === m.id; });
            var side = other ? 1 - other.side : Math.max(0, cards.indexOf(ctx));
            // The sheet's other card, not known yet: the same match's other side.
            var rest = cards.filter(function (c) { return c !== ctx && !c.match; });
            Promise.all([open(ctx, m, Math.min(1, side), 'operator')].concat(rest.slice(0, 1).map(function (c) {
                return open(c, m, 1 - Math.min(1, side), 'operator');
            }))).then(function () {
                env.renderSheet();
                env.drawPhoto();
            });
        });
    }

    /** The round's matches to choose from, for a card not identified (a select, its column's). */
    function pickSelect(ctx, list) {
        return '<select data-duel-pick="' + ctx.n + '"><option value="">' + env.esc(env.t('DuelChoose')) + '</option>' + list.map(function (m) {
            return '<option value="' + env.esc(m.id) + '">' + env.esc(m.eventName + ' ' + m.phase + ' : ' + m.sides[0].name + ' / ' + m.sides[1].name) + '</option>';
        }).join('') + '</select>';
    }

    // ------------------------------------------------------------ identification

    /**
     * Whose match the sheet is: its barcode (the browser's reading, else ours, above the
     * right card), else the names printed above the cards. -> a promise of {match, sides (the
     * side of each card, in order), by} or null.
     */
    function identify(cards, list, codes) {
        var byCode = {};
        list.forEach(function (m) { byCode[m.barcode] = m; });
        var code = codes.map(function (c) { return c.text; }).find(function (txt) { return byCode[txt]; }) || scanCode(cards[cards.length - 1], byCode);
        // Both cards: left first, as printed. A lone card: its side told by its name.
        if (code && cards.length === 2) return Promise.resolve({match: byCode[code], sides: [0, 1], by: 'barcode'});
        return env.loadModels().then(function (models) {
            var id = byHeaders(models.text, cards, code ? [byCode[code]] : list, !!code);
            if (id && code) id.by = 'barcode';
            return id;
        }, function () { return null; });
    }

    /**
     * The sheet's barcode, read across the strip above the right card's header (the printout
     * puts it 40 to 50 mm above the table, left of the right logo). -> its text when it is one
     * of the round's matches, else null.
     */
    function scanCode(ctx, byCode) {
        var e = ctx.dfit, f = e.frame, V = env.V, B = env.B, Hf = f.Hfull, mm = mmOf(e), votes = {};
        var u0 = e.u(0) - 40 * mm, u1 = e.u(0) + 150 * mm;
        for (var k = 0; k <= 16; k++) {
            var v = e.v[0] - (53 - k) * mm, n = Math.max(600, Math.round(2 * V.fullLength(Hf, u0, v, u1, v)));
            var line = V.scanline(env.state.full, Hf, u0, u1, v, n);
            [B.decode(B.edgeRuns(line)), B.decode(B.runs(line))].forEach(function (txt) {
                if (txt && byCode[txt]) votes[txt] = (votes[txt] || 0) + 1;
            });
        }
        var best = null;
        Object.keys(votes).forEach(function (txt) { if (!best || votes[txt] > votes[best]) best = txt; });
        return best;
    }

    /**
     * Bands of a card's header, as the network reads them: the name's line (on a team card
     * of a bracket, at the heights its components' lines may put it) and the event's.
     */
    function headerBands(net, ctx) {
        var e = ctx.dfit, s = e.spec, Hf = e.frame.Hfull, mm = mmOf(e), V = env.V, X = env.X, full = env.state.full;
        var left = e.u(0), team = s.team && !s.robin, unit = DL.CARD_MM / e.model.width;
        // Right of the name: the rank's box (a total column wide on an individual card of a
        // bracket, two end columns elsewhere), and left of it the target's box on the event's line.
        var rank = !s.team && !s.robin ? 3 * unit : 2 * unit, target = (team ? 2.5 : 3) * unit;
        var band = function (top, right) {
            return X.run(net, X._internal.band(V, full, Hf, left, e.v[0] + (top - 0.5) * mm, left + right * mm, e.v[0] + (top + 6.5) * mm, net.height));
        };
        var tops = team ? [-25, -29, -33, -23] : [-25];
        // The round, centred in the table's title row (right of the Winner box and its word).
        var title = X.run(net, X._internal.band(V, full, Hf, e.u(1) + 22 * mm, e.v[0] + 0.1 * (e.v[1] - e.v[0]), e.u(e.model.width) - 2 * mm,
            e.v[1] - 0.1 * (e.v[1] - e.v[0]), net.height));
        return {
            names: tops.map(function (top) { return band(top, DL.CARD_MM - rank); }),
            cat: band(team ? -11 : -13, DL.CARD_MM - rank - target),
            title: title
        };
    }

    /** How far a text falls below a band's free reading, per character (lower: likelier). */
    function gap(net, out, text) {
        var X = env.X, txt = X.fold(text, net.chars);
        if (!txt) return 99;
        if (out._free === undefined) out._free = X.free(out);
        return (out._free - X.score(out, net, txt, true)) / (txt.length + 2);
    }

    function nameGap(net, b, name) {
        return Math.min.apply(null, b.names.map(function (o) { return gap(net, o, name); }));
    }

    /**
     * The match whose names (and event) the cards' headers read best: each card its side
     * (left first, or the other way if the sheet's order was misread). -> {match, sides, by} or null.
     */
    function byHeaders(net, cards, list, known) {
        var bands = cards.map(function (ctx) { return headerBands(net, ctx); });
        var tries = [];
        list.forEach(function (m) {
            var orders = cards.length === 2 ? [[0, 1], [1, 0]] : [[0], [1]];
            orders.forEach(function (sides) {
                var names = 0, worst = 0, cat = 0;
                sides.forEach(function (side, i) {
                    var s = m.sides[side], g = s.present ? nameGap(net, bands[i], s.name) : 99;
                    names += g;
                    worst = Math.max(worst, g);
                    cat += gap(net, bands[i].cat, m.eventName) + gap(net, bands[i].title, m.phase);
                });
                tries.push({match: m, sides: sides, score: names + CAT_WEIGHT * cat, worst: worst});
            });
        });
        tries.sort(function (p, q) { return p.score - q.score; });
        var best = tries[0];
        if (known) return best ? {match: best.match, sides: best.sides, by: 'header'} : null;
        if (!best || best.worst > GAP_NAME) return null;
        // The next match (not the same one the other way round) must read clearly worse.
        var next = tries.find(function (x) { return x.match.id !== best.match.id; });
        if (next && next.score - best.score < MARGIN_MATCH) return null;
        return {match: best.match, sides: best.sides, by: 'header'};
    }

    // ------------------------------------------------------------- the reading

    /** The cells the recognizer reads: not the set points' three cells, nor the boxes (measured here). */
    K.readable = function (c) {
        return c.kind !== 'pick' && c.kind !== 'box';
    };

    K.allowed = function (c, ctx) {
        return DL.cellAllowed(c, ctx.fit.spec, ctx.card.values);
    };

    /**
     * The ink of a cell's part: the share of its pixels darker than the paper by a margin, its
     * inside (inside: the share of each side left out), or with ring only the band around
     * its middle (where a circle drawn round a printed digit lies, the digit not).
     */
    function ink(ctx, u0, v0, u1, v1, inside, ring) {
        var V = env.V, Hf = ctx.fit.frame.Hfull, du = (u1 - u0) * inside, dv = (v1 - v0) * inside;
        var g = V.crop(env.state.full, Hf, u0 + du, v0 + dv, u1 - du, v1 - dv, 32, 32), d = g.d, n = d.length;
        var sorted = Array.prototype.slice.call(d).sort(function (a, b) { return a - b; }), paper = sorted[Math.floor(0.9 * n)], dark = 0, all = 0;
        for (var y = 0; y < 32; y++) {
            for (var x = 0; x < 32; x++) {
                if (ring && x >= 8 && x < 24 && y >= 8 && y < 24) continue;
                all++;
                if (d[y * 32 + x] < paper - 70) dark++;
            }
        }
        return dark / all;
    }

    /** The ink round and over each of a set points cell's three digits: {ring: [2, 1, 0], full: [2, 1, 0]}. */
    function pickInk(ctx, c) {
        return {
            ring: [0, 1, 2].map(function (k) { return ink(ctx, c.picks[k], c.v0, c.picks[k + 1], c.v1, 0.04, true); }),
            full: [0, 1, 2].map(function (k) { return ink(ctx, c.picks[k], c.v0, c.picks[k + 1], c.v1, 0.04, false); })
        };
    }

    /**
     * Which of the set points (2, 1, 0 printed) each end's cell marks, over the sheet's cards:
     * circled (the band round its digit dark: 0 to 0.04 for a digit alone, 0.05 to 0.2 circled)
     * or written over (the cell darker than the same digit printed alone, the lightest of the
     * sheet's: 0.03 to 0.07 more measured). -> readings [{value, p}] (-1: none) in reads.
     */
    function decidePicks(cards) {
        var base = [Infinity, Infinity, Infinity];
        cards.forEach(function (ctx) {
            Object.keys(ctx.reading.pickInk).forEach(function (line) {
                ctx.reading.pickInk[line].full.forEach(function (f, k) { base[k] = Math.min(base[k], f); });
            });
        });
        cards.forEach(function (ctx) {
            Object.keys(ctx.reading.pickInk).forEach(function (line) {
                var m = ctx.reading.pickInk[line], score = [0, 1, 2].map(function (k) { return Math.max(m.ring[k], m.full[k] - base[k]); });
                var order = [0, 1, 2].sort(function (x, y) { return score[y] - score[x]; }), top = score[order[0]], next = score[order[1]], values = [2, 1, 0];
                var r;
                if (top < PICK_INK) r = [{value: -1, p: 0.95}];
                else if (top < PICK_RATIO * next) r = [{value: values[order[0]], p: 0.5}, {value: values[order[1]], p: 0.4}, {value: -1, p: 0.1}];
                else {
                    var p = Math.min(0.99, 0.6 + 0.4 * (1 - next / top));
                    r = [{value: values[order[0]], p: p}, {value: values[order[1]], p: 1 - p}];
                }
                ctx.reading.reads[line + '.pick'] = r;
            });
        });
    }

    /** A printed box ticked or not, from the ink inside its border. -> [{value 1|0, p}] */
    function readBox(ctx, c) {
        var x = ink(ctx, c.u0, c.v0, c.u1, c.v1, 0.18), on = x >= BOX_INK, p = Math.min(0.99, 0.6 + Math.abs(x - BOX_INK) * 8);
        return [{value: on ? 1 : 0, p: p}, {value: on ? 0 : 1, p: 1 - p}];
    }

    /**
     * A card's cells read (qualification.js's readCells): the set points and boxes measured,
     * its arrows chosen by the checks, then its match worked out with the other card if read.
     */
    K.finish = function (ctx, reads, msgs, time) {
        var pickInks = {};
        ctx.fit.cells.forEach(function (c) {
            var k = c.line + '.' + c.key;
            if (c.kind === 'pick') pickInks[c.line] = pickInk(ctx, c);
            else if (c.kind === 'box') reads[k] = readBox(ctx, c);
            // A cell without ink is blank for sure: the networks, given nothing, answer their
            // prior (an 8 likelier than a blank), and the ends a match did not need are left so.
            else if (ink(ctx, c.u0, c.v0, c.u1, c.v1, 0.15) < EMPTY_INK) reads[k] = c.kind === 'arrow' ? [{label: '', p: 0.99}] : [{value: -1, p: 0.99}];
        });
        var values = {};
        ctx.card.values.forEach(function (v) { values[v.p] = {n: v.n, g: v.g, x: v.x}; });
        ctx.reading = {reads: reads, values: values, forced: {}, forcedNum: {}, forcedBox: {}, msgs: msgs, time: time, photo: {}, fusedWith: {},
            locked: 0, pickInk: pickInks};
        decidePicks([ctx]);
        K.resolve(ctx);
    };

    /** Each card's checks again (the operator's corrections standing for sure), then the match's. */
    K.resolve = function (ctx) {
        solveCard(ctx);
        heldAsRead(ctx);
        var other = partnerOf(ctx);
        if (other && other.reading && other.reading.sol) {
            decidePicks([ctx, other]);
            solveCard(other);
            heldAsRead(other);
            work(ctx, other);
            other.step = 'done';
            env.show(other, other.reading.msgs.concat(summary(other)));
        }
        ctx.step = 'done';
        env.show(ctx, ctx.reading.msgs.concat(summary(ctx)));
    };

    /** A card's arrows chosen by the qualification's checks: its end's or set's totals, its running total. */
    function solveCard(ctx) {
        var rd = ctx.reading, s = ctx.fit.spec, p = ctx.fit.printed, lines = [], r = rd.reads;
        for (var i = 1; i <= s.ends; i++) {
            var arrows = [];
            for (var j = 0; j < s.arrows; j++) {
                var f = rd.forced[i + '.' + j];
                if (!r[i + '.a' + j]) r[i + '.a' + j] = [{label: '', p: 1}];
                arrows.push(f === undefined ? r[i + '.a' + j] : [{label: f, p: 1}]);
            }
            lines.push({arrows: arrows, line: r[i + '.line'] || null, end: null, cum: s.mode && !s.direct ? null : r[i + '.cum'] || null,
                gold: r[i + '.gold'] || null, xnine: r[i + '.xnine'] || null});
        }
        var total = {cum: s.mode ? null : r['total.cum'] || null, gold: r['total.gold'] || null, xnine: r['total.xnine'] || null};
        rd.sol = env.S.solve(p, lines, total, rd.values, !!s.golds);
        rd.chosen = {};
        rd.sol.arrows.forEach(function (a) {
            if (rd.forced[a.line + '.' + a.index] !== undefined) a.forced = true;
            rd.chosen[a.line + '.' + a.index] = a;
        });
        // The shoot-off's arrows: each box's likeliest value (or the operator's).
        rd.so = [];
        for (var row = 0; row < s.soRows; row++) {
            for (var k = 0; k < s.so; k++) {
                var key = 'so' + row + '.' + k, got = rd.forced[key] !== undefined ? [{label: rd.forced[key], p: 1}] : r['so' + row + '.s' + k] || [{label: '', p: 1}];
                rd.so.push({row: row, index: k, value: got[0].label, conf: got[0].p, forced: rd.forced[key] !== undefined});
            }
        }
    }

    /**
     * What ianseo holds, each end in the order read when it holds the same arrows (an entry
     * may sort them; the score is the same): shown and compared as the same, and written back
     * as ianseo holds them. The shoot-off's lines likewise.
     */
    function heldAsRead(ctx) {
        var s = ctx.match.sides[ctx.side], rd = ctx.reading, spec = ctx.fit.spec, arrows = s.arrows.slice(), tb = s.tbArrows.slice();
        var same = function (read, held) {
            return read.some(function (v) { return v !== ''; }) && read.slice().sort().join(',') === held.slice().sort().join(',');
        };
        rd.sameEnd = {};
        rd.sameSo = {};
        for (var e = 1; e <= spec.ends; e++) {
            var read = [], at = (e - 1) * spec.arrows;
            for (var j = 0; j < spec.arrows; j++) read.push(rd.chosen[e + '.' + j] ? rd.chosen[e + '.' + j].value : '');
            if (same(read, arrows.slice(at, at + spec.arrows))) {
                rd.sameEnd[e] = true;
                for (j = 0; j < spec.arrows; j++) arrows[at + j] = read[j];
            }
        }
        for (var r = 0; r < spec.soRows; r++) {
            var row = rd.so.filter(function (o) { return o.row === r; }).map(function (o) { return o.value; }), t0 = r * spec.so;
            if (same(row, tb.slice(t0, t0 + spec.so))) {
                rd.sameSo[r] = true;
                for (var k = 0; k < spec.so; k++) tb[t0 + k] = row[k];
            }
        }
        ctx.card.ianseo.arrows = arrows;
        ctx.card.ianseo.tbShown = tb;
    }

    /** An arrow's points ('' blank: null). */
    function points(rd, v) {
        if (v === '' || v === undefined || v === null) return null;
        return rd.values[v] ? rd.values[v].n : null;
    }

    /** A box as read, or as the operator set it. */
    function boxOf(ctx, key) {
        var rd = ctx.reading, f = rd.forcedBox[key];
        if (f !== undefined) return {on: f, sure: true, forced: true};
        var r = rd.reads[key + '.' + key];
        return r ? {on: r[0].value === 1, sure: r[0].p >= 0.9} : {on: false, sure: false};
    }

    /**
     * The match worked out from both cards' arrows: each end's or set's totals, the set points
     * and their running total, the total, the shoot-off's totals, the winner; kept on each card
     * (ctx.duel) with what the card says of it.
     */
    function work(a, b) {
        var pair = a.side === 0 ? [a, b] : [b, a], s = a.fit.spec, E = s.ends, per = s.arrows;
        var ends = pair.map(function (ctx) {
            var rd = ctx.reading, out = [];
            for (var e = 1; e <= E; e++) {
                var sum = 0, any = false;
                for (var j = 0; j < per; j++) {
                    var ch = rd.chosen[e + '.' + j], n = ch ? points(rd, ch.value) : null;
                    if (n !== null) {
                        sum += n;
                        any = true;
                    }
                }
                out.push(any ? sum : null);
            }
            return out;
        });
        var res = [{}, {}], run = [0, 0];
        [0, 1].forEach(function (i) {
            res[i].pick = [];
            res[i].run = [];
            res[i].sum = 0;
        });
        for (var e = 0; e < E; e++) {
            var x = ends[0][e], y = ends[1][e];
            [0, 1].forEach(function (i) {
                var me = i ? y : x, op = i ? x : y;
                res[i].sum += me || 0;
                if (s.mode && me !== null && op !== null) {
                    var pt = me > op ? 2 : me < op ? 0 : 1;
                    run[i] += pt;
                    res[i].pick.push(pt);
                    res[i].run.push(run[i]);
                } else {
                    res[i].pick.push(null);
                    res[i].run.push(me !== null && s.mode ? run[i] : null);
                }
            });
        }
        // The shoot-off: each line's total (several arrows: their sum), compared line by line;
        // equal, the closest to the centre (as ticked) wins.
        var soVal = pair.map(function (ctx) {
            var rd = ctx.reading, rows = [];
            for (var r = 0; r < s.soRows; r++) {
                var sum = null;
                rd.so.filter(function (o) { return o.row === r; }).forEach(function (o) {
                    var n = points(rd, o.value);
                    if (n !== null) sum = (sum || 0) + n;
                });
                rows.push(sum);
            }
            return rows;
        });
        var soWin = null;
        for (var r = 0; r < s.soRows && soWin === null; r++) {
            var p = soVal[0][r], q = soVal[1][r];
            if (p === null || q === null) break;
            if (p !== q) soWin = p > q ? 0 : 1;
        }
        if (soWin === null && soVal[0][0] !== null && soVal[1][0] !== null) {
            var c0 = boxOf(pair[0], 'closest').on, c1 = boxOf(pair[1], 'closest').on;
            if (c0 !== c1) soWin = c0 ? 0 : 1;
        }
        var total = s.mode ? [run[0], run[1]] : [res[0].sum, res[1].sum], winner = null;
        if (total[0] !== total[1]) winner = total[0] > total[1] ? 0 : 1;
        else if (soWin !== null) winner = soWin;
        if (s.mode && soWin !== null && total[0] === total[1]) total[soWin]++;
        [0, 1].forEach(function (i) {
            pair[i].duel = {ends: ends[i], pick: res[i].pick, run: res[i].run, total: total[i], so: soVal[i], soWin: soWin, winner: winner === null ? null : winner === i};
        });
    }

    /** A card's summary lines: its arrows in doubt, what differs from ianseo, the match as read. */
    function summary(ctx) {
        var rd = ctx.reading, out = [], doubt = rd.sol.arrows.filter(function (a) { return !a.forced && !(a.conf >= env.SURE && !rd.sol.agreement.low); }).length;
        if (rd.sol.agreement.low) out.push({kind: 'err', html: env.esc(env.t('ReadUnsure'))});
        out.push({kind: doubt ? 'warn' : 'ok', summary: true, html: env.esc(env.t('DuelReadSummary', {n: rd.sol.arrows.filter(function (a) { return a.value !== ''; }).length, doubt: doubt}))});
        if (!partnerOf(ctx)) out.push({kind: 'warn', html: env.esc(env.t('DuelOtherCard'))});
        if (rd.time !== undefined) out.push({kind: 'info', html: env.esc(env.t('ReadTime', rd.time.toFixed(1)))});
        return out;
    }

    // ----------------------------------------------------------------- the cards

    /** The sheet's places: its two cards, the match's first side left. */
    K.placesOf = function () {
        var sheet = env.state.sheet, known = sheet.filter(function (c) { return c.side === 0 || c.side === 1; });
        var rest = byPlace(sheet.filter(function (c) { return c.dfit && known.indexOf(c) < 0; })).concat(sheet.filter(function (c) { return !c.dfit && known.indexOf(c) < 0; }));
        var slots = [null, null];
        known.forEach(function (c) { if (!slots[c.side]) slots[c.side] = c; else rest.push(c); });
        for (var i = 0; i < 2; i++) if (!slots[i] && rest.length) slots[i] = rest.shift();
        return slots.concat(rest).map(function (ctx) { return {letter: '', target: null, ctx: ctx, archer: null}; });
    };

    /**
     * Where a card stands, for its summary (as qualification.js's cardState()): its arrows, and
     * the match's total as read against what the arrows make.
     */
    K.cardState = function (ctx) {
        var rd = ctx.reading, x = arrowState(ctx), alerts = x.alerts, red = x.red;
        Object.keys(rd.sol.sheet || {}).forEach(function () { alerts++; });
        if (!rd.sol.agreement.low && Object.keys(rd.sol.sheet || {}).length) red = true;
        var totals = [], d = ctx.duel, s = ctx.fit.spec;
        var tot = numberRead(ctx, 'total', s.mode ? 'pts' : 'cum');
        var label = env.t(s.mode ? 'DuelPoints' : 'TotScore');
        if (d) {
            var kind = 'check', tone = x.sure ? 'ok' : 'doubt', title = env.t(x.sure ? 'TotOkSure' : 'TotOkDoubt');
            if (tot === null) {
                kind = 'warn';
                tone = 'bad';
                title = env.t('EyeMissing');
            } else if (tot !== d.total) {
                kind = 'cross';
                tone = x.sure ? 'bad' : 'doubt';
                title = env.t(x.sure ? 'TotCalcSure' : 'TotCalcDoubt', {read: tot, want: d.total});
            }
            if (kind !== 'check') alerts++;
            totals.push({key: 'total', label: label, value: tot === null ? '-' : tot, kind: kind, tone: tone, title: title});
            var w = boxOf(ctx, 'winner');
            if (d.winner !== null && w.on !== d.winner) alerts++;
        }
        var eye = !rd.sol.arrows.length ? 'none' : x.sure && tot !== null ? 'ok' : x.all ? 'doubt' : 'bad';
        return {colour: red ? 'bad' : x.sure && d && tot === d.total ? 'ok' : 'doubt', eye: eye, totals: totals, alerts: alerts};
    };

    /**
     * A card's arrows for its summary: all sure, all read, how many differ from ianseo, and
     * whether one read for sure does (red).
     */
    function arrowState(ctx) {
        var out = {sure: true, all: true, alerts: 0, red: false};
        ctx.reading.sol.arrows.forEach(function (a) {
            var x = env.arrowOf(ctx, a.line, a.index);
            if (!x.sure) out.sure = false;
            if (!x.read) out.all = false;
            if (x.held !== '' && x.held !== a.value) {
                out.alerts++;
                if (x.sure) out.red = true;
            }
        });
        return out;
    }

    /** A number of the card as read (the operator's value first): null when not read, 0 for a blank total. */
    function numberRead(ctx, line, key) {
        var rd = ctx.reading, k = line + '.' + key;
        if (rd.forcedNum[k] !== undefined) return rd.forcedNum[k];
        var r = rd.reads[k];
        if (!r || !r.length) return null;
        return r[0].value === -1 ? (line === 'total' ? 0 : null) : r[0].value;
    }

    /** A card's cells, as on the sheet: its ends, then its total, the shoot-off and the boxes. */
    K.cardCells = function (ctx) {
        var f = ctx.fit, s = f.spec, by = {}, cells = f.cells, t = env.t, esc = env.esc;
        cells.forEach(function (c) { (by[c.line] = by[c.line] || {})[c.key] = c; });
        var keys = [];
        for (var j = 0; j < s.arrows; j++) keys.push('a' + j);
        keys.push('line');
        if (s.mode) keys.push('pick', 'run');
        else keys.push('cum');
        var heads = {line: t(s.mode ? 'DuelSetTotal' : 'ColEndTotal'), pick: t('DuelPoints'), run: t('DuelRun'), cum: t('ColRunning')};
        var table = document.createElement('table');
        table.className = 'ocr-grid ocr-duel';
        var head = '<tr><th></th>';
        keys.forEach(function (k) { head += '<th>' + esc(k.charAt(0) === 'a' ? String(+k.slice(1) + 1) : heads[k]) + '</th>'; });
        table.innerHTML = '<thead>' + head + '</tr></thead>';
        var body = document.createElement('tbody');
        for (var e = 1; e <= s.ends; e++) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<th>' + e + '</th>';
            keys.forEach(function (k) {
                var td = document.createElement('td'), c = by[e] && by[e][k];
                if (!c) td.className = 'ocr-crossed';
                else if (k.charAt(0) === 'a') env.arrowCell(ctx, td, c, e, +k.slice(1));
                else if (k === 'pick') pickCell(ctx, td, c, e);
                else if (k === 'run') duelNumber(ctx, td, c, e, 'run');
                else env.numberCell(ctx, td, c, e, k);
                tr.appendChild(td);
            });
            body.appendChild(tr);
        }
        // The total row: the card's actions beside its total.
        var tt = document.createElement('tr'), span = keys.length - 1;
        tt.innerHTML = '<th><span class="ocr-vert">' + esc(t('RowTotal')) + '</span></th>';
        var acts = document.createElement('td');
        acts.className = 'ocr-none ocr-acts';
        acts.colSpan = span;
        acts.innerHTML = env.cardActions(ctx);
        tt.appendChild(acts);
        var ttd = document.createElement('td'), tc = by.total && by.total[s.mode ? 'pts' : 'cum'];
        if (tc && s.mode) duelNumber(ctx, ttd, tc, 'total', 'pts');
        else if (tc) env.numberCell(ctx, ttd, tc, 'total', 'cum');
        tt.appendChild(ttd);
        body.appendChild(tt);
        table.appendChild(body);
        var wrap = document.createElement('div');
        wrap.className = 'ocr-scroll';
        wrap.appendChild(table);
        wrap.appendChild(extraTable(ctx, by));
        return wrap;
    };

    /** The shoot-off's boxes, the closest box and the Winner box, under the card's table. */
    function extraTable(ctx, by) {
        var s = ctx.fit.spec, t = env.t, esc = env.esc, table = document.createElement('table'), body = document.createElement('tbody');
        table.className = 'ocr-grid ocr-duel-extra';
        for (var r = 0; r < s.soRows; r++) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<th>' + esc(r ? '' : t('DuelShootOff')) + '</th>';
            for (var k = 0; k < s.so; k++) {
                var td = document.createElement('td'), c = by['so' + r] && by['so' + r]['s' + k];
                if (c) soCell(ctx, td, c, r, k);
                tr.appendChild(td);
            }
            if (s.soTotal) {
                var st = document.createElement('td'), sc = by['so' + r] && by['so' + r].st;
                if (sc) duelNumber(ctx, st, sc, 'so' + r, 'st');
                tr.appendChild(st);
            }
            body.appendChild(tr);
        }
        [['closest', 'DuelClosest'], ['winner', 'DuelWinner']].forEach(function (b) {
            var tr = document.createElement('tr'), td = document.createElement('td'), c = by[b[0]] && by[b[0]][b[0]];
            tr.innerHTML = '<th>' + esc(t(b[1])) + '</th>';
            td.colSpan = s.so + (s.soTotal ? 1 : 0);
            if (c) boxCell(ctx, td, c, b[0]);
            tr.appendChild(td);
            body.appendChild(tr);
        });
        table.appendChild(body);
        return table;
    }

    /** A crop and what was read under it, framed as the qualification cells are. */
    function cropInto(ctx, td, c) {
        td.appendChild(env.cropOf(ctx, c, env.cropH()));
    }

    /** The set points circled (2, 1 or 0) against those the two cards' totals give (orange when not: a check). */
    function pickCell(ctx, td, c, e) {
        cropInto(ctx, td, c);
        var rd = ctx.reading, k = e + '.pick', r = rd.reads[k], read = rd.forcedNum[k] !== undefined ? rd.forcedNum[k] : r ? r[0].value : null;
        var want = ctx.duel ? ctx.duel.pick[e - 1] : null;
        var sp = document.createElement('span');
        sp.className = 'ocr-read' + (want === null || read === null ? '' : read === want ? ' ocr-ok' : ' ocr-bad');
        sp.innerHTML = env.esc(read === null || read === -1 ? '' : String(read)) + '/<small class="ocr-calc">' + env.esc(want === null ? '-' : String(want)) + '</small>';
        td.appendChild(sp);
        if (want !== null && read !== null && read !== -1 && read !== want) td.classList.add('ocr-diff', 'ocr-diff-check');
    }

    /** A number only the duel has (running set points, their total, a shoot-off's total): read / worked out. */
    function duelNumber(ctx, td, c, line, key) {
        cropInto(ctx, td, c);
        var read = numberRead(ctx, line, key), d = ctx.duel, want = null;
        if (d && key === 'run') want = d.run[line - 1];
        else if (d && key === 'pts') want = d.total;
        else if (d && key === 'st') want = d.so[+String(line).slice(2)];
        var sp = document.createElement('span');
        sp.className = 'ocr-read' + (want === null || read === null ? '' : read === want ? ' ocr-ok' : ' ocr-bad');
        sp.innerHTML = env.esc(read === null ? '' : String(read)) + '/<small class="ocr-calc">' + env.esc(want === null ? '-' : String(want)) + '</small>';
        td.appendChild(sp);
        // Red for the total (written into ianseo), orange for what only checks it.
        if (want !== null && read !== null && read !== want) {
            td.classList.add('ocr-diff');
            if (key !== 'pts') td.classList.add('ocr-diff-check');
        }
    }

    /** A shoot-off arrow: its value (a click offers the face's values, as for an arrow). */
    function soCell(ctx, td, c, row, k) {
        cropInto(ctx, td, c);
        var o = ctx.reading.so.find(function (x) { return x.row === row && x.index === k; });
        var sp = document.createElement('span');
        sp.className = 'ocr-read' + (o && o.value !== '' && (o.forced || o.conf >= env.SURE) ? ' ocr-ok' : '') + (o && o.forced ? ' ocr-forced' : '');
        sp.textContent = o ? o.value : '';
        td.appendChild(sp);
        td.classList.add('ocr-editable');
        td.addEventListener('click', function () { env.palette(td, ctx, 'so' + row, k); });
    }

    /** A box: ticked or not as read (a click turns it), against the match's result. */
    function boxCell(ctx, td, c, key) {
        cropInto(ctx, td, c);
        var b = boxOf(ctx, key), d = ctx.duel, want = d && key === 'winner' ? d.winner : null;
        var sp = document.createElement('span');
        sp.className = 'ocr-read' + (b.forced ? ' ocr-forced' : want === null ? '' : b.on === want ? ' ocr-ok' : ' ocr-bad');
        sp.textContent = b.on ? '☒' : '☐';
        td.appendChild(sp);
        td.setAttribute('data-duel-box', key);
        td.setAttribute('data-of', String(ctx.n));
        td.classList.add('ocr-editable');
        if (want !== null && b.on !== want) td.classList.add('ocr-diff');
    }

    function toggleBox(ctx, key) {
        if (!ctx || !ctx.reading) return;
        ctx.reading.forcedBox[key] = !boxOf(ctx, key).on;
        K.resolve(ctx);
    }

    // ------------------------------------------------------------- into ianseo

    /**
     * What can be written of a card's match (both its cards together): {kind: 'import' |
     * 'validate', blocked}, {done} once confirmed; null while not read.
     */
    K.writeState = function (ctx) {
        if (!ctx || !ctx.card || !ctx.reading || !ctx.fit || !ctx.fit.ok || ctx.step !== 'done' || !env.root.getAttribute('data-duel-write')) return null;
        if (ctx.written) return {done: ctx.written};
        var m = ctx.match, other = partnerOf(ctx);
        if (m.confirmed) return {done: 'validated'};
        var whole = [0, 1].every(function (i) { return heldWhole(m, i, other && other.side === i ? other : ctx.side === i ? ctx : null); });
        var kind = whole ? 'validate' : 'import';
        if (!other || !other.reading || other.step !== 'done') return {kind: kind, blocked: env.t('DuelOtherCard')};
        if (ctx.writing || other.writing) return {kind: kind, blocked: env.t('Writing')};
        var pair = [ctx, other];
        if (pair.some(function (c) { var st = env.cardStateOf(c); return st.eye === 'bad' || st.eye === 'none'; })) return {kind: kind, blocked: env.t('EyeMissing')};
        var off = pair.reduce(function (n, c) { return n + offArrows(c); }, 0);
        if (off) return {kind: kind, blocked: env.t('ValidateNeedEdit', off)};
        if (kind === 'validate' && ctx.duel && ctx.duel.winner === null) return {kind: kind, blocked: env.t('DuelNoWinner')};
        return {kind: kind};
    };

    /** ianseo holds every arrow a side's card was read with (the shoot-off's too). */
    function heldWhole(m, side, ctx) {
        if (!ctx || !ctx.reading) return false;
        var s = m.sides[side], rd = ctx.reading;
        var arrowsOk = rd.sol.arrows.every(function (a) { return a.value === '' || s.arrows[(a.line - 1) * ctx.fit.spec.arrows + a.index] !== ''; });
        var soOk = rd.so.every(function (o) { return o.value === '' || s.tbArrows[o.row * ctx.fit.spec.so + o.index] !== ''; });
        return arrowsOk && soOk && rd.sol.arrows.some(function (a) { return a.value !== ''; });
    }

    /** Arrows ianseo holds otherwise than read, but those the operator corrected. */
    function offArrows(ctx) {
        var i = ctx.card.ianseo, rd = ctx.reading, per = ctx.fit.spec.arrows, n = 0;
        rd.sol.arrows.forEach(function (a) {
            var h = i.arrows[(a.line - 1) * per + a.index] || '';
            if (!a.forced && h !== '' && h !== a.value) n++;
        });
        rd.so.forEach(function (o) {
            var h = (i.tbShown || [])[o.row * ctx.fit.spec.so + o.index] || '';
            if (!o.forced && h !== '' && h !== o.value) n++;
        });
        return n;
    }

    /** A side's arrows and shoot-off as letters of the face (a value ianseo holds as it is, in its own letter). */
    function sideOf(ctx) {
        var m = ctx.match, s = m.sides[ctx.side], rd = ctx.reading, spec = ctx.fit.spec, byPrint = {};
        ctx.card.values.forEach(function (v) { byPrint[v.p] = v.l; });
        var letter = function (v, held, heldLetter) {
            if (v === '') return ' ';
            return heldLetter !== ' ' && heldLetter !== '' && held === v ? heldLetter : byPrint[v] || ' ';
        };
        var letters = '', tb = '', edited = [], tbEdited = [];
        for (var e = 1; e <= spec.ends; e++) {
            for (var j = 0; j < spec.arrows; j++) {
                var at = (e - 1) * spec.arrows + j, a = rd.chosen[e + '.' + j];
                // An end ianseo holds as read, in its own order maybe: left as it holds it.
                if (rd.sameEnd && rd.sameEnd[e] && !(a && a.forced)) letters += s.letters.charAt(at) || ' ';
                else letters += letter(a ? a.value : '', s.arrows[at], s.letters.charAt(at));
                if (a && a.forced) edited.push(at);
            }
        }
        rd.so.forEach(function (o) {
            var at = o.row * spec.so + o.index;
            if (rd.sameSo && rd.sameSo[o.row] && !o.forced) tb += s.tb.charAt(at) || ' ';
            else tb += letter(o.value, s.tbArrows[at], s.tb.charAt(at));
            if (o.forced) tbEdited.push(at);
        });
        return {letters: letters, tb: tb, closest: boxOf(ctx, 'closest').on, edited: edited, tbEdited: tbEdited};
    }

    /** A match written (both cards), as writeState() allows: imported, or validated (confirmed). */
    K.writeCard = function (ctx) {
        var w = K.writeState(ctx), other = partnerOf(ctx);
        if (!w || w.done || w.blocked || !other) return Promise.resolve(false);
        var pair = ctx.side === 0 ? [ctx, other] : [other, ctx];
        var body = new URLSearchParams({action: w.kind, id: ctx.match.id, sides: JSON.stringify(pair.map(sideOf)), csrf: env.root.getAttribute('data-csrf') || ''});
        pair.forEach(function (c) {
            c.writing = true;
            c.writeError = null;
            c.writeFailed = false;
        });
        env.renderSheet();
        var end = function (j) {
            pair.forEach(function (c) {
                c.writing = false;
                if (j && j.match) {
                    c.match = j.match;
                    c.card = cardOf(j.match, c.side);
                }
                if (!j || j.error) {
                    c.writeError = j && j.msg ? j.msg : env.t('ErrRequest');
                    c.writeFailed = true;
                } else {
                    c.written = w.kind === 'import' ? 'imported' : 'validated';
                }
            });
            env.recLog({f: 'write', kind: w.kind, match: ctx.match.id, ok: !!(j && !j.error), msg: ctx.writeError || ''});
            env.renderSheet();
            env.sheetWritten();
            return !!(j && !j.error);
        };
        return fetch(env.api, {method: 'POST', credentials: 'same-origin', body: body})
            .then(function (r) { return r.json(); }).then(end, function () { return end(null); });
    };

    /** A card whose grid was looked for again (Reread): read again on its match, or identified afresh. */
    K.reopen = function (ctx) {
        return matches().then(function (list) {
            var specs = specsOf(list);
            ctx.dfit = ctx.analysis ? bestFit(ctx.analysis, specs, ctx.fit ? ctx.fit.turn : null) || bestFit(ctx.analysis, specs, null) : null;
            if (ctx.match && ctx.dfit) return open(ctx, ctx.match, ctx.side, 'operator');
            return K.readSheet(env.state.gen, []);
        });
    };

    window.OcrKind = K;
})();
