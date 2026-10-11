<?php
/**
 * lib/licences.php — daily check of the licences of the registered archers (cron/sync-licences.php,
 * after the federation file of the day is loaded and judged complete).
 *
 * Only FFTA licence numbers are checked (AUTH licence-lib.php), only for competitions not started.
 * An archer may register with the licence of the season before and not renew it; on the day of
 * the competition only the archers in the file may shoot.
 *
 *  - Licence missing, online registration (BookingRegistrations) on a competition still open to
 *    registration: the registration is SUSPENDED, never deleted — out of its departure (QuSession 0,
 *    the departure kept in BrHoldSession), no target, status 5 "unknown status at the date of the
 *    tournament". Its place is free for the others; the archer may still take their licence.
 *  - Licence missing, archer entered by the organiser, or competition without online registration:
 *    the status only (5), nothing moves.
 *  - Licence back for a suspended registration: a waiting row of the registration itself
 *    (BwReturn, BwEnId), dated from the original registration so that it comes before the archers
 *    who registered later; served at once when its departure has room (bk_waitlist_process).
 *  - Competition started, or registration closed: nothing is changed; the organiser is told
 *    (bk_licence_report: page of the online registration, Main.php).
 */

if (defined('BK_LICENCES_LOADED')) return;
define('BK_LICENCES_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/waitlist.php';
require_once __DIR__ . '/clock.php';
require_once dirname(__DIR__, 2) . '/licence-lib.php';

/**
 * Daily check of one competition. Returns ['suspended', 'status', 'back'] counts. Call only when
 * the file of the day was judged complete.
 */
function bk_licence_daily($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $out = array('suspended' => 0, 'status' => 0, 'back' => 0);
    $t = safe_fetch(safe_r_sql("SELECT ToWhenFrom, ToWhenTo, " . bk_local_today_sql() . " AS Today
        FROM Tournament WHERE ToId = $tourId"));
    if (!$t) return $out;
    // Started (or over): nothing changes, the organiser is told.
    if (substr((string) $t->ToWhenFrom, 0, 10) <= substr((string) $t->Today, 0, 10)) return $out;   // bytes: ASCII dates

    $cfg = bk_comp_config($tourId);
    $online = intval($cfg->BcPublishLevel ?? 1) >= 2 && intval($cfg->BcOpen ?? 0) === 1;
    $open = $online && !empty($cfg->BcIsOpen);

    $rs = safe_r_sql("SELECT EnId, EnCode, EnStatus, EnDivision, EnClass, EnTargetFace, QuSession,
            BrId, BrLicHold, BrHoldSession, BrCreated, BrArcher, BrByRole, BrBy,
            EXISTS (SELECT 1 FROM LookUpEntries WHERE LueCode = EnCode AND LueIocCode = 'FRA') AS InLicFile
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN BookingRegistrations ON BrEnId = EnId
        WHERE EnTournament = $tourId AND EnAthlete = 1 AND " . aut_lic_ffta_sql());
    $replan = array();
    while ($r = safe_fetch($rs)) {
        $enId = intval($r->EnId);
        $held = $r->BrLicHold !== null;
        if (!intval($r->InLicFile)) {
            // Decisions of the organiser stand: withdrawn (6), not accredited (7), and of the
            // check-in desk: cannot participate (8). Status 1 ("admitted after checking") is no
            // exemption: bk_register writes it on every online registration, and an archer
            // entered by the organiser needs a licence too.
            if (in_array(intval($r->EnStatus), array(6, 7, 8), true) || $held) continue;
            if ($online && !$open) continue;   // registration closed: the organiser is told only
            if ($open && $r->BrId !== null) {
                $ses = intval($r->QuSession);
                safe_w_sql("UPDATE Qualifications INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
                    SET QuSession = 0, QuTarget = 0, QuLetter = '', QuTargetNo = ''
                    WHERE QuId = $enId");
                safe_w_sql("UPDATE Entries SET EnStatus = 5 WHERE EnId = $enId AND EnTournament = $tourId");
                safe_w_sql("UPDATE BookingRegistrations SET BrLicHold = NOW(), BrHoldSession = $ses WHERE BrEnId = $enId");
                bk_log('LIC_HOLD', $r->EnCode);
                if ($ses > 0) $replan[$ses] = true;
                $out['suspended']++;
            } elseif (intval($r->EnStatus) !== 5) {
                safe_w_sql("UPDATE Entries SET EnStatus = 5 WHERE EnId = $enId AND EnTournament = $tourId");
                $out['status']++;
            }
        } elseif ($held && $open) {
            // Licence back: the registration waits for its departure, first among those who
            // registered after it (ordered by BwCreated).
            $have = safe_fetch(safe_r_sql("SELECT BwId FROM BookingWaitlist
                WHERE BwTournament = $tourId AND BwEnId = $enId AND BwReturn = 1 AND BwStatus = 0"));
            if (!$have) {
                safe_w_sql("INSERT INTO BookingWaitlist SET BwTournament = $tourId,
                    BwLicence = " . StrSafe_DB($r->EnCode) . ", BwArcher = " . intval($r->BrArcher) . ",
                    BwByRole = " . StrSafe_DB((string) $r->BrByRole) . ", BwBy = " . StrSafe_DB((string) $r->BrBy) . ",
                    BwDivision = " . StrSafe_DB($r->EnDivision) . ", BwClass = " . StrSafe_DB($r->EnClass) . ",
                    BwFace = " . intval($r->EnTargetFace) . ", BwSession = " . intval($r->BrHoldSession) . ",
                    BwEnId = $enId, BwReturn = 1, BwCreated = " . StrSafe_DB($r->BrCreated));
                bk_log('LIC_BACK', $r->EnCode);
                $out['back']++;
            }
        }
    }
    // A target given back may change the plan of its departure; the places freed go to the list.
    foreach (array_keys($replan) as $ses) bk_replan_session($tourId, $ses, $cfg);
    if ($open) bk_waitlist_process($tourId);
    return $out;
}

/**
 * Brings a suspended registration back into its departure (waiting row served, or the organiser
 * registering it by hand). Its status comes from the file again — 1 rather than 0, as every
 * online registration is written (bk_register): the check-in desk has not seen it yet. The
 * placement follows.
 */
function bk_licence_restore($tourId, $enId, $session)
{
    $tourId = intval($tourId); $enId = intval($enId); $session = intval($session);
    safe_w_sql("UPDATE Qualifications INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
        SET QuSession = $session, QuTarget = 0, QuLetter = '', QuTargetNo = ''
        WHERE QuId = $enId");
    safe_w_sql("UPDATE Entries
        INNER JOIN LookUpEntries ON LueCode = EnCode AND LueIocCode = 'FRA'
        SET EnStatus = IF(LueStatus = 0, 1, LueStatus)
        WHERE EnId = $enId AND EnTournament = $tourId AND EnStatus = 5");
    safe_w_sql("UPDATE BookingRegistrations SET BrLicHold = NULL, BrHoldSession = 0 WHERE BrEnId = $enId");
}

/**
 * For the organiser: the archers of the competition with a licence problem today —
 * [['name', 'code', 'state']] where state is 'absent' (not in the file), 'held' (registration
 * suspended) or 'back' (licence back, waiting for a place in its departure). Empty while the
 * federation file cannot be believed.
 */
function bk_licence_report($tourId)
{
    $tourId = intval($tourId);
    $out = array();
    foreach (aut_lic_absent($tourId) as $id => $r) {
        $out[$id] = array('name' => trim($r->EnFirstName . ' ' . $r->EnName), 'code' => $r->EnCode, 'state' => 'absent');
    }
    if (!aut_lic_file_ok()) return array();
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName FROM BookingRegistrations
        INNER JOIN Entries ON EnId = BrEnId
        WHERE BrTournament = $tourId AND BrLicHold IS NOT NULL", false, true);
    while ($rs && ($r = safe_fetch($rs))) {
        $id = intval($r->EnId);
        $out[$id] = array('name' => trim($r->EnFirstName . ' ' . $r->EnName), 'code' => $r->EnCode,
            'state' => isset($out[$id]) ? 'held' : 'back');
    }
    uasort($out, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return $out;
}
