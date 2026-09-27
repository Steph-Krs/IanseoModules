/* Preparation page of the live draw module.

   Loads the whole draw in one call, renders it, and saves every field on its
   own as soon as it changes: a preparation spread over several evenings never
   depends on a final "save" button. Structural actions (a category added, a
   team removed, an import) reload the draw afterwards, so what is on screen is
   always what the database holds. */

(function () {
    'use strict';

    var P = window.TIR_PAGE;
    var T = TIR.T, esc = TIR.esc;
    var root = document.getElementById('tir-edit');
    var data = null;

    var FONT_LABELS = {
        impact: 'Impact', arial: 'Arial', segoe: 'Segoe UI', trebuchet: 'Trebuchet MS', verdana: 'Verdana',
        calibri: 'Calibri', georgia: 'Georgia', palatino: 'Palatino', times: 'Times New Roman'
    };
    var STATS = ['participations', 'wins', 'podiums', 'rank'];
    var STAT_LABELS = { participations: 'StatParticipations', wins: 'StatWins', podiums: 'StatPodiums', rank: 'StatRank' };

    function api(action, params) {
        var p = params instanceof FormData ? params : Object.assign({ id: P.id }, params || {});
        if (params instanceof FormData) p.append('id', P.id);
        return TIR.call(P.api, action, p, P.csrf);
    }

    function fail(e) { TIR.toast(e.message, true); }

    function load() {
        var y = window.scrollY;
        return api('load').then(function (j) {
            data = j;
            render();
            window.scrollTo(0, y);
        }).catch(function (e) {
            root.innerHTML = '<div class="tir-msg tir-msg-err">' + esc(e.message) + '</div>';
        });
    }

    /* ---------------------------------------------------------------- render */

    function render() {
        root.innerHTML = renderGeneral() + renderSeason() + renderCategories() + renderAddCategory() + renderLook();
    }

    function options(list, current, withNone) {
        var html = withNone ? '<option value="">' + esc(T('EventNone')) + '</option>' : '';
        list.forEach(function (o) {
            html += '<option value="' + esc(o.value) + '"' + (String(o.value) === String(current) ? ' selected' : '') + '>'
                + esc(o.label) + '</option>';
        });
        return html;
    }

    function eventOptions(current) {
        return options(data.events.map(function (e) {
            return { value: e.code, label: e.code + ' — ' + e.name };
        }), current, true);
    }

    function renderGeneral() {
        var s = data.show;
        var comps = [{ value: 0, label: T('SourceNone') }].concat(data.competitions.map(function (c) {
            return { value: c.id, label: c.date + ' — ' + c.code + ' — ' + c.name };
        }));
        var link = function (key, url) {
            return '<div class="tir-link"><b>' + esc(T(key)) + '</b>'
                + '<input type="text" readonly value="' + esc(location.origin + url) + '">'
                + '<button type="button" class="tir-btn" data-act="copy">' + esc(T('Copy')) + '</button>'
                + '<a class="tir-btn" target="_blank" href="' + esc(url) + '">' + esc(T('Open')) + '</a></div>';
        };
        return '<div class="tir-card"><h2>' + esc(T('SectionGeneral')) + '</h2>'
            + '<div class="tir-row">'
            + '<label class="tir-wide">' + esc(T('FieldTitle')) + '<input type="text" maxlength="120" data-show="title" value="' + esc(s.title) + '"></label>'
            + '<label class="tir-wide">' + esc(T('FieldSubtitle')) + '<input type="text" maxlength="160" data-show="subtitle" value="' + esc(s.subtitle) + '"></label>'
            + '</div><div class="tir-row">'
            + '<label class="tir-wide">' + esc(T('FieldSource')) + '<select data-show="source">' + options(comps, s.source) + '</select></label>'
            + '</div><p class="tir-hint">' + esc(T('FieldSourceHint')) + '</p>'
            + '<h3>' + esc(T('LinksTitle')) + '</h3><p class="tir-hint">' + esc(T('LinksHint')) + '</p>'
            + link('OpenDisplay', data.links.display) + link('OpenSpeaker', data.links.speaker)
            + '<p><button type="button" class="tir-btn" data-act="newTokens">' + esc(T('NewTokens')) + '</button> '
            + '<span class="tir-hint">' + esc(T('NewTokensHint')) + '</span></p>'
            + '</div>';
    }

    function renderSeason() {
        if (!data.source) {
            return '<div class="tir-card"><h2>' + esc(T('SectionSeasonNone')) + '</h2><p class="tir-hint">'
                + esc(T('SeasonNoSource')) + '</p></div>';
        }
        var src = data.source, show = data.show;
        var linked = {};
        data.categories.forEach(function (c) { if (c.event) linked[c.event] = true; });
        var boxes = data.events.filter(function (e) { return e.main; }).map(function (e) {
            return '<label><input type="checkbox" data-event="' + esc(e.code) + '"' + (linked[e.code] ? '' : ' checked') + '> '
                + esc(e.code + ' — ' + e.name) + (linked[e.code] ? ' <span class="tir-pill">' + esc(T('AlreadyListed')) + '</span>' : '')
                + '</label>';
        }).join('');
        var applied = show.seasonApplied === src.id;

        return '<div class="tir-card"><h2>' + esc(T('SectionSeason', src.name)) + '</h2>'
            + '<p>' + esc(T('ImportEventsLead')) + '</p><div class="tir-events">' + (boxes || esc(T('NoTeamEvent'))) + '</div>'
            + '<p class="tir-actions"><button type="button" class="tir-btn tir-btn-primary" data-act="importEvents">' + esc(T('ImportEvents')) + '</button>'
            + '<button type="button" class="tir-btn" data-act="linkClubs">' + esc(T('LinkClubs')) + '</button>'
            + '<span class="tir-hint">' + esc(T('LinkClubsHint')) + '</span></p>'
            + '<p class="tir-actions"><button type="button" class="tir-btn" data-act="applySeason"' + (applied ? ' disabled' : '') + '>'
            + esc(T('ApplySeason', src.year)) + '</button>'
            + (applied ? '<span class="tir-pill tir-pill-ok">' + esc(T('SeasonAppliedPill', src.year)) + '</span>'
                       : '<span class="tir-hint">' + esc(T('ApplySeasonHint')) + '</span>') + '</p>'
            + '<p><span class="tir-pill ' + (data.national ? 'tir-pill-ok' : 'tir-pill-warn') + '">'
            + esc(T(data.national ? 'NationalOn' : 'NationalOff')) + '</span></p>'
            + '</div>';
    }

    function typeSelect(current, attrs) {
        return '<select ' + attrs + ' title="' + esc(T('FieldType')) + '">' + options([
            { value: 'teams', label: T('TypeTeams') }, { value: 'stages', label: T('TypeStages') }
        ], current) + '</select>';
    }

    function categoryHeader(c, i) {
        return '<h2>'
            + '<input type="text" maxlength="120" data-cat-field="name" value="' + esc(c.name) + '" title="' + esc(T('FieldName')) + '">'
            + typeSelect(c.type, 'data-cat-field="type"')
            + (data.source && c.type === 'teams' ? '<select data-cat-field="event" title="' + esc(T('FieldEvent')) + '">' + eventOptions(c.event) + '</select>' : '')
            + '<span class="tir-grow"></span><span class="tir-pill">'
            + esc(c.type === 'stages' ? T('StageCount', c.teams.length) : T('TeamCount', c.teams.length)) + '</span>'
            + '<button type="button" class="tir-btn" data-act="moveUp" title="' + esc(T('MoveUp')) + '"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
            + '<button type="button" class="tir-btn" data-act="moveDown" title="' + esc(T('MoveDown')) + '"' + (i === data.categories.length - 1 ? ' disabled' : '') + '>↓</button>'
            + '<button type="button" class="tir-btn tir-btn-danger" data-act="deleteCategory">' + esc(T('DeleteCategory')) + '</button>'
            + '</h2>';
    }

    function addLines(c) {
        var stages = c.type === 'stages';
        return '<div class="tir-row" style="margin-top:8px"><label class="tir-wide">' + esc(T(stages ? 'AddStages' : 'AddTeams'))
            + '<textarea rows="2" data-add-lines placeholder="' + esc(T(stages ? 'AddStagesPlaceholder' : 'AddTeamsPlaceholder')) + '"></textarea></label>'
            + '<button type="button" class="tir-btn" data-act="addTeams">' + esc(T('Add')) + '</button></div>';
    }

    /* A list of stages: shown one by one in this order, so the order can be changed. */
    function renderStagesCard(c, i) {
        var rows = c.teams.map(function (t, k) {
            return '<tr data-team="' + t.id + '">'
                + '<td class="tir-nowrap"><b>' + esc(T('StageN', k + 1)) + '</b></td>'
                + '<td><input type="text" maxlength="120" data-f="name" value="' + esc(t.name) + '"></td>'
                + '<td><input type="text" maxlength="160" data-f="detail" value="' + esc(t.detail) + '"></td>'
                + '<td><textarea class="tir-note" data-f="note" maxlength="4000">' + esc(t.note) + '</textarea></td>'
                + '<td class="tir-nowrap">'
                + '<button type="button" class="tir-btn" data-act="teamUp" title="' + esc(T('MoveUp')) + '"' + (k === 0 ? ' disabled' : '') + '>↑</button> '
                + '<button type="button" class="tir-btn" data-act="teamDown" title="' + esc(T('MoveDown')) + '"' + (k === c.teams.length - 1 ? ' disabled' : '') + '>↓</button> '
                + '<button type="button" class="tir-btn tir-btn-danger" data-act="deleteTeam" title="' + esc(T('DeleteStage')) + '">✕</button></td>'
                + '</tr>';
        }).join('');
        return '<div class="tir-card tir-catcard" data-cat="' + c.id + '">' + categoryHeader(c, i)
            + '<p class="tir-hint">' + esc(T('StagesHint')) + '</p>'
            + '<div class="tir-scroll"><table class="tir-table"><thead><tr><th></th>'
            + '<th>' + esc(T('ColStageName')) + '</th><th>' + esc(T('ColStageDetail')) + '</th>'
            + '<th>' + esc(T('ColNote')) + '</th><th></th></tr></thead><tbody>'
            + (rows || '<tr><td colspan="5" class="tir-hint">' + esc(T('NoStage')) + '</td></tr>')
            + '</tbody></table></div>' + addLines(c) + '</div>';
    }

    function renderCategories() {
        return data.categories.map(function (c, i) {
            if (c.type === 'stages') return renderStagesCard(c, i);
            var clubs = data.clubs[c.event] || [];
            var datalist = c.event ? '<datalist id="tir-clubs-' + c.id + '">' + clubs.map(function (k) {
                return '<option value="' + esc(k.code) + '">' + esc(k.name) + '</option>';
            }).join('') + '</datalist>' : '';

            var rows = c.teams.map(function (t) {
                var cells = STATS.map(function (k) {
                    var v = t.stats[k];
                    return '<td><input type="number" min="0" class="tir-num" data-f="' + k + '" value="' + (v === null ? '' : v) + '"></td>';
                }).join('');
                return '<tr data-team="' + t.id + '">'
                    + '<td><input type="text" maxlength="120" data-f="name" value="' + esc(t.name) + '"></td>'
                    + '<td class="tir-club"><input type="text" maxlength="10" data-f="club" value="' + esc(t.club) + '"'
                    + (c.event ? ' list="tir-clubs-' + c.id + '"' : '') + '></td>'
                    + '<td class="tir-hintcell" data-hint>' + (t.hint ? esc(t.hint) : '–') + '</td>'
                    + cells
                    + '<td><textarea class="tir-note" data-f="note" maxlength="4000">' + esc(t.note) + '</textarea></td>'
                    + '<td><button type="button" class="tir-btn tir-btn-danger" data-act="deleteTeam" title="' + esc(T('DeleteTeam')) + '">✕</button></td>'
                    + '</tr>';
            }).join('');

            return '<div class="tir-card tir-catcard" data-cat="' + c.id + '">' + categoryHeader(c, i) + datalist
                + '<div class="tir-scroll"><table class="tir-table"><thead><tr>'
                + '<th>' + esc(T('ColTeam')) + '</th><th>' + esc(T('ColClub')) + '</th>'
                + '<th title="' + esc(T('ColHintTitle')) + '">' + esc(T('ColHint')) + '</th>'
                + STATS.map(function (k) { return '<th>' + esc(T(STAT_LABELS[k])) + '</th>'; }).join('')
                + '<th>' + esc(T('ColNote')) + '</th><th></th></tr></thead><tbody>'
                + (rows || '<tr><td colspan="9" class="tir-hint">' + esc(T('NoTeam')) + '</td></tr>')
                + '</tbody></table></div>' + addLines(c) + '</div>';
        }).join('');
    }

    function renderAddCategory() {
        return '<div class="tir-card"><h2>' + esc(T('AddCategory')) + '</h2><div class="tir-row">'
            + '<label class="tir-wide">' + esc(T('FieldName')) + '<input type="text" maxlength="120" id="tir-newcat-name"></label>'
            + '<label>' + esc(T('FieldType')) + typeSelect('teams', 'id="tir-newcat-type"') + '</label>'
            + (data.source ? '<label>' + esc(T('FieldEvent')) + '<select id="tir-newcat-event">' + eventOptions('') + '</select></label>' : '')
            + '<button type="button" class="tir-btn tir-btn-primary" data-act="addCategory">' + esc(T('Add')) + '</button>'
            + '</div><p class="tir-hint">' + esc(T('AddCategoryHint')) + '</p></div>';
    }

    function renderLook() {
        var L = data.show.look;
        var color = function (k, label) {
            return '<label>' + esc(T(label)) + '<input type="color" data-look="' + k + '" value="' + esc(L[k]) + '"></label>';
        };
        var range = function (k, label, min, max, step, unit) {
            return '<label>' + esc(T(label)) + '<input type="range" data-look="' + k + '" min="' + min + '" max="' + max
                + '" step="' + step + '" value="' + L[k] + '"><output data-unit="' + unit + '">' + fmt(L[k], unit) + '</output></label>';
        };
        var font = function (k, label) {
            return '<label>' + esc(T(label)) + '<select data-look="' + k + '">' + options(data.fonts.map(function (f) {
                return { value: f, label: FONT_LABELS[f] || f };
            }), L[k]) + '</select></label>';
        };
        var check = function (k, label) {
            return '<label>' + esc(T(label)) + '<input type="checkbox" data-look="' + k + '"' + (L[k] ? ' checked' : '') + '></label>';
        };
        return '<div class="tir-card"><h2>' + esc(T('SectionLook')) + '<span class="tir-grow"></span>'
            + '<a class="tir-btn" target="_blank" href="' + esc(data.links.display) + '">' + esc(T('Preview')) + '</a></h2>'
            + '<div class="tir-look">'
            + color('bg', 'LookBg') + color('accent', 'LookAccent') + color('text', 'LookText')
            + font('fontTitle', 'LookFontTitle') + font('fontBody', 'LookFontBody')
            + range('sizeTitle', 'LookSizeTitle', 1.5, 7, 0.1, 'rem') + range('sizeTeam', 'LookSizeTeam', 0.8, 4, 0.05, 'rem')
            + range('sizeRank', 'LookSizeRank', 0.8, 4, 0.05, 'rem') + range('margin', 'LookMargin', 0, 30, 1, '%')
            + range('overlay', 'LookOverlay', 0, 0.95, 0.05, 'pct')
            + range('rankSpace', 'LookRankSpace', 1, 5, 0.1, 'em') + range('statsSpace', 'LookStatsSpace', 0, 4, 0.1, 'rem')
            + range('statGap', 'LookStatGap', 0, 3, 0.1, 'em') + range('statWidth', 'LookStatWidth', 1.5, 6, 0.1, 'em')
            + check('ambient', 'LookAmbient') + check('slots', 'LookSlots')
            + '</div><h3>' + esc(T('LookImage')) + '</h3><p class="tir-actions">'
            + '<span class="tir-pill ' + (data.show.hasImage ? 'tir-pill-ok' : '') + '">' + esc(T(data.show.hasImage ? 'ImageSet' : 'ImageNone')) + '</span>'
            + '<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-image>'
            + (data.show.hasImage ? '<button type="button" class="tir-btn tir-btn-danger" data-act="clearImage">' + esc(T('ImageClear')) + '</button>' : '')
            + '</p><p class="tir-hint">' + esc(T('LookImageHint')) + '</p></div>';
    }

    function fmt(v, unit) {
        if (unit === 'pct') return Math.round(v * 100) + ' %';
        return v + ' ' + unit;
    }

    /* ---------------------------------------------------------------- events */

    function flash(el, ok) {
        el.classList.remove('tir-saved', 'tir-failed');
        el.classList.add(ok ? 'tir-saved' : 'tir-failed');
        if (ok) setTimeout(function () { el.classList.remove('tir-saved'); }, 1200);
    }

    function lookValues() {
        var look = Object.assign({}, data.show.look);
        root.querySelectorAll('[data-look]').forEach(function (el) {
            var k = el.getAttribute('data-look');
            look[k] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : (el.type === 'range' ? parseFloat(el.value) : el.value);
        });
        return look;
    }

    root.addEventListener('input', function (e) {
        var el = e.target;
        if (el.type === 'range' && el.nextElementSibling && el.nextElementSibling.tagName === 'OUTPUT') {
            el.nextElementSibling.textContent = fmt(parseFloat(el.value), el.nextElementSibling.getAttribute('data-unit'));
        }
    });

    root.addEventListener('change', function (e) {
        var el = e.target;

        if (el.hasAttribute('data-show')) {
            var key = el.getAttribute('data-show'), p = {};
            p[key] = el.value;
            api('saveShow', p).then(function () {
                flash(el, true);
                if (key === 'title') document.getElementById('tir-title-h').textContent = el.value;
                if (key === 'source') load();
            }).catch(function (err) { flash(el, false); fail(err); });
            return;
        }

        if (el.hasAttribute('data-look')) {
            var look = lookValues();
            api('saveShow', { look: JSON.stringify(look) }).then(function () {
                data.show.look = look;
            }).catch(fail);
            return;
        }

        if (el.hasAttribute('data-image')) {
            if (!el.files.length) return;
            var fd = new FormData();
            fd.append('image', el.files[0]);
            api('uploadImage', fd).then(load).catch(fail);
            return;
        }

        if (el.hasAttribute('data-cat-field')) {
            var card = el.closest('[data-cat]'), field = el.getAttribute('data-cat-field'), q = { cat: card.getAttribute('data-cat') };
            q[field] = el.value;
            api('saveCategory', q).then(function () {
                flash(el, true);
                if (field === 'event' || field === 'type') load();
            }).catch(function (err) { flash(el, false); fail(err); });
            return;
        }

        if (el.hasAttribute('data-f')) {
            var row = el.closest('[data-team]'), f = el.getAttribute('data-f');
            api('saveTeam', { team: row.getAttribute('data-team'), field: f, value: el.value }).then(function () {
                flash(el, true);
                if (f === 'club') updateHint(row, el.value);
            }).catch(function (err) { flash(el, false); fail(err); });
        }
    });

    /* The previous-season rank next to a club code, without reloading the page. */
    function updateHint(row, code) {
        var cat = data.categories.find(function (c) { return String(c.id) === row.closest('[data-cat]').getAttribute('data-cat'); });
        var club = cat && (data.clubs[cat.event] || []).find(function (k) { return k.code === code.trim(); });
        row.querySelector('[data-hint]').textContent = club ? club.rank : '–';
    }

    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        var act = btn.getAttribute('data-act');
        var card = btn.closest('[data-cat]');
        var cat = card ? card.getAttribute('data-cat') : null;
        var done = function (j) { if (j && j.msg) TIR.toast(j.msg); return load(); };

        switch (act) {
            case 'copy':
                var input = btn.parentNode.querySelector('input');
                input.select();
                (navigator.clipboard && window.isSecureContext
                    ? navigator.clipboard.writeText(input.value)
                    : Promise.resolve(document.execCommand('copy')))
                    .then(function () { TIR.toast(T('Copied')); });
                break;
            case 'newTokens':
                if (confirm(T('ConfirmNewTokens'))) api('newTokens').then(done).catch(fail);
                break;
            case 'importEvents':
                var events = Array.prototype.map.call(root.querySelectorAll('[data-event]:checked'), function (x) {
                    return x.getAttribute('data-event');
                });
                if (!events.length) { TIR.toast(T('ErrNoEventChecked'), true); break; }
                btn.disabled = true;
                api('importEvents', { events: events }).then(done).catch(function (err) { btn.disabled = false; fail(err); });
                break;
            case 'linkClubs':
                api('linkClubs').then(done).catch(fail);
                break;
            case 'applySeason':
                if (confirm(T('ConfirmApplySeason', data.source.year))) api('applySeason').then(done).catch(fail);
                break;
            case 'moveUp':
            case 'moveDown':
                api('moveCategory', { cat: cat, dir: act === 'moveUp' ? -1 : 1 }).then(done).catch(fail);
                break;
            case 'teamUp':
            case 'teamDown':
                api('moveTeam', { team: btn.closest('[data-team]').getAttribute('data-team'), dir: act === 'teamUp' ? -1 : 1 })
                    .then(done).catch(fail);
                break;
            case 'deleteCategory':
                if (confirm(T('ConfirmDeleteCategory'))) api('deleteCategory', { cat: cat }).then(done).catch(fail);
                break;
            case 'addTeams':
                var lines = card.querySelector('[data-add-lines]').value;
                api('addTeams', { cat: cat, lines: lines }).then(done).catch(fail);
                break;
            case 'deleteTeam':
                var row = btn.closest('[data-team]');
                var name = row.querySelector('[data-f=name]').value;
                if (confirm(T('ConfirmDeleteTeam', name))) api('deleteTeam', { team: row.getAttribute('data-team') }).then(done).catch(fail);
                break;
            case 'addCategory':
                var ev = document.getElementById('tir-newcat-event');
                api('addCategory', {
                    name: document.getElementById('tir-newcat-name').value,
                    type: document.getElementById('tir-newcat-type').value,
                    event: ev ? ev.value : ''
                }).then(done).catch(fail);
                break;
            case 'clearImage':
                api('clearImage').then(done).catch(fail);
                break;
        }
    });

    load();
})();
