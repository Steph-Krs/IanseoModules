<?php
/**
 * lib/admin-ui.php — pieces shared by the organiser pages of the points of sale (settings,
 * catalogue, posters): page assets, navigation, messages, form fields.
 *
 * These pages sit in the ianseo look (Common/Templates/head.php, ACL of the core, open
 * competition). That template reads global variables, so each page includes it itself, at the
 * top level of the script; the helpers here only prepare what goes before and after it.
 * Markup is produced in PHP; the page script (assets/admin.js) gets its texts from
 * data-* attributes written by the page.
 */

if (defined('SHP_ADMIN_UI_LOADED')) return;
define('SHP_ADMIN_UI_LOADED', true);

require_once __DIR__ . '/ui.php';   // shp_asset_url, shp_e

/** Stylesheet and script of the organiser pages, for $JS_SCRIPT (printed in the page head). */
function shp_adm_assets()
{
    return array(
        '<link rel="stylesheet" href="' . shp_e(shp_asset_url('admin.css')) . '">',
        '<script defer src="' . shp_e(shp_asset_url('admin.js')) . '"></script>',
    );
}

/** Address of a page of the organiser side. */
function shp_adm_url($page)
{
    return shp_url('admin/' . $page);
}

/** Message kept across a redirect: $type ok / err / warn, $lines list of texts. */
function shp_adm_flash_set($type, $lines)
{
    $_SESSION['SHP_FLASH'] = array('type' => $type, 'lines' => array_values((array) $lines));
}

/** Messages to show now (and forget). */
function shp_adm_flash_take()
{
    $f = $_SESSION['SHP_FLASH'] ?? null;
    unset($_SESSION['SHP_FLASH']);
    return is_array($f) ? $f : null;
}

/** A message block ($type ok, err, warn, info) with one or several lines. */
function shp_adm_msg($type, $lines)
{
    $lines = array_values(array_filter((array) $lines, 'strlen'));
    if (!$lines) return '';
    $type = in_array($type, array('ok', 'err', 'warn', 'info'), true) ? $type : 'info';
    $html = '<div class="sa-msg sa-' . $type . '" role="' . ($type === 'err' ? 'alert' : 'status') . '">';
    if (count($lines) === 1) return $html . shp_e($lines[0]) . '</div>';
    $html .= '<ul>';
    foreach ($lines as $l) $html .= '<li>' . shp_e($l) . '</li>';
    return $html . '</ul></div>';
}

/**
 * Navigation between the organiser pages of the points of sale. A page that does not exist
 * (yet) is left out, so that no link leads to an error. $current: file name of the page shown.
 */
function shp_adm_nav($current)
{
    $items = array('index.php' => 'MnuSettings', 'catalog.php' => 'MnuCatalog', 'posters.php' => 'MnuPosters',
        'staff.php' => 'MnuStaff', 'orders.php' => 'MnuOrders', 'reports.php' => 'MnuReports');
    $html = '<nav class="sa-nav" aria-label="' . shp_e(shp_t('MnuTitle')) . '">';
    foreach ($items as $file => $key) {
        if (!is_file(dirname(__DIR__) . '/admin/' . $file)) continue;
        $html .= '<a href="' . shp_e(shp_adm_url($file)) . '"' . ($file === $current ? ' class="on" aria-current="page"' : '') . '>'
            . shp_e(shp_t($key)) . '</a>';
    }
    return $html . '</nav>';
}

/** A labelled field: $input is markup already escaped by the caller. */
function shp_adm_field($label, $input, $hint = '', $class = '')
{
    return '<label class="sa-f' . ($class !== '' ? ' ' . $class : '') . '"><span>' . shp_e($label) . '</span>' . $input
        . ($hint !== '' ? '<small>' . shp_e($hint) . '</small>' : '') . '</label>';
}

/** A checkbox with its text. */
function shp_adm_check($name, $checked, $label, $hint = '', $extra = '')
{
    return '<label class="sa-chk"><input type="checkbox" name="' . shp_e($name) . '" value="1"' . ($checked ? ' checked' : '') . $extra . '> '
        . '<span>' . shp_e($label) . ($hint !== '' ? '<small>' . shp_e($hint) . '</small>' : '') . '</span></label>';
}

/** <option> list: [value => label], $sel the selected value. */
function shp_adm_options(array $opts, $sel)
{
    $html = '';
    foreach ($opts as $v => $l) {
        $html .= '<option value="' . shp_e($v) . '"' . ((string) $v === (string) $sel ? ' selected' : '') . '>' . shp_e($l) . '</option>';
    }
    return $html;
}

/** An amount for a text field: 2 decimals, the decimal separator of the language, no grouping; '' for null. */
function shp_adm_amount($v)
{
    if ($v === null || $v === '') return '';
    return number_format((float) $v, 2, bk_number_seps()['dec'], '');
}

/** datetime-local value from a DATETIME column ('' when empty). */
function shp_adm_datetime($v)
{
    $v = trim((string) $v);
    if ($v === '' || strpos($v, '0000') === 0) return '';
    return str_replace(' ', 'T', mb_substr($v, 0, 16));
}

/** Date for people (dd/mm/yyyy) from YYYY-MM-DD. */
function shp_adm_date($d)
{
    return bk_date_fr($d);
}
