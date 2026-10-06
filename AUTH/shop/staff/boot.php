<?php
/**
 * staff/boot.php — bootstrap of the volunteers' pages of the points of sale (the till).
 *
 * Same principle as public/boot.php: $SKIP_AUTH, so NO ianseo ACL. A volunteer is identified by
 * the cookie of their phone (lib/staff-session.php); every page and endpoint asks for the right
 * it needs on the stand concerned (shp_require_staff()). Never assume a core guard protects
 * anything here.
 */

$SKIP_AUTH = 1;

define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

require_once dirname(__DIR__) . '/lib/orders.php';   // common, catalog, stock, customer
require_once dirname(__DIR__) . '/lib/ui.php';

shp_schema();

// "Seen from another account" (server administrator, READ ONLY): no write of any kind.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && shp_impersonating()) {
    if ((string) ($_SERVER['HTTP_X_SHP'] ?? '') === '1') shp_json_error('read_only', shp_t('ShErrReadOnly'), 403);
    bk_impersonation_block();
}

// The address of a QR code carries a token, and these pages show codes and names: never kept
// by a cache, never sent to another site as a referrer.
if (!headers_sent()) {
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: same-origin');
}

// Volunteer identity, rights and erasing the day after the competition.
foreach (array('staff.php', 'staff-session.php', 'purge.php') as $shpLib) {
    if (is_file(dirname(__DIR__) . '/lib/' . $shpLib)) require_once dirname(__DIR__) . '/lib/' . $shpLib;
}
unset($shpLib);
if (function_exists('shp_purge_due') && !shp_impersonating()) shp_purge_due();
