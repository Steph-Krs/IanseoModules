/* Public screen of the live draw module.

   Polls the draw once a second and plays what the control page chose:
     idle      the waiting loop, title over the animated background;
     category  the list being drawn, one slot per place;
     summary   every team list, in drawn order (the stages are not a result).

   A team that received its place since the previous poll is revealed first, on
   a card in the middle of the screen, and only then appears in its slot. Teams
   drawn in quick succession are revealed one after the other. The first state
   received reveals nothing: a screen opened mid-draw shows the draw as it is. */

(function () {
    'use strict';

    var C = window.TIR_SCREEN;
    var T = TIR.T, esc = TIR.esc;
    var screenEl = document.getElementById('tir-screen');
    var stage = document.getElementById('tir-stage');
    var revealEl = document.getElementById('tir-reveal');
    var bg = document.getElementById('tir-bg');

    var state = null, revision = 0, first = true, sceneKey = '';
    var known = {};          // team id -> position at the previous state
    var pending = {};        // team id -> true while waiting for its reveal
    var queue = [], revealing = false;
    var flipping = {};       // stage id -> true while its card turns over

    var REVEAL_MS = 3200, OUT_MS = 600;
    var STAT_LABELS = { participations: 'StatParticipationsShort', wins: 'StatWinsShort', podiums: 'StatPodiumsShort', rank: 'StatRankShort' };

    if (!C.token) return;

    function poll() {
        TIR.get(C.live + '?t=' + encodeURIComponent(C.token) + '&rev=' + revision)
            .then(function (j) {
                if (j.error) { stage.innerHTML = '<div class="tir-idle"><div class="tir-sub">' + esc(j.msg) + '</div></div>'; return; }
                if (j.state) apply(j.state);
            })
            .catch(function () { /* the next poll retries */ })
            .then(function () { setTimeout(poll, 1000); });
    }

    function apply(s) {
        var fresh = [];
        var next = {};
        s.categories.forEach(function (c) {
            c.teams.forEach(function (t) {
                next[t.id] = t.position;
                if (!first && t.position && !known[t.id] && !pending[t.id]) fresh.push({ team: t, cat: c });
            });
        });
        // A place withdrawn before its reveal played is not revealed at all.
        Object.keys(pending).forEach(function (id) {
            if (!next[id]) {
                delete pending[id];
                queue = queue.filter(function (q) { return String(q.team.id) !== id; });
            }
        });
        known = next;
        state = s;
        revision = s.revision;
        applyLook();

        fresh.sort(function (a, b) { return a.team.position - b.team.position; });
        fresh.forEach(function (f) {
            // Only a team of the category on air is worth a reveal; one drawn
            // elsewhere simply appears when its category comes on.
            if (s.scene !== 'category' || f.cat.id !== s.category) return;
            if (f.cat.type === 'stages') {
                // A stage card is large enough to be its own reveal: it turns over in place.
                flipping[f.team.id] = true;
                setTimeout(function () { delete flipping[f.team.id]; }, 1600);
                return;
            }
            pending[f.team.id] = true;
            queue.push(f);
        });

        var key = s.scene + ':' + (s.scene === 'category' ? s.category : '');
        render(key !== sceneKey);
        sceneKey = key;
        first = false;
        runQueue();
    }

    /* ---------------------------------------------------------------- look */

    function hexToRgba(hex, alpha) {
        var n = parseInt(hex.slice(1), 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
    }

    function applyLook() {
        var L = state.look, st = screenEl.style;
        st.setProperty('--bg', L.bg);
        st.setProperty('--accent', L.accent);
        st.setProperty('--text', L.text);
        st.setProperty('--font-title', state.fonts[L.fontTitle] || 'sans-serif');
        st.setProperty('--font-body', state.fonts[L.fontBody] || 'sans-serif');
        st.setProperty('--size-title', L.sizeTitle + 'rem');
        st.setProperty('--size-team', L.sizeTeam + 'rem');
        st.setProperty('--size-rank', L.sizeRank + 'rem');
        st.setProperty('--margin', L.margin + 'vh');
        st.setProperty('--rank-space', L.rankSpace + 'em');
        st.setProperty('--stats-space', L.statsSpace + 'rem');
        st.setProperty('--stat-gap', L.statGap + 'em');
        st.setProperty('--stat-width', L.statWidth + 'em');
        st.setProperty('--overlay', state.image ? hexToRgba(L.bg, L.overlay) : 'transparent');
        screenEl.classList.toggle('tir-no-ambient', !L.ambient);
        var url = state.image ? 'url("' + C.image + '?t=' + encodeURIComponent(C.token) + '&v=' + state.image + '")' : 'none';
        if (bg.style.backgroundImage !== url) bg.style.backgroundImage = url;
        document.title = state.title;
    }

    /* -------------------------------------------------------------- scenes */

    function currentCategory() {
        return state.categories.find(function (c) { return c.id === state.category; }) || null;
    }

    function render(entering) {
        var html;
        if (state.scene === 'summary') html = renderSummary();
        else if (state.scene === 'category' && currentCategory()) html = renderCategory(currentCategory());
        else html = renderIdle();
        stage.innerHTML = html;
        stage.classList.toggle('tir-enter', !!entering);
        fitNames();
    }

    /* A name wider than its box is condensed rather than cut or allowed to push the
       layout off screen: first the letters move closer together, then, if that is
       not enough, the characters are narrowed. Measured after layout, since only
       the browser knows how wide a name is in the font of the screen. */
    // Tighter than -0.03em, bold capitals touch and the spaces between words vanish;
    // narrowing the characters keeps them apart, so it takes over from there.
    var SPACINGS = ['', '0em', '-0.015em', '-0.03em'];

    function fitNames() {
        stage.querySelectorAll('.tir-fit').forEach(function (el) {
            el.style.letterSpacing = '';
            el.style.transform = '';
            var room = el.parentNode.clientWidth;
            if (!room) return;
            for (var i = 0; i < SPACINGS.length; i++) {
                el.style.letterSpacing = SPACINGS[i];
                if (el.offsetWidth <= room) return;
            }
            el.style.transform = 'scaleX(' + (room / el.offsetWidth).toFixed(3) + ')';
        });
    }

    function fitName(name) {
        return '<span class="tir-fit">' + esc(name) + '</span>';
    }

    window.addEventListener('resize', fitNames);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitNames);

    function renderIdle() {
        return '<div class="tir-idle">'
            + '<div class="tir-idle-kicker">' + esc(T('DrawKicker')) + '</div>'
            + '<h1 class="tir-idle-title">' + esc(state.title) + '</h1>'
            + (state.subtitle ? '<div class="tir-sub">' + esc(state.subtitle) + '</div>' : '')
            + '</div>';
    }

    function statsFor(cat) {
        // Columns for this list: the ones switched on for which at least one team has a value.
        return state.stats.filter(function (k) {
            return cat.teams.some(function (t) { return t.stats[k] !== null; });
        });
    }

    /* The season's stages: one card per stage in list order, face down until shown. */
    function renderStages(cat) {
        var n = cat.teams.length;
        var cards = cat.teams.map(function (t, k) {
            var shown = !!t.position;
            return '<div class="tir-stagecard' + (shown ? ' tir-shown' : '') + (flipping[t.id] ? ' tir-flip' : '') + '"'
                + ' style="animation-delay:' + (flipping[t.id] ? 0 : k * 0.1) + 's">'
                + '<div class="tir-stage-num">' + esc(T('StageN', k + 1)) + '</div>'
                + (shown
                    ? '<div class="tir-stage-name">' + esc(t.name) + '</div>'
                        + (t.detail ? '<div class="tir-stage-detail">' + esc(t.detail) + '</div>' : '')
                    : '<div class="tir-stage-name tir-stage-q">?</div>')
                + '</div>';
        }).join('');

        return '<h1 class="tir-title">' + esc(cat.name) + '</h1>'
            + '<div class="tir-sub">' + esc(state.subtitle || state.title) + '</div>'
            + '<div class="tir-stages' + (n > 4 ? ' tir-many' : '') + '" style="grid-template-columns:repeat('
            + Math.min(Math.max(n, 1), 4) + ', minmax(0, 1fr))">' + cards + '</div>';
    }

    function renderCategory(cat) {
        if (cat.type === 'stages') return renderStages(cat);
        var total = cat.teams.length;
        var byPos = {};
        cat.teams.forEach(function (t) { if (t.position) byPos[t.position] = t; });
        var cols = statsFor(cat);
        var slots = state.look.slots ? total : cat.teams.filter(function (t) { return t.position; }).length;
        var twoCols = total > 8;
        var rows = twoCols ? Math.ceil(Math.max(slots, 1) / 2) : Math.max(slots, 1);

        var html = '';
        var shown = 0;
        for (var p = 1; p <= total; p++) {
            var t = byPos[p];
            if (!t && !state.look.slots) continue;
            var hidden = t && pending[t.id];
            var cls = 'tir-slot' + (!t ? ' tir-empty' : '') + (hidden ? ' tir-pending' : '')
                + ((shown % rows) % 2 === 1 ? ' tir-alt' : '');
            html += '<div class="' + cls + '" data-slot="' + (t ? t.id : '') + '" style="animation-delay:' + (shown * 0.04) + 's">'
                + '<span class="tir-rank">' + p + '.</span>'
                + '<span class="tir-team">' + (t && !hidden ? fitName(t.name) : '· · ·') + '</span>'
                + (t && !hidden && cols.length ? renderStats(t, cols) : '')
                + '</div>';
            shown++;
        }
        if (!shown) html = '<div class="tir-slot tir-empty"><span class="tir-team">' + esc(T('WaitingDraw')) + '</span></div>';

        return '<h1 class="tir-title">' + esc(cat.name) + '</h1>'
            + '<div class="tir-sub">' + esc(state.subtitle || state.title) + '</div>'
            + '<div class="tir-grid' + (twoCols ? ' tir-cols-2' : '') + '" style="grid-template-rows:repeat(' + rows + ', auto)">'
            + html + '</div>';
    }

    function renderStats(t, cols) {
        return '<span class="tir-stats">' + cols.map(function (k) {
            var v = t.stats[k];
            return '<span class="tir-stat"><small>' + esc(T(STAT_LABELS[k])) + '</small><b>' + (v === null ? '–' : esc(v)) + '</b></span>';
        }).join('') + '</span>';
    }

    function renderSummary() {
        // Team lists only: the season's stages are announced, not drawn, and are no
        // category of the result. Lists with nothing drawn yet are left out too,
        // unless nothing is drawn anywhere.
        var teamLists = state.categories.filter(function (c) { return c.type !== 'stages' && c.teams.length; });
        var cats = teamLists.filter(function (c) { return c.teams.some(function (t) { return t.position; }); });
        if (!cats.length) cats = teamLists;
        var cols = cats.map(function (c, i) {
            var list = c.teams.filter(function (t) { return t.position; })
                .sort(function (a, b) { return a.position - b.position; })
                .map(function (t) { return '<li><b>' + t.position + '</b><span>' + fitName(t.name) + '</span></li>'; }).join('');
            return '<div class="tir-sum-col" style="animation-delay:' + (i * 0.12) + 's"><h2>' + esc(c.name) + '</h2><ol>' + list + '</ol></div>';
        }).join('');
        return '<h1 class="tir-title">' + esc(state.title) + '</h1>'
            + (state.subtitle ? '<div class="tir-sub">' + esc(state.subtitle) + '</div>' : '<div class="tir-sub"></div>')
            + '<div class="tir-sum-cols" style="grid-template-columns:repeat(' + Math.max(cats.length, 1) + ', minmax(0, 1fr))">' + cols + '</div>';
    }

    /* -------------------------------------------------------------- reveal */

    function runQueue() {
        if (revealing || !queue.length) return;
        revealing = true;
        var item = queue.shift();

        revealEl.innerHTML = '<div class="tir-card">'
            + '<div class="tir-card-pos">' + esc(T('PlaceN', item.team.position)) + '</div>'
            + '<div class="tir-card-name">' + esc(item.team.name) + '</div>'
            + '<div class="tir-card-cat">' + esc(item.cat.name) + '</div></div>';

        setTimeout(function () {
            var card = revealEl.querySelector('.tir-card');
            if (card) card.classList.add('tir-out');
            setTimeout(function () {
                revealEl.innerHTML = '';
                delete pending[item.team.id];
                if (state) {
                    render(false);
                    var slot = stage.querySelector('[data-slot="' + item.team.id + '"]');
                    if (slot) slot.classList.add('tir-new');
                }
                revealing = false;
                runQueue();
            }, OUT_MS);
        }, REVEAL_MS);
    }

    poll();
})();
