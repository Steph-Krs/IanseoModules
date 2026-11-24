<?php
/**
 * The live page's engine: what moves the simulated competitors.
 *
 * Nothing in ianseo tells a module that a score has arrived (ISK-Lite, manual
 * entry), and menu.php may write nothing, so the simulated competitors move,
 * and the winners of the duels go on to the next round, only when a page of the
 * module calls trn_live_tick(): the live page does every few seconds, the
 * dashboard on each opening. The coach is told to keep the live page open.
 *
 * Next round: the core's evaluation of a match does not put the winner in the
 * next match; its entry screens do (Final/Individual/WriteScore_Bra.php), ISK
 * never. In every training, with or without simulated competitors, the winner
 * of each decided match is moved on here, and the byes of later rounds given.
 *
 * Qualification: lib/ghosts.php, at the pace of the real archer of the session
 * who has shot the most arrows.
 *
 * Duels: in every launched bracket, a match with a simulated side gets arrows
 * drawn once for that side (TrainingSimDuels):
 *   - a fictitious competitor shoots at its duel level;
 *   - a World Archery athlete or team shoots real arrows (lib/wa.php);
 *   - a team whose members are all fictitious shoots at their average level.
 * They are written through the core's own UpdateArrowString(), which totals the
 * match, decides it and moves the winner on (EvaluateMatch), exactly as a scorer
 * entering the arrows would. Against a real archer, the simulated side shows as
 * many arrows as its opponent has entered; between two simulated sides, the
 * match follows the real match of the same phase that has gone furthest, and is
 * played at once when no real archer is left in that phase. A shoot-off between
 * two simulated sides is settled the same way; against a real archer, an equal
 * arrow is left to the coach (closest to the centre, in the core's screen).
 */

/**
 * Load the core functions the matches need.
 */
function trn_live_require() {
    require_once 'Common/Lib/ArrTargets.inc.php';
    require_once 'Common/Lib/Fun_Phases.inc.php';
    require_once 'Common/Lib/Fun_Modules.php';
    require_once 'Final/Fun_ChangePhase.inc.php';
    require_once 'Final/Fun_MatchTotal.inc.php';
}

/**
 * Move every simulated competitor of the open competition.
 *
 * @param int $tourId Must be the open competition.
 * @return array ['qual' => competitors moved, 'duels' => arrows written, 'busy' => another tick was running]
 */
function trn_live_tick($tourId) {
    $tourId = intval($tourId);
    $out = ['qual' => 0, 'duels' => 0, 'busy' => false];
    $run = trn_run($tourId);
    if (!$run) return $out;
    $ghosts = (bool)safe_fetch(safe_r_sql("SELECT GhEntry FROM TrainingGhosts WHERE GhTournament = $tourId LIMIT 1"));

    // Two browsers on the live page must not write the same arrows twice.
    $lock = 'trn_live_' . $tourId;
    $got = safe_fetch(safe_r_sql("SELECT GET_LOCK(" . StrSafe_DB($lock) . ", 0) AS L"));
    if (!$got || intval($got->L) !== 1) {
        $out['busy'] = true;
        return $out;
    }
    if ($ghosts && !intval($run->RnQualSim)) {
        $out['qual'] = trn_ghost_qual_tick($tourId, intval($run->RnRevealAll) === 1);
    }
    trn_live_require();
    foreach (trn_duel_events($tourId) as $ev) {
        if (intval($ev->EvElimType) !== 0) continue;
        $out['duels'] += trn_live_event($tourId, intval($ev->EvTeamEvent), (string)$ev->EvCode);
    }
    safe_r_sql("SELECT RELEASE_LOCK(" . StrSafe_DB($lock) . ")");
    return $out;
}

/**
 * Who is simulated in the brackets of an event: individual EnIds, or team CoIds,
 * with what drives their arrows.
 *
 * @param int $tourId
 * @param int $team
 * @return array id => ['kind' => 'level'|'wa', 'levels' => row, 'wa' => WA key]
 */
function trn_live_simulated($tourId, $team) {
    static $cache = [];
    $key = intval($tourId) . '|' . intval($team);
    if (isset($cache[$key])) return $cache[$key];
    $ghosts = trn_ghosts($tourId);
    // A score of a duel by points becomes an average per arrow on the arrows of the
    // archer's individual event, so that it also serves a phase or a team with other arrows.
    $ref = [];
    $rs = safe_r_sql("SELECT IndId, EvFinEnds * EvFinArrows AS N FROM Individuals
        INNER JOIN Events ON EvTournament = IndTournament AND EvCode = IndEvent AND EvTeamEvent = 0
        WHERE IndTournament = " . intval($tourId));
    while ($r = safe_fetch($rs)) $ref[intval($r->IndId)] = max(1, intval($r->N));
    foreach ($ghosts as $id => $g) {
        if (intval($g->GhDuelScore) > 0) {
            $g->PtsMean = floatval($g->GhDuelScore) / ($ref[$id] ?? 15);
            $g->PtsDelta = floatval($g->GhDuelScoreDelta) / ($ref[$id] ?? 15);
        } else {
            $g->PtsMean = floatval($g->GhDuelAvg);
            $g->PtsDelta = floatval($g->GhDuelAvgDelta);
        }
    }
    $out = [];
    if (!$team) {
        foreach ($ghosts as $id => $g) {
            $out[$id] = intval($g->GhKind) === 1
                ? ['kind' => 'wa', 'wa' => (string)$g->GhWaId, 'levels' => $g]
                : ['kind' => 'level', 'levels' => $g];
        }
        return $cache[$key] = $out;
    }
    // A team is simulated when every one of its members is.
    $members = [];
    $rs = safe_r_sql("SELECT EnId, EnCountry2, EnCountry3, CoCode FROM Entries
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        WHERE EnTournament = " . intval($tourId));
    while ($r = safe_fetch($rs)) {
        foreach ([intval($r->EnCountry2), intval($r->EnCountry3)] as $co) {
            if ($co) $members[$co][] = ['id' => intval($r->EnId), 'noc' => (string)$r->CoCode];
        }
    }
    foreach ($members as $co => $list) {
        $all = true;
        $wa = true;
        $nocs = [];
        foreach ($list as $m) {
            if (!isset($ghosts[$m['id']])) { $all = false; break; }
            if (intval($ghosts[$m['id']]->GhKind) !== 1) $wa = false;
            $nocs[$m['noc']] = true;
        }
        if (!$all) continue;
        if ($wa && count($nocs) === 1) {
            $out[$co] = ['kind' => 'wa', 'wa' => (string)array_key_first($nocs)];
            continue;
        }
        $avg = ['GhDuelAvg' => 0, 'GhDuelAvgDelta' => 0, 'PtsMean' => 0, 'PtsDelta' => 0];
        foreach ($list as $m) foreach ($avg as $k => $v) $avg[$k] += floatval($ghosts[$m['id']]->$k) / count($list);
        $out[$co] = ['kind' => 'level', 'levels' => (object)$avg];
    }
    return $cache[$key] = $out;
}

/**
 * The matches of an event, by phase.
 *
 * @param int $tourId
 * @param int $team
 * @param string $event
 * @return array phase => [matchNo => row (who, arrows, tiebreak, win, irm)]
 */
function trn_live_matches($tourId, $team, $event) {
    $p = $team ? 'Tf' : 'Fin';
    $who = $team ? 'TfTeam' : 'FinAthlete';
    $rs = safe_r_sql("SELECT {$p}MatchNo AS M, $who AS W, {$p}Arrowstring AS A, {$p}Tiebreak AS T, {$p}WinLose AS WL,
            {$p}IrmType AS IRM, GrPhase
        FROM " . ($team ? 'TeamFinals' : 'Finals') . " INNER JOIN Grids ON GrMatchNo = {$p}MatchNo
        WHERE {$p}Tournament = " . intval($tourId) . " AND {$p}Event = " . StrSafe_DB($event));
    $out = [];
    while ($r = safe_fetch($rs)) {
        $out[intval($r->GrPhase)][intval($r->M)] = (object)['who' => intval($r->W), 'a' => rtrim((string)$r->A),
            't' => rtrim((string)$r->T), 'win' => intval($r->WL), 'irm' => intval($r->IRM)];
    }
    krsort($out);
    return $out;
}

/**
 * Play what can be played in the brackets of one event.
 *
 * @param int $tourId
 * @param int $team
 * @param string $event
 * @return int Arrows written.
 */
function trn_live_event($tourId, $team, $event) {
    // Without simulated competitors, the pass still moves the winners on and gives the byes:
    // every training is played without a click between two rounds.
    $sim = trn_live_simulated($tourId, $team);
    $written = 0;
    // A decided match moves its winner on, which can open a match of the next phase:
    // read again until nothing moves (one pass per phase at most).
    for ($pass = 0; $pass < 10; $pass++) {
        $moved = 0;
        $all = trn_live_matches($tourId, $team, $event);
        foreach ($all as $phase => $rows) {
            // The furthest real match of the phase: the pace of the matches between two simulated sides.
            $pace = null;
            foreach ($rows as $m => $r) {
                if ($m % 2) continue;
                $o = $rows[$m + 1] ?? null;
                if (!$o || !$r->who || !$o->who) continue;
                foreach ([$r, $o] as $side) {
                    if (!isset($sim[$side->who])) $pace = max((int)$pace, strlen($side->a)); // bytes: arrow letters are ASCII.
                }
            }
            foreach ($rows as $m => $r) {
                if ($m % 2) continue;
                $o = $rows[$m + 1] ?? null;
                if (!$o) continue;
                if ($r->win || $o->win) {
                    // Decided: the winner must be in the next phase, whoever entered the arrows.
                    $winner = $r->win ? $r->who : $o->who;
                    $to = $phase >= 4 ? ($m >> 1) : ($phase === 2 ? ($m >> 1) - 2 : -1);
                    // Phases of the grid are powers of two (64 and 32 for the 1/48 and the 1/24).
                    $nextPhase = $phase >= 4 ? intdiv($phase, 2) : 0;
                    if ($winner && $to >= 0 && isset($all[$nextPhase][$to]) && $all[$nextPhase][$to]->who !== $winner) {
                        trn_live_move_on($tourId, $team, $event, $m);
                        $moved++;
                    }
                    continue;
                }
                if ($r->irm || $o->irm) continue;
                if (($r->who xor $o->who) && trn_live_bye($tourId, $team, $event, $m, $r->who ? $m : $m + 1)) {
                    $moved++;
                    continue;
                }
                if (!$r->who || !$o->who) continue;
                if (!isset($sim[$r->who]) && !isset($sim[$o->who])) continue;
                $w = trn_live_match($tourId, $team, $event, $phase, $m, $r, $o, $sim, $pace);
                if ($w) {
                    trn_live_move_on($tourId, $team, $event, $m);
                    $moved += $w;
                }
            }
        }
        $written += $moved;
        if (!$moved) break;
    }
    return $written;
}

/**
 * A competitor alone in a match of a later phase, whose opponent can no longer
 * come (every match that could send one is empty): the core moves byes on in
 * the first phase only, so the bye is given here, the way the core writes one.
 *
 * @param int $tourId
 * @param int $team
 * @param string $event
 * @param int $m Even match number.
 * @param int $lone Match number of the competitor.
 * @return bool True when the bye was given.
 */
function trn_live_bye($tourId, $team, $event, $m, $lone) {
    $p = $team ? 'Tf' : 'Fin';
    $who = $team ? 'TfTeam' : 'FinAthlete';
    $table = $team ? 'TeamFinals' : 'Finals';
    $empty = $lone === $m ? $m + 1 : $m;
    // Matches that could still send an opponent: the two feeding the empty slot, and theirs.
    $feed = [$empty];
    $any = false;
    for ($depth = 0; $depth < 7 && $feed; $depth++) {
        $next = [];
        foreach ($feed as $f) { $next[] = 2 * $f; $next[] = 2 * $f + 1; }
        $next = array_filter($next, function ($x) { return $x < 256; });
        if (!$next) break;
        $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS N, SUM($who > 0) AS W FROM $table
            WHERE {$p}Tournament = " . intval($tourId) . " AND {$p}Event = " . StrSafe_DB($event) . " AND {$p}MatchNo IN (" . implode(',', $next) . ")"));
        if (!$r || intval($r->N) === 0) break;
        if (intval($r->W) > 0) { $any = true; break; }
        $feed = $next;
    }
    if ($any || $empty < 2) return false;
    $now = date('Y-m-d H:i:s');
    safe_w_sql("UPDATE $table SET {$p}Tie = 2, {$p}WinLose = 1, {$p}DateTime = '$now'
        WHERE {$p}Tournament = " . intval($tourId) . " AND {$p}Event = " . StrSafe_DB($event) . " AND {$p}MatchNo = " . intval($lone));
    trn_live_move_on($tourId, $team, $event, $m);
    return true;
}

/**
 * Move the winner of a decided match on to the next phase, as the core's entry
 * screens do after each match (Final/Individual/WriteScore_Bra.php): the core's
 * own evaluation moves winners on by itself only from the semi-finals.
 *
 * @param int $tourId
 * @param int $team
 * @param string $event
 * @param int $m
 */
function trn_live_move_on($tourId, $team, $event, $m) {
    $p = $team ? 'Tf' : 'Fin';
    $r = safe_fetch(safe_r_sql("SELECT SUM({$p}WinLose) AS W FROM " . ($team ? 'TeamFinals' : 'Finals') . "
        WHERE {$p}Tournament = " . intval($tourId) . " AND {$p}Event = " . StrSafe_DB($event) . " AND {$p}MatchNo IN (" . intval($m) . ", " . (intval($m) + 1) . ")"));
    if (!$r || !intval($r->W)) return;
    if ($team) move2NextPhaseTeam(null, $event, $m, $tourId);
    else move2NextPhase(null, $event, $m, $tourId);
}

/**
 * Bring one match up to date.
 *
 * @param int $tourId
 * @param int $team
 * @param string $event
 * @param int $phase
 * @param int $m Even match number.
 * @param object $a Row of match $m.
 * @param object $b Row of match $m + 1.
 * @param array $sim From trn_live_simulated().
 * @param int|null $pace Arrows of the furthest real match of the phase, null when there is none.
 * @return int Arrows written.
 */
function trn_live_match($tourId, $team, $event, $phase, $m, $a, $b, $sim, $pace) {
    $par = getEventArrowsParams($event, $phase, $team, $tourId);
    $n = intval($par->ends) * intval($par->arrows);
    if ($n <= 0) return 0;
    $per = max(1, intval($par->arrows));
    $so = max(1, intval($par->so));
    $sides = [$m => $a, $m + 1 => $b];
    $drawn = [];
    foreach ($sides as $no => $s) {
        if (isset($sim[$s->who])) {
            $opp = $sides[$no % 2 ? $no - 1 : $no + 1];
            $drawn[$no] = trn_live_draw($tourId, $team, $event, $phase, $no, $s->who, $opp->who, $sim, $par);
        }
    }
    $written = 0;
    $write = function ($no, $string, $start, $end, $closest = 0) use ($event, $team, $tourId, &$written) {
        $written += $end - $start + 1;
        return UpdateArrowString($no, $event, $team, $string, $start, $end, $tourId, $closest);
    };

    if (count($drawn) === 1) {
        // Against a real archer: as many arrows as the opponent has entered.
        $no = array_key_first($drawn);
        $real = $sides[$no % 2 ? $no - 1 : $no + 1];
        $k = min($n, strlen($real->a)); // bytes: arrow letters are ASCII.
        // bytes: arrow letters are ASCII.
        $want = rtrim(substr($drawn[$no]['a'], 0, $k));
        if ($want !== $sides[$no]->a) $write($no, str_pad($want, $n), 1, $n);
        if ($k >= $n) {
            $kt = min(strlen($drawn[$no]['t']), strlen($real->t)); // bytes: arrow letters are ASCII.
            $wantT = rtrim(substr($drawn[$no]['t'], 0, $kt)); // bytes: arrow letters are ASCII.
            if ($wantT !== $sides[$no]->t && $wantT !== '') $write($no, $wantT, $n + 1, $n + strlen($wantT)); // bytes: arrow letters are ASCII.
        }
        return $written;
    }

    // Two simulated sides: end by end, up to the pace of the phase, until the match is decided.
    $target = $pace === null ? $n : min($n, intval(ceil($pace / $per)) * $per);
    $done = min(strlen($a->a), strlen($b->a)); // bytes: arrow letters are ASCII.
    $done -= $done % $per;
    for ($k = $done + $per; $k <= $target; $k += $per) {
        $write($m, str_pad(substr($drawn[$m]['a'], 0, $k), $n), 1, $n); // bytes: arrow letters are ASCII.
        if ($write($m + 1, str_pad(substr($drawn[$m + 1]['a'], 0, $k), $n), 1, $n)) return $written; // bytes: arrow letters are ASCII.
    }
    if ($target < $n) return $written;
    if (strlen($a->a) < $n || strlen($b->a) < $n) { // bytes: arrow letters are ASCII.
        // Ends written in this call: the match may be over already.
        $state = trn_live_matches($tourId, $team, $event)[$phase] ?? [];
        if (!empty($state[$m]->win) || !empty($state[$m + 1]->win)) return $written;
    }
    // Shoot-off, one round at a time; equal arrows: the closest drawn for the match decides.
    $rounds = intdiv(min(strlen($drawn[$m]['t']), strlen($drawn[$m + 1]['t'])), $so); // bytes: arrow letters are ASCII.
    for ($r = 1; $r <= $rounds; $r++) {
        $len = $r * $so;
        $ta = substr($drawn[$m]['t'], 0, $len); // bytes: arrow letters are ASCII.
        $tb = substr($drawn[$m + 1]['t'], 0, $len); // bytes: arrow letters are ASCII.
        $va = trn_letters_value(substr($ta, -$so)); // bytes: arrow letters are ASCII.
        $vb = trn_letters_value(substr($tb, -$so)); // bytes: arrow letters are ASCII.
        $ca = substr($drawn[$m]['c'], $len - 1, 1) === '1'; // bytes: flags are ASCII.
        $cb = substr($drawn[$m + 1]['c'], $len - 1, 1) === '1'; // bytes: flags are ASCII.
        $closest = $va !== $vb ? -1 : (($ca && !$cb) ? $m : (($cb && !$ca) ? $m + 1 : ($ca ? $m : $m + 1)));
        $write($m, $ta, $n + 1, $n + $len, $closest === $m ? 1 : 0);
        if ($write($m + 1, $tb, $n + 1, $n + $len, $closest === $m + 1 ? 1 : 0)) break;
    }
    return $written;
}

/**
 * The arrows of a simulated side in one match, drawn once and kept.
 *
 * @param int $tourId
 * @param int $team
 * @param string $event
 * @param int $phase
 * @param int $matchNo
 * @param int $who EnId or CoId.
 * @param int $oppWho
 * @param array $sim
 * @param object $par From getEventArrowsParams().
 * @return array ['a' => regular arrows, 't' => shoot-off arrows, 'c' => closest flags by round]
 */
function trn_live_draw($tourId, $team, $event, $phase, $matchNo, $who, $oppWho, $sim, $par) {
    $tourId = intval($tourId);
    $key = ['SdTournament' => $tourId, 'SdTeam' => intval($team), 'SdEvent' => StrSafe_DB($event), 'SdMatchNo' => intval($matchNo)];
    $where = implode(' AND ', array_map(function ($c, $v) { return "$c = $v"; }, array_keys($key), $key));
    $set = implode(', ', array_map(function ($c, $v) { return "$c = $v"; }, array_keys($key), $key));
    $row = safe_fetch(safe_r_sql("SELECT SdWho, SdArrows, SdTiebreak, SdClosest FROM TrainingSimDuels WHERE $where"));
    if ($row && intval($row->SdWho) === intval($who)) {
        return ['a' => (string)$row->SdArrows, 't' => (string)$row->SdTiebreak, 'c' => (string)$row->SdClosest];
    }

    $n = intval($par->ends) * intval($par->arrows);
    $so = max(1, intval($par->so));
    $tar = intval(safe_fetch(safe_r_sql("SELECT EvFinalTargetType FROM Events WHERE EvTournament = $tourId AND EvTeamEvent = " . intval($team)
        . " AND EvCode = " . StrSafe_DB($event)))->EvFinalTargetType ?? 0);
    $letters = trn_face_letters($tar);
    $rnd = trn_rng("D|$tourId|$team|$event|$matchNo|$who");
    $s = $sim[$who];
    $res = null;
    if ($s['kind'] === 'wa' && function_exists('trn_wa_match_arrows')) {
        $oppWa = isset($sim[$oppWho]) && $sim[$oppWho]['kind'] === 'wa' ? $sim[$oppWho]['wa'] : '';
        $res = trn_wa_match_arrows($tourId, $team, $event, $phase, $s['wa'], $oppWa, $n, $so * 3, $letters, $rnd);
    }
    if (!$res) {
        $l = $s['levels'];
        $max = trn_face_max($letters);
        if (intval($par->EvMatchMode) === 1) {
            $mean = floatval($l->GhDuelAvg) + (2 * $rnd() - 1) * floatval($l->GhDuelAvgDelta);
        } else {
            $mean = floatval($l->PtsMean) + (2 * $rnd() - 1) * floatval($l->PtsDelta);
        }
        $mean = max(0, min($max, $mean));
        $values = array_keys(trn_face_by_value($letters));
        $tb = '';
        for ($i = 0; $i < $so * 3; $i++) {
            $tb .= trn_arrows_for_total($letters, 1, intval(round($mean + 1.2 * trn_rng_normal($rnd))), $rnd);
        }
        $res = ['a' => trn_arrows_for_total($letters, $n, intval(round($mean * $n)), $rnd), 't' => $tb, 'src' => 'level'];
    }
    // Closest to the centre on an equal shoot-off arrow: the real one when known, else drawn.
    $closest = '';
    for ($i = 0; $i < $so * 3; $i++) $closest .= isset($res['c'][$i]) ? $res['c'][$i] : ($rnd() < 0.5 ? '1' : '0');
    safe_w_sql("REPLACE INTO TrainingSimDuels SET $set, SdWho = " . intval($who) . ", SdSource = " . StrSafe_DB($res['src'])
        . ", SdArrows = " . StrSafe_DB($res['a']) . ", SdTiebreak = " . StrSafe_DB($res['t']) . ", SdClosest = " . StrSafe_DB($closest));
    return ['a' => $res['a'], 't' => $res['t'], 'c' => $closest];
}

/**
 * Where the training stands, for the live page.
 *
 * @param int $tourId
 * @return array
 */
function trn_live_state($tourId) {
    $tourId = intval($tourId);
    // Qualification by individual event (the same keys as the duels, so that one choice of
    // events serves both): the event ranking the core writes (IndRank).
    $out = ['events' => [], 'qual' => [], 'duels' => [], 'pending' => count(trn_shootoffs($tourId))];
    $rs = safe_r_sql("SELECT EvCode, EvEventName, EvTeamEvent FROM Events
        WHERE EvTournament = $tourId AND EvCodeParent = '' ORDER BY EvTeamEvent, EvProgr, EvCode");
    while ($r = safe_fetch($rs)) {
        $out['events'][] = ['key' => (intval($r->EvTeamEvent) ? 'T' : 'I') . $r->EvCode,
            'label' => $r->EvCode . ' - ' . $r->EvEventName . (intval($r->EvTeamEvent) ? ' (' . trn_text('LiveTeam') . ')' : '')];
    }
    $rs = safe_r_sql("SELECT IndEvent, EnFirstName, EnName, QuScore, QuHits, IndRank, CoCode,
            (SELECT GhKind FROM TrainingGhosts WHERE GhEntry = EnId) AS Kind
        FROM Individuals
        INNER JOIN Entries ON EnId = IndId AND EnTournament = IndTournament
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        WHERE IndTournament = $tourId
        ORDER BY IndEvent, IndRank = 0, IndRank, QuScore DESC, EnFirstName");
    while ($r = safe_fetch($rs)) {
        $out['qual']['I' . $r->IndEvent][] = [
            'name' => trim($r->EnFirstName . ' ' . $r->EnName), 'club' => (string)$r->CoCode,
            'score' => intval($r->QuScore), 'hits' => intval($r->QuHits), 'rank' => intval($r->IndRank),
            'sim' => $r->Kind === null ? 0 : (intval($r->Kind) === 1 ? 2 : 1),
        ];
    }

    // Matches under way (both sides known, no winner yet), and how many are over.
    $sims = [0 => trn_live_simulated($tourId, 0), 1 => trn_live_simulated($tourId, 1)];
    foreach (trn_duel_events($tourId) as $ev) {
        $team = intval($ev->EvTeamEvent);
        $p = $team ? 'Tf' : 'Fin';
        $name = $team
            ? "(SELECT CoName FROM Countries WHERE CoId = TfTeam)"
            : "(SELECT CONCAT(EnFirstName, ' ', EnName) FROM Entries WHERE EnId = FinAthlete)";
        $rs = safe_r_sql("SELECT {$p}MatchNo AS M, " . ($team ? 'TfTeam' : 'FinAthlete') . " AS W, $name AS N, {$p}Score AS S,
                {$p}SetScore AS SS, {$p}WinLose AS WL, {$p}Arrowstring AS A, GrPhase, EvMatchMode
            FROM " . ($team ? 'TeamFinals' : 'Finals') . "
            INNER JOIN Grids ON GrMatchNo = {$p}MatchNo
            INNER JOIN Events ON EvTournament = {$p}Tournament AND EvCode = {$p}Event AND EvTeamEvent = $team
            WHERE {$p}Tournament = $tourId AND {$p}Event = " . StrSafe_DB($ev->EvCode) . "
            ORDER BY GrPhase DESC, {$p}MatchNo");
        $rows = [];
        while ($r = safe_fetch($rs)) $rows[intval($r->M)] = $r;
        $live = [];
        $over = 0;
        foreach ($rows as $m => $r) {
            if ($m % 2 || !isset($rows[$m + 1])) continue;
            $o = $rows[$m + 1];
            if (!intval($r->W) || !intval($o->W)) continue;
            if (intval($r->WL) || intval($o->WL)) { $over++; continue; }
            $side = function ($x) use ($sims, $team) {
                return ['name' => trim((string)$x->N), 'sim' => isset($sims[$team][intval($x->W)]) ? 1 : 0,
                    'score' => intval($x->EvMatchMode) ? intval($x->SS) : intval($x->S), 'arrows' => strlen(rtrim((string)$x->A))]; // bytes: arrow letters are ASCII.
            };
            $live[] = ['phase' => intval($r->GrPhase), 'a' => $side($r), 'b' => $side($o)];
        }
        if ($live || $over) {
            $out['duels'][] = ['key' => ($team ? 'T' : 'I') . $ev->EvCode, 'event' => $ev->EvCode . ' - ' . $ev->EvEventName,
                'team' => $team, 'live' => $live, 'over' => $over];
        }
    }
    return $out;
}

/**
 * Matches waiting for the coach: both sides have shot every arrow of the match
 * and there is no winner, so a shoot-off is to be entered, or the arrow closest
 * to the centre to be named when the shoot-off arrows are equal.
 *
 * @param int $tourId
 * @return array List of ['team', 'event', 'name', 'phase', 'm', 'so', 'letters' => face letter => label,
 *               'sides' => [matchNo => ['who', 'name', 'sim', 'tb' => letters, 'closest']]]
 */
function trn_shootoffs($tourId) {
    trn_live_require();
    global $LetterPoint;
    $tourId = intval($tourId);
    $out = [];
    foreach (trn_duel_events($tourId) as $ev) {
        if (intval($ev->EvElimType) !== 0) continue;
        $team = intval($ev->EvTeamEvent);
        $p = $team ? 'Tf' : 'Fin';
        $name = $team ? "(SELECT CoName FROM Countries WHERE CoId = TfTeam)" : "(SELECT CONCAT(EnFirstName, ' ', EnName) FROM Entries WHERE EnId = FinAthlete)";
        $rs = safe_r_sql("SELECT {$p}MatchNo AS M, " . ($team ? 'TfTeam' : 'FinAthlete') . " AS W, $name AS N, {$p}Arrowstring AS A,
                {$p}Tiebreak AS T, {$p}TbClosest AS C, {$p}WinLose AS WL, {$p}IrmType AS IRM, GrPhase, EvFinalTargetType
            FROM " . ($team ? 'TeamFinals' : 'Finals') . "
            INNER JOIN Grids ON GrMatchNo = {$p}MatchNo
            INNER JOIN Events ON EvTournament = {$p}Tournament AND EvCode = {$p}Event AND EvTeamEvent = $team
            WHERE {$p}Tournament = $tourId AND {$p}Event = " . StrSafe_DB($ev->EvCode));
        $rows = [];
        while ($r = safe_fetch($rs)) $rows[intval($r->M)] = $r;
        $sim = trn_live_simulated($tourId, $team);
        foreach ($rows as $m => $r) {
            if ($m % 2 || !isset($rows[$m + 1])) continue;
            $o = $rows[$m + 1];
            if (!intval($r->W) || !intval($o->W) || intval($r->WL) || intval($o->WL) || intval($r->IRM) || intval($o->IRM)) continue;
            $par = getEventArrowsParams($ev->EvCode, intval($r->GrPhase), $team, $tourId);
            $n = intval($par->ends) * intval($par->arrows);
            // bytes: arrow letters are ASCII.
            if ($n <= 0 || strlen(rtrim((string)$r->A)) < $n || strlen(rtrim((string)$o->A)) < $n) continue;
            $labels = [];
            foreach (trn_face_letters(intval($r->EvFinalTargetType)) as $l => $v) $labels[$l] = (string)$LetterPoint[$l]['P'];
            arsort($labels);
            $sides = [];
            foreach ([$m => $r, $m + 1 => $o] as $no => $x) {
                $sides[$no] = ['who' => intval($x->W), 'name' => trim((string)$x->N), 'sim' => isset($sim[intval($x->W)]),
                    'tb' => rtrim((string)$x->T), 'closest' => intval($x->C) === 1];
            }
            $out[] = ['team' => $team, 'event' => (string)$ev->EvCode, 'name' => $ev->EvCode . ' - ' . $ev->EvEventName,
                'phase' => intval($r->GrPhase), 'm' => $m, 'n' => $n, 'so' => max(1, intval($par->so)), 'letters' => $labels, 'sides' => $sides];
        }
    }
    return $out;
}

/**
 * Enter a shoot-off round and, when its arrows are equal, the arrow closest to
 * the centre, through the core's own UpdateArrowString(); the winner is then
 * moved on.
 *
 * @param int $tourId Must be the open competition.
 * @param int $team
 * @param string $event
 * @param int $m Even match number.
 * @param array $arrows matchNo => letters of the round (sides left out keep theirs).
 * @param int $closest Match number of the side closest to the centre, -1 for none.
 * @return bool True when the match is decided.
 */
function trn_shootoff_save($tourId, $team, $event, $m, $arrows, $closest) {
    trn_live_require();
    $tourId = intval($tourId);
    $m = intval($m) - intval($m) % 2;
    foreach (trn_shootoffs($tourId) as $s) {
        if ($s['team'] !== intval($team) || $s['event'] !== (string)$event || $s['m'] !== $m) continue;
        $n = $s['n'];
        $so = $s['so'];
        // The round being shot: the first one a side has not finished, else the last one.
        $lens = array_map(function ($x) { return strlen($x['tb']); }, $s['sides']); // bytes: arrow letters are ASCII.
        $round = max(1, intval(ceil(max($lens) / $so)));
        if (min($lens) >= $round * $so && $closest < 0) $round++;
        $start = ($round - 1) * $so;
        // The side closest to the centre is written last, so that the core clears the other one first.
        $order = array_keys($s['sides']);
        if ($closest === $order[0]) $order = array_reverse($order);
        foreach ($order as $no) {
            $given = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($arrows[$no] ?? '')));
            $mine = $s['sides'][$no]['tb'];
            // bytes: arrow letters are ASCII.
            $round_letters = $given !== '' ? substr($given, 0, $so) : substr($mine, $start, $so);
            if (strlen($round_letters) < $so && $no !== $closest) continue; // bytes: arrow letters are ASCII.
            $string = substr(str_pad(substr($mine, 0, $start), $start), 0, $start) . $round_letters; // bytes: arrow letters are ASCII.
            UpdateArrowString($no, $event, $team, $string, $n + 1, $n + strlen($string), $tourId, $no === $closest ? 1 : 0); // bytes: arrow letters are ASCII.
        }
        trn_live_move_on($tourId, $team, $event, $m);
        $p = $team ? 'Tf' : 'Fin';
        $r = safe_fetch(safe_r_sql("SELECT SUM({$p}WinLose) AS W FROM " . ($team ? 'TeamFinals' : 'Finals') . "
            WHERE {$p}Tournament = $tourId AND {$p}Event = " . StrSafe_DB($event) . " AND {$p}MatchNo IN ($m, " . ($m + 1) . ")"));
        return $r && intval($r->W) > 0;
    }
    return false;
}
