<?php
/**
 * lib/clubmoves.php — the first club of the registered archers follows the federation file
 * (user's choice, 2026-10-27). An archer may change club during the season, after registering:
 * every night (cron/sync-licences.php, once the file of the day is judged complete), for the
 * competitions not started, a participant with an FFTA licence whose first club differs from
 * the file's takes the file's one. The second club (fictitious teams) is never touched.
 *
 * For every participant whose club changes:
 *  - the registration is the archer's own from then on: an old clubmate who registered them no
 *    longer sees nor cancels it (BrArcher, BwArcher → the archer's account);
 *  - the tariff follows the new club (it is computed from the club of the entry);
 *  - what the old club paid for the archer is taken off the archer's account (a refund line of
 *    that club, BlgClub), and the organiser is asked to refund the old club (BookingRefunds,
 *    'CLUBMOVE'); the new club may pay in its turn, as for any of its archers;
 *  - the payer trust index charges the archer, no longer a club (trust-lib.php).
 * Online registration, registration still open:
 *  - a geographic restriction the new club does not meet cancels the registration; the archer
 *    is told in their space;
 *  - the departure is planned again; when the archer cannot be placed without more archers of
 *    their club on a target than allowed, the registration is suspended as for a missing licence
 *    (status 5, out of its departure, first of its waiting list, BrHoldReason 'club') and comes
 *    back once a target can take it within the rule (bk_club_serve_return).
 * Registration closed, or archer entered by the organiser: nothing is cancelled nor moved; the
 * organiser is told when the new club breaks one of these rules ('check').
 * Every change is kept (BookingClubMoves): the organiser's report, the archer's notice.
 */

if (defined('BK_CLUBMOVES_LOADED')) return;
define('BK_CLUBMOVES_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/targets.php';
require_once __DIR__ . '/licences.php';
require_once __DIR__ . '/waitlist.php';
require_once __DIR__ . '/clock.php';
require_once dirname(__DIR__, 2) . '/licence-lib.php';

/**
 * Nightly check of one competition. Returns ['moved', 'held', 'cancelled', 'check', 'refunds']
 * counts. Call only when the federation file of the day was judged complete.
 */
function bk_club_daily($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $out = array('moved' => 0, 'held' => 0, 'cancelled' => 0, 'check' => 0, 'refunds' => 0);
    $t = safe_fetch(safe_r_sql("SELECT ToWhenFrom, " . bk_local_today_sql() . " AS Today FROM Tournament WHERE ToId = $tourId"));
    if (!$t) return $out;
    // Started (or over): the club stays the one the archer shoots under.
    if (substr((string) $t->ToWhenFrom, 0, 10) <= substr((string) $t->Today, 0, 10)) return $out;   // bytes: ASCII dates

    $cfg = bk_comp_config($tourId);
    $online = intval($cfg->BcPublishLevel ?? 1) >= 2 && intval($cfg->BcOpen ?? 0) === 1;
    $open = $online && !empty($cfg->BcIsOpen);
    $max = max(1, intval($cfg->BcMaxPerClubPerTarget));

    // Qualifications in LEFT JOIN on purpose: a competition just imported may lack its rows until
    // the core repairs them (Partecipants/index.php); its participants still take their club.
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, CoCode, CoName, QuSession, QuTarget,
            BrId, BrLicHold, BrValidated,
            (SELECT LueCountry FROM LookUpEntries WHERE LueCode = EnCode AND LueIocCode = 'FRA' ORDER BY LueDefault DESC LIMIT 1) AS LueClub,
            (SELECT LueCoDescr FROM LookUpEntries WHERE LueCode = EnCode AND LueIocCode = 'FRA' ORDER BY LueDefault DESC LIMIT 1) AS LueClubName
        FROM Entries
        LEFT JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry
        LEFT JOIN BookingRegistrations ON BrEnId = EnId
        WHERE EnTournament = $tourId AND EnAthlete = 1 AND " . aut_lic_ffta_sql());
    $rows = array();
    while ($r = safe_fetch($rs)) {
        $new = mb_strtoupper(trim((string) $r->LueClub));
        if ($new !== '' && $new !== mb_strtoupper(trim((string) $r->CoCode))) $rows[] = $r;
    }

    $replan = array();
    foreach ($rows as $r) {
        $enId = intval($r->EnId);
        $lic = (string) $r->EnCode;
        $old = (string) $r->CoCode;
        $new = mb_strtoupper(trim((string) $r->LueClub));
        $ses = intval($r->QuSession);
        $isOnline = $r->BrId !== null;
        // Only a placed archer can lose its target because of its club: one waiting for a target
        // already (field constraints, manual validation) is not suspended for its club.
        $placed = $isOnline && $open && $r->BrLicHold === null && intval($r->BrValidated) === 1 && $ses > 0
            && intval($r->QuTarget) > 0;
        $before = $placed ? count(bk_club_over($tourId, $ses, $new, $max)) : 0;

        // The club of the file. The second club is left as it is.
        $coId = bk_reg_club_id($tourId, $new, (string) $r->LueClubName);
        safe_w_sql("UPDATE Entries SET EnCountry = $coId, EnTimestamp = " . StrSafe_DB(date('Y-m-d H:i:s')) . "
            WHERE EnId = $enId AND EnTournament = $tourId");

        // The registration is the archer's own from now on.
        $self = safe_fetch(safe_r_sql("SELECT BaId FROM BookingArchers WHERE BaLicence = " . StrSafe_DB($lic) . " LIMIT 1"));
        $selfId = $self ? intval($self->BaId) : 0;
        if ($isOnline) safe_w_sql("UPDATE BookingRegistrations SET BrArcher = $selfId WHERE BrEnId = $enId");
        safe_w_sql("UPDATE BookingWaitlist SET BwArcher = $selfId
            WHERE BwTournament = $tourId AND BwStatus = 0 AND BwLicence = " . StrSafe_DB($lic));

        // What the old club paid goes back to it: the archer owes it again.
        $refund = $old !== '' ? bk_ledger_club_net($tourId, $lic, $old) : 0.0;
        $name = trim($r->EnFirstName . ' ' . $r->EnName);
        if ($refund > 0.005) {
            bk_ledger_add($tourId, $lic, 'refund', $refund, '', '', bk_t('LedgerClubMove', $old), 'licsync', 0, array('club' => $old));
            bk_refund_add($tourId, $old, (string) $r->CoName, $refund, '', 'CLUBMOVE', $name . ' (' . $lic . ')');
            $out['refunds']++;
        } else {
            $refund = 0.0;
        }

        $result = 'moved';
        $geo = bk_comp_archer_blocked($cfg, $new) !== '';
        if ($isOnline && $open && $geo) {
            // The new club may not take part: the registration is cancelled.
            if (bk_reg_remove($tourId, $enId)) {
                safe_w_sql("DELETE FROM BookingRegistrations WHERE BrEnId = $enId");
                safe_w_sql("UPDATE BookingWaitlist SET BwStatus = 2, BwSeen = 1
                    WHERE BwTournament = $tourId AND BwEnId = $enId AND BwStatus = 0");
                $result = 'cancel';
            } else {
                $result = 'check';   // participants locked by the organiser
            }
        } elseif ($placed) {
            // Planned again with the new club; it must not take a target beyond the rule.
            bk_replan_session($tourId, $ses, $cfg);
            $q = safe_fetch(safe_r_sql("SELECT QuTarget FROM Qualifications
                INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId WHERE QuId = $enId"));
            if (!$q || intval($q->QuTarget) <= 0 || count(bk_club_over($tourId, $ses, $new, $max)) > $before) {
                bk_reg_hold($tourId, $enId, $ses, 'club');
                bk_reg_return_row($tourId, $enId);
                $replan[$ses] = true;
                $result = 'held';
            }
        } elseif ($geo || ($ses > 0 && in_array(intval($r->QuTarget), bk_club_over($tourId, $ses, $new, $max), true) && intval($r->QuTarget) > 0)) {
            $result = 'check';
        }

        safe_w_sql("INSERT INTO BookingClubMoves SET BmvTournament = $tourId, BmvEnId = $enId,
            BmvLicence = " . StrSafe_DB($lic) . ", BmvName = " . StrSafe_DB(mb_substr($name, 0, 100)) . ",
            BmvFrom = " . StrSafe_DB(mb_substr($old, 0, 16)) . ", BmvFromName = " . StrSafe_DB(mb_substr((string) $r->CoName, 0, 80)) . ",
            BmvTo = " . StrSafe_DB(mb_substr($new, 0, 16)) . ", BmvToName = " . StrSafe_DB(mb_substr((string) $r->LueClubName, 0, 80)) . ",
            BmvRefund = " . StrSafe_DB(number_format($refund, 2, '.', '')) . ", BmvResult = " . StrSafe_DB($result) . ",
            BmvWhen = " . bk_local_now_sql("(SELECT ToTimeZone FROM Tournament WHERE ToId = $tourId)"));
        bk_log('CLUB_MOVE', $lic);
        $out[$result === 'cancel' ? 'cancelled' : $result]++;
    }
    // A target given back may change the plan of its departure; the places freed go to the list.
    foreach (array_keys($replan) as $ses) bk_replan_session($tourId, $ses, $cfg);
    bk_club_recheck($tourId, $cfg, $max);
    if ($open && ($out['held'] || $out['cancelled'])) bk_waitlist_process($tourId);
    return $out;
}

/** Targets of a departure that carry more archers of a club than allowed. */
function bk_club_over($tourId, $ses, $club, $max)
{
    $rs = safe_r_sql("SELECT QuTarget FROM Qualifications
        INNER JOIN Entries ON EnId = QuId AND EnTournament = " . intval($tourId) . "
        INNER JOIN Countries ON CoId = EnCountry
        WHERE QuSession = " . intval($ses) . " AND QuTarget > 0 AND CoCode = " . StrSafe_DB($club) . "
        GROUP BY QuTarget HAVING COUNT(*) > " . intval($max));
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = intval($r->QuTarget);
    return $out;
}

/**
 * The changes the organiser had to check ('check'): once the archer is gone, or the new club
 * no longer breaks the geographic restriction nor the number of archers of a club per target,
 * they are only a change of club ('moved').
 */
function bk_club_recheck($tourId, $cfg, $max)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT BmvId, BmvEnId, CoCode, QuSession, QuTarget
        FROM BookingClubMoves
        LEFT JOIN Entries ON EnId = BmvEnId AND EnTournament = BmvTournament
        LEFT JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry
        WHERE BmvTournament = $tourId AND BmvResult = 'check'");
    $rows = array();
    while ($r = safe_fetch($rs)) $rows[] = $r;
    foreach ($rows as $r) {
        $club = (string) $r->CoCode;
        $still = $club !== '' && (bk_comp_archer_blocked($cfg, $club) !== ''
            || (intval($r->QuTarget) > 0 && in_array(intval($r->QuTarget), bk_club_over($tourId, intval($r->QuSession), $club, $max), true)));
        if (!$still) safe_w_sql("UPDATE BookingClubMoves SET BmvResult = 'moved' WHERE BmvId = " . intval($r->BmvId));
    }
}

/**
 * A registration suspended because of its new club (BrHoldReason 'club'), first of its waiting
 * list: back into its departure once a target with a free place takes its profile with fewer
 * archers of its club than allowed. Checked before anything moves (no plan run for nothing),
 * then for real: planned again, it must be placed without one more target beyond the rule —
 * otherwise it is suspended again and keeps waiting. True when served.
 */
function bk_club_serve_return($tourId, $cfg, $w)
{
    $tourId = intval($tourId);
    $enId = intval($w->BwEnId);
    $o = intval($w->BwSession);
    if ($o <= 0 || bk_reg_session_left($tourId, $o) < 1) return false;
    $e = safe_fetch(safe_r_sql("SELECT CoCode FROM Entries INNER JOIN Countries ON CoId = EnCountry
        WHERE EnId = $enId AND EnTournament = $tourId"));
    if (!$e) return false;
    $club = (string) $e->CoCode;
    $max = max(1, intval($cfg->BcMaxPerClubPerTarget));

    // The targets with a free place, without those already full of the archer's club.
    $ctx = bk_profile_ctx($tourId, $o);
    if ($ctx === null) return false;
    $count = array();
    $rs = safe_r_sql("SELECT QuTarget, COUNT(*) AS n FROM Qualifications
        INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
        INNER JOIN Countries ON CoId = EnCountry
        WHERE QuSession = $o AND QuTarget > 0 AND CoCode = " . StrSafe_DB($club) . " GROUP BY QuTarget");
    while ($r = safe_fetch($rs)) $count[intval($r->QuTarget)] = intval($r->n);
    foreach (array_keys($ctx['targets']) as $t) if (($count[$t] ?? 0) >= $max) unset($ctx['targets'][$t]);
    if (bk_profile_left($ctx, $w->BwDivision, $w->BwClass, intval($w->BwFace)) < 1) return false;

    $before = count(bk_club_over($tourId, $o, $club, $max));
    bk_licence_restore($tourId, $enId, $o);
    bk_replan_session($tourId, $o, $cfg);
    $q = safe_fetch(safe_r_sql("SELECT QuTarget FROM Qualifications
        INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId WHERE QuId = $enId"));
    if (!$q || intval($q->QuTarget) <= 0 || count(bk_club_over($tourId, $o, $club, $max)) > $before) {
        bk_reg_hold($tourId, $enId, $o, 'club');
        bk_replan_session($tourId, $o, $cfg);
        return false;
    }
    return true;
}

/**
 * For the organiser: the last change of club of each archer of the competition, one line for
 * the shoots of one archer that ended the same way — [['name', 'code', 'from', 'to', 'refund',
 * 'result', 'when']], by name.
 */
function bk_club_report($tourId)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT BmvEnId, BmvLicence, BmvName, BmvFrom, BmvFromName, BmvTo, BmvToName, BmvRefund, BmvResult, BmvWhen
        FROM BookingClubMoves WHERE BmvTournament = $tourId ORDER BY BmvId", false, true);
    $out = array();
    $last = array();
    while ($rs && ($r = safe_fetch($rs))) $last[intval($r->BmvEnId)] = $r;
    foreach ($last as $enId => $r) {
        $k = ($r->BmvLicence !== '' ? $r->BmvLicence : '#' . $enId) . '|' . $r->BmvResult;
        if (isset($out[$k])) { $out[$k]['refund'] += (float) $r->BmvRefund; continue; }
        $out[$k] = array('name' => (string) $r->BmvName, 'code' => (string) $r->BmvLicence,
            'from' => trim($r->BmvFrom . ' ' . $r->BmvFromName), 'to' => trim($r->BmvTo . ' ' . $r->BmvToName),
            'refund' => (float) $r->BmvRefund, 'result' => (string) $r->BmvResult, 'when' => (string) $r->BmvWhen);
    }
    uasort($out, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return $out;
}

/**
 * Registrations of an archer cancelled because of a change of club, not shown to them yet:
 * [['id', 'tour' => competition name, 'club' => new club]]. $seen: marks them shown.
 */
function bk_club_notices($licence, $seen = false)
{
    bk_schema();
    $rs = safe_r_sql("SELECT BmvId, BmvTo, BmvToName, ToName FROM BookingClubMoves
        INNER JOIN Tournament ON ToId = BmvTournament
        WHERE BmvLicence = " . StrSafe_DB(bk_clean_licence($licence)) . " AND BmvResult = 'cancel' AND BmvSeen = 0
        ORDER BY BmvId");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = array('id' => intval($r->BmvId), 'tour' => (string) $r->ToName,
        'club' => trim($r->BmvTo . ' ' . $r->BmvToName));
    if ($seen && $out) {
        safe_w_sql("UPDATE BookingClubMoves SET BmvSeen = 1 WHERE BmvId IN (" . implode(',', array_column($out, 'id')) . ")");
    }
    return $out;
}
