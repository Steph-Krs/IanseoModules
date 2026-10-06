<?php
/**
 * lib/stock.php — stock of the products (or of their variants), changed only by single
 * conditional statements: two phones selling the last sandwich at the same second cannot both
 * succeed.
 *
 * These functions never open a transaction themselves (a BEGIN inside a running transaction
 * commits it in MySQL): the order engine wraps them in its own. Reads that must see the
 * caller's uncommitted changes go through the WRITE connection.
 *
 * Only products with a limited stock have movements in ShopStockMoves; NULL means unlimited.
 */

if (defined('SHP_STOCK_LOADED')) return;
define('SHP_STOCK_LOADED', true);

require_once __DIR__ . '/common.php';

/** Records a stock movement (local time of the competition). */
function shp_stock_move($tourId, $productId, $variantId, $delta, $reason, $orderId = 0, $staffId = 0)
{
    $tourId = intval($tourId);
    if ($tourId <= 0) {
        $p = safe_fetch(safe_w_sql("SELECT SpTournament FROM ShopProducts WHERE SpId = " . intval($productId)));
        $tourId = $p ? intval($p->SpTournament) : 0;
    }
    if (!in_array($reason, array('order', 'cancel', 'restock', 'adjust', 'loss'), true)) $reason = 'adjust';
    safe_w_sql("INSERT INTO ShopStockMoves SET SmTournament = $tourId, SmProduct = " . intval($productId)
        . ", SmVariant = " . intval($variantId) . ", SmDelta = " . intval($delta)
        . ", SmReason = " . StrSafe_DB($reason) . ", SmOrder = " . intval($orderId)
        . ", SmStaff = " . intval($staffId) . ", SmWhen = " . shp_local_now_sql($tourId));
}

/** Remaining stock of a product or variant, read on the write connection (null = unlimited, false = unknown). */
function shp_stock_left($productId, $variantId)
{
    if (intval($variantId) > 0) {
        $r = safe_fetch(safe_w_sql("SELECT SwStock AS s FROM ShopVariants WHERE SwId = " . intval($variantId)
            . " AND SwProduct = " . intval($productId)));
    } else {
        $r = safe_fetch(safe_w_sql("SELECT SpStock AS s FROM ShopProducts WHERE SpId = " . intval($productId)));
    }
    if (!$r) return false;
    return $r->s === null ? null : intval($r->s);
}

/**
 * Takes $qty from the stock. True when done (or when the stock is unlimited), false when there
 * is not enough left. Records an 'order' movement for a limited stock.
 */
function shp_stock_reserve($productId, $variantId, $qty, $orderId = 0, $tourId = 0, $staffId = 0)
{
    $productId = intval($productId); $variantId = intval($variantId); $qty = intval($qty);
    if ($qty <= 0) return true;
    if ($variantId > 0) {
        safe_w_sql("UPDATE ShopVariants SET SwStock = SwStock - $qty
            WHERE SwId = $variantId AND SwProduct = $productId AND SwStock IS NOT NULL AND SwStock >= $qty");
    } else {
        safe_w_sql("UPDATE ShopProducts SET SpStock = SpStock - $qty
            WHERE SpId = $productId AND SpStock IS NOT NULL AND SpStock >= $qty");
    }
    if (safe_w_affected_rows() > 0) {
        shp_stock_move($tourId, $productId, $variantId, -$qty, 'order', $orderId, $staffId);
        return true;
    }
    return shp_stock_left($productId, $variantId) === null;
}

/** Gives $qty back to a limited stock (cancelled order or line). */
function shp_stock_release($productId, $variantId, $qty, $orderId = 0, $tourId = 0, $staffId = 0)
{
    $productId = intval($productId); $variantId = intval($variantId); $qty = intval($qty);
    if ($qty <= 0) return;
    if ($variantId > 0) {
        safe_w_sql("UPDATE ShopVariants SET SwStock = SwStock + $qty
            WHERE SwId = $variantId AND SwProduct = $productId AND SwStock IS NOT NULL");
    } else {
        safe_w_sql("UPDATE ShopProducts SET SpStock = SpStock + $qty WHERE SpId = $productId AND SpStock IS NOT NULL");
    }
    if (safe_w_affected_rows() > 0) shp_stock_move($tourId, $productId, $variantId, $qty, 'cancel', $orderId, $staffId);
}

/**
 * Relative change by a volunteer: $reason 'restock' (delivery, $delta > 0), 'loss' (broken,
 * thrown away, $delta < 0) or 'adjust'. A stock never goes below zero: such a change is refused
 * with what is left. Unlimited stock: nothing to change.
 * Returns ['error' => 0, 'stock' => new] or ['error' => 1, 'code', 'msg', 'stock' => left].
 */
function shp_stock_adjust($productId, $variantId, $delta, $reason, $staffId = 0)
{
    $productId = intval($productId); $variantId = intval($variantId); $delta = intval($delta);
    $left = shp_stock_left($productId, $variantId);
    if ($left === false) return array('error' => 1, 'code' => 'product', 'msg' => shp_t('ShErrProduct'), 'stock' => null);
    if ($left === null || $delta === 0) return array('error' => 0, 'stock' => $left);
    $col = $variantId > 0 ? 'SwStock' : 'SpStock';
    $where = $variantId > 0 ? "SwId = $variantId AND SwProduct = $productId" : "SpId = $productId";
    $table = $variantId > 0 ? 'ShopVariants' : 'ShopProducts';
    safe_w_sql("UPDATE $table SET $col = $col + ($delta) WHERE $where AND $col IS NOT NULL AND $col + ($delta) >= 0");
    if (safe_w_affected_rows() < 1) {
        $left = shp_stock_left($productId, $variantId);
        return array('error' => 1, 'code' => 'stock', 'msg' => shp_t('ShErrStockNegative', intval($left)), 'stock' => $left);
    }
    shp_stock_move(0, $productId, $variantId, $delta, $reason, 0, $staffId);
    return array('error' => 0, 'stock' => shp_stock_left($productId, $variantId));
}

/**
 * Sets the stock to an absolute quantity (null = unlimited), from the organiser's catalogue or
 * a volunteer's count. The movement records the difference; going from unlimited to N is
 * recorded as +N (the initial stock). Only applied if nobody changed the stock in between.
 */
function shp_stock_set($productId, $variantId, $qty, $staffId = 0)
{
    $productId = intval($productId); $variantId = intval($variantId);
    $new = ($qty === null || $qty === '') ? null : max(0, intval($qty));
    $old = shp_stock_left($productId, $variantId);
    if ($old === false) return false;
    if ($old === $new) return true;
    $col = $variantId > 0 ? 'SwStock' : 'SpStock';
    $where = $variantId > 0 ? "SwId = $variantId AND SwProduct = $productId" : "SpId = $productId";
    $table = $variantId > 0 ? 'ShopVariants' : 'ShopProducts';
    safe_w_sql("UPDATE $table SET $col = " . ($new === null ? 'NULL' : $new)
        . " WHERE $where AND $col " . ($old === null ? 'IS NULL' : "= $old"));
    if (safe_w_affected_rows() < 1) return false;
    if ($new !== null) shp_stock_move(0, $productId, $variantId, $new - intval($old), 'adjust', 0, $staffId);
    return true;
}

/** The volunteers' "sold out" switch of a product or variant. */
function shp_stock_set_available($productId, $variantId, $on)
{
    $on = $on ? 1 : 0;
    if (intval($variantId) > 0) {
        safe_w_sql("UPDATE ShopVariants SET SwAvailable = $on WHERE SwId = " . intval($variantId)
            . " AND SwProduct = " . intval($productId));
    } else {
        safe_w_sql("UPDATE ShopProducts SET SpAvailable = $on WHERE SpId = " . intval($productId));
    }
}
