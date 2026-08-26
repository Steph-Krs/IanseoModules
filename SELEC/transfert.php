<?php
/**
 * transfert.php — emporter une sélection d'un serveur à l'autre.
 *
 * Complément à l'export natif de ianseo (`.ianseo`), qui n'emporte aucune table
 * de module : voir lib/transfert.php pour le détail de ce qui serait perdu.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/transfert.php';

$cfg = selec_config_lire($SELEC_TOUR);
$tour = selec_tournoi($SELEC_TOUR);

// ── Téléchargement ──────────────────────────────────────────────────────────
if (!empty($_GET['export'])) {
    $contenu = selec_transfert_export($SELEC_TOUR);
    $nom = 'SELEC-' . preg_replace('/[^A-Za-z0-9_-]/', '', $tour ? $tour['code'] : 'export') . '.selec';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    echo selec_transfert_fichier($contenu);
    selec_log($SELEC_TOUR, 'export', array('fichier' => $nom));
    exit;
}

// ── Analyse / import ────────────────────────────────────────────────────────
$analyse = null; $resultat = null; $erreur = '';
if (!empty($_POST['action']) && !empty($_FILES['fichier']['tmp_name'])) {
    if (!hash_equals(selec_token(), (string) ($_POST['jeton'] ?? ''))) {
        $erreur = 'Jeton de session invalide — rechargez la page.';
    } else {
        $brut = (string) @file_get_contents($_FILES['fichier']['tmp_name']);
        $data = selec_transfert_lire($brut);
        if (!$data) {
            $erreur = 'Fichier illisible : ce n\'est pas un fichier .selec.';
        } else {
            $analyse = selec_transfert_analyse($SELEC_TOUR, $data);
            if ($_POST['action'] === 'importer' && $analyse['ok']) {
                if (IsBlocked(BIT_BLOCK_TOURDATA)) {
                    $erreur = 'Compétition verrouillée par ianseo.';
                } else {
                    $resultat = selec_transfert_importer($SELEC_TOUR, $data);
                    $cfg = selec_config_lire($SELEC_TOUR);
                }
            }
        }
    }
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$PAGE_TITLE = 'Sélection — transfert';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<link rel="stylesheet" href="<?= $SELEC_ROOT ?>assets/selec.css?v=<?= selec_version() ?>">
<div id="selec">
  <h1>Sélection Équipe de France — transfert entre serveurs</h1>
  <p class="sous">Une compétition se déplace en <b>deux fichiers</b> : celui de ianseo, qui emporte
     les scores, les épreuves, les cibles et les horaires ; et celui-ci, qui emporte ce que le
     module ajoute. L'export de ianseo ne connaît pas les tables de module — sa liste est fixe —
     donc sans ce second fichier, le règlement figé, les classements et surtout
     <b>les archives des étapes verrouillées</b> resteraient sur le serveur d'origine.</p>

  <div class="carte">
    <h2><span>1. Emporter cette compétition</span><span class="sep"></span>
      <span class="sub"><?= h($tour ? $tour['code'] : '') ?></span></h2>
    <div class="corps">
      <p class="sous" style="margin:0 0 8px">Dans cet ordre, et depuis la compétition ouverte :</p>
      <ol class="sous" style="margin:0 0 10px 18px">
        <li><b>Export ianseo</b> — <a href="<?= $CFG->ROOT_DIR ?>Tournament/TournamentExport.php?Complete=1">
            télécharger le fichier <code>.ianseo</code></a> (cochez « complet »). Il contient les
            scores, les épreuves, les grilles, les cibles et les horaires.</li>
        <li><b>Export du module</b> — le fichier <code>.selec</code> ci-dessous.</li>
      </ol>
      <p style="margin:0">
        <a class="btn primaire" href="?export=1">Télécharger le fichier .selec</a>
      </p>
      <?php
      $ap = selec_transfert_export($SELEC_TOUR);
      ?>
      <p class="sous" style="margin:8px 0 0">Contenu : <?= count($ap['archers']) ?> archers,
         <?= count($ap['binds']) ?> rattachements, <?= count($ap['results']) ?> lignes de classement,
         <b><?= count($ap['archive']) ?> archives d'étape verrouillée</b>,
         <?= count($ap['shootoff']) ?> barrages, <?= count($ap['log']) ?> lignes de journal.
         <?php if (!$ap['config']): ?><br><span class="tie">Cette compétition n'est rattachée à
         aucun mode : le fichier ne portera pas de règlement.</span><?php endif; ?></p>
    </div>
  </div>

  <div class="carte">
    <h2><span>2. Reprendre une compétition ici</span></h2>
    <div class="corps">
      <p class="sous" style="margin:0 0 8px">Sur le serveur d'arrivée : importez d'abord le fichier
         <code>.ianseo</code> par
         <a href="<?= $CFG->ROOT_DIR ?>Tournament/TournamentImport.php">l'import de ianseo</a>,
         ouvrez la compétition ainsi créée, puis revenez ici charger le <code>.selec</code>.</p>
      <p class="sous" style="margin:0 0 8px">Les archers sont rapprochés par <b>licence + division +
         classe</b>, jamais par identifiant interne : celui-ci ne survit pas forcément à un import.
         Ce qui ne retombe pas sur exactement un archer est signalé, jamais deviné.</p>

      <?php if ($erreur): ?><div class="alerte"><?= h($erreur) ?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="jeton" value="<?= h(selec_token()) ?>">
        <p><input type="file" name="fichier" accept=".selec,.json,.gz" required>
           <button class="btn" type="submit" name="action" value="analyser">Analyser le fichier</button></p>

        <?php if ($analyse): ?>
          <?php foreach ($analyse['erreurs'] as $e): ?>
            <div class="alerte"><?= h($e) ?></div>
          <?php endforeach; ?>
          <?php foreach ($analyse['alertes'] as $a): ?>
            <div class="alerte"><?= h($a) ?></div>
          <?php endforeach; ?>

          <?php if ($analyse['ok']): ?>
            <div class="info">
              <b><?= intval($analyse['trouves']) ?> archers</b> retrouvés dans cette compétition.
              À reprendre : <?= intval($analyse['compte']['binds']) ?> rattachements,
              <?= intval($analyse['compte']['results']) ?> lignes de classement,
              <b><?= intval($analyse['compte']['archive']) ?> archives</b>,
              <?= intval($analyse['compte']['shootoff']) ?> barrages,
              <?= intval($analyse['compte']['log']) ?> lignes de journal.
              <?php if (!empty($analyse['config'])): ?><br>Règlement du fichier :
                <b><?= h($analyse['config']['mode']) ?></b> version
                <?= h($analyse['config']['version']) ?>, figé le <?= h($analyse['config']['date']) ?>.
              <?php endif; ?>
            </div>

            <?php if ($analyse['manquants']): ?>
              <details class="carte" style="box-shadow:none">
                <summary style="background:#fdf0ef;color:#c0392b;border-bottom:1px solid #e8b4ae">
                  <?= count($analyse['manquants']) ?> archer(s) introuvables ici</summary>
                <div class="corps"><ul class="sous" style="margin:0 0 0 16px">
                  <?php foreach (array_slice($analyse['manquants'], 0, 60) as $m): ?>
                    <li><?= h($m) ?></li>
                  <?php endforeach; ?>
                </ul></div>
              </details>
            <?php endif; ?>
            <?php if ($analyse['ambigus']): ?>
              <details class="carte" style="box-shadow:none">
                <summary style="background:#fdf7ef;color:#a45c10;border-bottom:1px solid #cb8137">
                  <?= count($analyse['ambigus']) ?> référence(s) ambiguë(s)</summary>
                <div class="corps"><ul class="sous" style="margin:0 0 0 16px">
                  <?php foreach ($analyse['ambigus'] as $m): ?><li><?= h($m) ?></li><?php endforeach; ?>
                </ul></div>
              </details>
            <?php endif; ?>

            <p class="alerte" style="margin-top:10px">L'import <b>remplace</b> toutes les données du
               module pour cette compétition : règlement figé, rattachements, classements, archives,
               barrages et journal. Les scores de ianseo ne sont pas touchés.</p>
            <p><button class="btn primaire" type="submit" name="action" value="importer"
                       onclick="return confirm('Remplacer les données du module pour cette compétition ?')">
               Importer dans cette compétition</button>
               <span class="tie">(recharger le fichier, il n'est pas conservé entre deux étapes)</span></p>
          <?php endif; ?>
        <?php endif; ?>
      </form>

      <?php if ($resultat): ?>
        <?php if ($resultat['ok']): ?>
          <div class="info"><b>Import terminé.</b><br>
            <?= implode('<br>', array_map('h', $resultat['faits'])) ?></div>
        <?php else: ?>
          <div class="alerte"><?= implode('<br>', array_map('h', $resultat['erreurs'])) ?></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="carte">
    <h2><span>État actuel de cette compétition</span></h2>
    <div class="corps">
      <?php if ($cfg && $cfg['snapshot']): ?>
        <p><span class="pill p-ok">rattachée</span> <b><?= h($cfg['snapshot']['libelle']) ?></b>
           — version <?= h($cfg['version']) ?>, figée le <?= h($cfg['date']) ?>.</p>
      <?php else: ?>
        <p><span class="pill p-nb">non rattachée</span> Aucun mode de sélection.</p>
      <?php endif; ?>
      <p><a class="btn" href="<?= $SELEC_ROOT ?>index.php">Configuration</a>
         <a class="btn" href="<?= $SELEC_ROOT ?>classement.php">Classements</a></p>
    </div>
  </div>
</div>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
