/* Commentators' screen of the live draw module.

   Two sources, fetched separately because they change at different paces:
     - the draw's state, polled every second and a half (who is drawn, what is
       on air, the notes);
     - the previous-season facts, heavier, fetched once and again only when the
       lists change (dataRevision moves).

   The team shown is the last one drawn, until the commentator picks another team
   or another category in the lists. From then on a "back to live" button stays
   in the header, returning to the last place given; the next draw brings the
   view back to the team just drawn in any case. */

(function () {
    'use strict';

    var C = window.TIR_SCREEN;
    var T = TIR.T, esc = TIR.esc;
    var root = document.getElementById('tir-speaker');
    if (!C.token) return;

    var state = null, revision = 0, facts = null, factsRevision = -1, loadingFacts = false;
    var known = {}, first = true;
    var selected = 0;       // team id on show
    var viewCat = 0;        // category browsed in the side lists
    var manual = false;     // the commentator moved away from the live view
    var flash = false;
    var lang = document.documentElement.lang || undefined;
    var SCENES = { idle: 'SceneIdle', category: 'SceneCategory', summary: 'SceneSummary' };

    function poll() {
        TIR.get(C.live + '?t=' + encodeURIComponent(C.token) + '&rev=' + revision)
            .then(function (j) {
                if (j.error) { root.innerHTML = '<p class="tir-sp-muted" style="padding:16px">' + esc(j.msg) + '</p>'; return; }
                if (j.state) apply(j.state);
            })
            .catch(function () { /* the next poll retries */ })
            .then(function () { setTimeout(poll, 1500); });
    }

    function loadFacts() {
        if (loadingFacts) return;
        loadingFacts = true;
        var wanted = state.dataRevision;
        TIR.get(C.live + '?t=' + encodeURIComponent(C.token) + '&facts=1')
            .then(function (j) {
                if (!j.error) { facts = j.facts; factsRevision = wanted; render(); }
            })
            .catch(function () { /* retried at the next state */ })
            .then(function () { loadingFacts = false; });
    }

    function apply(s) {
        var latest = null;
        var next = {};
        s.categories.forEach(function (c) {
            c.teams.forEach(function (t) {
                next[t.id] = t.position;
                if (!first && t.position && !known[t.id]) {
                    if (!latest || later(t, latest.team)) latest = { team: t, cat: c };
                }
            });
        });
        known = next;
        state = s;
        revision = s.revision;

        if (latest) {
            selected = latest.team.id;
            viewCat = latest.cat.id;
            manual = false;
            flash = true;
        } else if (first) {
            var last = lastDrawn();
            if (last) { selected = last.team.id; viewCat = last.cat.id; }
            else viewCat = s.category;
        }
        first = false;
        if (s.dataRevision !== factsRevision) loadFacts();
        render();
    }

    /* Draws recorded within the same second share a timestamp; the higher place
       of a category was necessarily given last. */
    function later(a, b) {
        return a.drawn > b.drawn || (a.drawn === b.drawn && a.position > b.position);
    }

    function lastDrawn() {
        var best = null;
        state.categories.forEach(function (c) {
            c.teams.forEach(function (t) {
                if (t.position && (!best || later(t, best.team))) best = { team: t, cat: c };
            });
        });
        return best;
    }

    /* The live view: the last place given, in its category; before any draw, the
       category on air. */
    function isLive() {
        var last = lastDrawn();
        return last ? selected === last.team.id && viewCat === last.cat.id : viewCat === state.category;
    }

    function goLive() {
        var last = lastDrawn();
        if (last) { selected = last.team.id; viewCat = last.cat.id; }
        else { selected = 0; viewCat = state.category; }
        manual = false;
        render();
        window.scrollTo(0, 0);
    }

    function findTeam(id) {
        var found = null;
        state.categories.forEach(function (c) {
            c.teams.forEach(function (t) { if (t.id === id) found = { team: t, cat: c }; });
        });
        return found;
    }

    /* ------------------------------------------------------------- helpers */

    function ord(n) { return T(n === 1 ? 'Ordinal1' : 'OrdinalN', n); }
    function num(v, digits) {
        return v === null || v === undefined ? '–' : Number(v).toLocaleString(lang, { minimumFractionDigits: digits, maximumFractionDigits: digits });
    }
    var MEDAL_TITLES = { gold: 'MedalGold', silver: 'MedalSilver', bronze: 'MedalBronze' };

    function kpi(label, value, extra, medal) {
        return '<div class="tir-sp-kpi' + (medal ? ' tir-sp-' + medal : '') + '"'
            + (medal ? ' title="' + esc(T(MEDAL_TITLES[medal])) + '"' : '') + '>'
            + '<small>' + esc(label) + '</small><b>' + value + '</b>'
            + (extra ? '<span>' + extra + '</span>' : '') + '</div>';
    }

    /* ---------------------------------------------------------------- medals

       Each figure of a team is compared with the same figure of the other teams
       of its category. The best gets gold, the second silver, the third bronze.
       Ranks follow sports usage: two teams sharing the best value are both gold,
       and the next one is third. A team with no value, or a count of zero, takes
       no part: a zero is not an achievement to crown. */

    function matchesOf(t) {
        var f = facts && facts.teams ? facts.teams[t.id] : null;
        return f && f.found && f.matches && f.matches.played ? f.matches : null;
    }
    function seasonOf(t) {
        var f = facts && facts.teams ? facts.teams[t.id] : null;
        return f && f.found ? f : null;
    }

    var METRICS = {
        participations: { higher: true,  get: function (t) { return t.stats.participations; } },
        wins:           { higher: true,  get: function (t) { return t.stats.wins; } },
        podiums:        { higher: true,  get: function (t) { return t.stats.podiums; } },
        prevRank:       { higher: false, get: function (t) { return t.stats.rank; } },
        rank:           { higher: false, get: function (t) { var f = seasonOf(t); return f ? f.rank : null; } },
        points:         { higher: true,  get: function (t) { var f = seasonOf(t); return f ? f.points : null; } },
        matches:        { higher: true,  get: function (t) { var m = matchesOf(t); return m ? m.pct : null; } },
        avg:            { higher: true,  get: function (t) { var m = matchesOf(t); return m ? m.avg : null; } },
        best:           { higher: true,  get: function (t) { var m = matchesOf(t); return m ? m.best : null; } },
        streak:         { higher: true,  get: function (t) { var m = matchesOf(t); return m ? m.streak : null; } },
        shootOffs:      { higher: true,  get: function (t) { var m = matchesOf(t); return m ? m.soWon : null; } },
        sets:           { higher: true,  get: function (t) {
            var m = matchesOf(t), all = m ? m.setsFor + m.setsAgainst : 0;
            return all ? m.setsFor / all : null;
        } }
    };

    function medal(cat, t, key) {
        var def = METRICS[key];
        var usable = function (v) { return v !== null && v !== undefined && !isNaN(v) && v > 0; };
        var mine = def.get(t);
        if (!usable(mine)) return '';
        var better = 0;
        cat.teams.forEach(function (o) {
            var v = def.get(o);
            if (usable(v) && (def.higher ? v > mine : v < mine)) better++;
        });
        return ['gold', 'silver', 'bronze'][better] || '';
    }
    function dash(v) { return v === null || v === undefined ? '–' : esc(v); }

    /* -------------------------------------------------------------- render */

    function render() {
        var onAir = state.categories.find(function (c) { return c.id === state.category; });
        var next = 0, total = 0;
        if (onAir) {
            total = onAir.teams.length;
            next = onAir.teams.reduce(function (m, t) { return Math.max(m, t.position); }, 0) + 1;
        }
        var progress = '';
        if (onAir && onAir.type === 'stages') {
            progress = next > total ? esc(T('AllStagesShown')) : esc(T('StagesShown', { shown: next - 1, total: total }));
        } else if (onAir) {
            progress = next > total ? esc(T('CategoryComplete')) : esc(T('NextPlace')) + ' <b>' + next + '</b> / ' + total;
        }
        var head = '<div class="tir-sp-head"><h1>' + esc(state.title) + '</h1>'
            + '<span class="tir-sp-onair">' + esc(T(SCENES[state.scene] || 'SceneIdle')) + '</span>'
            + (onAir ? '<span class="tir-sp-cat">' + esc(onAir.name) + '</span><span class="tir-sp-next">' + progress + '</span>' : '')
            + (manual && !isLive() ? '<button type="button" class="tir-sp-live" data-live><i></i>' + esc(T('BackToLive')) + '</button>' : '')
            + '<span class="tir-sp-clock" id="tir-sp-clock"></span></div>';

        var pick = findTeam(selected);
        var main = pick ? renderTeam(pick.team, pick.cat) : '<div class="tir-sp-card"><p class="tir-sp-muted">' + esc(T('SpeakerPick')) + '</p></div>';

        root.innerHTML = head + '<div class="tir-sp-body"><div>' + main + '</div><div>' + renderSide() + '</div></div>';
        tick();
        if (flash) {
            var hero = root.querySelector('.tir-sp-card');
            if (hero) hero.classList.add('tir-sp-flash');
            flash = false;
        }
    }

    /* A stage: where and when, and what the speaker noted about it. */
    function renderStage(t, cat) {
        var k = cat.teams.indexOf(t);
        return '<div class="tir-sp-card"><h2>' + esc(cat.name) + '</h2>'
            + '<div class="tir-sp-hero"><span class="tir-sp-pos">' + esc(T('StageN', k + 1)) + '</span>'
            + '<span class="tir-sp-name">' + esc(t.name) + '</span>'
            + '<span class="tir-sp-tag' + (t.position ? ' tir-sp-newteam' : '') + '">' + esc(T(t.position ? 'StageOnScreen' : 'StageUpcoming')) + '</span></div>'
            + (t.detail ? '<p class="tir-sp-detail">' + esc(t.detail) + '</p>' : '')
            + (t.note ? '<div class="tir-sp-note">' + esc(t.note) + '</div>' : '')
            + '</div>';
    }

    function renderTeam(t, cat) {
        if (cat.type === 'stages') return renderStage(t, cat);
        var f = facts && facts.teams ? facts.teams[t.id] : null;
        var src = facts && facts.source;

        var tags = '';
        if (t.club) tags += '<span class="tir-sp-tag">' + esc(t.club) + '</span>';
        if (f && !f.found && cat.event && src) tags += '<span class="tir-sp-tag tir-sp-newteam">' + esc(T('NewInEvent', src.year)) + '</span>';

        var html = '<div class="tir-sp-card"><h2>' + esc(cat.name) + '</h2>'
            + '<div class="tir-sp-hero"><span class="tir-sp-pos">' + (t.position ? esc(T('PlaceN', t.position)) : esc(T('NotDrawnYet'))) + '</span>'
            + '<span class="tir-sp-name">' + esc(t.name) + '</span>' + tags + '</div>'
            + '<div class="tir-sp-kpis">'
            + kpi(T('StatParticipations'), dash(t.stats.participations), '', medal(cat, t, 'participations'))
            + kpi(T('StatWins'), dash(t.stats.wins), '', medal(cat, t, 'wins'))
            + kpi(T('StatPodiums'), dash(t.stats.podiums), '', medal(cat, t, 'podiums'))
            + kpi(T('StatRank'), t.stats.rank === null ? '–' : esc(ord(t.stats.rank)), '', medal(cat, t, 'prevRank'))
            + '</div>'
            + (t.note ? '<div class="tir-sp-note">' + esc(t.note) + '</div>' : '')
            + '</div>';

        if (!src) return html;
        if (!facts) return html + '<div class="tir-sp-card"><p class="tir-sp-muted">' + esc(T('Loading')) + '</p></div>';
        if (!t.club) return html + '<div class="tir-sp-card"><p class="tir-sp-muted">' + esc(T('SpeakerNoClub')) + '</p></div>';
        if (!f) return html;

        var elsewhere = (f.elsewhere || []).map(function (e) { return esc(e.event) + ' (' + esc(ord(e.rank)) + ')'; }).join(', ');

        if (!f.found) {
            return html + '<div class="tir-sp-card"><h2>' + esc(T('SeasonTitle', src.year)) + '</h2><p>'
                + esc(T('NotInEvent', { year: src.year })) + '</p>'
                + (elsewhere ? '<p class="tir-sp-muted">' + esc(T('AlsoIn')) + ' ' + elsewhere + '</p>' : '') + '</div>';
        }

        var m = f.matches;
        var season = '<div class="tir-sp-card"><h2>' + esc(T('SeasonTitle', src.year)) + ' — ' + esc(f.eventName) + '</h2><div class="tir-sp-kpis">'
            + kpi(T('FactRank'), esc(ord(f.rank)), '/ ' + f.of, medal(cat, t, 'rank'))
            + kpi(T('FactPoints'), esc(f.points), '', medal(cat, t, 'points'));
        if (m) {
            var setsAll = m.setsFor + m.setsAgainst;
            // A non-breaking space keeps "%" on the line of its number in a narrow tile.
            season += kpi(T('FactMatches'), m.won + '–' + m.lost, esc(m.pct) + ' %', medal(cat, t, 'matches'))
                + kpi(T('FactAvg'), num(m.avg, 2), '', medal(cat, t, 'avg'))
                + kpi(T('FactBest'), num(m.best, 2), '', medal(cat, t, 'best'))
                + kpi(T('FactStreak'), esc(m.streak), '', medal(cat, t, 'streak'))
                + kpi(T('FactShootOffs'), m.soWon + '–' + m.soLost, '', medal(cat, t, 'shootOffs'))
                + kpi(T('FactSets'), m.setsFor + '–' + m.setsAgainst,
                    setsAll ? Math.round(100 * m.setsFor / setsAll) + ' %' : '', medal(cat, t, 'sets'));
        }
        season += '</div>';
        if (m && m.last.length) {
            season += '<p>' + esc(T('FactForm')) + ' <span class="tir-sp-form">' + m.last.map(function (w) {
                return '<i class="' + (w ? 'tir-w' : '') + '" title="' + esc(T(w ? 'Won' : 'Lost')) + '"></i>';
            }).join('') + '</span></p>';
        }
        season += renderStages(f) + (elsewhere ? '<p class="tir-sp-muted">' + esc(T('AlsoIn')) + ' ' + elsewhere + '</p>' : '') + '</div>';

        return html + season + renderArchers(f, src);
    }

    function renderStages(f) {
        var rows = {}, order = [];
        var add = function (name) { if (!rows[name]) { rows[name] = { name: name }; order.push(name); } return rows[name]; };
        ((f.matches && f.matches.stages) || []).forEach(function (s) { var r = add(s.name); r.match = s; });
        (f.qualification || []).forEach(function (q) { var r = add(q.name); r.qual = q; });
        if (!order.length) return '';

        return '<table class="tir-sp-table"><thead><tr><th>' + esc(T('FactStage')) + '</th><th>' + esc(T('FactQualification')) + '</th>'
            + '<th>' + esc(T('FactMatches')) + '</th><th class="tir-num">' + esc(T('FactAvg')) + '</th></tr></thead><tbody>'
            + order.map(function (n) {
                var r = rows[n];
                return '<tr><td>' + esc(n) + '</td>'
                    + '<td>' + (r.qual ? esc(r.qual.score) + ' <span class="tir-sp-muted">(' + esc(ord(r.qual.rank))
                        + (r.qual.bonus ? ', ' + esc(T('FactBonus', r.qual.bonus)) : '') + ')</span>' : '–') + '</td>'
                    + '<td>' + (r.match ? r.match.won + '–' + r.match.lost : '–') + '</td>'
                    + '<td class="tir-num">' + (r.match ? num(r.match.avg, 2) : '–') + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    function renderArchers(f, src) {
        if (!f.archers || !f.archers.length) return '';
        var nat = function (r) {
            return r ? esc(ord(r.rank)) + ' <span class="tir-sp-muted">/ ' + esc(r.of) + ' — ' + esc(r.avg) + '</span>' : '–';
        };
        var year = '';
        f.archers.forEach(function (a) { if (!year && (a.ti || a.s)) year = (a.ti || a.s).year; });

        return '<div class="tir-sp-card"><h2>' + esc(T('CompositionTitle', src.year)) + '</h2>'
            + '<table class="tir-sp-table"><thead><tr><th>' + esc(T('ColArcher')) + '</th>'
            + (facts.national ? '<th>' + esc(T('NationalOutdoor', year)) + '</th><th>' + esc(T('NationalIndoor', year)) + '</th>' : '')
            + '</tr></thead><tbody>'
            + f.archers.map(function (a) {
                return '<tr><td>' + esc(a.name) + '</td>' + (facts.national ? '<td>' + nat(a.ti) + '</td><td>' + nat(a.s) + '</td>' : '') + '</tr>';
            }).join('') + '</tbody></table>'
            + (facts.national ? '' : '<p class="tir-sp-muted">' + esc(T('NationalOff')) + '</p>')
            + '</div>';
    }

    function renderSide() {
        var cat = state.categories.find(function (c) { return c.id === viewCat; })
            || state.categories.find(function (c) { return c.id === state.category; })
            || state.categories[0];
        if (!cat) return '';

        var tabs = '<div class="tir-sp-cats">' + state.categories.map(function (c) {
            return '<button type="button" data-view="' + c.id + '" class="' + (c.id === cat.id ? 'tir-sp-cur' : '') + '">' + esc(c.name) + '</button>';
        }).join('') + '</div>';

        if (cat.type === 'stages') {
            return '<div class="tir-sp-card"><h2>' + esc(T('Categories')) + '</h2>' + tabs + '</div>'
                + '<div class="tir-sp-card"><h2>' + esc(T('StagesList')) + '</h2><ul class="tir-sp-list">'
                + (cat.teams.map(function (t, k) {
                    return '<li data-team="' + t.id + '" class="' + (t.id === selected ? 'tir-sp-sel' : '') + '"><b>' + (k + 1) + '</b>'
                        + '<span class="tir-sp-grow">' + esc(t.name) + (t.detail ? ' <small>' + esc(t.detail) + '</small>' : '') + '</span>'
                        + '<small>' + esc(T(t.position ? 'StageOnScreen' : 'StageUpcoming')) + '</small></li>';
                }).join('') || '<li class="tir-sp-muted">' + esc(T('NoStage')) + '</li>')
                + '</ul></div>';
        }

        var drawn = cat.teams.filter(function (t) { return t.position; }).sort(function (a, b) { return a.position - b.position; });
        var left = cat.teams.filter(function (t) { return !t.position; }).sort(TIR.byName);
        var item = function (t, lead) {
            return '<li data-team="' + t.id + '" class="' + (t.id === selected ? 'tir-sp-sel' : '') + '"><b>' + lead + '</b>'
                + '<span class="tir-sp-grow">' + esc(t.name) + '</span>'
                + (t.stats.rank !== null ? '<small>' + esc(T('StatRankShort')) + ' ' + esc(t.stats.rank) + '</small>' : '') + '</li>';
        };

        return '<div class="tir-sp-card"><h2>' + esc(T('Categories')) + '</h2>' + tabs + '</div>'
            + '<div class="tir-sp-card"><h2>' + esc(T('DrawOrder')) + ' (' + drawn.length + '/' + cat.teams.length + ')</h2><ul class="tir-sp-list">'
            + (drawn.map(function (t) { return item(t, t.position); }).join('') || '<li class="tir-sp-muted">' + esc(T('NothingDrawn')) + '</li>')
            + '</ul></div>'
            + '<div class="tir-sp-card"><h2>' + esc(T('LeftToDraw')) + '</h2><ul class="tir-sp-list">'
            + (left.map(function (t) { return item(t, ''); }).join('') || '<li class="tir-sp-muted">' + esc(T('CategoryComplete')) + '</li>')
            + '</ul></div>';
    }

    function tick() {
        var el = document.getElementById('tir-sp-clock');
        if (el) el.textContent = new Date().toLocaleTimeString(lang, { hour: '2-digit', minute: '2-digit' });
    }
    setInterval(tick, 15000);

    root.addEventListener('click', function (e) {
        if (e.target.closest('[data-live]')) { goLive(); return; }
        var li = e.target.closest('[data-team]');
        if (li) { selected = parseInt(li.getAttribute('data-team'), 10); manual = true; render(); window.scrollTo(0, 0); return; }
        var tab = e.target.closest('[data-view]');
        if (tab) { viewCat = parseInt(tab.getAttribute('data-view'), 10); manual = true; render(); }
    });

    poll();
})();
