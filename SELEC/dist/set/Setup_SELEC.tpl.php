<?php
/**
 * Setup du type « Sélection » — set SELEC.
 *
 * Déployé sous le nom Setup_<idDuType>_SELEC.php par l'auto-réparation du module
 * Custom/SELEC (ianseo compose le nom du fichier avec l'identifiant numérique du
 * type). La copie de référence est Modules/Custom/SELEC/dist/set/Setup_SELEC.tpl.php.
 *
 * Variables fournies par GetSetupFile() : $TourId, $ToType, $Lang, $SubRule,
 * $subRuleName. $SubRule vaut 1 pour la 1re sous-règle de sets.php, 2 pour la 2e.
 *
 * Ce que fait ce fichier :
 *   - déclare une compétition à 8 distances de 36 flèches (6 volées de 6), soit
 *     le maximum de ianseo — 4 qualifications de 2×36 tiennent exactement dedans ;
 *   - reprend les divisions, classes et blasons du set FR (mêmes catégories
 *     fédérales), sans les redéfinir ;
 *   - ne crée AUCUNE épreuve : c'est le module SELEC qui les génère à partir du
 *     mode de sélection retenu, avec les tournois et leurs consolantes.
 */

$TourType = intval($ToType);

$tourDetTypeName        = 'Type_FR_Selection';
$tourDetNumEnds         = '48';   // 8 distances × 6 volées
$tourDetMaxDistScore    = '360';  // 36 flèches × 10
$tourDetMaxFinIndScore  = '150';  // duel en sets : 5 volées de 3 flèches
$tourDetMaxFinTeamScore = '240';
$tourDetCategory        = '1';    // extérieur
$tourDetElabTeam        = '0';
$tourDetElimination     = '0';
$tourDetGolds           = '10+X';
$tourDetXNine           = 'X';
$tourDetGoldsChars      = 'KL';
$tourDetXNineChars      = 'K';
$tourDetDouble          = '0';
$tourDetIocCode         = 'FRA';

// Nombre de distances selon l'épreuve de sélection :
//  - épreuve 1 : 4 qualifications de 2×36 → 8 distances (le maximum de ianseo) ;
//  - épreuve 2 : 3 qualifications de 2×36 → 6 distances.
$SelecNbQual  = ($SubRule == 2) ? 3 : 4;
$tourDetNumDist = (string) ($SelecNbQual * 2);

$DistanceInfoArray = array();
for ($i = 0; $i < $SelecNbQual * 2; $i++) $DistanceInfoArray[] = array(6, 6);

// ── Divisions, classes et blasons : ceux du set FR ──────────────────────────
// Une sélection nationale utilise les catégories fédérales. Plutôt que de les
// recopier (et de les voir diverger à la première évolution FFTA), on réutilise
// la bibliothèque du set FR quand elle est présente.
require_once(dirname(dirname(__FILE__)) . '/lib.php');   // Modules/Sets/lib.php
$SelecFrLib = dirname(dirname(__FILE__)) . '/FR/lib.php';
if (is_file($SelecFrLib)) {
    require_once($SelecFrLib);
    // Sous-règle 13 du set FR = « sélectif » : divisions et classes complètes,
    // arc classique compris, sans les spécificités para.
    CreateStandardDivisions($TourId, 3, 13);
    CreateStandardClasses($TourId, 3, 13);
} else {
    // Repli minimal si le set FR n'est pas installé : arc classique seul.
    CreateDivision($TourId, 1, 'CL', 'Arc Classique', '1', 'R', 'R');
    $SelecClasses = array(
        array('S1H', 'Senior 1 Homme', 0), array('S1F', 'Senior 1 Femme', 1),
        array('S2H', 'Senior 2 Homme', 0), array('S2F', 'Senior 2 Femme', 1),
        array('S3H', 'Senior 3 Homme', 0), array('S3F', 'Senior 3 Femme', 1),
        array('U21H', 'U21 Homme', 0),     array('U21F', 'U21 Femme', 1),
    );
    $o = 1;
    foreach ($SelecClasses as $c) {
        CreateClass($TourId, $o++, 1900, 2100, $c[2], $c[0], '1', $c[1], '1', 'CL');
    }
    unset($SelecClasses, $o);
}
unset($SelecFrLib);

// ── Distances ───────────────────────────────────────────────────────────────
// Toutes les catégories tirent le même nombre de séries ; seule la distance
// physique change. Les libellés « 70m-1 … 70m-8 » correspondent aux 8 séries de
// 36 flèches, que le module répartit ensuite en 4 qualifications de 2 séries.
$SelecDist = array(
    'CL%' => 70,
    'CO%' => 50,
    'BB%' => 50,
);
foreach ($SelecDist as $SelecFiltre => $SelecMetres) {
    $SelecSeries = array();
    for ($i = 1; $i <= $SelecNbQual * 2; $i++) {
        $SelecSeries[] = array($SelecMetres . 'm-' . $i, $SelecMetres);
    }
    CreateDistanceNew($TourId, $TourType, $SelecFiltre, $SelecSeries);
}
unset($SelecDist, $SelecFiltre, $SelecMetres, $SelecSeries, $i);

// ── Blasons ─────────────────────────────────────────────────────────────────
// Le blason doit être renseigné sur CHAQUE série, pas seulement sur les deux
// premières : `Tournament/ManTargets.php` affiche une colonne par distance
// jusqu'à ToNumDist, et une série laissée à 0 n'a pas de blason du tout.
// CreateTargetFace() prend les couples (type, diamètre) en arguments positionnels
// T1/W1 … T8/W8 : on répète donc le même couple autant de fois qu'il y a de séries.
function selec_setup_blason($TourId, $Id, $Nom, $Classes, $Type, $Diametre, $NbSeries)
{
    $args = array($TourId, $Id, $Nom, $Classes, '1');
    for ($i = 0; $i < $NbSeries; $i++) { $args[] = $Type; $args[] = $Diametre; }
    call_user_func_array('CreateTargetFace', $args);
}

$SelecNbSeries = $SelecNbQual * 2;
selec_setup_blason($TourId, 1, 'Blason Classique 122', 'CL%', 5, 122, $SelecNbSeries);
selec_setup_blason($TourId, 2, 'Blason Poulies 80',    'CO%', 9,  80, $SelecNbSeries);
selec_setup_blason($TourId, 3, 'Blason Arc Nu 122',    'BB%', 5, 122, $SelecNbSeries);

// ── Une première session, avec toutes ses distances ─────────────────────────
// Le module SELEC réorganise ensuite : une session par qualification, portant
// seulement SES deux séries (session 1 → distances 1-2, session 2 → 3-4, etc.).
CreateDistanceInformation($TourId, $DistanceInfoArray, 24, 4);

// ── Enregistrement des caractéristiques de la compétition ───────────────────
UpdateTourDetails($TourId, array(
    'ToCollation'        => isset($tourCollation) ? $tourCollation : 'utf8mb4_unicode_ci',
    'ToTypeName'         => $tourDetTypeName,
    'ToNumDist'          => $tourDetNumDist,
    'ToNumEnds'          => $tourDetNumEnds,
    'ToMaxDistScore'     => $tourDetMaxDistScore,
    'ToMaxFinIndScore'   => $tourDetMaxFinIndScore,
    'ToMaxFinTeamScore'  => $tourDetMaxFinTeamScore,
    'ToCategory'         => $tourDetCategory,
    'ToElabTeam'         => $tourDetElabTeam,
    'ToElimination'      => $tourDetElimination,
    'ToGolds'            => $tourDetGolds,
    'ToXNine'            => $tourDetXNine,
    'ToGoldsChars'       => $tourDetGoldsChars,
    'ToXNineChars'       => $tourDetXNineChars,
    'ToDouble'           => $tourDetDouble,
    'ToIocCode'          => $tourDetIocCode,
));
