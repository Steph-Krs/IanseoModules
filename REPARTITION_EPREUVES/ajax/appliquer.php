<?php
/**
 * ajax/appliquer.php — aperçu puis écriture de l'attribution.
 *
 * L'aperçu ne touche à rien. L'écriture passe par lib/ecriture.php, seul endroit
 * du module qui écrit dans Qualifications.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once dirname(__DIR__) . '/lib/boot.php';
rep_check_token();

$mode     = $_POST['mode'] ?? 'apercu';
$forcer   = !empty($_POST['forcer']);
$sessions = trim((string) ($_POST['sessions'] ?? ''));
$sessions = $sessions === '' ? [] : array_filter(array_map('intval', explode(',', $sessions)));

$res = rep_appliquer($REP_TOUR, $sessions, $mode !== 'ecrire', $forcer);

// L'aperçu peut peser plusieurs centaines de lignes : on renvoie de quoi
// contrôler à la main, pas plus.
$lignes = [];
foreach ($res['lignes'] as $l) {
    $lignes[] = [
        'nom'     => $l['nom'],
        'licence' => $l['licence'],
        'club'    => $l['club'],
        'rang'    => $l['rang'],
        'epreuve' => $l['division'] . ' ' . $l['class'],
        'cible'   => rep_target_no($l['session'], $l['target'], $l['letter']),
    ];
}
$res['lignes'] = $lignes;

JsonOut($res);
