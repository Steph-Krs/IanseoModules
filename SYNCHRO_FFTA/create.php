<?php
/**
 * SYNCHRO_FFTA — création d'une compétition depuis une épreuve de l'extranet.
 *
 * Sans compétition ouverte. La création réelle est déléguée au formulaire natif
 * de ianseo (Tournament/index.php, Command=SAVE&New=1) : ce module ne fait que
 * lister les épreuves, vérifier les informations et pré-remplir les champs.
 *
 * Phase actuelle : infos de base uniquement. La configuration assistée (départs,
 * cibles, archers par cible, saisie par téléphone) viendra ensuite.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/ExtranetClient.php');
require_once(__DIR__ . '/mapping.php');

CheckTourSession(false);

// Droit de créer, calqué sur la page native (Tournament/index.php) : on ne bloque que si
// AUTH est actif ET l'utilisateur n'a pas le droit. Sur localhost (AUTH court-circuité,
// AUTH_ENABLE vide), la création reste permise, comme l'entrée de menu « Nouveau ».
$sfaAuthOn = !empty($CFG->USERAUTH) && !empty($_SESSION['AUTH_ENABLE']);
if ($sfaAuthOn && empty($_SESSION['AUTH_ROOT']) && !possibleFeature(AclRoot, AclReadWrite)) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    exit;
}

$AJAX = $CFG->ROOT_DIR . 'Modules/Custom/SYNCHRO_FFTA/ajax-create.php';
$RUN  = $CFG->ROOT_DIR . 'Modules/Custom/SYNCHRO_FFTA/create-run.php';
$BASE = ExtranetClient::BASE_PPROD;

// ── Types et sous-règles français réels (pour les menus déroulants) ──────────
$fr = sfa_fr_sets();
$typeLabels = [];
if (!empty($fr['types'])) {
    $in = implode(',', array_map('intval', $fr['types']));
    $rs = safe_r_sql("SELECT TtId, TtType, TtDistance FROM TourTypes WHERE TtId IN ($in)");
    while ($r = safe_fetch($rs)) {
        $typeLabels[(int) $r->TtId] = get_text($r->TtType, 'Tournament');
    }
}
// [ToType => [ ['idx'=>d_SubRule, 'label'=>...], ... ]]
$subMap = [];
foreach ($fr['rules'] as $toType => $keyed) {
    foreach ($keyed as $key => $code) {
        $subMap[$toType][] = ['idx' => $key + 1, 'label' => get_text($code, 'Install')];
    }
}

// Défauts techniques : repris de la dernière compétition, sinon valeurs FR sûres.
$def = ['cur' => 'EUR', 'lang' => '', 'chars' => 0, 'paper' => 0];
$rs = safe_r_sql("SELECT ToCurrency, ToPrintLang, ToPrintChars, ToPrintPaper
    FROM Tournament ORDER BY ToId DESC LIMIT 1");
if ($r = safe_fetch($rs)) {
    $def = ['cur' => $r->ToCurrency ?: 'EUR', 'lang' => $r->ToPrintLang,
            'chars' => (int) $r->ToPrintChars, 'paper' => (int) $r->ToPrintPaper];
}

// Modes ISK disponibles : liste construite par ianseo (Api/*/ConfigOptions.php),
// qui filtre le mode Live (module_exists 'ISK-NG_Live'). On réutilise ce mécanisme
// pour suivre les mises à jour ianseo — on ne réimplémente pas la liste.
require_once($CFG->DOCUMENT_PATH . 'Common/Lib/Fun_Modules.php');
$IskType = [];
if (file_exists($CFG->DOCUMENT_PATH . 'Api/index.php') && function_exists('AvailableApis')) {
    foreach (AvailableApis() as $Api) {
        @include($CFG->DOCUMENT_PATH . 'Api/' . $Api . '/ConfigOptions.php');
    }
}
$ISK_CONFIG_URL = $CFG->ROOT_DIR . 'Tournament/index-getIskConfig.php';

$PAGE_TITLE = 'Créer une compétition depuis l\'extranet FFTA';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
    #sfa { --bleu:#0254a8; --bleu-fonce:#01367c; --bleu-clair:#f0f4ff; --corail:#ff5043;
           --vert:#2ad56e; --gris:#4c4e50; --gris-clair:#7d8183; --bord:#d2d4d6; --fond:#f7f7f7; width:100%; }
    #sfa .card { border:1px solid var(--bord); border-radius:6px; background:#fff;
                 box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:16px; }
    #sfa .card > h3 { margin:0; padding:10px 14px; font-size:14px; color:#fff;
                      background:var(--bleu); border-radius:5px 5px 0 0; }
    #sfa .card > div { padding:14px; }
    #sfa .banner { background:var(--bleu-clair); border-left:4px solid var(--bleu); color:var(--gris);
                   border-radius:0 6px 6px 0; padding:10px 14px; margin-bottom:16px; font-size:13px; }
    #sfa .banner b { color:var(--bleu-fonce); }
    #sfa label { font-weight:600; color:var(--gris); }
    #sfa input[type=text], #sfa input[type=password], #sfa input[type=date], #sfa select, #sfa textarea {
        padding:7px 9px; border:1px solid var(--bord); border-radius:6px; font-size:13px; box-sizing:border-box; }
    #sfa input:focus, #sfa select:focus, #sfa textarea:focus {
        outline:none; border-color:var(--bleu); box-shadow:0 0 0 2px rgba(2,84,168,.15); }
    #sfa input[readonly] { background:#f2f2f2; color:var(--gris); cursor:default; }
    #sfa input[readonly]:focus { box-shadow:none; border-color:var(--bord); }
    #sfa button { border-radius:6px; border:1px solid var(--bord); background:var(--fond);
                  padding:8px 16px; font-size:13px; cursor:pointer; }
    #sfa button.primary { background:var(--bleu); border-color:var(--bleu); color:#fff; font-weight:600; }
    #sfa button.primary:hover { background:var(--bleu-fonce); }
    #sfa table.list { border-collapse:collapse; width:100%; font-size:12px; }
    #sfa table.list th { background:var(--bleu); color:#fff; padding:6px 8px; text-align:left; }
    #sfa table.list td { border-bottom:1px solid #e9e9e9; padding:6px 8px; vertical-align:top; }
    #sfa table.list tbody tr { cursor:pointer; }
    #sfa table.list tbody tr:hover td { background:var(--bleu-clair); }
    #sfa table.list tr.sel td { background:var(--bleu-clair); box-shadow:inset 3px 0 0 var(--bleu); }
    #sfa .pill { border:2px solid #aaa; background:#ddd; color:#333; border-radius:5px; padding:1px 6px; font-size:11px; font-weight:bold; }
    #sfa .pill.ok { background:#d2f4cd; border-color:#75ae77; color:#04ac0b; }
    #sfa .pill.ko { background:#ffd6db; border-color:#bb7575; color:#a80000; }
    #sfa .grid { display:grid; grid-template-columns:170px 1fr; gap:8px 12px; align-items:center; }
    #sfa .grid > label { text-align:right; }
    #sfa .grid2 { display:grid; grid-template-columns:150px 1fr; gap:8px 10px; align-items:center; }
    #sfa .cols2 { display:flex; gap:28px; flex-wrap:wrap; }
    /* Infos compétition à gauche, paramètres à droite — répartition 50/50 (DOM inchangé). */
    #sfa .col-base { flex:1 1 360px; min-width:0; order:1; }
    #sfa .col-assist { flex:1 1 360px; min-width:0; order:2; }
    #sfa .grid .full, #sfa .grid2 .full, #sfa .grid input, #sfa .grid2 input, #sfa .grid select, #sfa .grid2 select, #sfa .grid textarea { max-width:100%; box-sizing:border-box; }
    #sfa #IskConfig table { width:100%; }
    #sfa #IskConfig input[type=text], #sfa #IskConfig select { padding:5px 7px; }
    #sfa h4 { margin:0 0 8px; font-size:13px; color:var(--bleu-fonce); border-bottom:1px solid var(--bord); padding-bottom:4px; }
    #sfa .full { width:100%; }
    #sfa .err   { color:var(--corail); font-weight:600; font-size:13px; }
    #sfa .muted { color:var(--gris-clair); font-size:12px; }
    #sfa .warn  { background:#fff6d5; border:1px solid #e0a800; border-radius:4px; padding:.6em .9em; font-size:13px; }
    #sfa .login { max-width:420px; }
    #sfa-bar { position:fixed; top:4px; right:8px; z-index:99989; background:var(--bleu-fonce,#01367c);
               color:#fff; font:11px Verdana,Arial,sans-serif; border-radius:14px; padding:4px 12px;
               opacity:.94; box-shadow:0 1px 4px rgba(0,0,0,.3); display:none; }
    #sfa-bar select { font:11px Verdana,Arial,sans-serif; margin-left:6px; max-width:260px;
                      border-radius:8px; border:0; padding:1px 4px; background:#eef4fb; color:#01367c; }
    #sfa-bar a { color:#a7d6ff; text-decoration:none; margin-left:10px; cursor:pointer; }
    #sfa-bar a:hover { color:#fff; text-decoration:underline; }
</style>

<div id="sfa-bar">🔗 Extranet FFTA
    <select id="bar-role" style="display:none"></select>
    <a id="bar-logout">Déconnexion</a>
</div>

<div id="sfa">
  <div class="banner">
    <b>Mode essai — préproduction</b> (<?= htmlspecialchars($BASE) ?>).
    La compétition est créée avec le code ianseo, puis vous arrivez directement sur la saisie des participants.
  </div>

  <?php if (!empty($_GET['err'])): ?>
    <div class="warn" style="margin-bottom:16px"><b>Création non effectuée :</b> <?= htmlspecialchars($_GET['err']) ?></div>
  <?php endif; ?>

  <div class="card login" id="auth">
    <h3>Connexion à l'extranet FFTA</h3>
    <div>
      <p class="muted" style="margin-top:0">Identifiants FFTA (Espace Dirigeant / extranet, mêmes codes).
        Ni stockés, ni journalisés. Ouvre les deux espaces en une fois.</p>
      <p><label for="u">Identifiant</label><br><input type="text" id="u" class="full" autocomplete="off"></p>
      <p><label for="p">Mot de passe</label><br><input type="password" id="p" class="full" autocomplete="new-password"></p>
      <p><label for="o">Code MFA
          <span id="mfa-i" title="Cliquer pour en savoir plus"
                style="display:inline-block;width:16px;height:16px;line-height:16px;text-align:center;
                       border-radius:50%;background:var(--bleu);color:#fff;font-size:11px;font-style:italic;
                       cursor:pointer;font-family:serif">i</span>
          <small class="muted">— laisser vide si non activée</small></label><br>
        <input type="text" id="o" class="full" autocomplete="off" maxlength="8" inputmode="numeric" placeholder="6 chiffres">
        <span id="mfa-help" class="muted" style="display:none;margin-top:6px;background:var(--bleu-clair);
              border-left:3px solid var(--bleu);border-radius:0 4px 4px 0;padding:6px 8px">
          La double authentification (MFA) sécurise votre compte. Elle s'active sur l'<b>Espace Dirigeant</b>
          (recommandé). Si activée, saisissez le <b>code à 6 chiffres</b> de votre application
          d'authentification. Sinon, laissez vide.</span></p>
      <p><button type="button" class="primary" id="btn-login">Se connecter</button> <span id="m1" class="muted"></span></p>
    </div>
  </div>

  <div class="card" id="list-card" style="display:none">
    <h3>Vos épreuves sur l'extranet</h3>
    <div>
      <p>
        <label for="from">Du</label>
        <input type="date" id="from">
        <label for="to">au</label>
        <input type="date" id="to">
        <button type="button" id="btn-list">Rechercher</button>
        <span id="m2" class="muted"></span>
      </p>
      <p class="muted">
        <label style="font-weight:400"><input type="checkbox" id="hide-past" checked> Masquer les épreuves passées</label>
      </p>
      <div id="list"></div>
    </div>
  </div>

  <form class="card" id="review" style="display:none" method="post" action="<?= htmlspecialchars($RUN) ?>">
    <h3>Vérifier et créer</h3>
    <div>
      <input type="hidden" name="d_Rule" value="FR">
      <input type="hidden" name="d_ToNameShort" id="f-short">
      <input type="hidden" name="d_ToIocCode" value="">
      <input type="hidden" name="d_ToVenue" value="">
      <input type="hidden" name="d_ToCountry" value="FRA">
      <input type="hidden" name="d_ToTimeZone" id="f-tz">
      <input type="hidden" name="xx_ToCurrency" value="<?= htmlspecialchars($def['cur']) ?>">
      <input type="hidden" name="xx_ToPrintLang" value="<?= htmlspecialchars($def['lang']) ?>">
      <input type="hidden" name="xx_ToPrintChars" value="<?= (int) $def['chars'] ?>">
      <input type="hidden" name="xx_ToPaperSize" value="<?= (int) $def['paper'] ?>">
      <input type="hidden" name="xx_ToUseHHT" value="0">
      <input type="hidden" name="xx_ToWhenFromDay" id="f-fd">
      <input type="hidden" name="xx_ToWhenFromMonth" id="f-fm">
      <input type="hidden" name="xx_ToWhenFromYear" id="f-fy">
      <input type="hidden" name="xx_ToWhenToDay" id="f-td">
      <input type="hidden" name="xx_ToWhenToMonth" id="f-tm">
      <input type="hidden" name="xx_ToWhenToYear" id="f-ty">

      <div id="prop-note"></div>

      <div class="cols2">
      <div class="col-assist">
        <h4>Paramètres du concours</h4>
        <div class="grid2">
          <label for="a-departs">Nombre de départs</label>
          <input type="number" id="a-departs" name="sfa_departs" min="1" max="20" value="1">

          <label for="a-cibles" id="a-cibles-label">Nombre de cibles</label>
          <input type="number" id="a-cibles" name="sfa_cibles" min="0" max="400" placeholder="défaut de la règle">

          <label for="a-rythme">Rythme de tir</label>
          <select id="a-rythme" name="sfa_rythme">
            <option value="2">AB — 2 archers/cible</option>
            <option value="3">ABC — 3 archers/cible</option>
            <option value="4" selected>AB-CD — 4 archers/cible</option>
          </select>
        </div>
        <p class="muted" style="margin:6px 0 0">Cibles vide = valeur par défaut de la règle choisie.</p>

        <?php if ($IskType): ?>
        <h4 style="margin-top:18px">Saisie par téléphone (ISK-NG)</h4>
        <div class="grid2">
          <label for="IskSelect">Système</label>
          <select name="Module[ISK-NG][Mode]" id="IskSelect" oldval="" onchange="ChangeIskConfig(this)">
            <option value="">— Aucune —</option>
            <?php foreach ($IskType as $val => $opt): ?>
              <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="ISK-Messages"></div>
        <!-- Champs du mode chargés depuis le endpoint natif de ianseo (suivent les MàJ) -->
        <div id="IskConfig" style="margin-top:8px"></div>
        <?php endif; ?>
      </div>

      <div class="col-base">
      <div class="grid">
        <label>Code compétition</label>
        <span><input type="text" name="d_ToCode" id="f-code" maxlength="8" size="10" readonly> <span id="code-warn" class="err"></span></span>

        <label for="f-name">Nom</label>
        <textarea name="d_ToName" id="f-name" rows="2" class="full"></textarea>

        <label>Organisateur (agrément)</label>
        <input type="text" name="d_ToCommitee" id="f-commitee" maxlength="10" readonly>

        <label for="f-comdescr">Structure</label>
        <input type="text" name="d_ToComDescr" id="f-comdescr" class="full">

        <label for="f-where">Lieu</label>
        <input type="text" name="d_ToWhere" id="f-where" class="full">

        <label>Dates</label>
        <span id="f-dates-text" class="muted"></span>

        <label for="f-type">Discipline</label>
        <select name="d_ToType" id="f-type">
          <option value="">— choisir —</option>
          <?php foreach ($typeLabels as $id => $lab): ?>
            <option value="<?= $id ?>"><?= htmlspecialchars($lab) ?></option>
          <?php endforeach; ?>
        </select>

        <label for="f-sub">Sous-règle</label>
        <select name="d_SubRule" id="f-sub"><option value="">—</option></select>
      </div>
      </div><!-- /col-base -->
      </div><!-- /cols2 -->

      <p style="margin-top:14px">
        <button type="submit" class="primary">Créer et saisir les participants</button>
        <span class="muted">La compétition est créée dans ianseo, puis vous arrivez sur la saisie des participants.</span>
      </p>
    </div>
  </form>
</div>

<script>
(function () {
    'use strict';
    var AJAX   = '<?= addslashes($AJAX) ?>';
    var SUBMAP = <?= json_encode($subMap, JSON_UNESCAPED_UNICODE) ?>;
    var $ = function (id) { return document.getElementById(id); };

    function post(action, data) {
        var body = new URLSearchParams(Object.assign({sfa_action: action}, data || {}));
        return fetch(AJAX, {method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
            .then(function (r) { return r.json(); });
    }
    function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
    function msg(id,t,e){ var x=$(id); x.className=e?'err':'muted'; x.textContent=t||''; }

    function placeBar() {
        var bar=$('sfa-bar'), top=4;
        document.querySelectorAll('[id$="-bar"]').forEach(function(o){
            if(o!==bar && o.offsetParent!==null && getComputedStyle(o).position==='fixed')
                top=Math.max(top, o.getBoundingClientRect().bottom+4);
        });
        bar.style.top=top+'px';
    }

    // Dates par défaut : mois en cours
    (function(){
        var t=new Date(), iso=function(d){return d.toISOString().slice(0,10);};
        $('from').value = iso(new Date(t.getFullYear(), t.getMonth(), 1));
        $('to').value   = iso(new Date(t.getFullYear(), t.getMonth()+1, 0));
    })();

    function connected(roles, shared) {
        $('auth').style.display='none';
        $('list-card').style.display='';
        $('sfa-bar').style.display='block';
        $('bar-logout').style.display = shared ? 'none' : '';
        placeBar();
        if ((roles||[]).length>1) {
            var sel=$('bar-role'); sel.innerHTML='';
            roles.forEach(function(r){ var o=document.createElement('option');
                o.value=r.value; o.textContent=r.label; o.selected=r.selected; sel.appendChild(o); });
            sel.style.display='inline-block';
        }
        search();
    }

    post('status').then(function(r){ if(r.ok&&r.logged) connected(r.roles, r.shared); });

    // « i » MFA : déplie/replie l'explication
    var mfaI = $('mfa-i');
    if (mfaI) mfaI.addEventListener('click', function () {
        var h = $('mfa-help');
        h.style.display = (h.style.display === 'block') ? 'none' : 'block';
    });

    $('btn-login').addEventListener('click', function(){
        var u=$('u').value.trim(), p=$('p').value, o=$('o').value.trim();
        if(!u||!p){ msg('m1','Identifiant et mot de passe requis.',true); return; }
        msg('m1','Connexion…');
        // Ouvre extranet + Espace Dirigeant (r.ok = extranet, requis pour la création).
        post('login',{sfa_user:u, sfa_pass:p, sfa_otp:o}).then(function(r){
            $('p').value=''; $('o').value='';
            if(!r.ok){ msg('m1',r.msg,true); return; }
            connected(r.roles, false);
        }).catch(function(e){ msg('m1','Erreur : '+e.message,true); });
    });

    $('bar-logout').addEventListener('click', function(){
        post('logout').then(function(){
            $('auth').style.display=''; $('list-card').style.display='none';
            $('review').style.display='none'; $('sfa-bar').style.display='none';
            msg('m1','Session fermée.');
        });
    });

    $('bar-role').addEventListener('change', function(){
        post('role',{sfa_role:this.value}).then(function(r){ if(r.ok) search(); else alert(r.msg); });
    });

    $('btn-list').addEventListener('click', search);
    $('hide-past').addEventListener('change', function(){ renderList(lastEvents); });

    var lastEvents = [];

    function search() {
        $('list').innerHTML='<p class="muted">Recherche…</p>';
        $('review').style.display='none';
        post('list',{sfa_from:$('from').value, sfa_to:$('to').value}).then(function(r){
            if(!r.ok){ $('list').innerHTML='<p class="err">'+esc(r.msg)+'</p>'; return; }
            lastEvents = r.events||[];
            renderList(lastEvents);
        }).catch(function(e){ $('list').innerHTML='<p class="err">Erreur : '+esc(e.message)+'</p>'; });
    }

    /** Date de fin (la plus tardive) d'une épreuve, ou null. */
    function lastDate(ev) {
        var m = (ev.dates||'').match(/(\d{2})\/(\d{2})\/(\d{4})/g) || [];
        var max = null;
        m.forEach(function(d){ var p=d.split('/'); var dt=new Date(+p[2],p[1]-1,+p[0]);
            if(!max || dt>max) max=dt; });
        return max;
    }

    function renderList(events) {
        var today = new Date(); today.setHours(0,0,0,0);
        var hidePast = $('hide-past').checked;
        var shown = events.filter(function(ev){
            if(!hidePast) return true;
            var d = lastDate(ev);
            return !d || d >= today;
        });

        if(!shown.length){
            $('list').innerHTML = '<p class="muted">Aucune épreuve'
                + (hidePast && events.length ? ' à venir' : '') + ' sur cette période.</p>';
            return;
        }

        var hidden = events.length - shown.length;
        var note = hidden>0 ? '<p class="muted">'+hidden+' épreuve(s) passée(s) masquée(s).</p>' : '';

        var h=note+'<table class="list"><thead><tr><th>État</th><th>Dates</th><th>Nom</th><th>Lieu</th>'
            +'<th>Organisateur</th><th>Caractéristiques</th></tr></thead><tbody>';
        shown.forEach(function(ev){
            var pills=''; Object.keys(ev.pills).forEach(function(k){ pills+='<span class="pill '+ev.pills[k]+'">'+esc(k)+'</span> '; });
            var para = ev.para ? ' <span class="pill" title="Valide + Para : para regroupé">＋ Para</span>' : '';
            h+='<tr data-id="'+esc(ev.id)+'"><td>'+(pills||esc(ev.etat))+'</td><td>'+esc(ev.dates)+'</td>'
             +'<td>'+esc(ev.nom)+para+'</td><td>'+esc(ev.lieu)+'</td><td>'+esc(ev.organisateur)+'</td>'
             +'<td>'+esc(ev.carac)+'</td></tr>';
        });
        $('list').innerHTML=h+'</tbody></table>';
        $('list').querySelectorAll('tr[data-id]').forEach(function(tr){
            tr.addEventListener('click', function(){
                $('list').querySelectorAll('tr.sel').forEach(function(x){x.classList.remove('sel');});
                tr.classList.add('sel'); loadEvent(tr.getAttribute('data-id'));
            });
        });
    }

    function fillSubOptions(toType, selectIdx) {
        var sel=$('f-sub'); sel.innerHTML='<option value="">—</option>';
        (SUBMAP[toType]||[]).forEach(function(s){
            var o=document.createElement('option'); o.value=s.idx; o.textContent=s.label;
            if(selectIdx && String(s.idx)===String(selectIdx)) o.selected=true;
            sel.appendChild(o);
        });
    }
    // 3D (11) et Campagne (9) se tirent en pelotons ; sinon en cibles.
    function ciblesLabel(toType){
        var pel = (String(toType)==='11' || String(toType)==='9');
        $('a-cibles-label').textContent = pel ? 'Nombre de pelotons' : 'Nombre de cibles';
    }
    $('f-type').addEventListener('change', function(){ fillSubOptions(this.value, ''); ciblesLabel(this.value); });

    // Bloc ISK natif : les champs du mode viennent du endpoint ianseo (index-getIskConfig.php),
    // donc ils suivent les mises à jour de ianseo. On expose ChangeIskConfig en global car
    // le <select> l'appelle via onchange (comme la page native).
    var IskResetAlert = 'Changer de système effacera la configuration ISK précédente.';
    window.ChangeIskConfig = function(){
        var sel = $('IskSelect'); if(!sel) return;
        var m = $('ISK-Messages');
        if(sel.value!==sel.getAttribute('oldval') && sel.getAttribute('oldval')!=='') {
            if(m) m.innerHTML='<div class="warn" style="margin:6px 0">'+IskResetAlert+'</div>';
        } else if(m){ m.innerHTML=''; }
        fetch('<?= addslashes($ISK_CONFIG_URL) ?>?api='+encodeURIComponent(sel.value), {credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(d){ $('IskConfig').innerHTML = d.html || ''; })
            .catch(function(){ /* pas d'ISK disponible : on ignore */ });
    };

    function loadEvent(id) {
        $('review').style.display='';
        $('review').scrollIntoView({behavior:'smooth', block:'start'});   // amène l'utilisateur au bloc création
        $('prop-note').innerHTML='<p class="muted">Chargement…</p>';
        // Tag « Valide + Para » de la ligne : ligne regroupée ou tag dans les caractéristiques.
        var ev = lastEvents.filter(function(e){ return String(e.id)===String(id); })[0] || {};
        var vp = ev.para || /valide\s*\+\s*para/i.test(ev.carac||'');
        post('event',{sfa_id:id, sfa_vp: vp?1:0}).then(function(r){
            if(!r.ok){ $('prop-note').innerHTML='<p class="err">'+esc(r.msg)+'</p>'; return; }
            var pf=r.prefill, pr=r.proposal;

            $('f-code').value=pf.code; $('code-warn').textContent=pf.codeWarn||'';
            $('f-name').value=pf.name; $('f-short').value=(pf.name||'').slice(0,60);
            $('f-commitee').value=pf.commitee; $('f-comdescr').value=pf.comdescr;
            $('f-where').value=pf.where;
            $('f-tz').value=pf.timezone||'';   // pays = FRA en champ caché, plus de champ visible

            $('f-fy').value=pf.fromY; $('f-fm').value=pf.fromM; $('f-fd').value=pf.fromD;
            $('f-ty').value=pf.toY;   $('f-tm').value=pf.toM;   $('f-td').value=pf.toD;
            var pad=function(n){return String(n).padStart(2,'0');};
            var ds=pad(pf.fromD)+'/'+pad(pf.fromM)+'/'+pf.fromY, de=pad(pf.toD)+'/'+pad(pf.toM)+'/'+pf.toY;
            $('f-dates-text').textContent = (ds===de) ? ('le '+ds) : ('du '+ds+' au '+de);

            if(pr.creatable){
                $('f-type').value=pr.toType;
                fillSubOptions(pr.toType, pr.subIdx);
                $('prop-note').innerHTML='<p class="muted">Type proposé automatiquement d\'après l\'épreuve — '
                    +'vérifiez et corrigez si besoin.</p>';
            } else {
                $('f-type').value=''; fillSubOptions('', '');
                $('prop-note').innerHTML='<p class="warn">'+esc(pr.why||'Type ianseo non déterminé.')
                    +' Choisissez-le manuellement ci-dessous.</p>';
            }
            ciblesLabel($('f-type').value);
        });
    }

    $('review').addEventListener('submit', function(e){
        if(!$('f-type').value){ e.preventDefault(); alert('Choisissez la discipline avant de créer.'); return; }
        if(($('f-code').value||'').length>8){
            e.preventDefault();
            alert('Le code compétition dépasse 8 caractères — création impossible. Signale-le à un administrateur.');
        }
    });

    window.addEventListener('resize', placeBar);
})();
</script>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
