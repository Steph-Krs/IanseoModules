<?php
/**
 * lib/catalog-admin.php — the organiser's WRITES on points of sale and catalogue (settings page
 * and catalogue page). The read side is lib/catalog.php.
 *
 * Every statement is bounded to the competition given by the caller (the open one): an id sent
 * by a browser is only ever used together with SdTournament / SpTournament. A form is validated
 * as a whole first and nothing is written when a field is wrong (the page shows the form again
 * with what was typed); only the deletions, which can be refused one by one, are reported apart.
 *
 * Stock: a limited stock is the quantity LEFT. It is only written through lib/stock.php, so that
 * every change leaves a movement. A form sends back the quantity it showed (stock0): a stock is
 * only touched when the organiser changed it — saving a page that was opened before some sales
 * must not put the old quantity back.
 */

if (defined('SHP_CATALOG_ADMIN_LOADED')) return;
define('SHP_CATALOG_ADMIN_LOADED', true);

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/stock.php';

/* ------------------------------------------------------------------ */
/* Reading what a form sends                                           */
/* ------------------------------------------------------------------ */

/** Amount typed by a person ("2,5", "1 250.00") as "2.50"; null when it is not an amount of 0 to 99999.99. */
function shp_amount_parse($s)
{
    $s = str_replace(array(' ', "\xC2\xA0", "'"), '', trim((string) $s));
    if ($s === '') return null;
    $c = strrpos($s, ','); $d = strrpos($s, '.');   // bytes: positions of ASCII separators
    if ($c !== false && $d !== false) {
        // both present: the last one is the decimal separator, the other one groups thousands
        $s = $c > $d ? str_replace('.', '', $s) : str_replace(',', '', $s);
    }
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $s)) return null;
    return number_format((float) $s, 2, '.', '');
}

/** Whole number typed in a field: '' → null (no value), a number inside [$min, $max] → int, anything else → false. */
function shp_int_parse($s, $min, $max)
{
    $s = trim((string) $s);
    if ($s === '') return null;
    if (!preg_match('/^\d{1,9}$/', $s)) return false;
    $n = intval($s);
    return ($n >= $min && $n <= $max) ? $n : false;
}

/** Text of a form, trimmed and cut to $max characters (not bytes: names have accents). */
function shp_text_clean($s, $max)
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $s);
    return mb_substr(trim($s), 0, $max);
}

/** Does a competition already have orders? (Then its catalogue is not replaced any more.) */
function shp_tour_has_orders($tourId)
{
    $r = safe_fetch(safe_w_sql("SELECT ShId FROM ShopOrders WHERE ShTournament = " . intval($tourId) . " LIMIT 1"));
    return (bool) $r;
}

/** Has a point of sale ever had an order? */
function shp_stand_has_orders($tourId, $standId)
{
    $r = safe_fetch(safe_w_sql("SELECT ShId FROM ShopOrders WHERE ShTournament = " . intval($tourId)
        . " AND ShStand = " . intval($standId) . " LIMIT 1"));
    return (bool) $r;
}

/* ------------------------------------------------------------------ */
/* Points of sale                                                      */
/* ------------------------------------------------------------------ */

function shp_stand_modes()
{
    return array('direct' => shp_t('ShSetModeDirect'), 'prep' => shp_t('ShSetModePrep'));
}

function shp_stand_paywhens()
{
    return array('order' => shp_t('ShSetPayOrder'), 'pickup' => shp_t('ShSetPayPickup'));
}

/** A free letter for the numbers of a new point of sale ('' when all 26 are used). */
function shp_stand_free_prefix($tourId, array $alsoTaken = array())
{
    $taken = array_map('strtoupper', $alsoTaken);   // bytes: single ASCII letters
    foreach (shp_stands($tourId, false) as $s) $taken[] = strtoupper((string) $s->SdPrefix);
    foreach (range('A', 'Z') as $l) if (!in_array($l, $taken, true)) return $l;
    return '';
}

/** A line of the points of sale form, with the defaults of a new one. Values stay as typed. */
function shp_stand_row_defaults(array $in = array())
{
    return array_merge(array('id' => 0, 'kind' => 'bar', 'name' => '', 'mode' => 'direct', 'paywhen' => 'order',
        'online' => 1, 'prepmin' => 3, 'parallel' => 1, 'prefix' => '', 'active' => 1), $in);
}

/**
 * Saves the points of sale form: $rows = list of lines (id, kind, name, mode, paywhen, online,
 * prepmin, parallel, prefix, active). Lines in display order. A new line without a name is
 * ignored. Returns ['errors' => [text…], 'saved' => n]; nothing is written when there is an error.
 */
function shp_stands_save($tourId, array $rows)
{
    $tourId = intval($tourId);
    $db = shp_stands($tourId, false);
    $errors = array();
    $clean = array();
    $seen = array();
    $n = 0;
    foreach ($rows as $in) {
        if (!is_array($in)) continue;
        $id = intval($in['id'] ?? 0);
        if ($id > 0 && !isset($db[$id])) continue;           // not a point of sale of this competition
        $name = shp_text_clean($in['name'] ?? '', 60);
        if ($id === 0 && $name === '') continue;            // empty new line
        $n++;
        $who = $name !== '' ? $name : shp_t('ShSetStandN', $n);
        if ($id > 0 && isset($seen[$id])) continue;
        $seen[$id] = true;

        if ($name === '') $errors[] = shp_t('ShSetErrStandName', $n);
        $kind = (string) ($in['kind'] ?? '');
        if (!array_key_exists($kind, shp_stand_kinds())) { $errors[] = shp_t('ShSetErrStandKind', $who); $kind = 'bar'; }
        $mode = (string) ($in['mode'] ?? '');
        if (!array_key_exists($mode, shp_stand_modes())) { $errors[] = shp_t('ShSetErrStandMode', $who); $mode = 'direct'; }
        $paywhen = (string) ($in['paywhen'] ?? '');
        if (!array_key_exists($paywhen, shp_stand_paywhens())) { $errors[] = shp_t('ShSetErrStandPay', $who); $paywhen = 'order'; }
        $prepmin = shp_int_parse($in['prepmin'] ?? '', 0, 240);
        if ($prepmin === false || $prepmin === null) { $errors[] = shp_t('ShSetErrStandPrep', $who); $prepmin = 3; }
        $parallel = shp_int_parse($in['parallel'] ?? '', 1, 20);
        if ($parallel === false || $parallel === null) { $errors[] = shp_t('ShSetErrStandParallel', $who); $parallel = 1; }
        $prefix = strtoupper(trim((string) ($in['prefix'] ?? '')));   // bytes: one ASCII letter, checked next
        if (!preg_match('/^[A-Z]$/', $prefix)) { $errors[] = shp_t('ShSetErrStandPrefix', $who); $prefix = ''; }

        $clean[] = array('id' => $id, 'kind' => $kind, 'name' => $name, 'mode' => $mode, 'paywhen' => $paywhen,
            'online' => empty($in['online']) ? 0 : 1, 'prepmin' => $prepmin, 'parallel' => $parallel,
            'prefix' => $prefix, 'active' => empty($in['active']) ? 0 : 1, 'who' => $who);
    }

    // One letter per point of sale, over the whole competition (inactive ones included):
    // the order numbers (A-042) must stay unambiguous. (bytes: the letters are single ASCII characters.)
    $letters = array();
    foreach ($db as $sid => $s) if (!isset($seen[$sid])) $letters[strtoupper((string) $s->SdPrefix)] = '';
    foreach ($clean as $c) {
        if ($c['prefix'] === '') continue;
        if (isset($letters[$c['prefix']])) $errors[] = shp_t('ShSetErrStandPrefixTwice', $c['prefix']);
        $letters[$c['prefix']] = $c['who'];
        // The letter of a point of sale that already had orders stays: their numbers carry it (bytes: ASCII letter).
        if ($c['id'] > 0 && strtoupper((string) $db[$c['id']]->SdPrefix) !== $c['prefix'] && shp_stand_has_orders($tourId, $c['id'])) {
            $errors[] = shp_t('ShSetErrStandPrefixUsed', $c['who']);
        }
    }
    if ($errors) return array('errors' => array_values(array_unique($errors)), 'saved' => 0);

    $order = 0;
    foreach ($clean as $c) {
        $set = "SdKind = " . StrSafe_DB($c['kind']) . ", SdName = " . StrSafe_DB($c['name'])
            . ", SdMode = " . StrSafe_DB($c['mode']) . ", SdPayWhen = " . StrSafe_DB($c['paywhen'])
            . ", SdOnline = " . $c['online'] . ", SdPrepMin = " . $c['prepmin'] . ", SdParallel = " . $c['parallel']
            . ", SdPrefix = " . StrSafe_DB($c['prefix']) . ", SdOrder = " . $order++ . ", SdActive = " . $c['active'];
        if ($c['id'] > 0) {
            safe_w_sql("UPDATE ShopStands SET $set WHERE SdId = " . $c['id'] . " AND SdTournament = $tourId");
        } else {
            safe_w_sql("INSERT INTO ShopStands SET SdTournament = $tourId, SdOpen = 0, SdNextNo = 0, $set");
        }
    }
    return array('errors' => array(), 'saved' => count($clean));
}

/** Deletes a point of sale with its catalogue — refused when it ever had an order. ['error', 'msg']. */
function shp_stand_delete($tourId, $standId)
{
    $tourId = intval($tourId); $standId = intval($standId);
    $s = safe_fetch(safe_w_sql("SELECT SdName FROM ShopStands WHERE SdId = $standId AND SdTournament = $tourId"));
    if (!$s) return array('error' => 1, 'msg' => shp_t('ShErrStand'));
    if (shp_stand_has_orders($tourId, $standId)) {
        return array('error' => 1, 'msg' => shp_t('ShSetErrStandDelOrders', $s->SdName));
    }
    shp_products_purge($tourId, "SpStand = $standId");
    safe_w_sql("DELETE FROM ShopStaffStands WHERE StStand = $standId");
    safe_w_sql("DELETE FROM ShopStands WHERE SdId = $standId AND SdTournament = $tourId");
    return array('error' => 0, 'msg' => shp_t('ShSetStandDeleted', $s->SdName));
}

/** Removes products (and variants, stock movements) matching $where — callers have checked there are no orders. */
function shp_products_purge($tourId, $where)
{
    $tourId = intval($tourId);
    $ids = array();
    $rs = safe_w_sql("SELECT SpId FROM ShopProducts WHERE SpTournament = $tourId AND ($where)");
    while ($r = safe_fetch($rs)) $ids[] = intval($r->SpId);
    if (!$ids) return;
    $in = implode(',', $ids);
    safe_w_sql("DELETE FROM ShopVariants WHERE SwProduct IN ($in)");
    safe_w_sql("DELETE FROM ShopStockMoves WHERE SmTournament = $tourId AND SmProduct IN ($in)");
    safe_w_sql("DELETE FROM ShopProducts WHERE SpTournament = $tourId AND SpId IN ($in)");
}

/* ------------------------------------------------------------------ */
/* Catalogue                                                           */
/* ------------------------------------------------------------------ */

/**
 * What came in, went out and was lost for every limited stock of a competition, from the
 * movements: ['p<id>' or 'v<id>' => ['in' => entered (and corrected), 'sold' => sold net of
 * cancellations, 'lost' => thrown away]]. in − sold − lost = what is left.
 */
function shp_stock_stats($tourId)
{
    $out = array();
    $rs = safe_r_sql("SELECT SmProduct, SmVariant, SmReason, SUM(SmDelta) AS D FROM ShopStockMoves
        WHERE SmTournament = " . intval($tourId) . " GROUP BY SmProduct, SmVariant, SmReason");
    while ($r = safe_fetch($rs)) {
        $k = intval($r->SmVariant) > 0 ? 'v' . intval($r->SmVariant) : 'p' . intval($r->SmProduct);
        if (!isset($out[$k])) $out[$k] = array('in' => 0, 'sold' => 0, 'lost' => 0);
        $d = intval($r->D);
        if ($r->SmReason === 'order' || $r->SmReason === 'cancel') $out[$k]['sold'] -= $d;
        elseif ($r->SmReason === 'loss') $out[$k]['lost'] -= $d;
        else $out[$k]['in'] += $d;
    }
    return $out;
}

/** A product line of the catalogue form, with the defaults of a new one. Values stay as typed. */
function shp_product_row_defaults(array $in = array())
{
    return array_merge(array('id' => 0, 'category' => '', 'name' => '', 'description' => '', 'price' => '',
        'stock' => '', 'stock0' => '', 'alert' => '', 'maxper' => 0, 'option' => '', 'preorder' => 0,
        'onsite' => 1, 'active' => 1, 'variants' => array()), $in);
}

/** A variant line of the catalogue form. */
function shp_variant_row_defaults(array $in = array())
{
    return array_merge(array('id' => 0, 'label' => '', 'price' => '', 'stock' => '', 'stock0' => ''), $in);
}

/** Stock typed in a field as null (unlimited) or int; false when it is not a quantity. */
function shp_stock_parse($s)
{
    return shp_int_parse($s, 0, 1000000);
}

/**
 * Saves the catalogue form of ONE point of sale. $rows: lines in display order, each with the
 * fields of shp_product_row_defaults() plus 'del' (1 = delete) and 'variants' (lines of
 * shp_variant_row_defaults() plus 'del'). Returns ['errors' => [...], 'notes' => [...], 'saved' => n]:
 * errors → nothing was written; notes → deletions refused or done, and other remarks.
 */
function shp_products_save($tourId, $standId, array $rows)
{
    $tourId = intval($tourId); $standId = intval($standId);
    $res = array('errors' => array(), 'notes' => array(), 'saved' => 0);
    if (!safe_fetch(safe_w_sql("SELECT SdId FROM ShopStands WHERE SdId = $standId AND SdTournament = $tourId"))) {
        $res['errors'][] = shp_t('ShErrStand');
        return $res;
    }
    $mine = array();      // products of this point of sale: id => row
    $rs = safe_w_sql("SELECT * FROM ShopProducts WHERE SpTournament = $tourId AND SpStand = $standId");
    while ($r = safe_fetch($rs)) $mine[intval($r->SpId)] = $r;

    $clean = array();
    $seen = array();
    $n = 0;
    foreach ($rows as $in) {
        if (!is_array($in)) continue;
        $id = intval($in['id'] ?? 0);
        if ($id > 0 && !isset($mine[$id])) continue;         // not a product of this point of sale
        if ($id > 0 && isset($seen[$id])) continue;
        if ($id > 0) $seen[$id] = true;
        if (!empty($in['del'])) {
            if ($id > 0) $clean[] = array('id' => $id, 'del' => true, 'who' => (string) $mine[$id]->SpName);
            continue;
        }
        $name = shp_text_clean($in['name'] ?? '', 120);
        $price = shp_amount_parse($in['price'] ?? '');
        $blank = $name === '' && trim((string) ($in['price'] ?? '')) === '' && trim((string) ($in['description'] ?? '')) === '';
        if ($id === 0 && $blank) continue;                   // empty new line
        $n++;
        $who = $name !== '' ? $name : shp_t('ShSetProductN', $n);

        if ($name === '') $res['errors'][] = shp_t('ShSetErrProdName', $n);
        if ($price === null) $res['errors'][] = shp_t('ShSetErrProdPrice', $who);
        $option = shp_text_clean($in['option'] ?? '', 40);
        $stock = shp_stock_parse($in['stock'] ?? '');
        if ($stock === false) { $res['errors'][] = shp_t('ShSetErrProdStock', $who); $stock = null; }
        $alert = shp_stock_parse($in['alert'] ?? '');
        if ($alert === false) { $res['errors'][] = shp_t('ShSetErrProdAlert', $who); $alert = null; }
        $maxper = shp_int_parse($in['maxper'] ?? 0, 0, 999);
        if ($maxper === false) { $res['errors'][] = shp_t('ShSetErrProdMax', $who); $maxper = 0; }
        $stock0 = shp_stock_parse($in['stock0'] ?? '');
        if ($stock0 === false) $stock0 = null;

        $p = array('id' => $id, 'del' => false, 'who' => $who, 'category' => shp_text_clean($in['category'] ?? '', 60),
            'name' => $name, 'description' => shp_text_clean($in['description'] ?? '', 255),
            'price' => $price === null ? '0.00' : $price, 'stock' => $option !== '' ? null : $stock, 'stock0' => $stock0,
            'alert' => $alert, 'maxper' => intval($maxper), 'option' => $option,
            'preorder' => empty($in['preorder']) ? 0 : 1, 'onsite' => empty($in['onsite']) ? 0 : 1,
            'active' => empty($in['active']) ? 0 : 1, 'variants' => array());

        if ($option !== '') {
            $vseen = array();
            $vn = 0;
            $vdb = array();
            if ($id > 0) {
                $rv = safe_w_sql("SELECT * FROM ShopVariants WHERE SwProduct = $id");
                while ($v = safe_fetch($rv)) $vdb[intval($v->SwId)] = $v;
            }
            foreach ((array) ($in['variants'] ?? array()) as $vin) {
                if (!is_array($vin)) continue;
                $vid = intval($vin['id'] ?? 0);
                if ($vid > 0 && !isset($vdb[$vid])) continue;
                if ($vid > 0 && isset($vseen[$vid])) continue;
                if ($vid > 0) $vseen[$vid] = true;
                if (!empty($vin['del'])) {
                    if ($vid > 0) $p['variants'][] = array('id' => $vid, 'del' => true, 'label' => (string) $vdb[$vid]->SwLabel);
                    continue;
                }
                $label = shp_text_clean($vin['label'] ?? '', 80);
                if ($vid === 0 && $label === '' && trim((string) ($vin['price'] ?? '')) === '') continue;
                $vn++;
                if ($label === '') $res['errors'][] = shp_t('ShSetErrVarLabel', array('p' => $who, 'n' => $vn));
                $vprice = null;
                if (trim((string) ($vin['price'] ?? '')) !== '') {
                    $vprice = shp_amount_parse($vin['price']);
                    if ($vprice === null) $res['errors'][] = shp_t('ShSetErrVarPrice', array('p' => $who, 'v' => $label !== '' ? $label : $vn));
                }
                $vstock = shp_stock_parse($vin['stock'] ?? '');
                if ($vstock === false) { $res['errors'][] = shp_t('ShSetErrVarStock', array('p' => $who, 'v' => $label !== '' ? $label : $vn)); $vstock = null; }
                $vstock0 = shp_stock_parse($vin['stock0'] ?? '');
                $p['variants'][] = array('id' => $vid, 'del' => false, 'label' => $label, 'price' => $vprice,
                    'stock' => $vstock, 'stock0' => $vstock0 === false ? null : $vstock0);
            }
            $live = 0;
            foreach ($p['variants'] as $v) if (empty($v['del'])) $live++;
            if ($live === 0) $res['errors'][] = shp_t('ShSetErrProdNoVariant', $who);
        }
        $clean[] = $p;
    }
    if ($res['errors']) { $res['errors'] = array_values(array_unique($res['errors'])); return $res; }

    $order = 0;
    foreach ($clean as $p) {
        if ($p['del']) {
            $r = shp_product_delete($tourId, $standId, $p['id']);
            $res['notes'][] = $r['msg'];
            continue;
        }
        // A variant already ordered cannot be orphaned by clearing the option name: it stays.
        $optName = $p['option'];
        if ($p['id'] > 0 && $optName === '' && (string) $mine[$p['id']]->SpOptionName !== '' && shp_product_variants_ordered($p['id'])) {
            $optName = (string) $mine[$p['id']]->SpOptionName;
            $res['notes'][] = shp_t('ShSetNoteOptionKept', $p['who']);
        }
        $set = "SpCategory = " . StrSafe_DB($p['category']) . ", SpName = " . StrSafe_DB($p['name'])
            . ", SpDescription = " . StrSafe_DB($p['description']) . ", SpPrice = " . StrSafe_DB($p['price'])
            . ", SpStockAlert = " . ($p['alert'] === null ? 'NULL' : $p['alert']) . ", SpMaxPer = " . $p['maxper']
            . ", SpOptionName = " . StrSafe_DB($optName) . ", SpPreorder = " . $p['preorder']
            . ", SpOnsite = " . $p['onsite'] . ", SpActive = " . $p['active'] . ", SpOrder = " . $order++
            . ", SpUpdated = " . shp_local_now_sql($tourId);
        if ($p['id'] > 0) {
            safe_w_sql("UPDATE ShopProducts SET $set WHERE SpId = " . $p['id'] . " AND SpTournament = $tourId AND SpStand = $standId");
            $pid = $p['id'];
        } else {
            safe_w_sql("INSERT INTO ShopProducts SET SpTournament = $tourId, SpStand = $standId, SpStock = NULL, SpAvailable = 1, $set");
            $pid = intval(safe_w_last_id());
        }
        // Stock: only when the organiser changed it (or for a new product). A product with
        // options keeps its stock on the variants.
        if ($optName !== '') {
            if ($p['id'] > 0) shp_stock_set($pid, 0, null);
        } elseif ($p['id'] === 0 || $p['stock'] !== $p['stock0']) {
            if (!shp_stock_set($pid, 0, $p['stock'], 0)) $res['notes'][] = shp_t('ShSetNoteStockBusy', $p['who']);
        }
        $vo = 0;
        foreach ($p['variants'] as $v) {
            if (!empty($v['del'])) {
                if (shp_variant_ordered($v['id'])) {
                    $res['notes'][] = shp_t('ShSetNoteVarOrdered', array('p' => $p['who'], 'v' => $v['label']));
                } else {
                    safe_w_sql("DELETE FROM ShopStockMoves WHERE SmTournament = $tourId AND SmVariant = " . $v['id']);
                    safe_w_sql("DELETE FROM ShopVariants WHERE SwId = " . $v['id'] . " AND SwProduct = $pid");
                }
                continue;
            }
            $vset = "SwLabel = " . StrSafe_DB($v['label']) . ", SwPrice = " . ($v['price'] === null ? 'NULL' : StrSafe_DB($v['price']))
                . ", SwOrder = " . $vo++;
            if ($v['id'] > 0) {
                safe_w_sql("UPDATE ShopVariants SET $vset WHERE SwId = " . $v['id'] . " AND SwProduct = $pid");
                $vid = $v['id'];
            } else {
                safe_w_sql("INSERT INTO ShopVariants SET SwProduct = $pid, SwStock = NULL, SwAvailable = 1, $vset");
                $vid = intval(safe_w_last_id());
            }
            if ($v['id'] === 0 || $v['stock'] !== $v['stock0']) {
                if (!shp_stock_set($pid, $vid, $v['stock'], 0)) $res['notes'][] = shp_t('ShSetNoteStockBusy', $p['who'] . ' / ' . $v['label']);
            }
        }
        // A product without option keeps no variant (those not ordered are removed).
        if ($optName === '' && $p['id'] > 0) {
            $rv = safe_w_sql("SELECT SwId FROM ShopVariants WHERE SwProduct = $pid");
            while ($x = safe_fetch($rv)) {
                if (shp_variant_ordered($x->SwId)) continue;
                safe_w_sql("DELETE FROM ShopStockMoves WHERE SmTournament = $tourId AND SmVariant = " . intval($x->SwId));
                safe_w_sql("DELETE FROM ShopVariants WHERE SwId = " . intval($x->SwId) . " AND SwProduct = $pid");
            }
        }
        $res['saved']++;
    }
    return $res;
}

/** Has a line of an order ever used this variant? */
function shp_variant_ordered($variantId)
{
    return (bool) safe_fetch(safe_w_sql("SELECT SnId FROM ShopOrderLines WHERE SnVariant = " . intval($variantId) . " LIMIT 1"));
}

/** Has a line of an order ever used a variant of this product? */
function shp_product_variants_ordered($productId)
{
    return (bool) safe_fetch(safe_w_sql("SELECT SnId FROM ShopOrderLines WHERE SnProduct = " . intval($productId) . " AND SnVariant > 0 LIMIT 1"));
}

/** Deletes a product (and its variants) unless it was ever ordered: then the organiser deactivates it. ['error', 'msg']. */
function shp_product_delete($tourId, $standId, $productId)
{
    $tourId = intval($tourId); $standId = intval($standId); $productId = intval($productId);
    $p = safe_fetch(safe_w_sql("SELECT SpName FROM ShopProducts WHERE SpId = $productId AND SpTournament = $tourId AND SpStand = $standId"));
    if (!$p) return array('error' => 1, 'msg' => shp_t('ShErrProduct'));
    if (safe_fetch(safe_w_sql("SELECT SnId FROM ShopOrderLines WHERE SnProduct = $productId LIMIT 1"))) {
        return array('error' => 1, 'msg' => shp_t('ShSetNoteProdOrdered', $p->SpName));
    }
    shp_products_purge($tourId, "SpId = $productId AND SpStand = $standId");
    return array('error' => 0, 'msg' => shp_t('ShSetProdDeleted', $p->SpName));
}
