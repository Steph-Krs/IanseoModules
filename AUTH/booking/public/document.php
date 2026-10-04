<?php
/**
 * public/document.php — relay to an OFFICIAL ianseo document (programme, participants,
 * results), for the connected archer.
 *
 * Strict guard BEFORE any elevation (bk_doc_relay):
 *  1. connected archer (bk_require_archer) — these documents are not anonymous;
 *  2. known document (bk_doc_defs);
 *  3. existing competition AND the organiser ticked the matching box.
 * Only then does the relay generate the official PDF in an elevated context LIMITED to this
 * competition (see bk_doc_relay).
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
// Same "has content" guard as the list: no empty document generated from a forged URL.
$has = $defs[$doc]['has'] ?? '';
if ($has !== '' && function_exists($has) && !call_user_func($has, $tourId)) { http_response_code(404); exit; }

$t = safe_fetch(safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId = " . $tourId));
if (!$t || (string) $t->ToCode === '') { http_response_code(404); exit; }

bk_doc_relay($tourId, $t->ToCode, $defs[$doc]);
