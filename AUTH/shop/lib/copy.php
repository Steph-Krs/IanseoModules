<?php
/**
 * lib/copy.php — "Copy from…": takes the settings, points of sale and catalogue of another
 * competition (the same stalls are usually run from one year to the next).
 *
 * Copied: the settings (not the public key, not the on/off switch; the pre-order deadline keeps
 * the same distance from the first day), the points of sale (closed, order counter back to 0),
 * the products and variants with their INITIAL stock. Not copied: volunteers, customers, orders,
 * payments. The destination's catalogue is REPLACED, which is refused once it has orders.
 *
 * Which competitions may be the source follows the booking module's rule (bk_copy_*): the ones
 * the organiser can reach, or any code for the server administrator.
 */

if (defined('SHP_COPY_LOADED')) return;
define('SHP_COPY_LOADED', true);

require_once __DIR__ . '/catalog-admin.php';
require_once dirname(__DIR__, 2) . '/booking/lib/competition.php';   // bk_copy_access_where, bk_copy_is_admin

/** Competitions that can be copied from: reachable, with at least one point of sale, not the current one. */
function shp_copy_sources($currentTour)
{
    shp_schema();
    $rs = safe_r_sql("SELECT ToId, ToCode, ToName, ToWhenFrom FROM Tournament
        INNER JOIN ShopSettings ON SgTournament = ToId
        WHERE ToId <> " . intval($currentTour) . " AND " . bk_copy_access_where() . "
          AND EXISTS (SELECT 1 FROM ShopStands WHERE SdTournament = ToId)
        ORDER BY ToWhenFrom DESC, ToName LIMIT 500");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Is this competition an allowed source? (The list shown to the organiser is not proof.) */
function shp_copy_allowed($srcTour, $currentTour)
{
    $srcTour = intval($srcTour);
    if ($srcTour <= 0 || $srcTour === intval($currentTour)) return false;
    shp_schema();
    return (bool) safe_fetch(safe_r_sql("SELECT ToId FROM Tournament
        INNER JOIN ShopSettings ON SgTournament = ToId
        WHERE ToId = $srcTour AND " . bk_copy_access_where() . "
          AND EXISTS (SELECT 1 FROM ShopStands WHERE SdTournament = ToId)"));
}

/** Source typed by the server administrator (competition code, or numeric id) → ToId allowed, or 0. */
function shp_copy_resolve($input, $currentTour)
{
    $input = trim((string) $input);
    if ($input === '') return 0;
    shp_schema();
    if (ctype_digit($input) && shp_copy_allowed(intval($input), $currentTour)) return intval($input);
    $r = safe_fetch(safe_r_sql("SELECT ToId FROM Tournament
        INNER JOIN ShopSettings ON SgTournament = ToId
        WHERE ToCode = " . StrSafe_DB($input) . " AND ToId <> " . intval($currentTour) . " AND " . bk_copy_access_where() . "
          AND EXISTS (SELECT 1 FROM ShopStands WHERE SdTournament = ToId)
        ORDER BY ToWhenFrom DESC LIMIT 1"));
    return $r ? intval($r->ToId) : 0;
}

/** A date-time moved by the same distance from the first day: [source first day, source value, destination first day]. */
function shp_copy_shift($srcFrom, $srcValue, $dstFrom)
{
    if ($srcValue === null || !preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $srcFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $dstFrom)
        || (string) $srcFrom < '0001-01-01' || (string) $dstFrom < '0001-01-01') return null;
    try {
        $a = new DateTime(mb_substr((string) $srcFrom, 0, 10) . ' 00:00:00');
        $b = new DateTime((string) $srcValue);
        $c = new DateTime(mb_substr((string) $dstFrom, 0, 10) . ' 00:00:00');
        return $c->add($a->diff($b))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Copies the shop of $srcTour into $destTour. Returns ['error' => 0, 'stands' => n, 'products' => n]
 * or ['error' => 1, 'msg' => …]. Checks nothing about access: shp_copy_allowed() is the caller's job.
 */
function shp_copy_from($destTour, $srcTour)
{
    shp_schema();
    $destTour = intval($destTour); $srcTour = intval($srcTour);
    if ($destTour <= 0 || $srcTour <= 0 || $destTour === $srcTour) return array('error' => 1, 'msg' => shp_t('ShSetCopyNoSrc'));
    if (shp_tour_has_orders($destTour)) return array('error' => 1, 'msg' => shp_t('ShSetCopyOrders'));

    $src = safe_fetch(safe_r_sql("SELECT ToWhenFrom, ShopSettings.* FROM Tournament
        INNER JOIN ShopSettings ON SgTournament = ToId WHERE ToId = $srcTour"));
    $dst = safe_fetch(safe_r_sql("SELECT ToWhenFrom FROM Tournament WHERE ToId = $destTour"));
    if (!$src || !$dst) return array('error' => 1, 'msg' => shp_t('ShSetCopyNoSrc'));
    if (!shp_settings_ensure($destTour)) return array('error' => 1, 'msg' => shp_t('ShErrInternal'));

    // The source is read completely first: nothing is written until it is all in memory.
    $stands = shp_stands($srcTour, false);
    $stats = shp_stock_stats($srcTour);
    $products = array();
    $rs = safe_r_sql("SELECT * FROM ShopProducts WHERE SpTournament = $srcTour ORDER BY SpOrder, SpId");
    while ($r = safe_fetch($rs)) $products[intval($r->SpId)] = $r;
    $variants = array();
    if ($products) {
        $rs = safe_r_sql("SELECT * FROM ShopVariants WHERE SwProduct IN (" . implode(',', array_keys($products)) . ") ORDER BY SwOrder, SwId");
        while ($r = safe_fetch($rs)) $variants[intval($r->SwProduct)][] = $r;
    }
    // Initial stock = what is left + what went out (sold or lost) = what was entered.
    $initial = function ($key, $left) use ($stats) {
        if ($left === null) return null;
        $s = $stats[$key] ?? array('sold' => 0, 'lost' => 0);
        return max(0, intval($left) + intval($s['sold']) + intval($s['lost']));
    };

    safe_w_BeginTransaction();

    // The destination's points of sale, products, variants and stock movements go; the rights
    // of its volunteers on them go with them (the volunteers stay, without rights).
    $old = shp_stands($destTour, false);
    if ($old) {
        shp_products_purge($destTour, '1=1');
        safe_w_sql("DELETE FROM ShopStaffStands WHERE StStand IN (" . implode(',', array_keys($old)) . ")");
        safe_w_sql("DELETE FROM ShopStands WHERE SdTournament = $destTour");
    }

    $until = shp_copy_shift($src->ToWhenFrom, $src->SgPreorderUntil, $dst->ToWhenFrom);
    safe_w_sql("UPDATE ShopSettings SET SgGuests = " . intval($src->SgGuests) . ", SgTab = " . intval($src->SgTab)
        . ", SgTrustGate = " . intval($src->SgTrustGate) . ", SgGuestMaxOpen = " . intval($src->SgGuestMaxOpen)
        . ", SgNotice = " . StrSafe_DB($src->SgNotice)
        . ", SgPreorderUntil = " . ($until === null ? 'NULL' : StrSafe_DB($until))
        . ", SgUpdated = " . shp_local_now_sql($destTour) . " WHERE SgTournament = $destTour");

    $standMap = array();
    foreach ($stands as $sid => $s) {
        safe_w_sql("INSERT INTO ShopStands SET SdTournament = $destTour, SdKind = " . StrSafe_DB($s->SdKind)
            . ", SdName = " . StrSafe_DB($s->SdName) . ", SdMode = " . StrSafe_DB($s->SdMode)
            . ", SdPayWhen = " . StrSafe_DB($s->SdPayWhen) . ", SdOnline = " . intval($s->SdOnline)
            . ", SdOpen = 0, SdPrepMin = " . intval($s->SdPrepMin) . ", SdParallel = " . intval($s->SdParallel)
            . ", SdPrefix = " . StrSafe_DB($s->SdPrefix) . ", SdNextNo = 0, SdOrder = " . intval($s->SdOrder)
            . ", SdActive = " . intval($s->SdActive));
        $standMap[$sid] = intval(safe_w_last_id());
    }

    $nProducts = 0;
    foreach ($products as $pid => $p) {
        if (!isset($standMap[intval($p->SpStand)])) continue;
        safe_w_sql("INSERT INTO ShopProducts SET SpTournament = $destTour, SpStand = " . $standMap[intval($p->SpStand)]
            . ", SpCategory = " . StrSafe_DB($p->SpCategory) . ", SpName = " . StrSafe_DB($p->SpName)
            . ", SpDescription = " . StrSafe_DB($p->SpDescription) . ", SpPrice = " . StrSafe_DB($p->SpPrice)
            . ", SpStock = NULL, SpStockAlert = " . ($p->SpStockAlert === null ? 'NULL' : intval($p->SpStockAlert))
            . ", SpMaxPer = " . intval($p->SpMaxPer) . ", SpOptionName = " . StrSafe_DB($p->SpOptionName)
            . ", SpPreorder = " . intval($p->SpPreorder) . ", SpOnsite = " . intval($p->SpOnsite)
            . ", SpAvailable = 1, SpOrder = " . intval($p->SpOrder) . ", SpActive = " . intval($p->SpActive)
            . ", SpUpdated = " . shp_local_now_sql($destTour));
        $newId = intval(safe_w_last_id());
        $nProducts++;
        $qty = $initial('p' . $pid, $p->SpStock === null ? null : intval($p->SpStock));
        if ($qty !== null) shp_stock_set($newId, 0, $qty, 0);
        foreach ($variants[$pid] ?? array() as $v) {
            safe_w_sql("INSERT INTO ShopVariants SET SwProduct = $newId, SwLabel = " . StrSafe_DB($v->SwLabel)
                . ", SwPrice = " . ($v->SwPrice === null ? 'NULL' : StrSafe_DB($v->SwPrice))
                . ", SwStock = NULL, SwAvailable = 1, SwOrder = " . intval($v->SwOrder));
            $newVar = intval(safe_w_last_id());
            $vq = $initial('v' . intval($v->SwId), $v->SwStock === null ? null : intval($v->SwStock));
            if ($vq !== null) shp_stock_set($newId, $newVar, $vq, 0);
        }
    }

    safe_w_Commit();
    shp_settings($destTour, true);
    return array('error' => 0, 'stands' => count($standMap), 'products' => $nProducts);
}
