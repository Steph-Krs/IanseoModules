<?php
/**
 * Simulated competitors: entries of a training whose arrows are written by the
 * module, so that a small group meets a full field in the rankings and the
 * brackets.
 *
 * Two kinds, one table (TrainingGhosts):
 *   - fictitious competitors (GhKind 0), club TRNF, named "FICTITIOUS 01…",
 *     shooting at a level: a final qualification score plus or minus a delta,
 *     and a level for the duels (an average per arrow plus or minus a delta for
 *     set events, a match score plus or minus a delta for cumulative events),
 *     each refused when it is beyond what the face and the arrows allow;
 *   - World Archery athletes (GhKind 1) of a training created from a real
 *     competition (lib/wa.php), shooting their real results.
 *
 * Every simulated competitor is an ordinary entry of the competition: ranked by
 * the core, put in teams, placed in the brackets. It takes no target, so no
 * scorecard is printed for it. Its qualification arrows are drawn once, kept in
 * GhQualArrows, and shown at the pace of the real archer of its session who has
 * shot the most arrows (lib/live.php), so that the ranking moves while the
 * training goes on.
 */

/** Club code of the fictitious competitors. */
const TRN_GHOST_CLUB = 'TRNF';

/** Default deltas and duel average of a fictitious competitor; the scores default to a share of the maximum. */
const TRN_GHOST_DEFAULTS = ['qscore' => 0, 'qdelta' => 10, 'davg' => 8.5, 'ddelta' => 0.5, 'dscore' => 0, 'dsdelta' => 5];

/**
 * The simulated competitors of a competition.
 *
 * @param int $tourId
 * @return array EnId => row of TrainingGhosts.
 */
function trn_ghosts($tourId) {
    $out = [];
    $rs = safe_r_sql("SELECT * FROM TrainingGhosts WHERE GhTournament = " . intval($tourId));
    while ($r = safe_fetch($rs)) $out[intval($r->GhEntry)] = $r;
    return $out;
}

/**
 * SQL condition leaving out the simulated competitors of a competition.
 *
 * @param int $tourId
 * @return string To be used after WHERE / AND, on a query that reads Entries.
 */
function trn_not_ghost_sql($tourId) {
    return "EnId NOT IN (SELECT GhEntry FROM TrainingGhosts WHERE GhTournament = " . intval($tourId) . ")";
}

/**
 * Levels read from a form, kept within what they can mean.
 *
 * @param array $in Keys of TRN_GHOST_DEFAULTS, decimal commas accepted.
 * @return array
 */
function trn_ghost_levels($in) {
    $num = function ($k) use ($in) {
        $v = str_replace(',', '.', (string)($in[$k] ?? ''));
        return is_numeric($v) ? floatval($v) : TRN_GHOST_DEFAULTS[$k];
    };
    return [
        'qscore'  => max(0, intval(round($num('qscore')))),
        'qdelta'  => max(0, min(9999, round($num('qdelta'), 2))),
        'davg'    => max(0, min(99, round($num('davg'), 2))),
        'ddelta'  => max(0, min(99, round($num('ddelta'), 2))),
        'dscore'  => max(0, intval(round($num('dscore')))),
        'dsdelta' => max(0, min(9999, round($num('dsdelta'), 2))),
    ];
}

/**
 * SQL assignments of the levels.
 *
 * @param array $l From trn_ghost_levels().
 * @return string
 */
function trn_ghost_levels_sql($l) {
    $f = function ($v) { return StrSafe_DB(number_format($v, 2, '.', '')); };
    return "GhQualScore = " . intval($l['qscore']) . ", GhQualDelta = {$f($l['qdelta'])}, GhDuelAvg = {$f($l['davg'])},
        GhDuelAvgDelta = {$f($l['ddelta'])}, GhDuelScore = " . intval($l['dscore']) . ", GhDuelScoreDelta = {$f($l['dsdelta'])}";
}

/**
 * What a level may not exceed for a category of the open competition: the
 * highest qualification score, the highest value of an arrow in its set duels,
 * the highest score of its duels by points.
 *
 * @param int $tourId Must be the open competition.
 * @param string $div
 * @param string $cls
 * @return array ['qual' => int, 'arrow' => int|null, 'match' => int|null]
 */
function trn_ghost_limits($tourId, $div, $cls) {
    static $qmax = null;
    if ($qmax === null) $qmax = trn_cat_qual_max($tourId);
    $out = ['qual' => intval($qmax[$div . '|' . $cls] ?? 0), 'arrow' => null, 'match' => null];
    $rs = safe_r_sql("SELECT DISTINCT EvMatchMode, EvFinEnds, EvFinArrows, EvFinalTargetType FROM Events
        INNER JOIN EventClass ON EcTournament = EvTournament AND EcCode = EvCode AND EcTeamEvent = 0
        WHERE EvTournament = " . intval($tourId) . " AND EvTeamEvent = 0 AND EvFinalFirstPhase > 0
          AND EcDivision = " . StrSafe_DB($div) . " AND EcClass = " . StrSafe_DB($cls));
    while ($r = safe_fetch($rs)) {
        $max = trn_face_max(trn_face_letters(intval($r->EvFinalTargetType)));
        if (intval($r->EvMatchMode) === 1) {
            $out['arrow'] = $out['arrow'] === null ? $max : min($out['arrow'], $max);
        } else {
            $m = intval($r->EvFinEnds) * intval($r->EvFinArrows) * $max;
            $out['match'] = $out['match'] === null ? $m : min($out['match'], $m);
        }
    }
    return $out;
}

/**
 * Is a level possible for a category? Each score plus its delta must stay
 * within the maximum.
 *
 * @param int $tourId Must be the open competition.
 * @param string $div
 * @param string $cls
 * @param array $l From trn_ghost_levels().
 * @return string Translated reason when it is not, '' when it is.
 */
function trn_ghost_check($tourId, $div, $cls, $l) {
    $lim = trn_ghost_limits($tourId, $div, $cls);
    $cat = $div . ' ' . $cls;
    if ($lim['qual'] > 0 && $l['qscore'] + $l['qdelta'] > $lim['qual']) {
        return trn_text('GhostsErrQual', ['cat' => $cat, 'score' => $l['qscore'], 'delta' => $l['qdelta'], 'max' => $lim['qual']]);
    }
    if ($lim['arrow'] !== null && $l['davg'] + $l['ddelta'] > $lim['arrow']) {
        return trn_text('GhostsErrAvg', ['cat' => $cat, 'score' => $l['davg'], 'delta' => $l['ddelta'], 'max' => $lim['arrow']]);
    }
    if ($lim['match'] !== null && $l['dscore'] + $l['dsdelta'] > $lim['match']) {
        return trn_text('GhostsErrMatch', ['cat' => $cat, 'score' => $l['dscore'], 'delta' => $l['dsdelta'], 'max' => $lim['match']]);
    }
    return '';
}

/**
 * Add fictitious competitors to the open competition.
 *
 * @param int $tourId Must be the open competition.
 * @param int $count 1 to 32.
 * @param string $div
 * @param string $cls
 * @param int $session
 * @param array $levels From trn_ghost_levels().
 * @return int Competitors added.
 */
function trn_ghost_add($tourId, $count, $div, $cls, $session, $levels) {
    $tourId = intval($tourId);
    $count = max(1, min(32, intval($count)));
    $n = intval(safe_fetch(safe_r_sql("SELECT COUNT(*) AS N FROM TrainingGhosts WHERE GhTournament = $tourId AND GhKind = 0"))->N);
    $added = 0;
    for ($i = 1; $i <= $count; $i++) {
        $archer = (object)['ArCode' => '', 'ArIocCode' => '', 'ArFamilyName' => mb_strtoupper(trn_text('GhostName')),
            'ArGivenName' => sprintf('%02d', $n + $i), 'ArSex' => 0, 'ArDob' => null,
            'ArClubCode' => TRN_GHOST_CLUB, 'ArClubName' => trn_text('GhostClub')];
        $enId = trn_add_entry($tourId, $archer, $div, $cls, $session);
        if (!$enId) continue;
        safe_w_sql("INSERT INTO TrainingGhosts SET GhEntry = $enId, GhTournament = $tourId, GhKind = 0, " . trn_ghost_levels_sql($levels));
        $added++;
    }
    return $added;
}

/**
 * Change the levels of a fictitious competitor. Its qualification is drawn
 * again (the arrows already shown change too), and so are its duels not yet
 * finished.
 *
 * @param int $tourId
 * @param int $enId
 * @param array $levels From trn_ghost_levels().
 */
function trn_ghost_set_levels($tourId, $enId, $levels) {
    safe_w_sql("UPDATE TrainingGhosts SET " . trn_ghost_levels_sql($levels) . ", GhQualKey = '', GhQualArrows = NULL
        WHERE GhTournament = " . intval($tourId) . " AND GhEntry = " . intval($enId) . " AND GhKind = 0");
    safe_w_sql("DELETE FROM TrainingSimDuels WHERE SdTournament = " . intval($tourId) . " AND SdTeam = 0 AND SdWho = " . intval($enId));
}

/**
 * What the qualification of an entry is made of: per distance, the arrows of its
 * session, the face shot and the characters counted as golds and X/9.
 *
 * @param int $tourId
 * @param int $enId
 * @return array ['key' => signature, 'dist' => [d => ['n', 'tar', 'letters', 'max', 'g', 'x']]]
 */
function trn_ghost_qual_plan($tourId, $enId) {
    $tourId = intval($tourId);
    $t = safe_fetch(safe_r_sql("SELECT ToNumDist, ToGoldsChars, ToXNineChars FROM Tournament WHERE ToId = $tourId"));
    $nDist = max(1, min(8, intval($t->ToNumDist)));
    $e = safe_fetch(safe_r_sql("SELECT QuSession, TargetFaces.* FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN TargetFaces ON TfId = EnTargetFace AND TfTournament = EnTournament
        WHERE EnId = " . intval($enId) . " AND EnTournament = $tourId"));
    if (!$e) return ['key' => '', 'dist' => []];
    $out = [];
    $sig = [intval($e->QuSession), intval($e->TfId ?? 0)];
    $prevTar = 0;
    for ($d = 1; $d <= $nDist; $d++) {
        $di = safe_fetch(safe_r_sql("SELECT DiEnds, DiArrows FROM DistanceInformation
            WHERE DiTournament = $tourId AND DiSession = " . intval($e->QuSession) . " AND DiDistance = $d AND DiType = 'Q'"));
        $n = $di ? intval($di->DiEnds) * intval($di->DiArrows) : 0;
        $tar = intval($e->{'TfT' . $d} ?? 0);
        if ($tar === 0) $tar = $prevTar;
        $prevTar = $tar;
        $letters = $tar ? trn_face_letters($tar) : ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4, 'F' => 5, 'G' => 6, 'H' => 7, 'I' => 8, 'J' => 9, 'L' => 10];
        $g = (string)($e->{'TfGoldsChars' . $d} ?? '');
        if ($g === '') $g = (string)($e->TfGoldsChars ?? '') !== '' ? (string)$e->TfGoldsChars : (string)$t->ToGoldsChars;
        $x = (string)($e->{'TfXNineChars' . $d} ?? '');
        if ($x === '') $x = (string)($e->TfXNineChars ?? '') !== '' ? (string)$e->TfXNineChars : (string)$t->ToXNineChars;
        $out[$d] = ['n' => $n, 'tar' => $tar, 'letters' => $letters, 'max' => trn_face_max($letters), 'g' => $g, 'x' => $x];
        $sig[] = "$d:$n:$tar";
    }
    return ['key' => md5(implode('|', $sig)), 'dist' => $out];
}

/**
 * Split a total between parts in proportion to their weights, exactly
 * (largest remainders).
 *
 * @param int $total
 * @param array $weights key => weight.
 * @return array key => share.
 */
function trn_split_total($total, $weights) {
    $sum = array_sum($weights);
    $out = [];
    if ($sum <= 0) {
        foreach ($weights as $k => $w) $out[$k] = 0;
        return $out;
    }
    $rest = [];
    $given = 0;
    foreach ($weights as $k => $w) {
        $exact = $total * $w / $sum;
        $out[$k] = intval(floor($exact));
        $rest[$k] = $exact - $out[$k];
        $given += $out[$k];
    }
    arsort($rest);
    foreach (array_keys($rest) as $k) {
        if ($given >= $total) break;
        $out[$k]++;
        $given++;
    }
    return $out;
}

/**
 * Draw the whole qualification of a simulated competitor.
 *
 * @param int $tourId
 * @param object $g TrainingGhosts row.
 * @param array $plan From trn_ghost_qual_plan().
 * @return array distance => letters (every arrow of the distance).
 */
function trn_ghost_draw_qual($tourId, $g, $plan) {
    $rnd = trn_rng('Q|' . intval($tourId) . '|' . intval($g->GhEntry) . '|' . $plan['key'] . '|' . $g->GhQualScore . '|' . $g->GhQualDelta);
    $dist = array_filter($plan['dist'], function ($d) { return $d['n'] > 0; });
    if (!$dist) return [];
    $maxTotal = 0;
    $weights = [];
    foreach ($dist as $k => $d) {
        $weights[$k] = $d['n'] * $d['max'];
        $maxTotal += $weights[$k];
    }

    $real = intval($g->GhKind) === 1 && function_exists('trn_wa_qual') ? trn_wa_qual($tourId, (string)$g->GhWaId) : null;
    if ($real) {
        $total = intval($real['score']);
        $golds = intval($real['gold']);
        $xnines = intval($real['xnine']);
    } else {
        // No score given (a competitor of an older version): its average per arrow.
        $arrows = array_sum(array_column($dist, 'n'));
        $score = intval($g->GhQualScore) > 0 ? floatval($g->GhQualScore) : floatval($g->GhAverage) * $arrows;
        $total = intval(round(max(0, min($maxTotal, $score + (2 * $rnd() - 1) * floatval($g->GhQualDelta)))));
        $golds = $xnines = null;
    }

    // World Archery's golds and X/9 are its own columns ("10+X" and "X" outdoors), not
    // necessarily what this competition counts: the arrows are drawn with its definitions.
    if ($real && $real['gh'] !== '') {
        foreach ($dist as $k => $d) {
            $gl = trn_wa_header_letters($real['gh'], $d['letters']);
            $xl = trn_wa_header_letters($real['xh'], $d['letters']);
            if ($gl !== '') { $dist[$k]['g'] = $gl; $dist[$k]['x'] = $xl; }
        }
    }

    // One face and one way of counting for every distance: one draw, cut in pieces.
    $same = count(array_unique(array_map(function ($d) { return $d['tar'] . '|' . $d['g'] . '|' . $d['x']; }, $dist))) === 1;
    $out = [];
    if ($same) {
        $first = reset($dist);
        $n = array_sum(array_column($dist, 'n'));
        $all = $golds !== null
            ? trn_arrows_counted($first['letters'], $n, $total, $golds, $xnines, $first['g'], $first['x'], $rnd)
            : trn_arrows_for_total($first['letters'], $n, $total, $rnd);
        $pos = 0;
        foreach ($dist as $k => $d) {
            // bytes: arrow letters are ASCII.
            $out[$k] = substr($all, $pos, $d['n']);
            $pos += $d['n'];
        }
        return $out;
    }
    $shares = trn_split_total($total, $weights);
    $arrows = array_map(function ($d) { return $d['n']; }, $dist);
    $gShares = $golds !== null ? trn_split_total($golds, $arrows) : [];
    $xShares = $xnines !== null ? trn_split_total($xnines, $arrows) : [];
    foreach ($dist as $k => $d) {
        $out[$k] = $golds !== null
            ? trn_arrows_counted($d['letters'], $d['n'], $shares[$k], $gShares[$k] ?? 0, $xShares[$k] ?? 0, $d['g'], $d['x'], $rnd)
            : trn_arrows_for_total($d['letters'], $d['n'], $shares[$k], $rnd);
    }
    return $out;
}

/**
 * How far the real archers of each session have got: per distance, the arrows of
 * the real archer of the session who has shot the most. A distance entered by
 * totals, without arrows, counts as shot in full.
 *
 * @param int $tourId
 * @param int $nDist
 * @return array session => [d => arrows], plus key 0 for the whole competition.
 */
function trn_qual_pace($tourId, $nDist) {
    $tourId = intval($tourId);
    $cols = [];
    for ($d = 1; $d <= $nDist; $d++) $cols[] = "QuD{$d}Hits AS H$d, QuD{$d}Score AS S$d";
    $out = [];
    $best = [];
    $rs = safe_r_sql("SELECT QuSession, QuHits, " . implode(', ', $cols) . "
        FROM Entries INNER JOIN Qualifications ON QuId = EnId
        WHERE EnTournament = $tourId AND " . trn_not_ghost_sql($tourId));
    while ($r = safe_fetch($rs)) {
        $hits = [];
        $sum = 0;
        for ($d = 1; $d <= $nDist; $d++) {
            $h = intval($r->{'H' . $d});
            $hits[$d] = ($h === 0 && intval($r->{'S' . $d}) > 0) ? PHP_INT_MAX : $h;
            $sum += min($hits[$d], 10000);
        }
        foreach ([intval($r->QuSession), 0] as $s) {
            if (!isset($best[$s]) || $sum > $best[$s]) {
                $best[$s] = $sum;
                $out[$s] = $hits;
            }
        }
    }
    return $out;
}

/**
 * Show the qualification arrows of the simulated competitors at the pace of the
 * real archers (or all of them when the coach asked for it), drawing them first
 * when needed, and rank again when anything moved.
 *
 * @param int $tourId Must be the open competition.
 * @param bool $all Show every arrow.
 * @return int Competitors whose score changed.
 */
function trn_ghost_qual_tick($tourId, $all = false) {
    require_once 'Common/Lib/ArrTargets.inc.php';
    $tourId = intval($tourId);
    $ghosts = trn_ghosts($tourId);
    if (!$ghosts) return 0;
    $nDist = max(1, min(8, intval(safe_fetch(safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId"))->ToNumDist)));
    $pace = $all ? [] : trn_qual_pace($tourId, $nDist);

    $cols = [];
    for ($d = 1; $d <= $nDist; $d++) $cols[] = "QuD{$d}Arrowstring AS A$d";
    $current = [];
    $rs = safe_r_sql("SELECT EnId, QuSession, " . implode(', ', $cols) . " FROM Entries INNER JOIN Qualifications ON QuId = EnId
        WHERE EnTournament = $tourId AND EnId IN (" . implode(',', array_keys($ghosts)) . ")");
    while ($r = safe_fetch($rs)) $current[intval($r->EnId)] = $r;

    $changed = 0;
    foreach ($ghosts as $enId => $g) {
        if (!isset($current[$enId])) continue;
        $plan = trn_ghost_qual_plan($tourId, $enId);
        if ($plan['key'] === '') continue;
        $drawn = json_decode((string)$g->GhQualArrows, true);
        if ($g->GhQualKey !== $plan['key'] || !is_array($drawn)) {
            $drawn = trn_ghost_draw_qual($tourId, $g, $plan);
            safe_w_sql("UPDATE TrainingGhosts SET GhQualKey = " . StrSafe_DB($plan['key']) . ", GhQualArrows = " . StrSafe_DB(json_encode($drawn))
                . " WHERE GhEntry = $enId AND GhTournament = $tourId");
        }
        $ses = intval($current[$enId]->QuSession);
        $lead = $pace[$ses] ?? ($pace[0] ?? []);
        $set = [];
        foreach ($plan['dist'] as $d => $p) {
            $full = (string)($drawn[$d] ?? '');
            $k = $all ? $p['n'] : min($p['n'], intval($lead[$d] ?? 0));
            // bytes: arrow letters are ASCII.
            $shown = str_pad(substr($full, 0, $k), $p['n']);
            if ($shown === str_pad((string)$current[$enId]->{'A' . $d}, $p['n'])) continue;
            list($score, $gold, $xnine) = ValutaArrowStringGX($shown, $p['g'], $p['x']);
            $hits = strlen(str_replace(' ', '', $shown)); // bytes: arrow letters are ASCII.
            $set[] = "QuD{$d}Score = " . intval($score) . ", QuD{$d}Gold = " . intval($gold) . ", QuD{$d}Xnine = " . intval($xnine)
                . ", QuD{$d}Hits = $hits, QuD{$d}Arrowstring = " . StrSafe_DB($shown);
        }
        if (!$set) continue;
        safe_w_sql("UPDATE Qualifications INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
            SET " . implode(', ', $set) . ",
                QuScore = QuD1Score + QuD2Score + QuD3Score + QuD4Score + QuD5Score + QuD6Score + QuD7Score + QuD8Score,
                QuGold = QuD1Gold + QuD2Gold + QuD3Gold + QuD4Gold + QuD5Gold + QuD6Gold + QuD7Gold + QuD8Gold,
                QuXnine = QuD1Xnine + QuD2Xnine + QuD3Xnine + QuD4Xnine + QuD5Xnine + QuD6Xnine + QuD7Xnine + QuD8Xnine,
                QuHits = QuD1Hits + QuD2Hits + QuD3Hits + QuD4Hits + QuD5Hits + QuD6Hits + QuD7Hits + QuD8Hits,
                QuTimestamp = '" . date('Y-m-d H:i:s') . "'
            WHERE QuId = $enId");
        $changed++;
    }
    if ($changed) trn_recalc($tourId);
    return $changed;
}

/**
 * Highest qualification score of each category of a competition, on the face
 * the category is given and the distances of the first session: what a share of
 * the maximum means, shown next to the level.
 *
 * @param int $tourId Must be the open competition (getTargets() reads it).
 * @return array "DIV|CLS" => highest score.
 */
function trn_cat_qual_max($tourId) {
    require_once 'Partecipants/Fun_Targets.php';
    $tourId = intval($tourId);
    $t = safe_fetch(safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId"));
    $nDist = max(1, min(8, intval($t->ToNumDist)));
    $ses = safe_fetch(safe_r_sql("SELECT MIN(SesOrder) AS S FROM Session WHERE SesTournament = $tourId AND SesType = 'Q'"));
    $arrows = [];
    $rs = safe_r_sql("SELECT DiDistance, DiEnds * DiArrows AS N FROM DistanceInformation
        WHERE DiTournament = $tourId AND DiType = 'Q' AND DiSession = " . intval($ses ? $ses->S : 1));
    while ($r = safe_fetch($rs)) $arrows[intval($r->DiDistance)] = intval($r->N);
    $faces = [];
    $rs = safe_r_sql("SELECT * FROM TargetFaces WHERE TfTournament = $tourId");
    while ($r = safe_fetch($rs)) $faces[intval($r->TfId)] = $r;
    $targets = getTargets(true);
    $out = [];
    foreach (trn_tour_categories($tourId) as $c) {
        $tf = !empty($targets[$c['div']][$c['cls']]) ? intval(array_key_first($targets[$c['div']][$c['cls']])) : 0;
        $face = $faces[$tf] ?? null;
        $max = 0;
        $prev = 0;
        for ($d = 1; $d <= $nDist; $d++) {
            $tar = $face ? intval($face->{'TfT' . $d} ?? 0) : 0;
            if ($tar === 0) $tar = $prev;
            $prev = $tar;
            $max += ($arrows[$d] ?? 0) * ($tar ? trn_face_max(trn_face_letters($tar)) : 10);
        }
        $out[$c['div'] . '|' . $c['cls']] = $max;
    }
    return $out;
}

/**
 * Kinds of duel of a competition's individual events, for the level fields.
 *
 * @param int $tourId
 * @return array ['sets' => bool, 'points' => [event code => highest match score]]
 */
function trn_duel_kinds($tourId) {
    $out = ['sets' => false, 'points' => []];
    $rs = safe_r_sql("SELECT EvCode, EvMatchMode, EvFinEnds, EvFinArrows, EvFinalTargetType FROM Events
        WHERE EvTournament = " . intval($tourId) . " AND EvTeamEvent = 0 AND EvFinalFirstPhase > 0");
    while ($r = safe_fetch($rs)) {
        if (intval($r->EvMatchMode) === 1) {
            $out['sets'] = true;
        } else {
            $out['points'][(string)$r->EvCode] = intval($r->EvFinEnds) * intval($r->EvFinArrows) * trn_face_max(trn_face_letters(intval($r->EvFinalTargetType)));
        }
    }
    return $out;
}
