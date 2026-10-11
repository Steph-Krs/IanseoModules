<?php
/**
 * admin/catalog.php — catalogue and stock of the points of sale of the open competition: one
 * form per point of sale, one card per product, options (variants) with their own price and
 * stock. Deleting something already ordered is refused (deactivate it instead).
 *
 * ORGANISER page (computer): ianseo look and ACL of the core, frame shared by AUTH's organiser
 * pages (adm-lib.php). "Add a product" comes after the products; the save button is fixed at the
 * bottom right.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/catalog-admin.php';
require_once dirname(__DIR__) . '/lib/admin-ui.php';

shp_schema();

$TOUR = intval($_SESSION['TourId']);
$cur = bk_currency($TOUR);
$stands = shp_stands($TOUR, false);

$standId = intval($_GET['s'] ?? $_POST['stand_id'] ?? 0);
if (!isset($stands[$standId])) $standId = $stands ? intval(array_key_first($stands)) : 0;
$SELF = shp_adm_url('catalog.php') . ($standId > 0 ? '?s=' . $standId : '');

$errors = array();
$productRows = null;      // lines as typed, when the form was refused

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $standId > 0) {
    if (!bk_csrf_check()) {
        $errors[] = shp_t('ShSetErrSession');
    } elseif (!isset($_POST['form_end'])) {
        // PHP drops the fields beyond max_input_vars without a word: the last products would be lost.
        $errors[] = shp_t('ShSetErrTruncated');
    } else {
        $productRows = (array) ($_POST['p'] ?? array());
        $r = shp_products_save($TOUR, $standId, $productRows);
        if ($r['errors']) {
            $errors = $r['errors'];
        } else {
            shp_adm_flash_set($r['notes'] ? 'warn' : 'ok', array_merge(array(shp_t('ShSetCatSaved')), $r['notes']));
            header('Location: ' . $SELF);
            exit;
        }
    }
}

$flash = shp_adm_flash_take();
$started = shp_tour_has_orders($TOUR);
$stats = $started ? shp_stock_stats($TOUR) : array();

/** Text under a stock field: what came in, went out and was lost (once orders exist). */
function shp_adm_stock_stats($key, $stats)
{
    if (!isset($stats[$key])) return '';
    $s = $stats[$key];
    return '<small class="sa-stats">' . shp_e(shp_t('ShSetStockStats', array('in' => $s['in'], 'sold' => $s['sold'], 'lost' => $s['lost']))) . '</small>';
}

/** One variant line (server rendering AND script template, indexes __i__ / __v__). */
function shp_adm_var_row($i, $vi, array $v, $stats)
{
    $v = array_merge(shp_variant_row_defaults($v), array('del' => $v['del'] ?? 0));
    $n = 'p[' . $i . '][variants][' . $vi . ']';
    $id = intval($v['id']);
    return '<div class="sa-var" data-var' . (empty($v['del']) ? '' : ' hidden') . '><input type="hidden" name="' . $n . '[id]" value="' . $id . '">'
        . '<input type="hidden" name="' . $n . '[stock0]" value="' . shp_e($v['stock0']) . '">'
        . '<input type="hidden" name="' . $n . '[del]" value="' . (empty($v['del']) ? 0 : 1) . '" data-del>'
        . '<label><span>' . shp_e(shp_t('ShSetFVarLabel')) . '</span><input type="text" name="' . $n . '[label]" maxlength="80" value="' . shp_e($v['label']) . '"></label>'
        . '<label><span>' . shp_e(shp_t('ShSetFVarPrice')) . '</span><input type="text" name="' . $n . '[price]" size="6" value="' . shp_e($v['price']) . '" placeholder="' . shp_e(shp_t('ShSetFVarPricePh')) . '"></label>'
        . '<label><span>' . shp_e(shp_t('ShSetFStock')) . '</span><span class="sa-inl"><input type="number" min="0" name="' . $n . '[stock]" class="sa-num" value="' . shp_e($v['stock']) . '" placeholder="' . shp_e(shp_t('ShSetUnlimited')) . '">'
        . ($id > 0 ? shp_adm_stock_stats('v' . $id, $stats) : '') . '</span></label>'
        . '<button type="button" class="sa-btn sa-danger" data-mark-del="[data-var]" aria-label="' . shp_e(shp_t('ShSetVarDelete')) . '">' . shp_e(shp_t('ShSetVarDelete')) . '</button></div>';
}

/**
 * One product card (server rendering AND script template, index __i__). $labels: texts of the two
 * sales boxes, which name the pre-order deadline of the settings (shp_adm_sale_labels).
 */
function shp_adm_product_card($i, array $p, $stats, $cur, array $labels)
{
    $p = shp_product_row_defaults($p);
    $n = 'p[' . $i . ']';
    $id = intval($p['id']);
    $hasOpt = trim((string) $p['option']) !== '';
    $isDel = !empty($p['del']);
    $vars = '';
    $vi = 0;
    foreach ((array) $p['variants'] as $v) {
        if (!is_array($v)) continue;
        $vars .= shp_adm_var_row($i, 'v' . $vi++, $v, $stats);
    }
    $f = function ($label, $input, $cls = '') {
        return '<label class="sa-f ' . $cls . '"><span>' . shp_e($label) . '</span>' . $input . '</label>';
    };
    $chk = function ($name, $on, $label) use ($n) {
        return '<label class="sa-chk"><input type="checkbox" name="' . $n . '[' . $name . ']" value="1"' . ($on ? ' checked' : '') . '> <span>' . shp_e($label) . '</span></label>';
    };
    return '<div class="sa-prod" data-row data-prod' . ($hasOpt ? ' data-has-option' : '') . ($isDel ? ' hidden' : '') . '>'
        . '<input type="hidden" name="' . $n . '[id]" value="' . $id . '">'
        . '<input type="hidden" name="' . $n . '[stock0]" value="' . shp_e($p['stock0']) . '">'
        . '<input type="hidden" name="' . $n . '[del]" value="' . ($isDel ? 1 : 0) . '" data-del>'
        . '<div class="sa-row">'
        . $f(shp_t('ShSetFCategory'), '<input type="text" list="sa-cats" name="' . $n . '[category]" maxlength="60" value="' . shp_e($p['category']) . '" placeholder="' . shp_e(shp_t('ShSetFCategoryPh')) . '">')
        . $f(shp_t('ShSetFName'), '<input type="text" name="' . $n . '[name]" maxlength="120" value="' . shp_e($p['name']) . '" placeholder="' . shp_e(shp_t('ShSetFNamePh')) . '">', 'sa-grow')
        . $f(shp_t('ShSetFPrice', $cur), '<input type="text" name="' . $n . '[price]" size="7" value="' . shp_e($p['price']) . '">')
        . '</div>'
        . '<div class="sa-row">' . $f(shp_t('ShSetFDescription'), '<input type="text" name="' . $n . '[description]" maxlength="255" value="' . shp_e($p['description']) . '">', 'sa-grow') . '</div>'
        . '<div class="sa-row">'
        . '<label class="sa-f" data-simple-stock' . ($hasOpt ? ' hidden' : '') . '><span>' . shp_e(shp_t('ShSetFStock')) . '</span>'
        . '<span class="sa-inl"><input type="number" min="0" name="' . $n . '[stock]" class="sa-num" value="' . shp_e($p['stock']) . '" placeholder="' . shp_e(shp_t('ShSetUnlimited')) . '">'
        . ($id > 0 ? shp_adm_stock_stats('p' . $id, $stats) : '') . '</span></label>'
        . $f(shp_t('ShSetFAlert'), '<input type="number" min="0" name="' . $n . '[alert]" class="sa-num" value="' . shp_e($p['alert']) . '">')
        . $f(shp_t('ShSetFMaxPer'), '<input type="number" min="0" max="999" name="' . $n . '[maxper]" class="sa-num" value="' . intval($p['maxper']) . '">')
        . '<div class="sa-checks">' . $chk('preorder', $p['preorder'], $labels['preorder']) . $chk('onsite', $p['onsite'], $labels['onsite'])
        . $chk('active', $p['active'], shp_t('ShSetFActive')) . '</div>'
        . '</div>'
        . '<div class="sa-row">' . $f(shp_t('ShSetFOption'), '<input type="text" name="' . $n . '[option]" maxlength="40" value="' . shp_e($p['option']) . '" data-option placeholder="' . shp_e(shp_t('ShSetFOptionPh')) . '">', 'sa-grow') . '</div>'
        . '<div class="sa-vars" data-vars' . ($hasOpt ? '' : ' hidden') . '><div class="sa-vhead">' . shp_e(shp_t('ShSetFVariants')) . '</div>'
        . '<div data-var-list data-next="' . $vi . '">' . $vars . '</div>'
        . '<button type="button" class="sa-btn" data-add-var>' . shp_e(shp_t('ShSetAddVariant')) . '</button></div>'
        . '<div class="sa-foot"><span><button type="button" class="sa-btn" data-move="-1">' . shp_e(shp_t('ShSetUp')) . '</button> '
        . '<button type="button" class="sa-btn" data-move="1">' . shp_e(shp_t('ShSetDown')) . '</button> '
        . '<button type="button" class="sa-btn" data-dup>' . shp_e(shp_t('ShSetDuplicate')) . '</button></span>'
        . '<button type="button" class="sa-btn sa-danger" data-mark-del="[data-prod]"'
        . ($id > 0 ? ' data-confirm="' . shp_e(shp_t('ShSetProdDeleteConfirm')) . '"' : '') . '>' . shp_e(shp_t('ShSetDelete')) . '</button></div></div>';
}

/**
 * Texts of the two sales boxes of a product. Pre-ordered: before the competition, once the
 * pre-orders are opened in the settings, until their deadline — or the eve of the competition
 * without one. Sold during the competition: at the stands, and from the customers' phones.
 */
function shp_adm_sale_labels($tourId)
{
    $set = shp_settings($tourId);
    $until = $set ? trim((string) $set->SgPreorderUntil) : '';
    if (!shp_preorders_on($tourId)) {
        $pre = shp_t('ShSetFPreorderNone');
    } elseif ($until !== '' && strpos($until, '0000') !== 0) {
        // bytes: an ASCII DATETIME
        $pre = shp_t('ShSetFPreorder', shp_adm_date(substr($until, 0, 10)) . ' ' . substr($until, 11, 5));
    } else {
        $pre = shp_t('ShSetFPreorderEve');
    }
    return array('preorder' => $pre, 'onsite' => shp_t('ShSetFOnsite'));
}

/* ---------------- Page content ---------------- */

$saleLabels = shp_adm_sale_labels($TOUR);

$tabs = '';
foreach ($stands as $sid => $s) {
    $tabs .= '<a href="' . shp_e(shp_adm_url('catalog.php') . '?s=' . $sid) . '"' . ($sid === $standId ? ' class="on"' : '') . '>'
        . shp_e($s->SdName) . (intval($s->SdActive) ? '' : ' ' . shp_t('ShSetInactiveTag')) . '</a>';
}

$body = '';
if (!$stands) {
    $body = '<p class="sa-msg sa-info">' . shp_e(shp_t('ShSetCatNoStand')) . ' <a href="' . shp_e(shp_adm_url('index.php')) . '#stands">'
        . shp_e(shp_t('MnuSettings')) . '</a></p>';
} else {
    $cards = '';
    $i = 0;
    if ($productRows !== null) {
        foreach ($productRows as $r) {
            if (!is_array($r)) continue;
            $cards .= shp_adm_product_card($i++, $r, $stats, $cur, $saleLabels);
        }
    } else {
        foreach (shp_catalog($TOUR, $standId, 'all') as $p) {
            // A variant shows its own price only when it has one (empty = the product's price).
            $own = array();
            $rv = safe_r_sql("SELECT SwId, SwPrice FROM ShopVariants WHERE SwProduct = " . intval($p['id']));
            while ($x = safe_fetch($rv)) $own[intval($x->SwId)] = $x->SwPrice;
            $vs = array();
            foreach ($p['variants'] as $v) {
                $left = $v['stock'] === null ? '' : $v['stock'];
                $vs[] = array('id' => $v['id'], 'label' => $v['label'], 'price' => shp_adm_amount($own[$v['id']] ?? null),
                    'stock' => $left, 'stock0' => $left);
            }
            $cards .= shp_adm_product_card($i++, array('id' => $p['id'], 'category' => $p['category'], 'name' => $p['name'],
                'description' => $p['description'], 'price' => shp_adm_amount($p['price']),
                'stock' => $p['stock'] === null ? '' : $p['stock'], 'stock0' => $p['stock'] === null ? '' : $p['stock'],
                'alert' => $p['alert'] === null ? '' : $p['alert'], 'maxper' => $p['maxper'], 'option' => $p['option'],
                'preorder' => $p['preorder'], 'onsite' => $p['onsite'], 'active' => $p['active'], 'variants' => $vs), $stats, $cur, $saleLabels);
        }
    }
    $cats = '';
    $rs = safe_r_sql("SELECT DISTINCT SpCategory FROM ShopProducts WHERE SpTournament = $TOUR AND SpCategory <> '' ORDER BY SpCategory");
    while ($r = safe_fetch($rs)) $cats .= '<option value="' . shp_e($r->SpCategory) . '">';
    $body = '<form method="post" id="catalog" data-product-form>' . aut_adm_default_submit() . bk_csrf_field()
        . '<input type="hidden" name="stand_id" value="' . $standId . '">'
        . '<div id="prod-list" data-next="' . $i . '">' . $cards . '</div>'
        . '<div class="sa-bar"><button type="button" class="sa-btn" data-add="prod-tpl" data-into="prod-list">' . shp_e(shp_t('ShSetAddProduct')) . '</button></div>'
        . '<datalist id="sa-cats">' . $cats . '</datalist>'
        . '<template id="prod-tpl">' . shp_adm_product_card('__i__', array(), $stats, $cur, $saleLabels) . '</template>'
        . '<template id="var-tpl">' . shp_adm_var_row('__i__', '__v__', array(), $stats) . '</template>'
        . '<input type="hidden" name="form_end" value="1"></form>'
        . aut_adm_save('catalog');
}

$PAGE_TITLE = shp_t('MnuCatalog') . ' — ' . shp_t('MnuTitle');
$JS_SCRIPT = shp_adm_assets();
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

echo aut_adm_open('shop/admin/catalog.php', shp_t('ShSetCatTitle'), '', '', shp_e(shp_t('ShSetCatLead')) . '<br>' . shp_e(shp_t('ShSetCatHint')))
    . ($flash ? shp_adm_msg($flash['type'], $flash['lines']) : '')
    . ($errors ? shp_adm_msg('err', array_merge(array(shp_t('ShSetNothingSaved')), $errors)) : '')
    . ($tabs !== '' ? '<div class="sa-tabs">' . $tabs . '</div>' : '') . $body . aut_adm_close();

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
