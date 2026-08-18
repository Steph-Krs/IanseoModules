<?php
/**
 * public/document.php — relais vers un document OFFICIEL ianseo (programme,
 * participants, résultats), pour l'archer connecté.
 *
 * Garde stricte AVANT toute élévation (bk_doc_relay) :
 *  1. archer connecté (bk_require_archer) — ces documents ne sont pas anonymes ;
 *  2. document connu (bk_doc_defs) ;
 *  3. compétition existante ET organisateur ayant coché la case correspondante.
 * Seulement alors, le relais régénère le PDF officiel dans un contexte élevé BORNÉ
 * à cette compétition (voir bk_doc_relay).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';

$archer = bk_require_archer();

$tourId = intval($_GET['t'] ?? 0);
$doc    = (string) ($_GET['doc'] ?? '');
$defs   = bk_doc_defs();

if (!$tourId || !isset($defs[$doc])) { http_response_code(404); exit; }

$cfg  = bk_comp_config($tourId);
$flag = $defs[$doc]['flag'];
if (intval(is_object($cfg) ? ($cfg->$flag ?? 0) : 0) !== 1) { http_response_code(404); exit; }
// Même garde de « matière » que la liste : ne pas régénérer un document vide via URL forgée.
$has = $defs[$doc]['has'] ?? '';
if ($has !== '' && function_exists($has) && !call_user_func($has, $tourId)) { http_response_code(404); exit; }

$t = safe_fetch(safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId = " . $tourId));
if (!$t || (string) $t->ToCode === '') { http_response_code(404); exit; }

bk_doc_relay($tourId, $t->ToCode, $defs[$doc]);
