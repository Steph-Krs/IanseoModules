<?php
/**
 * lib/link.php — links from the online registration pages (booking/public) to the shop of a
 * competition. Self-contained and read only: these pages run on every installation, with or
 * without the shop's tables, so the one query is guarded ($force: false instead of an exit when
 * a table is missing, see CLAUDE.md "une requête SQL en erreur tue la page").
 */

if (defined('SHP_LINK_LOADED')) return;
define('SHP_LINK_LOADED', true);

require_once __DIR__ . '/lang.php';
require_once dirname(__DIR__, 2) . '/booking/lib/clock.php';

/**
 * Shop links of a competition for a licensee: ['shop' => address or '', 'preorder' => address or ''].
 * 'shop' when the shop is switched on and has something to sell; 'preorder' when pre-orders are
 * still open and some product can be pre-ordered.
 */
function shp_public_links($tourId)
{
    global $CFG;
    static $cache = array();
    $tourId = intval($tourId);
    $none = array('shop' => '', 'preorder' => '');
    if ($tourId <= 0) return $none;
    if (isset($cache[$tourId])) return $cache[$tourId];
    $now = bk_local_now_sql('(SELECT ToTimeZone FROM Tournament WHERE ToId = ' . $tourId . ')');
    $rs = safe_r_sql("SELECT SgPublicKey,
            (SgPreorderUntil IS NOT NULL AND SgPreorderUntil >= $now) AS PreorderOpen,
            EXISTS(SELECT 1 FROM ShopProducts INNER JOIN ShopStands ON SdId = SpStand
                WHERE SpTournament = SgTournament AND SpActive = 1 AND SdActive = 1) AS HasProducts,
            EXISTS(SELECT 1 FROM ShopProducts INNER JOIN ShopStands ON SdId = SpStand
                WHERE SpTournament = SgTournament AND SpActive = 1 AND SdActive = 1 AND SpPreorder = 1) AS HasPreorder
        FROM ShopSettings WHERE SgTournament = $tourId AND SgEnabled = 1", false, true);
    $r = $rs ? safe_fetch($rs) : null;
    if (!$r || !preg_match('/^[a-z0-9]{10}$/', (string) $r->SgPublicKey)) return $cache[$tourId] = $none;
    $url = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/shop/public/index.php?k=' . $r->SgPublicKey;
    return $cache[$tourId] = array(
        'shop' => intval($r->HasProducts) ? $url : '',
        'preorder' => intval($r->PreorderOpen) && intval($r->HasPreorder) ? $url : '',
    );
}
