<?php
/**
 * public/boot.php — bootstrap of the customers' pages of the points of sale.
 *
 * $SKIP_AUTH bypasses the ORGANISER authentication bootstrap (config.php): a customer without
 * any account must reach these pages — mechanism of the ianseo core, already used by
 * Api/ISK-NG and the online registration.
 *
 * The price, accepted: these pages have NO ianseo ACL. Every read and write is guarded by the
 * page itself (shp_customer(), shp_api_guard()) and limited to the competition of the public
 * key and to the customer's own orders.
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

// Erasing of the volunteer accounts and nicknames the day after a competition: also run from
// here, in case the nightly task is not installed (the function limits itself).
if (is_file(dirname(__DIR__) . '/lib/purge.php')) {
    require_once dirname(__DIR__) . '/lib/purge.php';
    if (function_exists('shp_purge_due') && !shp_impersonating()) shp_purge_due();
}
