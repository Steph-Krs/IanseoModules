<?php
/**
 * Duels of the scorecard reader: the rounds scheduled (individual and team brackets, round
 * robin), each round's matches as their scorecards print them (Final/Individual and
 * Final/Team PDFScoreMatch.php, Modules/RoundRobin/PdfScorecards.php: one sheet a match, its
 * two cards side by side), and their arrows written into ianseo with the core's own
 * functions, the match then confirmed as the core's barcode check does
 * (Modules/Barcodes/GetFinScoreBarCode.php, GetRobinScoreBarCode.php).
 *
 * The matches are read through the core's Rank classes (GridInd, GridTeam, Robin), the
 * queries the printouts use: nothing of them is written again here.
 */

require_once 'Common/Lib/CommonLib.php';
require_once 'Common/Lib/Obj_RankFactory.php';
require_once 'Common/Lib/Fun_Phases.inc.php';
require_once 'Common/Lib/ArrTargets.inc.php';

/**
 * Whether the visitor may see (AclReadOnly) or enter (AclReadWrite) some kind of match:
 * individual or team brackets, or round robin.
 *
 * @param int $level
 * @return bool
 */
function ocr_duel_access($level = AclReadOnly) {
    if (!CheckTourSession()) return false;
    foreach ([AclIndividuals, AclTeams, AclRobin] as $acl) {
        if (hasFullACL($acl, '', $level)) return true;
    }
    return false;
}

/**
 * The right a match asks for, and the core's lock of its kind.
 *
 * @param array $m A match (ocr_duel_normal()).
 * @return array [ACL feature, block bit]
 */
function ocr_duel_rights($m) {
    if ($m['robin']) return [AclRobin, BIT_BLOCK_ROBIN];
    return $m['team'] ? [AclTeams, BIT_BLOCK_TEAM] : [AclIndividuals, BIT_BLOCK_IND];
}

/**
 * The rounds scheduled, as the core lists them for its scorecard printouts: brackets
 * (individual, team) and round robin, never both at once in a competition.
 *
 * @param bool $today Only those of today.
 * @param bool $unfinished Only those not over.
 * @return array [{key, label}]
 */
function ocr_duel_tours($today, $unfinished) {
    $types = [];
    if (hasFullACL(AclIndividuals, '', AclReadOnly)) $types[] = 'I';
    if (hasFullACL(AclTeams, '', AclReadOnly)) $types[] = 'T';
    if (hasFullACL(AclRobin, '', AclReadOnly)) $types[] = 'R';
    if (!$types) return [];
    $out = [];
    foreach (getApiScheduledSessions(['Type' => $types, 'OnlyToday' => $today, 'Unfinished' => $unfinished]) as $key => $s) {
        if (ocr_duel_tour_parse((string)$key)) $out[] = ['key' => (string)$key, 'label' => (string)$s->Description];
    }
    return $out;
}

/**
 * A round's key as getApiScheduledSessions() makes it: I, T (brackets) or IR, TR (round
 * robin), then the date and the time run together.
 *
 * @param string $key
 * @return array|null {robin, team, when: 'YYYY-MM-DD HH:MM:SS'}
 */
function ocr_duel_tour_parse($key) {
    if (!preg_match('/^(I|T|IR|TR)(\d{4}-\d{2}-\d{2})(\d{2}:\d{2}:\d{2})$/', $key, $m)) return null;
    // bytes: the kind is one or two ASCII letters.
    return ['robin' => strlen($m[1]) === 2, 'team' => $m[1][0] === 'T' ? 1 : 0, 'when' => $m[2] . ' ' . $m[3]];
}

/**
 * The matches of a round, with an archer (or a team) on one side at least.
 *
 * @param string $key A round's key (ocr_duel_tours()).
 * @return array|null Null for a key that is not one.
 */
function ocr_duel_matches($key) {
    $t = ocr_duel_tour_parse($key);
    if (!$t) return null;
    $opts = ['tournament' => (int)$_SESSION['TourId'], 'schedule' => $t['when'], 'dist' => 0];
    if ($t['robin']) $opts['team'] = $t['team'];
    $family = $t['robin'] ? 'Robin' : ($t['team'] ? 'GridTeam' : 'GridInd');
    return ocr_duel_query($family, $opts, $t['team']);
}

/**
 * One match again (after a writing), from its id (ocr_duel_normal()).
 *
 * @param string $id
 * @return array|null
 */
function ocr_duel_match($id) {
    $p = explode('|', (string)$id);
    if ($p[0] === 'F' && count($p) === 4) {
        $team = (int)$p[1];
        $list = ocr_duel_query($team ? 'GridTeam' : 'GridInd',
            ['tournament' => (int)$_SESSION['TourId'], 'events' => [$p[2]], 'matchno' => (int)$p[3], 'dist' => 0], $team);
    } elseif ($p[0] === 'R' && count($p) === 7) {
        $list = ocr_duel_query('Robin', ['team' => (int)$p[1], 'events' => [$p[2]], 'levels' => [(int)$p[3]], 'groups' => [(int)$p[4]],
            'rounds' => [(int)$p[5]], 'matchno' => (int)$p[6]], (int)$p[1]);
    } else {
        return null;
    }
    foreach ($list as $m) {
        if ($m['id'] === $id) return $m;
    }
    return null;
}

/**
 * Matches through a Rank class of the core, as the duel page takes them.
 *
 * @param string $family GridInd, GridTeam or Robin.
 * @param array $opts The class's options.
 * @param int $team
 * @return array
 */
function ocr_duel_query($family, $opts, $team) {
    $rank = Obj_RankFactory::create($family, $opts);
    $rs = safe_r_sql($rank->getQuery(true));
    $out = [];
    while ($r = safe_fetch($rs)) {
        $m = $family === 'Robin' ? ocr_duel_robin($r) : ocr_duel_final($r, $team);
        if ($m) $out[] = $m;
    }
    safe_free_result($rs);
    return $out;
}

/**
 * A bracket's match (a GridInd or GridTeam row: the match and its opponent), as its sheet
 * prints it.
 */
function ocr_duel_final($r, $team) {
    $pa = getEventArrowsParams($r->Event, $r->Phase, $team);
    if (!$pa->ends || !$pa->arrows) return null;
    $phase = get_text(namePhase($r->EvFinalFirstPhase, $r->Phase) . '_Phase');
    $pools = (int)$r->EvElimType === 3 ? getPoolMatchesShort() : ((int)$r->EvElimType === 4 ? getPoolMatchesShortWA() : []);
    if (isset($pools[$r->MatchNo])) $phase = $pools[$r->MatchNo];
    $golds = !empty($r->EvCheckGolds);
    $xnine = !empty($r->EvCheckXNines);
    // As the printouts draw them: a team card 18 units wide (its arrows 10), an individual
    // card 15 (6), one more for each count column; the shoot-off on 3 lines, 1 on a card of
    // an event with eliminations (individual only).
    $spec = [
        'mode' => (int)$r->EvMatchMode, 'ends' => (int)$pa->ends, 'arrows' => (int)$pa->arrows, 'so' => max(1, (int)$pa->so),
        'soRows' => $team || !(int)$r->EvElimType ? 3 : 1, 'soTotal' => (int)$pa->so > 1,
        'golds' => $golds, 'xnine' => $xnine, 'team' => (bool)$team, 'robin' => false,
        'units' => ($team ? 18 : 15) + ($golds ? 1 : 0) + ($xnine ? 1 : 0),
        'people' => $team ? max(1, (int)($r->EvMaxTeamPerson ?? 3)) : 1,
    ];
    $sides = [];
    foreach (['', 'Opp'] as $p) {
        $name = $team ? (string)$r->{$p . 'CountryName'} : (string)$r->{$p . 'Athlete'};
        $sides[] = [
            'matchNo'  => (int)$r->{$p . 'MatchNo'},
            'present'  => $team ? (int)$r->{$p . 'Team'} > 0 : (int)$r->{$p . 'EnId'} > 0,
            'name'     => $name,
            'club'     => $team ? '' : trim((string)$r->{$p . 'CountryName'} . ((string)$r->{$p . 'CountryCode'} !== '' ? ' (' . $r->{$p . 'CountryCode'} . ')' : '')),
            'clubCode' => (string)$r->{$p . 'CountryCode'},
            'target'   => ltrim((string)$r->{$p . 'Target'}, '0'),
            'rank'     => (string)$r->{$p . 'QualRank'},
            'letters'  => (string)($r->{$p . 'ArrowString'} ?? $r->{$p . 'Arrowstring'} ?? ''),
            'tb'       => (string)$r->{$p . 'TieBreak'},
            'closest'  => (int)$r->{$p . 'TieClosest'} !== 0,
            'score'    => (int)$r->{$p . 'Score'},
            'setScore' => (int)$r->{$p . 'SetScore'},
            'tie'      => (int)$r->{$p . 'Tie'},
            'win'      => (int)$r->{$p . 'Winner'} !== 0,
            'confirmed' => (int)$r->{$p . 'Confirmed'} !== 0,
        ];
    }
    return ocr_duel_normal([
        'id' => 'F|' . (int)$team . '|' . $r->Event . '|' . (int)$r->MatchNo,
        'robin' => false, 'team' => (int)$team, 'event' => (string)$r->Event, 'eventName' => (string)$r->EventDescr,
        'phase' => $phase, 'matchNo' => (int)$r->MatchNo,
        'barcode' => (int)$r->MatchNo . '-' . (int)$team . '-' . $r->Event,
        'target' => (int)$r->TarId, 'goldsChars' => (string)$r->EvGoldsChars, 'xnineChars' => (string)$r->EvXNineChars,
    ], $spec, $sides);
}

/** A round robin's match (a Robin row: both sides), as its sheet prints it (left side first). */
function ocr_duel_robin($r) {
    $team = (int)$r->EvTeamEvent;
    $spec = [
        'mode' => (int)$r->RrLevMatchMode, 'ends' => (int)$r->RrLevEnds, 'arrows' => (int)$r->RrLevArrows, 'so' => max(1, (int)$r->RrLevSO),
        'soRows' => 3, 'soTotal' => false, 'golds' => false, 'xnine' => false, 'team' => (bool)$team, 'robin' => true,
        'units' => 15, 'people' => 1,
    ];
    if (!$spec['ends'] || !$spec['arrows']) return null;
    $ev = safe_fetch(safe_r_sql("SELECT EvFinalTargetType, EvGoldsChars, EvXNineChars FROM Events
        WHERE EvTournament = " . (int)$_SESSION['TourId'] . " AND EvTeamEvent = $team AND EvCode = " . StrSafe_DB($r->EvCode)));
    $sides = [];
    foreach ($r->Swapped ? ['2', '1'] : ['1', '2'] as $k) {
        $sides[] = [
            'matchNo'  => (int)$r->{"M{$k}MatchNo"},
            'present'  => (int)$r->{"M{$k}Athlete"} > 0,
            'name'     => (string)$r->{"Athlete{$k}"},
            'club'     => trim((string)$r->{"CoName{$k}"} . ((string)$r->{"CoShort{$k}"} !== '' ? ' (' . $r->{"CoShort{$k}"} . ')' : '')),
            'clubCode' => (string)$r->{"CoShort{$k}"},
            'target'   => ltrim((string)$r->{"M{$k}Target"}, '0'),
            'rank'     => (string)$r->{"Rank{$k}"},
            'letters'  => (string)$r->{"M{$k}Arrowstring"},
            'tb'       => (string)$r->{"M{$k}Tiebreak"},
            'closest'  => (int)$r->{"M{$k}TbClosest"} !== 0,
            'score'    => (int)$r->{"M{$k}Score"},
            'setScore' => (int)$r->{"M{$k}SetScore"},
            'tie'      => (int)$r->{"M{$k}Tie"},
            'win'      => (int)$r->{"M{$k}WinLose"} !== 0,
            'confirmed' => (int)$r->{"M{$k}Confirmed"} !== 0,
        ];
    }
    return ocr_duel_normal([
        'id' => 'R|' . $team . '|' . $r->EvCode . '|' . (int)$r->M1Level . '|' . (int)$r->M1Group . '|' . (int)$r->M1Round . '|' . (int)$r->M1MatchNo,
        'robin' => true, 'team' => $team, 'event' => (string)$r->EvCode, 'eventName' => (string)$r->EvEventName,
        'phase' => trim($r->RrLevName . ' ' . $r->RrGrName . ' ' . get_text('RoundNum', 'RoundRobin', $r->M1Round)),
        'matchNo' => (int)$r->M1MatchNo,
        'level' => (int)$r->M1Level, 'group' => (int)$r->M1Group, 'round' => (int)$r->M1Round,
        'barcode' => implode('-', [(int)$r->M1MatchNo, (int)$r->M1Round, (int)$r->M1Group, (int)$r->M1Level, $team, $r->EvCode]),
        'target' => $ev ? (int)$ev->EvFinalTargetType : 0,
        'goldsChars' => $ev ? (string)$ev->EvGoldsChars : '', 'xnineChars' => $ev ? (string)$ev->EvXNineChars : '',
    ], $spec, $sides);
}

/**
 * A match as the page takes it: its card's layout (spec), the values of its target face,
 * and each side as ianseo holds it, arrow by arrow ('' when not entered). Null when no side
 * has an archer.
 */
function ocr_duel_normal($m, $spec, $sides) {
    global $LetterPoint;
    if (!$sides[0]['present'] && !$sides[1]['present']) return null;
    $values = [];
    foreach ($m['target'] ? GetGoodLettersFromTgtId($m['target']) : [] as $l) {
        if (!isset($LetterPoint[$l])) continue;
        $values[] = [
            'l' => $l, 'p' => $LetterPoint[$l]['P'], 'n' => (int)$LetterPoint[$l]['N'], 'w' => (int)$LetterPoint[$l]['W'],
            'g' => mb_strpos($m['goldsChars'], $l) !== false, 'x' => mb_strpos($m['xnineChars'], $l) !== false,
        ];
    }
    $count = $spec['ends'] * $spec['arrows'];
    $soCount = $spec['soRows'] * $spec['so'];
    foreach ($sides as $i => $s) {
        // bytes: arrowstrings hold one ASCII letter per arrow.
        $sides[$i]['arrows'] = ocr_duel_decode($s['letters'], $count);
        $sides[$i]['tbArrows'] = ocr_duel_decode($s['tb'], $soCount);
        // bytes: as many letters as arrows, spaces for those not entered.
        $sides[$i]['letters'] = str_pad(substr($s['letters'], 0, $count), $count);
        $sides[$i]['tb'] = str_pad(substr($s['tb'], 0, $soCount), $soCount);
    }
    unset($m['target'], $m['goldsChars'], $m['xnineChars']);
    $m['spec'] = $spec;
    $m['values'] = $values;
    $m['sides'] = $sides;
    $m['confirmed'] = $sides[0]['confirmed'] && $sides[1]['confirmed'];
    $m['decided'] = $sides[0]['win'] || $sides[1]['win'];
    return $m;
}

/**
 * Arrows held as printed values, '' where none.
 *
 * @param string $letters An arrowstring (one ASCII letter an arrow).
 * @param int $count
 * @return array
 */
function ocr_duel_decode($letters, $count) {
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        // bytes: an arrowstring holds one ASCII letter per arrow.
        $c = $i < strlen($letters) ? $letters[$i] : ' ';
        $out[] = $c === ' ' ? '' : DecodeFromLetter($c);
    }
    return $out;
}

/**
 * Why a match cannot be written now ('' when it can): the right to enter it, the core's
 * lock of its kind.
 */
function ocr_duel_blocked($m) {
    list($acl, $bit) = ocr_duel_rights($m);
    if (!hasFullACL($acl, '', AclReadWrite)) return ocr_text('ErrAccess');
    if (IsBlocked($bit)) return ocr_text('DuelBlocked');
    return '';
}

/**
 * A reading written into ianseo: each side's arrows, its shoot-off and whether it was the
 * closest to the centre, through the core's function (UpdateArrowString,
 * RobinUpdateArrowString), which works the match out again. A cell ianseo holds otherwise
 * than read is written only when the operator corrected it (edited); otherwise the writing
 * is refused (the operator is told). Nothing is confirmed here.
 *
 * @param array $m The match (ocr_duel_match()).
 * @param array $sides Per side: {letters, tb, closest, edited: [arrow index…], tbEdited: [index…]}.
 * @return string '' when written, else why not.
 */
function ocr_duel_import($m, $sides) {
    if ($m['confirmed']) return ocr_text('DuelConfirmed');
    $spec = $m['spec'];
    $count = $spec['ends'] * $spec['arrows'];
    $soCount = $spec['soRows'] * $spec['so'];
    $allowed = array_column($m['values'], 'l');
    $allowed[] = ' ';
    $plan = [];
    foreach ([0, 1] as $i) {
        $s = $sides[$i] ?? null;
        if (!is_array($s)) return ocr_text('ErrRequest');
        // bytes: arrowstrings hold one ASCII letter per arrow.
        $letters = str_pad(substr((string)($s['letters'] ?? ''), 0, $count), $count);
        $tb = str_pad(substr((string)($s['tb'] ?? ''), 0, $soCount), $soCount);
        foreach (str_split($letters . $tb) as $c) {
            if (!in_array($c, $allowed, true)) return ocr_text('ErrRequest');
        }
        $held = $m['sides'][$i];
        $edited = array_map('intval', (array)($s['edited'] ?? []));
        $tbEdited = array_map('intval', (array)($s['tbEdited'] ?? []));
        for ($k = 0; $k < $count; $k++) {
            if ($held['letters'][$k] !== ' ' && $held['letters'][$k] !== $letters[$k] && !in_array($k, $edited, true)) {
                return ocr_text('DuelHeldOther', ['side' => $held['name'], 'n' => $k + 1]);
            }
        }
        for ($k = 0; $k < $soCount; $k++) {
            if ($held['tb'][$k] !== ' ' && $held['tb'][$k] !== $tb[$k] && !in_array($k, $tbEdited, true)) {
                return ocr_text('DuelHeldOther', ['side' => $held['name'], 'n' => $count + $k + 1]);
            }
        }
        $plan[] = ['matchNo' => $held['matchNo'], 'letters' => $letters, 'tb' => rtrim($tb), 'closest' => !empty($s['closest'])];
    }
    require_once 'Final/Fun_MatchTotal.inc.php';
    if ($m['robin']) require_once 'Modules/RoundRobin/Lib.php';
    $tour = (int)$_SESSION['TourId'];
    foreach ($plan as $p) {
        if ($m['robin']) {
            RobinUpdateArrowString($p['matchNo'], $m['event'], $m['team'], $m['level'], $m['group'], $m['round'], $p['letters'], 1, $count, $tour);
        } else {
            UpdateArrowString($p['matchNo'], $m['event'], $m['team'], $p['letters'], 1, $count, $tour);
        }
    }
    // The shoot-off after the arrows (it decides only once both are in), the closest last.
    foreach ($plan as $p) {
        if ($p['tb'] === '') continue;
        // bytes: one ASCII letter per arrow.
        $end = $count + strlen($p['tb']);
        if ($m['robin']) {
            RobinUpdateArrowString($p['matchNo'], $m['event'], $m['team'], $m['level'], $m['group'], $m['round'], $p['tb'], $count + 1, $end, $tour, $p['closest'] ? 1 : 0);
        } else {
            UpdateArrowString($p['matchNo'], $m['event'], $m['team'], $p['tb'], $count + 1, $end, $tour, $p['closest'] ? 1 : 0);
        }
    }
    return '';
}

/**
 * A match confirmed, as the core's barcode check confirms it: marked confirmed, then for a
 * bracket the winner moved on to the next round, for a round robin its group ranked again
 * (and the rules' own steps, if any). Only when ianseo holds what was read and the match
 * has a winner (the caller checks).
 *
 * @param array $m The match (ocr_duel_match()).
 */
function ocr_duel_confirm($m) {
    global $CFG;
    $tour = (int)$_SESSION['TourId'];
    $ev = StrSafe_DB($m['event']);
    $m1 = min($m['sides'][0]['matchNo'], $m['sides'][1]['matchNo']);
    $m2 = max($m['sides'][0]['matchNo'], $m['sides'][1]['matchNo']);
    if ($m['robin']) {
        // Modules/Barcodes/GetRobinScoreBarCode.php, ConfirmMatch().
        safe_w_sql("UPDATE RoundRobinMatches SET RrMatchConfirmed = 1, RrMatchStatus = 1
            WHERE RrMatchTournament = $tour AND RrMatchEvent = $ev AND RrMatchTeam = " . (int)$m['team'] . "
            AND RrMatchLevel = " . (int)$m['level'] . " AND RrMatchGroup = " . (int)$m['group'] . "
            AND RrMatchRound = " . (int)$m['round'] . " AND RrMatchMatchNo IN ($m1, $m2)");
        require_once 'Modules/RoundRobin/Lib.php';
        calculateGroupRank((object)['Team' => $m['team'], 'Event' => $m['event'], 'Level' => $m['level'], 'Group' => $m['group']]);
        $t = safe_fetch(safe_r_sql("SELECT ToType, ToLocRule, ToTypeSubRule FROM Tournament WHERE ToId = $tour"));
        if ($t) {
            $common = $CFG->DOCUMENT_PATH . "Modules/Sets/{$t->ToLocRule}/Functions/confirmRobinMatch%s.php";
            foreach (["-{$t->ToType}-{$t->ToTypeSubRule}", "-{$t->ToType}", "-{$t->ToTypeSubRule}", ''] as $suffix) {
                if (!file_exists($file = sprintf($common, $suffix))) continue;
                // The rules' file reads these from its scope.
                $Event = $m['event'];
                $Team = $m['team'];
                $Level = $m['level'];
                $Group = $m['group'];
                $Round = $m['round'];
                $Match = "$m1, $m2";
                require_once $file;
                break;
            }
        }
        return;
    }
    // Modules/Barcodes/GetFinScoreBarCode.php, ConfirmMatch().
    require_once 'Final/Fun_ChangePhase.inc.php';
    $team = (int)$m['team'];
    if ($team) {
        safe_w_sql("UPDATE TeamFinals SET TfConfirmed = 1, TfStatus = 1 WHERE TfTournament = $tour AND TfEvent = $ev AND TfMatchNo IN ($m1, $m2)");
    } else {
        safe_w_sql("UPDATE Finals SET FinConfirmed = 1, FinStatus = 1 WHERE FinTournament = $tour AND FinEvent = $ev AND FinMatchNo IN ($m1, $m2)");
    }
    if (safe_w_affected_rows()) updateOdfTiming('O', $tour, $m['event'], $team, $m1);
    $jack = ['Event' => $m['event'], 'Team' => $team, 'MatchNo' => $m1, 'TourId' => $tour];
    runJack('MatchFinished', $tour, $jack);
    if ($team) {
        move2NextPhaseTeam(null, $m['event'], $m1);
    } else {
        move2NextPhase(null, $m['event'], $m1);
    }
    runJack('FinConfirmEnd', $tour, $jack + ['Side' => 0]);
    runJack('MatchConfirmed', $tour, $jack);
}
