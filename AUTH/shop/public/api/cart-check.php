<?php
/**
 * public/api/cart-check.php — is what is in the basket still on sale? (POST JSON, no write)
 *
 * In:  {k: public key, stand: id, lines: [{product, variant, qty}, …]}
 * Out: {error: 0, open: bool, mode, lines: [{ok, code, msg, left, price, name}, …]} — one answer
 *      per line, in the same order. Asked when the customer opens the order sheet, so that a sold
 *      out item is spotted before the order, not after. The order itself is checked again.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/public.php';

shp_api_guard(false);
$in = shp_json_in();
$tourId = shp_cus_api_tour($in['k'] ?? '');
$standId = intval($in['stand'] ?? 0);
$m = shp_cus_mode($tourId);
if ($m['mode'] === 'closed') shp_json_error('not_now', shp_t('ShErrNotNow'));

$stand = shp_stand($standId);
if (!$stand || intval($stand->SdTournament) !== $tourId || intval($stand->SdActive) !== 1) shp_json_error('stand', shp_t('ShErrStand'));
$open = $m['mode'] === 'preorder' || (intval($stand->SdOnline) === 1 && intval($stand->SdOpen) === 1);

$byId = array();
foreach (shp_catalog($tourId, $standId, $m['mode'] === 'preorder' ? 'preorder' : 'onsite') as $p) $byId[$p['id']] = $p;

$lines = is_array($in['lines'] ?? null) ? array_slice(array_values($in['lines']), 0, SHP_MAX_LINES) : array();
$out = array();
foreach ($lines as $l) {
    $pid = intval($l['product'] ?? 0);
    $vid = intval($l['variant'] ?? 0);
    $qty = intval($l['qty'] ?? 0);
    $p = $byId[$pid] ?? null;
    $r = array('ok' => false, 'code' => '', 'msg' => '', 'left' => 0, 'price' => 0, 'name' => '');
    if (!$p) {
        $r['code'] = 'product';
        $r['msg'] = shp_t('ShErrProduct');
    } else {
        $r['name'] = $p['name'];
        $price = $p['price'];
        $stock = $p['stock'];
        $avail = $p['available'];
        if ($p['option'] !== '') {
            $v = null;
            foreach ($p['variants'] as $x) if ($x['id'] === $vid) $v = $x;
            if (!$v) {
                $r['code'] = 'variant';
                $r['msg'] = shp_t('ShErrVariant');
                $avail = false;
            } else {
                $price = $v['price'];
                $stock = $v['stock'];
                $avail = $v['available'];
                $r['name'] .= ' — ' . $v['label'];
            }
        } elseif ($vid !== 0) {
            $r['code'] = 'variant';
            $r['msg'] = shp_t('ShErrVariant');
            $avail = false;
        }
        $r['price'] = $price;
        if ($r['code'] === '') {
            if (!$avail) {
                $r['code'] = 'sold_out';
                $r['msg'] = shp_t('ShErrSoldOut', $r['name']);
            } elseif ($stock !== null && $qty > $stock) {
                $r['code'] = 'stock';
                $r['left'] = max(0, intval($stock));
                $r['msg'] = shp_t('ShErrStock', $r['left']);
            } else {
                $r['ok'] = true;
            }
        }
    }
    $out[] = $r;
}
shp_json(array('open' => $open, 'mode' => $m['mode'], 'lines' => $out));
