<?php
/**
 * public/shop.php — former address of the shop of a competition (online registration). The
 * shop now lives in the points of sale (AUTH/shop), where it was moved; links and QR codes
 * printed before keep working: they lead there, or to the competition page when the
 * competition has no shop any more.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/payment.php';   // bk_shp: schema and move of the former shop
require_once dirname(__DIR__, 2) . '/shop/lib/link.php';

$tourId = intval($_GET['t'] ?? 0);
bk_shp();
$url = $tourId > 0 ? shp_public_links($tourId)['shop'] : '';
header('Location: ' . ($url !== '' ? $url : bk_public_url($tourId > 0 ? 'competition.php?t=' . $tourId : 'index.php')));
exit;
