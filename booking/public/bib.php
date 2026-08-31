<?php
/**
 * public/bib.php — impression du/des DOSSARD(s) (badge Qualification), pour l'archer
 * connecté. Deux modes :
 *   - ?enid=<EnId>       : un seul dossard (le sien, ou celui d'un licencié qu'il a inscrit) ;
 *   - ?all=1&t=<tourId>  : TOUS ses dossards imprimables sur la compétition (le sien +
 *                          ceux qu'il a inscrits), sur des pages A4 complétées.
 *
 * Garde stricte AVANT toute élévation :
 *  1. archer connecté (bk_require_archer) ;
 *  2. chaque inscription lui appartient (BrLicence = sa licence OU BrArcher = lui) —
 *     bk_dossard_can / bk_dossard_entries (défense anti ?enid / ?t forgé) ;
 *  3. la compétition propose le dossard (bk_dossard_visible) ET un gabarit Q existe.
 * Alors seulement, bk_doc_relay_bib régénère le PDF dans un contexte élevé BORNÉ à
 * cette compétition (jamais AUTH_ROOT) — même mécanisme que public/document.php.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';

$archer = bk_require_archer();

if (!empty($_GET['all'])) {
    // Tout imprimer : tous les dossards de l'archer sur cette compétition.
    $tourId = intval($_GET['t'] ?? 0);
    if (!$tourId) { http_response_code(404); exit; }
    $cfg = bk_comp_config($tourId);
    if (!bk_dossard_visible($cfg)) { http_response_code(404); exit; }
    $enids = array();
    foreach (bk_dossard_entries($tourId, $archer) as $b) $enids[] = intval($b->BrEnId);
    if (!$enids) { http_response_code(404); exit; }
} else {
    // Un seul dossard.
    $enId = intval($_GET['enid'] ?? 0);
    $reg = $enId ? bk_dossard_can($enId, $archer) : null;
    if (!$reg) { http_response_code(404); exit; }
    $tourId = intval($reg->BrTournament);
    $cfg = bk_comp_config($tourId);
    if (!bk_dossard_visible($cfg)) { http_response_code(404); exit; }
    $enids = array($enId);
}

$card = bk_dossard_card($tourId);
if ($card === null) { http_response_code(404); exit; }

$t = safe_fetch(safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId = $tourId"));
if (!$t || (string) $t->ToCode === '') { http_response_code(404); exit; }

bk_dossard_normalize_layout($tourId, $card);   // remplissage gauche→droite, haut→bas
bk_doc_relay_bib($tourId, $t->ToCode, $enids, $card);
