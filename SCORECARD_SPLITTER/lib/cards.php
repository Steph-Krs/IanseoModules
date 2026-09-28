<?php
/**
 * The scorecards of the open competition, read from its entries and split into files.
 *
 * ianseo's own printout starts from every position a session can hold: its query
 * enumerates targets times positions with one SELECT per position, joined by
 * UNION ALL (80 000 of them for a session of 9 999 targets of 8 archers), then
 * keeps or drops the empty ones. Here the reading starts from the entries, so
 * only archers who really are on a target are read, and each card is sent to the
 * file it belongs to: its club's, or its archer's.
 *
 * What is printed follows the core's rules. A position outside the session's
 * targets, or beyond its number of archers per target, is not printed, exactly
 * as in the core's printout; the summary shown on the page counts such
 * positions, so that nobody disappears without a word.
 */

/**
 * Layout of every qualification session of the open competition.
 *
 * @return array Session order => [order, descr, perTarget, first, last].
 */
function scs_sessions() {
    $out = [];
    foreach (GetSessions('Q') as $s) {
        $out[(int)$s->SesOrder] = [
            'order'     => (int)$s->SesOrder,
            'descr'     => (string)$s->Descr,
            'perTarget' => max(1, (int)$s->SesAth4Target),
            'first'     => (int)$s->SesFirstTarget,
            'last'      => (int)$s->SesFirstTarget + (int)$s->SesTar4Session - 1,
        ];
    }
    return $out;
}

/**
 * What the module needs to know about the open competition itself.
 *
 * @return array [distances => number of distances, field => true for a field or 3D competition].
 */
function scs_competition() {
    $rs = safe_r_sql("SELECT ToNumDist, ToCategory FROM Tournament WHERE ToId = " . (int)$_SESSION['TourId']);
    $r  = safe_fetch($rs);
    return [
        'distances' => $r ? max(1, (int)$r->ToNumDist) : 1,
        // The core's printout tells field and 3D apart the same way (ToCategory & 12).
        'field'     => $r ? (((int)$r->ToCategory & 12) !== 0) : false,
    ];
}

/**
 * The print options of a request, checked against the open competition.
 *
 * The field names are the core's printout's own wherever an option is the same
 * (ScoreDist, ScorePageHeaderFooter, ScoreFlags…), so the two pages can be read
 * side by side.
 *
 * @param array $src $_GET or $_POST.
 * @param array $sessions Output of scs_sessions().
 * @param int $numDist Number of distances of the competition.
 * @return array
 */
function scs_options(array $src, array $sessions, $numDist) {
    $chosen = [];
    foreach ((array)($src['Sessions'] ?? []) as $s) {
        if (isset($sessions[(int)$s])) $chosen[(int)$s] = (int)$s;
    }
    ksort($chosen);

    // Like the core: distance 0 is the scorecard with no distance on it, and it
    // is what gets printed when nothing is chosen.
    $distances = [];
    foreach ((array)($src['ScoreDist'] ?? []) as $d) {
        $d = (int)$d;
        if ($d >= 0 && $d <= min(8, (int)$numDist)) $distances[$d] = $d;
    }
    ksort($distances);
    if (!$distances || isset($distances[0])) $distances = [0 => 0];

    return [
        'sessions'  => array_values($chosen),
        'distances' => array_values($distances),
        'mode'      => (($src['Mode'] ?? '') === 'archer') ? 'archer' : 'club',
        'page'      => !empty($src['ScorePageHeaderFooter']),
        'header'    => !empty($src['ScoreHeader']),
        'logos'     => !empty($src['ScoreLogos']),
        'flags'     => !empty($src['ScoreFlags']),
        'info'      => !empty($src['GetArcInfo']),
        // The core offers the barcode only with its Barcodes module, which reads it.
        'barcode'   => !empty($src['ScoreBarcode']) && module_exists('Barcodes'),
        'hide'      => !empty($src['HideTarget']),
    ];
}

/**
 * Would the core's printout print this position?
 *
 * @param int $session
 * @param int $target
 * @param string $letter Position on the target, A for the first.
 * @param array $sessions Output of scs_sessions().
 * @return bool
 */
function scs_printable($session, $target, $letter, array $sessions) {
    if (!isset($sessions[$session]) || $letter === '') return false;
    $s    = $sessions[$session];
    $slot = ord($letter) - 65;
    return $target >= $s['first'] && $target <= $s['last'] && $slot >= 0 && $slot < $s['perTarget'];
}

/**
 * Name of the file a card goes to, without its extension.
 *
 * The club code, or the licence number, as the files are named today. A code
 * that has to be cleaned to make a file name could meet another code once
 * cleaned, so it carries a checksum of the original; a clean code is used as is.
 *
 * @param array $card A card, or any row with EnId, EnCode and CoCode.
 * @param string $mode 'club' or 'archer'.
 * @return string
 */
function scs_file_name(array $card, $mode) {
    if ($mode === 'archer') {
        $code = (string)$card['EnCode'];
        if ($code === '') return '_no_licence_' . (int)$card['EnId'];
    } else {
        $code = (string)$card['CoCode'];
        if ($code === '') return '_no_club';
    }
    $clean = preg_replace('/[^A-Za-z0-9_\-]/', '_', $code);
    return $clean === $code ? $code : $clean . '_' . sprintf('%08x', crc32($code));
}

/**
 * Every printable scorecard of the chosen sessions, ready for ScorePDF::DrawScoreNew().
 *
 * The column names are those of the core's query (GetScoreBySessionQuery() in
 * Common/Lib/ScorecardsLib.php), so a card drawn here holds what the core would
 * draw. Arrows and scores are left out: these scorecards are printed blank.
 *
 * @param int[] $orders Session orders.
 * @param array $sessions Output of scs_sessions().
 * @return array List of cards, ordered by club, archer, session, target and position.
 */
function scs_read_cards(array $orders, array $sessions) {
    $orders = array_filter(array_map('intval', $orders));
    if (!$orders) return [];
    $tour = (int)$_SESSION['TourId'];

    $ends  = '';
    $joins = '';
    for ($i = 1; $i <= 8; $i++) {
        $ends  .= ", d$i.DiEnds AS NumEnds$i, d$i.DiArrows AS NumArrows$i";
        // The same table once per distance: the aliases are what tells them apart.
        $joins .= " LEFT JOIN DistanceInformation d$i ON d$i.DiTournament = EnTournament AND d$i.DiType = 'Q'"
                . " AND d$i.DiSession = QuSession AND d$i.DiDistance = $i";
    }

    // Countries is a LEFT JOIN, unlike in the core's query: an archer without a
    // club still gets scorecards, in a file of their own.
    // A missing target face would give NULL scoring characters, and the core then
    // queries the database again for every end drawn: IFNULL keeps the fallback
    // to the competition's characters in this one query.
    $sql = "SELECT EnId, TRIM(EnCode) AS EnCode, IFNULL(CoCode, '') AS CoCode, IFNULL(CoName, '') AS CoName,
            QuSession AS Session, QuTarget AS AtTarget, UPPER(QuLetter) AS Letter,
            CONCAT(QuTarget, UPPER(QuLetter)) AS tNo, '' AS Dist, EnDob AS DoB, EdEmail AS Email,
            CONCAT(EnFirstName, ' ', EnName) AS Ath, IF(CoId IS NULL, '', CONCAT(CoCode, ' - ', CoName)) AS Noc,
            EnDivision AS `Div`, EnClass AS Cls, CONCAT(EnDivision, ' ', EnClass) AS Cat, SesName,
            IF(IFNULL(TfGolds, '') = '', ToGolds, TfGolds) AS Golds,
            IF(IFNULL(TfXNine, '') = '', ToXNine, TfXNine) AS XNine,
            IF(IFNULL(TfGoldsChars, '') = '', ToGoldsChars, TfGoldsChars) AS GoldsChars,
            IF(IFNULL(TfXNineChars, '') = '', ToXNineChars, TfXNineChars) AS XNineChars,
            '' AS D0, Td1 AS D1, Td2 AS D2, Td3 AS D3, Td4 AS D4, Td5 AS D5, Td6 AS D6, Td7 AS D7, Td8 AS D8,
            d1.DiEnds AS NumEnds0, d1.DiArrows AS NumArrows0 $ends
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        INNER JOIN Tournament ON ToId = EnTournament
        INNER JOIN Session ON SesTournament = EnTournament AND SesOrder = QuSession AND SesType = 'Q'
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        LEFT JOIN TargetFaces ON TfTournament = EnTournament AND TfId = EnTargetFace
        LEFT JOIN ExtraData ON EdId = EnId AND EdType = 'E'
        LEFT JOIN TournamentDistances ON TdType = ToType AND TdTournament = ToId
            AND CONCAT(TRIM(EnDivision), TRIM(EnClass)) LIKE TdClasses
        $joins
        WHERE EnTournament = $tour AND QuTarget > 0 AND QuSession IN (" . implode(',', $orders) . ")
        ORDER BY CoCode, EnFirstName, EnName, EnCode, QuSession, QuTarget, Letter";

    $cards = [];
    $seen  = [];
    $rs = safe_r_sql($sql);
    while ($r = safe_fetch($rs)) {
        // A category matching two distance rules would bring the same entry twice.
        if (isset($seen[$r->EnId])) continue;
        $seen[$r->EnId] = true;
        if (!scs_printable((int)$r->Session, (int)$r->AtTarget, (string)$r->Letter, $sessions)) continue;
        $cards[] = (array)$r;
    }
    safe_free_result($rs);
    return $cards;
}

/**
 * Split the cards into files: one per club, or one per archer.
 *
 * A file is a list of targets, in the order the cards come: by archer within a
 * club, by session and target for one archer. Each target keeps only the cards
 * of the file's owner. The other positions of its pages are drawn as blank
 * grids, so a club never receives another club's archers, and every card keeps
 * the place the core's printout gives it on the sheet.
 *
 * @param array $cards Output of scs_read_cards().
 * @param string $mode 'club' or 'archer'.
 * @return array File name => list of ['session', 'target', 'cards' => [position index => card]].
 */
function scs_split(array $cards, $mode) {
    $files = [];
    foreach ($cards as $card) {
        $file = scs_file_name($card, $mode);
        $key  = $card['Session'] . '-' . $card['AtTarget'];
        $slot = ord($card['Letter']) - 65;
        if (!isset($files[$file][$key])) {
            $files[$file][$key] = ['session' => (int)$card['Session'], 'target' => (int)$card['AtTarget'], 'cards' => []];
        }
        // Two entries on one position of the same file: only one card fits the
        // place, and the summary has already reported the conflict.
        if (!isset($files[$file][$key]['cards'][$slot])) {
            $files[$file][$key]['cards'][$slot] = $card;
        }
    }
    foreach ($files as $name => $targets) {
        $files[$name] = array_values($targets);
    }
    return $files;
}

/**
 * Counts for the page: per club, per session, and what will not be printed.
 *
 * Read with a query of its own, much lighter than the cards: only who is where.
 *
 * @param array $sessions Output of scs_sessions().
 * @return array [clubs, totals, outside, twice, unplaced].
 */
function scs_summary(array $sessions) {
    $tour   = (int)$_SESSION['TourId'];
    $result = [
        'clubs'    => [],
        'totals'   => ['archers' => 0, 'cards' => 0, 'sessions' => array_fill_keys(array_keys($sessions), 0)],
        'outside'  => 0,
        'twice'    => 0,
        'unplaced' => 0,
    ];
    if (!$sessions) return $result;

    $rs = safe_r_sql("SELECT EnId, TRIM(EnCode) AS EnCode, IFNULL(CoCode, '') AS CoCode, IFNULL(CoName, '') AS CoName,
            QuSession, QuTarget, UPPER(QuLetter) AS Letter
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        WHERE EnTournament = $tour AND QuTarget > 0 AND QuSession IN (" . implode(',', array_keys($sessions)) . ")
        ORDER BY CoCode, CoName");

    $archers   = [];
    $positions = [];
    while ($r = safe_fetch($rs)) {
        $row = (array)$r;
        if (!scs_printable((int)$r->QuSession, (int)$r->QuTarget, (string)$r->Letter, $sessions)) {
            $result['outside']++;
            continue;
        }
        $pos = $r->QuSession . '-' . $r->QuTarget . '-' . $r->Letter;
        $positions[$pos] = ($positions[$pos] ?? 0) + 1;

        $file = scs_file_name($row, 'club');
        if (!isset($result['clubs'][$file])) {
            $result['clubs'][$file] = [
                'file'     => $file,
                'code'     => $r->CoCode,
                'name'     => $r->CoName,
                'archers'  => [],
                'cards'    => 0,
                'sessions' => array_fill_keys(array_keys($sessions), 0),
            ];
        }
        $archer = scs_file_name($row, 'archer');
        $result['clubs'][$file]['archers'][$archer] = true;
        $result['clubs'][$file]['cards']++;
        $result['clubs'][$file]['sessions'][(int)$r->QuSession]++;
        $result['totals']['sessions'][(int)$r->QuSession]++;
        $result['totals']['cards']++;
        $archers[$archer] = true;
    }
    safe_free_result($rs);

    foreach ($result['clubs'] as $file => $club) {
        $result['clubs'][$file]['archers'] = count($club['archers']);
    }
    $result['totals']['archers'] = count($archers);
    foreach ($positions as $n) {
        if ($n > 1) $result['twice'] += $n - 1;
    }

    // Archers still waiting for a target, whom no printout includes yet.
    $rs = safe_r_sql("SELECT COUNT(*) AS n FROM Entries INNER JOIN Qualifications ON QuId = EnId
        WHERE EnTournament = $tour AND EnAthlete = 1 AND (QuTarget = 0 OR QuSession = 0)");
    if ($r = safe_fetch($rs)) $result['unplaced'] = (int)$r->n;

    return $result;
}
