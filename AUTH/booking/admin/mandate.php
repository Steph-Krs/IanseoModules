<?php
/**
 * admin/mandate.php — générateur de mandat de compétition (organisateur).
 *
 * Deux rôles :
 *  1. Page de configuration (habillage ianseo) : template, couleur, logos à
 *     afficher, sections auto à masquer, blocs de texte libres.
 *  2. Aperçu imprimable (?print=1) : document HTML autonome mis aux couleurs
 *     choisies. Le rendu lui-même vit dans bk_mandate_document() (lib), mutualisé
 *     avec la vue publique consultée par les archers.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';
require_once dirname(__DIR__) . '/lib/archer.php';   // bk_csrf_*
require_once dirname(__DIR__) . '/lib/ui.php';       // bk_e, bk_date_*

bk_schema();

$TOUR = intval($_SESSION['TourId']);
$cfg  = bk_comp_config($TOUR);
$msg  = '';
$err  = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = 'Session expirée — rechargez la page et réessayez.';
    } elseif (isset($_POST['set_visible'])) {
        // Bascule visibilité par les archers (même effet que la case de « Ce que voient les
        // archers »). Tri-état BcShowMandate : on écrit explicitement 1 ou 0. PRG (rechargement).
        $v = (intval($_POST['set_visible']) === 1) ? 1 : 0;
        safe_w_sql("UPDATE BK_Competitions SET BcShowMandate = $v WHERE BcTournament = $TOUR");
        header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/mandate.php'
            . ($v ? '?vis=1' : '?vis=0'));
        exit;
    } else {
        bk_mandate_save($TOUR, bk_mandate_from_post($_POST));
        $cfg = bk_comp_config($TOUR);
        $msg = 'Mandat enregistré.';
    }
}
if (isset($_GET['vis'])) $msg = $_GET['vis'] === '1'
    ? 'Le mandat est désormais visible par les archers.'
    : 'Le mandat est désormais masqué pour les archers.';

$m    = bk_mandate_get($cfg);
$data = bk_mandate_data($TOUR);

// Visibilité par les archers (tri-état BcShowMandate ; jamais visible sans mandat).
$hasMandate     = trim((string) ($cfg->BcMandate ?? '')) !== '';
$mandateVisible = bk_mandate_visible($cfg);

/* ================================================================== */
/* Aperçu imprimable — délègue au rendu mutualisé (lib)               */
/* ================================================================== */
if (!empty($_GET['print']) && $data) {
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $abs    = ($_SERVER['HTTP_HOST'] ?? '') ? $scheme . '://' . $_SERVER['HTTP_HOST'] : '';
    bk_mandate_document($data, $m, array(
        // Organisateur : session ianseo ouverte → logos via TourLogo.php.
        'logo'    => function ($type, $w) use ($CFG) {
            return $CFG->ROOT_DIR . 'Common/TourLogo.php?Type=' . $type . '&W=' . intval($w);
        },
        'regUrl'  => $abs . bk_public_url('competition.php?t=' . $TOUR),
        'shopUrl' => $abs . bk_public_url('shop.php?t=' . $TOUR),
        'toolbar' => '<button type="button" class="mn-print" onclick="window.print()">Imprimer / enregistrer en PDF</button>'
                   . '<a class="mn-close" href="' . bk_e($CFG->ROOT_DIR) . 'Modules/Custom/AUTH/booking/admin/mandate.php">Retour à la configuration</a>',
    ));
    exit;
}

/* ================================================================== */
/* Page de configuration                                              */
/* ================================================================== */
$PAGE_TITLE = 'Mandat de compétition';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#bkadm .bk-sec { background:#fff; border:1px solid #d2d4d6; border-radius:6px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:14px 16px; margin:0 0 14px; }
#bkadm .bk-sec h2 { margin:0 0 10px; font-size:15px; color:#0254a8; }
#bkadm .bk-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px; }
#bkadm .bk-ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkadm .bk-err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#bkadm label { display:block; font-size:13px; font-weight:600; color:#01367c; margin:10px 0 3px; }
#bkadm select, #bkadm textarea, #bkadm input[type=text] { width:100%; max-width:520px; padding:7px 9px;
    border:1px solid #cfd3d6; border-radius:6px; font:inherit; font-size:14px; }
#bkadm textarea { min-height:60px; resize:vertical; }
#bkadm .bk-check { display:flex; align-items:center; gap:8px; font-weight:400; margin:6px 0; }
#bkadm .bk-check input { width:auto; }
#bkadm .bk-hint { font-size:12px; color:#7d8183; margin:4px 0 0; }
#bkadm .bk-btn { padding:8px 16px; border:1px solid #d2d4d6; border-radius:6px;
    background:#f7f7f7; color:#20263d; font-size:14px; cursor:pointer; text-decoration:none;
    display:inline-block; }
#bkadm .bk-btn-primary { background:#0254a8; border-color:#0254a8; color:#fff; font-weight:600; }
#bkadm .bk-cols { display:flex; flex-wrap:wrap; gap:6px 24px; }
#bkadm .bk-two { display:flex; flex-wrap:wrap; gap:2px 28px; }
#bkadm .bk-two > div { flex:1 1 240px; min-width:0; }
#bkadm .bk-color-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
#bkadm input[type=color] { width:48px; height:34px; padding:0; border:1px solid #cfd3d6; border-radius:6px; cursor:pointer; }
#bkadm .bk-swatch { width:26px; height:26px; border-radius:6px; border:1px solid rgba(0,0,0,.15); cursor:pointer; }
#bkadm .bk-disabled { color:#a2a6a9; }
#bkadm .bk-vis { border-left:4px solid #cfd3d6; }
#bkadm .bk-vis.on  { border-left-color:#1a8a3f; background:#f2fbf4; }
#bkadm .bk-vis.off { border-left-color:#cb8137; background:#fdf7ee; }
#bkadm .bk-vis.on  .bk-vis-state { color:#1a8a3f; }
#bkadm .bk-vis.off .bk-vis-state { color:#c0392b; }
</style>

<div id="bkadm">
<h1>Mandat de compétition</h1>
<p style="font-size:13px"><a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/competition.php">← Inscriptions en ligne</a></p>

<?php if ($msg): ?><div class="bk-msg bk-ok"><?= bk_e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="bk-msg bk-err"><?= bk_e($err) ?></div><?php endif; ?>

<div class="bk-sec bk-vis <?= $mandateVisible ? 'on' : 'off' ?>">
  <h2 style="margin-bottom:6px">Visibilité par les archers</h2>
  <?php if (!$hasMandate): ?>
    <p style="margin:0;font-size:13px">Le mandat n'est pas encore enregistré. Configurez-le ci-dessous puis
       cliquez <b>Enregistrer</b> : vous pourrez alors le rendre visible par les archers.</p>
  <?php else: ?>
    <p style="margin:0 0 8px;font-size:14px">
      État actuel :
      <b class="bk-vis-state"><?= $mandateVisible ? '👁️ visible par les archers' : '🚫 masqué (invisible pour les archers)' ?></b>
      <span class="bk-hint" style="display:inline">— fiche compétition du calendrier + « Mes inscriptions »</span>
    </p>
    <form method="post" style="margin:0">
      <?= bk_csrf_field() ?>
      <input type="hidden" name="set_visible" value="<?= $mandateVisible ? '0' : '1' ?>">
      <button type="submit" class="bk-btn <?= $mandateVisible ? '' : 'bk-btn-primary' ?>">
        <?= $mandateVisible ? 'Masquer le mandat' : 'Rendre le mandat visible' ?></button>
    </form>
  <?php endif; ?>
  <p class="bk-hint" style="margin-top:8px">Ce réglage est le même que la case
     « Rendre le mandat consultable » de <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/competition.php">« Ce que voient les archers »</a>.</p>
</div>

<p style="font-size:13px; color:#4c4e50; max-width:640px">Le mandat est rempli automatiquement depuis la
compétition (nom, dates, lieu, départs, catégories, tarifs, moyens de paiement). Choisissez un modèle et
une couleur, cochez les sections à afficher, complétez les blocs de texte utiles, puis
<b>Enregistrer</b>. « Aperçu / imprimer » ouvre le document prêt à imprimer (ou à enregistrer en PDF).</p>

<form method="post">
<?= bk_csrf_field() ?>

<div class="bk-sec">
  <h2>Modèles et couleur</h2>
  <div class="bk-two">
    <div>
      <label for="template">Modèle du mandat</label>
      <select id="template" name="template">
        <?php foreach (bk_mandate_templates() as $k => $lab): ?>
          <option value="<?= bk_e($k) ?>" <?= $m['template'] === $k ? 'selected' : '' ?>><?= bk_e($lab) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="bk-hint">Mise en page du document imprimable.</p>
    </div>
    <div>
      <label for="share_template">Modèle du visuel de partage</label>
      <select id="share_template" name="share_template">
        <?php foreach (bk_share_templates() as $k => $lab): ?>
          <option value="<?= bk_e($k) ?>" <?= $m['share_template'] === $k ? 'selected' : '' ?>><?= bk_e($lab) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="bk-hint">Image « J'y serai » que les inscrits partagent sur les réseaux.</p>
    </div>
  </div>

  <label for="color">Couleur principale (commune au mandat et au visuel)</label>
  <div class="bk-color-row">
    <input type="color" id="color" name="color" value="<?= bk_e($m['color']) ?>">
    <input type="text" id="colorhex" value="<?= bk_e($m['color']) ?>" maxlength="7"
           style="width:110px; font-family:monospace" aria-label="Code couleur (hexadécimal)">
    <span style="font-size:12px; color:#7d8183">choix libre ↑</span>
    <?php foreach (array('#0254a8','#c0392b','#1a8a3f','#7d3c98','#d35400','#00838f','#e67e22','#20263d') as $sw): ?>
      <span class="bk-swatch" style="background:<?= bk_e($sw) ?>" data-c="<?= bk_e($sw) ?>" title="<?= bk_e($sw) ?>"></span>
    <?php endforeach; ?>
  </div>
  <p class="bk-hint">Choisissez une couleur (pastilles), une couleur personnalisée (sélecteur), ou saisissez
     son code hexadécimal. Les nuances (fonds clairs, titres) en sont dérivées automatiquement.</p>
  <script>
  (function () {
    var col = document.getElementById('color'), hex = document.getElementById('colorhex');
    function norm(v){ v = (v || '').trim(); if (v && v[0] !== '#') v = '#' + v; return v; }
    col.addEventListener('input', function () { hex.value = col.value; });
    hex.addEventListener('input', function () { var v = norm(hex.value); if (/^#[0-9a-fA-F]{6}$/.test(v)) col.value = v; });
    hex.addEventListener('blur', function () { hex.value = col.value; });
    Array.prototype.forEach.call(document.querySelectorAll('.bk-swatch'), function (s) {
      s.addEventListener('click', function () { col.value = s.getAttribute('data-c'); hex.value = col.value; });
    });
  })();
  </script>
</div>

<div class="bk-sec">
  <h2>Logos de la compétition</h2>
  <p class="bk-hint" style="margin-top:0">Ce sont les images déjà téléversées dans ianseo
     (<a href="<?= $CFG->ROOT_DIR ?>Tournament/ManLogo.php">Compétition › Logos</a>). Décochez pour ne pas les afficher.</p>
  <?php
  $logoLabels = array('L' => 'Logo haut-gauche', 'R' => 'Logo haut-droit', 'B' => 'Image du bas');
  $has = $data
      ? array('L' => intval($data['tour']->HasL), 'R' => intval($data['tour']->HasR), 'B' => intval($data['tour']->HasB))
      : array('L' => 0, 'R' => 0, 'B' => 0);
  foreach ($logoLabels as $k => $lab): $present = $has[$k] > 0; ?>
    <label class="bk-check <?= $present ? '' : 'bk-disabled' ?>">
      <input type="checkbox" name="logo_<?= $k ?>" value="1" <?= (!empty($m['logos'][$k]) && $present) ? 'checked' : '' ?> <?= $present ? '' : 'disabled' ?>>
      <?= bk_e($lab) ?><?= $present ? '' : ' — aucune image téléversée' ?>
    </label>
  <?php endforeach; ?>
</div>

<div class="bk-sec">
  <h2>Sections automatiques à afficher</h2>
  <div class="bk-cols">
    <?php foreach (bk_mandate_auto_sections() as $k => $lab): ?>
      <label class="bk-check">
        <input type="checkbox" name="show_<?= bk_e($k) ?>" value="1" <?= !empty($m['show'][$k]) ? 'checked' : '' ?>>
        <?= bk_e($lab) ?>
      </label>
    <?php endforeach; ?>
  </div>
</div>

<div class="bk-sec">
  <h2>Blocs de texte libres</h2>
  <p class="bk-hint" style="margin-top:0">Laissez vide un bloc pour ne pas l'afficher.</p>
  <?php foreach (bk_mandate_sections() as $k => $lab): ?>
    <label for="block_<?= bk_e($k) ?>"><?= bk_e($lab) ?></label>
    <textarea id="block_<?= bk_e($k) ?>" name="block_<?= bk_e($k) ?>" maxlength="4000"><?= bk_e($m['blocks'][$k] ?? '') ?></textarea>
  <?php endforeach; ?>
</div>

<p>
  <button type="submit" class="bk-btn bk-btn-primary">Enregistrer</button>
  &nbsp;
  <a class="bk-btn" href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/mandate.php?print=1" target="_blank" rel="noopener">Aperçu / imprimer ↗</a>
</p>
</form>
</div>

<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
