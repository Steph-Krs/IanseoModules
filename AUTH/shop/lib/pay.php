<?php
/**
 * lib/pay.php — money of the points of sale: collecting an order, settling an account,
 * refunding, and the part of the food & shop in an archer's account.
 *
 * ONE journal for everything: BookingLedger, the journal of the online registration's
 * payments. An archer has one account per competition (registrations, shop, stands), so that
 * "open an account, consume, pay at the end" works, and one online payment later covers it
 * all. Sales without a name go to technical accounts: 'G<id>' (guest) and 'C' (counter).
 *
 * The journal is the truth. ShopOrders.ShPaid / ShPayState are a cache, recomputed from it
 * after every movement of an order (shp_order_pay_state). Payments of an account as a whole
 * (shp_pay_account) are not earmarked to orders: an order put on the account stays 'tab',
 * and the account shows what is left.
 *
 * Concurrency and repeats:
 *  - every write carries an idempotency key (the phone's UUID): sent twice, it writes once
 *    and answers with the first line ('existing' => true);
 *  - collecting or refunding an order locks the order row, then (refund by a volunteer) the
 *    volunteer row, always in that order: two volunteers pressing "Collect" on the same order
 *    collect it once, and a volunteer's refund ceiling cannot be passed by two refunds at once;
 *  - an account payment locks the competition's settings row: one account payment at a time.
 *
 * Rights are checked here too, not only by the pages: 'cash' to collect, 'refund' to refund
 * with the volunteer's ceilings (shp_staff_refund_check). $staffId = 0 means the organiser,
 * from an organiser page that has already checked the ianseo rights — never pass 0 from a
 * volunteer's or a customer's endpoint.
 */

if (defined('SHP_PAY_LOADED')) return;
define('SHP_PAY_LOADED', true);

require_once __DIR__ . '/orders.php';
require_once dirname(__DIR__, 2) . '/booking/lib/payment.php';
require_once dirname(__DIR__, 2) . '/pay-lib.php';
if (is_file(dirname(__DIR__, 2) . '/trust-lib.php')) require_once dirname(__DIR__, 2) . '/trust-lib.php';

/** Means a volunteer can choose at the till: [code => label], 'tab' = put on the account. */
function shp_pay_methods()
{
    return aut_pay_methods('shop');
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** Active volunteer $staffId of competition $tourId (row), or null. */
function shp_pay_staff($staffId, $tourId)
{
    // Same statuses as shp_staff_working(): a locked volunteer keeps working on a phone already signed in.
    $r = safe_fetch(safe_w_sql("SELECT * FROM ShopStaff WHERE SfId = " . intval($staffId)
        . " AND SfTournament = " . intval($tourId) . " AND SfStatus IN ('active', 'locked')"));
    return $r ?: null;
}

/** Has this volunteer the right $perm on stand $standId? */
function shp_pay_staff_can($staff, $perm, $standId)
{
    if (function_exists('shp_staff_can')) return (bool) shp_staff_can($staff, $perm, $standId);
    if ((string) $staff->SfKind === 'ORGANISER') return true;
    $r = safe_fetch(safe_r_sql("SELECT StPerms FROM ShopStaffStands WHERE StStaff = " . intval($staff->SfId)
        . " AND StStand = " . intval($standId)));
    return $r && in_array($perm, explode(',', (string) $r->StPerms), true);
}

/**
 * Who wrote a journal line (BlgBy). A volunteer is recorded by number, not by name: the
 * names of volunteers without a licence are erased the day after the competition, the
 * journal is kept. The number is the volunteer's rank in their competition, as in
 * shp_staff_label(); pages show the current name from BlgStaff.
 */
function shp_pay_by($staffId)
{
    $staffId = intval($staffId);
    if ($staffId <= 0) return (string) ($_SESSION['AUTH_User'] ?? '');
    $r = safe_fetch(safe_w_sql("SELECT COUNT(*) AS n FROM ShopStaff
        WHERE SfTournament = (SELECT SfTournament FROM ShopStaff WHERE SfId = $staffId) AND SfId <= $staffId"));
    return shp_t('ShPayStaffN', $r && intval($r->n) > 0 ? intval($r->n) : $staffId);
}

/** Idempotency key: '' → a new one (server side), malformed → false. */
function shp_pay_idem($idem)
{
    $idem = (string) $idem;
    if ($idem === '') return shp_idem_new();
    return shp_idem_ok($idem) ? strtolower($idem) : false;   // bytes: a UUID is ASCII
}

/** Amount asked: null or '' = the default; else a float rounded to the cent (≤ 0 kept, refused later). */
function shp_pay_amount($amount)
{
    if ($amount === null || $amount === '') return null;
    return round(is_string($amount) ? bk_money_in($amount) : (float) $amount, 2);
}

/** What the journal holds for an order (done lines, signed), read on the write connection. */
function shp_order_paid($orderId, $tourId)
{
    $r = safe_fetch(safe_w_sql("SELECT COALESCE(SUM(BlgAmount), 0) AS p FROM BookingLedger
        WHERE BlgOrder = " . intval($orderId) . " AND BlgTournament = " . intval($tourId) . " AND BlgStatus = 'done'"));
    return $r ? round((float) $r->p, 2) : 0.0;
}

/** Answer of a payment operation: the journal line and the fresh state of the order. */
function shp_pay_answer($orderId, $line, $existing)
{
    $o = shp_order_row($orderId);
    $out = array('error' => 0, 'line' => intval($line), 'existing' => (bool) $existing, 'order' => intval($orderId));
    if ($o) {
        $out += array('number' => (string) $o->ShNumber, 'status' => (string) $o->ShStatus,
            'pay_mode' => (string) $o->ShPayMode, 'pay_state' => (string) $o->ShPayState,
            'total' => (float) $o->ShTotal, 'paid' => (float) $o->ShPaid,
            'due' => round(max(0, (float) $o->ShTotal - (float) $o->ShPaid), 2));
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Payment state of an order                                           */
/* ------------------------------------------------------------------ */

/**
 * Recomputes ShPaid and ShPayState of an order from the journal:
 *   paid      the journal covers the total;
 *   partial   something is held, not the total (or a refund is waiting for a line cancellation);
 *   refunded  nothing held any more, after a refund;
 *   tab       on the account, nothing paid on the order itself;
 *   unpaid    otherwise.
 * Returns ['paid', 'state', 'due'] or null.
 */
function shp_order_pay_state($orderId)
{
    $o = shp_order_row($orderId);
    if (!$o) return null;
    $id = intval($o->ShId);
    $r = safe_fetch(safe_w_sql("SELECT COALESCE(SUM(BlgAmount), 0) AS p,
            COALESCE(SUM(BlgKind = 'refund' AND BlgCancelled = 0), 0) AS refunds
        FROM BookingLedger WHERE BlgOrder = $id AND BlgTournament = " . intval($o->ShTournament) . " AND BlgStatus = 'done'"));
    $paid = round((float) $r->p, 2);
    $total = round((float) $o->ShTotal, 2);
    if ($total > 0.004 && $paid >= $total - 0.004) $state = 'paid';
    elseif ($paid > 0.004) $state = 'partial';
    elseif (intval($r->refunds) > 0) $state = 'refunded';
    elseif ((string) $o->ShPayMode === 'tab') $state = 'tab';
    else $state = 'unpaid';
    if (abs($paid - (float) $o->ShPaid) > 0.004 || $state !== (string) $o->ShPayState) {
        safe_w_sql("UPDATE ShopOrders SET ShPaid = " . StrSafe_DB(number_format($paid, 2, '.', ''))
            . ", ShPayState = " . StrSafe_DB($state) . " WHERE ShId = $id");
    }
    return array('paid' => $paid, 'state' => $state, 'due' => round(max(0, $total - $paid), 2));
}

/* ------------------------------------------------------------------ */
/* Collecting                                                          */
/* ------------------------------------------------------------------ */

/**
 * Collects an order at a stand. $method: a means of shp_pay_methods(); 'tab' puts the order
 * on the archer's account instead (no money, no journal line). $amount: null = what is left
 * on the order; never more than that (the change for cash is given back by the till, the
 * journal records what the order cost). A counter sale created already handed over
 * ('deliver_now') is collected right after, in the same request, with the same key.
 * Returns shp_pay_answer() or ['error' => 1, 'code', 'msg', …].
 */
function shp_pay_order($orderId, $method, $amount, $staffId, $idem)
{
    shp_schema();
    $o = shp_order_row($orderId);
    if (!$o) return shp_err('order', 'ShErrOrder');
    $tourId = intval($o->ShTournament);
    $id = intval($o->ShId);
    $staffId = intval($staffId);
    $idem = shp_pay_idem($idem);
    if ($idem === false) return shp_err('bad_request', 'ShErrBadRequest');
    if ($line = bk_ledger_by_idem($tourId, $idem)) return shp_pay_answer($id, $line, true);

    $staff = null;
    if ($staffId > 0) {
        $staff = shp_pay_staff($staffId, $tourId);
        if (!$staff || !shp_pay_staff_can($staff, 'cash', intval($o->ShStand))) return shp_err('forbidden', 'ShPayErrRight');
    }
    if ($method === 'tab') return shp_order_to_tab($o, $staffId);
    if (!aut_pay_ledger_method($method) || !isset(shp_pay_methods()[$method])) return shp_err('method', 'ShPayErrMethod');
    $amount = shp_pay_amount($amount);
    if ($amount !== null && ($amount <= 0 || $amount > 999999)) return shp_err('amount', 'ShPayErrAmount');

    safe_w_BeginTransaction();
    safe_w_sql("SELECT ShId FROM ShopOrders WHERE ShId = $id FOR UPDATE");
    if ($line = bk_ledger_by_idem($tourId, $idem)) {   // the same request, served while we waited
        safe_w_Rollback();
        return shp_pay_answer($id, $line, true);
    }
    $o = shp_order_row($id);
    if ((string) $o->ShStatus === 'cancelled') {
        safe_w_Rollback();
        return shp_err('cancelled', 'ShErrCancelled');
    }
    $rest = round((float) $o->ShTotal - shp_order_paid($id, $tourId), 2);
    if ($rest <= 0.004) {
        safe_w_Rollback();
        return shp_err('nothing_due', 'ShPayErrNothingDue', null, array('due' => 0));
    }
    if ($amount === null) $amount = $rest;
    if ($amount > $rest + 0.004) {
        safe_w_Rollback();
        return shp_err('too_much', 'ShPayErrTooMuch', shp_money($rest, $tourId), array('due' => $rest));
    }
    $line = bk_ledger_add($tourId, shp_order_account($o), 'payment', $amount, $method, '',
        shp_t('ShPayLblOrder', (string) $o->ShNumber), shp_pay_by($staffId), 0,
        array('order' => $id, 'stand' => intval($o->ShStand), 'staff' => $staffId, 'idem' => $idem));
    if (!$line) {
        safe_w_Rollback();
        return shp_err('internal', 'ShErrInternal');
    }
    $existing = bk_ledger_existing();
    shp_order_pay_state($id);
    safe_w_Commit();
    return shp_pay_answer($id, $line, $existing);
}

/**
 * Puts an order on the archer's account ("on my account", at the till): a signed-in licensee
 * served by name, the competition allowing it, nothing paid on the order yet, and the payer
 * trust index not refusing credit. No journal line: the order counts in the account's due.
 */
function shp_order_to_tab($o, $staffId = 0)
{
    $id = intval($o->ShId);
    $tourId = intval($o->ShTournament);
    if ((string) $o->ShPayMode === 'tab') return shp_pay_answer($id, 0, true);
    if ((string) $o->ShStatus === 'cancelled') return shp_err('cancelled', 'ShErrCancelled');
    $licence = (string) $o->ShLicence;
    $set = shp_settings($tourId);
    if ((string) $o->ShCustKind !== 'ARCHER' || $licence === '' || !$set || intval($set->SgTab) !== 1) {
        return shp_err('tab_refused', 'ShErrTabRefused');
    }
    if ((float) $o->ShPaid > 0.004) return shp_err('paid', 'ShPayErrAlreadyPaid');
    if (intval($set->SgTrustGate) === 1 && function_exists('aut_trust_gate')) {
        $gate = aut_trust_gate($licence, $tourId, 'shop_tab');
        if (is_array($gate) && empty($gate['allow'])) {
            return array('error' => 1, 'code' => 'trust', 'msg' => (string) ($gate['msg'] ?? shp_t('ShErrTabRefused')));
        }
    }
    safe_w_sql("UPDATE ShopOrders SET ShPayMode = 'tab', ShPayState = 'tab'
        WHERE ShId = $id AND ShStatus <> 'cancelled' AND ShPaid <= 0.004");
    if (safe_w_affected_rows() < 1) {
        $cur = shp_order_row($id);
        if (!$cur || (string) $cur->ShPayMode !== 'tab') return shp_err('changed', 'ShErrChanged');
    }
    return shp_pay_answer($id, 0, false);
}

/* ------------------------------------------------------------------ */
/* Accounts                                                            */
/* ------------------------------------------------------------------ */

/** SQL: journal account of an order (same rule as shp_account_key()). */
function shp_account_sql()
{
    return "(CASE WHEN ShCustKind = 'ARCHER' AND ShLicence <> '' THEN ShLicence
        WHEN ShCustKind = 'GUEST' AND ShGuest > 0 THEN CONCAT('G', ShGuest) ELSE 'C' END)";
}

/** SQL condition: the orders of one account. */
function shp_account_where($account)
{
    $account = (string) $account;
    if ($account === 'C') {
        return "(ShCustKind NOT IN ('ARCHER', 'GUEST') OR (ShCustKind = 'ARCHER' AND ShLicence = '')
            OR (ShCustKind = 'GUEST' AND ShGuest = 0))";
    }
    if (preg_match('/^G(\d+)$/', $account, $m)) return "ShCustKind = 'GUEST' AND ShGuest = " . intval($m[1]);
    return "ShCustKind = 'ARCHER' AND ShLicence = " . StrSafe_DB($account);
}

/**
 * SQL condition: the orders that count in what an account owes. A cancelled order never; an
 * order put on the account always (pre-orders included); otherwise once handed over or once
 * money is held on it. An order placed from a phone and never fetched nor paid is nobody's
 * debt.
 */
function shp_due_where()
{
    return "ShStatus <> 'cancelled' AND (ShPayMode = 'tab' OR ShStatus = 'delivered' OR ShPaid > 0)";
}

/** Part of the food & shop in what account $account owes on competition $tourId. */
function shp_account_due($tourId, $account)
{
    $r = safe_fetch(safe_r_sql("SELECT COALESCE(SUM(ShTotal), 0) AS t FROM ShopOrders
        WHERE ShTournament = " . intval($tourId) . " AND " . shp_account_where($account) . " AND " . shp_due_where()));
    return $r ? round((float) $r->t, 2) : 0.0;
}

/** Part of shp_account_due() made of the orders moved from the former shop of the online registration. */
function shp_account_legacy($tourId, $account)
{
    $r = safe_fetch(safe_r_sql("SELECT COALESCE(SUM(ShTotal), 0) AS t FROM ShopOrders
        WHERE ShTournament = " . intval($tourId) . " AND ShLegacy = 1 AND " . shp_account_where($account) . " AND " . shp_due_where()));
    return $r ? round((float) $r->t, 2) : 0.0;
}

/** Same for every account of a competition, one query: [account => ['due', 'label']]. */
function shp_accounts_due($tourId)
{
    $out = array();
    $rs = safe_r_sql("SELECT " . shp_account_sql() . " AS k, SUM(ShTotal) AS t, MAX(ShCustLabel) AS l FROM ShopOrders
        WHERE ShTournament = " . intval($tourId) . " AND " . shp_due_where() . " GROUP BY k");
    while ($r = safe_fetch($rs)) $out[(string) $r->k] = array('due' => round((float) $r->t, 2), 'label' => (string) $r->l);
    return $out;
}

/**
 * Lines bought by an account (orders that count in its due), for the receipts:
 * [['order', 'number', 'stand', 'date', 'label', 'qty', 'unit', 'amount', 'tab']].
 */
function shp_account_lines($tourId, $account)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT ShId, ShNumber, ShStand, ShCreated, ShPayMode, SnLabel, SnUnit, SnQty - SnCancelled AS q
        FROM ShopOrderLines INNER JOIN ShopOrders ON ShId = SnOrder
        WHERE ShTournament = $tourId AND " . shp_account_where($account) . " AND " . shp_due_where() . "
          AND SnQty > SnCancelled
        ORDER BY ShCreated, ShId, SnId");
    $rows = array();
    while ($r = safe_fetch($rs)) $rows[] = $r;
    if (!$rows) return array();
    $stands = shp_stands($tourId, false);
    $out = array();
    foreach ($rows as $r) {
        $s = $stands[intval($r->ShStand)] ?? null;
        $out[] = array('order' => intval($r->ShId), 'number' => (string) $r->ShNumber,
            'stand' => $s ? (string) $s->SdName : '', 'date' => (string) $r->ShCreated, 'label' => (string) $r->SnLabel,
            'qty' => intval($r->q), 'unit' => (float) $r->SnUnit, 'amount' => round(intval($r->q) * (float) $r->SnUnit, 2),
            'tab' => (string) $r->ShPayMode === 'tab');
    }
    return $out;
}

/** Nickname of a guest, '' once erased. */
function shp_guest_label($tourId, $guestId)
{
    $r = safe_fetch(safe_r_sql("SELECT SuPseudo FROM ShopGuests WHERE SuId = " . intval($guestId)
        . " AND SuTournament = " . intval($tourId)));
    return $r ? (string) $r->SuPseudo : '';
}

/**
 * Balance of an account as the till shows it: when the competition also uses the payments of
 * the online registration, the WHOLE account (registrations, shop, stands) — it can be settled
 * in one go at a stand; otherwise the food & shop part only.
 * ['account', 'name', 'whole', 'due', 'shp', 'paid', 'remaining'].
 */
function shp_account_state($tourId, $account)
{
    $tourId = intval($tourId);
    $a = bk_account($tourId, $account);
    $whole = bk_comp_payments_on(bk_comp_config($tourId));
    $due = $whole ? $a['due'] : $a['shp'];
    return array('account' => (string) $account, 'name' => (string) $a['name'], 'whole' => $whole,
        'due' => $due, 'shp' => $a['shp'], 'paid' => $a['paid'], 'remaining' => round($due - $a['paid'], 2));
}

/**
 * Accounts of people who bought at the stands and still owe something, for the till's
 * "Accounts" list: [shp_account_state()-like rows], largest first. Accounts without a name
 * (counter, guests) are not listed: a guest pays each order at the counter.
 */
function shp_accounts_open($tourId)
{
    $tourId = intval($tourId);
    $whole = bk_comp_payments_on(bk_comp_config($tourId));
    $out = array();
    foreach (bk_accounts($tourId) as $k => $a) {
        if (!empty($a['synthetic']) || $a['shp'] <= 0.004) continue;
        $due = $whole ? $a['due'] : $a['shp'];
        $left = round($due - $a['paid'], 2);
        if ($left <= 0.004) continue;
        $out[] = array('account' => (string) $k, 'name' => (string) $a['name'], 'club' => (string) $a['club_name'],
            'whole' => $whole, 'due' => $due, 'shp' => $a['shp'], 'paid' => $a['paid'], 'remaining' => $left);
    }
    usort($out, function ($x, $y) { return $y['remaining'] <=> $x['remaining'] ?: strcasecmp($x['name'], $y['name']); });
    return $out;
}

/**
 * Settles an account at a stand (end of the competition, "I pay everything now"). $amount:
 * null = what is left (shp_account_state). $standId: where the money was taken, for the cash
 * report of that stand; required from a volunteer. Not earmarked to orders.
 * Returns ['error' => 0, 'line', 'existing', 'account', 'remaining'] or an error.
 */
function shp_pay_account($tourId, $account, $amount, $method, $staffId, $idem, $standId = 0)
{
    shp_schema();
    $tourId = intval($tourId);
    $account = (string) $account;
    $staffId = intval($staffId);
    $standId = intval($standId);
    if (!preg_match('/^(G\d+|[A-Z0-9]{1,25})$/', $account) || $account === 'C' || $account === 'ANON') {
        return shp_err('account', 'ShPayErrAccount');
    }
    $idem = shp_pay_idem($idem);
    if ($idem === false) return shp_err('bad_request', 'ShErrBadRequest');
    if ($line = bk_ledger_by_idem($tourId, $idem)) {
        return array('error' => 0, 'line' => $line, 'existing' => true, 'account' => $account,
            'remaining' => shp_account_state($tourId, $account)['remaining']);
    }
    if ($standId > 0) {
        $stand = shp_stand($standId);
        if (!$stand || intval($stand->SdTournament) !== $tourId) return shp_err('stand', 'ShErrStand');
    }
    if ($staffId > 0) {
        $staff = shp_pay_staff($staffId, $tourId);
        if (!$staff || $standId <= 0 || !shp_pay_staff_can($staff, 'cash', $standId)) return shp_err('forbidden', 'ShPayErrRight');
    }
    if (!aut_pay_ledger_method($method) || !isset(shp_pay_methods()[$method])) return shp_err('method', 'ShPayErrMethod');
    $amount = shp_pay_amount($amount);
    if ($amount !== null && ($amount <= 0 || $amount > 999999)) return shp_err('amount', 'ShPayErrAmount');
    if (!shp_settings($tourId)) return shp_err('shop_off', 'ShErrShopOff');
    bk_ledger_migrate($tourId);   // writes, if any, before the lock

    safe_w_BeginTransaction();
    safe_w_sql("SELECT SgTournament FROM ShopSettings WHERE SgTournament = $tourId FOR UPDATE");
    if ($line = bk_ledger_by_idem($tourId, $idem)) {
        safe_w_Rollback();
        return array('error' => 0, 'line' => $line, 'existing' => true, 'account' => $account,
            'remaining' => shp_account_state($tourId, $account)['remaining']);
    }
    $st = shp_account_state($tourId, $account);
    if ($st['remaining'] <= 0.004) {
        safe_w_Rollback();
        return shp_err('nothing_due', 'ShPayErrNothingAccount', null, array('remaining' => $st['remaining']));
    }
    if ($amount === null) $amount = $st['remaining'];
    if ($amount > $st['remaining'] + 0.004) {
        safe_w_Rollback();
        return shp_err('too_much', 'ShPayErrTooMuch', shp_money($st['remaining'], $tourId), array('remaining' => $st['remaining']));
    }
    $line = bk_ledger_add($tourId, $account, 'payment', $amount, $method, '', shp_t('ShPayLblAccount'), shp_pay_by($staffId), 0,
        array('stand' => $standId, 'staff' => $staffId, 'idem' => $idem));
    if (!$line) {
        safe_w_Rollback();
        return shp_err('internal', 'ShErrInternal');
    }
    $existing = bk_ledger_existing();
    safe_w_Commit();
    return array('error' => 0, 'line' => $line, 'existing' => $existing, 'account' => $account,
        'remaining' => round($st['remaining'] - ($existing ? 0 : $amount), 2));
}

/* ------------------------------------------------------------------ */
/* Refunds                                                             */
/* ------------------------------------------------------------------ */

/**
 * Refunds money held on an order. Never more than what the journal holds for that order; a
 * reason is required. A volunteer needs the right 'refund' on the order's stand and stays
 * within their ceilings (per refund and in total, shp_staff_refund_check); while that check
 * is not available, volunteers cannot refund at all — only the organiser ($staffId = 0).
 * To cancel a paid order: refund first, then cancel (shp_order_cancel / shp_order_cancel_line).
 * $amount null = everything held. Returns shp_pay_answer() or an error.
 */
function shp_refund($orderId, $amount, $method, $staffId, $idem, $reason)
{
    shp_schema();
    $o = shp_order_row($orderId);
    if (!$o) return shp_err('order', 'ShErrOrder');
    $tourId = intval($o->ShTournament);
    $id = intval($o->ShId);
    $staffId = intval($staffId);
    $idem = shp_pay_idem($idem);
    if ($idem === false) return shp_err('bad_request', 'ShErrBadRequest');
    if ($line = bk_ledger_by_idem($tourId, $idem)) return shp_pay_answer($id, $line, true);

    $reason = mb_substr(trim((string) preg_replace('/[\p{C}\s]+/u', ' ', (string) $reason)), 0, 120);
    if ($reason === '') return shp_err('reason', 'ShPayErrReason');
    if (!aut_pay_ledger_method($method) || !isset(shp_pay_methods()[$method])) return shp_err('method', 'ShPayErrMethod');
    $amount = shp_pay_amount($amount);
    if ($amount !== null && ($amount <= 0 || $amount > 999999)) return shp_err('amount', 'ShPayErrAmount');
    if ($staffId > 0) {
        if (!function_exists('shp_staff_refund_check')) return shp_err('refund_off', 'ShPayErrRefundOff');
        $staff = shp_pay_staff($staffId, $tourId);
        if (!$staff || !shp_pay_staff_can($staff, 'refund', intval($o->ShStand))) return shp_err('forbidden', 'ShPayErrRefundRight');
    }

    safe_w_BeginTransaction();
    safe_w_sql("SELECT ShId FROM ShopOrders WHERE ShId = $id FOR UPDATE");
    $staff = $staffId > 0 ? safe_fetch(safe_w_sql("SELECT * FROM ShopStaff WHERE SfId = $staffId FOR UPDATE")) : null;
    if ($line = bk_ledger_by_idem($tourId, $idem)) {
        safe_w_Rollback();
        return shp_pay_answer($id, $line, true);
    }
    $held = shp_order_paid($id, $tourId);
    if ($held <= 0.004) {
        safe_w_Rollback();
        return shp_err('nothing_paid', 'ShPayErrNothingPaid');
    }
    if ($amount === null) $amount = $held;
    if ($amount > $held + 0.004) {
        safe_w_Rollback();
        return shp_err('too_much', 'ShPayErrRefundTooMuch', shp_money($held, $tourId), array('paid' => $held));
    }
    if ($staff) {
        $chk = shp_staff_refund_check($staff, $amount, true);   // inside the transaction: reads what is locked
        if (!is_array($chk) || empty($chk['ok'])) {
            safe_w_Rollback();
            return array('error' => 1, 'code' => 'ceiling',
                'msg' => (string) (is_array($chk) && !empty($chk['msg']) ? $chk['msg'] : shp_t('ShPayErrCeiling')),
                'left' => is_array($chk) ? (float) ($chk['left'] ?? 0) : 0.0);
        }
    }
    $line = bk_ledger_add($tourId, shp_order_account($o), 'refund', $amount, $method, '',
        shp_t('ShPayLblRefund', array('number' => (string) $o->ShNumber, 'reason' => $reason)), shp_pay_by($staffId), 0,
        array('order' => $id, 'stand' => intval($o->ShStand), 'staff' => $staffId, 'idem' => $idem));
    if (!$line) {
        safe_w_Rollback();
        return shp_err('internal', 'ShErrInternal');
    }
    $existing = bk_ledger_existing();
    shp_order_pay_state($id);
    safe_w_Commit();
    return shp_pay_answer($id, $line, $existing);
}
