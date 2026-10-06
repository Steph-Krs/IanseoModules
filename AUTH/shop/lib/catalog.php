<?php
/**
 * lib/catalog.php — points of sale and catalogue, READ side. The organiser's editing lives in
 * lib/catalog-admin.php.
 *
 * Lists are returned in display order as plain lists (not keyed by id): a list keyed by
 * numbers becomes a JavaScript object whose keys are sorted, which loses the order.
 */

if (defined('SHP_CATALOG_LOADED')) return;
define('SHP_CATALOG_LOADED', true);

require_once __DIR__ . '/common.php';

/** Kinds of points of sale and their label. */
function shp_stand_kinds()
{
    return array('bar' => shp_t('ShKindBar'), 'food' => shp_t('ShKindFood'), 'shop' => shp_t('ShKindShop'));
}

/** Points of sale of a competition: [SdId => row], in display order. */
function shp_stands($tourId, $activeOnly = true)
{
    shp_schema();
    $out = array();
    $rs = safe_r_sql("SELECT * FROM ShopStands WHERE SdTournament = " . intval($tourId)
        . ($activeOnly ? " AND SdActive = 1" : "") . " ORDER BY SdOrder, SdId");
    while ($r = safe_fetch($rs)) $out[intval($r->SdId)] = $r;
    return $out;
}

/** One point of sale (row), or null. */
function shp_stand($standId)
{
    shp_schema();
    $r = safe_fetch(safe_r_sql("SELECT * FROM ShopStands WHERE SdId = " . intval($standId)));
    return $r ?: null;
}

/** One product (row), or null. */
function shp_product($productId)
{
    shp_schema();
    $r = safe_fetch(safe_r_sql("SELECT * FROM ShopProducts WHERE SpId = " . intval($productId)));
    return $r ?: null;
}

/** One variant (row), or null. */
function shp_variant($variantId)
{
    shp_schema();
    $r = safe_fetch(safe_r_sql("SELECT * FROM ShopVariants WHERE SwId = " . intval($variantId)));
    return $r ?: null;
}

/**
 * Catalogue of a competition (one stand, or all with $standId = 0). $context:
 *   'onsite'   products sold during the competition, active ones of active stands;
 *   'preorder' products that can be ordered beforehand, same filter;
 *   'all'      everything, inactive included (organiser).
 * Each product: id, stand, category, name, description, price, stock (remaining, null =
 * unlimited), alert, low, maxper, option, preorder, onsite, active, switch (the volunteers'
 * "available" switch), available (can be ordered now), variants (list: id, label, price, stock,
 * switch, available). A product with an option keeps its stock on its variants.
 */
function shp_catalog($tourId, $standId = 0, $context = 'onsite')
{
    shp_schema();
    $tourId = intval($tourId);
    $where = "SpTournament = $tourId";
    if (intval($standId) > 0) $where .= " AND SpStand = " . intval($standId);
    if ($context !== 'all') {
        $where .= " AND SpActive = 1 AND SdActive = 1";
        $where .= $context === 'preorder' ? " AND SpPreorder = 1" : " AND SpOnsite = 1";
    }
    $rs = safe_r_sql("SELECT ShopProducts.* FROM ShopProducts
        INNER JOIN ShopStands ON SdId = SpStand
        WHERE $where
        ORDER BY SdOrder, SdId, SpOrder, SpId");
    $products = array();
    while ($r = safe_fetch($rs)) {
        $stock = $r->SpStock === null ? null : intval($r->SpStock);
        $alert = $r->SpStockAlert === null ? null : intval($r->SpStockAlert);
        $products[intval($r->SpId)] = array(
            'id' => intval($r->SpId), 'stand' => intval($r->SpStand), 'category' => (string) $r->SpCategory,
            'name' => (string) $r->SpName, 'description' => (string) $r->SpDescription,
            'price' => (float) $r->SpPrice, 'stock' => $stock, 'alert' => $alert,
            'low' => $stock !== null && $alert !== null && $stock <= $alert,
            'maxper' => intval($r->SpMaxPer), 'option' => (string) $r->SpOptionName,
            'preorder' => intval($r->SpPreorder) === 1, 'onsite' => intval($r->SpOnsite) === 1,
            'active' => intval($r->SpActive) === 1, 'switch' => intval($r->SpAvailable) === 1,
            'available' => false, 'variants' => array(),
        );
    }
    if (!$products) return array();

    $rs = safe_r_sql("SELECT * FROM ShopVariants WHERE SwProduct IN (" . implode(',', array_keys($products)) . ")
        ORDER BY SwOrder, SwId");
    while ($r = safe_fetch($rs)) {
        $p = &$products[intval($r->SwProduct)];
        $stock = $r->SwStock === null ? null : intval($r->SwStock);
        $p['variants'][] = array(
            'id' => intval($r->SwId), 'label' => (string) $r->SwLabel,
            'price' => $r->SwPrice === null ? $p['price'] : (float) $r->SwPrice,
            'stock' => $stock, 'switch' => intval($r->SwAvailable) === 1,
            'available' => $p['switch'] && intval($r->SwAvailable) === 1 && ($stock === null || $stock > 0),
        );
        unset($p);
    }

    foreach ($products as &$p) {
        if ($p['option'] !== '') {
            $p['available'] = false;
            foreach ($p['variants'] as $v) if ($v['available']) { $p['available'] = true; break; }
        } else {
            $p['available'] = $p['switch'] && ($p['stock'] === null || $p['stock'] > 0);
        }
    }
    unset($p);
    return array_values($products);
}
