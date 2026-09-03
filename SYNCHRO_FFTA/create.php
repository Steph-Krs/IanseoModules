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
$BASE = ExtranetClient::BASE_PROD;   // création : lecture du calendrier fédéral en production

// Avec BOOKING (inscriptions en ligne), l'étape suivante logique est la mise en ligne des
// inscriptions, pas la saisie manuelle des participants — voir create-run.php pour la redirection.
$BOOKING_ON = sfa_booking_present();

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

// Configuration assistée des départs (§5 de MAPPING_TYPES_COMPETITION.md) : bornes et valeurs
// par défaut selon la famille de discipline (TAE/18m/Campagne/3D/Beursault — le Para partage la
// famille de son ToType, aucune détection spécifique nécessaire).
$sesFamilies   = sfa_session_families();
$sesRythme     = sfa_rythme_bounds();
$sesPelotons   = sfa_pelotons_config();
$sesDurations  = sfa_session_durations();

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
// Serveur fédéral en ligne (module de comptes actif) : les modes ISK pro/live
// déclenchent un trigger qui RÉVOQUE la licence ianseo → on ne propose que « aucun »
// et « lite ». Décision autonome (aucune dépendance au module AUTH) via $CFG->USERAUTH.
// Un mode interdit enregistré malgré tout est rebasculé sur lite à l'ouverture de la
// compétition (aut_isk_enforce, menu.php d'AUTH).
if (!empty($CFG->USERAUTH)) {
    unset($IskType['ng-pro'], $IskType['ng-live']);
}
$ISK_CONFIG_URL = $CFG->ROOT_DIR . 'Tournament/index-getIskConfig.php';

// Valeur par défaut proposée pour le champ « URL serveur » d'ISK-NG (Lite/Pro) : ce serveur
// ianseo lui-même — c'est presque toujours la bonne valeur (l'appli mobile interroge ce
// même serveur). L'organisateur peut la changer.
$iskScheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$ISK_DEFAULT_URL = $iskScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $CFG->ROOT_DIR;

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
    #sfa table.ses { border-collapse:collapse; width:100%; font-size:12px; margin-bottom:8px; }
    /* En-têtes sur plusieurs lignes si besoin : la table doit rester la plus étroite possible
       pour que les 2 colonnes tiennent côte à côte sur un écran de PC. */
    #sfa table.ses th { background:var(--bleu); color:#fff; padding:5px 6px; text-align:left; font-weight:600; }
    #sfa table.ses td { border-bottom:1px solid #e9e9e9; padding:5px 6px; vertical-align:middle; }
    #sfa .ses-step { display:inline-flex; align-items:center; gap:3px; white-space:nowrap; }
    #sfa .ses-step button { width:22px; height:22px; padding:0; line-height:1; font-size:14px; font-weight:bold; }
    #sfa .ses-step button:disabled { opacity:.35; cursor:default; }
    #sfa .ses-num { width:46px; text-align:center; padding:4px 2px; }
    #sfa .ses-day { width:128px; } #sfa .ses-time { width:84px; } #sfa .ses-dur { width:60px; text-align:center; }
    #sfa .ses-bis-label { font-weight:400; display:flex; align-items:flex-start; gap:4px; margin-top:3px; line-height:1.2; }
    #sfa .ses-warm { display:block; margin-top:3px; font-size:11px; color:var(--gris); white-space:nowrap; }
    #sfa .ses-warmends { width:40px; }
    #sfa .ses-del { color:var(--corail); border-color:var(--corail); background:#fff; width:26px; height:26px; padding:0; line-height:1; }
    #sfa .ses-del:disabled { opacity:.35; cursor:default; }
    #sfa .pill { border:2px solid #aaa; background:#ddd; color:#333; border-radius:5px; padding:1px 6px; font-size:11px; font-weight:bold; }
    #sfa .pill.ok { background:#d2f4cd; border-color:#75ae77; color:#04ac0b; }
    #sfa .pill.ko { background:#ffd6db; border-color:#bb7575; color:#a80000; }
    #sfa .grid { display:grid; grid-template-columns:170px 1fr; gap:8px 12px; align-items:center; }
    #sfa .grid > label { text-align:right; }
    #sfa .grid2 { display:grid; grid-template-columns:150px 1fr; gap:8px 10px; align-items:center; }
    #sfa .cols2 { display:flex; gap:28px; flex-wrap:wrap; max-width:100%; }
    /* Infos compétition à gauche, départs à droite. Les min-width font le travail tout seuls :
       dès que les 2 colonnes ne tiennent plus côte à côte, flex-wrap les empile — jamais de barre
       de défilement, tout reste visible. min() : la largeur mini s'efface d'elle-même si le
       conteneur est plus étroit, donc aucun débordement possible. */
    #sfa .col-base { flex:1 1 360px; min-width:min(340px, 100%); order:1; }
    #sfa .col-assist { flex:1 1 700px; min-width:min(700px, 100%); order:2; }
    /* Sous cette largeur, même empilée la table ne tient plus en colonnes : chaque départ devient
       une petite fiche (libellé + valeur par ligne), toujours sans défilement horizontal. */
    @media (max-width: 760px) {
        #sfa .col-base, #sfa .col-assist { flex-basis:auto; min-width:0; width:100%; }
        #sfa table.ses, #sfa table.ses tbody, #sfa table.ses tr, #sfa table.ses td { display:block; width:100%; }
        #sfa table.ses thead { display:none; }
        #sfa table.ses tr { border:1px solid var(--bord); border-radius:6px; padding:4px 8px; margin-bottom:10px; }
        #sfa table.ses td { display:flex; justify-content:space-between; align-items:center; gap:12px;
                            border-bottom:1px solid #f0f0f0; }
        #sfa table.ses tr td:last-child { border-bottom:0; justify-content:flex-end; }
        #sfa table.ses td::before { content:attr(data-label); font-weight:600; color:var(--gris); }
        #sfa table.ses td:last-child::before { content:none; }
    }
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

    /* Indicateur de chargement — 3 points qui rebondissent (repli en fondu si animation réduite) */
    #sfa .sfa-dots { display:inline-flex; gap:6px; vertical-align:middle; margin-left:4px; }
    #sfa .sfa-dots i { width:8px; height:8px; border-radius:50%; background:var(--bleu); opacity:.4;
        animation:sfaBounce 1s ease-in-out infinite; }
    #sfa .sfa-dots i:nth-child(2){ animation-delay:.16s; }
    #sfa .sfa-dots i:nth-child(3){ animation-delay:.32s; }
    @keyframes sfaBounce { 0%,80%,100%{ transform:translateY(0); opacity:.4; } 40%{ transform:translateY(-7px); opacity:1; } }
    @keyframes sfaFade   { 0%,100%{ opacity:.3; } 50%{ opacity:1; } }
    @media (prefers-reduced-motion: reduce) {
        #sfa .sfa-dots i { animation-name:sfaFade; }   /* fondu, sans saut : reste visible */
    }
</style>

<div id="sfa-bar">🔗 Extranet FFTA
    <select id="bar-role" style="display:none"></select>
    <a id="bar-logout">Déconnexion</a>
</div>

<div id="sfa">
  <div class="banner">
    <b>Calendrier fédéral</b> (<?= htmlspecialchars($BASE) ?>) — lecture seule : rien n'est modifié
    sur l'extranet. La compétition est créée dans ianseo, puis vous arrivez sur la saisie des participants.
  </div>

  <?php if (!empty($_GET['err'])): ?>
    <div class="warn" style="margin-bottom:16px"><b>Création non effectuée :</b> <?= htmlspecialchars($_GET['err']) ?></div>
  <?php endif; ?>

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
      <p id="role-active" class="muted"></p>
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
      <div id="list-diag"></div>
      <div id="list"></div>
    </div>
  </div>

  <form class="card" id="review" style="display:none" method="post" action="<?= htmlspecialchars($RUN) ?>">
    <h3>Vérifier et créer</h3>
    <div>
      <input type="hidden" name="d_Rule" value="FR">
      <input type="hidden" name="d_ToNameShort" id="f-short">
      <input type="hidden" name="d_ToIocCode" value="">
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
        <h4>Départs</h4>
        <table class="ses" id="ses-table">
          <thead><tr>
            <th id="ses-th-pel">Cibles autorisées</th>
            <th id="ses-th-ath">Archers / cible</th>
            <th>Jour</th>
            <th>Heure</th>
            <th>Durée (min)</th>
            <th>Entraînement inclus</th>
            <th></th>
          </tr></thead>
          <tbody id="ses-body"></tbody>
        </table>
        <p class="muted" id="ses-hint" style="margin:6px 0">Choisissez la discipline pour configurer les départs.</p>
        <button type="button" id="ses-add" disabled>+ Ajouter un départ</button>

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

        <label for="f-where">Ville</label>
        <input type="text" name="d_ToVenue" id="f-where" class="full">

        <label for="f-precis">Lieu précis</label>
        <span><input type="text" name="d_ToWhere" id="f-precis" class="full" required
              placeholder="Nom du gymnase, du stade, de la fôret…"></span>

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
        <?php if ($BOOKING_ON): ?>
        <button type="submit" class="primary">Créer et gérer la visibilité sur le serveur</button>
        <span class="muted">La compétition est créée dans ianseo, puis vous arrivez sur la page de gestion des inscriptions et de visibilité sur le serveur.</span>
        <?php else: ?>
        <button type="submit" class="primary">Créer et saisir les participants</button>
        <span class="muted">La compétition est créée dans ianseo, puis vous arrivez sur la saisie des participants.</span>
        <?php endif; ?>
      </p>
    </div>
  </form>
</div>

<script>
(function () {
    'use strict';
    var AJAX   = '<?= addslashes($AJAX) ?>';
    var SUBMAP = <?= json_encode($subMap, JSON_UNESCAPED_UNICODE) ?>;
    var AUTH_ON = <?= $sfaAuthOn ? 'true' : 'false' ?>;   // AUTH présent : #sfa-bar masquée, rôle auto
    // Configuration des départs par famille de discipline (MAPPING_TYPES_COMPETITION.md §5).
    var FAMILIES  = <?= json_encode($sesFamilies, JSON_UNESCAPED_UNICODE) ?>;
    var RYTHME    = <?= json_encode($sesRythme, JSON_UNESCAPED_UNICODE) ?>;
    var PELOTONS  = <?= json_encode($sesPelotons, JSON_UNESCAPED_UNICODE) ?>;
    var DURATIONS = <?= json_encode($sesDurations, JSON_UNESCAPED_UNICODE) ?>;
    var WARM_ENDS_DEFAULT = 3;   // volées d'entraînement, valeur courante (modifiable par ligne)
    var $ = function (id) { return document.getElementById(id); };

    function post(action, data) {
        var body = new URLSearchParams(Object.assign({sfa_action: action}, data || {}));
        return fetch(AJAX, {method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
            .then(function (r) { return r.json(); });
    }
    function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
    function msg(id,t,e){ var x=$(id); x.className=e?'err':'muted'; x.textContent=t||''; }
    // Chargement animé (3 points) : texte + points qui rebondissent.
    function dots(t){ return (t ? esc(t)+' ' : '') + '<span class="sfa-dots"><i></i><i></i><i></i></span>'; }
    function loadCard(t){ return '<p class="muted">' + dots(t) + '</p>'; }
    function msgLoad(id,t){ var x=$(id); x.className='muted'; x.innerHTML=dots(t); }

    function placeBar() {
        var bar=$('sfa-bar'), top=4;
        document.querySelectorAll('[id$="-bar"]').forEach(function(o){
            if(o!==bar && o.offsetParent!==null && getComputedStyle(o).position==='fixed')
                top=Math.max(top, o.getBoundingClientRect().bottom+4);
        });
        bar.style.top=top+'px';
    }

    // Dates par défaut : aujourd'hui → J+21
    (function(){
        var t=new Date(), iso=function(d){return d.toISOString().slice(0,10);};
        $('from').value = iso(new Date(t.getFullYear(), t.getMonth(), t.getDate()));
        $('to').value   = iso(new Date(t.getFullYear(), t.getMonth(), t.getDate()+21));
    })();

    /**
     * Si AUTH est présent, SA barre (#aut-bar) porte déjà le changement de rôle — la nôtre
     * reste masquée et le rôle extranet a été aligné automatiquement côté serveur (voir
     * ajax-create.php), sans UI ici.
     */
    function connected(roles, shared) {
        $('auth').style.display='none';
        $('list-card').style.display='';
        if (!AUTH_ON) {
            $('sfa-bar').style.display='block';
            $('bar-logout').style.display = shared ? 'none' : '';
            placeBar();
            if ((roles||[]).length>1) {
                var sel=$('bar-role'); sel.innerHTML='';
                roles.forEach(function(r){ var o=document.createElement('option');
                    o.value=r.value; o.textContent=r.label; o.selected=r.selected; sel.appendChild(o); });
                sel.style.display='inline-block';
            }
        }
        // Rôle extranet actuellement actif — visible même quand AUTH masque le sélecteur,
        // pour vérifier que la correspondance automatique a choisi le bon niveau.
        var active = (roles||[]).filter(function(r){ return r.selected; })[0];
        $('role-active').textContent = active ? ('Rôle actif sur l\'extranet : ' + active.label) : '';
        search();
    }

    /** Fin de vérification : on masque le « Vérification… » et on montre la bonne chose. */
    function doneChecking(showLogin) {
        $('checking').style.display = 'none';
        $('auth').style.display = showLogin ? '' : 'none';
    }

    post('status').then(function(r){
        if (r.ok && r.logged) { doneChecking(false); connected(r.roles, r.shared); return; }
        // Hors ligne : le dire clairement plutôt que de présenter un formulaire de connexion
        // qui échouera et ferait croire à un problème d'identifiants.
        if (r.ok && r.offline) { doneChecking(false); offlineNotice(r.msg); return; }
        doneChecking(true);   // pas de session valide → formulaire
    }).catch(function () { doneChecking(true); });

    /** Bandeau « pas de connexion » en tête de page, formulaire masqué. */
    function offlineNotice(msg) {
        $('checking').style.display = 'none';
        $('auth').style.display = 'none';
        $('list-card').style.display = 'none';
        $('review').style.display = 'none';
        var box = document.createElement('div');
        box.className = 'warn';
        box.style.cssText = 'border-left-color:#cc3333;background:#fdecea;margin-bottom:16px';
        box.innerHTML = '<b>Pas de connexion à Internet.</b><br>'
            + esc(msg || 'Cette étape nécessite une connexion pour lire le calendrier fédéral.')
            + '<br><button type="button" class="primary" style="margin-top:8px" '
            + 'onclick="location.reload()">Réessayer</button>';
        $('sfa').insertBefore(box, $('sfa').firstChild);
    }

    // « i » MFA : déplie/replie l'explication
    var mfaI = $('mfa-i');
    if (mfaI) mfaI.addEventListener('click', function () {
        var h = $('mfa-help');
        h.style.display = (h.style.display === 'block') ? 'none' : 'block';
    });

    $('btn-login').addEventListener('click', function(){
        var u=$('u').value.trim(), p=$('p').value, o=$('o').value.trim();
        if(!u||!p){ msg('m1','Identifiant et mot de passe requis.',true); return; }
        msgLoad('m1','Connexion');
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
        $('list').innerHTML=loadCard('Recherche');
        $('list-diag').innerHTML='';
        $('review').style.display='none';
        post('list',{sfa_from:$('from').value, sfa_to:$('to').value}).then(function(r){
            if(!r.ok){ $('list').innerHTML='<p class="err">'+esc(r.msg)+'</p>'; return; }
            lastEvents = r.events||[];
            showListDiag(r.diag);
            renderList(lastEvents);
        }).catch(function(e){ $('list').innerHTML='<p class="err">Erreur : '+esc(e.message)+'</p>'; });
    }

    /**
     * Diagnostic visible : niveau (search[Pers]) effectivement utilisé par l'extranet, et
     * comparaison entre le nombre d'épreuves qu'il ANNONCE (« Résultats : N ») et le nombre
     * que le module a su reconnaître. Si les deux divergent franchement, la cause n'est pas
     * un problème de rôle/niveau mais un tableau de structure différente à ce niveau — ce
     * diagnostic le montre directement, sans avoir à ouvrir les outils du navigateur.
     */
    function showListDiag(d) {
        if (!d) { return; }
        var mismatch = d.raw_total !== null && d.raw_total > 0 && d.parsed === 0;
        var levels = {FED:'Fédération', LIG:'Comité Régional', DEP:'Département', CLU:'Club'};
        var lvl = levels[d.pers] || d.pers || '—';
        var h = '<p class="muted">Niveau utilisé sur l\'extranet : <b>' + esc(lvl) + '</b>'
              + (d.raw_total !== null ? ' · ' + d.raw_total + ' épreuve(s) annoncée(s)' : '') + '.</p>';
        if (mismatch) {
            h = '<p class="warn">⚠ L\'extranet annonce <b>' + d.raw_total + '</b> épreuve(s) à ce niveau, '
              + 'mais aucune n\'a pu être lue par le module (structure de tableau inattendue à ce niveau). '
              + 'Niveau utilisé : <b>' + esc(lvl) + '</b>. Signale ce cas, ce n\'est pas normal.</p>' + h;
        }
        $('list-diag').innerHTML = h;
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
    // ── Départs (table par ligne, pilotée par la famille de discipline — §5 du fichier de
    // correspondance : FAMILIES/RYTHME/PELOTONS/DURATIONS) ────────────────────────────────────
    var sesIdx = 0;

    function familyFor(toType) { return FAMILIES[toType] || null; }
    function sesCurrentFamily() { return familyFor($('f-type').value); }

    /** Titre de la colonne 1 et unité (cible / peloton) de la famille — §5.B du fichier de mapping. */
    function sesLabels(fam) {
        var cfg = PELOTONS[fam] || {};
        return { title: cfg.title || 'Cibles autorisées', unit: cfg.unit || 'cible' };
    }

    /** Le TAE et le 18m se tirent en cibles, les parcours et le Beursault en pelotons. */
    function sesApplyLabels(fam) {
        var l = sesLabels(fam);
        $('ses-th-pel').textContent = l.title;
        $('ses-th-ath').textContent = 'Archers / ' + l.unit;
        // data-label : libellés repris en mode fiches (écran étroit)
        $('ses-body').querySelectorAll('.ses-pel-cell').forEach(function (td) {
            td.setAttribute('data-label', l.title);
        });
        $('ses-body').querySelectorAll('.ses-ath-cell').forEach(function (td) {
            td.setAttribute('data-label', 'Archers / ' + l.unit);
        });
    }

    /** Jour par défaut du premier départ auto-ajouté = date de début de la compétition. */
    function sesDefaultDay() {
        var y = $('f-fy').value, m = $('f-fm').value, d = $('f-fd').value;
        if (!y || !m || !d) return '';
        var pad = function (n) { return String(n).padStart(2, '0'); };
        return y + '-' + pad(m) + '-' + pad(d);
    }

    // Grisé (readonly) UNIQUEMENT quand la valeur ne peut vraiment pas être saisie directement
    // (toggle « pelotons bis autorisés » = seulement 2 valeurs possibles, Beursault archers/cible
    // = valeur fixe) — sinon champ normal et modifiable au clavier, comme le champ durée, en plus
    // des boutons +/- qui restent disponibles pour la saisie rapide.
    function pelotonsCellHtml(fam, val) {
        var cfg = PELOTONS[fam];
        if (cfg && cfg.mode === 'toggle') {
            var checked = (val === cfg.on);
            return '<input type="number" class="ses-num ses-pel" readonly value="' + (checked ? cfg.on : cfg.off) + '">'
                 + '<label class="ses-bis-label"><input type="checkbox" class="ses-pel-bis"' + (checked ? ' checked' : '') + '> pelotons bis autorisés</label>';
        }
        var def = cfg ? cfg.default : 24;
        var v = (val != null && !isNaN(val)) ? val : def;
        return '<span class="ses-step">'
             + '<button type="button" class="ses-pel-minus">−</button>'
             + '<input type="number" class="ses-num ses-pel" required min="1" value="' + v + '">'
             + '<button type="button" class="ses-pel-plus">+</button></span>';
    }

    function athCellHtml(fam, val) {
        var b = RYTHME[fam];
        if (b && b.fixed) {
            return '<input type="number" class="ses-num ses-ath" readonly value="' + b.min + '">';
        }
        var min = b ? b.min : 1, max = b ? b.max : null, def = b ? b.default : 4;
        var v = (val != null && !isNaN(val)) ? Math.min(Math.max(val, min), (max != null ? max : val)) : def;
        return '<span class="ses-step">'
             + '<button type="button" class="ses-ath-minus">−</button>'
             + '<input type="number" class="ses-num ses-ath" required min="' + min + '"' + (max != null ? ' max="' + max + '"' : '') + ' value="' + v + '">'
             + '<button type="button" class="ses-ath-plus">+</button></span>';
    }

    /** Branche les boutons +/- d'un stepper ET la saisie directe au clavier (pas de plafond si max===null). */
    function wireStepper(scope, minusSel, plusSel, valSel, min, max, onChange) {
        var minus = scope.querySelector(minusSel), plus = scope.querySelector(plusSel), val = scope.querySelector(valSel);
        if (!minus || !plus || !val) return;
        function clamp(v) {
            v = parseInt(v, 10);
            if (isNaN(v)) v = (min != null ? min : 0);
            if (min != null) v = Math.max(v, min);
            if (max != null) v = Math.min(v, max);
            return v;
        }
        function refresh() {
            var v = parseInt(val.value, 10) || 0;
            minus.disabled = (min != null && v <= min);
            plus.disabled = (max != null && v >= max);
        }
        minus.addEventListener('click', function () {
            val.value = clamp((parseInt(val.value, 10) || 0) - 1); refresh(); if (onChange) onChange();
        });
        plus.addEventListener('click', function () {
            val.value = clamp((parseInt(val.value, 10) || 0) + 1); refresh(); if (onChange) onChange();
        });
        // Saisie directe au clavier (champ non readonly) : reclamp au blur/validation, comme la durée.
        val.addEventListener('change', function () {
            val.value = clamp(val.value); refresh(); if (onChange) onChange();
        });
        refresh();
    }

    /** Construit/reconstruit les cellules pelotons + archers d'une ligne pour une famille donnée. */
    function wirePelAthCell(tr, fam, pelVal, athVal) {
        var i = tr.dataset.idx;
        var pelTd = tr.querySelector('.ses-pel-cell'), athTd = tr.querySelector('.ses-ath-cell');
        pelTd.innerHTML = pelotonsCellHtml(fam, pelVal);
        athTd.innerHTML = athCellHtml(fam, athVal);

        var pelInput = pelTd.querySelector('.ses-pel'); pelInput.name = 'sfa_ses_cibles[' + i + ']';
        var athInput = athTd.querySelector('.ses-ath'); athInput.name = 'sfa_ses_rythme[' + i + ']';

        var pcfg = PELOTONS[fam];
        if (pcfg && pcfg.mode === 'toggle') {
            pelTd.querySelector('.ses-pel-bis').addEventListener('change', function () {
                pelInput.value = this.checked ? pcfg.on : pcfg.off;
            });
        } else {
            wireStepper(pelTd, '.ses-pel-minus', '.ses-pel-plus', '.ses-pel', 1, null);
        }
        var b = RYTHME[fam];
        if (!b || !b.fixed) {
            wireStepper(athTd, '.ses-ath-minus', '.ses-ath-plus', '.ses-ath', b ? b.min : 1, b ? b.max : null,
                function () { refreshDuration(tr); });
        }
    }

    /** Auto-remplit la durée depuis DURATIONS[famille][archers] — jamais si l'utilisateur l'a modifiée à la main. */
    function refreshDuration(tr) {
        if (tr.dataset.durDirty === '1') return;
        var fam = sesCurrentFamily();
        var athInput = tr.querySelector('.ses-ath');
        if (!fam || !athInput) return;
        var mins = (DURATIONS[fam] || {})[parseInt(athInput.value, 10)];
        if (mins != null) { tr.querySelector('.ses-dur').value = mins; }
    }

    function sesUpdateDelButtons() {
        var rows = $('ses-body').querySelectorAll('tr');
        rows.forEach(function (tr) { tr.querySelector('.ses-del').disabled = (rows.length <= 1); });
    }

    function removeSessionRow(tr) {
        if ($('ses-body').querySelectorAll('tr').length <= 1) return;   // au moins 1 départ
        tr.remove();
        sesUpdateDelButtons();
    }

    function sesRowValues(tr) {
        return {
            pel: parseInt(tr.querySelector('.ses-pel').value, 10),
            ath: parseInt(tr.querySelector('.ses-ath').value, 10),
            day: tr.querySelector('.ses-day').value,
            time: tr.querySelector('.ses-time').value,
            dur: tr.querySelector('.ses-dur').value,
            train: tr.querySelector('.ses-train').checked,
            warm: tr.querySelector('.ses-warmends').value
        };
    }

    /**
     * Le nombre de volées d'entraînement n'a de sens que si l'entraînement est compris dans
     * l'horaire du départ : le champ n'apparaît que dans ce cas. `required` est posé/retiré avec
     * l'affichage — un champ requis mais masqué empêcherait la soumission du formulaire
     * (le navigateur refuse de signaler un champ invalide qu'il ne peut pas atteindre).
     */
    function syncTrainCell(tr) {
        var on  = tr.querySelector('.ses-train').checked;
        var box = tr.querySelector('.ses-warm');
        var inp = tr.querySelector('.ses-warmends');
        box.style.display = on ? '' : 'none';
        if (on) { inp.setAttribute('required', 'required'); }
        else    { inp.removeAttribute('required'); }
    }

    /** Ajoute une ligne de départ. copyFrom (facultatif) = valeurs de la ligne précédente à reprendre telles quelles. */
    function addSessionRow(copyFrom) {
        var fam = sesCurrentFamily();
        if (!fam) return;
        var i = sesIdx++;
        var tr = document.createElement('tr');
        tr.dataset.idx = i;

        var day   = copyFrom ? copyFrom.day   : sesDefaultDay();
        var time  = copyFrom ? copyFrom.time  : '';
        var train = copyFrom ? copyFrom.train : (fam === 'TAE' || fam === '18m');
        var warm  = copyFrom ? copyFrom.warm  : WARM_ENDS_DEFAULT;
        var b = RYTHME[fam];
        var athDefault = copyFrom ? copyFrom.ath : (b ? b.default : 4);
        var durTable = DURATIONS[fam] || {};
        var dur = copyFrom ? copyFrom.dur : (durTable[athDefault] != null ? durTable[athDefault] : '');

        // Tous les champs d'un départ sont obligatoires (required) : une compétition ne peut pas
        // être créée avec un départ incomplet. data-label sert à l'affichage en fiches sur mobile.
        tr.innerHTML =
            '<td class="ses-pel-cell"></td>' +   // data-label posé par sesApplyLabels()
            '<td class="ses-ath-cell"></td>' +
            '<td data-label="Jour"><input type="date" class="ses-day" required name="sfa_ses_day[' + i + ']" value="' + esc(day || '') + '"></td>' +
            '<td data-label="Heure"><input type="time" class="ses-time" required name="sfa_ses_time[' + i + ']" value="' + esc(time || '') + '"></td>' +
            '<td data-label="Durée (min)"><input type="number" class="ses-dur" required min="1" name="sfa_ses_duration[' + i + ']" value="' + esc(dur) + '"></td>' +
            '<td data-label="Entraînement inclus" style="text-align:center">' +
                '<input type="hidden" name="sfa_ses_training[' + i + ']" value="0">' +
                '<input type="checkbox" name="sfa_ses_training[' + i + ']" value="1" class="ses-train"' + (train ? ' checked' : '') + '>' +
                '<span class="ses-warm">' +
                    '<input type="number" class="ses-warmends" min="1" max="20" ' +
                        'name="sfa_ses_warmends[' + i + ']" value="' + esc(warm) + '"> volées' +
                '</span>' +
            '</td>' +
            '<td><button type="button" class="ses-del" title="Supprimer ce départ">✕</button></td>';

        wirePelAthCell(tr, fam, copyFrom ? copyFrom.pel : null, copyFrom ? copyFrom.ath : null);

        tr.querySelector('.ses-dur').addEventListener('input', function () { tr.dataset.durDirty = '1'; });
        tr.querySelector('.ses-del').addEventListener('click', function () { removeSessionRow(tr); });
        tr.querySelector('.ses-train').addEventListener('change', function () { syncTrainCell(tr); });
        syncTrainCell(tr);

        $('ses-body').appendChild(tr);
        $('ses-hint').style.display = 'none';
        sesUpdateDelButtons();
        sesApplyLabels(fam);
    }

    $('ses-add').addEventListener('click', function () {
        var rows = $('ses-body').querySelectorAll('tr');
        var last = rows[rows.length - 1];
        addSessionRow(last ? sesRowValues(last) : null);
    });

    /** Appelé au changement de discipline : réactive/désactive « Ajouter », reclamp les lignes
     * existantes dans les nouvelles bornes, ajoute la première ligne si la table est vide. */
    function sesRefreshFamily() {
        var fam = sesCurrentFamily();
        $('ses-add').disabled = !fam;
        if (!fam) {
            $('ses-hint').textContent = 'Choisissez la discipline pour configurer les départs.';
            $('ses-hint').style.display = '';
            return;
        }
        var rows = Array.prototype.slice.call($('ses-body').querySelectorAll('tr'));
        if (!rows.length) { addSessionRow(null); return; }
        rows.forEach(function (tr) {
            var pelInput = tr.querySelector('.ses-pel'), athInput = tr.querySelector('.ses-ath');
            var pelVal = pelInput ? parseInt(pelInput.value, 10) : null;
            var athVal = athInput ? parseInt(athInput.value, 10) : null;
            wirePelAthCell(tr, fam, pelVal, athVal);
            refreshDuration(tr);
        });
        sesApplyLabels(fam);
    }

    $('f-type').addEventListener('change', function () { fillSubOptions(this.value, ''); sesRefreshFamily(); });

    // Bloc ISK natif : les champs du mode viennent du endpoint ianseo (index-getIskConfig.php),
    // donc ils suivent les mises à jour de ianseo. On expose ChangeIskConfig en global car
    // le <select> l'appelle via onchange (comme la page native).
    var IskResetAlert = 'Changer de système effacera la configuration ISK précédente.';
    var ISK_DEFAULT_URL = '<?= addslashes($ISK_DEFAULT_URL) ?>';
    // Serveur fédéral (module de comptes) : URL / code de sécurité / QR-code restent préremplis
    // et soumis, mais ne sont pas affichés — mêmes valeurs pour tout le monde. Même condition que
    // le filtrage des modes ISK plus haut ($CFG->USERAUTH), donc aucune dépendance au module AUTH.
    var ISK_HIDE_PREFILLED = <?= !empty($CFG->USERAUTH) ? 'true' : 'false' ?>;

    /**
     * Pré-remplit les champs ISK natifs juste chargés (vides pour une compétition qui n'existe
     * pas encore) avec des valeurs sûres, pour éviter à l'organisateur de les chercher :
     *  - URL serveur = ce serveur ianseo lui-même (cas quasi systématique) ;
     *  - Code de sécurité = un code à 4 chiffres tiré au hasard ;
     *  - QR-code obligatoire = Oui (n'existe qu'en mode Lite).
     * N'écrase jamais une valeur déjà présente (rechargement du fragment après un changement).
     *
     * Sur un serveur fédéral (module de comptes actif), ces 3 réglages n'ont pas à être exposés :
     * ils sont toujours les mêmes et l'organisateur n'a rien à y changer. On les remplit puis on
     * masque LEUR LIGNE seulement — les champs restent dans le formulaire et sont bien soumis,
     * la construction native est inchangée.
     */
    function iskPrefillDefaults() {
        var url = $('IskConfig').querySelector('input[name="Module[ISK-NG][ServerUrl]"]');
        if (url && !url.value) { url.value = ISK_DEFAULT_URL; }

        var pin = $('IskConfig').querySelector('input[name="Module[ISK-NG][ServerUrlPin]"]');
        if (pin && !pin.value) { pin.value = String(Math.floor(Math.random() * 10000)).padStart(4, '0'); }

        var qr = $('IskConfig').querySelector('select[name="Module[ISK-NG][ForceQRCodeScanning]"]');
        if (qr) { qr.value = '1'; }

        if (ISK_HIDE_PREFILLED) {
            [url, pin, qr].forEach(function (el) {
                var tr = el && el.closest('tr');
                if (tr) { tr.style.display = 'none'; }
            });
        }
    }

    window.ChangeIskConfig = function(){
        var sel = $('IskSelect'); if(!sel) return;
        var m = $('ISK-Messages');
        if(sel.value!==sel.getAttribute('oldval') && sel.getAttribute('oldval')!=='') {
            if(m) m.innerHTML='<div class="warn" style="margin:6px 0">'+IskResetAlert+'</div>';
        } else if(m){ m.innerHTML=''; }
        fetch('<?= addslashes($ISK_CONFIG_URL) ?>?api='+encodeURIComponent(sel.value), {credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(d){ $('IskConfig').innerHTML = d.html || ''; iskPrefillDefaults(); })
            .catch(function(){ /* pas d'ISK disponible : on ignore */ });
    };

    function loadEvent(id) {
        $('review').style.display='';
        $('review').scrollIntoView({behavior:'smooth', block:'start'});   // amène l'utilisateur au bloc création
        $('prop-note').innerHTML=loadCard('Chargement de l\'épreuve');
        // Tag « Valide + Para » de la ligne : ligne regroupée ou tag dans les caractéristiques.
        var ev = lastEvents.filter(function(e){ return String(e.id)===String(id); })[0] || {};
        var vp = ev.para || /valide\s*\+\s*para/i.test(ev.carac||'');
        post('event',{sfa_id:id, sfa_vp: vp?1:0}).then(function(r){
            if(!r.ok){ $('prop-note').innerHTML='<p class="err">'+esc(r.msg)+'</p>'; return; }
            var pf=r.prefill, pr=r.proposal;

            $('f-code').value=pf.code; $('code-warn').textContent=pf.codeWarn||'';
            $('f-name').value=pf.name; $('f-short').value=(pf.name||'').slice(0,60);
            $('f-commitee').value=pf.commitee; $('f-comdescr').value=pf.comdescr;
            $('f-where').value=pf.where; $('f-precis').value='';   // saisie libre, propre à chaque épreuve
            $('f-tz').value=pf.timezone||'';   // pays = FRA en champ caché, plus de champ visible

            // Départs propres à chaque épreuve : on repart d'une table vide.
            $('ses-body').innerHTML=''; sesIdx=0;

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
            sesRefreshFamily();
        });
    }

    $('review').addEventListener('submit', function(e){
        if(!$('f-type').value){ e.preventDefault(); alert('Choisissez la discipline avant de créer.'); return; }
        if(($('f-code').value||'').length>8){
            e.preventDefault();
            alert('Le code compétition dépasse 8 caractères — création impossible. Signale-le à un administrateur.');
            return;
        }
        // Départs : chaque ligne doit être complète (l'attribut required couvre déjà les champs,
        // ce contrôle attrape le cas « aucun départ » et double la validation côté client).
        var rows = $('ses-body').querySelectorAll('tr');
        if(!rows.length){
            e.preventDefault();
            alert('Ajoutez au moins un départ avant de créer la compétition.');
            return;
        }
        var incomplete = false, warmMissing = false;
        rows.forEach(function(tr){
            ['.ses-pel', '.ses-ath', '.ses-day', '.ses-time', '.ses-dur'].forEach(function(sel){
                var el = tr.querySelector(sel);
                if(!el || String(el.value).trim() === '') incomplete = true;
            });
            if(tr.querySelector('.ses-train').checked
               && String(tr.querySelector('.ses-warmends').value).trim() === '') warmMissing = true;
        });
        if(incomplete){
            e.preventDefault();
            alert('Chaque départ doit indiquer le nombre de cibles, le nombre d\'archers par cible, '
                + 'le jour, l\'heure et la durée.');
            return;
        }
        if(warmMissing){
            e.preventDefault();
            alert('Indiquez le nombre de volées d\'entraînement pour chaque départ où '
                + 'l\'entraînement est compris dans l\'horaire.');
        }
    });

    window.addEventListener('resize', placeBar);
})();
</script>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
