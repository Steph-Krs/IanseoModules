<?php
/**
 * Qualification scorecards: whose card it is, what its grid looks like, which values
 * the archer's target face allows, and what ianseo already holds for it.
 *
 * Read-only. Everything is bounded to the open competition through Entries, since
 * Qualifications has no competition column of its own.
 */

/**
 * Split the text of a scorecard barcode, as the core's barcode check does.
 *
 * The core prints "<licence>-<division>-<class>-<distance>", a licence starting with
 * "_" being printed "UU". A licence may itself hold a dash, which makes five parts.
 * A keyboard-wedge scanner may type the dash as an apostrophe.
 *
 * @param string $text
 * @return array|null [code, div, cls, dist] or null when it cannot be a scorecard code.
 */
function ocr_parse_code($text) {
    $text = trim(str_replace(['*', "'"], ['', '-'], (string)$text));
    $p = explode('-', $text);
    if (count($p) === 5) {
        $p = [$p[0] . '-' . $p[1], $p[2], $p[3], $p[4]];
    }
    if (count($p) < 3 || $p[0] === '' || $p[1] === '' || $p[2] === '') return null;
    // bytes: the prefix test is on ASCII characters the core itself writes.
    $code = substr($p[0], 0, 2) === 'UU' ? '_' . substr($p[0], 2) : $p[0];
    return ['code' => $code, 'div' => $p[1], 'cls' => $p[2], 'dist' => isset($p[3]) ? (int)$p[3] : 0];
}

/**
 * Archers of the open competition matching a scorecard code or a target.
 *
 * @param string $by 'code' or 'target'.
 * @param string $value The barcode text, or a target such as "12A" (or "12" for the whole target).
 * @param int $session Qualification session, 0 for any.
 * @return array List of ['enId', 'name', 'target', 'session', 'div', 'cls', 'code'].
 */
function ocr_find_entries($by, $value, $session = 0) {
    $tour = (int)$_SESSION['TourId'];
    $where = '';
    if ($by === 'code') {
        $p = ocr_parse_code($value);
        if (!$p) return [];
        $where = 'EnCode = ' . StrSafe_DB($p['code']) . ' AND EnDivision = ' . StrSafe_DB($p['div'])
            . ' AND EnClass = ' . StrSafe_DB($p['cls']);
    } else {
        if (!preg_match('/^\s*0*(\d{1,4})\s*([A-Za-z]?)\s*$/', (string)$value, $m)) return [];
        $where = 'QuTarget = ' . (int)$m[1];
        if ($m[2] !== '') $where .= ' AND QuLetter = ' . StrSafe_DB(mb_strtoupper($m[2]));
    }
    if ($session > 0) $where .= ' AND QuSession = ' . (int)$session;

    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnDivision, EnClass, QuSession, QuTarget, QuLetter
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        WHERE EnTournament = $tour AND EnAthlete = 1 AND QuTarget > 0 AND $where
        ORDER BY QuSession, QuTarget, QuLetter");
    $out = [];
    while ($r = safe_fetch($rs)) {
        $out[] = [
            'enId'    => (int)$r->EnId,
            // EnFirstName holds the family name in ianseo, EnName the given name.
            'name'    => trim(mb_strtoupper($r->EnFirstName) . ' ' . $r->EnName),
            'target'  => (int)$r->QuTarget . $r->QuLetter,
            'session' => (int)$r->QuSession,
            'div'     => $r->EnDivision,
            'cls'     => $r->EnClass,
            'code'    => $r->EnCode,
        ];
    }
    safe_free_result($rs);
    return $out;
}

/**
 * Every archer of the open competition who has a target, with the header of their
 * scorecard as the core prints it (ScorecardsLib): the target without leading
 * zeros, the name as stored ("family given", not upper-cased), division and class.
 * The page reads the printed header of a photo and finds the archer among these.
 *
 * @return array List of ['enId', 'session', 'target', 'name', 'cat'].
 */
function ocr_roster() {
    $tour = (int)$_SESSION['TourId'];
    $rs = safe_r_sql("SELECT EnId, EnFirstName, EnName, EnDivision, EnClass, QuSession, QuTarget, QuLetter
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        WHERE EnTournament = $tour AND EnAthlete = 1 AND QuTarget > 0
        ORDER BY QuSession, QuTarget, QuLetter");
    $out = [];
    while ($r = safe_fetch($rs)) {
        $out[] = [
            'enId'    => (int)$r->EnId,
            'session' => (int)$r->QuSession,
            'target'  => ltrim($r->QuTarget . $r->QuLetter, '0'),
            'name'    => $r->EnFirstName . ' ' . $r->EnName,
            'cat'     => $r->EnDivision . ' ' . $r->EnClass,
        ];
    }
    safe_free_result($rs);
    return $out;
}

/**
 * The scorecard layouts the open competition can print, for the live view to tell a
 * whole grid from a part of one: every count of ends and arrows of its qualification
 * distances, with one count column or two (the labels of the target faces).
 *
 * @return array [{ends, arrows, golds, tourType}]
 */
function ocr_layouts() {
    $tour = (int)$_SESSION['TourId'];
    $rs = safe_r_sql("SELECT ToType, ToGolds, ToXNine FROM Tournament WHERE ToId = $tour");
    $t = safe_fetch($rs);
    safe_free_result($rs);
    if (!$t) return [];
    $golds = [(string)$t->ToGolds !== (string)$t->ToXNine];
    $rs = safe_r_sql("SELECT DISTINCT IF(IFNULL(TfGolds, '') = '', " . StrSafe_DB((string)$t->ToGolds) . ", TfGolds) AS G,
            IF(IFNULL(TfXNine, '') = '', " . StrSafe_DB((string)$t->ToXNine) . ", TfXNine) AS X
        FROM TargetFaces WHERE TfTournament = $tour");
    while ($r = safe_fetch($rs)) $golds[] = (string)$r->G !== (string)$r->X;
    safe_free_result($rs);
    $golds = array_values(array_unique($golds));
    $out = [];
    $rs = safe_r_sql("SELECT DISTINCT DiEnds, DiArrows FROM DistanceInformation
        WHERE DiTournament = $tour AND DiType = 'Q' AND DiEnds > 0 AND DiArrows > 0");
    while ($r = safe_fetch($rs)) {
        foreach ($golds as $g) {
            $out[] = ['ends' => (int)$r->DiEnds, 'arrows' => (int)$r->DiArrows, 'golds' => $g, 'tourType' => (int)$t->ToType];
        }
    }
    safe_free_result($rs);
    return $out;
}

/**
 * Everything the page needs about one archer's scorecard at one distance.
 *
 * @param int $enId
 * @param int $dist 1 to the number of distances.
 * @return array|null Null when the archer is not in the open competition, or the
 *                    distance does not exist.
 */
function ocr_card($enId, $dist) {
    global $LetterPoint;
    $tour  = (int)$_SESSION['TourId'];
    $enId  = (int)$enId;
    $dist  = (int)$dist;
    if ($dist < 1 || $dist > 8) return null;

    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnDivision, EnClass, EnStatus,
            IFNULL(CoCode, '') AS CoCode, IFNULL(CoName, '') AS CoName,
            QuSession, QuTarget, QuLetter, QuConfirm, QuSigned,
            QuD{$dist}Arrowstring AS Arrows, QuD{$dist}Score AS Score, QuD{$dist}Hits AS Hits,
            QuD{$dist}Gold AS Gold, QuD{$dist}Xnine AS Xnine,
            ToType, ToNumDist,
            IF(IFNULL(TfGolds, '') = '', ToGolds, TfGolds) AS GoldsLabel,
            IF(IFNULL(TfXNine, '') = '', ToXNine, TfXNine) AS XNineLabel,
            IF(IFNULL(TfGoldsChars, '') = '', ToGoldsChars, TfGoldsChars) AS GoldsChars,
            IF(IFNULL(TfXNineChars, '') = '', ToXNineChars, TfXNineChars) AS XNineChars,
            DiEnds, DiArrows, Td{$dist} AS DistName
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        INNER JOIN Tournament ON ToId = EnTournament
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        LEFT JOIN TargetFaces ON TfTournament = EnTournament AND TfId = EnTargetFace
        LEFT JOIN DistanceInformation ON DiTournament = EnTournament AND DiType = 'Q'
            AND DiSession = QuSession AND DiDistance = $dist
        LEFT JOIN TournamentDistances ON TdType = ToType AND TdTournament = ToId
            AND CONCAT(TRIM(EnDivision), TRIM(EnClass)) LIKE TdClasses
        WHERE EnTournament = $tour AND EnId = $enId
        LIMIT 1");
    $r = safe_fetch($rs);
    safe_free_result($rs);
    if (!$r || $dist > (int)$r->ToNumDist) return null;

    $ends   = (int)$r->DiEnds;
    $arrows = (int)$r->DiArrows;
    $count  = $ends * $arrows;

    // Values the archer's target face allows at this distance, in the core's order, each
    // with the core's weight (w: X above 10, M at 0), the order a value is offered in.
    $values = [];
    foreach (GetGoodLettersFromDist($enId, $dist) as $l) {
        if (!isset($LetterPoint[$l])) continue;
        $values[] = [
            'l' => $l,
            'p' => $LetterPoint[$l]['P'],
            'n' => (int)$LetterPoint[$l]['N'],
            'w' => (int)$LetterPoint[$l]['W'],
            'g' => mb_strpos((string)$r->GoldsChars, $l) !== false,
            'x' => mb_strpos((string)$r->XNineChars, $l) !== false,
        ];
    }

    // What ianseo holds, arrow by arrow, as printed on a scorecard; '' when not entered.
    // Its letters too: a value two letters print alike is written back as ianseo holds it.
    $held = [];
    $letters = '';
    $string = (string)$r->Arrows;
    for ($i = 0; $i < $count; $i++) {
        // bytes: an arrowstring holds one ASCII letter per arrow.
        $c = $i < strlen($string) ? $string[$i] : ' ';
        $held[] = $c === ' ' ? '' : DecodeFromLetter($c);
        $letters .= $c;
    }

    $bit = 1 << $dist;
    return [
        'archer' => [
            'enId'    => (int)$r->EnId,
            'code'    => $r->EnCode,
            'name'    => trim(mb_strtoupper($r->EnFirstName) . ' ' . $r->EnName),
            'club'    => trim($r->CoCode . ' ' . $r->CoName),
            'div'     => $r->EnDivision,
            'cls'     => $r->EnClass,
            'session' => (int)$r->QuSession,
            'target'  => (int)$r->QuTarget . $r->QuLetter,
            'status'  => (int)$r->EnStatus,
        ],
        'dist'      => $dist,
        'distName'  => (string)$r->DistName,
        'structure' => [
            'ends'     => $ends,
            'arrows'   => $arrows,
            'tourType' => (int)$r->ToType,
            // The core prints a single count column when both labels are the same.
            'golds'    => (string)$r->GoldsLabel !== (string)$r->XNineLabel,
            'goldsLabel' => (string)$r->GoldsLabel,
            'xnineLabel' => (string)$r->XNineLabel,
        ],
        'values' => $values,
        'ianseo' => [
            'arrows'    => $held,
            'letters'   => $letters,
            'entered'   => count(array_filter($held, function ($v) { return $v !== ''; })),
            'score'     => (int)$r->Score,
            'hits'      => (int)$r->Hits,
            'gold'      => (int)$r->Gold,
            'xnine'     => (int)$r->Xnine,
            'confirmed' => ((int)$r->QuConfirm & $bit) !== 0,
            'signed'    => ((int)$r->QuSigned & $bit) !== 0,
        ],
    ];
}
