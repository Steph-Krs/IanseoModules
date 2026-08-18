<?php
/**
 * public/tourlogo.php — sert un logo de compétition (ToImgL/R/B) en accès PUBLIC,
 * pour le mandat consultable par les archers.
 *
 * Common/TourLogo.php du cœur exige une session organisateur (CheckTourSession) :
 * inutilisable depuis la face publique. Cet endpoint lit le même BLOB, mais borné
 * aux compétitions dont le mandat est explicitement rendu VISIBLE (bk_mandate_visible)
 * → aucune image d'une compétition non publiée n'est exposée.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';

$tourId = intval($_GET['t'] ?? 0);
$type   = strtoupper((string) ($_GET['type'] ?? ''));
if (!$tourId || !in_array($type, array('L', 'R', 'B'), true)) { http_response_code(404); exit; }

$cfg = bk_comp_config($tourId);
if (!bk_mandate_visible($cfg)) { http_response_code(404); exit; }

$row = safe_fetch(safe_r_sql("SELECT ToImg$type AS Img FROM Tournament WHERE ToId = $tourId"));
$content = $row ? (string) $row->Img : '';
$im = $content !== '' ? @imagecreatefromstring($content) : false;
if ($im === false) { http_response_code(404); exit; }

$w = imagesx($im); $h = imagesy($im);
$maxW = intval($_GET['w'] ?? 0);
if ($maxW > 0 && $w > $maxW) {
    $scala = $maxW / $w;
    $nw = max(1, intval($w * $scala)); $nh = max(1, intval($h * $scala));
    $n = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($n, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im);
    $im = $n;
}
header('Content-Type: image/png');
header('Cache-Control: private, max-age=300');
imagepng($im);
imagedestroy($im);
