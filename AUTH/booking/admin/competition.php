<?php
/**
 * admin/competition.php — ouverture des inscriptions pour la compétition
 * actuellement ouverte dans ianseo.
 *
 * Page ORGANISATEUR : contrairement à public/, elle s'insère dans l'habillage
 * ianseo et suit les ACL du cœur.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/pricing.php';  // tarification avancée
require_once dirname(__DIR__) . '/lib/payment.php';  // moyens de paiement
require_once dirname(__DIR__) . '/lib/mandate.php';  // bk_mandate_visible
require_once dirname(__DIR__) . '/lib/targets.php';  // bk_rules_check
require_once dirname(__DIR__) . '/lib/archer.php';   // bk_csrf_*
require_once dirname(__DIR__) . '/lib/adopt.php';    // bk_adopt_check (persistance réimport)
require_once dirname(__DIR__) . '/lib/ui.php';       // bk_e

bk_schema();

$TOUR = intval($_SESSION['TourId']);
$msg  = '';
$err  = '';

// Publication sur ianseo.net : Tournament.ToOnlineId n'est renseigné qu'au moment où les
// codes de publication sont obtenus ET validés pour CETTE compétition (cœur ianseo,
// Common/Lib/CommonLib.php → CheckCredentials). C'est exactement ce qui fait disparaître
// le bloc « demander les codes » de Tournament/SetCredentials.php. Sans code, il n'existe
// aucune fiche ianseo.net : inutile de proposer d'en coller le lien.
$rOnline  = safe_fetch(safe_r_sql("SELECT ToOnlineId FROM Tournament WHERE ToId = $TOUR"));
$onlineId = $rOnline ? intval($rOnline->ToOnlineId) : 0;

// Persistance à travers un réimport : si cette compétition est une version plus
// récente d'une compétition déjà suivie par booking (même ToCode, ToId différent),
// on rapatrie automatiquement config, paiements, boutique et inscriptions. Ne fait
// rien (un SELECT indexé) hors de ce cas. Voir lib/adopt.php.
$adoptReport = bk_adopt_check($TOUR);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = 'Session expirée — rechargez la page et réessayez.';
    } elseif (isset($_POST['copy_from'])) {
        // « Copier depuis… » : reprendre la configuration d'une autre compétition accessible.
        $srcId = bk_copy_is_admin()
            ? bk_copy_resolve($_POST['copy_src_text'] ?? '', $TOUR)
            : intval($_POST['copy_src'] ?? 0);
        // Non-admin : la source doit être ré-vérifiée accessible (la liste affichée ne fait pas foi).
        if (!bk_copy_is_admin() && $srcId > 0) {
            $chk = safe_fetch(safe_r_sql("SELECT t.ToId FROM BK_Competitions o INNER JOIN Tournament t ON t.ToId = o.BcTournament
                WHERE t.ToId = $srcId AND t.ToId <> $TOUR AND " . bk_copy_access_where('t')));
            if (!$chk) $srcId = 0;
        }
        if ($srcId <= 0) {
            $err = 'Compétition source introuvable ou non accessible.';
        } elseif (bk_comp_copy_from($TOUR, $srcId)) {
            header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php?copied=1');
            exit;
        } else {
            $err = "La copie a échoué : la compétition source n'a pas de configuration d'inscription en ligne.";
        }
    } elseif (isset($_POST['set_level'])) {
        // Barre à 3 niveaux : applique la transition (snapshot / auto / restore) puis
        // recharge la page (PRG) pour afficher l'UI du niveau choisi.
        bk_comp_set_level($TOUR, intval($_POST['set_level']));
        header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php');
        exit;
    } elseif (isset($_POST['save_fee'])) {
        // Niveau 2 « publication simple » : tarif de base seul (sans la modulation avancée).
        $fee = number_format((float) str_replace(',', '.', (string) ($_POST['fee'] ?? 0)), 2, '.', '');
        safe_w_sql("UPDATE BK_Competitions SET BcFee = " . StrSafe_DB($fee) . " WHERE BcTournament = $TOUR");
        $msg = 'Tarif d\'inscription enregistré.';
    } else {
        $kind = (string) ($_POST['kind'] ?? '');
        if (!array_key_exists($kind, bk_restrict_kinds())) $kind = '';
        $err = bk_scope_error($kind, $_POST['code'] ?? '');
        if ($err === '') {
            // Tarification avancée : reconstruite depuis le POST puis normalisée.
            $num = function ($x) { return floatval(str_replace(',', '.', trim((string) $x))); };
            $pin = array(
                'categories' => array(), 'departures' => array(), 'rank' => array(),
                'prov' => array(
                    'deptCode'   => $_POST['prov_deptcode'] ?? '',
                    'regionCode' => $_POST['prov_regioncode'] ?? '',
                    'dept'       => $num($_POST['prov_dept'] ?? 0),
                    'region'     => $num($_POST['prov_region'] ?? 0),
                ),
            );
            foreach ((array) ($_POST['cat'] ?? array()) as $row) {
                if (!is_array($row)) continue;
                $price = trim((string) ($row['price'] ?? ''));
                $div   = array_values((array) ($row['div'] ?? array()));
                $cls   = array_values((array) ($row['cls'] ?? array()));
                if ($price === '' && !$div && !$cls) continue;     // règle vide ignorée
                $pin['categories'][] = array('label' => $row['label'] ?? '',
                    'div' => $div, 'cls' => $cls, 'price' => $num($price));
            }
            foreach ((array) ($_POST['dep'] ?? array()) as $ord => $val) {
                $v = $num($val); if ($v != 0.0) $pin['departures'][(string) intval($ord)] = $v;
            }
            foreach ((array) ($_POST['rank'] ?? array()) as $th => $val) {
                $v = $num($val); if ($v != 0.0 && intval($th) >= 2) $pin['rank'][(string) intval($th)] = $v;
            }
            $norm = bk_pricing_norm($pin);
            $pricingJson = bk_pricing_is_advanced($norm) ? json_encode($norm) : '';

            // Règles de placement : valeurs FFTA, modifiables seulement en DROM-TOM.
            $isDromPost = bk_is_dromtom(bk_org_agrement($TOUR));
            $save = array(
                'open'        => 1,   // niveau 3 = publié (la barre pilote la publication)
                'from'        => $_POST['from'] ?? '',
                'to'          => $_POST['to'] ?? '',
                'kind'        => $kind,
                'code'        => $_POST['code'] ?? '',
                'restrict_to' => $kind === '' ? '' : ($_POST['restrict_to'] ?? ''),
                'max_club'    => $isDromPost ? ($_POST['max_club'] ?? 2) : 2,
                'min_clubs'   => $isDromPost ? ($_POST['min_clubs'] ?? 3) : 3,
                'show_assign' => !empty($_POST['show_assign']),
                'show_gauges' => !empty($_POST['show_gauges']),
                'scoresheet'  => !empty($_POST['scoresheet']),
                'wish_letter' => !empty($_POST['wish_letter']),
                'wish_with'   => !empty($_POST['wish_with']),
                'wish_free'   => !empty($_POST['wish_free']),
                'manual_validation' => !empty($_POST['manual_validation']),
                'fee'         => $_POST['fee'] ?? 0,
                'pricing'     => $pricingJson,
                'payinfo'     => bk_payinfo_from_post($_POST['pay'] ?? array()),
                'docs_present'      => 1,
                'show_program'      => !empty($_POST['show_program']),
                'show_participants' => !empty($_POST['show_participants']),
                'show_results'      => !empty($_POST['show_results']),
                'show_dossard'      => !empty($_POST['show_dossard']),
            );
            // Visibilité du mandat : n'écrire la valeur que si la case était présente
            // (elle ne l'est que lorsqu'un mandat existe) — préserve le tri-état.
            if (!empty($_POST['show_mandate_present'])) {
                $save['show_mandate'] = !empty($_POST['show_mandate']);
            }
            // Idem pour le lien ianseo.net : la case n'est présentée que si la compétition
            // a ses codes de publication. Sans ce garde-fou, tout enregistrement fait case
            // absente effacerait le lien. L'ADRESSE, elle, n'est jamais postée : elle est
            // reconstruite depuis ToOnlineId par bk_comp_save().
            if (!empty($_POST['ianseo_present'])) {
                $save['ianseo_present'] = 1;
                $save['show_ianseo'] = !empty($_POST['show_ianseo']);
            }
            bk_comp_save($TOUR, $save);
            safe_w_sql("UPDATE BK_Competitions SET BcPublishLevel = 3 WHERE BcTournament = $TOUR");
            $msg = 'Configuration enregistrée.';
        }
    }
}

/* Enregistrement automatique : même POST, même validation, mais on renvoie l'état au
   lieu de la page. Volontairement PAS JsonOut() — il pose « Access-Control-Allow-Origin: * »,
   inutile ici (appel de même origine) sur une page d'administration. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok'  => ($err === ''),
        'msg' => ($err !== '' ? $err : ($msg !== '' ? $msg : 'Enregistré')),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['copied'])) $msg = 'Configuration reprise depuis l\'autre compétition. Vérifiez les dates et les contraintes du terrain.';

$cfg      = bk_comp_config($TOUR);
$sessions = bk_comp_sessions($TOUR);
$level    = intval($cfg->BcPublishLevel ?? 1);          // barre à 3 niveaux
$copyAdmin  = bk_copy_is_admin();
$copySources = $copyAdmin ? array() : bk_copy_sources($TOUR);
$isDrom   = bk_is_dromtom(bk_org_agrement($TOUR));       // règles de placement modifiables
$rules    = ($level >= 2) ? bk_rules_check($TOUR, $cfg) : array();
$publicUrl = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://'
    . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/';

// Tarification : config existante + listes de catégories pour l'éditeur.
$pricing = bk_pricing_get($cfg);
$payinfo = bk_payinfo_get($cfg);
$payByM = array();
foreach ($payinfo as $pi) $payByM[$pi['m']] = $pi;
$divs = array();
$rs = safe_r_sql("SELECT DivId, DivDescription FROM Divisions WHERE DivTournament = $TOUR ORDER BY DivId");
while ($r = safe_fetch($rs)) $divs[(string) $r->DivId] = $r->DivDescription ?: $r->DivId;
$classes = array();
$rs = safe_r_sql("SELECT ClId, ClDescription FROM Classes WHERE ClTournament = $TOUR ORDER BY ClId");
while ($r = safe_fetch($rs)) $classes[(string) $r->ClId] = $r->ClDescription ?: $r->ClId;
// Codes locaux proposés d'après l'agrément organisateur tant qu'ils ne sont pas réglés.
$orgc = preg_replace('/[^0-9A-Za-z]/', '', bk_org_agrement($TOUR));
$provDeptDef   = $pricing['prov']['deptCode']   !== '' ? $pricing['prov']['deptCode']   : (strlen($orgc) >= 4 ? substr($orgc, 2, 2) : '');
$provRegionDef = $pricing['prov']['regionCode'] !== '' ? $pricing['prov']['regionCode'] : (strlen($orgc) >= 2 ? substr($orgc, 0, 2) : '');

/** Valeur d'un champ datetime-local depuis une colonne DATETIME. */
function bk_dtval($v)
{
    $v = trim((string) $v);
    return $v === '' ? '' : str_replace(' ', 'T', substr($v, 0, 16));
}

/** Options d'un <select> avec présélection (valeurs = clés du map). */
function bk_opts($map, $selected)
{
    $sel = array_flip(array_map('strval', (array) $selected));
    $h = '';
    foreach ($map as $k => $lab) {
        $h .= '<option value="' . bk_e($k) . '"' . (isset($sel[(string) $k]) ? ' selected' : '') . '>'
            . bk_e($lab) . '</option>';
    }
    return $h;
}

/** Montant en champ texte (2 déc., virgule) ; vide si null/''. */
function bk_amt($v)
{
    return ($v === '' || $v === null) ? '' : number_format((float) $v, 2, ',', '');
}

/** Une règle de catégorie (rendu serveur ET gabarit JS quand $i vaut '__i__'). */
function bk_cat_row($i, $rule, $divs, $classes)
{
    ob_start(); ?>
    <div class="bk-cat-row">
      <label class="bk-f"><span>Libellé</span>
        <input type="text" name="cat[<?= $i ?>][label]" value="<?= bk_e($rule['label'] ?? '') ?>" placeholder="ex. Jeunes"></label>
      <label class="bk-f"><span>Armes (vide = toutes)</span>
        <select name="cat[<?= $i ?>][div][]" multiple size="4"><?= bk_opts($divs, $rule['div'] ?? array()) ?></select></label>
      <label class="bk-f"><span>Catégories (vide = toutes)</span>
        <select name="cat[<?= $i ?>][cls][]" multiple size="4"><?= bk_opts($classes, $rule['cls'] ?? array()) ?></select></label>
      <label class="bk-f"><span>Prix (€)</span>
        <input type="text" name="cat[<?= $i ?>][price]" size="6" value="<?= bk_e(bk_amt($rule['price'] ?? '')) ?>"></label>
      <button type="button" class="bk-cat-del" title="Retirer cette règle">✕</button>
    </div>
    <?php return ob_get_clean();
}

$PAGE_TITLE = 'Inscriptions en ligne';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#bkadm { max-width: 100%; }
#bkadm .bk-sec { background:#fff; border:1px solid #d2d4d6; border-radius:6px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:14px 16px; margin:0 0 14px; }
#bkadm .bk-sec h2 { margin:0 0 10px; font-size:15px; color:#0254a8; }
#bkadm label { display:inline-block; margin:6px 10px 6px 0; font-size:13px; }
#bkadm input[type=text], #bkadm input[type=number], #bkadm input[type=datetime-local], #bkadm select {
    padding:6px 8px; border:1px solid #d2d4d6; border-radius:6px; font-size:14px; }
#bkadm .bk-row { display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; }
#bkadm .bk-f { display:flex; flex-direction:column; gap:3px; }
#bkadm .bk-f > span { font-size:12px; color:#7d8183; }
#bkadm .bk-chk { display:block; margin:5px 0; font-size:13px; }
#bkadm .bk-btn { padding:9px 18px; border:1px solid #0254a8; border-radius:6px;
    background:#0254a8; color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
#bkadm .bk-btn:hover { background:#01367c; border-color:#01367c; }
#bkadm .bk-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px; }
#bkadm .bk-ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkadm .bk-err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#bkadm .bk-hint { margin:6px 0 0; font-size:12px; color:#7d8183; }
#bkadm table.bk-t { border-collapse:collapse; font-size:13px; }
#bkadm table.bk-t th, #bkadm table.bk-t td { border:1px solid #d2d4d6; padding:5px 10px; text-align:left; }
#bkadm table.bk-t th { background:#f0f4ff; color:#01367c; }
#bkadm .bk-gauge { display:inline-block; width:130px; height:9px; background:#e9ecef;
    border-radius:5px; overflow:hidden; vertical-align:middle; margin-right:7px; }
#bkadm .bk-gauge i { display:block; height:100%; background:#0254a8; }
#bkadm .bk-url { font-family:monospace; font-size:12px; background:#f0f4ff;
    border:1px solid #a7d6ff; border-radius:5px; padding:3px 7px; }
#bkadm .bk-adv > summary { cursor:pointer; font-weight:600; color:#0254a8; margin:6px 0 10px; }
#bkadm .bk-adv h3 { font-size:13px; color:#01367c; margin:16px 0 4px; }
#bkadm .bk-cat-row { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;
    border:1px solid #e2e6ee; border-radius:6px; padding:8px 10px; margin:0 0 8px; }
#bkadm .bk-cat-row select[multiple] { min-width:150px; padding:2px 4px; }
#bkadm .bk-cat-del { border:1px solid #d2d4d6; background:#fff; color:#c0392b; border-radius:6px;
    padding:6px 10px; cursor:pointer; font-size:13px; align-self:center; }
#bkadm .bk-cat-del:hover { background:#ffd6db; }
#bkadm .bk-add { background:#f0f4ff; color:#0254a8; border-color:#a7d6ff; margin:2px 0 4px; }
#bkadm .bk-sim-out { margin-top:10px; padding:10px 12px; border:1px solid #a7d6ff; border-radius:8px;
    background:#f7faff; max-width:340px; }
#bkadm .bk-sim-t { width:100%; border-collapse:collapse; font-size:13px; }
#bkadm .bk-sim-t td { padding:3px 0; border:0; }
#bkadm .bk-sim-t td:last-child { text-align:right; white-space:nowrap; }
#bkadm .bk-sim-tot { margin:8px 0 0; padding-top:8px; border-top:1px solid #cfe0f5;
    font-size:14px; color:#01367c; }
#bkadm .bk-sim-tot b { font-size:17px; }
#bkadm .bk-pay-row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:7px 0; }
#bkadm .bk-pay-name { min-width:150px; margin:0; }
#bkadm .bk-pay-info { flex:1 1 240px; }
#bkadm .bk-levels { display:flex; gap:10px; flex-wrap:wrap; }
#bkadm .bk-lvl-form { margin:0; flex:1 1 200px; }
#bkadm .bk-lvl { width:100%; height:100%; text-align:left; cursor:pointer; display:block;
    border:1px solid #d2d4d6; border-radius:8px; background:#fff; padding:12px 14px; font:inherit; }
#bkadm .bk-lvl:hover { border-color:#0254a8; }
#bkadm .bk-lvl.on { border-color:#0254a8; background:#eaf1fb; box-shadow:0 0 0 1px #0254a8 inset; }
#bkadm .bk-lvl-n { display:inline-flex; width:22px; height:22px; border-radius:50%; background:#0254a8;
    color:#fff; align-items:center; justify-content:center; font-size:12px; font-weight:700; margin-right:6px; }
#bkadm .bk-lvl.on .bk-lvl-n { background:#01367c; }
#bkadm .bk-lvl-t { font-weight:700; color:#01367c; font-size:14px; }
#bkadm .bk-lvl-d { display:block; margin-top:5px; font-size:12px; color:#5b6470; line-height:1.35; }
#bkadm .bk-shortcuts { display:flex; flex-wrap:wrap; gap:8px; }
#bkadm .bk-shortcuts .bk-btn { text-decoration:none; }
#bkadm .bk-sec-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
#bkadm .bk-sec-head h2 { margin:0; }
#bkadm .bk-copy { font-size:13px; }
#bkadm .bk-copy > summary { cursor:pointer; color:#0254a8; font-weight:600; list-style:none;
    padding:5px 11px; border:1px solid #a7d6ff; border-radius:6px; background:#f0f4ff; white-space:nowrap; }
#bkadm .bk-copy > summary::-webkit-details-marker { display:none; }
#bkadm .bk-copy[open] > summary { background:#0254a8; color:#fff; border-color:#0254a8; }
#bkadm .bk-copy-body { margin-top:8px; padding:10px 12px; border:1px solid #d2d4d6; border-radius:8px;
    background:#fafbfc; width:min(420px, 90vw); }
#bkadm .bk-copy-body select, #bkadm .bk-copy-body input[type=text] { max-width:100%; width:100%; }
#bkadm .bk-copy-body .bk-btn { margin-top:8px; }
#bkadm .bk-pub-what { font-size:13px; line-height:1.5; margin:0 0 6px; padding:8px 10px;
    background:#eef4fb; border:1px solid #cddff2; border-radius:6px; color:#123a63; }
/* Enregistrement automatique : pastille d'état, flottante en bas à droite. */
#bk-pill { position:fixed; right:14px; bottom:14px; z-index:60; padding:8px 13px;
    border-radius:20px; font-size:12px; font-weight:600; border:1px solid transparent;
    box-shadow:0 2px 10px rgba(0,0,0,.18); }
#bk-pill.wait { background:#fdf4e3; border-color:#e8cf9a; color:#7a5b12; }
#bk-pill.ok   { background:#eaf7ea; border-color:#bfe3bf; color:#1c6b1c; }
#bk-pill.err  { background:#fdecea; border-color:#e8b4ae; color:#a02015; }
#bkadm .bk-auto-note { font-size:12px; color:#5a6570; margin:10px 0 0; }
</style>

<div id="bkadm">
<h1>Inscriptions en ligne</h1>

<?php if ($msg): ?><div class="bk-msg bk-ok"><?= bk_e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="bk-msg bk-err"><?= bk_e($err) ?></div><?php endif; ?>

<?php
// Compte-rendu d'un réimport qui vient d'être rapatrié (affiché une fois).
$ar = bk_adopt_report_pull();
if ($ar && !empty($ar['ok'])):
    $reimUrl = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/reimport.php';
?>
<div class="bk-msg" style="background:#eaf2fb;border:1px solid #b9d3f0;color:#123a63;text-align:left">
  <b>Version précédente récupérée.</b> Les données d'inscription et de paiement de la version
  antérieure de cette compétition ont été rattachées automatiquement.
  <ul style="margin:6px 0 0 18px">
    <?php if ($ar['payments']): ?><li><?= intval($ar['payments']) ?> suivi(s) de paiement conservé(s).</li><?php endif; ?>
    <?php if ($ar['relinked']): ?><li><?= intval($ar['relinked']) ?> inscription(s) en ligne reconnectée(s) au nouvel import (placement de l'import conservé).</li><?php endif; ?>
    <?php if ($ar['reinjected']): ?><li><?= intval($ar['reinjected']) ?> inscription(s) en ligne absente(s) de l'import ré-injectée(s) (à confirmer).</li><?php endif; ?>
    <?php if ($ar['imported']): ?><li><?= intval($ar['imported']) ?> participant(s) de l'import, saisi(s) hors module, rendus visibles dans leur espace — <b>sans information de paiement</b> (à confirmer).</li><?php endif; ?>
    <?php if ($ar['category']): ?><li><b><?= intval($ar['category']) ?> catégorie(s) divergente(s)</b> à trancher.</li><?php endif; ?>
    <?php if ($ar['reinject_fail']): ?><li><b><?= intval($ar['reinject_fail']) ?> inscription(s)</b> n'ont pas pu être ré-injectées.</li><?php endif; ?>
  </ul>
  <p style="margin:8px 0 0">L'organisateur tranche chaque écart (garder l'import ou booking), ou tout d'un coup.
    <a href="<?= bk_e($reimUrl) ?>" style="font-weight:600">Vérifier et trancher →</a></p>
</div>
<?php
endif;
// Rappel persistant tant qu'il reste des écarts à trancher.
$openConf = bk_reimport_conflicts($TOUR);
if ($openConf):
?>
<div class="bk-msg" style="background:#fdf0ef;border:1px solid #e8b4ae;color:#8b1a1a;text-align:left">
  <b>Réimport : <?= count($openConf) ?> élément(s) à valider</b> (catégories, inscriptions, participants importés).
  <a href="<?= bk_e($CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/reimport.php') ?>">Trancher →</a>
</div>
<?php endif; ?>

<div class="bk-sec">
  <div class="bk-sec-head">
    <h2>Ouverture des inscriptions sur ce serveur</h2>
    <details class="bk-copy">
      <summary>📋 Copier depuis…</summary>
      <div class="bk-copy-body">
        <p class="bk-hint" style="margin:0 0 8px">Reprendre la configuration d'une <b>autre compétition</b> :
          niveau de publication, inscriptions, visibilité, tarif, mandat, boutique et contraintes du terrain.
          Les <b>dates</b> gardent le même décalage par rapport à la date de début. Les logos et le lien
          ianseo.net ne sont pas copiés. <b>Les réglages actuels seront remplacés.</b></p>
        <form method="post" onsubmit="return confirm('Copier toute la configuration de la compétition source ? Les réglages actuels (inscriptions, tarif, mandat, boutique, contraintes du terrain) seront REMPLACÉS.');">
          <?= bk_csrf_field() ?>
          <?php if ($copyAdmin): ?>
            <label class="bk-f"><span>Code (ou identifiant) de la compétition source</span>
              <input type="text" name="copy_src_text" placeholder="ex. F26CF3D" autocomplete="off" required></label>
            <button type="submit" name="copy_from" value="1" class="bk-btn">Copier la configuration</button>
          <?php elseif (!$copySources): ?>
            <p class="bk-hint" style="margin:0">Aucune autre compétition configurée n'est accessible pour l'instant.</p>
          <?php else: ?>
            <label class="bk-f"><span>Compétition source</span>
              <select name="copy_src" required>
                <option value="">— choisir —</option>
                <?php foreach ($copySources as $s): ?>
                  <option value="<?= intval($s->ToId) ?>"><?= bk_e($s->ToName . ' (' . $s->ToCode . ') — ' . bk_date_fr($s->ToWhenFrom)) ?></option>
                <?php endforeach; ?>
              </select></label>
            <button type="submit" name="copy_from" value="1" class="bk-btn">Copier la configuration</button>
          <?php endif; ?>
        </form>
      </div>
    </details>
  </div>
  <p class="bk-pub-what">Il s'agit de la <b>page d'inscription en ligne de ce serveur</b> : ce que voient
     les archers qui se connectent ici <b>avec leur numéro de licence</b>. Eux seuls y ont accès —
     la compétition n'est visible ni du public, ni des moteurs de recherche.</p>
  <p class="bk-hint" style="margin-top:0">Cela <b>ne concerne pas ianseo.net</b> : rien n'y est envoyé
     ni publié depuis cette page. L'envoi des résultats vers ianseo.net reste le menu habituel de ianseo
     (<i>Compétition › Envoyer à ianseo.net</i>).</p>
  <div class="bk-levels">
    <?php
    $lvls = array(
      1 => array('Fermée', "Vous seul la voyez. Elle n'apparaît pas dans le calendrier des archers et personne ne peut s'y inscrire."),
      2 => array('Inscriptions ouvertes', "Les archers connectés la voient et s'inscrivent en ligne. Mandat et documents leur sont proposés automatiquement. Il ne reste que le tarif à indiquer et, si besoin, les contraintes du terrain et la boutique."),
      3 => array('Inscriptions ouvertes — réglages détaillés', "Idem, mais vous réglez vous-même chaque paramètre : dates, restriction géographique, ce que voient les archers, tarifs, moyens de paiement."),
    );
    foreach ($lvls as $n => $info): ?>
      <form method="post" class="bk-lvl-form">
        <?= bk_csrf_field() ?>
        <input type="hidden" name="set_level" value="<?= $n ?>">
        <button type="submit" class="bk-lvl <?= $level === $n ? 'on' : '' ?>">
          <span class="bk-lvl-t"><span class="bk-lvl-n"><?= $n ?></span><?= bk_e($info[0]) ?></span>
          <span class="bk-lvl-d"><?= bk_e($info[1]) ?></span>
        </button>
      </form>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($level == 1): ?>
  <div class="bk-sec">
    <p class="bk-hint" style="margin:0">Les inscriptions en ligne sont fermées : cette compétition
       n'apparaît pas dans le calendrier des archers connectés et ne compte pas dans leurs statistiques.
       Choisissez <b>Inscriptions ouvertes</b> pour la leur rendre visible en un clic.</p>
  </div>
<?php endif; ?>

<?php if ($level == 2): ?>
  <div class="bk-sec">
    <h2>À finaliser</h2>
    <p class="bk-hint" style="margin-top:0">Les archers connectés voient la compétition et peuvent s'inscrire ;
       mandat et documents leur sont proposés automatiquement. Indiquez le tarif d'inscription puis,
       si besoin, configurez les contraintes d'affectation du terrain et la boutique :</p>
    <form method="post" class="bk-row" style="margin:0 0 14px" data-autosave="1">
      <?= bk_csrf_field() ?>
      <input type="hidden" name="save_fee" value="1">
      <label class="bk-f"><span>Tarif d'inscription (€)</span>
        <input type="text" name="fee" size="8" value="<?= bk_e(number_format((float) $cfg->BcFee, 2, ',', '')) ?>"></label>
      <button type="submit" class="bk-btn" data-manual-save="1" style="align-self:flex-end">Enregistrer le tarif</button>
    </form>
    <p class="bk-hint" style="margin:0 0 6px">Tarif unique appliqué à chaque inscription. La modulation
       fine (par catégorie, départ, provenance…) reste disponible dans les
       <b>réglages détaillés</b>.</p>
    <p class="bk-shortcuts">
      <a class="bk-btn" href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/field.php">Contraintes d'affectation du terrain →</a>
      <a class="bk-btn" href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/shop.php">Boutique →</a>
      <a class="bk-btn" href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/dues.php">Sommes dues →</a>
    </p>
  </div>
<?php endif; ?>

<?php if ($level == 3): ?>
<form method="post" id="bk-cfg" data-autosave="1">
<?= bk_csrf_field() ?>

<div class="bk-sec">
  <h2>Période d'inscription</h2>
  <div class="bk-row">
    <label class="bk-f"><span>Ouverture le (facultatif)</span>
      <input type="datetime-local" name="from" value="<?= bk_e(bk_dtval($cfg->BcOpenFrom)) ?>"></label>
    <label class="bk-f"><span>Clôture le (facultatif)</span>
      <input type="datetime-local" name="to" value="<?= bk_e(bk_dtval($cfg->BcOpenTo)) ?>"></label>
  </div>
  <p class="bk-hint">Sans date, l'inscription est ouverte dès maintenant et ne se referme pas d'elle-même.
     État actuel : <b style="color: crimson;"><?= $cfg->BcIsOpen ? 'inscriptions ouvertes' : 'hors période' ?></b>.</p>
</div>

<div class="bk-sec">
  <h2>Restriction géographique</h2>
  <div class="bk-row">
    <label class="bk-f"><span>Réservée aux archers</span>
      <select name="kind">
        <?php foreach (bk_restrict_kinds() as $k => $lab): ?>
          <option value="<?= bk_e($k) ?>" <?= $cfg->BcRestrictKind === $k ? 'selected' : '' ?>><?= bk_e($lab) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="bk-f"><span>Code (ex. 60 ou 07)</span>
      <input type="text" name="code" size="8" maxlength="12" value="<?= bk_e($cfg->BcRestrictCode) ?>"></label>
    <label class="bk-f"><span>Ouvrir à tous à partir du (facultatif)</span>
      <input type="datetime-local" name="restrict_to" value="<?= bk_e(bk_dtval($cfg->BcRestrictTo)) ?>"></label>
  </div>
  <p class="bk-hint">Le périmètre est comparé à l'agrément du club de l'archer (format LLDDCCC :
     ligue, département, club). Passé la date d'ouverture à tous, la restriction est levée
     automatiquement.
     <?php if ($cfg->BcRestrictKind !== ''): ?>
       État actuel : <b><?= $cfg->BcAllOpen ? 'ouverte à tous' : 'restreinte' ?></b>.
     <?php endif; ?>
  </p>
</div>

<div class="bk-sec">
  <h2>Placement et validation</h2>
  <?php if ($isDrom): ?>
    <p class="bk-hint" style="margin-top:0">Compétition DROM-TOM : les règles fédérales de mixité peuvent
       être ajustées (peu de clubs sur le territoire).</p>
    <div class="bk-row">
      <label class="bk-f"><span>Archers d'un même club, au plus, par cible</span>
        <input type="number" name="max_club" min="1" max="20" value="<?= intval($cfg->BcMaxPerClubPerTarget) ?>"></label>
      <label class="bk-f"><span>Clubs différents, au moins, par départ</span>
        <input type="number" name="min_clubs" min="1" max="50" value="<?= intval($cfg->BcMinClubsPerSession) ?>"></label>
    </div>
  <?php else: ?>
    <p class="bk-hint" style="margin-top:0">Règles fédérales appliquées automatiquement : au plus
       <b>2 archers d'un même club par cible</b> et au moins <b>3 clubs par départ</b>. (Modifiables
       uniquement pour les compétitions DROM-TOM.)</p>
  <?php endif; ?>
  <label class="bk-chk" style="margin-top:6px"><input type="checkbox" name="manual_validation" value="1" <?= !empty($cfg->BcManualValidation) ? 'checked' : '' ?>>
    <b>Valider manuellement chaque inscription</b> avant l'attribution de sa cible</label>
  <p class="bk-hint">Par défaut, une inscription en ligne est placée automatiquement selon le plan.
     Coché, chaque inscription reste « en attente » jusqu'à ce que vous la validiez (page
     <b>Attribution des cibles</b>) — l'affectation ne se fait qu'ensuite.</p>
</div>

<div class="bk-sec">
  <h2>Ce que voient les archers</h2>
  <label class="bk-chk"><input type="checkbox" name="show_gauges" value="1" <?= $cfg->BcShowGauges ? 'checked' : '' ?>>
    Afficher les places restantes par départ</label>
  <label class="bk-chk"><input type="checkbox" name="show_assign" value="1" <?= $cfg->BcShowAssignment ? 'checked' : '' ?>>
    Afficher les attributions de cibles en temps réel</label>
  <label class="bk-chk"><input type="checkbox" name="scoresheet" value="1" <?= $cfg->BcAllowScoresheet ? 'checked' : '' ?>>
    Autoriser chaque archer à imprimer sa feuille de marque</label>
  <?php $hasMandate = trim((string) ($cfg->BcMandate ?? '')) !== ''; ?>
  <?php if ($hasMandate): ?>
    <input type="hidden" name="show_mandate_present" value="1">
    <label class="bk-chk"><input type="checkbox" name="show_mandate" value="1" <?= bk_mandate_visible($cfg) ? 'checked' : '' ?>>
      Rendre le <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/mandate.php">mandat</a> consultable
      par les archers (fiche compétition du calendrier + « Mes inscriptions »)</label>
  <?php else: ?>
    <p class="bk-hint" style="margin:6px 0 0">Aucun mandat pour l'instant.
      <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/mandate.php">Créer le mandat</a> pour pouvoir le proposer aux archers.</p>
  <?php endif; ?>

  <h3 class="bk-h3">Documents de la compétition</h3>
  <p class="bk-hint">Rassemblés pour l'archer sur une page « Documents » (accessible depuis le calendrier
     et « Mes inscriptions »). Le mandat s'y ajoute automatiquement s'il est rendu visible ci-dessus.</p>
  <?php // L'adresse n'est plus demandée : elle se reconstruit depuis l'identifiant en ligne
        // attribué avec les codes de publication. Il ne reste à décider que de l'afficher.
        $ianseoUrl      = bk_ianseo_url($TOUR);
        $ianseoUrlSaved = trim((string) ($cfg->BcIanseoUrl ?? '')); ?>
  <?php if ($ianseoUrl !== ''): ?>
    <input type="hidden" name="ianseo_present" value="1">
    <label class="bk-chk"><input type="checkbox" name="show_ianseo" value="1" <?= $ianseoUrlSaved !== '' ? 'checked' : '' ?>>
      Lien vers la <b>fiche ianseo.net</b> de la compétition (identifiant en ligne <?= $onlineId ?>) :
      <a href="<?= bk_e($ianseoUrl) ?>" target="_blank" rel="noopener"><?= bk_e($ianseoUrl) ?></a></label>
  <?php elseif ($ianseoUrlSaved !== ''): ?>
    <?php // Valeur dérivée dont la source a disparu (réimport sans identifiant en ligne,
          // ou adresse saisie à la main du temps où le champ était libre) : on l'annonce
          // et le prochain enregistrement la retire — il n'existe plus de fiche à pointer. ?>
    <input type="hidden" name="ianseo_present" value="1">
    <p class="bk-hint" style="margin:6px 0 0; color:#a86b00">Un lien ianseo.net est enregistré
       (<?= bk_e($ianseoUrlSaved) ?>) alors que cette compétition n'a pas (ou plus) de codes de
       publication ianseo.net : il ne pointe vers aucune fiche et sera retiré au prochain
       enregistrement de cette page.</p>
  <?php endif; ?>

  <p class="bk-hint" style="margin-top:12px">Documents officiels ianseo (PDF) à proposer aux archers. Ils sont
     régénérés à la demande depuis les données de la compétition — n'affichez les résultats qu'une fois les
     scores saisis.</p>
  <input type="hidden" name="docs_present" value="1">
  <label class="bk-chk"><input type="checkbox" name="show_program" value="1" <?= !empty($cfg->BcShowProgram) ? 'checked' : '' ?>>
    Programme des départs (répartition par cible)</label>
  <label class="bk-chk"><input type="checkbox" name="show_participants" value="1" <?= !empty($cfg->BcShowParticipants) ? 'checked' : '' ?>>
    Liste des participants</label>
  <label class="bk-chk"><input type="checkbox" name="show_results" value="1" <?= !empty($cfg->BcShowResults) ? 'checked' : '' ?>>
    Résultats — les boutons apparaissent selon l'avancement : Qualifications (dès les premiers scores),
    Duels individuels et Matchs par équipe (dès les grilles générées)</label>
  <?php $dossardCard = bk_dossard_card($TOUR); ?>
  <label class="bk-chk"><input type="checkbox" name="show_dossard" value="1" <?= !empty($cfg->BcShowDossard) ? 'checked' : '' ?>>
    Dossard — chaque archer imprime <b>son</b> dossard (et ceux qu'il a inscrits) depuis la page Documents.
    Utilise le premier gabarit « Dossard (Qualification) » de la compétition
    (<a href="<?= $CFG->ROOT_DIR ?>Accreditation/IdCards.php?CardType=Q" target="_blank" rel="noopener">Accréditation › Dossards</a>).
    <?php if ($dossardCard === null): ?><span class="bk-hint" style="color:#a86b00">Aucun gabarit de dossard n'existe encore : le bouton n'apparaîtra qu'une fois un dossard créé.</span><?php endif; ?></label>

  <h3 class="bk-h3">Souhaits proposés à l'inscription</h3>
  <p class="bk-hint">Choisissez ce que l'archer peut demander. Par défaut, seule la position sur la cible.</p>
  <label class="bk-chk"><input type="checkbox" name="wish_letter" value="1" <?= $cfg->BcWishLetter ? 'checked' : '' ?>>
    Position souhaitée sur la cible (lettre)</label>
  <label class="bk-chk"><input type="checkbox" name="wish_with" value="1" <?= $cfg->BcWishWith ? 'checked' : '' ?>>
    « Sur la même cible que… » (un archer de son club déjà inscrit)</label>
  <label class="bk-chk"><input type="checkbox" name="wish_free" value="1" <?= $cfg->BcWishFree ? 'checked' : '' ?>>
    Champ libre « Autre demande » (transmis à l'organisateur)</label>
</div>

<div class="bk-sec">
  <h2>Tarifs</h2>
  <div class="bk-row">
    <label class="bk-f"><span>Tarif de base (€)</span>
      <input type="text" name="fee" size="8" value="<?= bk_e(number_format((float) $cfg->BcFee, 2, ',', '')) ?>"></label>
  </div>
  <p class="bk-hint">Montant appliqué par défaut à une inscription. Laissez les tarifs avancés
     repliés si un tarif unique suffit.</p>

  <details class="bk-adv" <?= bk_pricing_is_advanced($pricing) ? 'open' : '' ?>>
    <summary>Tarifs avancés (facultatif)</summary>

    <p class="bk-hint" style="margin:0 0 6px">
      <b>Comment le prix est calculé :</b> on part du <b>tarif de base</b> (remplacé par le
      <b>prix fixe d'une catégorie</b> si une règle correspond à l'archer), puis on
      <b>ajoute ou retire</b> l'ajustement du <b>départ</b>, celui de la <b>provenance</b>
      (le plus local seul, sans cumul) et celui du <b>dégressif</b> multi-inscriptions ;
      jamais en dessous de 0 €. L'<b>aperçu en bas</b> montre le résultat en direct.</p>

    <h3>Par catégorie (prix fixe)</h3>
    <p class="bk-hint">Une règle fixe le prix pour les armes et catégories cochées (vides = toutes).
       La première règle correspondant à l'archer l'emporte ; sinon le tarif de base s'applique.</p>
    <div id="bk-cat-list">
      <?php foreach ($pricing['categories'] as $i => $rule) echo bk_cat_row($i, $rule, $divs, $classes); ?>
    </div>
    <button type="button" class="bk-btn bk-add" onclick="bkAddCat()">+ Ajouter une règle</button>
    <template id="bk-cat-tpl"><?= bk_cat_row('__i__', array(), $divs, $classes) ?></template>

    <h3>Par départ (ajustement +/−)</h3>
    <p class="bk-hint">Écart appliqué au tarif selon le départ choisi (ex. −2 pour un 2ᵉ départ moins cher).
       Laisser vide = pas d'écart.</p>
    <?php if (!$sessions): ?>
      <p class="bk-hint">Aucun départ configuré pour l'instant.</p>
    <?php else: ?>
      <div class="bk-row">
        <?php foreach ($sessions as $s): $o = intval($s->SesOrder); $dv = $pricing['departures'][(string) $o] ?? ''; ?>
          <label class="bk-f"><span>Départ <?= $o ?><?= $s->SesName ? ' — ' . bk_e($s->SesName) : '' ?> (Δ €)</span>
            <input type="text" name="dep[<?= $o ?>]" size="6" value="<?= bk_e(bk_amt($dv)) ?>"></label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h3>Selon la provenance (favoriser les locaux)</h3>
    <p class="bk-hint">Écart pour les archers du département / de la ligue de l'organisateur.
       Le plus local l'emporte (pas de cumul). Codes pré-remplis d'après votre agrément — modifiables.</p>
    <div class="bk-row">
      <label class="bk-f"><span>Département local (2 chiffres)</span>
        <input type="text" name="prov_deptcode" size="4" maxlength="2" value="<?= bk_e($provDeptDef) ?>"></label>
      <label class="bk-f"><span>Δ départemental (€)</span>
        <input type="text" name="prov_dept" size="6" value="<?= bk_e(bk_amt($pricing['prov']['dept'] ?: '')) ?>"></label>
      <label class="bk-f"><span>Ligue locale (2 chiffres)</span>
        <input type="text" name="prov_regioncode" size="4" maxlength="2" value="<?= bk_e($provRegionDef) ?>"></label>
      <label class="bk-f"><span>Δ régional (€)</span>
        <input type="text" name="prov_region" size="6" value="<?= bk_e(bk_amt($pricing['prov']['region'] ?: '')) ?>"></label>
    </div>

    <h3>Dégressif multi-inscriptions</h3>
    <p class="bk-hint">Écart selon le rang de l'inscription de la personne sur cette compétition.</p>
    <div class="bk-row">
      <label class="bk-f"><span>À partir de la 2ᵉ (Δ €)</span>
        <input type="text" name="rank[2]" size="6" value="<?= bk_e(bk_amt($pricing['rank']['2'] ?? '')) ?>"></label>
      <label class="bk-f"><span>À partir de la 3ᵉ (Δ €)</span>
        <input type="text" name="rank[3]" size="6" value="<?= bk_e(bk_amt($pricing['rank']['3'] ?? '')) ?>"></label>
    </div>

    <h3>Aperçu du tarif</h3>
    <p class="bk-hint">Simulez un archer : le prix se met à jour en direct d'après votre configuration ci-dessus.</p>
    <div class="bk-row bk-sim-in">
      <label class="bk-f"><span>Arme</span>
        <select id="sim-div"><?php foreach ($divs as $k => $v) echo '<option value="' . bk_e($k) . '">' . bk_e($v) . '</option>'; ?></select></label>
      <label class="bk-f"><span>Catégorie</span>
        <select id="sim-cls"><?php foreach ($classes as $k => $v) echo '<option value="' . bk_e($k) . '">' . bk_e($v) . '</option>'; ?></select></label>
      <label class="bk-f"><span>Départ</span>
        <select id="sim-ses"><option value="0">—</option><?php foreach ($sessions as $s) { $o = intval($s->SesOrder); echo '<option value="' . $o . '">Départ ' . $o . '</option>'; } ?></select></label>
      <label class="bk-f"><span>Provenance</span>
        <select id="sim-prov"><option value="">Hors zone</option><option value="region">Régional</option><option value="dept">Local départemental</option></select></label>
      <label class="bk-f"><span>Inscription n°</span>
        <select id="sim-rank"><option value="1">1re</option><option value="2">2e</option><option value="3">3e</option></select></label>
    </div>
    <div class="bk-sim-out">
      <table class="bk-sim-t"><tbody id="sim-lines"></tbody></table>
      <p class="bk-sim-tot">Total : <b id="sim-total">—</b></p>
    </div>
  </details>
</div>

<div class="bk-sec">
  <h2>Moyens de paiement</h2>
  <p class="bk-hint">Cochez les moyens acceptés, précisez quand ils le sont et l'info utile (ordre du
     chèque, RIB, contact…). À la fin de son inscription, l'archer les voit.</p>
  <?php foreach (bk_payment_methods() as $mk => $ml): $cur = $payByM[$mk] ?? null; ?>
    <div class="bk-pay-row">
      <label class="bk-chk bk-pay-name"><input type="checkbox" name="pay[<?= bk_e($mk) ?>][on]" value="1" <?= $cur ? 'checked' : '' ?>>
        <b><?= bk_e($ml) ?></b></label>
      <select name="pay[<?= bk_e($mk) ?>][when]">
        <?php foreach (bk_payinfo_when_labels() as $wk => $wl): ?>
          <option value="<?= bk_e($wk) ?>" <?= ($cur && $cur['when'] === $wk) ? 'selected' : '' ?>><?= bk_e($wl) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" class="bk-pay-info" name="pay[<?= bk_e($mk) ?>][info]"
             value="<?= bk_e($cur['info'] ?? '') ?>" placeholder="Info (ex. à l'ordre de…, RIB, contact)">
    </div>
  <?php endforeach; ?>
</div>

<button type="submit" class="bk-btn" data-manual-save="1">Enregistrer</button>
</form>
<?php endif; // fin du mode avancé (niveau 3) ?>

<?php if ($level >= 2): ?>
<div class="bk-sec" style="margin-top:18px">
  <h2>Départs</h2>
  <?php if (!$sessions): ?>
    <p class="bk-hint">Aucun départ de qualification n'est configuré pour cette compétition.
       Renseignez-les dans <b>Compétition › Départs</b> : le nombre de cibles et de places par
       cible en découle directement.</p>
  <?php else: ?>
    <table class="bk-t">
      <tr><th>Départ</th><th>Cibles</th><th>Places / cible</th><th>Total</th><th>Occupation</th></tr>
      <?php foreach ($sessions as $s):
        $pl = intval($s->Places); $pr = intval($s->Pris);
        $pc = $pl > 0 ? min(100, round($pr * 100 / $pl)) : 0; ?>
        <tr>
          <td><?= intval($s->SesOrder) ?><?= $s->SesName ? ' — ' . bk_e($s->SesName) : '' ?></td>
          <td><?= intval($s->SesTar4Session) ?></td>
          <td><?= intval($s->SesAth4Target) ?></td>
          <td><?= $pl ?></td>
          <td><span class="bk-gauge"><i style="width:<?= $pc ?>%"></i></span><?= $pr ?> / <?= $pl ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="bk-hint">Lu directement dans la configuration des départs de ianseo — rien à ressaisir ici.</p>
  <?php endif; ?>
  <p style="margin:10px 0 0">
    <a class="bk-btn" style="text-decoration:none;display:inline-block"
       href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/field.php">Contraintes d'affectation du terrain →</a>
    <span class="bk-hint" style="display:block;margin-top:6px">Déclarez les distances et les
      blasons que chaque cible peut recevoir : l'attribution automatique s'y conformera.</span>
  </p>
</div>

<div class="bk-sec">
  <h2>Contrôle du règlement</h2>
  <?php if (!$rules): ?>
    <p class="bk-hint" style="margin:0">Aucun départ avec des inscrits pour l'instant.</p>
  <?php else: ?>
    <p class="bk-hint" style="margin-top:0">Vérification à faire à la clôture des inscriptions (avant, le
       plateau bouge encore).</p>
    <table class="bk-t">
      <tr><th>Départ</th><th>Inscrits</th><th>Clubs</th><th>Règlement</th></tr>
      <?php foreach ($rules as $rc): ?>
        <tr>
          <td><?= intval($rc['depart']) ?><?= $rc['nom'] ? ' — ' . bk_e($rc['nom']) : '' ?></td>
          <td><?= intval($rc['archers']) ?></td>
          <td><?= intval($rc['clubs']) ?> / <?= intval($rc['minClubs']) ?> min</td>
          <td>
            <?php if ($rc['ok']): ?>
              <span style="color:#04ac0b;font-weight:600">✓ conforme</span>
            <?php else: ?>
              <span style="color:#c0392b;font-weight:600">À revoir</span>
              <ul class="bk-hint" style="margin:4px 0 0; padding-left:18px; color:#a80000">
                <?php if (!$rc['clubsOk']): ?><li>moins de <?= intval($rc['minClubs']) ?> clubs</li><?php endif; ?>
                <?php foreach ($rc['exces'] as $ex): ?><li>cible <?= intval($ex['cible']) ?> : <?= intval($ex['n']) ?> archers du club <?= bk_e($ex['club']) ?> (max <?= intval($rc['max']) ?>)</li><?php endforeach; ?>
                <?php if (intval($rc['nonPlaces']) > 0): ?><li><?= intval($rc['nonPlaces']) ?> archer(s) non placé(s)</li><?php endif; ?>
                <?php if (!empty($rc['doublons'])): ?><li>doublon(s) de licence sur le départ</li><?php endif; ?>
              </ul>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="bk-sec">
  <h2>Lien pour les archers</h2>
  <p style="font-size:13px;margin:0">Communiquez cette adresse à vos licenciés :<br>
     <span class="bk-url"><?= bk_e($publicUrl) ?></span></p>
</div>

<div id="bk-pill" hidden></div>
<?php endif; // fin niveau >= 2 ?>

</div>

<?php if ($level >= 2): ?>
<script>
/* Enregistrement automatique — le bouton « Enregistrer » disparaît quand JS est
   disponible, et chaque modification est écrite au fil de l'eau.
   Choix : on renvoie le formulaire ENTIER au même point d'entrée (même validation,
   même normalisation côté serveur) plutôt que d'inventer une API par champ ; seule
   la réponse change (JSON au lieu de la page). Sans JS, le bouton reste. */
(function () {
  var forms = [].slice.call(document.querySelectorAll('form[data-autosave]'));
  if (!forms.length || !window.fetch || !window.FormData) return;

  var pill = document.getElementById('bk-pill');
  var timer = null, enCours = false, aRefaire = null, sale = false;

  function etat(cls, txt) { if (!pill) return; pill.className = cls; pill.textContent = txt; pill.hidden = false; }
  function heure() { return new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }); }

  function envoyer(form) {
    if (enCours) { aRefaire = form; return; }      // une écriture à la fois, la dernière rejouée
    enCours = true;
    etat('wait', '⏳ Enregistrement…');
    var fd = new FormData(form);
    fd.append('ajax', '1');
    fetch(window.location.href, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (j && j.ok) { sale = false; etat('ok', '✓ Enregistré à ' + heure()); }
        else etat('err', '⚠ ' + ((j && j.msg) || 'Enregistrement refusé — rechargez la page.'));
      })
      .catch(function () { etat('err', '⚠ Enregistrement impossible (connexion ?). Vos dernières modifications ne sont pas écrites.'); })
      .then(function () {
        enCours = false;
        if (aRefaire) { var f = aRefaire; aRefaire = null; envoyer(f); }
      });
  }

  function planifier(form, delai) {
    sale = true;
    clearTimeout(timer);
    timer = setTimeout(function () { envoyer(form); }, delai);
  }

  forms.forEach(function (form) {
    // L'aperçu de tarif est bâti sur des champs SANS name (sim-*) : les manipuler ne
    // change rien d'enregistrable, inutile de déclencher une écriture.
    function utile(e) { return e.target && e.target.name && !e.target.disabled; }
    // Frappe au clavier : on laisse finir la saisie. Case/liste/date : immédiat.
    form.addEventListener('input',  function (e) { if (utile(e)) planifier(form, 900); });
    form.addEventListener('change', function (e) { if (utile(e)) planifier(form, 150); });
    form.addEventListener('submit', function (e) { e.preventDefault(); planifier(form, 0); });
    form.querySelectorAll('[data-manual-save]').forEach(function (b) { b.hidden = true; });
    // Après le formulaire, pas dedans : celui du tarif est une rangée flex.
    var note = document.createElement('p');
    note.className = 'bk-auto-note';
    note.textContent = 'Vos modifications sont enregistrées automatiquement.';
    if (form.parentNode) form.parentNode.insertBefore(note, form.nextSibling);
  });

  // Supprimer une règle de tarif retire des champs sans déclencher d'événement.
  // (La suppression elle-même a lieu dans l'autre écouteur, synchrone : le délai
  //  ci-dessous garantit que l'envoi part APRÈS.)
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('.bk-cat-del')) {
      var f = document.getElementById('bk-cfg');
      if (f) planifier(f, 200);
    }
  });

  // Quitter la page avec une modification non écrite : on prévient.
  window.addEventListener('beforeunload', function (e) {
    if (sale || enCours) { e.preventDefault(); e.returnValue = ''; }
  });
})();
</script>
<?php endif; ?>
<?php if ($level == 3): ?>
<script>
var bkCatN = <?= count($pricing['categories']) ?>;
function bkAddCat() {
  var html = document.getElementById('bk-cat-tpl').innerHTML.replace(/__i__/g, 'n' + (bkCatN++));
  var wrap = document.createElement('div'); wrap.innerHTML = html.trim();
  document.getElementById('bk-cat-list').appendChild(wrap.firstElementChild);
}
document.addEventListener('click', function (e) {
  var del = e.target.closest && e.target.closest('.bk-cat-del');
  if (del) { e.preventDefault(); var row = del.closest('.bk-cat-row'); if (row) row.remove(); }
});
</script>
<script>
/* Aperçu du tarif en direct : lit la configuration du formulaire et applique la
   même formule que le serveur (lib/pricing.php). */
(function () {
  // #bk-cfg et non « #bkadm form » : le PREMIER formulaire de la page est celui de
  // « Copier depuis… » — l'aperçu y lisait donc un tarif de base toujours vide.
  var form = document.getElementById('bk-cfg'); if (!form) return;
  function num(v) { v = parseFloat(String(v == null ? '' : v).replace(',', '.')); return isNaN(v) ? 0 : v; }
  function val(id) { var e = document.getElementById(id); return e ? e.value : ''; }
  function selVals(sel) { var a = []; if (!sel) return a; for (var i = 0; i < sel.options.length; i++) if (sel.options[i].selected) a.push(sel.options[i].value); return a; }
  function eur(n, signed) { var s = n < 0 ? '−' : (signed ? '+' : ''); return s + Math.abs(n).toFixed(2).replace('.', ',') + ' €'; }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]; }); }

  function readConfig() {
    var feeEl = form.elements['fee'];
    var cfg = { base: num(feeEl ? feeEl.value : 0), cats: [], deps: {}, prov: {}, rank: {} };
    Array.prototype.forEach.call(document.querySelectorAll('#bk-cat-list .bk-cat-row'), function (row) {
      var label = (row.querySelector('input[name$="[label]"]') || {}).value || '';
      var price = (row.querySelector('input[name$="[price]"]') || {}).value || '';
      var divSel = row.querySelector('select[name*="[div]"]');
      var clsSel = row.querySelector('select[name*="[cls]"]');
      if (price === '' && !selVals(divSel).length && !selVals(clsSel).length) return;
      cfg.cats.push({ label: label, div: selVals(divSel), cls: selVals(clsSel), price: num(price) });
    });
    Array.prototype.forEach.call(form.querySelectorAll('input[name^="dep["]'), function (inp) {
      var m = inp.name.match(/dep\[(\d+)\]/); if (!m) return; var v = num(inp.value); if (v !== 0) cfg.deps[m[1]] = v;
    });
    cfg.prov = { dept: num((form.elements['prov_dept'] || {}).value), region: num((form.elements['prov_region'] || {}).value) };
    [2, 3].forEach(function (t) { var el = form.elements['rank[' + t + ']']; var v = el ? num(el.value) : 0; if (v !== 0) cfg.rank[t] = v; });
    return cfg;
  }

  function simulate() {
    if (!document.getElementById('sim-total')) return;
    var cfg = readConfig();
    var div = val('sim-div'), cls = val('sim-cls'), ses = val('sim-ses'), prov = val('sim-prov'), rank = parseInt(val('sim-rank') || '1', 10);
    var base = cfg.base, label = 'Tarif de base';
    for (var i = 0; i < cfg.cats.length; i++) {
      var c = cfg.cats[i];
      var okD = !c.div.length || c.div.indexOf(div) >= 0, okC = !c.cls.length || c.cls.indexOf(cls) >= 0;
      if (okD && okC) { base = c.price; label = 'Tarif' + (c.label ? ' ' + c.label : ' catégorie'); break; }
    }
    var lines = [[label, base, false]], total = base;
    if (ses && ses !== '0' && cfg.deps[ses] !== undefined) { lines.push(['Départ ' + ses, cfg.deps[ses], true]); total += cfg.deps[ses]; }
    var pd = prov === 'dept' ? cfg.prov.dept : (prov === 'region' ? cfg.prov.region : 0);
    if (pd) { lines.push([prov === 'dept' ? 'Tarif départemental' : 'Tarif régional', pd, true]); total += pd; }
    var rd = 0, th = 0;
    for (var k in cfg.rank) { var kk = parseInt(k, 10); if (rank >= kk && kk > th) { th = kk; rd = cfg.rank[k]; } }
    if (rd) { lines.push([rank + 'ᵉ inscription', rd, true]); total += rd; }
    total = Math.max(0, total);
    var html = '';
    for (var j = 0; j < lines.length; j++) html += '<tr><td>' + esc(lines[j][0]) + '</td><td>' + eur(lines[j][1], lines[j][2]) + '</td></tr>';
    document.getElementById('sim-lines').innerHTML = html;
    document.getElementById('sim-total').textContent = eur(total, false);
  }
  form.addEventListener('input', simulate);
  form.addEventListener('change', simulate);
  simulate();
})();
</script>
<?php endif; // scripts du mode avancé ?>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
