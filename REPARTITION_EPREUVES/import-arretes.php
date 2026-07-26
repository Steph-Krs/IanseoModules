<?php
/**
 * import-arretes.php — écran 4 : import des arrêtés FFTA (fichiers Exalto).
 *
 * Assistant en une page : dépôt des fichiers (sélections individuelles + dépôts
 * d'équipes), consolidation multi-fichiers avec résolution de conflit,
 * construction des classements dérivés de l'arrêté (source de plus pour le plan
 * des départs), puis écriture dans ianseo — fichier à réimporter manuellement,
 * ou import direct (réutilise Partecipants/ListLoad.php, jamais destructif :
 * voir lib/arretes_ecriture.php).
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once __DIR__ . '/lib/boot.php';

$PAGE_TITLE = 'Import des arrêtés';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<link rel="stylesheet" href="<?= $REP_ROOT ?>assets/rep.css?v=<?= rep_version() ?>">
<link rel="stylesheet" href="<?= $REP_ROOT ?>assets/import.css?v=<?= rep_version() ?>">
<div id="rep">
  <h1>Import des arrêtés</h1>
  <p class="sous">
    Dépose les fichiers d'arrêté FFTA (Exalto) — sélections individuelles et dépôts
    d'équipes — pour créer en une fois les inscriptions de la compétition, coachs compris.
    Rien n'est écrit dans ianseo avant l'étape finale.
  </p>

  <details class="rep-aide">
    <summary>Comment ça marche</summary>
    <div class="rep-aide-corps">
      <ul>
        <li>Dépose un ou plusieurs fichiers : le format (individuel ou équipe) est détecté
            depuis l'en-tête, jamais deviné sur la position des colonnes.</li>
        <li>« Tout faire automatiquement » enchaîne en un clic la construction des classements,
            l'import direct dans ianseo et la propagation double mixte par club — le cas courant,
            sans rien à corriger. Nom, prénom et sexe divergents entre fichiers ne bloquent jamais
            (première valeur trouvée retenue automatiquement, sans incidence sur l'attribution) ;
            seule une divergence de division, classe ou club arrête le mode automatique, le temps
            de choisir la bonne valeur dans « Archers et coachs consolidés ».</li>
        <li>Un licencié présent à la fois comme archer et comme coach (capitaine) donne deux
            lignes distinctes — jamais fusionnées.</li>
        <li>Les archers présents uniquement dans un dépôt d'équipe reçoivent le drapeau équipe
            (ou double mixte) sans qualification individuelle ; un archer marqué « étranger »
            (HORS_F) n'a jamais la qualification individuelle.</li>
        <li>Les cartes « Archers et coachs consolidés », « Classements dérivés de l'arrêté »,
            « Écriture dans ianseo » et « Double mixte — propagation par club » sont repliées par
            défaut pour ne pas encombrer la vue — elles restent accessibles d'un clic sur leur
            titre, et pour intervenir à la main si besoin (le mode automatique fait exactement les
            mêmes actions que ces cartes, dans le même ordre).</li>
        <li>« Double mixte — propagation par club » : pour chaque club ayant une équipe double
            mixte, propose le drapeau double mixte à TOUS ses archers éligibles (classe/division
            acceptées par l'épreuve double mixte ianseo), pas seulement les 2 nommés dans le dépôt —
            pour que la composition définitive, validée plus tard dans ianseo, puisse choisir parmi
            tous les éligibles du club.</li>
      </ul>
      <p class="rep-aide-note">Les coachs demandent que la compétition ait déjà une division «
        OF » et une classe « COA » configurées (réglages de compétition ianseo) — sinon leur
        import est bloqué et signalé.</p>
    </div>
  </details>

  <div id="imp-app">Chargement…</div>
</div>

<script>
window.REP_IMPORT_CFG = {
  root:  <?= json_encode($REP_ROOT) ?>,
  jeton: <?= json_encode(rep_token()) ?>
};
</script>
<script src="<?= $REP_ROOT ?>assets/import.js?v=<?= rep_version() ?>"></script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
