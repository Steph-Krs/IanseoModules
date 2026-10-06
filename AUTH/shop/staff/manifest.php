<?php
/**
 * staff/manifest.php?k=<public key> — web app manifest of the volunteers' till of a competition:
 * added to the home screen, the till opens like an application, at its stable address.
 */
require_once __DIR__ . '/boot.php';

$key = (string) ($_GET['k'] ?? '');
$tourId = shp_tour_by_key($key);
header('Cache-Control: no-cache');
// ianseo's envelope key: browsers ignore manifest members they do not know.
JsonOut(array(
    'error' => 0,
    'name' => shp_t('ShStfTillTitle') . ' — ' . shp_t('ShBrand'),
    'short_name' => shp_t('ShStfTillTitle'),
    'start_url' => $tourId > 0 ? 'index.php?k=' . $key : 'index.php',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#0254a8',
    'lang' => mb_substr(aut_lang_code(), 0, 2),
    'icons' => array(
        array('src' => '../public/icon.php?s=192', 'sizes' => '192x192', 'type' => 'image/png'),
        array('src' => '../public/icon.php?s=512', 'sizes' => '512x512', 'type' => 'image/png'),
    ),
));
