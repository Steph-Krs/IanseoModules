<?php
/**
 * public/documents.php — documents d'une compétition, pour l'archer connecté.
 *
 * Regroupe les documents que l'organisateur a rendus consultables (mandat, lien
 * ianseo.net, et à terme programme/participants/résultats). Réservé aux archers
 * connectés (bk_require_archer) ; chaque document est par ailleurs borné par sa
 * propre garde (ex. bk_mandate_visible côté mandat).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';

$archer = bk_require_archer();

$tourId = intval($_GET['t'] ?? 0);
$cfg    = $tourId ? bk_comp_config($tourId) : null;
$tour   = $tourId ? safe_fetch(safe_r_sql("SELECT ToName, ToWhere, ToWhenFrom, ToWhenTo
    FROM Tournament WHERE ToId = " . intval($tourId))) : null;
$docs   = $cfg ? bk_docs_list($cfg, $tourId) : array();

bk_head('Documents');
?>
<h1>Documents de la compétition</h1>

<?php if ($tour): ?>
  <div class="bk-block" style="margin-bottom:16px">
    <h2><?= bk_e($tour->ToName) ?></h2>
    <p class="bk-meta">
      <span><?= bk_e(bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo)) ?></span>
      <?php if ($tour->ToWhere): ?><span><?= bk_e($tour->ToWhere) ?></span><?php endif; ?>
    </p>
  </div>
<?php endif; ?>

<?php if (!$docs): ?>
  <p class="bk-empty">Aucun document n'est disponible pour cette compétition.
     <a href="<?= bk_e(bk_public_url('calendar.php')) ?>">Retour au calendrier</a>.</p>
<?php else: ?>
  <div class="bk-doclist">
    <?php foreach ($docs as $d): ?>
      <a class="bk-doc" href="<?= bk_e($d['url']) ?>" target="_blank" rel="noopener">
        <span class="bk-doc-ic"><?= $d['icon'] ?></span>
        <span class="bk-doc-lab"><?= bk_e($d['label']) ?><?php if (!empty($d['external'])): ?>
          <span class="bk-doc-ext">↗ site externe</span><?php endif; ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php bk_foot(); ?>
