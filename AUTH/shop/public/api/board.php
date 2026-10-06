<?php
/**
 * public/api/board.php?k=<public key>&s=<stand id> — what the public screen of the orders shows,
 * in three columns as in a fast-food restaurant.
 *
 *   waiting    received, not started: [{n, who, pay: paid|topay|pickup|tab, at: 'HH:MM' service time}]
 *   preparing  being prepared: [{n, who, eta: minutes announced by the volunteers, or null}]
 *   ready      to collect: [{n, who}], the latest first
 *   count      {waiting, preparing, ready}: how many orders each column really has
 *
 * Each list stops at SHP_BOARD_ROWS orders, what a screen shows in one column; the counts let the
 * page say that some are not shown, and let the public see the queue at a glance.
 *
 * "who" lets each customer find their order at a glance: the given name and the initial of the
 * family name of a licensee ("Stéphane K"), the nickname of a visitor, the name a volunteer typed
 * for a counter sale ('' when there is none). Nothing else about a customer leaves the server.
 *
 * Pre-orders without a service time are left out: they are handed over at the stand, never
 * prepared in the queue. GET, X-Shp header, JSON.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/public.php';

define('SHP_BOARD_ROWS', 15);

/**
 * "Stéphane K": given name and initial of the family name; the family name alone without a given
 * name. A given name stored in capitals (federation files) is written as a name; one typed with
 * its own case is kept as it is.
 */
function shp_board_short($first, $family)
{
    $first = trim((string) $first);
    $family = trim((string) $family);
    if ($first === '') return $family;
    if (mb_strtoupper($first) === $first) $first = mb_convert_case(mb_strtolower($first), MB_CASE_TITLE);
    return $family === '' ? $first : $first . ' ' . mb_strtoupper(mb_substr($family, 0, 1));
}

/**
 * "who" of each order: [ShId => text]. A licensee's names are read from the competition's
 * entries, else from the archer accounts: the label of an archer order holds both names, in an
 * order that depends on where the sale was made, so it cannot be cut reliably.
 */
function shp_board_who($tourId, array $orders)
{
    $out = $lic = array();
    foreach ($orders as $o) {
        // An anonymised order keeps the kind ARCHER with the licence ANON: no name to look for.
        if ((string) $o->ShCustKind === 'ARCHER' && !in_array((string) $o->ShLicence, array('', 'ANON'), true)) $lic[(string) $o->ShLicence] = '';
    }
    if ($lic) {
        $in = implode(', ', array_map('StrSafe_DB', array_keys($lic)));
        $rs = safe_r_sql("SELECT BaLicence, BaName, BaFamilyName FROM BookingArchers WHERE BaLicence IN ($in)");
        while ($r = safe_fetch($rs)) $lic[(string) $r->BaLicence] = shp_board_short($r->BaName, $r->BaFamilyName);
        $rs = safe_r_sql("SELECT EnCode, EnFirstName, EnName FROM Entries WHERE EnTournament = " . intval($tourId) . " AND EnCode IN ($in)");
        while ($r = safe_fetch($rs)) {
            // ianseo's EnFirstName holds the family name, EnName the given name.
            $short = shp_board_short($r->EnName, $r->EnFirstName);
            if (isset($lic[(string) $r->EnCode]) && $short !== '') $lic[(string) $r->EnCode] = $short;
        }
    }
    foreach ($orders as $o) {
        $who = (string) $o->ShCustKind === 'ARCHER' ? ($lic[(string) $o->ShLicence] ?? '') : (string) $o->ShCustLabel;
        $out[intval($o->ShId)] = mb_substr(trim($who), 0, 40);
    }
    return $out;
}

shp_api_guard(false);
header('Cache-Control: no-store, private');
$tourId = shp_cus_api_tour($_GET['k'] ?? '');
$standId = intval($_GET['s'] ?? 0);
$name = '';
if ($standId > 0) {
    $stand = shp_stand($standId);
    if (!$stand || intval($stand->SdTournament) !== $tourId || intval($stand->SdActive) !== 1) shp_json_error('stand', shp_t('ShErrStand'), 404);
    $name = (string) $stand->SdName;
}
$where = "ShTournament = $tourId" . ($standId > 0 ? " AND ShStand = $standId" : "");
$today = (string) shp_window($tourId)['today'];
$inQueue = "(ShChannel <> 'preorder' OR ShWantedAt IS NOT NULL)";
// An order for a later day stays off the screen until its day; one of an earlier day that is
// still waiting stays on it, past midnight included.
$waiting = "ShStatus = 'placed' AND $inQueue AND (ShWantedAt IS NULL OR DATE(ShWantedAt) <= " . StrSafe_DB($today) . ")";
$preparing = "ShStatus = 'preparing' AND $inQueue";
$rows = SHP_BOARD_ROWS;

$c = safe_fetch(safe_r_sql("SELECT SUM($waiting) AS w, SUM($preparing) AS p, SUM(ShStatus = 'ready') AS r FROM ShopOrders
    WHERE $where AND ShStatus IN ('placed', 'preparing', 'ready')"));
$out = array('waiting' => array(), 'preparing' => array(), 'ready' => array(), 'name' => $name,
    'count' => array('waiting' => intval($c->w ?? 0), 'preparing' => intval($c->p ?? 0), 'ready' => intval($c->r ?? 0)));

$cols = "ShId, ShNumber, ShPayMode, ShPayState, ShWantedAt, ShReadyBy, ShCustKind, ShCustLabel, ShLicence";
$list = array('waiting' => array(), 'preparing' => array(), 'ready' => array());
$order = array('waiting' => 'COALESCE(ShWantedAt, ShCreated), ShId', 'preparing' => 'COALESCE(ShWantedAt, ShCreated), ShId',
    'ready' => 'ShReadyAt DESC, ShId DESC');
foreach (array('waiting' => $waiting, 'preparing' => $preparing, 'ready' => "ShStatus = 'ready'") as $k => $cond) {
    $rs = safe_r_sql("SELECT $cols FROM ShopOrders WHERE $where AND $cond ORDER BY {$order[$k]} LIMIT $rows");
    while ($r = safe_fetch($rs)) $list[$k][] = $r;
}
$who = shp_board_who($tourId, array_merge($list['waiting'], $list['preparing'], $list['ready']));
foreach ($list['waiting'] as $r) {
    $wanted = (string) ($r->ShWantedAt ?? '');
    $pay = (string) $r->ShPayState === 'paid' ? 'paid'
        : ((string) $r->ShPayMode === 'tab' ? 'tab' : ((string) $r->ShPayMode === 'pickup' ? 'pickup' : 'topay'));
    $out['waiting'][] = array('n' => (string) $r->ShNumber, 'who' => $who[intval($r->ShId)], 'pay' => $pay,
        'at' => $wanted !== '' ? mb_substr($wanted, 11, 5) : '');
}
foreach ($list['preparing'] as $r) {
    $out['preparing'][] = array('n' => (string) $r->ShNumber, 'who' => $who[intval($r->ShId)],
        'eta' => $r->ShReadyBy !== null ? max(0, shp_minutes_to($tourId, (string) $r->ShReadyBy)) : null);
}
foreach ($list['ready'] as $r) $out['ready'][] = array('n' => (string) $r->ShNumber, 'who' => $who[intval($r->ShId)]);
shp_json($out);
