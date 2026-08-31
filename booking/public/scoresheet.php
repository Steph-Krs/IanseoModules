<?php
/**
 * public/scoresheet.php — feuille de marque individuelle, prête à imprimer.
 *
 * Le nombre de volées et de flèches vient de DistanceInformation, les distances
 * de TournamentDistances : la feuille suit donc exactement le format saisi par
 * l'organisateur, sans réglage propre au module.
 *
 * Ne s'affiche que si l'organisateur a activé l'option pour la compétition, et
 * seulement pour l'inscription de l'archer connecté.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/documents.php';

$archer = bk_require_archer();
$e = bk_doc_entry(intval($_GET['enid'] ?? 0));

if (!$e || bk_clean_licence($e->BrLicence) !== bk_clean_licence($archer->BaLicence)) {
    bk_head('Feuille de marque', 'card');
    echo '<div class="bk-card"><h1>Indisponible</h1>'
       . bk_msg('err', "Cette inscription n'est pas la vôtre.") . '</div>';
    bk_foot();
    exit;
}
if (empty($e->BcAllowScoresheet)) {
    bk_head('Feuille de marque', 'card');
    echo '<div class="bk-card"><h1>Indisponible</h1>'
       . bk_msg('err', "L'organisateur n'a pas activé l'impression des feuilles de marque.")
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">Mes inscriptions</a></p></div>';
    bk_foot();
    exit;
}

$rhythm = bk_doc_rhythm($e->ToId, $e->QuSession);
$dists  = bk_doc_distances($e->ToId, $e->ToType, $e->EnDivision, $e->EnClass);
$face   = bk_doc_face($e->ToId, $e->EnTargetFace);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Feuille de marque</title>
<link rel="stylesheet" href="<?= bk_e(bk_public_url('assets/bk.css')) ?>?v=<?= bk_e(bk_version()) ?>">
<link rel="stylesheet" href="<?= bk_e(bk_public_url('assets/print.css')) ?>?v=<?= bk_e(bk_version()) ?>">
</head>
<body>
<div id="bk" class="bk-doc">
  <p class="bk-noprint"><a href="<?= bk_e(bk_public_url('registrations.php')) ?>">← Mes inscriptions</a>
     &nbsp; <button onclick="window.print()" class="bk-btn bk-btn-primary">Imprimer</button></p>

  <header class="bk-doc-head">
    <h1>Feuille de marque</h1>
    <p class="bk-doc-comp"><b><?= bk_e($e->ToName) ?></b><br>
       <?= bk_e(bk_date_range($e->ToWhenFrom, $e->ToWhenTo)) ?>
       <?= $e->ToWhere ? ' — ' . bk_e($e->ToWhere) : '' ?></p>
  </header>

  <table class="bk-doc-id">
    <tr><th>Archer</th><td><?= bk_e($e->EnFirstName . ' ' . $e->EnName) ?></td>
        <th>Licence</th><td><?= bk_e($e->EnCode) ?></td></tr>
    <tr><th>Club</th><td><?= bk_e($e->CoName ?: $e->CoCode) ?></td>
        <th>Catégorie</th><td><?= bk_e(($e->DivDescription ?: $e->EnDivision) . ' — ' . ($e->ClDescription ?: $e->EnClass)) ?></td></tr>
    <tr><th>Départ</th><td><?= intval($e->QuSession) ?></td>
        <th>Cible</th><td><?= intval($e->QuTarget) > 0
            ? bk_e(intval($e->QuTarget) . $e->QuLetter) : 'non attribuée' ?></td></tr>
    <?php if ($dists || $face): ?>
    <tr><th>Distance</th>
        <td><?php
          $labels = array();
          foreach ($dists as $d) $labels[] = $d['label'] . ($d['metres'] ? ' (' . $d['metres'] . ' m)' : '');
          echo bk_e(implode(' · ', $labels) ?: '—'); ?></td>
        <th>Blason</th><td><?= bk_e($face ?: '—') ?></td></tr>
    <?php endif; ?>
  </table>

  <?php if (!$rhythm): ?>
    <p class="bk-empty">Le rythme de tir de ce départ n'est pas encore renseigné par l'organisateur.</p>
  <?php endif; ?>

  <?php foreach ($rhythm as $i => $r):
    $dLabel = $dists[$i]['label'] ?? ('Distance ' . $r['dist']); ?>
    <section class="bk-doc-dist">
      <h2><?= bk_e($dLabel) ?> — <?= $r['ends'] ?> volées de <?= $r['arrows'] ?> flèches</h2>
      <table class="bk-doc-grid">
        <tr>
          <th>Volée</th>
          <?php for ($a = 1; $a <= $r['arrows']; $a++): ?><th>F<?= $a ?></th><?php endfor; ?>
          <th>Volée</th><th>Cumul</th>
        </tr>
        <?php for ($v = 1; $v <= $r['ends']; $v++): ?>
          <tr>
            <td class="bk-doc-n"><?= $v ?></td>
            <?php for ($a = 1; $a <= $r['arrows']; $a++): ?><td></td><?php endfor; ?>
            <td></td><td></td>
          </tr>
        <?php endfor; ?>
        <tr class="bk-doc-tot">
          <td colspan="<?= $r['arrows'] + 1 ?>">Total <?= bk_e($dLabel) ?></td>
          <td></td><td></td>
        </tr>
      </table>
    </section>
  <?php endforeach; ?>

  <table class="bk-doc-sign">
    <tr><th>Signature de l'archer</th><th>Signature du marqueur</th><th>10 / 9</th><th>Total</th></tr>
    <tr><td class="bk-doc-box"></td><td class="bk-doc-box"></td><td class="bk-doc-box"></td><td class="bk-doc-box"></td></tr>
  </table>

  <p class="bk-doc-foot">Document généré depuis l'espace licencié. La feuille officielle remise
     sur le pas de tir fait foi.</p>
</div>
</body>
</html>
