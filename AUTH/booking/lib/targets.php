<?php
/**
 * lib/targets.php — target assignment and check of the federation rules.
 *
 * The possible slots are NOT computed here: they come from createAvailableTargetSQL()
 * (Common/Globals.inc.php), the virtual view the ianseo core builds from Session
 * (SesFirstTarget/SesTar4Session/SesAth4Target). The AvailableTarget table exists but is dead
 * (its INSERT is commented out in Fun_ManSessions.inc.php): never rely on it.
 *
 * ⚠️ Qualifications has no competition column: every read and every write goes through a join
 * on Entries (EnTournament).
 */

if (defined('BK_TARGETS_LOADED')) return;
define('BK_TARGETS_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/competition.php';
require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/caps.php';
require_once __DIR__ . '/cohabitation.php';   // faces sharing a target (M7)
require_once __DIR__ . '/placement.php';      // placement by face (pure planner)

/** ianseo format of QuTargetNo: departure + target on 3 digits + letter → 1004A. */
function bk_target_no($session, $target, $letter)
{
    // bytes: target letters and numbers are ASCII
    return intval($session) . str_pad(intval($target), 3, '0', STR_PAD_LEFT) . strtoupper($letter);
}

/**
 * Free slots of a departure, ordered by target then letter. The slots are those
 * createAvailableTargetSQL() of the core defines (targets of the Session row × its letters),
 * built here from that row rather than filtered in SQL: a filter on that derived table turns
 * quadratic under MySQL 8 (derived_condition_pushdown), and its query cost 60 to 650 ms per
 * departure even under MariaDB.
 */
function bk_free_slots($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $sessionOrder = intval($sessionOrder);
    $ses = safe_fetch(safe_r_sql("SELECT SesFirstTarget, SesTar4Session, SesAth4Target FROM Session
        WHERE SesTournament = $tourId AND SesOrder = $sessionOrder AND SesType = 'Q'"));
    if (!$ses) return array();
    $taken = array();
    $rs = safe_r_sql("SELECT QuTarget, QuLetter FROM Qualifications
        INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
        WHERE QuSession = $sessionOrder");
    // bytes: target letters are ASCII (the SQL join compared them without case)
    while ($r = safe_fetch($rs)) $taken[intval($r->QuTarget) . '|' . strtoupper((string) $r->QuLetter)] = true;
    $out = array();
    $first = intval($ses->SesFirstTarget);
    for ($t = $first; $t < $first + intval($ses->SesTar4Session); $t++) {
        for ($i = 0; $i < intval($ses->SesAth4Target); $i++) {
            $l = chr(65 + $i);
            if (!isset($taken[$t . '|' . $l])) $out[] = array('t' => $t, 'l' => $l);
        }
    }
    return $out;
}

/** Archers of a departure, with their club and their place if already assigned. */
function bk_session_archers($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnDivision, EnClass,
                EnCountry, EnTargetFace, EnWChair, CoCode, CoName,
                QuTarget, QuLetter, BrRequest, BrEnId AS BrRow, BrValidated
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT  JOIN Countries ON CoId = EnCountry
        LEFT  JOIN BookingRegistrations ON BrEnId = EnId
        WHERE EnTournament = $tourId AND EnAthlete = 1
          AND QuSession = " . intval($sessionOrder) . "
        ORDER BY CoCode, EnFirstName, EnName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Same, with the structured wishes and the origin of the registration. */
function bk_session_archers_full($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnDivision, EnClass,
                EnCountry, EnTargetFace, EnWChair, CoCode, CoName,
                QuTarget, QuLetter,
                BrEnId, BrRequest, BrWantLetter, BrWantWith, BrValidated
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT  JOIN Countries ON CoId = EnCountry
        LEFT  JOIN BookingRegistrations ON BrEnId = EnId
        WHERE EnTournament = $tourId AND EnAthlete = 1
          AND QuSession = " . intval($sessionOrder) . "
        ORDER BY CoCode, EnFirstName, EnName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * What the planner (lib/placement.php) needs of a departure, read from ianseo: discipline,
 * rhythm, targets, field settings (faces turned into families), archers already placed (they
 * never move) and archers to place, with their distances series by series, their family of
 * faces at each distance, their club and their wishes.
 *
 * Returns null when the departure does not exist, else
 * ['in' => planner input, 'rows' => [EnId => row], 'type' => ToType, 'fams' => fn(TfId, nDist): families, 'nDist' => int].
 */
function bk_plan_read($tourId, $sessionOrder, $cfg = null)
{
    $tourId = intval($tourId);
    $sessionOrder = intval($sessionOrder);
    $tr = safe_fetch(safe_r_sql("SELECT ToType, ToTypeName, ToTypeSubRule, ToNumDist FROM Tournament WHERE ToId = $tourId"));
    $ses = safe_fetch(safe_r_sql("SELECT SesFirstTarget, SesTar4Session, SesAth4Target FROM Session
        WHERE SesTournament = $tourId AND SesOrder = $sessionOrder AND SesType = 'Q'"));
    if (!$tr || !$ses) return null;
    $type = $tr->ToType;
    $disc = bk_comp_discipline($type, $tr->ToTypeSubRule, $tr->ToTypeName)['key'];
    $nDist = max(1, intval($tr->ToNumDist));

    // Families of every face of the competition, distance by distance (TfT<n> / TfW<n>).
    $faces = array();
    $rs = safe_r_sql("SELECT * FROM TargetFaces WHERE TfTournament = $tourId");
    while ($r = safe_fetch($rs)) {
        $tw = array();
        for ($i = 1; $i <= 8; $i++) $tw[] = array(intval($r->{'TfT' . $i} ?? 0), intval($r->{'TfW' . $i} ?? 0));
        $faces[intval($r->TfId)] = array('name' => (string) $r->TfName, 'tw' => $tw);
    }
    $famCache = array();
    $fams = function ($faceId, $n) use (&$famCache, $faces, $disc) {
        $k = intval($faceId) . '|' . $n;
        if (!isset($famCache[$k])) {
            $f = $faces[intval($faceId)] ?? null;
            $famCache[$k] = $f ? bk_face_families($disc, $f['name'], $f['tw'], $n) : array_fill(0, $n, '?');
        }
        return $famCache[$k];
    };

    // Field settings: faces (TfId) turned into the families they carry.
    $caps = array();
    foreach (bk_caps_get($tourId, $sessionOrder) as $t => $c) {
        $f = array();
        foreach ($c['f'] as $id) foreach ($fams($id, $nDist) as $x) $f[$x] = true;
        // array_keys turns '60' into the integer 60: back to strings, as the families are.
        $caps[$t] = array('min' => $c['min'], 'max' => $c['max'], 'fams' => array_map('strval', array_keys($f)));
    }

    $targets = array();
    for ($t = intval($ses->SesFirstTarget); $t < intval($ses->SesFirstTarget) + intval($ses->SesTar4Session); $t++) $targets[] = $t;

    $in = array('disc' => $disc, 'rhythm' => max(1, intval($ses->SesAth4Target)), 'targets' => $targets,
                'maxPerClub' => $cfg ? max(1, intval($cfg->BcMaxPerClubPerTarget)) : 99,
                // Targets filled (2 archers or more) at least, rule of the regulations.
                'minTargets' => $cfg ? max(0, intval($cfg->BcMinTargetsPerSession ?? 0)) : 0,
                'caps' => $caps, 'fixed' => array(), 'archers' => array());
    $rows = array();
    $byLicence = array();
    $archers = bk_session_archers_full($tourId, $sessionOrder);
    foreach ($archers as $a) $byLicence[bk_clean_licence($a->EnCode)] = intval($a->EnId);
    foreach ($archers as $a) {
        $id = intval($a->EnId);
        $rows[$id] = $a;
        $dist = bk_plan_series($tourId, $type, $a->EnDivision, $a->EnClass, $nDist);
        $one = array('id' => $id, 'dist' => $dist, 'fam' => $fams($a->EnTargetFace, max(1, count($dist))),
                     'club' => (string) ($a->CoCode ?: '?'),
                     // Wheelchair (ianseo's own field): no 4 archers on their target (B.1.2).
                     'wheelchair' => intval($a->EnWChair ?? 0) > 0);
        if (intval($a->QuTarget) > 0) {
            // bytes: target letters are ASCII
            $in['fixed'][] = $one + array('t' => intval($a->QuTarget), 'l' => strtoupper((string) $a->QuLetter));
            continue;
        }
        // Online registration not validated yet (manual mode) → not placed.
        if (!empty($a->BrEnId) && intval($a->BrValidated) === 0) continue;
        $want = bk_clean_licence($a->BrWantWith ?? '');
        $one['with'] = ($want !== '' && isset($byLicence[$want]) && $byLicence[$want] !== $id) ? $byLicence[$want] : null;
        // bytes: target letters are ASCII
        $one['letter'] = strtoupper(trim((string) ($a->BrWantLetter ?? '')));
        $in['archers'][] = $one;
    }
    return array('in' => $in, 'rows' => $rows, 'type' => $type, 'fams' => $fams, 'nDist' => $nDist);
}

/**
 * Distances of a category, series by series, in metres (TournamentDistances: Td<n> / TdDist<n>,
 * the most specific TdClasses). A series without a distance ("-") is left out.
 */
function bk_plan_series($tourId, $type, $division, $class, $nDist)
{
    static $cache = array();
    $k = intval($tourId) . '|' . $type . '|' . $division . '|' . $class;
    if (isset($cache[$k])) return $cache[$k];
    $rs = safe_r_sql("SELECT * FROM TournamentDistances
        WHERE TdTournament = " . intval($tourId) . "
          AND TdType = " . StrSafe_DB($type) . "
          AND " . StrSafe_DB(trim($division) . trim($class)) . " LIKE TdClasses
        ORDER BY CHAR_LENGTH(TdClasses) DESC LIMIT 1");
    $d = array();
    if ($r = safe_fetch($rs)) {
        for ($i = 1; $i <= min(8, max(1, intval($nDist))); $i++) {
            $lab = trim((string) ($r->{'Td' . $i} ?? ''));
            if ($lab === '' || $lab === '-') continue;
            $m = bk_td_metres($lab, $r->{'TdDist' . $i} ?? 0);
            if ($m > 0) $d[] = $m;
        }
    }
    return $cache[$k] = $d;
}

/**
 * Assigns a target to the archers of a departure who have none yet, by FACE and not by category
 * (lib/placement.php, rules in REGLES_PLACEMENT_FR.md).
 *
 * NEVER MOVES an archer already placed: the organiser may have adjusted by hand (or another tool
 * placed them), and a late registration must not reshuffle everyone. Writes QuTarget, QuLetter
 * and QuTargetNo, as the target screens of the core do.
 *
 * Returns ['places'=>N, 'restants'=>M, 'compromis'=>K, 'melanges'=>X, 'incompatibles'=>I,
 * 'voeux'=>W, 'voeuxOk'=>V]: `compromis` counts the archers placed beyond the club quota,
 * `melanges` the targets carrying both 40 and triple faces, `fauteuil` the targets of 4 archers
 * with a wheelchair archer, for lack of room, `incompatibles` the archers no
 * target of the departure can take (field settings).
 */
function bk_assign_session($tourId, $sessionOrder, $cfg)
{
    $tourId = intval($tourId);
    $zero = array('places' => 0, 'restants' => 0, 'compromis' => 0, 'melanges' => 0, 'fauteuil' => 0,
                  'incompatibles' => 0, 'voeux' => 0, 'voeuxOk' => 0);
    $data = bk_plan_read($tourId, $sessionOrder, $cfg);
    if ($data === null || !$data['in']['archers']) return $zero;

    $res = bk_plan_session($data['in']);

    foreach ($res['places'] as $id => $p) {
        $no = bk_target_no($sessionOrder, $p['t'], $p['l']);
        // Redundant safeguard: the UPDATE aims at one EnId AND checks the competition again —
        // Qualifications alone would spill over the whole database.
        safe_w_sql("UPDATE Qualifications
            INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
            SET QuTarget = " . intval($p['t']) . ",
                QuLetter = " . StrSafe_DB($p['l']) . ",
                QuTargetNo = " . StrSafe_DB($no) . ",
                QuTimestamp = QuTimestamp
            WHERE QuId = " . intval($id));
    }

    // Actual satisfaction, measured AFTERWARDS rather than guessed during placement: a "with
    // someone" wish can only be judged once both are placed (archers already placed included).
    $pose = array();
    foreach ($data['in']['fixed'] as $f) $pose[bk_clean_licence($data['rows'][$f['id']]->EnCode)] = array('t' => $f['t'], 'l' => $f['l'], 'a' => $data['rows'][$f['id']]);
    foreach ($res['places'] as $id => $p) $pose[bk_clean_licence($data['rows'][$id]->EnCode)] = array('t' => $p['t'], 'l' => $p['l'], 'a' => $data['rows'][$id]);
    $voeux = 0; $voeuxOk = 0;
    foreach ($data['in']['archers'] as $one) {
        $a = $data['rows'][$one['id']];
        // bytes: target letters are ASCII
        $wl = strtoupper(trim((string) ($a->BrWantLetter ?? '')));
        $ww = bk_clean_licence($a->BrWantWith ?? '');
        if ($wl === '' && $ww === '') continue;
        $voeux++;
        $me = $pose[bk_clean_licence($a->EnCode)] ?? null;
        if (!$me) continue;
        if ($wl !== '' && $me['l'] !== $wl) continue;
        if ($ww !== '' && (!isset($pose[$ww]) || $pose[$ww]['t'] !== $me['t'])) continue;
        $voeuxOk++;
    }

    // Those NO target of the departure can take (field settings), told apart from a lack of room.
    $incompatibles = 0;
    $ctx = array('caps' => $data['in']['caps']);
    $byId = array();
    foreach ($data['in']['archers'] as $one) $byId[$one['id']] = $one;
    foreach ($res['unplaced'] as $id) {
        $one = $byId[$id];
        $ok = false;
        foreach ($data['in']['targets'] as $t) {
            if (bk_plan_caps_ok($ctx, $t, array('dist' => $one['dist'], 'fams' => $one['fam']))) { $ok = true; break; }
        }
        if (!$ok) $incompatibles++;
    }

    return array('places' => count($res['places']), 'restants' => count($res['unplaced']),
                 'compromis' => $res['overQuota'], 'melanges' => $res['mixed40tri'], 'fauteuil' => $res['wheelchairFull'],
                 'incompatibles' => $incompatibles, 'voeux' => $voeux, 'voeuxOk' => $voeuxOk);
}

/**
 * Places STILL available on a departure for a given PROFILE (bow, category, face) — the
 * "specific gauge" of the registration, and the admission check: 0 ⇒ no target can take this
 * archer any more, the registration must be refused.
 *
 * Measured with the placement itself (lib/placement.php): as many archers of this profile as
 * there are free places are planned around the archers already placed, the ones the plan takes
 * are the gauge. Returns null when the constraint is unknown (no departure). Based on the
 * current placements: exact with AUTOMATIC validation (each registration is placed at once);
 * with MANUAL validation, the pending registrations are not placed yet.
 */
function bk_profile_remaining($tourId, $sessionOrder, $division, $class, $faceId)
{
    $ctx = bk_profile_ctx($tourId, $sessionOrder);
    return $ctx === null ? null : bk_profile_left($ctx, $division, $class, $faceId);
}

/**
 * What bk_profile_remaining needs of a departure, read once: several profiles can then be
 * measured against it (the table of the places left per category). null: no such departure.
 */
function bk_profile_ctx($tourId, $sessionOrder)
{
    $data = bk_plan_read($tourId, $sessionOrder);
    if ($data === null) return null;
    $data['in']['archers'] = array();   // the archers still to place are not counted (as before)
    $data['tour'] = intval($tourId);
    $data['left'] = array();
    return $data;
}

/** bk_caps_needs, kept for the request: the same categories come back for every departure. */
function bk_profile_needs($tourId, $type, $div, $cls, $fid)
{
    static $cache = array();
    $k = intval($tourId) . '|' . $type . '|' . $div . '|' . $cls . '|' . intval($fid);
    if (!isset($cache[$k])) $cache[$k] = bk_caps_needs($tourId, $type, $div, $cls, $fid);
    return $cache[$k];
}

/**
 * Places of a departure (bk_profile_ctx) still open to one profile — see bk_profile_remaining.
 *
 * ⚠️ PLACES, not targets. A former version added up, per target, "how many more of this face NOW"
 * (bk_cohabit_max_add): on an empty outdoor target a full face (122) costs the whole budget, so it
 * returned 1 although this face is then SHARED by up to SesAth4Target archers — the gauge showed
 * the number of free targets instead of the places (confusion reported at registration). Hence
 * the simulation with the planner itself: never go back to a shortcut that counts targets.
 */
function bk_profile_left(&$ctx, $division, $class, $faceId)
{
    $dist = bk_plan_series($ctx['tour'], $ctx['type'], $division, $class, $ctx['nDist']);
    $fam = ($ctx['fams'])($faceId, max(1, count($dist)));
    $key = implode('-', $dist) . '#' . implode('/', $fam);
    if (isset($ctx['left'][$key])) return $ctx['left'][$key];

    $in = $ctx['in'];
    $free = count($in['targets']) * $in['rhythm'] - count($in['fixed']);
    $in['maxPerClub'] = $in['rhythm'];
    $in['fast'] = true;
    for ($i = 0; $i < max(0, $free); $i++) {
        $in['archers'][] = array('id' => -1 - $i, 'dist' => $dist, 'fam' => $fam, 'club' => 'G' . $i);
    }
    $n = $in['archers'] ? count(bk_plan_session($in)['places']) : 0;
    return $ctx['left'][$key] = $n;
}

/**
 * Plans a departure ENTIRELY again to best meet the archers' wishes, then places everyone.
 *
 * Called after each registration: a "with someone" wish cannot be met by placing people one by
 * one in order of arrival — the cards must be reshuffled.
 *
 * ⚠️ Frees ONLY the registrations made by this module (in BookingRegistrations). A participant
 * entered by the organiser, or placed by another tool, keeps their target: it becomes a fixed
 * obstacle. Without this guard, an online registration would move the organiser's manual work.
 */
function bk_replan_session($tourId, $sessionOrder, $cfg)
{
    $tourId = intval($tourId);
    safe_w_sql("UPDATE Qualifications
        INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
        INNER JOIN BookingRegistrations ON BrEnId = EnId
        SET QuTarget = 0, QuLetter = '', QuTargetNo = '', QuTimestamp = QuTimestamp
        WHERE QuSession = " . intval($sessionOrder));
    return bk_assign_session($tourId, $sessionOrder, $cfg);
}

/**
 * Plans again every departure where the module has registrations. Used after a registration or
 * a cancellation, when the organiser leaves the automatic placement on.
 */
function bk_replan_all($tourId, $cfg)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT DISTINCT QuSession FROM Qualifications
        INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
        INNER JOIN BookingRegistrations ON BrEnId = EnId
        WHERE QuSession > 0");
    $tot = array('places' => 0, 'restants' => 0, 'compromis' => 0, 'melanges' => 0, 'fauteuil' => 0,
                 'incompatibles' => 0, 'voeux' => 0, 'voeuxOk' => 0);
    while ($r = safe_fetch($rs)) {
        $x = bk_replan_session($tourId, intval($r->QuSession), $cfg);
        foreach ($tot as $k => $v) $tot[$k] = $v + ($x[$k] ?? 0);
    }
    return $tot;
}

/* ---- Manual validation of the registrations ---------------------------- */

/** Online registrations waiting for validation (BrValidated=0). */
function bk_pending_registrations($tourId)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT BrEnId, BrCreated, EnFirstName, EnName, EnCode,
                EnDivision, EnClass, DivDescription, ClDescription,
                CoName, CoCode, QuSession
        FROM BookingRegistrations
        INNER JOIN Entries        ON EnId = BrEnId
        /* 1:1 with Entries: INNER JOIN, never LEFT + IS NULL. */
        INNER JOIN Qualifications ON QuId = EnId
        LEFT  JOIN Divisions      ON DivTournament = EnTournament AND DivId = EnDivision
        LEFT  JOIN Classes       ON ClTournament = EnTournament AND ClId = EnClass
        LEFT  JOIN Countries      ON CoId = EnCountry
        WHERE BrTournament = $tourId AND BrValidated = 0
        ORDER BY BrCreated, BrId");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Number of registrations waiting for validation. */
function bk_pending_count($tourId)
{
    $tourId = intval($tourId);
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) n FROM BookingRegistrations
        WHERE BrTournament = " . $tourId . " AND BrValidated = 0"));
    return $r ? intval($r->n) : 0;
}

/** Validates a registration, then places its departure. Returns bool. */
function bk_validate_registration($tourId, $enId, $cfg)
{
    $tourId = intval($tourId); $enId = intval($enId);
    $r = safe_fetch(safe_r_sql("SELECT QuSession FROM BookingRegistrations
        INNER JOIN Qualifications ON QuId = BrEnId
        WHERE BrEnId = $enId AND BrTournament = $tourId AND BrValidated = 0"));
    if (!$r) return false;
    safe_w_sql("UPDATE BookingRegistrations SET BrValidated = 1 WHERE BrEnId = $enId AND BrTournament = $tourId");
    bk_replan_session($tourId, intval($r->QuSession), $cfg);
    return true;
}

/** Validates every pending registration + places the departures. Returns the number validated. */
function bk_validate_all($tourId, $cfg)
{
    $tourId = intval($tourId);
    $sessions = array(); $n = 0;
    $rs = safe_r_sql("SELECT QuSession FROM BookingRegistrations
        INNER JOIN Qualifications ON QuId = BrEnId
        WHERE BrTournament = $tourId AND BrValidated = 0");
    while ($r = safe_fetch($rs)) { $sessions[intval($r->QuSession)] = true; $n++; }
    if (!$n) return 0;
    safe_w_sql("UPDATE BookingRegistrations SET BrValidated = 1 WHERE BrTournament = $tourId AND BrValidated = 0");
    foreach (array_keys($sessions) as $so) bk_replan_session($tourId, $so, $cfg);
    return $n;
}

/** Frees the targets of a departure (archers back to waiting for a placement). */
function bk_clear_session($tourId, $sessionOrder)
{
    $tourId = intval($tourId);
    safe_w_sql("UPDATE Qualifications
        INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
        SET QuTarget = 0, QuLetter = '', QuTargetNo = '', QuTimestamp = QuTimestamp
        WHERE QuSession = " . intval($sessionOrder));
    return intval(safe_w_affected_rows());
}

/**
 * Check of the rules, departure by departure. To run when registrations close (that is when the
 * check makes sense: before, the field still moves).
 *
 * Returns an array per departure: club/target quota breaches, number of clubs present, archers
 * not placed.
 */
function bk_rules_check($tourId, $cfg)
{
    $tourId   = intval($tourId);
    $max      = max(1, intval($cfg->BcMaxPerClubPerTarget));
    $minClubs = max(1, intval($cfg->BcMinClubsPerSession));
    $minTargets = max(0, intval($cfg->BcMinTargetsPerSession ?? 0));

    $out = array();
    foreach (bk_comp_sessions($tourId) as $s) {
        $order   = intval($s->SesOrder);
        $archers = bk_session_archers($tourId, $order);
        if (!$archers) continue;

        $parCible = array(); $clubs = array(); $nonPlaces = 0; $doublons = array();
        foreach ($archers as $a) {
            $club = (string) ($a->CoCode ?: '?');
            $clubs[$club] = true;
            if (intval($a->QuTarget) > 0) {
                $parCible[intval($a->QuTarget)][$club][] = $a;
            } else {
                $nonPlaces++;
            }
        }

        // Targets filled: 2 archers or more (rule of the regulations, with the clubs). The greffe
        // also checks that no archer is left alone on a target, nor 2 archers of one club alone
        // (they would mark each other's scores) — regulations B.1.2, after withdrawals too.
        $remplies = 0; $seuls = array(); $paires = array();
        foreach ($parCible as $cible => $parClub) {
            $n = 0;
            foreach ($parClub as $liste) $n += count($liste);
            if ($n >= 2) $remplies++;
            if ($n === 1) $seuls[] = $cible;
            if ($n === 2 && count($parClub) === 1) $paires[] = array('cible' => $cible, 'club' => (string) key($parClub));   // a numeric club code is an int key
        }

        $exces = array();
        foreach ($parCible as $cible => $parClub) {
            foreach ($parClub as $club => $liste) {
                if (count($liste) > $max) {
                    $exces[] = array('cible' => $cible, 'club' => $club, 'n' => count($liste));
                }
            }
        }

        // The same archer twice on one departure (should never happen through booking, but the
        // organiser also enters by hand).
        $vus = array();
        foreach ($archers as $a) {
            $k = bk_clean_licence($a->EnCode);
            if ($k === '') continue;
            if (isset($vus[$k])) $doublons[$k] = ($doublons[$k] ?? 1) + 1;
            $vus[$k] = true;
        }

        $out[] = array(
            'depart'     => $order,
            'nom'        => $s->SesName,
            'archers'    => count($archers),
            'clubs'      => count($clubs),
            'minClubs'   => $minClubs,
            'clubsOk'    => count($clubs) >= $minClubs,
            'remplies'   => $remplies,
            'minTargets' => $minTargets,
            'targetsOk'  => $remplies >= $minTargets,
            'max'        => $max,
            'exces'      => $exces,
            'nonPlaces'  => $nonPlaces,
            'doublons'   => $doublons,
            'seuls'      => $seuls,
            'paires'     => $paires,
            'ok'         => !$exces && count($clubs) >= $minClubs && $remplies >= $minTargets && $nonPlaces === 0 && !$doublons
                            && !$seuls && !$paires,
        );
    }
    return $out;
}

/**
 * Plan of a departure for display: target → letter → archer.
 * Used by the organiser and, when BcShowAssignment, by the archers.
 */
function bk_session_plan($tourId, $sessionOrder)
{
    $plan = array();
    foreach (bk_session_archers($tourId, $sessionOrder) as $a) {
        if (intval($a->QuTarget) > 0) {
            // bytes: target letters and numbers are ASCII
            $plan[intval($a->QuTarget)][strtoupper($a->QuLetter)] = $a;
        }
    }
    ksort($plan);
    foreach ($plan as &$c) ksort($c);
    return $plan;
}
