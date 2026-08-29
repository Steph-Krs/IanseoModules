<?php
/**
 * SYNCHRO_FFTA — dépôt du fichier résultats sur l'extranet FFTA (page « dépôt »).
 *
 * Phase 1 : navigation seule. L'épreuve correspondante est trouvée automatiquement ;
 * le bouton de dépôt est présent mais inerte, aucun fichier n'est envoyé.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/ExtranetClient.php');

CheckTourSession(true);
checkFullACL(AclCompetition, 'cExport', AclReadOnly);

$q    = safe_r_sql('SELECT ToName, ToCommitee, ToComDescr, ToWhere, ToVenue, ToWhenFrom, ToWhenTo
    FROM Tournament WHERE ToId=' . intval($_SESSION['TourId']));
$TOUR = safe_fetch($q);
// Ville de la compétition : « Ville » (ToVenue, utilisée par l'export FFTA) ou, à défaut,
// « Lieu » (ToWhere) pour une compétition saisie à la main.
$TOUR->City = ($TOUR->ToVenue !== '') ? $TOUR->ToVenue : $TOUR->ToWhere;

$AJAX = $CFG->ROOT_DIR . 'Modules/Custom/SYNCHRO_FFTA/ajax.php';
$BASE = ExtranetClient::BASE_PROD;   // dépôt validé : extranet de production

// AUTH (module de comptes) actif : sa barre (#aut-bar) prend la main sur les changements
// de rôle — la nôtre (#itxt-bar) ne doit ni apparaître ni proposer de sélecteur de rôle.
// Le rôle extranet est alors aligné automatiquement sur la vue AUTH (voir ajax.php).
$AUTH_ON = !empty($CFG->USERAUTH) && !empty($_SESSION['AUTH_ENABLE']);

$PAGE_TITLE = 'Intégration TXT — Extranet FFTA';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
    /* Charte FFTA (ffta.fr) — voir CHARTE_GRAPHIQUE.md à la racine du projet */
    #itxt { --bleu:#0254a8; --bleu-fonce:#01367c; --bleu-nuit:#082c7c; --bleu-clair:#f0f4ff;
            --corail:#ff5043; --vert:#2ad56e; --gris:#4c4e50; --gris-clair:#7d8183;
            --bord:#d2d4d6; --fond:#f7f7f7; width:100%; }

    #itxt .card { border:1px solid var(--bord); border-radius:6px; background:#fff;
                  box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:16px; }
    #itxt .card > h3 { margin:0; padding:10px 14px; font-size:14px; color:#fff;
                       background:var(--bleu); border-radius:5px 5px 0 0; }
    #itxt .card > div { padding:14px; }

    #itxt .banner { background:var(--bleu-clair); border-left:4px solid var(--bleu); color:var(--gris);
                    border-radius:0 6px 6px 0; padding:10px 14px; margin-bottom:16px; font-size:13px; }
    #itxt .banner b { color:var(--bleu-fonce); }

    #itxt .cols { display:flex; gap:16px; flex-wrap:wrap; }
    #itxt .cols > * { flex:1 1 380px; min-width:0; }

    #itxt label { font-weight:600; color:var(--gris); }
    #itxt input[type=text], #itxt input[type=password], #itxt input[type=email], #itxt input[type=date] {
        padding:7px 9px; border:1px solid var(--bord); border-radius:6px; font-size:13px; }
    #itxt input:focus { outline:none; border-color:var(--bleu); box-shadow:0 0 0 2px rgba(2,84,168,.15); }
    #itxt .full { width:100%; box-sizing:border-box; }

    #itxt button { border-radius:6px; border:1px solid var(--bord); background:var(--fond);
                   padding:8px 16px; font-size:13px; cursor:pointer; }
    #itxt button.primary { background:var(--bleu); border-color:var(--bleu); color:#fff; font-weight:600; }
    #itxt button.primary:hover { background:var(--bleu-fonce); }
    #itxt button:disabled { opacity:.5; cursor:not-allowed; }

    #itxt table.list { border-collapse:collapse; width:100%; font-size:12px; }
    #itxt table.list th { background:var(--bleu); color:#fff; padding:6px 8px; text-align:left; font-weight:600; }
    #itxt table.list td { border-bottom:1px solid #e9e9e9; padding:6px 8px; vertical-align:top; }
    #itxt table.list tbody tr { cursor:pointer; }
    #itxt table.list tbody tr:hover td { background:var(--bleu-clair); }
    #itxt table.list tr.best td { background:#e8f7ee; box-shadow:inset 3px 0 0 var(--vert); }
    #itxt table.list tr.sel  td { background:var(--bleu-clair); box-shadow:inset 3px 0 0 var(--bleu); }

    /* Pastilles : couleurs de l'extranet, volontairement inchangées */
    #itxt .pill { border:2px solid #aaa; background:#ddd; color:#333; border-radius:5px;
                  padding:1px 6px; font-size:11px; font-weight:bold; }
    #itxt .pill.ok { background:#d2f4cd; border-color:#75ae77; color:#04ac0b; }
    #itxt .pill.ko { background:#ffd6db; border-color:#bb7575; color:#a80000; }

    #itxt dl.kv { margin:0; font-size:13px; }
    #itxt dl.kv dt { float:left; clear:left; width:180px; text-align:right; margin-right:10px;
                     font-weight:600; color:var(--gris); }
    #itxt dl.kv dd { margin:0 0 5px 190px; }

    #itxt .chk { font-size:13px; margin:2px 0; }
    #itxt .chk .ok  { color:#1a9e52; font-weight:bold; }
    #itxt .chk .bad { color:var(--corail); font-weight:bold; }

    #itxt .err   { color:var(--corail); font-weight:600; font-size:13px; }
    #itxt .muted { color:var(--gris-clair); font-size:12px; }
    #itxt details > summary { cursor:pointer; color:var(--bleu); font-size:13px; font-weight:600; }
    #itxt details[open] { margin-top:10px; }
    #itxt .login { max-width:420px; }

    /* Barre de session — convention partagée avec AUTH (#aut-bar), id propre */
    #itxt-bar { position:fixed; top:4px; right:8px; z-index:99989; background:var(--bleu-fonce, #01367c);
                color:#fff; font:11px Verdana,Arial,sans-serif; border-radius:14px; padding:4px 12px;
                opacity:.94; box-shadow:0 1px 4px rgba(0,0,0,.3); display:none; }
    #itxt-bar select { font:11px Verdana,Arial,sans-serif; margin-left:6px; max-width:260px;
                       border-radius:8px; border:0; padding:1px 4px; background:#eef4fb; color:#01367c; }
    #itxt-bar a { color:#a7d6ff; text-decoration:none; margin-left:10px; cursor:pointer; }
    #itxt-bar a:hover { color:#fff; text-decoration:underline; }

    /* Indicateur de chargement — 3 points qui rebondissent (repli en fondu si animation réduite) */
    #itxt .sfa-dots { display:inline-flex; gap:6px; vertical-align:middle; margin-left:4px; }
    #itxt .sfa-dots i { width:8px; height:8px; border-radius:50%; background:var(--bleu); opacity:.4;
        animation:sfaBounce 1s ease-in-out infinite; }
    #itxt .sfa-dots i:nth-child(2){ animation-delay:.16s; }
    #itxt .sfa-dots i:nth-child(3){ animation-delay:.32s; }
    @keyframes sfaBounce { 0%,80%,100%{ transform:translateY(0); opacity:.4; } 40%{ transform:translateY(-7px); opacity:1; } }
    @keyframes sfaFade   { 0%,100%{ opacity:.3; } 50%{ opacity:1; } }
    @media (prefers-reduced-motion: reduce) {
        #itxt .sfa-dots i { animation-name:sfaFade; }
    }
</style>

<div id="itxt-bar">🔗 Extranet FFTA
    <select id="bar-role" style="display:none"></select>
    <a id="bar-logout">Déconnexion</a>
</div>

<div id="itxt">

  <div class="banner">
    <b>Extranet FFTA</b> (<?= htmlspecialchars($BASE) ?>).
    Le fichier TXT (produit par l'export FFTA de ianseo) est <b>réellement déposé</b> sur l'extranet
    après confirmation.
  </div>

  <!-- Affiché tant que la session extranet n'est pas vérifiée : évite de faire croire
       à l'utilisateur qu'il doit se reconnecter alors qu'un cookie valide existe. -->
  <div class="card login" id="checking">
    <h3>Extranet FFTA</h3>
    <div><p class="muted" style="margin:0">Vérification de la session en cours
      <span class="sfa-dots"><i></i><i></i><i></i></span></p></div>
  </div>

  <div class="card login" id="auth" style="display:none">
    <h3>Connexion à l'extranet FFTA</h3>
    <div>
      <p class="muted" style="margin-top:0">Identifiants de l'Espace Dirigeant, sans MFA.
        Ni stockés, ni journalisés.</p>
      <p><label for="u">Identifiant</label><br><input type="text" id="u" class="full" autocomplete="off"></p>
      <p><label for="p">Mot de passe</label><br><input type="password" id="p" class="full" autocomplete="new-password"></p>
      <p><button type="button" class="primary" id="btn-login">Se connecter</button>
         <span id="m1" class="muted"></span></p>
    </div>
  </div>

  <div class="cols">
    <div class="card">
      <h3>Compétition ianseo</h3>
      <div>
        <dl class="kv">
          <dt>Nom</dt><dd><?= htmlspecialchars($TOUR->ToName) ?></dd>
          <dt>Ville</dt><dd><?= htmlspecialchars($TOUR->City) ?></dd>
          <dt>Organisateur</dt><dd><?= htmlspecialchars($TOUR->ToCommitee . ' — ' . $TOUR->ToComDescr) ?></dd>
          <dt>Dates</dt><dd><?= date('d/m/Y', strtotime($TOUR->ToWhenFrom)) ?>
            au <?= date('d/m/Y', strtotime($TOUR->ToWhenTo)) ?></dd>
        </dl>
        <div style="clear:both"></div>
      </div>
    </div>

    <div id="event-box">
      <div class="card"><h3>Épreuve sur l'extranet</h3>
        <div><p class="muted">Connecte-toi pour retrouver l'épreuve correspondante.</p></div>
      </div>
    </div>
  </div>

  <div id="deposit"></div>

  <details id="manual" style="display:none">
    <summary>Ce n'est pas la bonne épreuve ? Choisir manuellement</summary>
    <div class="card" style="margin-top:10px">
      <h3>Épreuves de l'extranet</h3>
      <div>
        <p>
          <label for="from">Du</label>
          <input type="date" id="from" value="<?= date('Y-m-d', strtotime($TOUR->ToWhenFrom . ' -1 day')) ?>">
          <label for="to">au</label>
          <input type="date" id="to" value="<?= date('Y-m-d', strtotime($TOUR->ToWhenTo . ' +1 day')) ?>">
          <button type="button" id="btn-list">Rechercher</button>
          <span id="m3" class="muted"></span>
        </p>
        <p class="muted">
          <label style="font-weight:400"><input type="checkbox" id="f-disc" checked> Discipline de la compétition</label>
          &nbsp;
          <label style="font-weight:400"><input type="checkbox" id="f-org" checked> Organisateur <?= htmlspecialchars($TOUR->ToCommitee) ?></label>
        </p>
        <div id="list"></div>
      </div>
    </div>
  </details>

</div>

<script>
(function () {
    'use strict';
    var AJAX = '<?= addslashes($AJAX) ?>';
    var AUTH_ON = <?= $AUTH_ON ? 'true' : 'false' ?>;   // AUTH présent : #itxt-bar masquée, rôle auto
    var $ = function (id) { return document.getElementById(id); };

    function post(action, data) {
        var body = new URLSearchParams(Object.assign({itxt_action: action}, data || {}));
        return fetch(AJAX, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function msg(id, text, isErr) {
        var e = $(id);
        e.className = isErr ? 'err' : 'muted';
        e.textContent = text || '';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    // Chargement animé (3 points) : texte + points qui rebondissent.
    function dots(t) { return (t ? esc(t) + ' ' : '') + '<span class="sfa-dots"><i></i><i></i><i></i></span>'; }
    function loadCard(t) { return '<p class="muted">' + dots(t) + '</p>'; }
    function msgLoad(id, t) { var x = $(id); x.className = 'muted'; x.innerHTML = dots(t); }

    /** Empilement des barres flottantes : on se place sous celles déjà présentes. */
    function placeBar() {
        var bar = $('itxt-bar'), top = 4;
        document.querySelectorAll('[id$="-bar"]').forEach(function (o) {
            if (o !== bar && o.offsetParent !== null &&
                getComputedStyle(o).position === 'fixed') {
                top = Math.max(top, o.getBoundingClientRect().bottom + 4);
            }
        });
        bar.style.top = top + 'px';
    }

    // ── Connexion ───────────────────────────────────────────────────────────

    /**
     * Passe la page en état « connecté » : bloc de connexion masqué. Si AUTH est présent,
     * SA barre (#aut-bar) porte déjà le changement de rôle — la nôtre reste masquée et le
     * rôle extranet a été aligné automatiquement côté serveur (voir ajax.php), sans UI ici.
     */
    function connected(roles, shared) {
        $('auth').style.display = 'none';

        if (!AUTH_ON) {
            $('itxt-bar').style.display = 'block';  // 'block' explicite : la règle CSS porte display:none
            placeBar();

            // Session ouverte par la connexion ianseo (module AUTH) : elle ne nous
            // appartient pas, la fermer ici n'aurait aucun sens.
            $('bar-logout').style.display = shared ? 'none' : '';

            roles = roles || [];
            if (roles.length > 1) {
                var sel = $('bar-role');
                sel.innerHTML = '';
                roles.forEach(function (role) {
                    var o = document.createElement('option');
                    o.value = role.value;
                    o.textContent = role.label;
                    o.selected = role.selected;
                    sel.appendChild(o);
                });
                sel.style.display = 'inline-block';
            }
        }
        search();
    }

    // Session extranet déjà ouverte (mot de passe saisi plus tôt dans cette session ianseo) ?
    /** Fin de vérification : on masque le « Vérification… » et on montre la bonne chose. */
    function doneChecking(showLogin) {
        $('checking').style.display = 'none';
        $('auth').style.display = showLogin ? '' : 'none';
    }

    post('status').then(function (r) {
        if (r.ok && r.logged) { doneChecking(false); connected(r.roles, r.shared); return; }
        // Hors ligne : le dire clairement plutôt que d'afficher un formulaire de connexion
        // qui échouera et ferait croire à un problème d'identifiants.
        if (r.ok && r.offline) { doneChecking(false); offlineNotice(r.msg); return; }
        doneChecking(true);   // pas de session valide → formulaire
    }).catch(function () { doneChecking(true); });

    /** Bandeau « pas de connexion » en tête de page, formulaire masqué. */
    function offlineNotice(msg) {
        $('checking').style.display = 'none';
        $('auth').style.display = 'none';
        var box = document.createElement('div');
        box.className = 'warn';
        box.style.cssText = 'border-left-color:#cc3333;background:#fdecea;margin-bottom:16px';
        box.innerHTML = '<b>Pas de connexion à Internet.</b><br>'
            + esc(msg || 'Le dépôt des résultats nécessite une connexion à l\'extranet FFTA.')
            + '<br><button type="button" class="primary" style="margin-top:8px" '
            + 'onclick="location.reload()">Réessayer</button>';
        $('itxt').insertBefore(box, $('itxt').firstChild);
    }

    $('btn-login').addEventListener('click', function () {
        var u = $('u').value.trim(), p = $('p').value;
        if (!u || !p) { msg('m1', 'Identifiant et mot de passe requis.', true); return; }

        msgLoad('m1', 'Connexion');
        post('login', {itxt_user: u, itxt_pass: p}).then(function (r) {
            $('p').value = '';
            if (!r.ok) { msg('m1', r.msg, true); return; }
            connected(r.roles, false);
        }).catch(function (e) { msg('m1', 'Erreur : ' + e.message, true); });
    });

    $('bar-logout').addEventListener('click', function () {
        post('logout').then(function () {
            $('itxt-bar').style.display = 'none';
            $('auth').style.display = '';
            $('manual').style.display = 'none';
            $('deposit').innerHTML = '';
            $('event-box').innerHTML = '<div class="card"><h3>Épreuve sur l\'extranet</h3>'
                + '<div><p class="muted">Connecte-toi pour retrouver l\'épreuve correspondante.</p></div></div>';
            msg('m1', 'Session fermée.');
        });
    });

    // Bascule de rôle directement au changement, sans bouton de validation
    $('bar-role').addEventListener('change', function () {
        post('role', {itxt_role: this.value}).then(function (r) {
            if (r.ok) { search(); }
            else { alert(r.msg); }
        });
    });

    // ── Recherche de l'épreuve ──────────────────────────────────────────────
    function search() {
        $('event-box').innerHTML = '<div class="card"><h3>Épreuve sur l\'extranet</h3>'
            + '<div>' + loadCard('Recherche en cours') + '</div></div>';
        $('deposit').innerHTML = '';
        msgLoad('m3', 'Recherche');

        post('list', {
            itxt_from:   $('from').value.trim(),
            itxt_to:     $('to').value.trim(),
            itxt_f_disc: $('f-disc').checked ? 1 : 0,
            itxt_f_org:  $('f-org').checked  ? 1 : 0
        }).then(function (r) {
            $('manual').style.display = '';
            if (!r.ok) { fail(r.msg); msg('m3', '', false); return; }

            lastEvents = r.events || [];
            msg('m3', r.events.length + ' épreuve(s).');
            renderList(r.events, r.suggested, r.filters, r.total);

            if (r.suggested)               { loadEvent(r.suggested); }
            else if (r.events.length === 1) { loadEvent(r.events[0].id); }
            else {
                $('manual').setAttribute('open', 'open');
                fail('Aucune épreuve ne ressemble à cette compétition — choisis-la dans la liste ci-dessous.');
            }
        }).catch(function (e) { fail('Erreur : ' + e.message); });
    }

    function fail(text) {
        $('event-box').innerHTML = '<div class="card"><h3>Épreuve sur l\'extranet</h3>'
            + '<div><p class="err">' + esc(text) + '</p></div></div>';
    }

    $('btn-list').addEventListener('click', search);
    $('f-disc').addEventListener('change', search);
    $('f-org').addEventListener('change', search);

    function filterNote(f, total, shown) {
        if (!f) { return ''; }
        var active = [];
        if (f.discipline.on) { active.push('discipline « ' + f.discipline.label + ' »'); }
        if (f.agrement.on)   { active.push('organisateur ' + f.agrement.code); }
        if (!active.length)  { return '<p class="muted">Aucun filtre : toutes les épreuves de la période.</p>'; }

        var n = 'Filtré sur ' + active.join(' et ') + '.';
        if (total > shown) { n += ' ' + (total - shown) + ' épreuve(s) masquée(s).'; }
        return '<p class="muted">' + n + '</p>';
    }

    var lastEvents = [];

    function renderList(events, suggested, filters, total) {
        var box = $('list');
        if (!events.length) {
            box.innerHTML = filterNote(filters, total, 0)
                + '<p class="muted">Rien sur cette période — élargis les dates ou décoche les filtres.</p>';
            return;
        }

        var h = filterNote(filters, total, events.length)
              + '<table class="list"><thead><tr><th>État</th><th>Dates</th><th>Nom</th>'
              + '<th>Lieu</th><th>Organisateur</th><th>Caractéristiques</th></tr></thead><tbody>';
        events.forEach(function (ev) {
            var pills = '';
            Object.keys(ev.pills).forEach(function (k) {
                pills += '<span class="pill ' + ev.pills[k] + '">' + esc(k) + '</span> ';
            });
            var para = ev.para ? ' <span class="pill" title="Valide + Para : para regroupé">＋ Para</span>' : '';
            h += '<tr data-id="' + esc(ev.id) + '"' + (ev.id === suggested ? ' class="best"' : '') + '>'
               + '<td>' + (pills || esc(ev.etat)) + '</td><td>' + esc(ev.dates) + '</td>'
               + '<td>' + esc(ev.nom) + para + '</td><td>' + esc(ev.lieu) + '</td>'
               + '<td>' + esc(ev.organisateur) + '</td><td>' + esc(ev.carac) + '</td></tr>';
        });
        box.innerHTML = h + '</tbody></table>';

        box.querySelectorAll('tr[data-id]').forEach(function (tr) {
            tr.addEventListener('click', function () {
                box.querySelectorAll('tr.sel').forEach(function (x) { x.classList.remove('sel'); });
                tr.classList.add('sel');
                loadEvent(tr.getAttribute('data-id'));
            });
        });
    }

    // ── Épreuve retenue + cadre de dépôt ────────────────────────────────────
    function loadEvent(id) {
        var ev = lastEvents.filter(function (e) { return String(e.id) === String(id); })[0] || {id: id, carac: ''};
        // Épreuve para si sa discipline (en tête des caractéristiques) commence par « Para ».
        // Une compétition Valide+Para est regroupée : la ligne valides porte para_id.
        var isParaRow  = /^\s*para/i.test(ev.carac || '');
        var validesVid = isParaRow ? '' : String(ev.id);
        var paraVid    = isParaRow ? String(ev.id) : (ev.para_id ? String(ev.para_id) : '');

        post('event', {itxt_id: id}).then(function (r) {
            if (!r.ok) { fail(r.msg); return; }

            var h = '<div class="card"><h3>Épreuve sur l\'extranet</h3><div><dl class="kv">';
            ['Nom de l\'épreuve', 'Discipline', 'Date', 'Lieu', 'Structure Organisatrice',
             'Type d\'épreuve', 'Caractéristiques'].forEach(function (k) {
                if (r.details[k]) { h += '<dt>' + esc(k) + '</dt><dd>' + esc(r.details[k]) + '</dd>'; }
            });
            h += '</dl><div style="clear:both"></div>' + concordance(r.compare)
               + block('Données actuelles', r.donnees, r.donnees_text);
            if (r.links.length) {
                h += '<p>';
                r.links.forEach(function (l) {
                    h += '<a href="' + esc(l.href) + '" target="_blank">' + esc(l.label || l.href) + '</a> ';
                });
                h += '</p>';
            }
            $('event-box').innerHTML = h + '</div></div>';

            renderDeposit(r, validesVid, paraVid);
        });
    }

    // Cadre de dépôt : dépose côté valides et/ou para selon où sont les archers dans ianseo.
    function renderDeposit(r, validesVid, paraVid) {
        var c = r.counts || {valides: 0, para: 0};
        var email = (r.insert && r.insert.email) ? r.insert.email : '';

        if (c.valides === 0 && c.para === 0) {
            $('deposit').innerHTML = '<div class="card"><h3>Dépôt du fichier TXT</h3><div>'
                + '<p class="err">Aucun archer inscrit dans cette compétition ianseo — rien à déposer.</p>'
                + '</div></div>';
            return;
        }

        var warn = '', h = '<div class="card"><h3>Dépôt du fichier TXT</h3><div>';
        h += '<p><b>À déposer :</b></p><ul style="margin:4px 0 10px">';
        if (c.valides > 0) {
            h += '<li>' + c.valides + ' archer(s) <b>valides</b> → '
               + (validesVid ? 'épreuve valides' : '<span class="err">épreuve valides absente</span>') + '</li>';
            if (!validesVid) {
                warn += '<p class="warn">Des archers valides sont présents mais l\'épreuve valides n\'apparaît pas '
                     + 'sur l\'extranet.</p>';
            }
        }
        if (c.para > 0) {
            h += '<li>' + c.para + ' archer(s) <b>para</b> → '
               + (paraVid ? 'épreuve para' : '<span class="err">épreuve para absente</span>') + '</li>';
            if (!paraVid) {
                warn += '<p class="warn"><b>L\'épreuve para n\'apparaît pas sur l\'extranet.</b> '
                     + 'La compétition n\'a probablement pas été déclarée « Valide + Para » au calendrier fédéral. '
                     + 'Corrigez la déclaration sur l\'extranet pour pouvoir y déposer les résultats para.</p>';
            }
        }
        h += '</ul>' + warn;

        h += '<p><label for="mail">Adresse e-mail du déposant</label><br>'
           + '<input type="email" id="mail" style="width:340px" value="' + esc(email) + '"></p>';

        var canDeposit = (c.valides > 0 && validesVid) || (c.para > 0 && paraVid);
        h += '<p><button type="button" class="primary" id="btn-deposit"' + (canDeposit ? '' : ' disabled') + '>'
           + 'Déposer sur l\'extranet</button> '
           + '<span class="muted">TXT produit par l\'export FFTA de ianseo.</span></p>'
           + '<div id="dep-report"></div></div></div>';

        $('deposit').innerHTML = h;

        var btn = $('btn-deposit');
        if (btn) btn.addEventListener('click', function () {
            var mail = ($('mail').value || '').trim();
            if (!mail) { $('dep-report').innerHTML = '<p class="err">Adresse e-mail requise.</p>'; return; }
            var sides = [];
            if (c.valides > 0 && validesVid) sides.push('valides');
            if (c.para > 0 && paraVid) sides.push('para');
            if (!confirm('Déposer les résultats (' + sides.join(' + ') + ') sur l\'extranet FFTA ?')) return;

            btn.disabled = true;
            $('dep-report').innerHTML = loadCard('Génération du TXT et dépôt en cours');
            post('deposit', {
                itxt_email: mail,
                itxt_valides_vid: validesVid,
                itxt_para_vid: paraVid
            }).then(function (d) {
                btn.disabled = false;
                if (!d.ok) { $('dep-report').innerHTML = '<p class="err">' + esc(d.msg) + '</p>'; return; }
                showReports(d.reports || {});
            }).catch(function (e) {
                btn.disabled = false;
                $('dep-report').innerHTML = '<p class="err">Erreur : ' + esc(e.message) + '</p>';
            });
        });
    }

    function showReports(reports) {
        var h = '', anyKo = false;
        ['valides', 'para'].forEach(function (side) {
            var rep = reports[side];
            if (!rep) return;

            var ko = rep.ok && rep.ko;
            if (ko) anyKo = true;

            var head = !rep.ok ? '<span class="pill ko">Échec</span>'
                     : ko      ? '<span class="pill ko">Lignes rejetées (KO)</span>'
                               : '<span class="pill ok">Déposé sans erreur</span>';

            h += '<div class="card" style="margin-top:10px' + (ko ? ';border:2px solid #cc3333' : '')
               + '"><h3' + (ko ? ' style="background:#cc3333"' : '') + '>Rapport — ' + side + '</h3><div>';
            h += '<p>' + head + '</p>';
            if (ko) {
                h += '<p class="warn" style="border-left-color:#cc3333;background:#fdecea">'
                   + '<b>Des lignes ont été refusées.</b> Corrigez ces erreurs dans ianseo puis redéposez : '
                   + 'tant qu\'elles ne sont pas corrigées, les archers concernés ne sont pas pris en compte '
                   + 'et risquent d\'être pénalisés.</p>';
            }
            h += rep.ok ? (rep.report || '') : ('<p class="err">' + esc(rep.msg || 'Échec du dépôt.') + '</p>');
            h += '</div></div>';
        });

        if (anyKo) {
            h = '<div class="warn" style="border-left-color:#cc3333;background:#fdecea;font-weight:bold">'
              + '⚠ Le dépôt contient des lignes en erreur (KO) — voir le détail ci-dessous.</div>' + h;
        }
        $('dep-report').innerHTML = h || '<p class="muted">Aucun rapport.</p>';
    }

    /**
     * Bloc de l'extranet : liste libellé/valeur quand il y en a une (dépôt déjà fait),
     * sinon la phrase brute (« Aucun fichier n'a encore été déposé »).
     */
    function block(title, pairs, text) {
        var keys = pairs ? Object.keys(pairs) : [];
        if (!keys.length) {
            return '<p class="muted"><b>' + esc(title) + '</b> — ' + esc(text || '—') + '</p>';
        }

        var h = '<p style="margin-bottom:4px"><b>' + esc(title) + '</b></p><dl class="kv">';
        keys.forEach(function (k) {
            var v = pairs[k], cell = esc(v);
            if (/^État/i.test(k) && /\bOK\b|\bKO\b/.test(v)) {
                cell = '<span class="pill ' + (/\bOK\b/.test(v) ? 'ok' : 'ko') + '">' + esc(v) + '</span>';
            }
            h += '<dt>' + esc(k) + '</dt><dd>' + cell + '</dd>';
        });
        return h + '</dl><div style="clear:both"></div>';
    }

    /** Concordance ianseo ↔ extranet sur les points qui invalident un TXT. */
    function concordance(c) {
        if (!c) { return ''; }
        var norm = function (s) { return (s || '').toUpperCase(); };
        var rows = [
            ['N° d\'agrément', c.agrement.ianseo, c.agrement.extranet,
             c.agrement.ianseo && norm(c.agrement.extranet).indexOf(norm(c.agrement.ianseo)) !== -1],
            ['Date', c.date.ianseo, c.date.extranet,
             norm(c.date.extranet).indexOf(norm(c.date.ianseo)) !== -1],
            ['Lieu', c.lieu.ianseo, c.lieu.extranet,
             norm(c.lieu.extranet).indexOf(norm(c.lieu.ianseo)) !== -1 ||
             norm(c.lieu.ianseo).indexOf(norm(c.lieu.extranet)) !== -1]
        ];

        var h = '<p style="margin-bottom:4px"><b>Concordance avec ianseo</b></p>';
        rows.forEach(function (r) {
            h += '<p class="chk"><span class="' + (r[3] ? 'ok' : 'bad') + '">' + (r[3] ? '✓' : '✗')
               + '</span> ' + esc(r[0]) + ' — ianseo : <b>' + esc(r[1] || '—')
               + '</b> · extranet : <b>' + esc(r[2] || '—') + '</b></p>';
        });
        return h;
    }

    window.addEventListener('resize', placeBar);
})();
</script>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
