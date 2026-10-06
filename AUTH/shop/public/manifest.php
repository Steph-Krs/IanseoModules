<?php
/**
 * public/manifest.php?k=<public key> — web app manifest of the shop of a competition, so that a
 * customer can add it to the home screen: it opens then like an application, and on an iPhone
 * that is the condition to receive notifications. Unknown key: the generic shop.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/public.php';

$key = (string) ($_GET['k'] ?? '');
$tourId = shp_tour_by_key($key);
$name = $tourId > 0 ? shp_cus_tour_name($tourId) : '';
header('Cache-Control: no-cache');
// ianseo's envelope key: browsers ignore manifest members they do not know.
JsonOut(array(
    'error' => 0,
    'name' => $name !== '' ? $name . ' — ' . shp_t('ShBrand') : shp_t('ShBrand'),
    'short_name' => shp_t('ShBrand'),
    'start_url' => $tourId > 0 ? 'index.php?k=' . $key : 'index.php',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#0254a8',
    'lang' => mb_substr(aut_lang_code(), 0, 2),
    'icons' => array(
        array('src' => 'icon.php?s=192', 'sizes' => '192x192', 'type' => 'image/png'),
        array('src' => 'icon.php?s=512', 'sizes' => '512x512', 'type' => 'image/png'),
    ),
));
