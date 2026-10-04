<?php
/**
 * public/bib.php — prints the BIB(s) (Qualification badge) of the connected archer. Two modes:
 *   - ?enid=<EnId>       : one bib (their own, or that of a licensee they registered);
 *   - ?all=1&t=<tourId>  : ALL the bibs they may print on the competition (their own + those
 *                          they registered), on filled A4 pages.
 *
 * Strict guard BEFORE any elevation:
 *  1. connected archer (bk_require_archer);
 *  2. each registration is theirs (BrLicence = their licence OR BrArcher = them) —
 *     bk_dossard_can / bk_dossard_entries (defence against a forged ?enid / ?t);
 *  3. the competition offers the bib (bk_dossard_visible) AND a Q template exists.
 * Only then does bk_doc_relay_bib generate the PDF in an elevated context LIMITED to this
 * competition (never AUTH_ROOT) — same mechanism as public/document.php.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';

$archer = bk_require_archer();

if (!empty($_GET['all'])) {
    // Print all: every bib of the archer on this competition.
    $tourId = intval($_GET['t'] ?? 0);
    if (!$tourId) { http_response_code(404); exit; }
    $cfg = bk_comp_config($tourId);
    if (!bk_dossard_visible($cfg)) { http_response_code(404); exit; }
    $enids = array();
    foreach (bk_dossard_entries($tourId, $archer) as $b) $enids[] = intval($b->BrEnId);
    if (!$enids) { http_response_code(404); exit; }
} else {
    // One bib.
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

bk_dossard_normalize_layout($tourId, $card);   // filled left to right, top to bottom
bk_doc_relay_bib($tourId, $t->ToCode, $enids, $card);
