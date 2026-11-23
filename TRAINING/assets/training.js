/*
 * Browser side of the training module: list filter, ticking, the team editor,
 * the ordering of the ranking page and the licence file search.
 * Texts come from window.TRN_T, published by the page in the user's language.
 */
(function () {
    'use strict';
    // The page loads this file in its head, before the body exists: wait for it.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        var T = window.TRN_T || {};
        function t(key, a) {
            var s = T[key] || key;
            return a === undefined ? s : s.replace('{$a}', a);
        }
        function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
        function el(tag, cls, text) {
            var e = document.createElement(tag);
            if (cls) e.className = cls;
            if (text !== undefined) e.textContent = text;
            return e;
        }

        /* Filter of a list by typed text. */
        all('.trn-filter').forEach(function (input) {
            input.addEventListener('input', function () {
                var q = input.value.toLowerCase().trim();
                all(input.dataset.target + ' .trn-row').forEach(function (row) {
                    row.style.display = !q || row.textContent.toLowerCase().indexOf(q) >= 0 ? '' : 'none';
                });
            });
        });

        /* Tick everything visible. */
        all('.trn-check-all').forEach(function (box) {
            box.addEventListener('change', function () {
                all(box.dataset.target + ' input[type=checkbox]').forEach(function (c) {
                    if (c === box) return;
                    var row = c.closest('tr, label');
                    if (row && row.style.display === 'none') return;
                    c.checked = box.checked;
                });
                refresh();
            });
        });

        /* Tick a whole group of archers. */
        all('.trn-group-pick').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var rows = all('#trn-pick tr[data-groups]').filter(function (r) {
                    return (',' + r.dataset.groups + ',').indexOf(',' + btn.dataset.group + ',') >= 0;
                });
                var allOn = rows.every(function (r) { return r.querySelector('.trn-pick').checked; });
                rows.forEach(function (r) { r.querySelector('.trn-pick').checked = !allOn; });
                refresh();
            });
        });
        all('.trn-pick').forEach(function (c) { c.addEventListener('change', refresh); });

        /* Team editor. */
        var peopleNode = document.getElementById('trn-people');
        var people = peopleNode ? JSON.parse(peopleNode.textContent) : {};
        var picks = all('.trn-pick');

        function candidates() {
            if (!picks.length) return Object.keys(people);
            return picks.filter(function (c) { return c.checked; }).map(function (c) { return c.dataset.id; });
        }

        var editors = all('.trn-teams').map(function (box) {
            var kind = box.dataset.kind;
            var teams = JSON.parse(box.dataset.teams || '[]');
            var list = box.querySelector('.trn-team-list');

            function used(except) {
                var u = {};
                teams.forEach(function (tm, i) { if (i !== except) tm.members.forEach(function (m) { u[m] = true; }); });
                return u;
            }

            function render() {
                var allowed = {};
                candidates().forEach(function (k) { allowed[k] = true; });
                list.innerHTML = '';
                teams.forEach(function (tm, i) {
                    tm.members = tm.members.filter(function (m) { return allowed[m]; });
                    var row = el('div', 'trn-team');
                    var label = el('label', 'trn-team-name', t(kind === 'team' ? 'TeamN' : 'MixedN', i + 1) + ' : ');
                    var name = el('input');
                    name.type = 'text';
                    name.maxLength = 30;
                    name.name = 'team_name[' + kind + '][' + i + ']';
                    name.value = tm.name;
                    name.placeholder = t(kind === 'team' ? 'TeamN' : 'MixedN', i + 1);
                    name.addEventListener('input', function () { tm.name = name.value; });
                    label.appendChild(name);
                    row.appendChild(label);

                    var hidId = el('input');
                    hidId.type = 'hidden';
                    hidId.name = 'team_id[' + kind + '][' + i + ']';
                    hidId.value = tm.id || 0;
                    row.appendChild(hidId);
                    var hidMem = el('input');
                    hidMem.type = 'hidden';
                    hidMem.name = 'team_members[' + kind + '][' + i + ']';
                    hidMem.value = tm.members.join(',');
                    row.appendChild(hidMem);

                    tm.members.forEach(function (m) {
                        var chip = el('span', 'trn-chip', people[m] || m);
                        var x = el('button', 'trn-chip-x', '×');
                        x.type = 'button';
                        x.title = t('Remove');
                        x.addEventListener('click', function () {
                            tm.members = tm.members.filter(function (k) { return k !== m; });
                            render();
                        });
                        chip.appendChild(x);
                        row.appendChild(chip);
                    });

                    var taken = used(-1);
                    var sel = el('select');
                    sel.appendChild(new Option(t('AddArcher'), ''));
                    candidates().forEach(function (k) {
                        if (!taken[k]) sel.appendChild(new Option(people[k] || k, k));
                    });
                    sel.addEventListener('change', function () {
                        if (sel.value) { tm.members.push(sel.value); render(); }
                    });
                    row.appendChild(sel);

                    var del = el('button', 'trn-btn trn-danger', t('RemoveTeam'));
                    del.type = 'button';
                    del.addEventListener('click', function () { teams.splice(i, 1); render(); });
                    row.appendChild(del);
                    list.appendChild(row);
                });
            }

            box.querySelector('.trn-team-add').addEventListener('click', function () {
                teams.push({ id: 0, name: '', members: [] });
                render();
            });
            render();
            return render;
        });

        function refresh() {
            var n = picks.filter(function (c) { return c.checked; }).length;
            var count = document.getElementById('trn-count');
            if (count) count.textContent = t('CountPicked', n);
            editors.forEach(function (r) { r(); });
        }
        refresh();

        /* Ranking: drag and drop, arrows, and the qualified line. */
        all('.trn-sortable').forEach(function (ol) {
            var dragged = null;
            function mark() {
                var first = parseInt(ol.dataset.first, 10) || 1;
                var last = parseInt(ol.dataset.last, 10) || 0;
                all('li', ol).forEach(function (li, i) {
                    var rank = i + 1;
                    li.classList.toggle('trn-out', rank < first || rank > last);
                });
            }
            all('li', ol).forEach(function (li) {
                li.addEventListener('dragstart', function (ev) {
                    dragged = li;
                    li.classList.add('trn-dragging');
                    ev.dataTransfer.effectAllowed = 'move';
                    ev.dataTransfer.setData('text/plain', li.dataset.key);
                });
                li.addEventListener('dragend', function () { li.classList.remove('trn-dragging'); dragged = null; mark(); });
                li.addEventListener('dragover', function (ev) {
                    if (!dragged || dragged === li || dragged.parentNode !== ol) return;
                    ev.preventDefault();
                    var r = li.getBoundingClientRect();
                    ol.insertBefore(dragged, ev.clientY > r.top + r.height / 2 ? li.nextSibling : li);
                });
                li.querySelector('.trn-up').addEventListener('click', function () {
                    if (li.previousElementSibling) ol.insertBefore(li, li.previousElementSibling);
                    mark();
                });
                li.querySelector('.trn-down').addEventListener('click', function () {
                    if (li.nextElementSibling) ol.insertBefore(li.nextElementSibling, li);
                    mark();
                });
            });
            mark();
            var form = ol.nextElementSibling;
            if (form && form.classList.contains('trn-launch')) {
                form.addEventListener('submit', function () {
                    form.querySelector('input[name=order]').value = all('li', ol).map(function (li) { return li.dataset.key; }).join(',');
                });
            }
        });

        /*
         * Category from the date of birth, the rule of the core (Partecipants/Get-Classes.php):
         * age = year of the last day - year of birth, between the class bounds, for the archer's sex,
         * in the archer's bow (recurve when none). The recorded class is kept only when it fits.
         * Same algorithm as trn_default_category() on the server.
         */
        function fits(c, birth, sex, year) {
            if (c.sex !== -1 && c.sex !== sex) return false;
            if (!birth) return true;
            var age = year - birth;
            return age >= c.from && age <= c.to;
        }
        function defaultCategory(cats, birth, sex, div, cls, year) {
            if (!cats.length) return '';
            div = div || 'CL';
            var bows = [div, null];
            for (var b = 0; b < bows.length; b++) {
                var first = null;
                for (var i = 0; i < cats.length; i++) {
                    var c = cats[i];
                    if (bows[b] !== null && c.div !== bows[b]) continue;
                    if (!fits(c, birth, sex, year)) continue;
                    if (c.cls === cls) return c.div + '|' + c.cls;
                    if (!first) first = c;
                }
                if (first) return first.div + '|' + first.cls;
            }
            return '';
        }

        var catsNode = document.getElementById('trn-cats');
        var createForm = document.getElementById('trn-create');
        if (catsNode && createForm) {
            var cats = JSON.parse(catsNode.textContent);
            var days = parseInt(createForm.dataset.days, 10) || 1;
            var selects = all('select.trn-cat', createForm);
            selects.forEach(function (sel) { sel.addEventListener('change', function () { sel.dataset.touched = '1'; }); });
            var dateInput = createForm.elements.date;
            var fr = createForm.dataset.fr === '1';
            var recompute = function () {
                var d = new Date(dateInput.value + 'T12:00:00Z');
                if (isNaN(d.getTime())) return;
                // French rules: from September on, the next season (trn_season_year() on the server).
                var next = fr && d.getUTCMonth() >= 8 ? 1 : 0;
                d.setUTCDate(d.getUTCDate() + days - 1);
                var year = d.getUTCFullYear() + next;
                selects.forEach(function (sel) {
                    if (sel.dataset.touched) return;
                    var v = defaultCategory(cats, parseInt(sel.dataset.birth, 10) || 0, parseInt(sel.dataset.sex, 10) || 0,
                        sel.dataset.div, sel.dataset.cls, year);
                    if (v) sel.value = v;
                });
            };
            dateInput.addEventListener('change', recompute);
            recompute();
        }

        /* Class of the archer form, from the date of birth. */
        var classNode = document.getElementById('trn-classes');
        var archerForm = document.getElementById('trn-archer-form');
        function fillClass() {
            if (!classNode || !archerForm) return;
            var rules = JSON.parse(classNode.textContent);
            var f = archerForm.elements;
            if (!f.div.value) f.div.value = 'CL';
            var birth = parseInt((f.dob.value || '').slice(0, 4), 10) || 0;
            if (!birth) return;
            var sex = parseInt(f.sex.value, 10) || 0;
            var year = parseInt(archerForm.dataset.year, 10);
            var bowCats = rules.filter(function (r) {
                return !r.divs || (',' + r.divs + ',').indexOf(',' + f.div.value + ',') >= 0;
            }).map(function (r) { return { div: f.div.value, cls: r.cls, sex: r.sex, from: r.from, to: r.to }; });
            var v = defaultCategory(bowCats, birth, sex, f.div.value, f.cls.value, year);
            if (v) f.cls.value = v.split('|')[1];
        }
        if (archerForm) {
            ['dob', 'sex', 'div'].forEach(function (n) { archerForm.elements[n].addEventListener('change', fillClass); });
        }

        /* Licence file search, on the archers page. */
        var look = document.getElementById('trn-lookup');
        if (look) {
            var res = document.getElementById('trn-lookup-res');
            var form = document.getElementById('trn-archer-form');
            var timer = null;
            look.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    var q = look.value.trim();
                    res.innerHTML = '';
                    if (q.length < 2) return;
                    fetch('archers.php?lookup=' + encodeURIComponent(q), { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (j) {
                            res.innerHTML = '';
                            if (j.error !== 0 || !j.rows.length) { res.appendChild(el('p', 'trn-note', t('LookupNone'))); return; }
                            j.rows.forEach(function (row) {
                                var b = el('button', 'trn-btn trn-lookup-row',
                                    row.family + ' ' + row.given + ' - ' + row.code + ' - ' + row.clubname + ' (' + row.div + row.cls + ')');
                                b.type = 'button';
                                b.addEventListener('click', function () {
                                    Object.keys(row).forEach(function (k) {
                                        var f = form.elements[k];
                                        if (f) f.value = row[k];
                                    });
                                    fillClass();
                                    res.innerHTML = '';
                                    look.value = '';
                                });
                                res.appendChild(b);
                            });
                        })
                        .catch(function () { res.appendChild(el('p', 'trn-note', t('LookupNone'))); });
                }, 300);
            });
        }
    }
})();
