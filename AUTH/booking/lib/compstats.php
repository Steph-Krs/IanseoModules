<?php
/**
 * lib/compstats.php — an archer's statistics on ONE competition: score end by end (target by
 * target on a field / 3D course), average arrow, spread of the arrows over the values, success
 * zone, compared with the competition, the category, or an archer of the competition.
 *
 * Read from the arrows ianseo keeps (Qualifications.QuD<n>Arrowstring, one letter per arrow, the
 * core's $LetterPoint giving its printed value, its points and its order). On a course
 * (Tournament.ToCategory 4 field, 8 3D) the core stores the arrows BY TARGET, whatever the target
 * the archer started from (Qualification/WriteScoreCard.php): position i is target i + 1, which is
 * what an archer compares (the same target), not the end number.
 *
 * Scores keyed in as totals only leave no arrows: nothing to show end by end (said on the page).
 */

if (defined('BK_COMPSTATS_LOADED')) return;
define('BK_COMPSTATS_LOADED', true);

require_once __DIR__ . '/competition.php';
require_once('Common/Lib/ArrTargets.inc.php');   // $LetterPoint

/** Letter of an arrow => [printed value, points, order]. */
function bk_cs_letters()
{
    static $map = null;
    if ($map !== null) return $map;
    $map = array();
    foreach (($GLOBALS['LetterPoint'] ?? array()) as $l => $v) $map[(string) $l] = array((string) $v['P'], intval($v['N']), intval($v['W']));
    return $map;
}

/** Columns read for a series of entries. */
function bk_cs_cols()
{
    $c = array();
    for ($d = 1; $d <= 8; $d++) $c[] = "QuD{$d}Arrowstring";
    return 'EnId, EnCode, EnFirstName, EnName, EnDivision, EnClass, QuSession, QuTarget, QuScore, QuClRank, '
        . 'DivDescription, ClDescription, ' . implode(', ', $c);
}

/**
 * The entry $enId of the archer $licence (their own shoot, whoever registered it), with what the
 * page needs of its competition; null when it is not theirs.
 */
function bk_cs_entry($enId, $licence)
{
    $r = safe_fetch(safe_r_sql("SELECT " . bk_cs_cols() . ", EnTournament, ToId, ToName, ToWhere, ToWhenFrom, ToWhenTo,
            ToNumDist, ToCategory, ToType, ToTypeName, ToTypeSubRule, BcShowResults,
            TfName, TfT1, TfT2, TfT3, TfT4, TfT5, TfT6, TfT7, TfT8
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        INNER JOIN Tournament ON ToId = EnTournament
        LEFT JOIN BookingCompetitions ON BcTournament = ToId
        LEFT JOIN TargetFaces ON TfTournament = EnTournament AND TfId = EnTargetFace
        LEFT JOIN Divisions ON DivTournament = EnTournament AND DivId = EnDivision
        LEFT JOIN Classes ON ClTournament = EnTournament AND ClId = EnClass
        WHERE EnId = " . intval($enId) . " AND EnCode = " . StrSafe_DB(bk_clean_licence($licence)) . " AND EnAthlete = 1"));
    return $r ?: null;
}

/**
 * The archer's own shoots in a competition that have arrows to show (scores keyed in arrow by
 * arrow), in the competition's order: what the documents page links to.
 */
function bk_cs_mine($tourId, $licence)
{
    $c = array();
    for ($d = 1; $d <= 8; $d++) $c[] = "QuD{$d}Arrowstring";
    $out = array();
    $rs = safe_r_sql("SELECT EnId, QuSession, DivDescription, ClDescription
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Divisions ON DivTournament = EnTournament AND DivId = EnDivision
        LEFT JOIN Classes ON ClTournament = EnTournament AND ClId = EnClass
        WHERE EnTournament = " . intval($tourId) . " AND EnCode = " . StrSafe_DB(bk_clean_licence($licence)) . " AND EnAthlete = 1
          AND TRIM(CONCAT(" . implode(', ', $c) . ")) <> ''
        ORDER BY QuSession, EnId");
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/**
 * May the archer see the other archers of the competition next to theirs? Once the results are
 * public: the competition is over, or the organiser shows its results.
 */
function bk_cs_can_compare($row)
{
    return bk_is_finished($row->ToWhenTo) || intval($row->BcShowResults ?? 0) === 1;
}

/**
 * Ends and arrows per end of every distance of every departure of a competition, as the core's
 * score entry reads them (DistanceInformation): [session][distance] => [ends, arrows per end].
 */
function bk_cs_layout($tourId)
{
    $tourId = intval($tourId);
    $out = array();
    $rs = safe_r_sql("SELECT DiSession, DiDistance, DiEnds, DiArrows FROM DistanceInformation
        WHERE DiTournament = $tourId AND DiType = 'Q' AND DiEnds > 0 AND DiArrows > 0");
    while ($r = safe_fetch($rs)) $out[intval($r->DiSession)][intval($r->DiDistance)] = array(intval($r->DiEnds), intval($r->DiArrows));
    return $out;
}

/**
 * One entry read into ends: [distance => ['ends' => [points|null…], 'letters' => [letter => count]]]
 * — an end with no arrow is null (not shot, or keyed in as a total). Distances never shot are left
 * out. $layout: bk_cs_layout().
 */
function bk_cs_parse($row, $nDist, $layout)
{
    $L = bk_cs_letters();
    $out = array();
    for ($d = 1; $d <= max(1, min(8, intval($nDist))); $d++) {
        $as = (string) ($row->{"QuD{$d}Arrowstring"} ?? '');
        if (trim($as) === '') continue;
        $lay = $layout[intval($row->QuSession)][$d] ?? null;
        if (!$lay) {
            // Not described: one end per 3 arrows indoors would be a guess; the whole string as
            // ends of 6, which every outdoor round and most others use.
            // bytes: one ASCII letter per arrow
            $lay = array(intval(ceil(strlen(rtrim($as)) / 6)), 6);
        }
        list($nEnds, $per) = $lay;
        $ends = array();
        $letters = array();
        for ($e = 0; $e < $nEnds; $e++) {
            // bytes: one ASCII letter per arrow
            $chunk = substr($as, $e * $per, $per);
            $pts = 0; $n = 0;
            // bytes: one ASCII letter per arrow
            for ($i = 0; $i < strlen($chunk); $i++) {
                $c = $chunk[$i];
                if (!isset($L[$c])) continue;
                $pts += $L[$c][1];
                $n++;
                $letters[$c] = ($letters[$c] ?? 0) + 1;
            }
            $ends[] = $n > 0 ? $pts : null;
        }
        $out[$d] = array('ends' => $ends, 'letters' => $letters, 'per' => $per);
    }
    return $out;
}

/**
 * Figures of one shoot or of a group of shoots, for the page: points per end (the average over
 * the shoots that shot it, for a group), average arrow, arrows counted, spread of the arrows by
 * printed value ([value, order, share %]). $parsed: list of bk_cs_parse() results.
 */
function bk_cs_figures(array $parsed)
{
    $L = bk_cs_letters();
    $sum = array(); $cnt = array();
    $letters = array();
    foreach ($parsed as $p) {
        foreach ($p as $d => $one) {
            foreach ($one['ends'] as $e => $v) {
                if ($v === null) continue;
                $sum[$d][$e] = ($sum[$d][$e] ?? 0) + $v;
                $cnt[$d][$e] = ($cnt[$d][$e] ?? 0) + 1;
            }
            foreach ($one['letters'] as $c => $n) $letters[$c] = ($letters[$c] ?? 0) + $n;
        }
    }
    $ends = array();
    foreach ($sum as $d => $row) {
        $max = max(array_keys($row));
        for ($e = 0; $e <= $max; $e++) $ends[$d][$e] = isset($row[$e]) ? round($row[$e] / $cnt[$d][$e], 2) : null;
    }
    $arrows = array_sum($letters);
    $points = 0;
    $byValue = array();
    foreach ($letters as $c => $n) {
        $points += $L[$c][1] * $n;
        $k = $L[$c][0];
        if (!isset($byValue[$k])) $byValue[$k] = array('v' => $k, 'w' => $L[$c][2], 'n' => 0);
        $byValue[$k]['w'] = max($byValue[$k]['w'], $L[$c][2]);
        $byValue[$k]['n'] += $n;
    }
    usort($byValue, function ($a, $b) { return $b['w'] - $a['w']; });
    foreach ($byValue as &$bv) { $bv['p'] = $arrows ? round(100 * $bv['n'] / $arrows, 2) : 0; unset($bv['n']); }
    unset($bv);
    return array('ends' => $ends, 'avg' => $arrows ? round($points / $arrows, 3) : null, 'arrows' => $arrows,
        'spread' => $byValue, 'count' => count($parsed));
}

/** Entries of competition $tourId with arrows ($where: more conditions on Entries). */
function bk_cs_entries($tourId, $where = '')
{
    $tourId = intval($tourId);
    $have = array();
    for ($d = 1; $d <= 8; $d++) $have[] = "TRIM(QuD{$d}Arrowstring) <> ''";
    $rs = safe_r_sql("SELECT " . bk_cs_cols() . " FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Divisions ON DivTournament = EnTournament AND DivId = EnDivision
        LEFT JOIN Classes ON ClTournament = EnTournament AND ClId = EnClass
        WHERE EnTournament = $tourId AND EnAthlete = 1 AND (" . implode(' OR ', $have) . ")" . $where . "
        ORDER BY EnDivision, EnClass, EnFirstName, EnName");
    $out = array();
    while ($r = safe_fetch($rs)) $out[] = $r;
    return $out;
}

/** Distances of the entry as labels ("Distance 1 — 18 m"), from TournamentDistances. */
function bk_cs_dist_labels($row)
{
    $out = array();
    $td = safe_fetch(safe_r_sql("SELECT * FROM TournamentDistances
        WHERE TdTournament = " . intval($row->EnTournament) . " AND " . StrSafe_DB($row->EnDivision . $row->EnClass) . " LIKE TdClasses
        ORDER BY LENGTH(REPLACE(REPLACE(TdClasses, '%', ''), '_', '')) DESC LIMIT 1"));
    for ($d = 1; $d <= 8; $d++) {
        $name = $td ? trim((string) ($td->{'Td' . $d} ?? '')) : '';
        $out[$d] = bk_t('CsDistN', $d) . ($name !== '' ? ' — ' . $name : '');
    }
    return $out;
}

/**
 * The face drawn for the success zone, from the core's target type (Targets.TarId) of the archer's
 * face on the first distance with arrows:
 *  'wa'    full face, 10 rings (1, 3, 5, 24, 25)
 *  'wa5'   reduced face, rings 5 to 10 (9 = outdoor 80 cm reduced, 30, 32)
 *  'wa6'   reduced face, rings 6 to 10 (10, 29, 31)
 *  'tri'   triple spot, three faces of rings 6 to 10 (2, 4: the core's indoor small faces, used for
 *          the trispots — the face's name decides when it says otherwise)
 *  'field' field face, 6 rings (6)
 *  '3d'    an animal with its zones 11, 10, 8, 5 (8, 18, 23; any 3D course)
 *  'beursault' the beursault card, zones 4 to 1 (27; any beursault, ToType 50)
 *  ''      nothing drawn (nature, run, unknown).
 */
function bk_cs_face_kind($row)
{
    $cat = intval($row->ToCategory);
    if ($cat === 8) return '3d';
    if (intval($row->ToType) === 50) return 'beursault';
    if (intval($row->ToType) === 48) return '';
    $type = 0;
    for ($d = 1; $d <= 8 && !$type; $d++) {
        if (trim((string) ($row->{"QuD{$d}Arrowstring"} ?? '')) !== '') $type = intval($row->{"TfT{$d}"} ?? 0);
    }
    if (!$type) return $cat === 4 ? 'field' : 'wa';
    $name = mb_strtolower((string) ($row->TfName ?? ''));
    if (in_array($type, array(2, 4), true)) {
        return (strpos($name, 'tri') !== false || strpos($name, 'spot') !== false || $name === '') ? 'tri' : 'wa6';
    }
    if (in_array($type, array(9, 30, 32), true)) return 'wa5';
    if (in_array($type, array(10, 29, 31), true)) return 'wa6';
    if (in_array($type, array(1, 3, 5, 24, 25), true)) return 'wa';
    if ($type === 6) return 'field';
    if (in_array($type, array(8, 18, 23), true)) return '3d';
    if ($type === 27) return 'beursault';
    return '';
}

/** One archer of the competition, for the comparison picked on the page. */
function bk_cs_one($tourId, $enId, $nDist, $layout)
{
    $rows = bk_cs_entries($tourId, " AND EnId = " . intval($enId));
    if (!$rows) return null;
    $r = $rows[0];
    $f = bk_cs_figures(array(bk_cs_parse($r, $nDist, $layout)));
    $f['name'] = trim($r->EnFirstName . ' ' . $r->EnName);
    return $f;
}
