/**
 * Arithmetic checks of a read scorecard: the arrows chosen so that the totals the
 * archer wrote hold, as far as the readings allow.
 *
 * Each arrow cell comes with a few readings and their probabilities, each written
 * number (line sum, end total, running total, counts of 10s and Xs, total row) with
 * a few readings too. Every printed line gets the most likely combinations of its
 * arrows; the lines of an end are put together; the ends are chained by the running
 * total, by dynamic programming over its value. The score of a choice is the
 * likelihood of the arrows read, times that of the written numbers given the arrows:
 * a written number that disagrees costs, without forbidding it (the archer may have
 * added wrong: the arrows are what counts).
 *
 * The archer's own running total is followed along: one slip in an addition is carried
 * on to every later running total and to the total row, so a sheet whose totals are
 * all off from one end onwards is told apart from a single misread number.
 *
 * Out: the value chosen for every arrow, its confidence (forward-backward over the
 * same chain), and the written numbers that disagree with the arrows (a mistake on
 * the sheet).
 *
 * Browser and Node alike, no dependency.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.OcrSolver = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var MISS = Math.log(0.01);    // a written number none of the readings gives
    var KEEP_ARROW = 6;           // readings kept per arrow cell
    // Softening of the arrow probabilities (p to the power 1/T). On synthetic cards 2 was
    // best; on real ones, with the numbers read better (margin, sequence reader), 1.25:
    // as many arrows right, fewer wrong among those shown sure.
    var TEMPERATURE = 1.25;
    // Softening of the written numbers' readings, as for the arrows: a reader sure of a
    // wrong number would make the checks bend the arrows to it. 2 measured as 1 on real
    // and synthetic cards: kept at 1, the hook left for another reader.
    var NUMBER_TEMPERATURE = 1;
    var KEEP_LINE = 60;           // combinations kept per printed line
    var KEEP_END = 80;            // combinations kept per end
    var SLIP = Math.log(0.005);   // the archer adds wrong at one running total
    var MAX_SLIP = 60;            // furthest a written running total is taken to be off
    var COUNT_SLIP = 0.01;        // a count of the total row written wrong
    var PRUNE = 30;               // chain states this far below the best are dropped
    var MAX_STATES = 1500;        // chain states kept per end at most
    var AGREE_MIN = 0.75;         // written numbers agreeing with the arrows, below which nothing is sure
    var AGREE_COUNT = 8;          // ... when at least that many numbers are written
    var AGREE_P = 0.1;            // a reading that likely, at least, must give the value
    // A count of tens or Xs left blank: often for none, but archers forget to fill it in
    // too. Blank weighs this much for a count above zero, against one for none.
    var FORGOT = 0.3;
    // An end shot on two lines or more: the first line's sum cell left blank and the end's
    // total written in a line's sum cell instead (what the archer is told otherwise).
    var END_IN_LINE = 0.05;
    // An end where the checks have the archer's running total go wrong: as likely a value
    // misread with assurance, that adds up with a misread sum. Its arrows' confidence is
    // held below anything shown sure.
    var SLIP_SURE = 0.9;
    // The ends' totals and running totals read blank on more than this share of the ends
    // shot: the grid is likely set a line off (those cells fall where the core crosses them
    // out), its sums agreeing with the arrows of the line next to theirs.
    var EMPTY_TOTALS = 0.5;
    // Archers write an end's arrows highest first, nearly always: on ends of two lines or
    // more, the arrows chosen out of that order in more than this share of the ends (three
    // at least) tell a grid set a line off (each end then starts with the last line of the
    // one before). Ends of one line (18 m) tell nothing.
    var DISORDER = 0.5;
    // The running total written across the end's total cell and its own: its hundreds in
    // the first, the rest in the second ("1 | 12" for 112). One person writes a whole sheet,
    // so the sheet is read that way when its numbers then agree better; tried only when they
    // agree less than this, with at least SPLIT_MIN ends looking so.
    var SPLIT_TRY = 0.9;
    var SPLIT_MIN = 2;

    function lse(a, b) {
        if (a === -Infinity) return b;
        if (b === -Infinity) return a;
        var m = Math.max(a, b);
        return m + Math.log(Math.exp(a - m) + Math.exp(b - m));
    }

    /** Log-likelihood of a written number given the value it should have. */
    function numberScore(readings, value, isCount) {
        if (!readings) return 0;
        var p = 0, total = 0;
        readings.forEach(function (r) {
            var q = Math.pow(Math.max(1e-9, r.p), 1 / NUMBER_TEMPERATURE);
            total += q;
            if (r.value === -1 && isCount) p += value === 0 ? q : FORGOT * q;
            else if (r.value === value) p += q;
        });
        return p > 0 ? Math.log(p / total) : MISS;
    }

    /**
     * Arrow readings restricted to the values the target face allows (and empty).
     *
     * @param {Array} reading [{label, p}] from OcrRecognizer.readArrow().
     * @param {Object} values Print value -> {n, g, x}.
     */
    function arrowOptions(reading, values) {
        var opts = reading.filter(function (r) { return r.label === '' || values[r.label]; }).map(function (r) {
            return {label: r.label, p: Math.pow(Math.max(1e-6, r.p), 1 / TEMPERATURE)};
        });
        var s = opts.reduce(function (a, r) { return a + r.p; }, 0) || 1;
        return opts.slice(0, KEEP_ARROW).map(function (r) {
            var v = r.label === '' ? {n: 0, g: false, x: false} : values[r.label];
            return {label: r.label, lp: Math.log(Math.max(1e-6, r.p / s)), n: v.n, g: v.g ? 1 : 0, x: v.x ? 1 : 0};
        });
    }

    /** Most likely combinations of one printed line's arrows, with their sums and counts. */
    function lineCombos(arrows) {
        var combos = [{labels: [], lp: 0, sum: 0, g: 0, x: 0}];
        arrows.forEach(function (opts) {
            var next = [];
            combos.forEach(function (c) {
                opts.forEach(function (o) {
                    next.push({labels: c.labels.concat([o.label]), lp: c.lp + o.lp, sum: c.sum + o.n, g: c.g + o.g, x: c.x + o.x});
                });
            });
            combos = next.sort(function (a, b) { return b.lp - a.lp; }).slice(0, KEEP_LINE);
        });
        return combos;
    }

    function key(t, d) { return t * 4096 + d + 2048; }

    /** The printed line that closes an end, which holds its running total. */
    function closing(endLines) { return endLines[endLines.length - 1]; }

    /** An end's candidates grouped by their sum: what the running total sees of them. */
    function bySum(list) {
        var m = new Map();
        list.forEach(function (c, i) {
            var g = m.get(c.sum);
            if (!g) m.set(c.sum, g = {sum: c.sum, max: -Infinity, lse: -Infinity, best: -1});
            if (c.lp > g.max) {
                g.max = c.lp;
                g.best = i;
            }
            g.lse = lse(g.lse, c.lp);
        });
        return m;
    }

    /**
     * How a running total (or the total row) whose true value is t may have been written,
     * when the archer's was off by d so far: added right to the last one (still off by d),
     * or a new slip to one of the values read. -> [[new d, log-likelihood]]
     */
    function cumSteps(read, t, d) {
        if (!read) return [[d, 0]];
        var out = [[d, numberScore(read, t + d, false)]], seen = {};
        read.forEach(function (r) {
            if (r.value >= 0) seen[r.value] = (seen[r.value] || 0) + r.p;
        });
        Object.keys(seen).forEach(function (vs) {
            var v = +vs;
            if (v !== t + d && Math.abs(v - t) <= MAX_SLIP) out.push([v - t, SLIP + Math.log(seen[vs])]);
        });
        return out;
    }

    /** The value of a written count that disagrees with the arrows, or null (left blank: none). */
    function countSlip(read, want) {
        var mass = {}, topV = null;
        read.forEach(function (r) {
            if (r.value !== -1) mass[r.value] = (mass[r.value] || 0) + r.p;
        });
        Object.keys(mass).forEach(function (vs) { if (topV === null || mass[vs] > mass[topV]) topV = vs; });
        if (topV === null || +topV === want) return null;
        var right = Math.max(mass[want] || 0, Math.exp(MISS));
        return COUNT_SLIP * mass[topV] > (1 - COUNT_SLIP) * right ? +topV : null;
    }

    /**
     * A running total's readings, its first digits taken from the end's total cell: a digit
     * there makes the hundreds ("1 | 12", "1 | 05"), two make the hundreds and tens when one is
     * left in the running total's cell ("24 | 9", the number written over the line between);
     * blank (or anything else) leaves the running total as read.
     */
    function joined(endRead, cumRead) {
        var mass = {}, all = 0;
        endRead.forEach(function (r1) {
            cumRead.forEach(function (r2) {
                var a = r1.value, b = r2.value, v = b;
                if (b >= 0 && a >= 1 && a <= 9 && b <= 99) v = a * 100 + b;
                else if (b >= 0 && a >= 10 && a <= 99 && b <= 9) v = a * 10 + b;
                mass[v] = (mass[v] || 0) + r1.p * r2.p;
                all += r1.p * r2.p;
            });
        });
        return Object.keys(mass).map(function (v) { return {value: +v, p: mass[v] / (all || 1)}; })
            .sort(function (a, b) { return b.p - a.p; });
    }

    /**
     * The sheet's lines read with their running totals across two cells (joined()), or null
     * when fewer than SPLIT_MIN ends look so (a digit in the end's total cell, a running
     * total under a hundred in its own). -> {lines, split: {printed line: readings}}
     */
    function splitSheet(lines, perEnd) {
        var out = lines.slice(), split = {}, n = 0;
        for (var i = perEnd - 1; i < lines.length; i += perEnd) {
            var ln = lines[i];
            if (!ln.end || !ln.cum || !ln.end.length || !ln.cum.length) continue;
            var top = ln.end[0], cum = ln.cum[0];
            if (top.value >= 1 && top.value <= 9 && cum.value >= 0 && cum.value <= 99) n++;
            var copy = Object.assign({}, ln);
            copy.cum = joined(ln.end, ln.cum);
            copy.end = null;
            out[i] = copy;
            split[i + 1] = copy.cum;
        }
        return n >= SPLIT_MIN ? {lines: out, split: split} : null;
    }

    /**
     * Solve a scorecard; when its numbers agree little, read again with the running totals
     * written across two cells (splitSheet()), kept if they then agree better. The result
     * says so (split: printed line -> the running total's readings, its end's total cell
     * being part of it).
     *
     * @param {Object} p Printed layout (OcrLayout.printed()).
     * @param {Array} lines Per printed line: {arrows: [reading…], line, end, cum, gold, xnine}
     *                (number readings, any may be missing).
     * @param {Object} total Total row readings: {cum, gold, xnine}.
     * @param {Object} values Print value -> {n, g, x}.
     * @param {boolean} golds Whether the 10s column exists.
     */
    function solve(p, lines, total, values, golds) {
        var sol = solveLines(p, lines, total, values, golds);
        if (sol.agreement.share >= SPLIT_TRY && !sol.agreement.low) return sol;
        // (Requiring the ends that look so to give, read together, the running total chosen
        // kept the poor readings and lost the true ones: a number written over the line is
        // often cut, "2 | 0" for 210.)
        var s = splitSheet(lines, Math.max(1, Math.round(p.arrows / p.perLine)));
        if (!s) return sol;
        var other = solveLines(p, s.lines, total, values, golds);
        if (other.agreement.share <= sol.agreement.share || other.agreement.low && !sol.agreement.low) return sol;
        other.split = s.split;
        return other;
    }

    function solveLines(p, lines, total, values, golds) {
        var perEnd = Math.max(1, Math.round(p.arrows / p.perLine)), ends = [];
        for (var i = 0; i < lines.length; i += perEnd) ends.push(lines.slice(i, i + perEnd));

        // Candidates of each end: the combinations of its lines, scored by what the
        // archer wrote on each line, then on the end's last line.
        var cands = ends.map(function (endLines) {
            var list = [{parts: [], lp: 0, sum: 0, g: 0, x: 0}];
            endLines.forEach(function (ln) {
                var combos = lineCombos(ln.arrows.map(function (r) { return arrowOptions(r, values); }));
                var next = [];
                list.forEach(function (c) {
                    combos.forEach(function (k) {
                        var lp = c.lp + k.lp;
                        if (ln.line) lp += numberScore(ln.line, k.sum, false);
                        if (golds && ln.gold) lp += numberScore(ln.gold, k.g, true);
                        if (ln.xnine) lp += numberScore(ln.xnine, k.x, true);
                        next.push({parts: c.parts.concat([k]), lp: lp, sum: c.sum + k.sum, g: c.g + k.g, x: c.x + k.x});
                    });
                });
                list = next.sort(function (a, b) { return b.lp - a.lp; }).slice(0, KEEP_END);
            });
            var last = endLines[endLines.length - 1];
            if (last.end) list.forEach(function (c) { c.lp += numberScore(last.end, c.sum, false); });
            // A line's sum cell may hold the end's total: scored again, that way too.
            if (endLines.length > 1) {
                list.forEach(function (c) {
                    endLines.forEach(function (ln, li) {
                        if (!ln.line) return;
                        var asLine = numberScore(ln.line, c.parts[li].sum, false), asEnd = numberScore(ln.line, c.sum, false);
                        c.lp += lse(Math.log(1 - END_IN_LINE) + asLine, Math.log(END_IN_LINE) + asEnd) - asLine;
                    });
                });
            }
            return list;
        });

        // Chain by the running total. A state is the true running total and how far the
        // archer's own running total is off (0 until a slip; carried on after it, since
        // each one is added to the last one written): forward (max and sum), then backward.
        var E = ends.length, F = [], groups = cands.map(bySum);
        var start = new Map([[key(0, 0), {t: 0, d: 0, max: 0, sum: 0}]]), prev = start;
        for (var e = 0; e < E; e++) {
            var read = closing(ends[e]).cum, cur = new Map(), top = -Infinity;
            prev.forEach(function (st, pk) {
                groups[e].forEach(function (g) {
                    var t = st.t + g.sum;
                    cumSteps(read, t, st.d).forEach(function (step) {
                        var nk = key(t, step[0]), n = cur.get(nk);
                        if (!n) cur.set(nk, n = {t: t, d: step[0], max: -Infinity, sum: -Infinity, from: null, cand: -1});
                        if (st.max + g.max + step[1] > n.max) {
                            n.max = st.max + g.max + step[1];
                            n.from = pk;
                            n.cand = g.best;
                        }
                        n.sum = lse(n.sum, st.sum + g.lse + step[1]);
                        if (n.max > top) top = n.max;
                    });
                });
            });
            cur.forEach(function (n, nk) { if (n.max < top - PRUNE) cur.delete(nk); });
            if (cur.size > MAX_STATES) {
                // Readings so poor that many totals and slips stay alike: keep the best.
                var kept = Array.from(cur.entries()).sort(function (p, q) { return q[1].max - p[1].max; }).slice(0, MAX_STATES);
                cur = new Map(kept);
            }
            F.push(cur);
            prev = cur;
        }

        // The total row closes the chain.
        var totRead = total && total.cum, Z = -Infinity, best = null, bestScore = -Infinity, bestStep = null;
        F[E - 1].forEach(function (n, nk) {
            n.bwd = -Infinity;
            cumSteps(totRead, n.t, n.d).forEach(function (step) {
                n.bwd = lse(n.bwd, step[1]);
                if (n.max + step[1] > bestScore) {
                    bestScore = n.max + step[1];
                    best = nk;
                    bestStep = step;
                }
            });
            Z = lse(Z, n.sum + n.bwd);
        });
        for (e = E - 1; e > 0; e--) {
            var readB = closing(ends[e]).cum, next = F[e];
            F[e - 1].forEach(function (st) {
                var acc = -Infinity;
                groups[e].forEach(function (g) {
                    var t = st.t + g.sum;
                    cumSteps(readB, t, st.d).forEach(function (step) {
                        var n = next.get(key(t, step[0]));
                        if (n) acc = lse(acc, g.lse + step[1] + n.bwd);
                    });
                });
                st.bwd = acc;
            });
        }

        // The choice, end by end, from the best total back.
        var chosen = new Array(E), path = new Array(E), k = best;
        for (e = E - 1; e >= 0; e--) {
            var n = F[e].get(k);
            chosen[e] = n.cand;
            path[e] = n;
            k = n.from;
        }

        // Marginals: probability of each end candidate, then of each arrow label; and
        // of a running total written off.
        var cells = [], ends2 = [], cum = 0, order = {n: 0, bad: 0};
        for (e = 0; e < E; e++) {
            var before = e ? F[e - 1] : start, readE = closing(ends[e]).cum, rest = {};
            groups[e].forEach(function (g) {
                var acc = -Infinity;
                before.forEach(function (st) {
                    var t = st.t + g.sum;
                    cumSteps(readE, t, st.d).forEach(function (step) {
                        var n = F[e].get(key(t, step[0]));
                        if (n) acc = lse(acc, st.sum + step[1] + n.bwd);
                    });
                });
                rest[g.sum] = acc;
            });
            var post = cands[e].map(function (c) { return Math.exp(c.lp + rest[c.sum] - Z); });
            var off = 0;
            F[e].forEach(function (n) { if (n.d !== 0) off += Math.exp(n.sum + n.bwd - Z); });
            var pick = cands[e][chosen[e]], slip = path[e].d !== (e ? path[e - 1].d : 0);
            cum += pick.sum;
            if (perEnd > 1) {
                var seq = [].concat.apply([], pick.parts.map(function (part) { return part.labels; }));
                if (seq.every(function (l) { return l !== ''; })) {
                    var rank = seq.map(function (l) { var v = values[l]; return v ? 2 * v.n + (v.x ? 1 : 0) : 0; });
                    order.n++;
                    if (rank.some(function (r, i) { return i && r > rank[i - 1]; })) order.bad++;
                }
            }
            pick.parts.forEach(function (part, li) {
                part.labels.forEach(function (lab, ai) {
                    var conf = 0, alt = {};
                    cands[e].forEach(function (c, ci) {
                        var l2 = c.parts[li].labels[ai];
                        alt[l2] = (alt[l2] || 0) + post[ci];
                        if (l2 === lab) conf += post[ci];
                    });
                    if (slip) conf = Math.min(conf, SLIP_SURE);
                    cells.push({line: e * perEnd + li + 1, index: ai, value: lab, conf: conf, alternatives: alt});
                });
            });
            ends2.push({sum: pick.sum, cum: cum, written: {cum: path[e].t + path[e].d, off: off},
                lines: pick.parts.map(function (c) { return {sum: c.sum, g: c.g, x: c.x}; })});
        }
        var g = 0, x = 0, offTotal = 0;
        ends2.forEach(function (en) { en.lines.forEach(function (l) { g += l.g; x += l.x; }); });
        F[E - 1].forEach(function (n) {
            cumSteps(totRead, n.t, n.d).forEach(function (step) { if (step[0] !== 0) offTotal += Math.exp(n.sum + step[1] - Z); });
        });
        var tot = {cum: cum, gold: g, xnine: x, written: {cum: cum + bestStep[0], off: offTotal}};

        // The written numbers that disagree with the arrows: the running totals and the
        // total row by the chain above; the counts of the total row on their own.
        var sheet = {};
        ends2.forEach(function (en, ei) {
            if (closing(ends[ei]).cum && en.written.off > 0.5) sheet[((ei + 1) * perEnd) + '.cum'] = {read: en.written.cum, want: en.cum};
        });
        if (totRead && offTotal > 0.5) sheet['total.cum'] = {read: tot.written.cum, want: cum};
        [['gold', g], ['xnine', x]].forEach(function (kv) {
            if (kv[0] === 'gold' && !golds) return;
            var r = total && total[kv[0]], v = r ? countSlip(r, kv[1]) : null;
            if (v !== null) sheet['total.' + kv[0]] = {read: v, want: kv[1]};
        });
        return {arrows: cells, ends: ends2, total: tot, sheet: sheet, agreement: agreement(ends, ends2, total, cum, golds, order),
            score: bestScore};
    }

    /**
     * How far the numbers the archer wrote agree with the arrows chosen (one of the
     * readings of each, the empty ones left out). On a sound reading nearly all do; a
     * grid set one line off, or a photo too poor to read, still adds up here and
     * there, but much less: then every value is to be checked. A grid set a line off is
     * told too by its ends' totals read blank (empty).
     * -> {n, share, empty, low}
     */
    function agreement(ends, ends2, total, cum, golds, order) {
        var n = 0, ok = 0;
        function check(read, want, isCount, also) {
            if (!read || (read[0].value === -1 && read[0].p > 0.5)) return;
            n++;
            if (read.some(function (r) {
                var v = r.value === -1 && isCount ? 0 : r.value;
                return r.p >= AGREE_P && (v === want || v === also);
            })) ok++;
        }
        ends.forEach(function (endLines, ei) {
            endLines.forEach(function (ln, li) {
                var part = ends2[ei].lines[li];
                // A line's sum cell holding the end's total agrees too.
                check(ln.line, part.sum, false, endLines.length > 1 ? ends2[ei].sum : undefined);
                if (golds) check(ln.gold, part.g, true);
                check(ln.xnine, part.x, true);
            });
            check(closing(endLines).end, ends2[ei].sum, false);
            check(closing(endLines).cum, ends2[ei].cum, false);
        });
        if (total) check(total.cum, cum, false);
        var share = n ? ok / n : 1, cells = 0, blank = 0;
        ends.forEach(function (endLines, ei) {
            if (!ends2[ei].sum) return;
            var last = closing(endLines), sum = ends2[ei].sum;
            // The end's total written in the line's sum cell instead: its own cell left blank
            // says nothing of the grid's place (a grid set a line off has a three arrows'
            // sum there).
            var moved = endLines.length > 1 && last.line && last.line.some(function (r) { return r.p >= AGREE_P && r.value === sum; });
            [moved ? null : last.end, last.cum].forEach(function (r) {
                if (!r) return;
                cells++;
                if (r[0].value === -1 && r[0].p > 0.5) blank++;
            });
        });
        var empty = cells >= 6 && blank > EMPTY_TOTALS * cells;
        var disorder = !!order && order.n >= 3 && order.bad > DISORDER * order.n;
        return {n: n, share: share, empty: empty, disorder: disorder, low: n >= AGREE_COUNT && share < AGREE_MIN || empty || disorder};
    }

    return {solve: solve, _internal: {numberScore: numberScore, lineCombos: lineCombos, arrowOptions: arrowOptions}};
});
