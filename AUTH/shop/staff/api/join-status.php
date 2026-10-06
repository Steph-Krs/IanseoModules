<?php
/**
 * staff/api/join-status.php — state of this phone's volunteer session (GET, JSON).
 *
 * Asked every 3 s by the waiting screen of a join request, and by the till to learn at once
 * that its access was withdrawn. Answer: {error: 0, state, msg, code?, next?} — state as
 * shp_staff_session_state(): ok, pending, refused, revoked, locked, expired, shop_off,
 * signed_out. Reads only (the last-seen stamp apart); no right needed beyond the cookie.
 */

require_once dirname(__DIR__) . '/boot.php';

shp_api_guard(false);

$st = shp_staff_session_state();
$out = array('error' => 0, 'state' => $st['reason'], 'msg' => $st['reason'] === 'ok' ? '' : shp_staff_reason_msg($st['reason']));
if ($st['reason'] === 'pending') $out['code'] = (string) $st['row']->SfCode;
if ($st['reason'] === 'ok') {
    $out['next'] = shp_url('staff/index.php');
    $out['name'] = shp_staff_label(intval($st['staff']->SfId));
}
shp_json($out);
