<?php
/**
 * Fictitious competitors: entries of a training whose qualification scores are
 * drawn around an average per arrow, so that a small group still meets a full
 * field in the rankings and the brackets.
 *
 * A fictitious competitor is an ordinary entry of the competition (it is
 * ranked, it can be put in a team, it takes its place in the brackets) with a
 * club of its own (code TRNF) and a row of TrainingGhosts. It takes no target,
 * so no scorecard is printed for it. Its duels are entered like any other.
 */

/** Club code of the fictitious competitors. */
const TRN_GHOST_CLUB = 'TRNF';

/**
 * EnIds of the fictitious competitors of a competition.
 *
 * @param int $tourId
 * @return array EnId => ['avg' => float, 'spread' => float]
 */
function trn_ghosts($tourId) {
    $out = [];
    $rs = safe_r_sql("SELECT GhEntry, GhAverage, GhSpread FROM TrainingGhosts WHERE GhTournament = " . intval($tourId));
    while ($r = safe_fetch($rs)) $out[intval($r->GhEntry)] = ['avg' => floatval($r->GhAverage), 'spread' => floatval($r->GhSpread)];
    return $out;
}

/**
 * SQL condition leaving out the fictitious competitors of a competition.
 *
 * @param int $tourId
 * @return string To be used after WHERE / AND, on a query that reads Entries.
 */
function trn_not_ghost_sql($tourId) {
    return "EnId NOT IN (SELECT GhEntry FROM TrainingGhosts WHERE GhTournament = " . intval($tourId) . ")";
}

/**
 * Add fictitious competitors to the open competition.
 *
 * @param int $tourId Must be the open competition.
 * @param int $count 1 to 32.
 * @param string $div
 * @param string $cls
 * @param int $session
 * @param float $avg Average per arrow.
 * @param float $spread Standard deviation per arrow.
 * @return int Competitors added.
 */
function trn_ghost_add($tourId, $count, $div, $cls, $session, $avg, $spread) {
    $tourId = intval($tourId);
    $count = max(1, min(32, intval($count)));
    $avg = max(0, min(20, round(floatval($avg), 2)));
    $spread = max(0, min(10, round(floatval($spread), 2)));
    $n = intval(safe_fetch(safe_r_sql("SELECT COUNT(*) AS N FROM TrainingGhosts WHERE GhTournament = $tourId"))->N);
    $added = 0;
    for ($i = 1; $i <= $count; $i++) {
        $archer = (object)['ArCode' => '', 'ArIocCode' => '', 'ArFamilyName' => mb_strtoupper(trn_text('GhostName')),
            'ArGivenName' => sprintf('%02d', $n + $i), 'ArSex' => 0, 'ArDob' => null,
            'ArClubCode' => TRN_GHOST_CLUB, 'ArClubName' => trn_text('GhostClub')];
        $enId = trn_add_entry($tourId, $archer, $div, $cls, $session);
        if (!$enId) continue;
        safe_w_sql("INSERT INTO TrainingGhosts SET GhEntry = $enId, GhTournament = $tourId,
            GhAverage = " . StrSafe_DB(number_format($avg, 2, '.', '')) . ", GhSpread = " . StrSafe_DB(number_format($spread, 2, '.', '')));
        $added++;
    }
    return $added;
}

/**
 * One arrow drawn around an average (normal law, Box-Muller), as a whole value
 * between 0 and the maximum of the face.
 *
 * @param float $avg
 * @param float $spread
 * @param int $max
 * @return int
 */
function trn_ghost_arrow($avg, $spread, $max) {
    $u = max(mt_rand(1, mt_getrandmax()) / mt_getrandmax(), 1e-9);
    $v = mt_rand() / mt_getrandmax();
    $z = sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    return intval(max(0, min($max, round($avg + $spread * $z))));
}

/**
 * Draw the qualification scores of the fictitious competitors of the open
 * competition: every distance of their session, arrow by arrow, totals only
 * (as a score entered by totals). Golds count the arrows at the maximum of the
 * face, X/9 the ones just below.
 *
 * @param int $tourId Must be the open competition.
 * @return int Competitors scored.
 */
function trn_ghost_scores($tourId) {
    $tourId = intval($tourId);
    $ghosts = trn_ghosts($tourId);
    if (!$ghosts) return 0;
    $nDist = max(1, intval(safe_fetch(safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tourId"))->ToNumDist));
    $done = 0;
    $rs = safe_r_sql("SELECT EnId, QuSession FROM Entries INNER JOIN Qualifications ON QuId = EnId
        WHERE EnTournament = $tourId AND EnId IN (" . implode(',', array_keys($ghosts)) . ")");
    while ($r = safe_fetch($rs)) {
        $g = $ghosts[intval($r->EnId)];
        $set = [];
        $tot = ['s' => 0, 'h' => 0, 'g' => 0, 'x' => 0];
        for ($d = 1; $d <= $nDist; $d++) {
            $di = safe_fetch(safe_r_sql("SELECT DiEnds, DiArrows, DiMaxpoints FROM DistanceInformation
                WHERE DiTournament = $tourId AND DiSession = " . intval($r->QuSession) . " AND DiDistance = $d AND DiType = 'Q'"));
            $arrows = $di ? intval($di->DiEnds) * intval($di->DiArrows) : 0;
            $max = ($di && $arrows > 0 && intval($di->DiMaxpoints) > 0) ? max(1, intval(round($di->DiMaxpoints / $arrows))) : 10;
            $s = $gold = $x = 0;
            for ($a = 0; $a < $arrows; $a++) {
                $v = trn_ghost_arrow($g['avg'], $g['spread'], $max);
                $s += $v;
                if ($v === $max) $gold++;
                elseif ($v === $max - 1) $x++;
            }
            $set[] = "QuD{$d}Score = $s, QuD{$d}Hits = $arrows, QuD{$d}Gold = $gold, QuD{$d}Xnine = $x, QuD{$d}Arrowstring = ''";
            $tot['s'] += $s; $tot['h'] += $arrows; $tot['g'] += $gold; $tot['x'] += $x;
        }
        safe_w_sql("UPDATE Qualifications INNER JOIN Entries ON EnId = QuId AND EnTournament = $tourId
            SET " . implode(', ', $set) . ", QuScore = {$tot['s']}, QuHits = {$tot['h']}, QuGold = {$tot['g']}, QuXnine = {$tot['x']},
                QuTimestamp = '" . date('Y-m-d H:i:s') . "'
            WHERE QuId = " . intval($r->EnId));
        $done++;
    }
    trn_recalc($tourId);
    return $done;
}
