<?php
/**
 * lib/registration.php — creating and deleting a registration.
 *
 * Writes into the ianseo CORE tables (Entries, Qualifications, Countries) following exactly the
 * path of Partecipants/PopEdit.php, recomputation hooks included. Any difference would leave
 * rankings and teams outdated.
 *
 * A booking registration = one Entries row + one Qualifications row (1:1 by QuId=EnId, like the
 * core) + one BK_Registrations row that records the author (Entries has no notion of who made a
 * registration).
 */

if (defined('BK_REG_LOADED')) return;
define('BK_REG_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/archer.php';   // bk_lookup_licence, bk_clean_licence

// Core ianseo functions used to write a registration and start the recomputations. Loaded by
// relative path: config.php adds htdocs to the include_path, so these files include each
// other normally.
require_once('Common/Fun_FormatText.inc.php');              // AdjustCaseTitle
require_once('Common/Fun_Various.inc.php');                 // checkAgainstLUE
require_once('Partecipants/Fun_Targets.php');               // getTargets
require_once('Partecipants/Fun_Partecipants.local.inc.php');// Params4Recalc, RecalculateShootoffAndTeams
require_once('Qualification/Fun_Qualification.local.inc.php');// CalcQualRank, MakeIndAbs

/**
 * Runs $fn with the ianseo competition session set to $tourId, then PUTS the session BACK in
 * its original state.
 *
 * Needed: the core's recomputation functions (RecalculateShootoffAndTeams, CalcQualRank,
 * MakeIndAbs…) read $_SESSION['TourId'] and the like. But the licensee space has no open
 * competition — and the browser may also carry an organiser's session on ANOTHER competition.
 *
 * ⚠️ CreateTourSession() EMPTIES $_SESSION (Globals.inc.php): it would erase the archer's session
 * token. The WHOLE session is therefore saved and restored as is — also through
 * register_shutdown_function, so a fatal error never leaves the archer signed out nor an
 * organiser with the wrong competition open.
 */
function bk_with_tournament($tourId, $fn)
{
    $saved = $_SESSION;
    $done  = false;

    register_shutdown_function(function () use ($saved, &$done) {
        if (!$done) $_SESSION = $saved;   // filet en cas d'erreur fatale
    });

    try {
        CreateTourSession(intval($tourId));
        return $fn();
    } finally {
        $_SESSION = $saved;
        $done = true;
    }
}

/* ------------------------------------------------------------------ */
/* Categories offered                                                  */
/* ------------------------------------------------------------------ */

/** Bows (divisions) open to athletes on this competition. */
function bk_reg_divisions($tourId)
{
    $rs = safe_r_sql("SELECT DivId, DivDescription FROM Divisions
        WHERE DivTournament = " . intval($tourId) . " AND DivAthlete = 1
        ORDER BY DivViewOrder, DivId");
    $out = array();
    while ($r = safe_fetch($rs)) $out[$r->DivId] = $r->DivDescription;
    return $out;
}

/**
 * Categories (classes) open to this archer for this bow.
 *
 * Rule of the ianseo core (Partecipants/Get-Classes.php, Participants/lib.php): the age is the
 * YEAR the competition ends minus the YEAR of birth — never the age reached on today's date.
 */
function bk_reg_classes($tourId, $dob, $sex, $division)
{
    $tourId = intval($tourId);
    $sex    = intval($sex) ? 1 : 0;

    $rs = safe_r_sql("SELECT ClId, ClDescription, ClAgeFrom, ClAgeTo
        FROM Classes
        INNER JOIN Tournament ON ToId = ClTournament
        WHERE ClTournament = $tourId
          AND ClAthlete = 1
          AND ClSex IN (-1, $sex)
          AND (YEAR(ToWhenTo) - YEAR(" . StrSafe_DB($dob) . ")) BETWEEN ClAgeFrom AND ClAgeTo
          AND (ClDivisionsAllowed = '' OR FIND_IN_SET(" . StrSafe_DB($division) . ", ClDivisionsAllowed))
        ORDER BY (ClAgeTo - ClAgeFrom) ASC, ClId");   // the most specific first
    $out = array();
    while ($r = safe_fetch($rs)) $out[$r->ClId] = $r->ClDescription;
    return $out;
}

/* ------------------------------------------------------------------ */
/* Group registration (a licensee registers a club mate)               */
/* ------------------------------------------------------------------ */

/**
 * Is the licensee an ADULT? (18 years reached on today's date.)
 *
 * Only an adult may register other licensees. $dob as YYYY-MM-DD (LueCtrlCode); today's date
 * is the server zone's (bk_today()).
 */
function bk_is_major($dob)
{
    $dob = trim((string) $dob);
    if ($dob === '' || $dob === '0000-00-00') return false;
    $r = safe_fetch(safe_r_sql("SELECT (" . StrSafe_DB($dob)
        . " <= DATE_SUB(" . StrSafe_DB(bk_today()) . ", INTERVAL 18 YEAR)) AS major"));
    return $r ? (bool) intval($r->major) : false;
}

/**
 * Resolves a club mate from a typed licence number, limited to the connected archer's club.
 * Returns the federation record (LookUpEntries) or null.
 *
 * null when: empty licence, licence unknown to the federation file, OR archer of another club —
 * in all these cases "nothing must happen" (business rule: a third party outside one's own club
 * is never registered).
 */
function bk_lookup_clubmate($licence, $selfClubCode)
{
    $selfClubCode = strtoupper(trim((string) $selfClubCode));
    if ($selfClubCode === '') return null;

    $lue = bk_lookup_licence($licence);
    if (!$lue) return null;
    if (strtoupper(trim((string) $lue->LueCountry)) !== $selfClubCode) return null;
    return $lue;
}

/**
 * DISTINCT club mates already registered by this archer, restricted to those STILL in their club
 * today. Feeds the drop-down of the group registration (shortcut to a licensee already
 * registered). The club check is DONE AGAIN here (`bk_lookup_clubmate`): an archer who changed
 * club during the season can no longer be registered. Returns [licence => "Name Given name"].
 */
function bk_authored_clubmates($archerId, $selfLicence, $selfClubCode)
{
    $archerId = intval($archerId);
    if ($archerId <= 0 || trim((string) $selfClubCode) === '') return array();

    $selfLic = bk_clean_licence($selfLicence);
    $rs = safe_r_sql("SELECT DISTINCT BrLicence FROM BK_Registrations
        WHERE BrArcher = $archerId AND BrLicence <> " . StrSafe_DB($selfLic));
    $out = array();
    while ($r = safe_fetch($rs)) {
        $mate = bk_lookup_clubmate($r->BrLicence, $selfClubCode);   // checks the current club again
        if ($mate) $out[$mate->LueCode] = trim($mate->LueFamilyName . ' ' . $mate->LueName);
    }
    asort($out);
    return $out;
}

/**
 * Registrations an archer made FOR OTHER licensees (group registration) — so they can follow
 * and cancel them from "My registrations". Leaves out their own registrations (BrLicence =
 * their licence).
 */
function bk_authored_registrations($archerId, $selfLicence)
{
    bk_schema();
    $archerId = intval($archerId);
    if ($archerId <= 0) return array();

    $rs = safe_r_sql("SELECT r.BrId, r.BrEnId, r.BrTournament, r.BrLicence, r.BrCreated, r.BrValidated,
                e.EnFirstName, e.EnName, e.EnCode, e.EnDivision, e.EnClass, e.EnIndClEvent,
                q.QuSession, q.QuTarget, q.QuLetter,
                d.DivDescription, c.ClDescription,
                t.ToName, t.ToWhere, t.ToVenue, t.ToWhenFrom, t.ToWhenTo,
                t.ToType, t.ToTypeName, t.ToTypeSubRule,
                o.BcShowAssignment, o.BcAllowScoresheet, " . bk_comp_calc_sql('o') . "
        FROM BK_Registrations r
        INNER JOIN Entries e        ON e.EnId = r.BrEnId
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        INNER JOIN Tournament t     ON t.ToId = r.BrTournament
        LEFT  JOIN Divisions d      ON d.DivTournament = t.ToId AND d.DivId = e.EnDivision
        LEFT  JOIN Classes c        ON c.ClTournament  = t.ToId AND c.ClId  = e.EnClass
        LEFT  JOIN BK_Competitions o ON o.BcTournament = t.ToId
        WHERE r.BrArcher = $archerId
          AND r.BrLicence <> " . StrSafe_DB(bk_clean_licence($selfLicence)) . "
        ORDER BY t.ToWhenFrom DESC, e.EnFirstName, e.EnName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/* ------------------------------------------------------------------ */
/* Checks                                                              */
/* ------------------------------------------------------------------ */

/** Existing registrations of this licence on this competition. */
function bk_reg_existing($tourId, $licence)
{
    $rs = safe_r_sql("SELECT e.EnId, e.EnDivision, e.EnClass, q.QuSession
        FROM Entries e
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        WHERE e.EnTournament = " . intval($tourId) . "
          AND e.EnCode = " . StrSafe_DB($licence) . "
          AND e.EnAthlete = 1");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Places left on a departure (0 when the departure does not exist). */
function bk_reg_session_left($tourId, $order)
{
    foreach (bk_comp_sessions($tourId) as $s) {
        if (intval($s->SesOrder) === intval($order)) {
            return max(0, intval($s->Places) - intval($s->Pris));
        }
    }
    return -1;   // unknown departure
}

/**
 * Every rule to pass before registering. Returns '' when all is well, otherwise the reason of
 * the refusal (a displayable message).
 *
 * Checked again by the server when writing: the calendar informs, it does not authorise.
 */
function bk_reg_blocked($tourId, $cfg, $licence, $clubCode, $division, $class, $sessionOrder, $lue = null, $ignoreFull = false)
{
    // $ignoreFull: every rule but "this departure is full" — used to join the waiting list.
    if (empty($cfg->BcIsOpen)) return bk_t('ClubRegsNotOpen');

    // A finished competition can no longer be registered for, even if the registration window
    // was left open past its date.
    if (bk_comp_finished($tourId)) return bk_t('RgFinished');

    // "Non-practising" licence (LueStatus = 9, e.g. officer/treasurer): can NOT register for a
    // competition (but may register others — the AUTHOR registers, not the SUBJECT; here
    // $lue = the subject). Applies to self-registration as to a club mate's registration whose
    // subject would be non-practising.
    if ($lue && intval($lue->LueStatus) === 9) {
        return bk_t('RgNoPractice');
    }

    $geo = bk_comp_archer_blocked($cfg, $clubCode);
    if ($geo !== '') return $geo;

    if (!array_key_exists($division, bk_reg_divisions($tourId))) {
        return bk_t('RgBadBow');
    }

    // The category must be one that age and sex allow — checked HERE and not only when the
    // form is shown: a forged POST must not register an adult in a youth category.
    if ($lue) {
        $classes = bk_reg_classes($tourId, $lue->LueCtrlCode, $lue->LueSex, $division);
        if (!array_key_exists($class, $classes)) {
            return bk_t('RgBadAge');
        }
    }

    $left = bk_reg_session_left($tourId, $sessionOrder);
    if ($left < 0)  return bk_t('WlNoDep');
    if ($left === 0 && !$ignoreFull) return bk_t('RgDepFull');

    // Rule: never two shoots for one archer on the same departure.
    foreach (bk_reg_existing($tourId, $licence) as $e) {
        if (intval($e->QuSession) === intval($sessionOrder)) {
            return bk_t('RgAlreadyDep');
        }
    }

    // The competition may be locked by the organiser.
    if (bk_with_tournament($tourId, function () { return IsBlocked(BIT_BLOCK_PARTICIPANT); })) {
        return bk_t('RgLocked');
    }

    return '';
}

/* ------------------------------------------------------------------ */
/* Writing                                                             */
/* ------------------------------------------------------------------ */

/** Resolves (or creates) the club in Countries — taken from PopEdit.php. */
function bk_reg_club_id($tourId, $code, $name)
{
    $tourId = intval($tourId);
    $code = mb_convert_case(trim((string) $code), MB_CASE_UPPER, 'UTF-8');
    if ($code === '') return 0;

    $rs = safe_r_sql("SELECT CoId FROM Countries
        WHERE CoCode = " . StrSafe_DB($code) . " AND CoTournament = $tourId");
    if ($r = safe_fetch($rs)) {
        $coId = intval($r->CoId);
    } else {
        safe_w_sql("INSERT INTO Countries (CoTournament, CoCode) VALUES ($tourId, " . StrSafe_DB($code) . ")");
        $coId = intval(safe_w_last_id());
    }
    if (trim((string) $name) !== '') {
        safe_w_sql("UPDATE Countries SET CoName = " . StrSafe_DB(AdjustCaseTitle($name))
            . " WHERE CoId = $coId AND CoTournament = $tourId");
    }

    // Club logo: as soon as a club enters a competition, its logo (ianseo flag) is set from the
    // shared cache, so the core's printouts have it at once without waiting for the nightly
    // cron. Purely LOCAL (no network access), and fully isolated: it can never make the
    // registration fail.
    $lg = dirname(__DIR__, 2) . '/logos-lib.php';
    if (is_file($lg)) {
        require_once $lg;
        if (function_exists('aut_logos_ensure_club')) aut_logos_ensure_club($tourId, $code);
    }
    return $coId;
}

/* ------------------------------------------------------------------ */
/* Several shoots of one archer with one weapon                        */
/* ------------------------------------------------------------------ */
// Only the FIRST SHOOT OF THE COMPETITION takes part in the events (ranking, teams, finals);
// any other shoot with the same weapon is an extra shoot, out of the events — otherwise the
// archer would be ranked twice. "First" is in the order of the competition, not of the
// registrations: registered on departure 2, then adding departure 1, it is departure 1 that
// counts and departure 2 becomes the extra shoot. Another weapon has its own events.

/** Event flags of Entries, moved together from one shoot to another. */
function bk_event_cols()
{
    return array('EnIndClEvent', 'EnTeamClEvent', 'EnIndFEvent', 'EnTeamFEvent', 'EnTeamMixEvent');
}

/**
 * Shoots of an archer with a weapon, in the order of the competition: departure start (date
 * and time, when the organiser gave one), then departure number. Qualifications in LEFT JOIN
 * on purpose: an entry just imported may not have its row yet (the core adds it when the
 * participants screen is opened); it then counts as a shoot without departure, last.
 */
function bk_shoots_in_order($tourId, $code, $division)
{
    $out = array();
    $rs = safe_r_sql("SELECT EnId, " . implode(', ', bk_event_cols()) . "
        FROM Entries
        LEFT JOIN Qualifications ON QuId = EnId
        LEFT JOIN Session ON SesTournament = EnTournament AND SesOrder = QuSession AND SesType = 'Q'
        WHERE EnTournament = " . intval($tourId) . " AND EnCode = " . StrSafe_DB($code) . "
          AND EnDivision = " . StrSafe_DB($division) . " AND EnAthlete = 1
        ORDER BY (QuSession IS NULL OR QuSession = 0), (SesDtStart IS NULL OR SesDtStart < '1000-01-01'),
                 SesDtStart, QuSession, EnId");
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Sets the event flags of a shoot of competition $tourId (array col => 0/1). */
function bk_event_flags_set($tourId, $enId, $flags)
{
    $set = array();
    foreach (bk_event_cols() as $c) $set[] = "$c = " . (empty($flags[$c]) ? 0 : 1);
    safe_w_sql("UPDATE Entries SET " . implode(', ', $set) . ", EnTimestamp = '" . date('Y-m-d H:i:s') . "'
        WHERE EnId = " . intval($enId) . " AND EnTournament = " . intval($tourId));
}

/**
 * Gives the events to the first shoot of the competition (see above). The shoot that took
 * part hands its flags over as they are (the organiser may have taken the archer out of the
 * team or final events) and becomes an extra shoot. Returns the EnId that lost the events,
 * or 0 when nothing moved. When no shoot takes part (organiser's choice), nothing moves.
 */
function bk_events_to_first_shoot($tourId, $code, $division)
{
    $shoots = bk_shoots_in_order($tourId, $code, $division);
    if (count($shoots) < 2 || intval($shoots[0]->EnIndClEvent) === 1) return 0;
    foreach ($shoots as $s) {
        if (intval($s->EnIndClEvent) !== 1) continue;
        bk_event_flags_set($tourId, $shoots[0]->EnId, (array) $s);
        bk_event_flags_set($tourId, $s->EnId, array());
        return intval($s->EnId);
    }
    return 0;
}

/**
 * A shoot is removed: if it took part in the events ($old: its EnCode, EnDivision and flags,
 * read before removal), the first remaining shoot of the competition with the same weapon
 * takes them over, with the same flags. Returns the EnId promoted, or 0.
 */
function bk_events_after_removal($tourId, $old)
{
    if (!$old || intval($old->EnIndClEvent ?? 0) !== 1) return 0;
    $shoots = bk_shoots_in_order($tourId, $old->EnCode, $old->EnDivision);
    if (!$shoots) return 0;
    bk_event_flags_set($tourId, $shoots[0]->EnId, (array) $old);
    return intval($shoots[0]->EnId);
}

/**
 * Registers an archer. Returns ['ok'=>true, 'enid'=>N] or ['ok'=>false, 'msg'=>…].
 *
 * $lue     : LookUpEntries record (federation identity)
 * $by      : ['role'=>'SELF'|'MANAGER', 'who'=>identifier, 'archer'=>BaId]
 */
function bk_register($tourId, $lue, $division, $class, $sessionOrder, $request, $by, $opts = array())
{
    $tourId = intval($tourId);

    // Last guard: every registration path goes through here. Never a write on a finished
    // competition, whatever happened upstream.
    if (bk_comp_finished($tourId)) {
        return array('ok' => false, 'msg' => bk_t('RgFinished'));
    }

    // Last "non-practising" guard (LueStatus = 9): the subject can never be registered for a
    // competition, whatever the path (self-registration or group registration).
    if (intval($lue->LueStatus ?? 0) === 9) {
        return array('ok' => false, 'msg' => bk_t('RgNoPractice'));
    }

    return bk_with_tournament($tourId, function () use ($tourId, $lue, $division, $class, $sessionOrder, $request, $by, $opts) {

        $now  = date('Y-m-d H:i:s');
        $coId = bk_reg_club_id($tourId, $lue->LueCountry, $lue->LueCoDescr);

        // EnAthlete: derived from the division and the class, like PopEdit.php.
        $rs = safe_r_sql("SELECT (DivAthlete AND ClAthlete) AS Athlete
            FROM Divisions INNER JOIN Classes ON DivTournament = ClTournament
            WHERE DivTournament = $tourId
              AND DivId = " . StrSafe_DB($division) . "
              AND ClId  = " . StrSafe_DB($class));
        $r = safe_fetch($rs);
        $athlete = ($r && $r->Athlete) ? 1 : 0;
        if (!$athlete) return array('ok' => false, 'msg' => bk_t('RgBadCategory'));

        // Target face: the one the archer chose when it is among those the settings allow for
        // their category, otherwise the first offered (getTargets sorts from the most specific
        // to the most generic).
        $face = 0;
        $all = getTargets(true);
        if (!empty($all[$division][$class])) {
            $ids  = array_map('intval', array_keys($all[$division][$class]));
            $want = intval($opts['face'] ?? 0);
            $face = ($want && in_array($want, $ids, true)) ? $want : $ids[0];
        }

        // Admission check (faces sharing a target): the field plan defines what is technically
        // possible. When no target of this departure can take this profile any more (bow +
        // category + face), the registration is REFUSED — otherwise the archer could never be
        // placed. bk_profile_remaining lives in targets.php (loaded as soon as there is
        // placement); without it, nothing is blocked.
        if (empty($opts['skip_capacity']) && function_exists('bk_profile_remaining')) {
            $rem = bk_profile_remaining($tourId, $sessionOrder, $division, $class, $face);
            if ($rem !== null && $rem < 1) {
                return array('ok' => false, 'msg' => bk_t('RgProfileFull'));
            }
        }

        // Events: with the same weapon, only the first shoot of the competition takes part
        // (see bk_events_to_first_shoot). Inserted out of the events when another shoot
        // already takes part; moved just below if this one comes first in the competition.
        $rs = safe_r_sql("SELECT COUNT(*) AS n FROM Entries
            WHERE EnTournament = $tourId
              AND EnCode = " . StrSafe_DB($lue->LueCode) . "
              AND EnDivision = " . StrSafe_DB($division) . "
              AND EnAthlete = 1
              AND EnIndClEvent = 1");
        $r = safe_fetch($rs);
        $ev = ($r && intval($r->n) > 0) ? 0 : 1;

        $sql = "EnTournament = $tourId,
            EnDivision = "   . StrSafe_DB($division) . ",
            EnClass = "      . StrSafe_DB($class) . ",
            EnAthlete = $athlete,
            EnSubClass = " . StrSafe_DB($lue->LueSubClass ?: '') . ",
            EnAgeClass = " . StrSafe_DB($class) . ",
            EnCountry = $coId,
            EnDob = "     . StrSafe_DB($lue->LueCtrlCode ?: '0000-00-00') . ",
            EnCode = "    . StrSafe_DB($lue->LueCode) . ",
            EnName = "      . StrSafe_DB(AdjustCaseTitle($lue->LueName)) . ",
            EnFirstName = " . StrSafe_DB(AdjustCaseTitle($lue->LueFamilyName)) . ",
            EnSex = " . (intval($lue->LueSex) ? 1 : 0) . ",
            EnTargetFace = $face,
            EnStatus = 1,
            EnIndClEvent = $ev, EnTeamClEvent = $ev,
            EnIndFEvent = $ev, EnTeamFEvent = $ev, EnTeamMixEvent = $ev";

        safe_w_sql("INSERT INTO Entries SET EnTimestamp = '$now', EnMainInfoUpdate = '$now', $sql");
        $enId = intval(safe_w_last_id());
        if (!$enId) return array('ok' => false, 'msg' => bk_t('RegFailed'));

        // Empty EnIocCode = inherits the competition's ToIocCode (core convention:
        // LueIocCode = IF(EnIocCode!='', EnIocCode, ToIocCode)). Only set when the archer
        // belongs to another federation.
        $rs = safe_r_sql("SELECT ToIocCode FROM Tournament WHERE ToId = $tourId");
        $to = safe_fetch($rs);
        if ($to && $lue->LueIocCode && $lue->LueIocCode !== $to->ToIocCode) {
            safe_w_sql("UPDATE Entries SET EnIocCode = " . StrSafe_DB($lue->LueIocCode)
                . ", EnTimestamp = EnTimestamp WHERE EnId = $enId");
        }

        // Matching Qualifications row (1:1, QuId = EnId), then the departure.
        safe_w_sql("INSERT INTO Qualifications (QuId, QuSession) VALUES ($enId, 0)");
        safe_w_sql("UPDATE Qualifications SET QuSession = " . intval($sessionOrder)
            . ", QuTarget = 0, QuLetter = '', QuTimestamp = QuTimestamp WHERE QuId = $enId");

        // Registered on a departure before an existing shoot with the same weapon: this one
        // now takes part in the events, the other becomes the extra shoot.
        $lost = bk_events_to_first_shoot($tourId, $lue->LueCode, $division);

        // Core recalculation hooks, as Partecipants/PopEdit.php does — without them rankings
        // and teams stay out of date. Also for the shoot that lost the events.
        $rs = safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId");
        $t = safe_fetch($rs);
        foreach (array_unique(array_filter(array($enId, $lost))) as $recalcId) {
            $p = Params4Recalc($recalcId);
            if ($p === false) continue;
            list($indF, $teamF, $country, $div, $cl, $subCl, $zero) = $p;
            RecalculateShootoffAndTeams($indF, $teamF, $country, $div, $cl, $subCl, $zero);
            if ($t) for ($i = 0; $i < intval($t->ToNumDist); $i++) CalcQualRank($i, $div . $cl);
        }
        MakeIndAbs();
        checkAgainstLUE($enId);

        // Wishes: only those the organiser offers are honoured. Checked again here (write side):
        // a forged POST must not turn on a disabled wish, whoever calls (archer or club
        // manager).
        $wLetter = strtoupper(substr(trim((string) ($opts['letter'] ?? '')), 0, 2));
        $wWith   = bk_clean_licence($opts['with'] ?? '');
        $wReq    = mb_substr(trim((string) $request), 0, 2000);
        if (function_exists('bk_comp_config')) {
            $wc = bk_comp_config($tourId);
            if (empty($wc->BcWishLetter)) $wLetter = '';
            if (empty($wc->BcWishWith))   $wWith   = '';
            if (empty($wc->BcWishFree))   $wReq    = '';
        }

        // Manual validation: in manual mode the registration arrives "pending" (BrValidated=0)
        // and is not placed until the organiser validates it.
        $mv = safe_fetch(safe_r_sql("SELECT BcManualValidation FROM BK_Competitions WHERE BcTournament = $tourId"));
        $validated = ($mv && intval($mv->BcManualValidation) === 1) ? 0 : 1;

        // Booking tracking (author, special requests).
        safe_w_sql("INSERT INTO BK_Registrations SET
            BrEnId = $enId,
            BrTournament = $tourId,
            BrArcher = " . intval($by['archer'] ?? 0) . ",
            BrLicence = " . StrSafe_DB($lue->LueCode) . ",
            BrByRole = "  . StrSafe_DB($by['role'] ?? 'SELF') . ",
            BrBy = "      . StrSafe_DB(substr((string) ($by['who'] ?? ''), 0, 64)) . ",
            BrRequest = " . StrSafe_DB($wReq) . ",
            BrWantLetter = " . StrSafe_DB($wLetter) . ",
            BrWantWith = "   . StrSafe_DB($wWith) . ",
            BrValidated = $validated,
            BrDivision = " . StrSafe_DB($division) . ",
            BrClass = "    . StrSafe_DB($class) . ",
            BrSession = "  . intval($sessionOrder) . ",
            BrFace = $face");

        return array('ok' => true, 'enid' => $enId, 'event' => $ev, 'validated' => $validated);
    });
}

/**
 * Cancels a registration. Accepts ONLY registrations made through booking (in
 * BK_Registrations): an archer must never be able to delete a participant entered by the
 * organiser. Allowed when the registration is the archer's ($licence) OR when they are its
 * AUTHOR (group registration made for a club mate, BrArcher = $archerId).
 */
function bk_unregister($enId, $archerId, $licence)
{
    $enId = intval($enId);
    $archerId = intval($archerId);

    $rs = safe_r_sql("SELECT r.BrTournament, r.BrLicence, r.BrArcher, r.BrByRole, e.EnTournament
        FROM BK_Registrations r
        INNER JOIN Entries e ON e.EnId = r.BrEnId
        WHERE r.BrEnId = $enId");
    $r = safe_fetch($rs);
    if (!$r) return array('ok' => false, 'msg' => "Inscription introuvable.");
    // Registration taken from an import (entered outside the module by the organiser): visible in
    // the archer's space, but NOT cancellable by them — the rule "an archer never deletes a
    // participant entered by the organiser" holds despite the takeover.
    if ((string) $r->BrByRole === 'IMPORT') {
        return array('ok' => false, 'msg' => bk_t('RgByOrganiser'));
    }
    $isOwn    = bk_clean_licence($r->BrLicence) === bk_clean_licence($licence);
    $isAuthor = $archerId > 0 && intval($r->BrArcher) === $archerId;
    if (!$isOwn && !$isAuthor) {
        return array('ok' => false, 'msg' => bk_t('NotYourReg'));
    }

    $tourId = intval($r->BrTournament);
    $cfg    = bk_comp_config($tourId);
    if (empty($cfg->BcIsOpen)) {
        return array('ok' => false, 'msg' => bk_t('RgClosed'));
    }

    $res = bk_with_tournament($tourId, function () use ($enId, $tourId) {
        if (IsBlocked(BIT_BLOCK_PARTICIPANT)) return false;

        // Keep the bow and the event flags BEFORE deletion: when the registration carrying the
        // event is deleted while the archer keeps other shoots with the same bow, one of them
        // must take it over, otherwise they would vanish from the ranking while still
        // registered.
        $rs = safe_r_sql("SELECT EnCode, EnDivision, " . implode(', ', bk_event_cols()) . " FROM Entries WHERE EnId = $enId");
        $old = safe_fetch($rs);

        $p = Params4Recalc($enId);
        deleteArcher($enId);

        // The first remaining shoot of the competition takes the events over.
        bk_events_after_removal($tourId, $old);

        if ($p !== false) {
            list($indF, $teamF, $country, $div, $cl, $subCl, $zero) = $p;
            RecalculateShootoffAndTeams($indF, $teamF, $country, $div, $cl, $subCl, $zero);
            $rs = safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId");
            if ($t = safe_fetch($rs)) {
                for ($i = 0; $i < intval($t->ToNumDist); $i++) CalcQualRank($i, $div . $cl);
            }
            MakeIndAbs();
        }
        return true;
    });

    if (!$res) return array('ok' => false, 'msg' => bk_t('RgLocked'));

    safe_w_sql("DELETE FROM BK_Registrations WHERE BrEnId = $enId");
    return array('ok' => true);
}

/** Registrations of an archer (all competitions), the most recent first. */
function bk_my_registrations($licence)
{
    bk_schema();
    $rs = safe_r_sql("SELECT r.BrId, r.BrEnId, r.BrTournament, r.BrRequest, r.BrCreated, r.BrByRole,
                r.BrWantLetter, r.BrWantWith, r.BrValidated,
                e.EnDivision, e.EnClass, e.EnIndClEvent, e.EnTargetFace,
                q.QuSession, q.QuTarget, q.QuLetter,
                d.DivDescription, c.ClDescription,
                t.ToName, t.ToWhere, t.ToVenue, t.ToWhenFrom, t.ToWhenTo,
                t.ToType, t.ToTypeName, t.ToTypeSubRule,
                o.BcShowAssignment, o.BcAllowScoresheet, o.BcFee, o.BcMandate, o.BcShowMandate, o.BcIanseoUrl,
                o.BcShowProgram, o.BcShowParticipants, o.BcShowResults, o.BcPublishLevel,
                " . bk_comp_calc_sql('o') . "
        FROM BK_Registrations r
        INNER JOIN Entries e        ON e.EnId = r.BrEnId
        INNER JOIN Qualifications q ON q.QuId = e.EnId
        INNER JOIN Tournament t     ON t.ToId = r.BrTournament
        LEFT  JOIN Divisions d      ON d.DivTournament = t.ToId AND d.DivId = e.EnDivision
        LEFT  JOIN Classes c        ON c.ClTournament  = t.ToId AND c.ClId  = e.EnClass
        LEFT  JOIN BK_Competitions o ON o.BcTournament = t.ToId
        WHERE r.BrLicence = " . StrSafe_DB(bk_clean_licence($licence)) . "
        ORDER BY t.ToWhenFrom DESC");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}
