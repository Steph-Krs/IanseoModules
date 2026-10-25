<?php
/**
 * Qualification scores read from a scorecard, written into ianseo: arrows and totals of one
 * distance (when none are entered yet), or what is entered confirmed as the core's barcode
 * check does (QuConfirm), the operator's corrections written first.
 *
 * Every write is bound to the open competition through Entries (Qualifications has no
 * competition column). Ranks are then calculated again by the core's own classes, as an
 * import from ISK does.
 */

require_once $CFG->DOCUMENT_PATH . 'Common/Lib/ArrTargets.inc.php';
require_once $CFG->DOCUMENT_PATH . 'Common/Fun_Sessions.inc.php';
require_once $CFG->DOCUMENT_PATH . 'Common/Lib/Obj_RankFactory.php';
require_once $CFG->DOCUMENT_PATH . 'Qualification/Fun_Qualification.local.inc.php';

/**
 * An archer's distance in the open competition, as the writes need it.
 *
 * @param int $enId
 * @param int $dist 1 to 8.
 * @return object|null Null when the archer is not in the open competition, or the distance
 *                     does not exist.
 */
function ocr_score_row($enId, $dist) {
    $tour = (int)$_SESSION['TourId'];
    $enId = (int)$enId;
    $dist = (int)$dist;
    if ($dist < 1 || $dist > 8) return null;
    $rs = safe_r_sql("SELECT EnId, EnDivision, EnClass, EnCountry, EnIndClEvent, EnIndFEvent, EnTeamClEvent,
            EnTeamFEvent + EnTeamMixEvent AS TeamFinals, ToElabTeam != 127 AS MakeTeams, ToLocRule, ToNumDist,
            QuConfirm, QuD{$dist}Arrowstring AS Arrows, QuD{$dist}Score AS Score, QuD{$dist}Gold AS Gold,
            QuD{$dist}Xnine AS Xnine,
            IF(IFNULL(TfGoldsChars, '') = '', ToGoldsChars, TfGoldsChars) AS GoldsChars,
            IF(IFNULL(TfXNineChars, '') = '', ToXNineChars, TfXNineChars) AS XNineChars,
            DiEnds, DiArrows
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        INNER JOIN Tournament ON ToId = EnTournament
        LEFT JOIN TargetFaces ON TfTournament = EnTournament AND TfId = EnTargetFace
        LEFT JOIN DistanceInformation ON DiTournament = EnTournament AND DiType = 'Q'
            AND DiSession = QuSession AND DiDistance = $dist
        WHERE EnTournament = $tour AND EnId = $enId
        LIMIT 1");
    $r = safe_fetch($rs);
    safe_free_result($rs);
    if (!$r || $dist > (int)$r->ToNumDist || !(int)$r->DiEnds || !(int)$r->DiArrows) return null;
    return $r;
}

/**
 * The arrows sent, as an arrowstring of the archer's face: one letter per arrow, a space
 * for none. Null when its length or a letter is not the distance's.
 *
 * @param object $row ocr_score_row()'s.
 * @param int $dist
 * @param string $letters
 * @return string|null
 */
function ocr_score_letters($row, $dist, $letters) {
    $count = (int)$row->DiEnds * (int)$row->DiArrows;
    // bytes: an arrowstring holds one ASCII letter per arrow.
    if (strlen($letters) !== $count) return null;
    // The core gives the letters 1 to 9 as numbers.
    $good = array_map('strval', GetGoodLettersFromDist((int)$row->EnId, $dist));
    for ($i = 0; $i < $count; $i++) {
        if ($letters[$i] !== ' ' && !in_array($letters[$i], $good, true)) return null;
    }
    return $letters;
}

/**
 * How many arrows ianseo holds otherwise than these letters, those the operator corrected
 * left out.
 *
 * @param object $row
 * @param string $letters
 * @param int[] $edited The arrows the operator corrected (0-based).
 * @param bool $blanks True: an arrow ianseo does not hold counts as held otherwise (to
 *                     validate, every arrow must be entered); false: it is free (an import
 *                     completes what is entered).
 * @return int
 */
function ocr_score_off($row, $letters, $edited, $blanks) {
    $count = (int)$row->DiEnds * (int)$row->DiArrows;
    // bytes: one ASCII letter per arrow, padded with spaces for the arrows not entered.
    $was = str_pad((string)$row->Arrows, $count);
    $off = 0;
    for ($i = 0; $i < $count; $i++) {
        // bytes: one ASCII letter per arrow.
        if ($was[$i] === $letters[$i] || in_array($i, $edited, true)) continue;
        if ($blanks || $was[$i] !== ' ') $off++;
    }
    return $off;
}

/**
 * Whether ianseo holds every arrow of the distance.
 *
 * @param object $row
 * @return bool
 */
function ocr_score_whole($row) {
    $count = (int)$row->DiEnds * (int)$row->DiArrows;
    // bytes: one ASCII letter per arrow, padded with spaces for the arrows not entered.
    return strpos(str_pad((string)$row->Arrows, $count), ' ') === false;
}

/**
 * The distance's totals for these arrows: what they make, but a total the archer left
 * blank, which counts 0 (the operator said so: no recount).
 *
 * @param object $row
 * @param string $letters
 * @param array $zero Totals left blank: any of 'score', 'gold', 'xnine'.
 * @return array [score, gold, xnine, hits]
 */
function ocr_score_totals($row, $letters, $zero) {
    list($score, $gold, $xnine) = ValutaArrowStringGX($letters, $row->GoldsChars, $row->XNineChars);
    if (in_array('score', $zero, true)) $score = 0;
    if (in_array('gold', $zero, true)) $gold = 0;
    if (in_array('xnine', $zero, true)) $xnine = 0;
    // bytes: one ASCII letter per arrow, a space for none.
    $hits = strlen(str_replace(' ', '', $letters));
    return [(int)$score, (int)$gold, (int)$xnine, $hits];
}

/**
 * Write one distance's arrows and totals, the archer's totals summed again, and the ranks
 * calculated again.
 *
 * @param object $row
 * @param int $dist
 * @param string $letters
 * @param array $zero
 */
function ocr_score_write($row, $dist, $letters, $zero) {
    $tour = (int)$_SESSION['TourId'];
    $enId = (int)$row->EnId;
    list($score, $gold, $xnine, $hits) = ocr_score_totals($row, $letters, $zero);
    $set = "QuD{$dist}Arrowstring = " . StrSafe_DB($letters) . ", QuD{$dist}Score = $score, QuD{$dist}Gold = $gold,
        QuD{$dist}Xnine = $xnine, QuD{$dist}Hits = $hits, QuTimestamp = " . StrSafe_DB(date('Y-m-d H:i:s'));
    safe_w_sql('UPDATE Qualifications INNER JOIN Entries ON EnId = QuId SET ' . $set . ' WHERE EnTournament = ' . $tour . ' AND EnId = ' . $enId);
    // Apart: the sums read the distances' columns as they stand once written.
    $sums = 'QuScore = QuD1Score + QuD2Score + QuD3Score + QuD4Score + QuD5Score + QuD6Score + QuD7Score + QuD8Score,
        QuGold = QuD1Gold + QuD2Gold + QuD3Gold + QuD4Gold + QuD5Gold + QuD6Gold + QuD7Gold + QuD8Gold,
        QuXnine = QuD1Xnine + QuD2Xnine + QuD3Xnine + QuD4Xnine + QuD5Xnine + QuD6Xnine + QuD7Xnine + QuD8Xnine,
        QuHits = QuD1Hits + QuD2Hits + QuD3Hits + QuD4Hits + QuD5Hits + QuD6Hits + QuD7Hits + QuD8Hits';
    safe_w_sql('UPDATE Qualifications INNER JOIN Entries ON EnId = QuId SET ' . $sums . ' WHERE EnTournament = ' . $tour . ' AND EnId = ' . $enId);
    if ($row->ToLocRule === 'LANC') CalculateDropWeight($enId, $row->GoldsChars);
    runJack('QualArrUpdate', $tour, ['Dist' => $dist, 'Index' => 0, 'Id' => $enId, 'Point' => 0, 'TourId' => $tour]);
    ocr_score_ranks($row, $dist);
}

/** The distance confirmed, as the core's barcode check does. */
function ocr_score_confirm($row, $dist) {
    $tour = (int)$_SESSION['TourId'];
    $enId = (int)$row->EnId;
    $bit = 1 << (int)$dist;
    safe_w_sql('UPDATE Qualifications INNER JOIN Entries ON EnId = QuId SET QuConfirm = QuConfirm | ' . $bit . ' WHERE EnTournament = ' . $tour . ' AND EnId = ' . $enId);
}

/**
 * The ranks one archer's new scores change, by the core's classes, as an import from ISK
 * calculates them: the category, the teams, the events and their shoot-offs.
 *
 * @param object $row
 * @param int $dist
 */
function ocr_score_ranks($row, $dist) {
    $tour = (int)$_SESSION['TourId'];
    $enId = (int)$row->EnId;
    $category = $row->EnDivision . $row->EnClass;
    if ((int)$row->EnIndClEvent) {
        Obj_RankFactory::create('DivClass', ['tournament' => $tour, 'events' => $category, 'dist' => $dist])->calculate();
        Obj_RankFactory::create('DivClass', ['tournament' => $tour, 'events' => $category, 'dist' => 0])->calculate();
    }
    if ((int)$row->MakeTeams && (int)$row->EnTeamClEvent) MakeTeams(null, $category, $tour);
    if ((int)$row->EnIndFEvent) {
        $rs = safe_r_sql("SELECT DISTINCT IndEvent FROM Individuals
            INNER JOIN Entries ON EnId = IndId AND EnTournament = IndTournament
            WHERE IndId = $enId AND IndTournament = $tour");
        while ($r = safe_fetch($rs)) {
            Obj_RankFactory::create('Abs', ['tournament' => $tour, 'events' => $r->IndEvent, 'dist' => $dist])->calculate();
            Obj_RankFactory::create('Abs', ['tournament' => $tour, 'events' => $r->IndEvent, 'dist' => 0])->calculate();
            ResetShootoff($r->IndEvent, 0, 0, $tour);
            runJack('QRRankUpdate', $tour, ['Event' => $r->IndEvent, 'Team' => 0, 'TourId' => $tour]);
        }
        safe_free_result($rs);
    }
    if ((int)$row->MakeTeams && (int)$row->TeamFinals) MakeTeamsAbs(null, $row->EnDivision, $row->EnClass, $tour);
}
