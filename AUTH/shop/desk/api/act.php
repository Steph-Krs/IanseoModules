<?php
/**
 * desk/api/act.php — a write of the desk (POST, JSON): {act, account, idem, …}
 *   decide  division ('' = documents), decision ok|ko|undo, note   right reg or equip
 *   power   division, power (pounds)                               right equip
 *   note    post reg|equip, text                                   right reg or equip
 *   pay     method, amount (empty = what is left)                  right pay
 * The same key sent again writes once. Answers the archer's fresh file.
 */

require_once dirname(__DIR__, 2) . '/staff/boot.php';
require_once dirname(__DIR__, 2) . '/lib/desk.php';

shp_api_guard(true);
$me = shp_require_desk();
$in = shp_json_in();
$account = (string) ($in['account'] ?? '');
$idem = (string) ($in['idem'] ?? '');
switch ((string) ($in['act'] ?? '')) {
    case 'decide':
        $r = shp_desk_decide($me, $account, (string) ($in['division'] ?? ''), (string) ($in['decision'] ?? ''), $idem, (string) ($in['note'] ?? ''));
        break;
    case 'power':
        $r = shp_desk_power($me, $account, (string) ($in['division'] ?? ''), (string) ($in['power'] ?? ''), $idem);
        break;
    case 'note':
        $r = shp_desk_note($me, $account, (string) ($in['post'] ?? ''), (string) ($in['text'] ?? ''), $idem);
        break;
    case 'pay':
        $amount = isset($in['amount']) && $in['amount'] !== '' ? (string) $in['amount'] : null;
        $r = shp_desk_pay($me, $account, (string) ($in['method'] ?? ''), $amount, $idem);
        break;
    default:
        shp_json_error('bad_request', shp_t('ShErrBadRequest'), 400);
}
if (empty($r['error'])) {
    $r['error'] = 0;
    $r['file'] = shp_desk_file($me, $account);
}
shp_json($r);
