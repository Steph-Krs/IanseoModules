<?php
/**
 * public/api/order.php — places an order from a customer's phone (POST JSON).
 *
 * In:  {k: public key, stand: id, lines: [{product, variant, qty}], pay_mode: now|pickup|tab,
 *       note, pseudo (first order of a visitor), idem: UUID made by the phone}
 * Out: {error: 0, order, number, total, status, existing} or {error: 1, code, msg, line?, left?}
 *
 * The same idem sent again (double tap, network retry) returns the order created the first
 * time. A visitor without a licensee account gets a nickname and a cookie on their first order.
 * Everything is decided here: the page only shows what this script would accept.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/public.php';

shp_api_guard(true);
$in = shp_json_in();
$tourId = shp_cus_api_tour($in['k'] ?? '');
$standId = intval($in['stand'] ?? 0);

$idem = (string) ($in['idem'] ?? '');
if (!shp_idem_ok($idem)) shp_json_error('bad_request', shp_t('ShErrBadRequest'), 400);

// Sent again after a lost answer: the first answer, and nothing else (no second visitor).
$ex = shp_order_by_idem($tourId, strtolower($idem));   // bytes: a UUID is hexadecimal ASCII
if ($ex) shp_json(shp_order_answer($ex, true));

$m = shp_cus_mode($tourId);
if ($m['mode'] === 'closed') shp_json_error('not_now', shp_t($m['why'] === 'over' ? 'ShCusOver' : 'ShErrNotNow'));
// A stand of another competition is refused before anything is created for this phone.
$stand = shp_stand($standId);
if (!$stand || intval($stand->SdTournament) !== $tourId || intval($stand->SdActive) !== 1) shp_json_error('stand', shp_t('ShErrStand'));
$channel = $m['mode'] === 'preorder' ? 'preorder' : 'online';

$customer = shp_customer($tourId);
$newGuest = 0;
$pseudo = trim((string) ($in['pseudo'] ?? ''));
$renames = $customer && $customer['kind'] === 'GUEST' && $pseudo !== '' && $pseudo !== $customer['label'];
if (!$customer || $renames) {
    if ($channel === 'preorder') shp_json_error('customer', shp_t('ShErrPreorderLogin'));
    $g = shp_guest_create($tourId, $pseudo);
    if ($g['error']) shp_json_error($g['code'], $g['msg']);
    $customer = $g['customer'];
    if (empty($renames)) $newGuest = intval($customer['guest']);
}

$lines = array();
$raw = is_array($in['lines'] ?? null) ? array_slice(array_values($in['lines']), 0, SHP_MAX_LINES + 1) : array();
foreach ($raw as $l) {
    $lines[] = is_array($l)
        ? array('product' => intval($l['product'] ?? 0), 'variant' => intval($l['variant'] ?? 0), 'qty' => intval($l['qty'] ?? 0))
        : null;
}
$payMode = (string) ($in['pay_mode'] ?? '');
if (!in_array($payMode, array('now', 'pickup', 'tab'), true)) $payMode = '';

$res = shp_order_create($tourId, $standId, $lines, $customer, array(
    'channel' => $channel, 'pay_mode' => $payMode, 'idem' => $idem, 'note' => (string) ($in['note'] ?? ''),
    'wanted' => (string) ($in['wanted'] ?? ''),
));
if (!$res['error']) $res['label'] = (string) ($customer['label'] ?? '');
// Lost the race against the same request sent twice at once: the order belongs to the visitor
// the winner created, so this request's own visitor and cookie must not replace it.
if ($newGuest > 0 && !$res['error'] && !empty($res['existing'])) {
    safe_w_sql("DELETE FROM ShopGuests WHERE SuId = $newGuest AND SuTournament = $tourId");
    header_remove('Set-Cookie');
}
shp_json($res);
