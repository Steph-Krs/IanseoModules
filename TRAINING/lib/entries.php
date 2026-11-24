<?php
/**
 * Archers of a training: entering them in the competition, taking them out,
 * placing them on the targets.
 *
 * An entry is a real ianseo entry, written the way Partecipants/PopEdit.php
 * writes one (club in Countries, EnAthlete from the division and the class,
 * target face from getTargets(), one Qualifications row per Entries row), then
 * followed by the core's ranking hooks. Every function here works on the open
 * competition: getTargets() and the hooks read $_SESSION['TourId'].
 */

/**
 * Categories (division and class pairs) an archer may be entered in.
 *
 * @param int $tourId
 * @return array List of ['div', 'cls', 'sex', 'from', 'to'], in the core's order.
 */
function trn_tour_categories($tourId) {
    $out = [];
    $rs = safe_r_sql("SELECT DivId, ClId, ClSex, ClAgeFrom, ClAgeTo
        FROM Divisions INNER JOIN Classes ON ClTournament = DivTournament
        WHERE DivTournament = " . intval($tourId) . " AND DivAthlete = '1' AND ClAthlete = '1'
          AND (ClDivisionsAllowed = '' OR FIND_IN_SET(DivId, ClDivisionsAllowed))
        ORDER BY DivViewOrder, ClViewOrder");
    while ($r = safe_fetch($rs)) {
        $out[] = ['div' => (string)$r->DivId, 'cls' => (string)$r->ClId, 'sex' => intval($r->ClSex),
                  'from' => intval($r->ClAgeFrom), 'to' => intval($r->ClAgeTo)];
    }
    return $out;
}

/** Division of an archer who has none recorded: recurve, the bow of most trainings. */
const TRN_DEFAULT_DIVISION = 'CL';

/** The same bows in the international set (a training made from a World Archery competition). */
const TRN_DIVISION_ALIASES = ['CL' => 'R', 'CO' => 'C', 'BB' => 'B', 'R' => 'CL', 'C' => 'CO', 'B' => 'BB'];

/**
 * The category proposed for a regular archer: their bow (recurve when none is
 * recorded), and the class their date of birth allows in the competition,
 * computed as the core does — year of the end of the competition minus year of
 * birth, between ClAgeFrom and ClAgeTo, for their sex (Partecipants/Get-Classes.php).
 * The class recorded for the archer is kept only when it is one of those, so a
 * class that no longer fits the age is never carried over from a former season.
 *
 * @param array $cats From trn_tour_categories() or a format summary.
 * @param object $archer TrainingArchers row.
 * @param int $year Year to subtract the year of birth from: YEAR(ToWhenTo) with the bounds of a
 *                  competition (which carry the French season offset), trn_season_year() with the
 *                  bounds of a format summary (which carry none).
 * @return string "DIV|CLS", or '' when the format has no category at all.
 */
function trn_default_category($cats, $archer, $year) {
    if (!$cats) return '';
    $div = (string)$archer->ArDivision !== '' ? (string)$archer->ArDivision : TRN_DEFAULT_DIVISION;
    $known = array_column($cats, 'div', 'div');
    if (!isset($known[$div]) && isset(TRN_DIVISION_ALIASES[$div]) && isset($known[TRN_DIVISION_ALIASES[$div]])) $div = TRN_DIVISION_ALIASES[$div];
    $age = trn_is_date($archer->ArDob ?? null) ? intval($year) - intval(mb_substr($archer->ArDob, 0, 4)) : null;
    $fits = function ($c) use ($archer, $age) {
        if ($c['sex'] !== -1 && $c['sex'] !== intval($archer->ArSex)) return false;
        return $age === null || ($age >= $c['from'] && $age <= $c['to']);
    };

    // The recorded class first, when it is allowed, then the first allowed one in the core's order;
    // the archer's bow first, then any bow when the format does not have it.
    foreach ([$div, null] as $bow) {
        $first = null;
        foreach ($cats as $c) {
            if ($bow !== null && $c['div'] !== $bow) continue;
            if (!$fits($c)) continue;
            if ($c['cls'] === (string)$archer->ArClass && ($bow === null || $c['div'] === $bow)) return $c['div'] . '|' . $c['cls'];
            if ($first === null) $first = $c;
        }
        if ($first !== null) return $first['div'] . '|' . $first['cls'];
    }
    foreach ($cats as $c) {
        if ($c['div'] === $div) return $c['div'] . '|' . $c['cls'];
    }
    return $cats[0]['div'] . '|' . $cats[0]['cls'];
}

/**
 * Club of an archer in the competition, created when missing (as PopEdit.php does).
 *
 * @param int $tourId
 * @param string $code
 * @param string $name
 * @return int CoId.
 */
function trn_club_id($tourId, $code, $name) {
    $tourId = intval($tourId);
    $code = mb_strtoupper(trim((string)$code));
    if ($code === '') $code = 'TRN';
    $rs = safe_r_sql("SELECT CoId FROM Countries WHERE CoTournament = $tourId AND CoCode = " . StrSafe_DB($code));
    if ($r = safe_fetch($rs)) return intval($r->CoId);
    $name = trim((string)$name) !== '' ? trim((string)$name) : $code;
    safe_w_sql("INSERT INTO Countries SET CoTournament = $tourId, CoCode = " . StrSafe_DB($code)
        . ", CoName = " . StrSafe_DB(mb_substr($name, 0, 30))
        . ", CoNameComplete = " . StrSafe_DB(mb_substr($name, 0, 80)));
    return intval(safe_w_last_id());
}

/**
 * Enter a regular archer in the open competition.
 *
 * @param int $tourId Must be the open competition.
 * @param object $archer TrainingArchers row.
 * @param string $div
 * @param string $cls
 * @param int $session Qualification session (SesOrder).
 * @return int EnId, 0 if the category does not exist in the competition.
 */
function trn_add_entry($tourId, $archer, $div, $cls, $session) {
    // getTargets() reads the open competition: one list per competition, never one for the process.
    static $targets = [];
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT (DivAthlete AND ClAthlete) AS Athlete
        FROM Divisions INNER JOIN Classes ON ClTournament = DivTournament
        WHERE DivTournament = $tourId AND DivId = " . StrSafe_DB($div) . " AND ClId = " . StrSafe_DB($cls));
    $r = safe_fetch($rs);
    if (!$r) return 0;

    if (!isset($targets[$tourId])) {
        require_once 'Partecipants/Fun_Targets.php';
        $targets[$tourId] = getTargets(true);
    }
    $face = !empty($targets[$tourId][$div][$cls]) ? intval(array_key_first($targets[$tourId][$div][$cls])) : 0;
    // Yes to the individual events; yes to a team event only once the coach puts the archer in a
    // team (lib/teams.php).

    $ioc = (string)$archer->ArIocCode;
    if ($ioc === '') {
        $t = safe_fetch(safe_r_sql("SELECT ToIocCode FROM Tournament WHERE ToId = $tourId"));
        $ioc = $t ? (string)$t->ToIocCode : '';
    }
    $now = date('Y-m-d H:i:s');
    safe_w_sql("INSERT INTO Entries SET EnTournament = $tourId,
        EnCode = " . StrSafe_DB($archer->ArCode) . ",
        EnIocCode = " . StrSafe_DB($ioc) . ",
        EnFirstName = " . StrSafe_DB(mb_substr($archer->ArFamilyName, 0, 30)) . ",
        EnName = " . StrSafe_DB(mb_substr($archer->ArGivenName, 0, 30)) . ",
        EnSex = " . intval($archer->ArSex) . ",
        EnDob = " . StrSafe_DB(trn_is_date($archer->ArDob) ? $archer->ArDob : '0000-00-00') . ",
        EnDivision = " . StrSafe_DB($div) . ",
        EnClass = " . StrSafe_DB($cls) . ",
        EnAgeClass = " . StrSafe_DB($cls) . ",
        EnSubClass = '',
        EnAthlete = " . (intval($r->Athlete) ? 1 : 0) . ",
        EnCountry = " . trn_club_id($tourId, $archer->ArClubCode, $archer->ArClubName) . ",
        EnTargetFace = $face,
        EnStatus = 0,
        EnIndClEvent = 1, EnIndFEvent = 1, EnTeamClEvent = 0, EnTeamFEvent = 0, EnTeamMixEvent = 0,
        EnTimestamp = '$now', EnMainInfoUpdate = '$now'");
    $enId = intval(safe_w_last_id());
    if (!$enId) return 0;
    safe_w_sql("INSERT INTO Qualifications SET QuId = $enId, QuSession = " . intval($session) . ", QuTarget = 0, QuLetter = ''");
    return $enId;
}

/**
 * Change the category of an archer of the open competition.
 *
 * @param int $tourId
 * @param int $enId
 * @param string $div
 * @param string $cls
 */
function trn_set_category($tourId, $enId, $div, $cls) {
    $tourId = intval($tourId);
    $ok = false;
    foreach (trn_tour_categories($tourId) as $c) {
        if ($c['div'] === $div && $c['cls'] === $cls) $ok = true;
    }
    if (!$ok) return;
    require_once 'Partecipants/Fun_Targets.php';
    $targets = getTargets(true);
    $face = !empty($targets[$div][$cls]) ? intval(array_key_first($targets[$div][$cls])) : 0;
    safe_w_sql("UPDATE Entries SET EnDivision = " . StrSafe_DB($div) . ", EnClass = " . StrSafe_DB($cls)
        . ", EnAgeClass = " . StrSafe_DB($cls) . ", EnTargetFace = $face, EnMainInfoUpdate = '" . date('Y-m-d H:i:s') . "'
        WHERE EnId = " . intval($enId) . " AND EnTournament = $tourId");
}

/**
 * Take an archer out of the open competition, by the core's own function.
 *
 * @param int $tourId
 * @param int $enId
 */
function trn_remove_entry($tourId, $enId) {
    $rs = safe_r_sql("SELECT EnId FROM Entries WHERE EnId = " . intval($enId) . " AND EnTournament = " . intval($tourId));
    if (!safe_fetch($rs)) return;
    deleteArcher(intval($enId));
    safe_w_sql("DELETE FROM TrainingGhosts WHERE GhEntry = " . intval($enId));
    safe_w_sql("DELETE FROM TrainingSimDuels WHERE SdTournament = " . intval($tourId) . " AND SdTeam = 0 AND SdWho = " . intval($enId));
}

/**
 * The archers of the open competition.
 *
 * @param int $tourId
 * @return array EnId => row.
 */
function trn_entries($tourId) {
    $out = [];
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnSex, EnDivision, EnClass, EnCountry2, EnCountry3,
            QuSession, QuTarget, QuLetter, QuScore, CoCode, CoName,
            (SELECT GhKind FROM TrainingGhosts WHERE GhEntry = EnId) AS GhostKind
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        WHERE EnTournament = " . intval($tourId) . "
        ORDER BY EnFirstName, EnName, EnId");
    while ($r = safe_fetch($rs)) $out[intval($r->EnId)] = $r;
    return $out;
}

/**
 * Qualification sessions of the open competition.
 *
 * @param int $tourId
 * @return array SesOrder => row (SesName, SesTar4Session, SesAth4Target, SesFirstTarget).
 */
function trn_sessions($tourId) {
    $out = [];
    $rs = safe_r_sql("SELECT SesOrder, SesName, SesTar4Session, SesAth4Target, SesFirstTarget
        FROM Session WHERE SesTournament = " . intval($tourId) . " AND SesType = 'Q' ORDER BY SesOrder");
    while ($r = safe_fetch($rs)) $out[intval($r->SesOrder)] = $r;
    return $out;
}

/**
 * Bring the core's rankings up to date after archers came in or went out, or
 * after scores changed.
 *
 * @param int $tourId Must be the open competition.
 */
function trn_recalc($tourId) {
    require_once 'Qualification/Fun_Qualification.local.inc.php';
    require_once 'Common/Lib/Obj_RankFactory.php';
    require_once 'Common/Fun_Sessions.inc.php';
    $tourId = intval($tourId);
    $t = safe_fetch(safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId"));
    $nDist = intval($t ? $t->ToNumDist : 0);
    $rs = safe_r_sql("SELECT DISTINCT CONCAT(EnDivision, EnClass) AS Cat FROM Entries WHERE EnTournament = $tourId");
    while ($r = safe_fetch($rs)) {
        for ($i = 0; $i <= $nDist; $i++) CalcQualRank($i, $r->Cat);
    }
    MakeIndAbs();
    MakeTeamsAbs();

    // MakeIndAbs() ranks again only the events whose archers changed: scores alone move
    // nothing. Every event is ranked again, as ISK does after each score
    // (CalculateRanks() in Api/ISK-NG/Lib.php). The shoot-off flags and final ranks are
    // reset as the core does, but only where the bracket is not launched yet: a running
    // bracket is never undone by a late score.
    $events = [0 => [], 1 => []];
    $launched = [];
    $rs = safe_r_sql("SELECT EvCode, EvTeamEvent, EvShootOff FROM Events WHERE EvTournament = $tourId AND EvCodeParent = ''");
    while ($r = safe_fetch($rs)) {
        $events[intval($r->EvTeamEvent)][] = (string)$r->EvCode;
        if (intval($r->EvShootOff)) $launched[intval($r->EvTeamEvent) . '|' . $r->EvCode] = true;
    }
    if ($events[0]) {
        for ($i = 1; $i <= $nDist; $i++) {
            Obj_RankFactory::create('Abs', ['tournament' => $tourId, 'events' => $events[0], 'dist' => $i])->calculate();
        }
        $abs = Obj_RankFactory::create('Abs', ['tournament' => $tourId, 'events' => $events[0], 'dist' => 0]);
        if ($abs->calculate()) {
            foreach ($abs->getEventsRankChanged() as $e) {
                if (!isset($launched['0|' . $e])) ResetShootoff($e, 0, 0, $tourId);
            }
        }
    }
    if ($events[1]) {
        $abs = Obj_RankFactory::create('AbsTeam', ['tournament' => $tourId, 'events' => $events[1]]);
        if ($abs->calculate()) {
            foreach ($abs->getEventsRankChanged() as $e) {
                if (!isset($launched['1|' . $e])) ResetShootoff($e, 1, 0, $tourId);
            }
        }
    }
}

/**
 * Place on the targets every archer of a session who has no target yet, with
 * the rules of lib/placement.php. Archers already placed keep their place.
 * Targets are never added: when they run short, the archers left are counted
 * and the dashboard says how many targets the session would need.
 *
 * @param int $tourId
 * @param int $session SesOrder.
 * @return array ['placed' => N, 'left' => M].
 */
function trn_place_session($tourId, $session) {
    $in = trn_session_plan_input($tourId, $session);
    if (!$in || !$in['todo']) return ['placed' => 0, 'left' => 0];
    $plan = trn_plan_session($in['disc'], $in['rhythm'], $in['targets'], $in['fixed'], $in['todo']);
    foreach ($plan['places'] as $id => $p) {
        // bytes: target numbers and letters are ASCII.
        $no = intval($session) . str_pad((string)$p[0], 3, '0', STR_PAD_LEFT) . $p[1];
        safe_w_sql("UPDATE Qualifications INNER JOIN Entries ON EnId = QuId AND EnTournament = " . intval($tourId) . "
            SET QuTarget = " . intval($p[0]) . ", QuLetter = " . StrSafe_DB($p[1]) . ", QuTargetNo = " . StrSafe_DB($no) . "
            WHERE QuId = " . intval($id));
    }
    return ['placed' => count($plan['places']), 'left' => count($plan['left'])];
}

/**
 * What the planner needs about a session: discipline, rhythm, targets, archers
 * already placed (by target) and archers to place.
 *
 * @param int $tourId
 * @param int $session
 * @return array|null
 */
function trn_session_plan_input($tourId, $session) {
    require_once __DIR__ . '/placement.php';
    $tourId = intval($tourId);
    $ses = trn_sessions($tourId)[intval($session)] ?? null;
    if (!$ses) return null;
    $tour = safe_fetch(safe_r_sql("SELECT ToType, ToNumDist FROM Tournament WHERE ToId = $tourId"));
    $disc = trn_disc($tour->ToType);
    $nDist = max(1, intval($tour->ToNumDist));
    $rhythm = max(1, intval($ses->SesAth4Target));
    $first = max(1, intval($ses->SesFirstTarget));
    $targets = range($first, $first + max(1, intval($ses->SesTar4Session)) - 1);

    $faces = [];
    $rs = safe_r_sql("SELECT * FROM TargetFaces WHERE TfTournament = $tourId");
    while ($r = safe_fetch($rs)) {
        $fams = [];
        for ($i = 1; $i <= $nDist; $i++) {
            $t = intval($r->{'TfT' . $i} ?? 0);
            $fams[] = ($i > 1 && $t === 0) ? $fams[$i - 2] : trn_face_family($disc, $r->TfName, $t, $r->{'TfW' . $i} ?? 0);
        }
        $faces[intval($r->TfId)] = $fams;
    }

    $fixed = [];
    $todo = [];
    $rs = safe_r_sql("SELECT EnId, EnDivision, EnClass, EnTargetFace, EnFirstName, EnName, QuTarget, QuLetter,
            DivViewOrder, ClSex, ClAgeFrom, ClAgeTo
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Divisions ON DivTournament = EnTournament AND DivId = EnDivision
        LEFT JOIN Classes ON ClTournament = EnTournament AND ClId = EnClass
        WHERE EnTournament = $tourId AND QuSession = " . intval($session) . " AND " . trn_not_ghost_sql($tourId));
    while ($r = safe_fetch($rs)) {
        $a = [
            'id'       => intval($r->EnId),
            'dist'     => trn_category_distances($tourId, $tour->ToType, $r->EnDivision, $r->EnClass, $nDist),
            'fams'     => $faces[intval($r->EnTargetFace)] ?? array_fill(0, $nDist, $disc === 'other' ? '*' : '?'),
            'div'      => (string)$r->EnDivision,
            'divOrder' => intval($r->DivViewOrder),
            // A class with no age range (discovery: 1 to 127) has no age, as in AUTH.
            'age'      => (intval($r->ClAgeFrom) <= 1 && intval($r->ClAgeTo) >= 99) ? null : intval($r->ClAgeFrom),
            'sex'      => is_null($r->ClSex) ? -1 : intval($r->ClSex),
            'name'     => mb_strtoupper($r->EnFirstName . ' ' . $r->EnName),
            'l'        => null,
        ];
        if (intval($r->QuTarget) > 0 && $r->QuLetter !== '') {
            $a['l'] = (string)$r->QuLetter;
            $fixed[intval($r->QuTarget)][] = $a;
        } else {
            $todo[] = $a;
        }
    }
    return ['disc' => $disc, 'rhythm' => $rhythm, 'targets' => $targets, 'fixed' => $fixed, 'todo' => $todo];
}

/**
 * Change the number of archers per target of a session (A, AB, ABC, AB-CD), as
 * the core's session settings do (Tournament/Fun_ManSessions.inc.php), then
 * place everybody again: letters beyond the new rhythm no longer exist.
 *
 * @param int $tourId
 * @param int $session
 * @param int $rhythm 1 to 4.
 * @return array As trn_place_session().
 */
function trn_set_rhythm($tourId, $session, $rhythm) {
    $tourId = intval($tourId);
    $rhythm = max(1, min(4, intval($rhythm)));
    safe_w_sql("UPDATE Session SET SesAth4Target = $rhythm
        WHERE SesTournament = $tourId AND SesOrder = " . intval($session) . " AND SesType = 'Q'");
    safe_w_sql("UPDATE Entries INNER JOIN Qualifications ON QuId = EnId AND QuSession = " . intval($session) . "
        SET EnMainInfoUpdate = '" . date('Y-m-d H:i:s') . "' WHERE EnTournament = $tourId");
    trn_unplace_session($tourId, $session);
    return trn_place_session($tourId, $session);
}

/**
 * Give a session the number of targets its archers need, as the core's session
 * settings change it, then place everybody again.
 *
 * @param int $tourId
 * @param int $session
 * @param int $targets
 * @return array As trn_place_session().
 */
function trn_set_targets($tourId, $session, $targets) {
    $tourId = intval($tourId);
    safe_w_sql("UPDATE Session SET SesTar4Session = " . max(1, intval($targets)) . "
        WHERE SesTournament = $tourId AND SesOrder = " . intval($session) . " AND SesType = 'Q'");
    safe_w_sql("UPDATE Entries INNER JOIN Qualifications ON QuId = EnId AND QuSession = " . intval($session) . "
        SET EnMainInfoUpdate = '" . date('Y-m-d H:i:s') . "' WHERE EnTournament = $tourId");
    trn_unplace_session($tourId, $session);
    return trn_place_session($tourId, $session);
}

/**
 * How many targets the session would need to place all its archers from
 * scratch, with its present rhythm and the rules of lib/placement.php.
 *
 * @param int $tourId
 * @param int $session
 * @return array ['have' => targets of the session, 'need' => targets needed (0: none can do)]
 */
function trn_targets_needed($tourId, $session) {
    $in = trn_session_plan_input($tourId, $session);
    if (!$in) return ['have' => 0, 'need' => 0];
    $all = $in['todo'];
    foreach ($in['fixed'] as $list) foreach ($list as $a) { $a['l'] = null; $all[] = $a; }
    $have = count($in['targets']);
    $first = reset($in['targets']);
    $start = max(1, (int)ceil(count($all) / $in['rhythm']));
    for ($k = $start; $k <= max($start, count($all)); $k++) {
        $plan = trn_plan_session($in['disc'], $in['rhythm'], range($first, $first + $k - 1), [], $all);
        if (!$plan['left']) return ['have' => $have, 'need' => $k];
    }
    return ['have' => $have, 'need' => 0];
}

/**
 * Remove every target of a session, so that it can be placed again.
 *
 * @param int $tourId
 * @param int $session
 */
function trn_unplace_session($tourId, $session) {
    safe_w_sql("UPDATE Qualifications INNER JOIN Entries ON EnId = QuId AND EnTournament = " . intval($tourId) . "
        SET QuTarget = 0, QuLetter = '', QuTargetNo = ''
        WHERE QuSession = " . intval($session));
}

/**
 * Duels only: give every archer of the open competition one point, so that
 * every one of them is ranked and every team is formed; the order of the
 * brackets is then chosen on the ranking page.
 *
 * @param int $tourId Must be the open competition.
 */
function trn_simulate_qualification($tourId) {
    $tourId = intval($tourId);
    safe_w_sql("UPDATE Qualifications INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
        SET QuD1Score = 1, QuD1Hits = 1, QuScore = 1, QuHits = 1, QuTimestamp = QuTimestamp
        WHERE EnAthlete = 1");
    trn_recalc($tourId);
}
