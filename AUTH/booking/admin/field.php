<?php
/**
 * admin/field.php — plan du terrain : capacités de chaque cible.
 *
 * Édition graphique : palette de distances et de blasons à glisser sur les
 * cibles, sélection multiple pour appliquer d'un coup. Enregistrement en AJAX
 * (ajax-field.php) — aucune soumission de page pendant l'édition.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pTarget', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/caps.php';
require_once dirname(__DIR__) . '/lib/archer.php';   // bk_csrf_*
require_once dirname(__DIR__) . '/lib/ui.php';       // bk_e

bk_schema();

$TOUR = intval($_SESSION['TourId']);

$rs   = safe_r_sql("SELECT ToType FROM Tournament WHERE ToId = $TOUR");
$tRow = safe_fetch($rs);
$type = $tRow ? $tRow->ToType : '';

$sessions = bk_comp_sessions($TOUR);
$ses      = intval($_GET['s'] ?? 0);
if (!$ses && $sessions) $ses = intval($sessions[0]->SesOrder);

$sesRow = null;
foreach ($sessions as $s) if (intval($s->SesOrder) === $ses) $sesRow = $s;

$dists = bk_caps_distances($TOUR, $type);
$faces = bk_caps_faces($TOUR);
$caps  = $ses ? bk_caps_get($TOUR, $ses) : array();

// Parcours : les « blasons » sont des PIQUETS de couleur (Campagne/3D/Nature).
$hasPegs = false;
foreach ($faces as $f) if (!empty($f['peg'])) { $hasPegs = true; break; }

$targets = array();
if ($sesRow) {
    $first = intval($sesRow->SesFirstTarget) ?: 1;
    for ($i = 0; $i < intval($sesRow->SesTar4Session); $i++) $targets[] = $first + $i;
}

// Échelle de l'axe : les distances RÉELLEMENT utilisées par la compétition,
// espacées régulièrement. Un réglage au mètre près n'a aucun sens — une cible
// se pose à l'une des distances du règlement, pas entre deux.
$steps = array_keys($dists);
sort($steps);

$boot = array(
    'steps'    => $steps,
    'tour'     => $TOUR,
    'session'  => $ses,
    'targets'  => $targets,
    'caps'     => (object) $caps,
    'dists'    => array_values($dists),
    'faces'    => array_values($faces),
    'token'    => bk_csrf_token(),
    'ajax'     => $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/ajax-field.php',
    'sessions' => array_map(function ($s) { return intval($s->SesOrder); }, $sessions),
);

$PAGE_TITLE = 'Plan du terrain';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<link rel="stylesheet" href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/assets/field.css?v=<?= bk_e(bk_version()) ?>">

<div id="bkfield">
<h1>Plan du terrain</h1>
<p class="bkf-back"><a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/competition.php">← Inscriptions en ligne</a>
   &nbsp;·&nbsp; <a href="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/targets.php">Attribution des cibles →</a></p>

<?php if (!$sessions): ?>
  <p class="bkf-warn">Aucun départ de qualification n'est configuré. Renseignez-les dans
     <b>Compétition › Départs</b> : le nombre de cibles en découle.</p>
<?php elseif (!$steps && !$faces): ?>
  <p class="bkf-warn">Ni distances ni blasons exploitables ne sont définis sur cette compétition.
     Renseignez-les dans <b>Compétition › Distances</b> et <b>Compétition › Blasons</b>.</p>
<?php else: ?>

<?php if (!$steps): ?>
  <p class="bkf-warn">Cette compétition ne déclare aucune distance en mètres (parcours,
     distances non chiffrées) : seuls les blasons peuvent être réglés ici. L'attribution ne
     posera donc pas de contrainte de distance.</p>
<?php endif; ?>

  <div class="bkf-tabs">
    <?php foreach ($sessions as $s): $o = intval($s->SesOrder); ?>
      <a class="bkf-tab<?= $o === $ses ? ' bkf-tab-on' : '' ?>" href="?s=<?= $o ?>">
        Départ <?= $o ?><?= $s->SesName ? ' — ' . bk_e($s->SesName) : '' ?></a>
    <?php endforeach; ?>
  </div>

  <div class="bkf-wrap">
    <aside class="bkf-palette">
      <div class="bkf-pcol">
        <h2>Distances</h2>
        <?php if (!$dists): ?>
          <p class="bkf-none">Aucune distance définie sur cette compétition.</p>
        <?php else: ?>
          <div class="bkf-dform">
            <label>Mini
              <select id="bkf-min"><option value="">—</option>
                <?php foreach ($steps as $m): ?><option value="<?= $m ?>"><?= $m ?> m</option><?php endforeach; ?>
              </select></label>
            <label>Défaut
              <select id="bkf-def"><option value="">—</option>
                <?php foreach ($steps as $m): ?><option value="<?= $m ?>"><?= $m ?> m</option><?php endforeach; ?>
              </select></label>
            <label>Maxi
              <select id="bkf-max"><option value="">—</option>
                <?php foreach ($steps as $m): ?><option value="<?= $m ?>"><?= $m ?> m</option><?php endforeach; ?>
              </select></label>
            <button type="button" class="bkf-btn bkf-btn-go" data-act="applyd">Appliquer à la sélection</button>
          </div>
          <div class="bkf-quicks">
            <span class="bkf-quick">Fixer à :</span>
            <?php foreach ($steps as $m): ?>
              <button type="button" class="bkf-chip bkf-chip-d" data-quick="<?= $m ?>"><?= $m ?> m</button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="bkf-pcol">
        <h2><?= $hasPegs ? 'Piquets' : 'Blasons' ?> <span class="bkf-h2sub">(glisser sur une cible)</span></h2>
        <?php if (!$faces): ?><p class="bkf-none">Aucun <?= $hasPegs ? 'piquet' : 'blason' ?> défini.</p><?php endif; ?>
        <div class="bkf-chips">
          <?php foreach ($faces as $f): ?>
            <div class="bkf-chip bkf-chip-f" draggable="true" data-kind="f" data-val="<?= intval($f['id']) ?>">
              <?php if (!empty($f['peg'])): ?>
                <?= bk_piquet_svg($f['color'], 22) ?>
                <span class="bkf-chip-txt"><span class="bkf-chip-main"><?= bk_e($f['name']) ?></span></span>
              <?php else: ?>
                <img class="bkf-face-ic" src="<?= bk_e($CFG->ROOT_DIR . 'Common/Images/Targets/' . $f['svg']) ?>"
                     width="22" height="22" alt="" draggable="false">
                <span class="bkf-chip-txt">
                  <span class="bkf-chip-main"><?= bk_e($f['cm'] ? $f['cm'] . ' cm' : 'Blason') ?></span>
                  <?php if ($f['name'] !== ''): ?><span class="bkf-chip-sub"><?= bk_e($f['name']) ?></span><?php endif; ?>
                </span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bkf-pcol bkf-help">
        <p>Chaque cible est une <b>plage</b> : la barre va du mini au maxi, le repère plein
           marque la distance <b>par défaut</b>. Glissez une poignée pour la déplacer — elle
           s'aligne sur les distances de la compétition.</p>
        <p>Sélectionnez plusieurs cibles (clic-glissé, ou <kbd>Maj</kbd>+clic), puis réglez
           d'un coup ci-dessus.</p>
        <p>Une cible <b>sans réglage</b> n'est pas contrainte : elle accepte tout.</p>
      </div>
    </aside>

    <section class="bkf-field">
      <div class="bkf-toolbar">
        <span id="bkf-count" class="bkf-count">Aucune cible sélectionnée</span>
        <button type="button" class="bkf-btn" data-act="all">Tout sélectionner</button>
        <button type="button" class="bkf-btn" data-act="none">Désélectionner</button>
        <button type="button" class="bkf-btn" data-act="clearsel">Vider la sélection</button>
        <span class="bkf-sep"></span>
        <?php if (count($sessions) > 1): ?>
          <label class="bkf-copy">Reprendre depuis
            <select id="bkf-copyfrom">
              <option value="">— départ —</option>
              <?php foreach ($sessions as $s): $o = intval($s->SesOrder);
                if ($o === $ses) continue; ?>
                <option value="<?= $o ?>">Départ <?= $o ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="button" class="bkf-btn" data-act="copyfrom" title="Copier la configuration d'un autre départ sur ce départ">Reprendre</button>
          <span class="bkf-sep"></span>
          <label class="bkf-copy">Copier vers
            <select id="bkf-copyto">
              <option value="">— départ —</option>
              <?php foreach ($sessions as $s): $o = intval($s->SesOrder);
                if ($o === $ses) continue; ?>
                <option value="<?= $o ?>">Départ <?= $o ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="button" class="bkf-btn" data-act="copy" title="Copier la configuration de ce départ vers un autre">Copier</button>
        <?php endif; ?>
        <span class="bkf-sep"></span>
        <button type="button" class="bkf-btn bkf-btn-danger" data-act="clearall">Tout effacer</button>
        <span id="bkf-state" class="bkf-state"></span>
      </div>

      <div class="bkf-zoom">
        <label>Taille de l'affichage
          <input type="range" id="bkf-size" min="34" max="110" value="56" step="2"></label>
        <span class="bkf-hint-inline">Réduisez pour voir 50 à 70 cibles d'un coup.</span>
      </div>

      <div class="bkf-plot">
        <div id="bkf-axis" class="bkf-axis"></div>
        <div id="bkf-grid" class="bkf-grid"></div>
      </div>

      <p class="bkf-legend">Les cibles sans contrainte apparaissent en gris clair. L'attribution
         automatique ne placera un archer que sur une cible dont la plage couvre sa distance et
         qui accepte son blason, et ne mélangera jamais deux distances sur une même cible.</p>
    </section>
  </div>

<?php endif; ?>
</div>

<script>window.BKF = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/booking/admin/assets/field.js?v=<?= bk_e(bk_version()) ?>"></script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
