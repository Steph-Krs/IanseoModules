<?php
/**
 * index.php — écran 3 : plan des départs.
 *
 * Un bloc = une épreuve sur une plage de cibles × lettres contiguës, avec ses
 * quatre réglages. L'attribution se lit lettre d'abord (↓ ou ↑), cible ensuite
 * (→ ou ←). Rien n'est écrit dans ianseo avant le bouton « Appliquer », et le
 * panneau de contrôles le désactive tant qu'une erreur bloquante subsiste.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once __DIR__ . '/lib/boot.php';

$cfg     = rep_config_lire($REP_TOUR);
$departs = rep_departs($REP_TOUR);

$PAGE_TITLE = 'Plan des départs';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<link rel="stylesheet" href="<?= $REP_ROOT ?>assets/rep.css?v=<?= rep_version() ?>">
<div id="rep">
  <h1>Plan des départs</h1>
  <p class="sous">
    Saison <b><?= intval($cfg['annee']) ?></b> —
    <b><?= htmlspecialchars(rep_disc_lib($cfg['discipline'])) ?></b>
    (modifiable depuis <a href="classements.php">les classements</a>).
    Glisser le centre d'un bloc pour le déplacer, ses bords ou ses coins pour l'étirer.
  </p>

  <?php if (!$departs): ?>
    <div class="msg err on">
      Cette compétition ne déclare aucun départ de qualification exploitable
      (table <code>Session</code> : nombre de cibles à zéro). Configurez les départs
      dans ianseo avant d'utiliser cette page.
    </div>
  <?php else: ?>
    <div id="rep-editeur"></div>
  <?php endif; ?>
</div>

<script>
window.REP_CFG = {
  root:  <?= json_encode($REP_ROOT) ?>,
  jeton: <?= json_encode(rep_token()) ?>
};
</script>
<script src="<?= $REP_ROOT ?>assets/rep.js?v=<?= rep_version() ?>"></script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
