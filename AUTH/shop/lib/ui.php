<?php
/**
 * lib/ui.php — HTML shell of the phone pages of the points of sale (customers, volunteers).
 *
 * These pages do NOT go through Common/Templates/head.php (organiser menu, open competition
 * expected): standalone page, charter colours (CHARTE_GRAPHIQUE.md), CSS scoped by #shp.
 * Texts for the page's script travel as a JSON block (shp_json_script), never as literals in
 * the script.
 */

if (defined('SHP_UI_LOADED')) return;
define('SHP_UI_LOADED', true);

require_once __DIR__ . '/common.php';
require_once dirname(__DIR__, 2) . '/booking/lib/ui.php';   // bk_version, bk_impersonation_block

/** Address of an asset, with the module version to break the browser cache after an update. */
function shp_asset_url($file)
{
    return shp_url('assets/' . $file) . '?v=' . rawurlencode(bk_version());
}

/**
 * Page head. $opt: 'layout' ('app' full screen, 'card' centred card), 'css' (extra stylesheets
 * of assets/), 'title_suffix' (false to show the title alone), 'manifest' (address of a web app
 * manifest: the page can then be added to a phone's home screen and open like an application).
 */
function shp_head($title, array $opt = array())
{
    $layout = ($opt['layout'] ?? 'app') === 'card' ? 'card' : 'app';
    $full = ($opt['title_suffix'] ?? true) ? $title . ' — ' . shp_t('ShBrand') : $title;
    echo '<!DOCTYPE html>' . "\n" . '<html lang="' . shp_e(mb_substr(aut_lang_code(), 0, 2)) . '">' . "\n<head>\n"
        . '<meta charset="utf-8">' . "\n"
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n"
        . '<meta name="theme-color" content="#0254a8">' . "\n"
        . '<meta name="robots" content="noindex">' . "\n"
        . '<title>' . shp_e($full) . "</title>\n"
        . '<link rel="stylesheet" href="' . shp_e(shp_asset_url('shp.css')) . '">' . "\n";
    foreach ((array) ($opt['css'] ?? array()) as $css) {
        echo '<link rel="stylesheet" href="' . shp_e(shp_asset_url($css)) . '">' . "\n";
    }
    if (!empty($opt['manifest'])) {
        echo '<link rel="manifest" href="' . shp_e($opt['manifest']) . '">' . "\n"
            . '<link rel="apple-touch-icon" href="' . shp_e(shp_url('public/icon.php?s=180')) . '">' . "\n"
            . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    }
    echo "</head>\n<body>\n" . '<div id="shp" class="shp-' . $layout . '">' . "\n";

    $mnt = function_exists('aut_maintenance_notice') ? aut_maintenance_notice() : '';
    if ($mnt !== '') echo '  <div class="shp-banner shp-banner-warn">' . shp_e($mnt) . "</div>\n";
    if (shp_impersonating()) {
        $imp = bk_impersonating();
        echo '  <div class="shp-banner shp-banner-imp">' . shp_e(bk_t('ImpView')) . ' — <b>'
            . shp_e((string) ($imp['label'] ?? '')) . '</b> — ' . shp_e(bk_t('ImpReadOnly')) . "</div>\n";
    }
}

/** Page end, with the scripts of assets/ given (shp.js, the shared helpers, always first). */
function shp_foot(array $scripts = array())
{
    echo '  <script src="' . shp_e(shp_asset_url('shp.js')) . '"></script>' . "\n";
    foreach ($scripts as $js) echo '  <script src="' . shp_e(shp_asset_url($js)) . '"></script>' . "\n";
    echo "</div>\n</body>\n</html>\n";
}

/** Data for the page's script, as a JSON block (no markup can escape from it). */
function shp_json_script($id, $data)
{
    return '<script type="application/json" id="' . shp_e($id) . '">'
        . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
        . "</script>\n";
}

/**
 * Settings of a page's script (JSON block #shp-cfg read by shp.js): number separators of the
 * visitor's language, currency of the competition, and a scope that keeps the unanswered writes
 * of one page apart from another's.
 */
function shp_page_cfg($tourId, $scope, array $extra = array())
{
    $seps = bk_number_seps();
    return array_merge(array('dec' => $seps['dec'], 'thousands' => $seps['thousands'],
        'currency' => bk_currency(intval($tourId)), 'scope' => (string) $scope), $extra);
}

/** Short message block: $type ok, err, warn, info. */
function shp_msg($type, $text)
{
    $type = in_array($type, array('ok', 'err', 'warn', 'info'), true) ? $type : 'info';
    return '<div class="shp-msg shp-msg-' . $type . '" role="' . ($type === 'err' ? 'alert' : 'status') . '">' . shp_e($text) . '</div>';
}

/** Whole page with one message (shop unknown, closed…), then stops. */
function shp_page_message($title, $text, $type = 'info', $http = 200)
{
    if ($http !== 200 && !headers_sent()) http_response_code(intval($http));
    shp_head($title, array('layout' => 'card'));
    echo '  <main class="shp-main"><div class="shp-card"><h1>' . shp_e($title) . '</h1>' . shp_msg($type, $text) . "</div></main>\n";
    shp_foot();
    exit;
}

/** Texts shared by every phone page (shp.js): connection, undo, errors. */
function shp_base_texts()
{
    return shp_ts(array('ShOffline', 'ShRetrying', 'ShUndo', 'ShClose', 'ShLoading', 'ShErrNetwork', 'ShEtaMin'));
}
