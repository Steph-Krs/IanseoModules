/* Control page of the live draw module.

   The page is rebuilt from the draw's state after every action and every time
   the polled revision moves. Only the search field survives a rebuild, since
   the operator may be typing in it when a colleague's action arrives.

   Enter in the search field draws the team when exactly one undrawn team
   matches: typing the first letters of the name read out is then enough. */

(function () {
    'use strict';

    var P = window.TIR_PAGE;
    var T = TIR.T, esc = TIR.esc;
    var root = document.getElementById('tir-control');
    var state = null, revision = 0, busy = false, search = '', built = false;
    var STATS = ['participations', 'wins', 'podiums', 'rank'];
    var STAT_LABELS = { participations: 'StatParticipations', wins: 'StatWins', podiums: 'StatPodiums', rank: 'StatRank' };
    var SCENES = { idle: 'SceneIdle', category: 'SceneCategory', summary: 'SceneSummary' };

    function act(action, params) {
        if (busy) return Promise.resolve();
        busy = true;
        return TIR.call(P.api, action, Object.assign({ id: P.id }, params || {}), P.csrf)
            .then(function (j) { if (j.state) apply(j.state); })
            .catch(function (e) { TIR.toast(e.message, true); })
            .then(function () { busy = false; });
    }

    function poll() {
        TIR.call(P.api, 'state', { id: P.id, rev: revision })
            .then(function (j) { if (j.state && !busy) apply(j.state); })
            .catch(function () { /* a missed poll is retried by the next one */ })
            .then(function () { setTimeout(poll, 2000); });
    }

    function apply(s) {
        state = s;
        revision = s.revision;
        render();
    }

    function currentCategory() {
        var cats = state.categories;
        return cats.find(function (c) { return c.id === state.category; }) || cats[0] || null;
    }

    function nextPosition(cat) {
        return cat.teams.reduce(function (m, t) { return Math.max(m, t.position); }, 0) + 1;
    }

    /* ---------------------------------------------------------------- render */

    function build() {
        root.innerHTML = '<div class="tir-ctl">'
            + '<div class="tir-ctl-left">'
            + '<div class="tir-card"><h2>' + esc(T('OnAir')) + '</h2><div class="tir-scenes" id="tir-scenes"></div></div>'
            + '<div class="tir-card"><h2>' + esc(T('Categories')) + '</h2><div class="tir-cats" id="tir-cats"></div></div>'
            + '<div class="tir-card"><h2>' + esc(T('StatsShown')) + '</h2><div class="tir-toggles" id="tir-stats"></div></div>'
            + '</div>'
            + '<div class="tir-ctl-main"><div class="tir-card">'
            + '<h2><span id="tir-cat-name"></span><span class="tir-grow"></span>'
            + '<button type="button" class="tir-btn" data-act="undo">' + esc(T('UndoLast')) + '</button>'
            + '<button type="button" class="tir-btn tir-btn-danger" data-act="reset">' + esc(T('ResetCategory')) + '</button></h2>'
            + '<p class="tir-next" id="tir-next"></p>'
            + '<input type="text" class="tir-search" id="tir-search" autocomplete="off" placeholder="' + esc(T('SearchTeam')) + '">'
            + '<div class="tir-teams" id="tir-teams"></div>'
            + '</div></div>'
            + '<div class="tir-ctl-side">'
            + '<div class="tir-card"><h2>' + esc(T('PreviewTitle')) + '</h2><div class="tir-preview" id="tir-preview">'
            + '<iframe src="' + esc(P.display + '&preview=1') + '" title="' + esc(T('PreviewTitle')) + '"></iframe></div></div>'
            + '<div class="tir-card"><h2>' + esc(T('DrawOrder')) + '</h2><ol class="tir-order" id="tir-order"></ol></div>'
            + '<div class="tir-card" id="tir-paste-card"><h2>' + esc(T('PasteTitle')) + '</h2><div id="tir-paste"></div></div>'
            + '</div></div>';
        built = true;

        var input = document.getElementById('tir-search');
        input.addEventListener('input', function () { search = input.value; renderTeams(); });
        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var cat = currentCategory();
            var candidates = cat ? matching(cat).filter(function (t) { return !t.position; }) : [];
            if (candidates.length === 1) {
                act('draw', { team: candidates[0].id }).then(function () {
                    input.value = ''; search = ''; renderTeams();
                });
            }
        });
        window.addEventListener('resize', scalePreview);
    }

    function render() {
        if (!built) build();
        if (!state.categories.length) {
            document.getElementById('tir-teams').innerHTML = '<p class="tir-hint">' + esc(T('NoCategory')) + '</p>';
        }
        var cat = currentCategory();

        document.getElementById('tir-scenes').innerHTML = Object.keys(SCENES).map(function (k) {
            return '<button type="button" class="tir-btn tir-btn-big tir-scene' + (state.scene === k ? ' tir-cur' : '') + '" data-scene="' + k + '">'
                + esc(T(SCENES[k])) + '</button>';
        }).join('');

        document.getElementById('tir-cats').innerHTML = state.categories.map(function (c) {
            var drawn = c.teams.filter(function (t) { return t.position; }).length;
            return '<button type="button" class="tir-cat' + (cat && c.id === cat.id ? ' tir-cur' : '') + '" data-cat="' + c.id + '">'
                + '<span>' + esc(c.name) + '</span><span class="tir-count">' + drawn + '/' + c.teams.length + '</span></button>';
        }).join('');

        document.getElementById('tir-stats').innerHTML = STATS.map(function (k) {
            return '<label><input type="checkbox" data-stat="' + k + '"' + (state.stats.indexOf(k) >= 0 ? ' checked' : '') + '> '
                + esc(T(STAT_LABELS[k])) + '</label>';
        }).join('');

        document.getElementById('tir-cat-name').textContent = cat ? cat.name : '';
        renderTeams();
        scalePreview();
    }

    function matching(cat) {
        var words = search.trim().toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        return cat.teams.filter(function (t) {
            return !words || t.name.toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').indexOf(words) >= 0;
        });
    }

    function renderTeams() {
        var cat = currentCategory();
        var list = document.getElementById('tir-teams');
        var order = document.getElementById('tir-order');
        if (!cat) { list.innerHTML = ''; order.innerHTML = ''; document.getElementById('tir-next').textContent = ''; return; }

        document.getElementById('tir-search').hidden = cat.type === 'stages';
        renderPaste(cat);
        if (cat.type === 'stages') { renderStages(cat, list, order); return; }

        var next = nextPosition(cat);
        var complete = cat.teams.length > 0 && next > cat.teams.length;
        document.getElementById('tir-next').innerHTML = complete
            ? '<span class="tir-pill tir-pill-ok">' + esc(T('CategoryComplete')) + '</span>'
            : esc(T('NextPlace')) + ' <b>' + next + '</b> / ' + cat.teams.length;

        var teams = matching(cat).slice().sort(TIR.byName);
        list.innerHTML = teams.map(function (t) {
            if (t.position) {
                return '<div class="tir-team tir-done"><span class="tir-pos">' + t.position + '</span>'
                    + '<span class="tir-name">' + esc(t.name) + '</span>'
                    + '<button type="button" class="tir-btn tir-btn-danger" data-unassign="' + t.id + '" title="' + esc(T('Unassign')) + '">✕</button></div>';
            }
            return '<div class="tir-team"><span class="tir-pos"></span><span class="tir-name">' + esc(t.name) + '</span>'
                + '<button type="button" class="tir-btn tir-btn-primary tir-draw" data-draw="' + t.id + '">▶ ' + esc(T('PlaceN', next)) + '</button></div>';
        }).join('') || '<p class="tir-hint">' + esc(T(cat.teams.length ? 'NoMatch' : 'NoTeam')) + '</p>';

        order.innerHTML = cat.teams.filter(function (t) { return t.position; })
            .sort(function (a, b) { return a.position - b.position; })
            .map(function (t) { return '<li><b>' + t.position + '</b><span>' + esc(t.name) + '</span></li>'; }).join('')
            || '<li class="tir-hint">' + esc(T('NothingDrawn')) + '</li>';
    }

    /* Stages are not drawn: they are shown in their own order, one at a time, as
       the speaker announces them. "Show" gives the stage a place like a drawn
       team, which is what the public screen reads as "turned over". */
    function renderStages(cat, list, order) {
        var shown = cat.teams.filter(function (t) { return t.position; }).length;
        var total = cat.teams.length;
        var nextStage = cat.teams.find(function (t) { return !t.position; });

        document.getElementById('tir-next').innerHTML = (shown >= total && total
            ? '<span class="tir-pill tir-pill-ok">' + esc(T('AllStagesShown')) + '</span>'
            : esc(T('StagesShown', { shown: shown, total: total })))
            + (nextStage ? ' <button type="button" class="tir-btn tir-btn-primary tir-btn-big" data-draw="' + nextStage.id + '">▶ '
                + esc(T('ShowNextStage')) + '</button>' : '');

        list.innerHTML = cat.teams.map(function (t, k) {
            return '<div class="tir-team' + (t.position ? ' tir-done' : '') + '">'
                + '<span class="tir-pos tir-stage-num">' + esc(T('StageN', k + 1)) + '</span>'
                + '<span class="tir-name">' + esc(t.name) + (t.detail ? ' <span class="tir-hint">' + esc(t.detail) + '</span>' : '') + '</span>'
                + (t.position
                    ? '<button type="button" class="tir-btn tir-btn-danger" data-unassign="' + t.id + '" title="' + esc(T('HideStage')) + '">✕</button>'
                    : '<button type="button" class="tir-btn tir-draw" data-draw="' + t.id + '">' + esc(T('ShowStage')) + '</button>')
                + '</div>';
        }).join('') || '<p class="tir-hint">' + esc(T('NoStage')) + '</p>';

        order.innerHTML = cat.teams.filter(function (t) { return t.position; })
            .sort(function (a, b) { return a.position - b.position; })
            .map(function (t) { return '<li><b>' + t.position + '</b><span>' + esc(t.name) + '</span></li>'; }).join('')
            || '<li class="tir-hint">' + esc(T('NothingDrawn')) + '</li>';
    }

    /* Club codes in drawn order, as the Setup screen of the first division
       competition takes them: one text box per event, codes in place order. That
       screen skips empty lines, so a team without a code would move every team
       after it up one place — the list is refused until every code is known. */
    function renderPaste(cat) {
        var card = document.getElementById('tir-paste-card');
        var box = document.getElementById('tir-paste');
        card.hidden = cat.type === 'stages';
        if (card.hidden) return;

        var missing = cat.teams.filter(function (t) { return !t.club; });
        var drawn = cat.teams.filter(function (t) { return t.position; }).sort(function (a, b) { return a.position - b.position; });
        if (missing.length) {
            box.innerHTML = '<div class="tir-msg tir-msg-err">' + esc(T('PasteMissingCode', missing.map(function (t) { return t.name; }).join(', '))) + '</div>';
            return;
        }
        if (!drawn.length) {
            box.innerHTML = '<p class="tir-hint">' + esc(T('NothingDrawn')) + '</p>';
            return;
        }
        box.innerHTML = '<p class="tir-hint">' + esc(cat.event ? T('PasteHint', cat.event) : T('PasteHintNoEvent')) + '</p>'
            + (drawn.length < cat.teams.length
                ? '<p><span class="tir-pill tir-pill-warn">' + esc(T('PasteIncomplete', { drawn: drawn.length, total: cat.teams.length })) + '</span></p>' : '')
            + '<textarea class="tir-paste" readonly rows="' + Math.min(drawn.length, 16) + '">'
            + esc(drawn.map(function (t) { return t.club; }).join('\n')) + '</textarea>'
            + '<p><button type="button" class="tir-btn tir-btn-primary" data-act="copyPaste">' + esc(T('CopyList')) + '</button></p>';
    }

    /* The public screen is laid out for 1920x1080; the preview shrinks it to fit. */
    function scalePreview() {
        var box = document.getElementById('tir-preview');
        if (!box) return;
        var frame = box.querySelector('iframe');
        frame.style.transform = 'scale(' + (box.clientWidth / 1920) + ')';
    }

    /* ---------------------------------------------------------------- events */

    root.addEventListener('click', function (e) {
        var el = e.target.closest('button');
        if (!el) return;
        var cat = currentCategory();
        if (el.hasAttribute('data-draw')) {
            act('draw', { team: el.getAttribute('data-draw') });
        } else if (el.hasAttribute('data-unassign')) {
            act('unassign', { team: el.getAttribute('data-unassign') });
        } else if (el.hasAttribute('data-scene')) {
            act('scene', { scene: el.getAttribute('data-scene'), cat: cat ? cat.id : 0 });
        } else if (el.hasAttribute('data-cat')) {
            search = '';
            document.getElementById('tir-search').value = '';
            act('scene', { scene: state.scene, cat: el.getAttribute('data-cat') });
        } else if (el.getAttribute('data-act') === 'undo' && cat) {
            act('undo', { cat: cat.id });
        } else if (el.getAttribute('data-act') === 'reset' && cat) {
            if (confirm(T('ConfirmReset', cat.name))) act('reset', { cat: cat.id });
        } else if (el.getAttribute('data-act') === 'copyPaste') {
            var area = root.querySelector('.tir-paste');
            area.select();
            // The clipboard API needs a secure page; on a plain http address of the
            // local network the selection is copied the older way.
            (navigator.clipboard && window.isSecureContext
                ? navigator.clipboard.writeText(area.value)
                : Promise.resolve(document.execCommand('copy')))
                .then(function () { TIR.toast(T('ListCopied')); });
        }
    });

    root.addEventListener('change', function (e) {
        if (!e.target.hasAttribute('data-stat')) return;
        var list = Array.prototype.map.call(root.querySelectorAll('[data-stat]:checked'), function (x) {
            return x.getAttribute('data-stat');
        });
        act('stats', { list: list.join(',') });
    });

    poll();
})();
