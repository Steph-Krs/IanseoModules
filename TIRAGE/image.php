<?php
/**
 * Background image of a show, served to its screens.
 *
 * Opened by either screen link, like the screens themselves. The URL carries
 * the show's data revision, which changes whenever the image is replaced, so the
 * answer can be cached for a long time without ever showing a stale picture.
 */

require_once __DIR__ . '/lib/boot.php';
session_write_close();

[$show] = tir_show_by_token((string)($_GET['t'] ?? ''));
if (!$show || !$show['hasImage']) {
    http_response_code(404);
    exit;
}

$q = safe_r_sql("SELECT DwImage, DwImageType FROM DrawShows WHERE DwId=" . $show['id']);
$r = safe_fetch($q);
$data = $r ? base64_decode((string)$r->DwImage, true) : false;
if ($data === false || $data === '') {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $r->DwImageType);
header('Cache-Control: public, max-age=31536000, immutable');
// bytes: Content-Length counts bytes of the binary image.
header('Content-Length: ' . strlen($data));
echo $data;
