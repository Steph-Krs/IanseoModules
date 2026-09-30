<?php
/**
 * What can be uploaded, and the request the core upload expects for it.
 *
 * The core page (Tournament/UploadResults.php) posts a form to
 * Tournament/UploadResults-upload.php; the scheduled task replays that form.
 * Only the lists the core page keeps ticked between two automatic uploads are
 * offered here: results and rankings. Start lists, statistics, scorecards,
 * PDF files and links are one-off uploads on the core page ("removeAfterUpload")
 * and have no place in a schedule.
 *
 * The core page offers an item only once it makes sense (brackets once the
 * shoot-offs are resolved, medals once someone has one). aus_items_catalog()
 * applies the same rules, and the scheduled task evaluates them again before
 * every upload: an item may be chosen in advance and is sent from the moment
 * ianseo would offer it, never before — an empty bracket is never published.
 *
 * The request is built from a fixed list of names, never from whatever was
 * stored: the core upload also accepts btnDelOnline, which deletes data online.
 */

const AUS_ITEM_LISTS = [
    'QualificationInd', 'QualificationTeam', 'EliminationInd', 'RobinInd', 'RobinTeam',
    'BracketsInd', 'BracketsTeam', 'FinalInd', 'FinalTeam',
];
const AUS_ITEM_FLAGS = ['IC', 'TC', 'MEDSTD', 'MEDLST'];

/**
 * Every item the competition could upload, with its availability right now.
 *
 * One query over Events; the per-event conditions mirror the core page.
 *
 * @param int $tour
 * @return array ['items' => list of [list, value, code, name, available], 'medals' => bool]
 */
function aus_items_catalog($tour) {
    $tour = (int)$tour;
    $q = safe_r_sql("SELECT EvCode, EvEventName, EvTeamEvent, EvElimType, EvElim1, EvElim2,
            EvFinalFirstPhase, EvShootOff, EvE1ShootOff, EvMedals,
            IF(EvTeamEvent=0,
                EXISTS(SELECT 1 FROM Individuals INNER JOIN Qualifications ON QuId=IndId
                    WHERE IndTournament=EvTournament AND IndEvent=EvCode),
                EXISTS(SELECT 1 FROM Teams WHERE TeTournament=EvTournament AND TeEvent=EvCode)) AS HasEntries,
            IF(EvMedals=0, 0, IF(EvTeamEvent=0,
                EXISTS(SELECT 1 FROM Individuals WHERE IndTournament=EvTournament AND IndEvent=EvCode
                    AND IndRankFinal IN (EvWinnerFinalRank, EvWinnerFinalRank+2)),
                EXISTS(SELECT 1 FROM Teams WHERE TeTournament=EvTournament AND TeEvent=EvCode
                    AND TeRankFinal IN (EvWinnerFinalRank, EvWinnerFinalRank+2)))) AS HasMedal
        FROM Events
        WHERE EvTournament=$tour AND EvCodeParentWinnerBranch=0
        ORDER BY EvTeamEvent, EvProgr");

    $items  = [];
    $medals = false;
    $add = function ($list, $value, $r, $available) use (&$items) {
        $items[] = [
            'list'      => $list,
            'value'     => $value,
            'code'      => $r->EvCode,
            'name'      => $r->EvEventName,
            'available' => (bool)$available,
        ];
    };

    while ($r = safe_fetch($q)) {
        if (!$r->HasEntries) continue;
        if ($r->HasMedal) $medals = true;

        $finals = $r->EvFinalFirstPhase > 0;
        $so     = (bool)$r->EvShootOff;
        $robin  = $r->EvElimType == 5;

        if (!$r->EvTeamEvent) {
            $add('QualificationInd', 'IQ' . $r->EvCode, $r, true);
            switch ((int)$r->EvElimType) {
                case 0:
                    break;
                case 3:
                case 4:
                    $add('EliminationInd', 'IP' . $r->EvCode . $r->EvElimType, $r, $so);
                    break;
                case 5:
                    $add('RobinInd', 'R0' . $r->EvCode, $r, $r->EvE1ShootOff);
                    break;
                default:
                    if ($r->EvElim1 > 0 || $r->EvElim2 > 0) $add('EliminationInd', 'IE' . $r->EvCode, $r, true);
            }
            if ($finals || $so) $add('BracketsInd', 'IB' . $r->EvCode, $r, $so);
            if ($finals || $so || $robin) $add('FinalInd', 'IF' . $r->EvCode, $r, $so || $robin);
        } else {
            $add('QualificationTeam', 'TQ' . $r->EvCode, $r, true);
            if ($robin) $add('RobinTeam', 'R1' . $r->EvCode, $r, $r->EvE1ShootOff);
            if ($finals) $add('BracketsTeam', 'TB' . $r->EvCode, $r, $so);
            if ($finals || $robin) $add('FinalTeam', 'TF' . $r->EvCode, $r, $finals ? $so : true);
        }
    }

    return ['items' => $items, 'medals' => $medals];
}

/**
 * Keep only well-formed names and values from a posted or stored selection.
 *
 * @param array $in Such as $_POST, or a decoded AsItems.
 * @return array ['QualificationInd' => [...], ..., 'IC' => 1, ...]
 */
function aus_items_clean(array $in) {
    $out = [];
    foreach (AUS_ITEM_LISTS as $list) {
        if (empty($in[$list]) || !is_array($in[$list])) continue;
        foreach ($in[$list] as $v) {
            $v = (string)$v;
            if (preg_match('/^[A-Za-z0-9_.\-]{3,24}$/', $v)) $out[$list][] = $v;
        }
        if (!empty($out[$list])) $out[$list] = array_values(array_unique($out[$list]));
    }
    foreach (AUS_ITEM_FLAGS as $flag) {
        if (!empty($in[$flag])) $out[$flag] = 1;
    }
    return $out;
}

/**
 * Number of items in a selection.
 *
 * @param array $sel
 * @return int
 */
function aus_items_count(array $sel) {
    $n = 0;
    foreach (AUS_ITEM_LISTS as $list) $n += count($sel[$list] ?? []);
    foreach (AUS_ITEM_FLAGS as $flag) $n += empty($sel[$flag]) ? 0 : 1;
    return $n;
}

/**
 * The form fields to post to the core upload, from a stored selection.
 *
 * @param int $tour
 * @param array $sel Stored selection (aus_items_clean() format).
 * @return array ['request' => fields, 'sent' => n, 'waiting' => [values], 'missing' => [values]]
 */
function aus_items_request($tour, array $sel) {
    $cat = aus_items_catalog($tour);
    $known = [];
    foreach ($cat['items'] as $it) $known[$it['list']][$it['value']] = $it['available'];

    $request = [];
    $waiting = [];
    $missing = [];
    foreach (AUS_ITEM_LISTS as $list) {
        foreach ($sel[$list] ?? [] as $v) {
            if (!isset($known[$list][$v])) {
                $missing[] = $v;
            } elseif (!$known[$list][$v]) {
                $waiting[] = $v;
            } else {
                $request[$list][] = $v;
            }
        }
    }
    foreach (AUS_ITEM_FLAGS as $flag) {
        if (empty($sel[$flag])) continue;
        if (($flag === 'MEDSTD' || $flag === 'MEDLST') && !$cat['medals']) {
            $waiting[] = $flag;
        } else {
            $request[$flag] = 1;
        }
    }

    return [
        'request' => $request,
        'sent'    => aus_items_count($request),
        'waiting' => $waiting,
        'missing' => $missing,
    ];
}

/**
 * Title of an item family, with the core's own wording and translations.
 *
 * @param string $list List name or flag.
 * @return string
 */
function aus_item_family_label($list) {
    $ind  = get_text('Individual');
    $team = get_text('Team');
    switch ($list) {
        case 'QualificationInd':  return get_text('Q-Session', 'Tournament') . ' - ' . $ind;
        case 'QualificationTeam': return get_text('Q-Session', 'Tournament') . ' - ' . $team;
        case 'EliminationInd':    return get_text('E-Session', 'Tournament') . ' - ' . $ind;
        case 'RobinInd':          return get_text('R-Session', 'Tournament') . ' - ' . $ind;
        case 'RobinTeam':         return get_text('R-Session', 'Tournament') . ' - ' . $team;
        case 'BracketsInd':       return get_text('Brackets') . ' - ' . $ind;
        case 'BracketsTeam':      return get_text('Brackets') . ' - ' . $team;
        case 'FinalInd':          return get_text('Rankings') . ' - ' . $ind;
        case 'FinalTeam':         return get_text('Rankings') . ' - ' . $team;
        case 'IC':                return get_text('ResultClass', 'Tournament') . ' - ' . $ind;
        case 'TC':                return get_text('ResultClass', 'Tournament') . ' - ' . $team;
        case 'MEDSTD':            return get_text('MedalStanding');
        case 'MEDLST':            return get_text('MedalList');
    }
    return $list;
}
