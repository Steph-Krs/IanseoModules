<?php
/**
 * public/icon.php?s=<size> — square icon of the shop when it is added to a phone's home screen
 * (web app manifest, notifications): ianseo's own logo centred on white. Made with GD from
 * Common/Images/ianseo-logo.png; sizes 96, 180, 192 and 512 only. Cached by the browser.
 */
$size = intval($_GET['s'] ?? 192);
if (!in_array($size, array(96, 180, 192, 512), true)) $size = 192;
$logo = dirname(__DIR__, 5) . '/Common/Images/ianseo-logo.png';
header('Cache-Control: public, max-age=604800');
if (!function_exists('imagecreatetruecolor') || !is_file($logo)) {
    header('Content-Type: image/png');
    readfile($logo);
    exit;
}
$src = imagecreatefrompng($logo);
$img = imagecreatetruecolor($size, $size);
imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
$w = imagesx($src);
$h = imagesy($src);
$scale = $size * 0.86 / max($w, $h);
$dw = (int) round($w * $scale);
$dh = (int) round($h * $scale);
imagecopyresampled($img, $src, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
header('Content-Type: image/png');
imagepng($img);
imagedestroy($img);
imagedestroy($src);
