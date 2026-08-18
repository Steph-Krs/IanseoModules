/* Plan du terrain — plage de distances par cible + blasons autorisés.
   Portée : #bkfield. Aucune dépendance.

   L'échelle n'est PAS métrique : elle liste les distances réellement utilisées
   par la compétition, régulièrement espacées. Une cible se pose à l'une de ces
   distances, jamais entre deux — d'où l'accrochage systématique. */
(function () {
    'use strict';

    var B = window.BKF;
    if (!B || !document.getElementById('bkf-grid')) return;

    var grid  = document.getElementById('bkf-grid');
    var axis  = document.getElementById('bkf-axis');
    var state = document.getElementById('bkf-state');
    var count = document.getElementById('bkf-count');
    var size  = document.getElementById('bkf-size');

    var caps  = B.caps || {};
    var STEPS = (B.steps || []).slice().sort(function (a, b) { return a - b; });
    var sel   = {};
    var H     = 120;

    var faceLabel = {}, faceColor = {};
    B.faces.forEach(function (f) { faceLabel[f.id] = f.label; if (f.peg && f.color) faceColor[f.id] = f.color; });

    // Sans distance chiffrée (parcours campagne : TdDist = 0), la plage n'a pas
    // de sens. On garde néanmoins l'éditeur pour les blasons — un palier fictif
    // suffit à faire tenir le rendu, et la page l'annonce explicitement.
    var SANS_DIST = !STEPS.length;
    if (SANS_DIST) { STEPS = [0]; H = 0; }

    /* ---------- échelle discrète ---------- */

    function yOfIdx(i) {
        if (STEPS.length === 1) return Math.round(H / 2);
        return Math.round((1 - i / (STEPS.length - 1)) * H);
    }
    function yOf(m) {
        var i = STEPS.indexOf(m);
        return i < 0 ? yOfIdx(idxNear(m)) : yOfIdx(i);
    }
    /** Indice de la distance déclarée la plus proche d'une valeur en mètres. */
    function idxNear(m) {
        var best = 0, d = Infinity;
        STEPS.forEach(function (s, i) {
            var x = Math.abs(s - m);
            if (x < d) { d = x; best = i; }
        });
        return best;
    }
    /** Position verticale → distance déclarée (accrochage). */
    function mOfY(y) {
        if (STEPS.length === 1) return STEPS[0];
        var r = 1 - Math.max(0, Math.min(1, y / H));
        return STEPS[Math.round(r * (STEPS.length - 1))];
    }

    function capOf(t) {
        var c = caps[t] || caps[String(t)];
        return {
            def: (c && +c.def) || 0, min: (c && +c.min) || 0,
            max: (c && +c.max) || 0, f: (c && c.f) || []
        };
    }
    function vide(c) { return !c.def && !c.min && !c.max && !c.f.length; }

    /** Valeurs affichées : une cible non réglée montre la plage complète, grisée.
        Sans ça il n'y aurait rien à saisir sur une cible vierge — on ne pourrait
        jamais la configurer au glisser. */
    function vue(c) {
        return {
            min: c.min || STEPS[0],
            max: c.max || STEPS[STEPS.length - 1],
            def: c.def || c.min || STEPS[0],
            pose: !!(c.min || c.max || c.def)
        };
    }

    /* ---------- rendu ---------- */

    function renderAxis() {
        axis.innerHTML = '';
        axis.style.height = H + 'px';
        if (SANS_DIST) { axis.style.display = 'none'; return; }
        STEPS.forEach(function (m, i) {
            var g = document.createElement('div');
            g.className = 'bkf-gline';
            g.style.top = yOfIdx(i) + 'px';
            g.innerHTML = '<span>' + m + ' m</span>';
            axis.appendChild(g);
        });
    }

    function render() {
        var frag = document.createDocumentFragment();
        B.targets.forEach(function (t) {
            var c = capOf(t), v = vue(c), libre = vide(c);

            var card = document.createElement('div');
            card.className = 'bkf-target' + (libre ? ' bkf-target-free' : '') + (sel[t] ? ' bkf-sel' : '');
            card.setAttribute('data-t', t);

            if (!SANS_DIST) {
                var box = document.createElement('div');
                box.className = 'bkf-box' + (v.pose ? '' : ' bkf-box-off');
                box.style.height = H + 'px';

                var yh = yOf(v.max), yl = yOf(v.min);
                var range = document.createElement('div');
                range.className = 'bkf-range';
                range.style.top = yh + 'px';
                range.style.height = Math.max(4, yl - yh) + 'px';
                box.appendChild(range);

                box.appendChild(handle('max', yOf(v.max), v.max));
                box.appendChild(handle('def', yOf(v.def), v.def));
                box.appendChild(handle('min', yOf(v.min), v.min));

                card.appendChild(box);
            }

            var no = document.createElement('div');
            no.className = 'bkf-target-no';
            no.textContent = t;
            card.appendChild(no);

            if (c.f.length) {
                var fl = document.createElement('div');
                fl.className = 'bkf-faces';
                c.f.forEach(function (id) {
                    var s = document.createElement('span');
                    s.className = 'bkf-tag bkf-tag-f';
                    // Piquet : pastille de couleur devant le nom.
                    if (faceColor[id]) {
                        var dot = document.createElement('span');
                        dot.className = 'bkf-peg-dot';
                        dot.style.background = faceColor[id];
                        s.appendChild(dot);
                    }
                    s.appendChild(document.createTextNode(faceLabel[id] || ('#' + id)));
                    s.title = 'Cliquer pour retirer';
                    s.setAttribute('data-face', id);
                    s.setAttribute('data-target', t);
                    fl.appendChild(s);
                });
                card.appendChild(fl);
            }
            frag.appendChild(card);
        });
        grid.innerHTML = '';
        grid.appendChild(frag);
        majCount();
    }

    function handle(kind, y, val) {
        var h = document.createElement('div');
        h.className = 'bkf-h bkf-h-' + kind;
        h.style.top = y + 'px';
        h.setAttribute('data-h', kind);
        h.title = ({ min: 'Distance mini', def: 'Distance par défaut', max: 'Distance maxi' })[kind];
        h.innerHTML = '<i></i><b>' + val + '</b>';
        return h;
    }

    function majCount() {
        var n = Object.keys(sel).length;
        count.textContent = n === 0 ? 'Aucune cible sélectionnée'
            : (n === 1 ? '1 cible sélectionnée' : n + ' cibles sélectionnées');
        count.className = 'bkf-count' + (n ? ' bkf-count-on' : '');
    }

    function flash(msg, err) {
        state.textContent = msg;
        state.className = 'bkf-state ' + (err ? 'bkf-state-err' : 'bkf-state-ok');
        if (!err) setTimeout(function () { state.textContent = ''; state.className = 'bkf-state'; }, 2500);
    }

    /* ---------- serveur ---------- */

    function post(data, done) {
        var body = new URLSearchParams();
        body.append('bk_csrf', B.token);
        body.append('session', B.session);
        Object.keys(data).forEach(function (k) {
            if (Array.isArray(data[k])) {
                if (!data[k].length) body.append(k + '[]', '');      // liste vide explicite
                else data[k].forEach(function (v) { body.append(k + '[]', v); });
            } else body.append(k, data[k]);
        });
        state.textContent = 'Enregistrement…'; state.className = 'bkf-state';
        return fetch(B.ajax, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (r) { return r.json(); })
          .then(function (j) {
              if (!j || !j.ok) { flash((j && j.err) || 'Échec de l\'enregistrement.', true); return; }
              if (j.caps) caps = j.caps;
              done && done(j);
              render();
          })
          .catch(function () { flash('Serveur injoignable.', true); });
    }

    /** Écrit un lot de cibles en conservant, pour chacune, ce qui n'est pas modifié. */
    function ecrire(targets, patch) {
        if (!targets.length) return;
        var groupes = {};
        targets.forEach(function (t) {
            var c = capOf(t), v = vue(c);
            // Une cible encore vierge prend la plage complète comme point de
            // départ : régler une seule poignée doit produire un réglage complet
            // et cohérent, pas une plage à moitié définie.
            var base = v.pose ? { def: c.def, min: c.min, max: c.max }
                              : { def: v.def, min: v.min, max: v.max };
            var o = {
                def: patch.def !== undefined ? patch.def : base.def,
                min: patch.min !== undefined ? patch.min : base.min,
                max: patch.max !== undefined ? patch.max : base.max,
                f:   patch.f   !== undefined ? patch.f   : c.f
            };
            if (o.min && o.max && o.min > o.max) { var x = o.min; o.min = o.max; o.max = x; }
            if (o.def && o.min && o.def < o.min) o.def = o.min;
            if (o.def && o.max && o.def > o.max) o.def = o.max;

            var k = o.def + '|' + o.min + '|' + o.max + '|' + o.f.slice().sort().join(',');
            (groupes[k] = groupes[k] || { v: o, t: [] }).t.push(t);
        });
        var reste = Object.keys(groupes).length, n = 0;
        Object.keys(groupes).forEach(function (k) {
            var g = groupes[k];
            n += g.t.length;
            post({ action: 'set', targets: g.t, def: g.v.def, min: g.v.min, max: g.v.max, f: g.v.f },
                 function () {
                     if (--reste === 0) {
                         flash(n + ' cible' + (n > 1 ? 's' : '') + ' enregistrée' + (n > 1 ? 's' : '') + '.');
                     }
                 });
        });
    }

    function cibles(t) {
        var s = Object.keys(sel).map(Number);
        return (s.length && s.indexOf(t) >= 0) ? s : [t];
    }

    /* ---------- glisser une poignée ---------- */

    var dragH = null, selDrag = false, selAdd = true;

    grid.addEventListener('mousedown', function (e) {
        var h = e.target.closest('.bkf-h');
        if (h) {
            e.preventDefault(); e.stopPropagation();
            var card = h.closest('.bkf-target');
            dragH = { kind: h.getAttribute('data-h'), t: +card.getAttribute('data-t'),
                      box: h.parentNode, el: h, val: undefined };
            h.classList.add('bkf-h-drag');
            return;
        }
        if (e.target.closest('.bkf-tag')) return;
        var c2 = e.target.closest('.bkf-target');
        if (!c2) return;
        e.preventDefault();
        var t = +c2.getAttribute('data-t');
        selDrag = true; selAdd = !sel[t];
        if (!e.shiftKey && !e.ctrlKey && selAdd) sel = {};
        if (selAdd) sel[t] = true; else delete sel[t];
        render();
    });

    document.addEventListener('mousemove', function (e) {
        if (!dragH) return;
        var r = dragH.box.getBoundingClientRect();
        var m = mOfY(e.clientY - r.top);
        if (m === dragH.val) return;
        dragH.val = m;
        dragH.el.style.top = yOf(m) + 'px';
        var b = dragH.el.querySelector('b');
        if (b) b.textContent = m;
    });

    document.addEventListener('mouseup', function () {
        selDrag = false;
        if (!dragH) return;
        var d = dragH; dragH = null;
        d.el.classList.remove('bkf-h-drag');
        if (d.val === undefined) { render(); return; }   // simple clic, rien à écrire
        var patch = {};
        patch[d.kind] = d.val;
        ecrire(cibles(d.t), patch);
    });

    grid.addEventListener('mouseover', function (e) {
        if (!selDrag || dragH) return;
        var card = e.target.closest('.bkf-target');
        if (!card) return;
        var t = +card.getAttribute('data-t');
        if (selAdd) sel[t] = true; else delete sel[t];
        render();
    });

    /* ---------- blasons ---------- */

    var payload = null;

    document.querySelectorAll('#bkfield .bkf-chip-f').forEach(function (chip) {
        chip.addEventListener('dragstart', function (e) {
            payload = +chip.getAttribute('data-val');
            e.dataTransfer.effectAllowed = 'copy';
            e.dataTransfer.setData('text/plain', String(payload));
            chip.classList.add('bkf-chip-drag');
        });
        chip.addEventListener('dragend', function () { chip.classList.remove('bkf-chip-drag'); payload = null; });
        chip.addEventListener('click', function () {
            var s = Object.keys(sel).map(Number);
            if (!s.length) { flash('Sélectionnez d\'abord une ou plusieurs cibles.', true); return; }
            ajoutFace(s, +chip.getAttribute('data-val'));
        });
    });

    function ajoutFace(targets, id) {
        var groupes = {};
        targets.forEach(function (t) {
            var f = capOf(t).f.slice();
            if (f.indexOf(id) < 0) f.push(id);
            (groupes[f.slice().sort().join(',')] = groupes[f.slice().sort().join(',')] || { f: f, t: [] }).t.push(t);
        });
        Object.keys(groupes).forEach(function (k) { ecrire(groupes[k].t, { f: groupes[k].f }); });
    }

    grid.addEventListener('click', function (e) {
        var tag = e.target.closest('.bkf-tag');
        if (!tag) return;
        e.stopPropagation();
        var id = +tag.getAttribute('data-face');
        cibles(+tag.getAttribute('data-target')).forEach(function (t) {
            ecrire([t], { f: capOf(t).f.filter(function (x) { return x !== id; }) });
        });
    });

    grid.addEventListener('dragover', function (e) {
        var c = e.target.closest('.bkf-target');
        if (!c) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
        c.classList.add('bkf-over');
    });
    grid.addEventListener('dragleave', function (e) {
        var c = e.target.closest('.bkf-target');
        if (c) c.classList.remove('bkf-over');
    });
    grid.addEventListener('drop', function (e) {
        var card = e.target.closest('.bkf-target');
        if (!card) return;
        e.preventDefault();
        card.classList.remove('bkf-over');
        var id = payload || +(e.dataTransfer.getData('text/plain') || 0);
        if (id) ajoutFace(cibles(+card.getAttribute('data-t')), id);
    });

    /* ---------- barre d'outils ---------- */

    document.querySelectorAll('#bkfield [data-quick]').forEach(function (b) {
        b.addEventListener('click', function () {
            var s = Object.keys(sel).map(Number);
            if (!s.length) { flash('Sélectionnez d\'abord une ou plusieurs cibles.', true); return; }
            var m = +b.getAttribute('data-quick');
            ecrire(s, { def: m, min: m, max: m });
        });
    });

    document.querySelectorAll('#bkfield [data-act]').forEach(function (b) {
        b.addEventListener('click', function () {
            var a = b.getAttribute('data-act');
            var s = Object.keys(sel).map(Number);

            if (a === 'all')  { B.targets.forEach(function (t) { sel[t] = true; }); render(); }
            if (a === 'none') { sel = {}; render(); }

            if (a === 'applyd') {
                if (!s.length) { flash('Sélectionnez d\'abord une ou plusieurs cibles.', true); return; }
                var p = {};
                ['def', 'min', 'max'].forEach(function (k) {
                    var v = document.getElementById('bkf-' + k).value;
                    if (v !== '') p[k] = parseInt(v, 10);
                });
                if (!Object.keys(p).length) { flash('Choisissez au moins une valeur.', true); return; }
                ecrire(s, p);
            }
            if (a === 'clearsel') {
                if (!s.length) { flash('Sélectionnez d\'abord une ou plusieurs cibles.', true); return; }
                var reste = s.length;
                s.forEach(function (t) {
                    post({ action: 'set', targets: [t], def: 0, min: 0, max: 0, f: [] }, function () {
                        if (--reste === 0) flash(s.length + ' cible(s) remise(s) sans contrainte.');
                    });
                });
            }
            if (a === 'clearall') {
                if (!confirm('Effacer toutes les capacités de ce départ ?')) return;
                post({ action: 'clear' }, function () { sel = {}; flash('Départ remis sans contrainte.'); });
            }
            if (a === 'copy') {
                var to = document.getElementById('bkf-copyto').value;
                if (!to) { flash('Choisissez un départ de destination.', true); return; }
                if (!confirm('Copier vers le départ ' + to + ' ? Les réglages y seront remplacés.')) return;
                post({ action: 'copy', to: to }, function (j) { flash(j.msg || 'Copié.'); });
            }
            if (a === 'copyfrom') {
                var from = document.getElementById('bkf-copyfrom').value;
                if (!from) { flash('Choisissez un départ source.', true); return; }
                if (!confirm('Reprendre la configuration du départ ' + from + ' sur ce départ ? Les réglages actuels seront remplacés.')) return;
                // le serveur renvoie les caps de CE départ → la grille se rafraîchit
                post({ action: 'copyfrom', from: from }, function (j) { sel = {}; flash(j.msg || 'Configuration reprise.'); });
            }
        });
    });

    if (size) {
        // La taille d'affichage est une préférence : les onglets de départ
        // rechargent la page, on la mémorise donc pour ne pas repartir du défaut.
        var KEY = 'bkf_size';
        try {
            var saved = parseInt(localStorage.getItem(KEY), 10);
            if (saved >= +size.min && saved <= +size.max) size.value = saved;
        } catch (e) {}
        var maj = function () { grid.style.setProperty('--bkf-w', size.value + 'px'); };
        size.addEventListener('input', function () {
            maj();
            try { localStorage.setItem(KEY, size.value); } catch (e) {}
        });
        maj();
    }

    renderAxis();
    render();
})();
