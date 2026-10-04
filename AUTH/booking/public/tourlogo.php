<?php
/**
 * public/tourlogo.php — serves a competition logo (ToImgL/R/B) PUBLICLY, for the mandate the
 * archers can read.
 *
 * The core's Common/TourLogo.php needs an organiser session (CheckTourSession): unusable from
 * the public side. This endpoint reads the same BLOB, limited to competitions whose mandate is
 * explicitly VISIBLE (bk_mandate_visible) → no image of an unpublished competition is exposed.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/mandate.php';
require_once dirname(__DIR__) . '/lib/registration.php';   // bk_reg_existing (shareable picture)

$tourId = intval($_GET['t'] ?? 0);
$type   = strtoupper((string) ($_GET['type'] ?? ''));
if (!$tourId || !in_array($type, array('L', 'R', 'B'), true)) { http_response_code(404); exit; }

$cfg = bk_comp_config($tourId);
// Allowed when the mandate is published, OR when the connected archer is REGISTERED on this
// competition (shareable "I'll be there" picture) — they then see the logos of THEIR
// competition only, never those of a competition they are not in.
$ok = bk_mandate_visible($cfg);
if (!$ok) {
    $a = bk_current_archer();
    if ($a && bk_reg_existing($tourId, $a->BaLicence)) $ok = true;
}
if (!$ok) { http_response_code(404); exit; }

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
