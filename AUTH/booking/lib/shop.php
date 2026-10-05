<?php
/**
 * lib/shop.php — the competition's shop (a generalised refreshment stall: souvenirs,
 * accommodation, access…).
 *
 * An item (BookingShopItems) belongs to a free section (Refreshments, Souvenirs…). When it has an
 * option name (SiOptionName, e.g. "Size"), it has variants (BookingShopVariants, e.g. S/M/L), each
 * with its own stock; otherwise it is a simple item with one stock. Orders (BookingShopOrders) are a
 * quantity per (competition, licence, item, variant), editable while the shop is open.
 * Stock 0 = unlimited; SiMaxPerPerson 0 = unlimited.
 *
 * The server has the last word: every stock and limit check is made again here.
 */

if (defined('BK_SHOP_LOADED')) return;
define('BK_SHOP_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';   // bk_comp_payments_on, bk_comp_set_effective, bk_comp_finished

/** Does the shop take orders? Its own deadline, otherwise the registration window. */
function bk_shop_open($cfg)
{
    $until = trim((string) ($cfg->BcShopUntil ?? ''));
    if ($until === '' || strpos($until, '0000') === 0) {
        if (!empty($cfg->BcIsOpen)) return true;
        // Closed competition using the shop (level 1): no registration window to follow, the
        // shop stays open until the competition is over.
        return intval($cfg->BcPublishLevel ?? 1) === 1 && !empty($cfg->BcPayments)
            && !bk_comp_finished(intval($cfg->BcTournament ?? 0));
    }
    if (!bk_comp_payments_on($cfg)) return false;
    // Deadline typed in the competition's local time: compare with its local "now"
    // (lib/clock.php) — NOW() alone is UTC on the archer pages.
    $tour = intval($cfg->BcTournament ?? 0);
    $rs = safe_r_sql("SELECT (" . StrSafe_DB($until) . " >= "
        . bk_local_now_sql("(SELECT ToTimeZone FROM Tournament WHERE ToId = $tour)") . ") AS o");
    $r = safe_fetch($rs);
    return $r ? (bool) $r->o : false;
}

/** At least one active item in this competition's shop. */
function bk_shop_has_items($tourId)
{
    bk_schema();
    $rs = safe_r_sql("SELECT 1 FROM BookingShopItems
        WHERE SiTournament = " . intval($tourId) . " AND SiActive = 1 LIMIT 1");
    return (bool) safe_fetch($rs);
}

/**
 * Items of the shop, with variants and remaining stock. With $licence, each item/variant also
 * has 'mine' (quantity already ordered).
 * Returns [SiId => [id, section, label, description, price, stock, maxper,
 *                   option, active, remaining, mine, variants=[SvId => [...]]]].
 */
function bk_shop_items($tourId, $activeOnly = false, $licence = null)
{
    bk_schema();
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT * FROM BookingShopItems WHERE SiTournament = $tourId"
        . ($activeOnly ? " AND SiActive = 1" : "") . " ORDER BY SiOrder, SiId");
    $items = array();
    while ($r = safe_fetch($rs)) {
        $items[intval($r->SiId)] = array(
            'id' => intval($r->SiId), 'section' => (string) $r->SiSection, 'label' => (string) $r->SiLabel,
            'description' => (string) $r->SiDescription, 'price' => (float) $r->SiPrice,
            'stock' => intval($r->SiStock), 'maxper' => intval($r->SiMaxPerPerson),
            'option' => (string) $r->SiOptionName, 'active' => intval($r->SiActive),
            'remaining' => null, 'mine' => 0, 'variants' => array(),
        );
    }
    if (!$items) return array();
    $ids = implode(',', array_map('intval', array_keys($items)));

    $rs = safe_r_sql("SELECT * FROM BookingShopVariants WHERE SvItem IN ($ids) ORDER BY SvOrder, SvId");
    while ($r = safe_fetch($rs)) {
        $it = intval($r->SvItem);
        if (isset($items[$it])) $items[$it]['variants'][intval($r->SvId)] = array(
            'id' => intval($r->SvId), 'label' => (string) $r->SvLabel,
            'stock' => intval($r->SvStock), 'remaining' => null, 'mine' => 0,
        );
    }

    $rs = safe_r_sql("SELECT SoItem, SoVariant, SUM(SoQty) q FROM BookingShopOrders
        WHERE SoTournament = $tourId GROUP BY SoItem, SoVariant");
    $ord = array();
    while ($r = safe_fetch($rs)) $ord[intval($r->SoItem) . ':' . intval($r->SoVariant)] = intval($r->q);

    $mine = array();
    if ($licence !== null) {
        $rs = safe_r_sql("SELECT SoItem, SoVariant, SoQty FROM BookingShopOrders
            WHERE SoTournament = $tourId AND SoLicence = " . StrSafe_DB($licence));
        while ($r = safe_fetch($rs)) $mine[intval($r->SoItem) . ':' . intval($r->SoVariant)] = intval($r->SoQty);
    }

    foreach ($items as $id => &$it) {
        if ($it['variants']) {
            foreach ($it['variants'] as $vid => &$v) {
                $o = $ord["$id:$vid"] ?? 0;
                $v['remaining'] = $v['stock'] > 0 ? max(0, $v['stock'] - $o) : null;
                $v['mine'] = $mine["$id:$vid"] ?? 0;
            }
            unset($v);
        } else {
            $o = $ord["$id:0"] ?? 0;
            $it['remaining'] = $it['stock'] > 0 ? max(0, $it['stock'] - $o) : null;
            $it['mine'] = $mine["$id:0"] ?? 0;
        }
    }
    unset($it);
    return $items;
}

/**
 * Records an ordered quantity (upsert, or deletion when 0), checking the stock and the
 * per-person limit. Returns ['ok' => bool, 'msg' => ?].
 */
function bk_shop_order_set($tourId, $licence, $itemId, $variantId, $qty)
{
    bk_schema();
    $tourId = intval($tourId); $itemId = intval($itemId);
    $variantId = intval($variantId); $qty = max(0, intval($qty));
    $lic = StrSafe_DB($licence);

    $rs = safe_r_sql("SELECT * FROM BookingShopItems WHERE SiId = $itemId AND SiTournament = $tourId AND SiActive = 1");
    $it = safe_fetch($rs);
    if (!$it) return array('ok' => false, 'msg' => bk_t('ShopItemGone'));

    if (trim((string) $it->SiOptionName) !== '') {
        if ($variantId <= 0) return array('ok' => false, 'msg' => bk_t('ShopPickOption'));
        $rs = safe_r_sql("SELECT SvStock FROM BookingShopVariants WHERE SvId = $variantId AND SvItem = $itemId");
        $v = safe_fetch($rs);
        if (!$v) return array('ok' => false, 'msg' => bk_t('ShopBadOption'));
        $stock = intval($v->SvStock);
    } else {
        $variantId = 0;
        $stock = intval($it->SiStock);
    }

    $rs = safe_r_sql("SELECT SoQty FROM BookingShopOrders WHERE SoTournament = $tourId
        AND SoLicence = $lic AND SoItem = $itemId AND SoVariant = $variantId");
    $cur = safe_fetch($rs); $mineOld = $cur ? intval($cur->SoQty) : 0;

    $rs = safe_r_sql("SELECT COALESCE(SUM(SoQty),0) q FROM BookingShopOrders
        WHERE SoTournament = $tourId AND SoItem = $itemId AND SoVariant = $variantId");
    $others = intval(safe_fetch($rs)->q) - $mineOld;
    if ($stock > 0 && $qty > $stock - $others) {
        return array('ok' => false, 'msg' => bk_t('ShopLowStock', max(0, $stock - $others)));
    }

    $maxper = intval($it->SiMaxPerPerson);
    if ($maxper > 0) {
        $rs = safe_r_sql("SELECT COALESCE(SUM(SoQty),0) q FROM BookingShopOrders
            WHERE SoTournament = $tourId AND SoLicence = $lic AND SoItem = $itemId AND SoVariant <> $variantId");
        if (intval(safe_fetch($rs)->q) + $qty > $maxper) {
            return array('ok' => false, 'msg' => bk_t('ShopMaxPer', $maxper));
        }
    }

    if ($qty === 0) {
        safe_w_sql("DELETE FROM BookingShopOrders WHERE SoTournament = $tourId
            AND SoLicence = $lic AND SoItem = $itemId AND SoVariant = $variantId");
    } else {
        safe_w_sql("INSERT INTO BookingShopOrders (SoTournament, SoLicence, SoItem, SoVariant, SoQty)
            VALUES ($tourId, $lic, $itemId, $variantId, $qty)
            ON DUPLICATE KEY UPDATE SoQty = $qty");
    }
    return array('ok' => true);
}

/** Shop total of an archer on a competition (for the receipt). */
function bk_shop_order_total($tourId, $licence)
{
    bk_schema();
    $rs = safe_r_sql("SELECT COALESCE(SUM(SoQty * SiPrice), 0) t FROM BookingShopOrders
        INNER JOIN BookingShopItems ON SiId = SoItem
        WHERE SoTournament = " . intval($tourId) . " AND SoLicence = " . StrSafe_DB($licence) . " AND SoQty > 0");
    $r = safe_fetch($rs);
    return $r ? (float) $r->t : 0.0;
}

/** Detailed lines of an archer's shop order (receipt, summary). */
function bk_shop_order_lines($tourId, $licence)
{
    bk_schema();
    $rs = safe_r_sql("SELECT SiLabel, SiSection, SiPrice, SvLabel, SoQty
        FROM BookingShopOrders
        INNER JOIN BookingShopItems ON SiId = SoItem
        LEFT  JOIN BookingShopVariants ON SvId = SoVariant
        WHERE SoTournament = " . intval($tourId) . " AND SoLicence = " . StrSafe_DB($licence) . " AND SoQty > 0
        ORDER BY SiOrder, SiId");
    $out = array();
    while ($r = safe_fetch($rs)) {
        $out[] = array(
            'label' => $r->SiLabel . ($r->SvLabel ? ' — ' . $r->SvLabel : ''),
            'section' => (string) $r->SiSection, 'qty' => intval($r->SoQty),
            'unit' => (float) $r->SiPrice, 'amount' => intval($r->SoQty) * (float) $r->SiPrice,
        );
    }
    return $out;
}

/** Sets the shop's own deadline ('' = follows the registration window). */
function bk_shop_set_deadline($tourId, $dt)
{
    bk_schema();
    $tourId = intval($tourId);
    $dt = trim((string) $dt);
    // datetime-local value, ASCII: bytes are characters here.
    bk_comp_set_effective($tourId, array('BcShopUntil' => $dt === '' ? null : str_replace('T', ' ', substr($dt, 0, 16))));
}

/* ----- Organiser's editing ----- */

function bk_shop_item_upsert($tourId, $d)
{
    bk_schema();
    $tourId = intval($tourId);
    $set = "SiSection = " . StrSafe_DB(mb_substr(trim((string) ($d['section'] ?? '')), 0, 60))
        . ", SiLabel = " . StrSafe_DB(mb_substr(trim((string) ($d['label'] ?? '')), 0, 120))
        . ", SiDescription = " . StrSafe_DB(mb_substr(trim((string) ($d['description'] ?? '')), 0, 255))
        . ", SiPrice = " . StrSafe_DB(number_format((float) str_replace(',', '.', (string) ($d['price'] ?? 0)), 2, '.', ''))
        . ", SiStock = " . max(0, intval($d['stock'] ?? 0))
        . ", SiMaxPerPerson = " . max(0, intval($d['maxper'] ?? 0))
        . ", SiOptionName = " . StrSafe_DB(mb_substr(trim((string) ($d['option'] ?? '')), 0, 40))
        . ", SiOrder = " . max(0, intval($d['order'] ?? 0))
        . ", SiActive = " . (empty($d['active']) ? 0 : 1);
    $id = intval($d['id'] ?? 0);
    if ($id > 0) {
        safe_w_sql("UPDATE BookingShopItems SET $set WHERE SiId = $id AND SiTournament = $tourId");
        return $id;
    }
    safe_w_sql("INSERT INTO BookingShopItems SET SiTournament = $tourId, $set");
    return intval(safe_w_last_id());   // id on the WRITE connection (READ_CON would give 0)
}

function bk_shop_item_delete($tourId, $itemId)
{
    bk_schema();
    $tourId = intval($tourId); $itemId = intval($itemId);
    if (!safe_fetch(safe_r_sql("SELECT SiId FROM BookingShopItems WHERE SiId = $itemId AND SiTournament = $tourId"))) return;
    safe_w_sql("DELETE FROM BookingShopVariants WHERE SvItem = $itemId");
    safe_w_sql("DELETE FROM BookingShopOrders WHERE SoItem = $itemId AND SoTournament = $tourId");
    safe_w_sql("DELETE FROM BookingShopItems WHERE SiId = $itemId AND SiTournament = $tourId");
}

function bk_shop_variant_upsert($itemId, $d)
{
    bk_schema();
    $itemId = intval($itemId);
    $set = "SvLabel = " . StrSafe_DB(mb_substr(trim((string) ($d['label'] ?? '')), 0, 80))
        . ", SvStock = " . max(0, intval($d['stock'] ?? 0))
        . ", SvOrder = " . max(0, intval($d['order'] ?? 0));
    $id = intval($d['id'] ?? 0);
    if ($id > 0) {
        safe_w_sql("UPDATE BookingShopVariants SET $set WHERE SvId = $id AND SvItem = $itemId");
        return $id;
    }
    safe_w_sql("INSERT INTO BookingShopVariants SET SvItem = $itemId, $set");
    return intval(safe_w_last_id());   // id on the WRITE connection (READ_CON would give 0)
}

function bk_shop_variant_delete($variantId)
{
    bk_schema();
    $variantId = intval($variantId);
    safe_w_sql("DELETE FROM BookingShopOrders WHERE SoVariant = $variantId");
    safe_w_sql("DELETE FROM BookingShopVariants WHERE SvId = $variantId");
}
