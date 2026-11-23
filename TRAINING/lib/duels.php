<?php
/**
 * From the ranking to the brackets, without the core's shoot-off screen.
 *
 * The coach puts the archers (or teams) of an event in the order of the
 * bracket — the qualification order, ties settled by hand, or any order when
 * the qualification round is simulated — and the brackets are filled exactly
 * as Final/Individual/AbsIndividual.php and Final/Team/AbsTeam.php fill them
 * once every shoot-off is solved: the rank is written by the core's Rank
 * classes (setRow), the grid is recreated by the ruleset's CreateFinals
 * function, byes are moved by move2NextPhase(), final ranks recalculated.
 *
 * Only events whose brackets start straight after the qualification
 * (EvElimType 0) are handled here; eliminations, pools and round robins keep
 * the core's screens.
 */

/**
 * Load the core functions the brackets need.
 */
function trn_duels_require() {
    require_once 'Common/Fun_FormatText.inc.php';
    require_once 'Common/Lib/ArrTargets.inc.php';
    require_once 'Common/Lib/Obj_RankFactory.php';
    require_once 'Common/Fun_Phases.inc.php';
    require_once 'Common/Fun_Sessions.inc.php';
    require_once 'Final/Fun_ChangePhase.inc.php';
    require_once 'Modules/Sets/lib.php';
}

/**
 * Events of the open competition that have duels.
 *
 * @param int $tourId
 * @return array List of rows (EvCode, EvEventName, EvTeamEvent, EvElimType, EvNumQualified,
 *               EvFirstQualified, EvShootOff, EvFinalFirstPhase).
 */
function trn_duel_events($tourId) {
    $out = [];
    $rs = safe_r_sql("SELECT EvCode, EvEventName, EvTeamEvent, EvMixedTeam, EvElimType, EvNumQualified, EvFirstQualified,
            EvShootOff, EvFinalFirstPhase
        FROM Events
        WHERE EvTournament = " . intval($tourId) . " AND EvFinalFirstPhase > 0 AND EvCodeParent = ''
        ORDER BY EvTeamEvent, EvProgr, EvCode");
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * Archers of an individual event in their present order: ranked first, by
 * rank, then by score.
 *
 * @param int $tourId
 * @param string $event
 * @return array List of ['key' => EnId, 'label', 'score', 'rank'].
 */
function trn_duel_list_ind($tourId, $event) {
    $out = [];
    $rs = safe_r_sql("SELECT EnId, EnFirstName, EnName, CoCode, IndRank, QuScore
        FROM Individuals
        INNER JOIN Entries ON EnId = IndId AND EnTournament = IndTournament
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        WHERE IndTournament = " . intval($tourId) . " AND IndEvent = " . StrSafe_DB($event) . " AND EnStatus <= 1
        ORDER BY IndRank = 0, IndRank, QuScore DESC, EnFirstName, EnName");
    while ($r = safe_fetch($rs)) {
        $out[] = ['key' => (string)$r->EnId, 'label' => trim($r->EnFirstName . ' ' . $r->EnName),
                  'club' => (string)$r->CoCode, 'score' => intval($r->QuScore), 'rank' => intval($r->IndRank)];
    }
    return $out;
}

/**
 * Teams of a team event in their present order, with their archers.
 *
 * @param int $tourId
 * @param string $event
 * @return array List of ['key' => "CoId_SubTeam", 'label', 'score', 'rank', 'club' => archers].
 */
function trn_duel_list_team($tourId, $event) {
    $tourId = intval($tourId);
    $names = [];
    $rs = safe_r_sql("SELECT TcCoId, TcSubTeam, EnFirstName, EnName
        FROM TeamComponent INNER JOIN Entries ON EnId = TcId
        WHERE TcTournament = $tourId AND TcEvent = " . StrSafe_DB($event) . " AND TcFinEvent = 1
        ORDER BY TcOrder");
    while ($r = safe_fetch($rs)) $names[$r->TcCoId . '_' . $r->TcSubTeam][] = trim($r->EnFirstName . ' ' . $r->EnName);

    $out = [];
    $rs = safe_r_sql("SELECT TeCoId, TeSubTeam, TeRank, TeScore, CoName
        FROM Teams LEFT JOIN Countries ON CoId = TeCoId AND CoTournament = TeTournament
        WHERE TeTournament = $tourId AND TeEvent = " . StrSafe_DB($event) . " AND TeFinEvent = 1
        ORDER BY TeRank = 0, TeRank, TeScore DESC, CoName");
    while ($r = safe_fetch($rs)) {
        $key = $r->TeCoId . '_' . $r->TeSubTeam;
        $out[] = ['key' => $key, 'label' => (string)$r->CoName . (intval($r->TeSubTeam) > 1 ? ' ' . $r->TeSubTeam : ''),
                  'club' => implode(', ', $names[$key] ?? []), 'score' => intval($r->TeScore), 'rank' => intval($r->TeRank)];
    }
    return $out;
}

/**
 * Name of the ruleset's function that recreates the grids of some events.
 *
 * @param bool $team
 * @return string
 */
function trn_create_finals_function($team) {
    global $CFG;
    $base = $team ? 'CreateFinalsTeam' : 'CreateFinalsInd';
    $rule = (string)($_SESSION['TourLocRule'] ?? '');
    if ($rule !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $rule)
        && file_exists($CFG->DOCUMENT_PATH . 'Modules/Sets/' . $rule . '/lib.php')) {
        require_once 'Modules/Sets/' . $rule . '/lib.php';
        $f = $base . '_' . $rule . '_' . ($_SESSION['TourType'] ?? '') . '_' . ($_SESSION['TourLocSubRule'] ?? '');
        if (function_exists($f)) return $f;
    }
    return $base;
}

/**
 * Rank the archers of an individual event in the given order and fill its bracket.
 *
 * @param int $tourId Must be the open competition.
 * @param string $event
 * @param string[] $order EnIds, best first.
 * @return bool False if the event has no direct bracket or the order is not the event's archers.
 */
function trn_duel_launch_ind($tourId, $event, $order) {
    trn_duels_require();
    $tourId = intval($tourId);
    $ev = safe_fetch(safe_r_sql("SELECT EvFinalFirstPhase, EvElimType FROM Events
        WHERE EvTournament = $tourId AND EvTeamEvent = 0 AND EvCode = " . StrSafe_DB($event)));
    if (!$ev || intval($ev->EvFinalFirstPhase) <= 0 || intval($ev->EvElimType) !== 0) return false;

    $known = [];
    foreach (trn_duel_list_ind($tourId, $event) as $a) $known[$a['key']] = true;
    $order = array_values(array_filter(array_map('strval', (array)$order), function ($k) use ($known) { return isset($known[$k]); }));
    if (count($order) !== count($known)) return false;

    $rank = Obj_RankFactory::create('Abs', ['tournament' => $tourId, 'events' => [$event], 'dist' => 0]);
    foreach ($order as $i => $enId) {
        $rank->setRow([['ath' => intval($enId), 'event' => $event, 'dist' => 0, 'rank' => $i + 1]]);
    }

    $ev2 = StrSafe_DB($event);
    safe_w_sql("DELETE FROM Finals WHERE FinTournament = $tourId AND FinEvent = $ev2");
    $f = trn_create_finals_function(false);
    $f($tourId, $ev2);

    $rs = safe_r_sql("SELECT IndId, IndTournament, IndEvent, GrMatchNo
        FROM Individuals
        INNER JOIN Events ON IndTournament = EvTournament AND IndEvent = EvCode AND EvTeamEvent = 0
        INNER JOIN Phases ON PhId = EvFinalFirstPhase AND (PhIndTeam & 1) = 1
        INNER JOIN Grids ON GrPhase = GREATEST(PhId, PhLevel)
            AND (IndRank - EvFirstQualified + 1) = IF(EvFinalFirstPhase = 48, GrPosition2, IF(GrPosition > EvNumQualified, 0, GrPosition))
        WHERE IndRank BETWEEN EvFirstQualified AND (EvNumQualified + EvFirstQualified - 1)
          AND IndTournament = $tourId AND EvCode = $ev2
        ORDER BY IndRank, GrMatchNo");
    $now = date('Y-m-d H:i:s');
    while ($r = safe_fetch($rs)) {
        safe_w_sql("UPDATE Finals SET FinAthlete = " . intval($r->IndId) . ", FinDateTime = '$now'
            WHERE FinTournament = $tourId AND FinEvent = $ev2 AND FinMatchNo = " . intval($r->GrMatchNo));
        if (safe_w_affected_rows()) {
            runJack('BracketUpdate', $tourId, ['Event' => $event, 'Team' => 0, 'MatchNo' => $r->GrMatchNo, 'TourId' => $tourId]);
        }
    }

    safe_w_sql("UPDATE Events SET EvShootOff = 1 WHERE EvTournament = $tourId AND EvTeamEvent = 0 AND EvCode = $ev2");
    set_qual_session_flags();
    move2NextPhase(valueFirstPhase($ev->EvFinalFirstPhase), $event, null);
    Obj_RankFactory::create('FinalInd', ['eventsC' => [$event . '@-3']])->calculate();
    return true;
}

/**
 * Rank the teams of a team event in the given order and fill its bracket.
 *
 * @param int $tourId Must be the open competition.
 * @param string $event
 * @param string[] $order "CoId_SubTeam", best first.
 * @return bool
 */
function trn_duel_launch_team($tourId, $event, $order) {
    trn_duels_require();
    $tourId = intval($tourId);
    $ev = safe_fetch(safe_r_sql("SELECT EvFinalFirstPhase, EvElimType FROM Events
        WHERE EvTournament = $tourId AND EvTeamEvent = 1 AND EvCode = " . StrSafe_DB($event)));
    if (!$ev || intval($ev->EvFinalFirstPhase) <= 0 || intval($ev->EvElimType) !== 0) return false;

    $known = [];
    foreach (trn_duel_list_team($tourId, $event) as $a) $known[$a['key']] = true;
    $order = array_values(array_filter(array_map('strval', (array)$order), function ($k) use ($known) { return isset($known[$k]); }));
    if (!$order || count($order) !== count($known)) return false;

    $rank = Obj_RankFactory::create('AbsTeam', ['tournament' => $tourId, 'events' => [$event]]);
    foreach ($order as $i => $key) {
        list($co, $sub) = array_map('intval', explode('_', $key));
        $rank->setRow([['team' => $co, 'subteam' => $sub, 'event' => $event, 'rank' => $i + 1]]);
    }

    $ev2 = StrSafe_DB($event);
    safe_w_sql("DELETE FROM TeamFinals WHERE TfTournament = $tourId AND TfEvent = $ev2");
    safe_w_sql("DELETE FROM TeamFinComponent WHERE TfcTournament = $tourId AND TfcEvent = $ev2");
    $f = trn_create_finals_function(true);
    $f($tourId, $ev2);

    $now = date('Y-m-d H:i:s');
    $rs = safe_r_sql("SELECT TeCoId, TeSubTeam, GrMatchNo
        FROM Teams
        INNER JOIN Events ON TeTournament = EvTournament AND TeEvent = EvCode AND EvTeamEvent = 1
        INNER JOIN Phases ON PhId = EvFinalFirstPhase AND (PhIndTeam & 2) > 0
        INNER JOIN Grids ON GrPhase = GREATEST(PhId, PhLevel)
            AND (TeRank - EvFirstQualified + 1) = IF(EvFinalFirstPhase = 48, GrPosition2, IF(GrPosition > EvNumQualified, 0, GrPosition))
        WHERE TeRank BETWEEN EvFirstQualified AND (EvNumQualified + EvFirstQualified - 1)
          AND TeTournament = $tourId AND TeFinEvent = 1 AND EvCode = $ev2
        ORDER BY TeRank, GrMatchNo");
    while ($r = safe_fetch($rs)) {
        safe_w_sql("UPDATE TeamFinals SET TfTeam = " . intval($r->TeCoId) . ", TfSubTeam = " . intval($r->TeSubTeam) . ", TfDateTime = '$now'
            WHERE TfTournament = $tourId AND TfEvent = $ev2 AND TfMatchNo = " . intval($r->GrMatchNo));
        if (safe_w_affected_rows()) {
            runJack('BracketUpdate', $tourId, ['Event' => $event, 'Team' => 1, 'MatchNo' => $r->GrMatchNo, 'TourId' => $tourId]);
        }
    }

    safe_w_sql("INSERT INTO TeamFinComponent (TfcCoId, TfcSubTeam, TfcTournament, TfcEvent, TfcId, TfcOrder, TfcTimeStamp)
        SELECT TcCoId, TcSubTeam, TcTournament, TcEvent, TcId, TcOrder, '$now'
        FROM TeamComponent
        INNER JOIN Teams ON TeCoId = TcCoId AND TeSubTeam = TcSubTeam AND TeEvent = TcEvent AND TeTournament = TcTournament AND TeFinEvent = TcFinEvent
        INNER JOIN Events ON TeTournament = EvTournament AND TeEvent = EvCode AND EvTeamEvent = 1
        WHERE (TeRank BETWEEN EvFirstQualified AND (EvNumQualified + EvFirstQualified - 1))
          AND TcFinEvent = 1 AND TcTournament = $tourId AND TcEvent = $ev2");

    safe_w_sql("UPDATE Events SET EvShootOff = 1 WHERE EvTournament = $tourId AND EvTeamEvent = 1 AND EvCode = $ev2");
    set_qual_session_flags();
    move2NextPhaseTeam(valueFirstPhase($ev->EvFinalFirstPhase), $event, null);
    Obj_RankFactory::create('FinalTeam', ['eventsC' => [$event . '@-3']])->calculate();
    return true;
}
