<?php
/**
 * lib/till.php — what the volunteers' till (staff/index.php and staff/api/*) asks of the engine:
 * the state of a stand in one piece, a sale with its collection, status changes, refunds,
 * stock changes, the cash figures of the day.
 *
 * Every function here receives the volunteer row of shp_require_staff() and works on the
 * volunteer's own competition only. The endpoints check the right on the stand BEFORE calling;
 * the functions that move money or stock check again (shp_pay_*, shp_refund) or take the stand
 * from the order itself, never from the request.
 *
 * Every write of a phone carries an idempotency key. A sale uses the SAME key for the order and
 * for its collection: sent again after a network cut it gives the first order and the first
 * journal line, never a second collection.
 */

if (defined('SHP_TILL_LOADED')) return;
define('SHP_TILL_LOADED', true);

require_once __DIR__ . '/pay.php';   // orders, journal, means of payment, payer trust index

define('SHP_UNDO_SECONDS', 90);      // a sale can be voided this long after it was made

/* ------------------------------------------------------------------ */
/* Stands, rights, state                                               */
/* ------------------------------------------------------------------ */

/** Is one of these rights held on this stand? ('manage' always counts.) */
function shp_till_can_any($me, array $perms, $standId)
{
    foreach (array_merge($perms, array('manage')) as $p) if (shp_staff_can($me, $p, $standId)) return true;
    return false;
}

/** Active stands where the volunteer has at least one right, in display order. */
function shp_till_stands($me)
{
    $out = array();
    $perms = shp_staff_perms($me);
    foreach (shp_stands(intval($me->SfTournament), true) as $id => $s) {
        if (empty($perms[$id])) continue;
        $out[] = array('id' => intval($id), 'name' => (string) $s->SdName, 'kind' => (string) $s->SdKind,
            'mode' => (string) $s->SdMode, 'pay_when' => (string) $s->SdPayWhen, 'open' => intval($s->SdOpen) === 1,
            'perms' => array_values($perms[$id]));
    }
    return $out;
}

/** First name of a volunteer for the top bar. */
function shp_till_first_name($me)
{
    $g = trim((string) $me->SfGivenName);
    return $g !== '' ? $g : shp_staff_label(intval($me->SfId));
}

/** Refund limits of a volunteer: ['max', 'left'], both null for an organiser (no limit). */
function shp_till_refund_limits($me)
{
    if ((string) $me->SfKind === 'ORGANISER') return array('max' => null, 'left' => null);
    $max = round((float) $me->SfRefundMax, 2);
    $total = round((float) $me->SfRefundTotal, 2);
    if ($max <= 0 || $total <= 0) return array('max' => 0.0, 'left' => 0.0);
    return array('max' => $max, 'left' => max(0.0, round($total - shp_staff_refunded($me), 2)));
}

/** Means of payment a volunteer can collect: [['code', 'label']], without "on account". */
function shp_till_methods()
{
    $out = array();
    foreach (shp_pay_methods() as $code => $label) {
        if ($code !== 'tab') $out[] = array('code' => (string) $code, 'label' => (string) $label);
    }
    return $out;
}

/**
 * An order for the till's script: what a card shows, nothing more. 'wanted': the service time
 * asked ('' = as soon as possible); 'wait': minutes before it should be ready (announced by a
 * volunteer, else the place in the queue), only while it is to prepare.
 */
function shp_till_order_out(array $o)
{
    $lines = array();
    foreach ($o['lines'] as $l) {
        $q = $l['qty'] - $l['cancelled'];
        if ($q > 0) $lines[] = array('id' => $l['id'], 'label' => $l['label'], 'qty' => $q, 'unit' => $l['unit'], 'amount' => $l['amount']);
    }
    $wait = null;
    if (in_array($o['status'], array('placed', 'preparing'), true)) {
        if ($o['ready_by'] !== '') $wait = max(0, shp_minutes_to($o['tournament'], $o['ready_by']));
        elseif (isset($o['eta'])) $wait = intval($o['eta']);
    }
    return array('id' => $o['id'], 'number' => $o['number'], 'label' => $o['label'], 'kind' => $o['cust_kind'],
        'channel' => $o['channel'], 'status' => $o['status'], 'pay_mode' => $o['pay_mode'], 'pay_state' => $o['pay_state'],
        'total' => $o['total'], 'paid' => $o['paid'], 'due' => $o['due'], 'note' => $o['note'],
        'since' => $o['status'] === 'ready' && $o['ready'] !== '' ? $o['ready'] : $o['created'],
        'wanted' => $o['wanted'], 'wait' => $wait, 'wait_set' => $o['ready_by'] !== '',
        'lines' => $lines);
}

function shp_till_orders_out(array $orders)
{
    return array_map('shp_till_order_out', array_values($orders));
}

/** Catalogue of a stand for the till (sale tiles and stock list). */
function shp_till_catalog($tourId, $standId)
{
    $out = array();
    foreach (shp_catalog($tourId, $standId, 'all') as $p) {
        if (!$p['active']) continue;
        $vars = array();
        foreach ($p['variants'] as $v) {
            $vars[] = array('id' => $v['id'], 'label' => $v['label'], 'price' => $v['price'], 'stock' => $v['stock'],
                'switch' => $v['switch'], 'available' => $v['available']);
        }
        $out[] = array('id' => $p['id'], 'category' => $p['category'], 'name' => $p['name'], 'price' => $p['price'],
            'stock' => $p['stock'], 'alert' => $p['alert'], 'low' => $p['low'], 'maxper' => $p['maxper'],
            'option' => $p['option'], 'onsite' => $p['onsite'], 'switch' => $p['switch'], 'available' => $p['available'],
            'variants' => $vars);
    }
    return $out;
}

/**
 * The whole state of the till for one stand (the stand asked for when the volunteer may work
 * there, otherwise their first one). 'hash' changes when anything the screen shows changes: the
 * phone sends it back and gets a short "same" answer while nothing moved. 'now' (local time of
 * the competition, for the timers) is left out of the hash.
 */
function shp_till_state($me, $standId = 0)
{
    $tour = intval($me->SfTournament);
    $stands = shp_till_stands($me);
    $cur = null;
    foreach ($stands as $s) if ($s['id'] === intval($standId)) $cur = $s;
    if (!$cur && $stands) $cur = $stands[0];
    $set = shp_settings($tour);
    $lim = shp_till_refund_limits($me);
    $state = array(
        'me' => array('id' => intval($me->SfId), 'name' => shp_till_first_name($me), 'refund_max' => $lim['max'], 'refund_left' => $lim['left']),
        'stands' => $stands, 'stand' => $cur ? $cur['id'] : 0,
        'methods' => shp_till_methods(), 'tab_on' => $set && intval($set->SgTab) === 1,
        'catalog' => array(), 'to_pay' => array(), 'queue' => array(), 'ready' => array(), 'preorders' => array(), 'recent' => array(),
        'eta_next' => 0, 'lead' => SHP_SCHEDULE_LEAD,
    );
    if ($cur) {
        $row = shp_stand($cur['id']);
        $perms = $cur['perms'];
        $can = function (array $any) use ($perms) { return (bool) array_intersect(array_merge($any, array('manage')), $perms); };
        if ($can(array('sell', 'stock'))) $state['catalog'] = shp_till_catalog($tour, $cur['id']);
        if ($can(array('sell', 'prepare', 'cash'))) {
            $q = shp_queue($row);
            $state['eta_next'] = intval($q['eta_next']);
            // Handed over but never collected (the collection failed after the sale): still to collect.
            $left = shp_orders_fetch("ShTournament = $tour AND ShStand = " . $cur['id'] . " AND ShStatus = 'delivered'
                AND ShPayMode IN ('now', 'pickup') AND ShPayState IN ('unpaid', 'partial')", 'ShId');
            $state['to_pay'] = shp_till_orders_out(array_merge($q['to_pay'], $left));
            $state['queue'] = shp_till_orders_out($q['queue']);
            $state['ready'] = shp_till_orders_out($q['ready']);
            $state['preorders'] = shp_till_orders_out($q['preorders']);
            $state['recent'] = shp_till_orders_out(shp_orders_fetch("ShTournament = $tour AND ShStand = " . $cur['id']
                . " AND ShStatus IN ('delivered', 'cancelled')", 'ShId DESC', 12));
        }
    }
    $state['hash'] = md5(json_encode($state));
    $state['now'] = (string) (safe_fetch(safe_r_sql("SELECT " . shp_local_now_sql($tour) . " AS n"))->n ?? '');
    return $state;
}

/* ------------------------------------------------------------------ */
/* Licensees at the counter                                            */
/* ------------------------------------------------------------------ */

/** Licensee taking part in a competition (or having an account), by licence: ['licence', 'name', 'club'] or null. */
function shp_till_find_archer($tourId, $licence)
{
    $licence = bk_clean_licence($licence);
    if (!preg_match('/^[A-Z0-9]{1,25}$/', $licence)) return null;
    $r = safe_fetch(safe_r_sql("SELECT EnCode, EnFirstName, EnName, CoCode FROM Entries
        LEFT JOIN Countries ON CoId = EnCountry
        WHERE EnTournament = " . intval($tourId) . " AND EnCode = " . StrSafe_DB($licence) . " LIMIT 1"));
    if ($r) return array('licence' => $licence, 'name' => trim($r->EnFirstName . ' ' . $r->EnName), 'club' => (string) $r->CoCode);
    $r = safe_fetch(safe_r_sql("SELECT BaFamilyName, BaName, BaClubCode FROM BookingArchers WHERE BaLicence = " . StrSafe_DB($licence)));
    if ($r) return array('licence' => $licence, 'name' => trim($r->BaFamilyName . ' ' . $r->BaName), 'club' => (string) $r->BaClubCode);
    return null;
}

/**
 * Search by name or licence among the participants of the competition, then — by exact licence
 * only — among the licensees who have an account on the online registration (a parent, a coach:
 * someone who is not taking part but may run a tab; a volunteer must not be able to browse the
 * names of every licensee of the server): up to 12 rows ['licence', 'name', 'club', 'tab' =>
 * ['allow', 'warn', 'msg']]. 'tab' says whether the payer trust index lets this person put an
 * order on their account (always allowed unless the competition applies the index and the mode
 * of the server is alert or block).
 */
function shp_till_search($tourId, $q)
{
    $tourId = intval($tourId);
    $terms = preg_split('/\s+/u', trim((string) $q), -1, PREG_SPLIT_NO_EMPTY);
    $terms = array_slice($terms, 0, 4);
    if (!$terms || mb_strlen(implode('', $terms)) < 2) return array();
    $like = function ($expr) use ($terms) {
        $w = array();
        foreach ($terms as $t) $w[] = "$expr LIKE " . StrSafe_DB('%' . addcslashes(mb_substr($t, 0, 30), '%_\\') . '%');
        return implode(' AND ', $w);
    };
    $found = array();
    $rs = safe_r_sql("SELECT DISTINCT EnCode, EnFirstName, EnName, CoCode FROM Entries
        LEFT JOIN Countries ON CoId = EnCountry
        WHERE EnTournament = $tourId AND EnCode <> '' AND " . $like("CONCAT(EnFirstName, ' ', EnName, ' ', EnCode)") . "
        ORDER BY EnFirstName, EnName LIMIT 12");
    while ($r = safe_fetch($rs)) {
        $found[bk_clean_licence($r->EnCode)] = array('name' => trim($r->EnFirstName . ' ' . $r->EnName), 'club' => (string) $r->CoCode);
    }
    $lic = count($terms) === 1 ? bk_clean_licence($terms[0]) : '';
    if (count($found) < 12 && preg_match('/^[A-Z0-9]{5,25}$/', $lic) && !isset($found[$lic])) {
        $r = safe_fetch(safe_r_sql("SELECT BaFamilyName, BaName, BaClubCode FROM BookingArchers
            WHERE BaActive = 1 AND BaLicence = " . StrSafe_DB($lic)));
        if ($r) $found[$lic] = array('name' => trim($r->BaFamilyName . ' ' . $r->BaName), 'club' => (string) $r->BaClubCode);
    }
    $set = shp_settings($tourId);
    $gate = $set && intval($set->SgTrustGate) === 1 && function_exists('aut_trust_gate');
    $out = array();
    foreach ($found as $licence => $f) {
        $tab = array('allow' => true, 'warn' => false, 'msg' => '');
        if ($gate) {
            $g = aut_trust_gate($licence, $tourId, 'shop_tab');
            if (is_array($g)) $tab = array('allow' => !empty($g['allow']), 'warn' => !empty($g['warn']), 'msg' => (string) ($g['msg'] ?? ''));
        }
        $out[] = array('licence' => (string) $licence, 'name' => $f['name'], 'club' => $f['club'], 'tab' => $tab);
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Sale                                                                */
/* ------------------------------------------------------------------ */

/**
 * Sells at the counter. $in: stand, lines [[product, variant, qty]], customer ['kind' =>
 * 'ARCHER'|'COUNTER', 'licence', 'label'], pay ['mode' => 'now'|'pickup'|'tab', 'method'],
 * note, idem; at a stand with preparation also wanted (service time asked, 'HH:MM') and wait
 * (minutes announced to the customer). Stand without preparation: the sale is handed over at once. Collected in the same
 * request ('now'), with the same key as the order. Returns shp_order_create()'s answer, plus
 * 'pay_state'/'paid' and, when the order exists but the collection failed, 'pay_error'.
 */
function shp_till_sell($me, array $in)
{
    $tour = intval($me->SfTournament);
    $staffId = intval($me->SfId);
    $standId = intval($in['stand'] ?? 0);
    $stand = shp_stand($standId);
    if (!$stand || intval($stand->SdTournament) !== $tour || intval($stand->SdActive) !== 1) return shp_err('stand', 'ShErrStand');
    $idem = (string) ($in['idem'] ?? '');
    if (!shp_idem_ok($idem)) return shp_err('bad_request', 'ShErrBadRequest');

    $lines = array();
    foreach ((array) ($in['lines'] ?? array()) as $l) {
        if (!is_array($l)) return shp_err('bad_request', 'ShErrBadRequest');
        $lines[] = array('product' => intval($l['product'] ?? 0), 'variant' => intval($l['variant'] ?? 0), 'qty' => intval($l['qty'] ?? 0));
    }

    $pay = is_array($in['pay'] ?? null) ? $in['pay'] : array();
    $mode = (string) ($pay['mode'] ?? '');
    $allowed = (string) $stand->SdMode === 'direct' ? array('now', 'tab') : array('now', 'pickup', 'tab');
    if (!in_array($mode, $allowed, true)) return shp_err('pay_mode', 'ShErrPayMode');
    $method = (string) ($pay['method'] ?? '');
    if ($mode === 'now') {
        if (!shp_staff_can($me, 'cash', $standId)) return shp_err('forbidden', 'ShPosErrNoCash');
        if (!aut_pay_ledger_method($method) || !isset(shp_pay_methods()[$method])) return shp_err('method', 'ShPayErrMethod');
    }

    $c = is_array($in['customer'] ?? null) ? $in['customer'] : array();
    if ((string) ($c['kind'] ?? '') === 'ARCHER') {
        $a = shp_till_find_archer($tour, (string) ($c['licence'] ?? ''));
        if (!$a) return shp_err('customer', 'ShErrCustomer');
        $customer = array('kind' => 'ARCHER', 'licence' => $a['licence'], 'label' => $a['name']);
    } else {
        $customer = array('kind' => 'COUNTER', 'label' => (string) ($c['label'] ?? ''));
    }
    if ($mode === 'tab' && $customer['kind'] !== 'ARCHER') return shp_err('tab_refused', 'ShErrTabRefused');

    $prep = (string) $stand->SdMode === 'prep';
    $res = shp_order_create($tour, $standId, $lines, $customer, array(
        'channel' => 'counter', 'pay_mode' => $mode, 'idem' => $idem, 'staff' => $staffId,
        'note' => (string) ($in['note'] ?? ''), 'deliver_now' => !$prep,
        'wanted' => $prep ? (string) ($in['wanted'] ?? '') : '',
        'wait' => $prep && isset($in['wait']) && $in['wait'] !== '' ? intval($in['wait']) : null));
    if (!empty($res['error'])) return $res;

    if ($mode === 'now') {
        $p = shp_pay_order(intval($res['order']), $method, null, $staffId, $idem);
        if (!empty($p['error'])) {
            $res['pay_error'] = array('code' => (string) ($p['code'] ?? ''), 'msg' => (string) ($p['msg'] ?? ''));
        } else {
            $res['pay_state'] = (string) ($p['pay_state'] ?? '');
            $res['paid'] = (float) ($p['paid'] ?? 0);
        }
    }
    $fresh = shp_order(intval($res['order']), $tour);
    if ($fresh) $res['order_row'] = shp_till_order_out($fresh);
    return $res;
}

/**
 * Voids a sale made a moment ago by the same volunteer: its collections are cancelled in the
 * journal (a counter-line, both kept — the money goes back to the customer at the counter, it
 * is not a refund and does not use the refund ceilings), the order goes back to "received"
 * and is cancelled, stock given back. Only a counter sale of this volunteer, SHP_UNDO_SECONDS
 * after it was made; later, a refund does it (shp_refund). Asked twice: same answer.
 */
function shp_till_undo($me, $orderId)
{
    $tour = intval($me->SfTournament);
    $staffId = intval($me->SfId);
    $o = shp_order_row($orderId);
    if (!$o || intval($o->ShTournament) !== $tour) return shp_err('order', 'ShErrOrder');
    if ((string) $o->ShStatus === 'cancelled') return array('error' => 0, 'status' => 'cancelled');
    $age = safe_fetch(safe_w_sql("SELECT TIMESTAMPDIFF(SECOND, ShCreated, " . shp_local_now_sql($tour) . ") AS s
        FROM ShopOrders WHERE ShId = " . intval($o->ShId)));
    if (intval($o->ShByStaff) !== $staffId || (string) $o->ShChannel !== 'counter' || !$age || intval($age->s) > SHP_UNDO_SECONDS) {
        return shp_err('undo', 'ShPosErrUndo');
    }
    $id = intval($o->ShId);
    $rs = safe_w_sql("SELECT BlgId, BlgKind, BlgStaff FROM BookingLedger WHERE BlgOrder = $id AND BlgTournament = $tour
        AND BlgCancelled = 0 AND BlgKind <> 'cancel' AND BlgStatus = 'done'");
    $lines = array();
    while ($r = safe_fetch($rs)) $lines[] = $r;
    // Only this volunteer's own collections: money another one took is undone by a refund.
    foreach ($lines as $l) {
        if ((string) $l->BlgKind !== 'payment' || intval($l->BlgStaff) !== $staffId) return shp_err('undo', 'ShPosErrUndo');
    }
    foreach ($lines as $l) bk_ledger_cancel($tour, intval($l->BlgId), shp_pay_by($staffId), $staffId);
    shp_order_pay_state($id);
    for ($i = 0; $i < 4; $i++) {   // back to "received" one step at a time
        $cur = shp_order_row($id);
        if (!$cur || (string) $cur->ShStatus === 'placed' || (string) $cur->ShStatus === 'cancelled') break;
        $stand = shp_stand($cur->ShStand);
        $back = $stand && (string) $stand->SdMode === 'direct' ? 'placed'
            : array('delivered' => 'ready', 'ready' => 'preparing', 'preparing' => 'placed')[(string) $cur->ShStatus];
        $t = shp_order_transition($id, $back, $staffId);
        if (!empty($t['error'])) break;
    }
    return shp_order_cancel($id, $staffId, false);
}

/* ------------------------------------------------------------------ */
/* Orders                                                              */
/* ------------------------------------------------------------------ */

/**
 * Rights needed to move an order to status $to coming from $from: the step being done when going
 * forward, the step being undone when going back.
 */
function shp_till_step_perms($from, $to)
{
    $order = array_flip(shp_order_statuses());
    if (!isset($order[$from], $order[$to])) return array();
    $step = $order[$to] > $order[$from] ? $to : $from;
    return $step === 'delivered' ? array('sell', 'prepare') : array('prepare');
}

/**
 * One action on an order of the volunteer's competition. $in['action']: 'status' (+ 'to'),
 * 'cancel', 'cancel_line' (+ 'line', 'qty'), 'undo'. The right is looked up on the ORDER's stand.
 * Returns the engine's answer plus the fresh 'order' (for the till's script).
 */
function shp_till_order_action($me, array $in)
{
    $tour = intval($me->SfTournament);
    $staffId = intval($me->SfId);
    $o = shp_order_row(intval($in['order'] ?? 0));
    if (!$o || intval($o->ShTournament) !== $tour) return shp_err('order', 'ShErrOrder');
    $standId = intval($o->ShStand);
    $id = intval($o->ShId);
    $action = (string) ($in['action'] ?? '');

    if ($action === 'status') {
        $to = (string) ($in['to'] ?? '');
        if (!shp_till_can_any($me, shp_till_step_perms((string) $o->ShStatus, $to), $standId)) return shp_err('forbidden', 'ShStfErrForbidden');
        $res = shp_order_transition($id, $to, $staffId);
        // Another volunteer pressed the same button a moment before: what this one wanted is done.
        if (!empty($res['error']) && ($res['code'] ?? '') === 'changed' && (string) ($res['status'] ?? '') === $to) {
            $res = array('error' => 0, 'status' => $to);
        }
    } elseif ($action === 'cancel' || $action === 'cancel_line') {
        if (!shp_till_can_any($me, array('sell'), $standId)) return shp_err('forbidden', 'ShStfErrForbidden');
        $res = $action === 'cancel' ? shp_order_cancel($id, $staffId, false)
            : shp_order_cancel_line($id, intval($in['line'] ?? 0), intval($in['qty'] ?? 0), $staffId);
    } elseif ($action === 'undo') {
        if (!shp_till_can_any($me, array('sell'), $standId)) return shp_err('forbidden', 'ShStfErrForbidden');
        $res = shp_till_undo($me, $id);
    } elseif ($action === 'wait') {
        // The time announced to the customer: set by whoever takes the order, adjusted by the preparer.
        if (!shp_till_can_any($me, array('prepare', 'sell', 'cash'), $standId)) return shp_err('forbidden', 'ShStfErrForbidden');
        $res = shp_order_set_wait($id, intval($in['minutes'] ?? 0));
    } else {
        return shp_err('bad_request', 'ShErrBadRequest');
    }
    $fresh = shp_order($id, $tour);
    if ($fresh) $res['order'] = shp_till_order_out($fresh);
    return $res;
}

/**
 * Collects an order ('pay': method, amount?, idem; 'then_deliver' hands it over once collected)
 * . 'tab' puts it on the archer's account.
 */
function shp_till_pay($me, array $in)
{
    $tour = intval($me->SfTournament);
    $staffId = intval($me->SfId);
    $o = shp_order_row(intval($in['order'] ?? 0));
    if (!$o || intval($o->ShTournament) !== $tour) return shp_err('order', 'ShErrOrder');
    if (!shp_staff_can($me, 'cash', intval($o->ShStand))) return shp_err('forbidden', 'ShPayErrRight');
    $amount = array_key_exists('amount', $in) ? $in['amount'] : null;
    $res = shp_pay_order(intval($o->ShId), (string) ($in['method'] ?? ''), $amount, $staffId, (string) ($in['idem'] ?? ''));
    // Collected before preparation: the volunteer tells the customer how long it will take.
    if (empty($res['error']) && isset($in['wait']) && $in['wait'] !== '' && (string) $o->ShWantedAt === '') {
        shp_order_set_wait(intval($o->ShId), intval($in['wait']));
    }
    if (empty($res['error']) && !empty($in['then_deliver'])) {
        $t = shp_order_transition(intval($o->ShId), 'delivered', $staffId);
        if (!empty($t['error'])) $res['deliver_error'] = array('code' => (string) ($t['code'] ?? ''), 'msg' => (string) ($t['msg'] ?? ''));
    }
    $fresh = shp_order(intval($o->ShId), $tour);
    if ($fresh) $res['order'] = shp_till_order_out($fresh);
    return $res;
}

/**
 * Refunds money held on an order ('refund': amount, method, reason, idem), then optionally
 * cancels the whole order ('then' = 'order') or $in['qty'] of one line ('then' = 'line', 'line').
 * The right and the ceilings are checked by shp_refund().
 */
function shp_till_refund($me, array $in)
{
    $tour = intval($me->SfTournament);
    $staffId = intval($me->SfId);
    $o = shp_order_row(intval($in['order'] ?? 0));
    if (!$o || intval($o->ShTournament) !== $tour) return shp_err('order', 'ShErrOrder');
    $id = intval($o->ShId);
    $standId = intval($o->ShStand);
    $then = (string) ($in['then'] ?? '');
    if ($then !== '' && !shp_till_can_any($me, array('sell'), $standId)) return shp_err('forbidden', 'ShStfErrForbidden');
    $amount = array_key_exists('amount', $in) ? $in['amount'] : null;
    $res = shp_refund($id, $amount, (string) ($in['method'] ?? ''), $staffId, (string) ($in['idem'] ?? ''), (string) ($in['reason'] ?? ''));
    if (empty($res['error']) && $then !== '') {
        $c = $then === 'line' ? shp_order_cancel_line($id, intval($in['line'] ?? 0), intval($in['qty'] ?? 0), $staffId) : shp_order_cancel($id, $staffId, false);
        if (!empty($c['error'])) $res['cancel_error'] = array('code' => (string) ($c['code'] ?? ''), 'msg' => (string) ($c['msg'] ?? ''));
    }
    $fresh = shp_order($id, $tour);
    if ($fresh) $res['order'] = shp_till_order_out($fresh);
    $lim = shp_till_refund_limits($me);
    $res['refund_left'] = $lim['left'];
    return $res;
}

/* ------------------------------------------------------------------ */
/* Stock, stand                                                        */
/* ------------------------------------------------------------------ */

/** Product (and variant) of the volunteer's competition, with its stand: [product row, variant row|null] or null. */
function shp_till_product($me, $productId, $variantId)
{
    $p = shp_product($productId);
    if (!$p || intval($p->SpTournament) !== intval($me->SfTournament)) return null;
    $v = null;
    if (intval($variantId) > 0) {
        $v = shp_variant($variantId);
        if (!$v || intval($v->SwProduct) !== intval($p->SpId)) return null;
    }
    return array($p, $v);
}

/**
 * Stock changes of a stand ('stock' right on the product's stand). $in['action']: 'switch'
 * (+ 'on': the "sold out" switch), 'restock' or 'loss' (+ 'qty', + 'idem': applied once whatever
 * the number of times it is sent). Unlimited stock has nothing to restock. Returns
 * ['error' => 0, 'stock' => remaining] or an error.
 */
function shp_till_stock($me, array $in)
{
    $staffId = intval($me->SfId);
    $pv = shp_till_product($me, intval($in['product'] ?? 0), intval($in['variant'] ?? 0));
    if (!$pv) return shp_err('product', 'ShErrProduct');
    list($p, $v) = $pv;
    if (!shp_till_can_any($me, array('stock'), intval($p->SpStand))) return shp_err('forbidden', 'ShStfErrForbidden');
    $pid = intval($p->SpId);
    $vid = $v ? intval($v->SwId) : 0;
    $action = (string) ($in['action'] ?? '');

    if ($action === 'switch') {
        shp_stock_set_available($pid, $vid, !empty($in['on']));
        return array('error' => 0, 'stock' => shp_stock_left($pid, $vid));
    }
    if ($action !== 'restock' && $action !== 'loss') return shp_err('bad_request', 'ShErrBadRequest');
    $qty = intval($in['qty'] ?? 0);
    $idem = (string) ($in['idem'] ?? '');
    if ($qty < 1 || $qty > 9999) return shp_err('qty', 'ShErrQty', 9999);
    if (!shp_idem_ok($idem)) return shp_err('bad_request', 'ShErrBadRequest');
    $idem = strtolower($idem);   // bytes: a UUID is hexadecimal ASCII
    $tour = intval($me->SfTournament);

    safe_w_BeginTransaction();
    safe_w_sql("SELECT SpId FROM ShopProducts WHERE SpId = $pid FOR UPDATE");
    if ($vid > 0) safe_w_sql("SELECT SwId FROM ShopVariants WHERE SwId = $vid FOR UPDATE");
    if (safe_fetch(safe_w_sql("SELECT SmId FROM ShopStockMoves WHERE SmTournament = $tour AND SmIdem = " . StrSafe_DB($idem)))) {
        $left = shp_stock_left($pid, $vid);   // the same request, already applied
        safe_w_Rollback();
        return array('error' => 0, 'stock' => $left, 'existing' => true);
    }
    if (shp_stock_left($pid, $vid) === null) {
        safe_w_Rollback();
        return shp_err('unlimited', 'ShPosErrUnlimited');
    }
    $r = shp_stock_adjust($pid, $vid, $action === 'restock' ? $qty : -$qty, $action, $staffId);
    if (!empty($r['error'])) {
        safe_w_Rollback();
        return $r;
    }
    // The move just written (the product row is locked: nobody else writes one for it meanwhile).
    safe_w_sql("UPDATE ShopStockMoves SET SmIdem = " . StrSafe_DB($idem) . "
        WHERE SmTournament = $tour AND SmProduct = $pid AND SmVariant = $vid AND SmStaff = $staffId AND SmIdem IS NULL
        ORDER BY SmId DESC LIMIT 1");
    safe_w_Commit();
    return array('error' => 0, 'stock' => $r['stock'], 'existing' => false);
}

/** Opens or closes a stand ('manage' right). */
function shp_till_stand_open($me, $standId, $open)
{
    $stand = shp_stand($standId);
    if (!$stand || intval($stand->SdTournament) !== intval($me->SfTournament)) return shp_err('stand', 'ShErrStand');
    if (!shp_staff_can($me, 'manage', intval($stand->SdId))) return shp_err('forbidden', 'ShStfErrForbidden');
    safe_w_sql("UPDATE ShopStands SET SdOpen = " . ($open ? 1 : 0) . " WHERE SdId = " . intval($stand->SdId));
    return array('error' => 0, 'open' => (bool) $open);
}

/* ------------------------------------------------------------------ */
/* Cash and accounts                                                   */
/* ------------------------------------------------------------------ */

/**
 * Figures of the day, from the payment journal (done lines, signed: collections count positive,
 * refunds, cancellations and rejections negative). 'mine' = what this volunteer took; 'stand' =
 * the whole stand, only for a manager. Each: [['method', 'label', 'in', 'out', 'net', 'count']]
 * and 'total' (net).
 */
function shp_till_report($me, $standId)
{
    $tour = intval($me->SfTournament);
    $standId = intval($standId);
    $day = "DATE(BlgWhen) = DATE(" . shp_local_now_sql($tour) . ")";
    $labels = shp_pay_methods();
    $sum = function ($where) use ($tour, $day, $labels) {
        $rs = safe_r_sql("SELECT BlgMethod, BlgKind, COUNT(*) AS n, SUM(BlgAmount) AS s FROM BookingLedger
            WHERE BlgTournament = $tour AND BlgStatus = 'done' AND $day AND $where GROUP BY BlgMethod, BlgKind");
        $rows = array();
        while ($r = safe_fetch($rs)) {
            $m = (string) $r->BlgMethod;
            if (!isset($rows[$m])) $rows[$m] = array('method' => $m, 'label' => (string) ($labels[$m] ?? $m), 'in' => 0.0, 'out' => 0.0, 'net' => 0.0, 'count' => 0);
            $s = round((float) $r->s, 2);
            if ((string) $r->BlgKind === 'payment') { $rows[$m]['in'] += $s; $rows[$m]['count'] += intval($r->n); } else { $rows[$m]['out'] -= $s; }
            $rows[$m]['net'] = round($rows[$m]['in'] - $rows[$m]['out'], 2);
        }
        $total = 0.0;
        foreach ($rows as &$x) { $x['in'] = round($x['in'], 2); $x['out'] = round($x['out'], 2); $total += $x['net']; }
        unset($x);
        return array('rows' => array_values($rows), 'total' => round($total, 2));
    };
    $lim = shp_till_refund_limits($me);
    $out = array('mine' => $sum("BlgStaff = " . intval($me->SfId)), 'stand' => null,
        'refund_max' => $lim['max'], 'refund_left' => $lim['left'], 'refunded' => shp_staff_refunded($me));
    if ($standId > 0 && shp_staff_can($me, 'manage', $standId)) $out['stand'] = $sum("BlgStand = $standId");
    return $out;
}

/** Accounts of named people owing something at the stands, filtered by name: up to 40 rows. */
function shp_till_accounts($me, $q)
{
    $q = trim((string) $q);
    $out = array();
    foreach (shp_accounts_open(intval($me->SfTournament)) as $a) {
        if ($q !== '' && mb_stripos($a['name'] . ' ' . $a['account'], $q) === false) continue;
        $out[] = array('account' => $a['account'], 'name' => $a['name'], 'club' => $a['club'],
            'remaining' => $a['remaining'], 'whole' => $a['whole']);
        if (count($out) >= 40) break;
    }
    return $out;
}

/** Settles an account at the volunteer's stand ('cash' right on it): account, method, amount?, idem, stand. */
function shp_till_account_pay($me, array $in)
{
    $standId = intval($in['stand'] ?? 0);
    if (!shp_staff_can($me, 'cash', $standId)) return shp_err('forbidden', 'ShPayErrRight');
    $amount = array_key_exists('amount', $in) ? $in['amount'] : null;
    return shp_pay_account(intval($me->SfTournament), (string) ($in['account'] ?? ''), $amount,
        (string) ($in['method'] ?? ''), intval($me->SfId), (string) ($in['idem'] ?? ''), $standId);
}
