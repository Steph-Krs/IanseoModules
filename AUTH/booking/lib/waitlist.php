<?php
/**
 * lib/waitlist.php — waiting list for full departures.
 *
 * An archer whose profile (weapon, category, target face) no longer fits a departure
 * joins the list through the NORMAL registration form: a full departure stays selectable,
 * and the form then queues instead of registering (register-comp.php), keeping every
 * choice made there — wishes and payment choice included. When a place frees for that
 * profile, the FIRST compatible archer of the list is registered automatically through
 * bk_register(): every rule of a normal registration applies (manual validation,
 * category, finished competition…). The archer is told on the site (home banner, "My
 * registrations"), never by e-mail; if they no longer want the place they cancel it like
 * any registration, which frees it for the next one.
 *
 * Fairness: a freed place goes to the list before anyone else — register-comp.php runs
 * bk_waitlist_process() before computing the places it offers.
 *
 * Places are looked for after an online cancellation, on the registration page, on the
 * archer's home page (lists they are on), on the organiser's page, and every 10 minutes
 * by cron/waitlist.php — places freed in ianseo's own screens (a participant deleted,
 * targets added) are seen by no booking page. Once registrations close the list is
 * frozen: the organiser keeps it and can register or remove by hand.
 */

if (defined('BK_WAITLIST_LOADED')) return;
define('BK_WAITLIST_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/targets.php';
require_once __DIR__ . '/payment.php';   // bk_payment_declare

/** Is the waiting list offered? Always at level 2, a checkbox at level 3 (BcWaitlist). */
function bk_waitlist_on($cfg)
{
    return !empty($cfg->BcOpen) && (!isset($cfg->BcWaitlist) || !empty($cfg->BcWaitlist));
}

/**
 * SQL condition: rows of this archer — about their licence, or put on the list by them
 * for a clubmate. An author id of 0 matches nothing: it is "no author", not an archer.
 */
function bk_waitlist_who_sql($archerId, $licence, $alias = '')
{
    $a = $alias !== '' ? $alias . '.' : '';
    return '(' . $a . 'BwLicence = ' . StrSafe_DB(bk_clean_licence($licence))
        . (intval($archerId) > 0 ? ' OR ' . $a . 'BwArcher = ' . intval($archerId) : '') . ')';
}

/**
 * Is this departure full for the profile? $session: a row of bk_comp_sessions(); $profileLeft:
 * places left for the profile (bk_profile_remaining, null = no constraint known).
 */
function bk_waitlist_full($session, $profileLeft)
{
    return intval($session->Places) - intval($session->Pris) <= 0 || ($profileLeft !== null && intval($profileLeft) < 1);
}

/** Declares the payment choice kept with a waiting row ("method|when"), once registered. */
function bk_waitlist_declare_payment($tourId, $w)
{
    $pc = explode('|', (string) $w->BwPayChoice, 2);
    if (count($pc) === 2) bk_payment_declare($tourId, $w->BwLicence, $pc[0], $pc[1]);
}

/** SQL expression: current local time of the competition (lib/clock.php). */
function bk_waitlist_now_sql($tourId)
{
    return bk_local_now_sql('(SELECT ToTimeZone FROM Tournament WHERE ToId = ' . intval($tourId) . ')');
}

/**
 * Joins the list. $session 0 = any departure. Same rules as a registration except that
 * the departure is full ($by as for bk_register; $wishes: letter, with, request, and pay
 * = the payment choice "method|when" of the form). ['ok' => bool, 'msg' => …].
 */
function bk_waitlist_join($tourId, $cfg, $lue, $division, $class, $face, $session, $by, $wishes)
{
    $tourId = intval($tourId);
    $session = intval($session);
    $lic = bk_clean_licence($lue->LueCode);
    if (!bk_waitlist_on($cfg)) {
        return array('ok' => false, 'msg' => bk_t('WlNone'));
    }
    $orders = array();
    foreach (bk_comp_sessions($tourId) as $s) {
        if (!$session || intval($s->SesOrder) === $session) $orders[] = intval($s->SesOrder);
    }
    if (!$orders) return array('ok' => false, 'msg' => bk_t('WlNoDep'));
    $err = '';
    foreach ($orders as $o) {
        $err = bk_reg_blocked($tourId, $cfg, $lic, $lue->LueCountry, $division, $class, $o, $lue, true);
        if ($err === '') break;
    }
    if ($err !== '') return array('ok' => false, 'msg' => $err);

    $dup = safe_fetch(safe_r_sql("SELECT BwId FROM BookingWaitlist
        WHERE BwTournament = $tourId AND BwStatus = 0
          AND BwLicence = " . StrSafe_DB($lic) . " AND BwDivision = " . StrSafe_DB($division)));
    if ($dup) {
        return array('ok' => false, 'msg' => bk_t('WlAlready'));
    }
    safe_w_sql("INSERT INTO BookingWaitlist SET
        BwTournament = $tourId,
        BwLicence = "    . StrSafe_DB($lic) . ",
        BwArcher = "     . intval($by['archer'] ?? 0) . ",
        BwByRole = "     . StrSafe_DB($by['role'] ?? 'SELF') . ",
        BwBy = "         . StrSafe_DB(mb_substr((string) ($by['who'] ?? ''), 0, 64)) . ",
        BwDivision = "   . StrSafe_DB($division) . ",
        BwClass = "      . StrSafe_DB($class) . ",
        BwFace = "       . intval($face) . ",
        BwSession = $session,
        BwWantLetter = " . StrSafe_DB(mb_substr(trim((string) ($wishes['letter'] ?? '')), 0, 2)) . ",
        BwWantWith = "   . StrSafe_DB(bk_clean_licence($wishes['with'] ?? '')) . ",
        BwRequest = "    . StrSafe_DB(mb_substr(trim((string) ($wishes['request'] ?? '')), 0, 2000)) . ",
        BwPayChoice = "  . StrSafe_DB(preg_match('/^[A-Za-z0-9_]{1,20}\|(before|onsite)$/', (string) ($wishes['pay'] ?? ''))
                                ? (string) $wishes['pay'] : '') . ",
        BwCreated = "    . bk_waitlist_now_sql($tourId));
    return array('ok' => true, 'msg' => '');
}

/** Closes a row that can no longer succeed; the archer sees why in "Mes inscriptions". */
function bk_waitlist_close($id, $tourId, $note)
{
    safe_w_sql("UPDATE BookingWaitlist SET BwStatus = 2, BwSeen = 0, BwNote = " . StrSafe_DB(mb_substr($note, 0, 120))
        . ", BwDone = " . bk_waitlist_now_sql($tourId) . " WHERE BwId = " . intval($id));
}

/**
 * Registrations still waiting for the organiser's validation on a departure, for one
 * profile. Not placed yet, so the target-face capacity does not count them: without
 * this, a competition in manual validation would register the whole list at once.
 */
function bk_waitlist_pending($tourId, $session, $division, $class, $face)
{
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM BookingRegistrations
        WHERE BrTournament = " . intval($tourId) . " AND BrValidated = 0 AND BrSession = " . intval($session) . "
          AND BrDivision = " . StrSafe_DB($division) . " AND BrClass = " . StrSafe_DB($class) . "
          AND BrFace = " . intval($face)));
    return $r ? intval($r->n) : 0;
}

/**
 * Registers the first compatible archers of the list for every place freed. Returns the
 * number of archers registered. Cheap when nobody waits (one indexed query); one run at
 * a time per competition (GET_LOCK).
 */
function bk_waitlist_process($tourId)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT BwId FROM BookingWaitlist WHERE BwTournament = $tourId AND BwStatus = 0 LIMIT 1", false, true);
    if (!$rs || !safe_fetch($rs)) return 0;
    $cfg = bk_comp_config($tourId);
    if (!bk_waitlist_on($cfg) || empty($cfg->BcIsOpen) || bk_comp_finished($tourId)) return 0;

    $lockName = "CONCAT('bkwl:', DATABASE(), ':', $tourId)";
    $lk = safe_fetch(safe_r_sql("SELECT GET_LOCK($lockName, 0) AS l"));
    if (!$lk || !intval($lk->l)) return 0;   // another request is already on it

    $done = 0;
    try {
        $orders = array();
        foreach (bk_comp_sessions($tourId) as $s) $orders[] = intval($s->SesOrder);
        $rows = array();
        $q = safe_r_sql("SELECT * FROM BookingWaitlist WHERE BwTournament = $tourId AND BwStatus = 0 ORDER BY BwId");
        while ($r = safe_fetch($q)) $rows[] = $r;
        $rem = array();   // places left per departure and profile, for this run

        foreach ($rows as $w) {
            $lue = bk_lookup_licence($w->BwLicence);
            if (!$lue) continue;   // not in today's federal file: next time
            if (!array_key_exists($w->BwClass, bk_reg_classes($tourId, $lue->LueCtrlCode, $lue->LueSex, $w->BwDivision))) {
                bk_waitlist_close($w->BwId, $tourId, bk_t('WlCatGone'));
                continue;
            }
            $taken = array();
            foreach (bk_reg_existing($tourId, $w->BwLicence) as $e) $taken[intval($e->QuSession)] = true;
            $cands = array();
            foreach (intval($w->BwSession) ? array(intval($w->BwSession)) : $orders as $o) {
                if (!isset($taken[$o]) && in_array($o, $orders, true)) $cands[] = $o;
            }
            if (!$cands) {
                bk_waitlist_close($w->BwId, $tourId, bk_t('WlAlreadyDep'));
                continue;
            }
            foreach ($cands as $o) {
                if (bk_reg_session_left($tourId, $o) < 1) continue;
                $k = $o . '|' . $w->BwDivision . '|' . $w->BwClass . '|' . intval($w->BwFace);
                if (!array_key_exists($k, $rem)) {
                    $left = bk_with_tournament($tourId, function () use ($tourId, $o, $w) {
                        return bk_profile_remaining($tourId, $o, $w->BwDivision, $w->BwClass, intval($w->BwFace));
                    });
                    $rem[$k] = ($left === null) ? null
                        : intval($left) - bk_waitlist_pending($tourId, $o, $w->BwDivision, $w->BwClass, $w->BwFace);
                }
                if ($rem[$k] !== null && $rem[$k] < 1) continue;
                if (bk_reg_blocked($tourId, $cfg, $w->BwLicence, $lue->LueCountry, $w->BwDivision, $w->BwClass, $o, $lue) !== '') continue;

                $res = bk_register($tourId, $lue, $w->BwDivision, $w->BwClass, $o, (string) $w->BwRequest,
                    array('role' => $w->BwByRole, 'who' => $w->BwBy, 'archer' => intval($w->BwArcher)),
                    array('face' => intval($w->BwFace), 'letter' => $w->BwWantLetter, 'with' => $w->BwWantWith));
                if (empty($res['ok'])) continue;

                safe_w_sql("UPDATE BookingWaitlist SET BwStatus = 1, BwSeen = 0, BwEnId = " . intval($res['enid'])
                    . ", BwSession = $o, BwDone = " . bk_waitlist_now_sql($tourId) . " WHERE BwId = " . intval($w->BwId));
                bk_log('WAIT_PROMOTE', $w->BwLicence);
                bk_waitlist_declare_payment($tourId, $w);
                if (!empty($res['validated'])) bk_replan_session($tourId, $o, $cfg);
                $rem = array();   // the capacity of every profile may have moved
                $done++;
                break;
            }
        }
    } finally {
        safe_r_sql("SELECT RELEASE_LOCK($lockName)");
    }
    return $done;
}

/** Every competition with somebody waiting (cron/waitlist.php). Returns archers registered. */
function bk_waitlist_sweep()
{
    $t = safe_fetch(safe_r_sql("SELECT 1 AS x FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BookingWaitlist'", false, true));
    if (!$t) return 0;
    $done = 0;
    $rs = safe_r_sql("SELECT DISTINCT BwTournament FROM BookingWaitlist WHERE BwStatus = 0");
    $tours = array();
    while ($r = safe_fetch($rs)) $tours[] = intval($r->BwTournament);
    foreach ($tours as $t) $done += bk_waitlist_process($t);
    return $done;
}

/**
 * Lists of the competitions this archer waits on, at most once every 2 minutes per
 * competition and per browser (home page: frequent, and the archer is the one waiting).
 */
function bk_waitlist_process_for($archerId, $licence)
{
    $rs = safe_r_sql("SELECT DISTINCT BwTournament FROM BookingWaitlist WHERE BwStatus = 0
        AND " . bk_waitlist_who_sql($archerId, $licence), false, true);
    while ($rs && ($r = safe_fetch($rs))) {
        $t = intval($r->BwTournament);
        if (time() - intval($_SESSION['BK_WaitCheck'][$t] ?? 0) < 120) continue;
        $_SESSION['BK_WaitCheck'][$t] = time();
        bk_waitlist_process($t);
    }
}

/** Position of a waiting row among the rows of the same profile that come before it. */
function bk_waitlist_position($w)
{
    $s = intval($w->BwSession);
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM BookingWaitlist
        WHERE BwTournament = " . intval($w->BwTournament) . " AND BwStatus = 0 AND BwId < " . intval($w->BwId) . "
          AND BwDivision = " . StrSafe_DB($w->BwDivision) . " AND BwClass = " . StrSafe_DB($w->BwClass) . "
          AND BwFace = " . intval($w->BwFace) . ($s ? " AND (BwSession = 0 OR BwSession = $s)" : '')));
    return ($r ? intval($r->n) : 0) + 1;
}

/** SELECT part shared by the archer and organiser views (competition, weapon, category). */
function bk_waitlist_select_sql()
{
    return "SELECT BookingWaitlist.*, ToName, ToWhere, ToWhenFrom, ToWhenTo,
            DivDescription, ClDescription, LueFamilyName, LueName, LueCoDescr
        FROM BookingWaitlist
        INNER JOIN Tournament ON ToId = BwTournament
        LEFT JOIN Divisions ON DivTournament = BwTournament AND DivId = BwDivision" . bk_coll() . "
        LEFT JOIN Classes ON ClTournament = BwTournament AND ClId = BwClass" . bk_coll() . "
        LEFT JOIN LookUpEntries ON LueCode = BwLicence" . bk_coll();
}

/**
 * Rows to show an archer: what they (or a clubmate for them) wait for, and what happened
 * since their last look (registered, or closed). Competitions not over yet.
 */
function bk_waitlist_for_archer($archerId, $licence)
{
    $out = array();
    $rs = safe_r_sql(bk_waitlist_select_sql() . "
        WHERE " . bk_waitlist_who_sql($archerId, $licence) . "
          AND (BwStatus = 0 OR BwSeen = 0)
          AND ToWhenTo >= " . bk_local_today_sql('ToTimeZone') . "
        ORDER BY ToWhenFrom, BwId", false, true);
    while ($rs && ($r = safe_fetch($rs))) $out[] = $r;
    return $out;
}

/** The archer has seen what happened to their waiting rows. */
function bk_waitlist_mark_seen($archerId, $licence)
{
    safe_w_sql("UPDATE BookingWaitlist SET BwSeen = 1 WHERE BwStatus IN (1, 2) AND BwSeen = 0
        AND " . bk_waitlist_who_sql($archerId, $licence));
}

/** Leaves the list: only the archer concerned, or the one who put them on it. */
function bk_waitlist_leave($id, $archerId, $licence)
{
    safe_w_sql("DELETE FROM BookingWaitlist WHERE BwId = " . intval($id) . " AND BwStatus = 0
        AND " . bk_waitlist_who_sql($archerId, $licence));
    return safe_w_affected_rows() > 0;
}

/** Organiser view: waiting rows in order, then the latest registered or closed ones. */
function bk_waitlist_of_tournament($tourId)
{
    $tourId = intval($tourId);
    $out = array('waiting' => array(), 'done' => array());
    $rs = safe_r_sql(bk_waitlist_select_sql() . " WHERE BwTournament = $tourId AND BwStatus = 0 ORDER BY BwId", false, true);
    while ($rs && ($r = safe_fetch($rs))) $out['waiting'][] = $r;
    $rs = safe_r_sql(bk_waitlist_select_sql() . " WHERE BwTournament = $tourId AND BwStatus IN (1, 2)
        ORDER BY BwDone DESC, BwId DESC LIMIT 20", false, true);
    while ($rs && ($r = safe_fetch($rs))) $out['done'][] = $r;
    return $out;
}

/** Organiser: removes a waiting archer from the list. */
function bk_waitlist_remove($tourId, $id)
{
    safe_w_sql("DELETE FROM BookingWaitlist WHERE BwId = " . intval($id) . " AND BwTournament = " . intval($tourId) . " AND BwStatus = 0");
}

/**
 * Organiser: registers a waiting archer now, on the departure chosen, even if it is full
 * (their decision, as when they add a participant in ianseo). ['ok', 'msg'].
 */
function bk_waitlist_register_now($tourId, $id, $session)
{
    $tourId = intval($tourId);
    $w = safe_fetch(safe_r_sql("SELECT * FROM BookingWaitlist WHERE BwId = " . intval($id) . "
        AND BwTournament = $tourId AND BwStatus = 0"));
    if (!$w) return array('ok' => false, 'msg' => bk_t('WlNotOnList'));
    $lue = bk_lookup_licence($w->BwLicence);
    if (!$lue) return array('ok' => false, 'msg' => bk_t('WlUnknownLic'));
    $session = intval($session);
    $cfg = bk_comp_config($tourId);
    // The organiser may register outside the registration window and the geographic
    // restriction, as in ianseo's own screens: only the rules about the archer and the
    // departure stand (bk_register refuses a finished competition by itself).
    if (bk_reg_session_left($tourId, $session) < 0) {
        return array('ok' => false, 'msg' => bk_t('WlNoDep'));
    }
    if (!array_key_exists($w->BwClass, bk_reg_classes($tourId, $lue->LueCtrlCode, $lue->LueSex, $w->BwDivision))) {
        return array('ok' => false, 'msg' => bk_t('WlCatGone'));
    }
    foreach (bk_reg_existing($tourId, $w->BwLicence) as $e) {
        if (intval($e->QuSession) === $session) return array('ok' => false, 'msg' => bk_t('WlAlreadyDep'));
    }
    $res = bk_register($tourId, $lue, $w->BwDivision, $w->BwClass, $session, (string) $w->BwRequest,
        array('role' => $w->BwByRole, 'who' => $w->BwBy, 'archer' => intval($w->BwArcher)),
        array('face' => intval($w->BwFace), 'letter' => $w->BwWantLetter, 'with' => $w->BwWantWith, 'skip_capacity' => true));
    if (empty($res['ok'])) return array('ok' => false, 'msg' => $res['msg'] ?? bk_t('RegFailed'));
    safe_w_sql("UPDATE BookingWaitlist SET BwStatus = 1, BwSeen = 0, BwEnId = " . intval($res['enid'])
        . ", BwSession = $session, BwDone = " . bk_waitlist_now_sql($tourId) . " WHERE BwId = " . intval($w->BwId));
    bk_waitlist_declare_payment($tourId, $w);
    if (!empty($res['validated'])) bk_replan_session($tourId, $session, $cfg);
    return array('ok' => true, 'msg' => '');
}

// The lists of competitions over are dropped every night by aut_log_purge() (AUTH lib.php).
