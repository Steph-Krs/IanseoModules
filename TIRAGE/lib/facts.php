<?php
/**
 * Previous-season facts for the commentators, read from an ianseo competition.
 *
 * A show can name the competition of the previous season (DwSource) and each of
 * its categories the team event it continues (DcEvent). A team whose club code
 * (DtClub) is found in that event then gets its season summarised: final rank,
 * matches won and lost, points per arrow, results stage by stage, qualification
 * of each stage, and the archers who shot for it.
 *
 * RANKINGS COME FROM THE CORE'S Rank CLASSES. Obj_RankFactory picks the class of
 * the competition's own rule set — for the French first division, the D1 classes
 * of Modules/Sets/FR — so the rank, the team components and the match results
 * are exactly the ones ianseo prints. No ranking query is rewritten here.
 *
 * NATIONAL RANKINGS ARE OPTIONAL. When the REPARTITION_EPREUVES module has
 * downloaded the national rankings, each archer gets their national rank and
 * average. Its tables are tested through information_schema first: they belong
 * to another module, may be absent, and a failing query ends the request.
 */

/**
 * Does a table exist in the current database?
 *
 * @param string $name
 * @return bool
 */
function tir_table_exists($name) {
    $q = safe_r_sql("SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . StrSafe_DB($name) . " LIMIT 1", false, true);
    return $q && safe_fetch($q);
}

/**
 * Competitions that can serve as the previous season, newest first.
 *
 * @return array Entries of ['id', 'code', 'name', 'date'].
 */
function tir_competitions() {
    $out = [];
    $q = safe_r_sql("SELECT ToId, ToCode, ToName, ToWhenFrom FROM Tournament ORDER BY ToWhenFrom DESC, ToId DESC");
    while ($r = safe_fetch($q)) {
        $out[] = ['id' => (int)$r->ToId, 'code' => $r->ToCode, 'name' => $r->ToName, 'date' => (string)$r->ToWhenFrom];
    }
    return $out;
}

/**
 * One competition, or null.
 *
 * @param int $src
 * @return array|null ['id', 'code', 'name', 'year'].
 */
function tir_source($src) {
    $src = (int)$src;
    if ($src <= 0) return null;
    $q = safe_r_sql("SELECT ToId, ToCode, ToName, YEAR(ToWhenFrom) AS Y FROM Tournament WHERE ToId=$src");
    $r = safe_fetch($q);
    return $r ? ['id' => (int)$r->ToId, 'code' => $r->ToCode, 'name' => $r->ToName, 'year' => (int)$r->Y] : null;
}

/**
 * Team events of a competition.
 *
 * An event whose code extends another one (FCLof, FCLdn after FCL) is a later
 * phase of the same team competition: it is marked as not main, and its matches
 * are counted with its parent's.
 *
 * @param int $src
 * @return array Entries of ['code', 'name', 'main'].
 */
function tir_source_events($src) {
    $src = (int)$src;
    $rows = [];
    $q = safe_r_sql("SELECT EvCode, EvEventName FROM Events WHERE EvTournament=$src AND EvTeamEvent=1 ORDER BY EvProgr, EvCode");
    while ($r = safe_fetch($q)) $rows[] = ['code' => $r->EvCode, 'name' => $r->EvEventName];

    foreach ($rows as &$e) {
        $e['main'] = true;
        foreach ($rows as $o) {
            if ($o['code'] !== $e['code'] && strpos($e['code'], $o['code']) === 0) { $e['main'] = false; break; }
        }
    }
    unset($e);
    return $rows;
}

/**
 * Final team ranking of some events, keyed by event then club code.
 *
 * @param int $src
 * @param array $events Event codes.
 * @param bool $components Include the archers of each team.
 * @return array [event => ['name' => …, 'items' => [clubCode => item]]]
 */
function tir_source_ranking($src, $events, $components) {
    global $CFG;
    $events = array_values(array_filter(array_unique($events)));
    if (!$src || !$events) return [];

    require_once $CFG->DOCUMENT_PATH . 'Common/Lib/Obj_RankFactory.php';
    $rank = Obj_RankFactory::create('FinalTeam', [
        'tournament' => (int)$src,
        'eventsR'    => $events,
        'components' => $components,
    ]);
    if (!$rank) return [];
    $rank->read();
    $data = $rank->getData();

    $out = [];
    foreach (($data['sections'] ?? []) as $code => $section) {
        if (!is_array($section)) continue;
        $out[$code] = ['name' => $section['meta']['descr'] ?? $code, 'items' => []];
        foreach (($section['items'] ?? []) as $item) {
            // A club's second team (sub-team 1, 2…) is not the team a draw lists.
            if ((int)($item['subteam'] ?? 0) !== 0) continue;
            $out[$code]['items'][(string)$item['countryCode']] = $item;
        }
    }
    return $out;
}

/**
 * Clubs of one event, for the editor's lists and the automatic matching.
 *
 * @param int $src
 * @param string $event
 * @return array Entries of ['code', 'name', 'rank'], by name.
 */
function tir_source_clubs($src, $event) {
    $ranking = tir_source_ranking($src, [$event], false);
    $out = [];
    foreach (($ranking[$event]['items'] ?? []) as $code => $item) {
        $out[] = ['code' => (string)$code, 'name' => (string)$item['countryName'], 'rank' => (int)$item['rank']];
    }
    usort($out, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return $out;
}

/**
 * Stage names of a competition, by date — the French D1 stores them.
 *
 * @param int $src
 * @return array ['dates' => [date => name], 'distances' => [n => name]]
 */
function tir_source_stages($src) {
    $out = ['dates' => [], 'distances' => []];
    if (!function_exists('getModuleParameter')) return $out;
    $i = 0;
    foreach ((array)getModuleParameter('FFTA', 'D1TourDates', [], (int)$src) as $key => $stage) {
        $i++;
        if (!is_array($stage)) continue;
        $name = trim((string)($stage['comp'] ?? ''));
        if ($name === '') $name = (string)($stage['date'] ?? $key);
        if (!empty($stage['date'])) $out['dates'][(string)$stage['date']] = $name;
        $out['distances'][$i] = $name;
    }
    return $out;
}

/**
 * Number of arrows actually shot in an arrow string.
 *
 * @param string $s ianseo's letter encoding, one character per arrow.
 * @return int
 */
function tir_arrow_count($s) {
    return (int)preg_match_all('/\S/', (string)$s);
}

/**
 * Round-robin match statistics of some events, keyed by parent event then club.
 *
 * @param int $src
 * @param array $events Parent event codes (the categories' events).
 * @return array [event => [clubCode => stats]]
 */
function tir_source_matches($src, $events) {
    global $CFG;
    $src = (int)$src;
    $events = array_values(array_filter(array_unique($events)));
    if (!$src || !$events) return [];

    // Each round-robin team event, attached to the longest category event it extends.
    $parents = [];
    $q = safe_r_sql("SELECT EvCode FROM Events WHERE EvTournament=$src AND EvTeamEvent=1 AND EvElimType=5");
    while ($r = safe_fetch($q)) {
        $best = '';
        foreach ($events as $e) {
            if (strpos($r->EvCode, $e) === 0 && mb_strlen($e) > mb_strlen($best)) $best = $e;
        }
        if ($best !== '') $parents[$r->EvCode] = $best;
    }
    if (!$parents) return [];

    $codes = [];
    $q = safe_r_sql("SELECT CoId, CoCode FROM Countries WHERE CoTournament=$src");
    while ($r = safe_fetch($q)) $codes[(int)$r->CoId] = (string)$r->CoCode;

    require_once $CFG->DOCUMENT_PATH . 'Common/Lib/Obj_RankFactory.php';
    $rank = Obj_RankFactory::create('Robin', ['tournament' => $src, 'team' => 1, 'events' => array_keys($parents)]);
    if (!$rank) return [];
    $rank->OnlyMatch = true;
    $rank->read();
    $data = $rank->getData();

    $stages = tir_source_stages($src);
    $out = [];
    foreach (($data['sections'] ?? []) as $evCode => $section) {
        $parent = $parents[$evCode] ?? null;
        if ($parent === null) continue;
        foreach (($section['levels'] ?? []) as $level => $lvl) {
            foreach (($lvl['matches'] ?? []) as $group) {
                foreach (($group['rounds'] ?? []) as $roundNo => $round) {
                    foreach (($round['items'] ?? []) as $m) {
                        $date  = (string)($m['scheduledDate'] ?? '');
                        $stage = (string)($round['place'] ?? '');
                        if ($stage === '') $stage = $stages['dates'][$date] ?? $date;
                        $order = $date . ' ' . ($m['scheduledTime'] ?? '') . sprintf(' %02d %03d', (int)$level, (int)$roundNo);
                        tir_count_match($out[$parent], $codes, $m, false, $stage, $order);
                        tir_count_match($out[$parent], $codes, $m, true, $stage, $order);
                    }
                }
            }
        }
    }

    foreach ($out as &$clubs) {
        foreach ($clubs as &$s) $s = tir_finish_match_stats($s);
    }
    unset($clubs, $s);
    return $out;
}

/**
 * Add one side of a match to a club's statistics.
 *
 * @param mixed $acc Accumulator for one event, by reference.
 * @param array $codes CoId to club code.
 * @param array $m Match item from the Robin rank class.
 * @param bool $opp Count the opponent's side instead.
 * @param string $stage
 * @param string $order Sort key: date, time, level, round.
 */
function tir_count_match(&$acc, $codes, $m, $opp, $stage, $order) {
    $me    = (int)($m[$opp ? 'oppItemId' : 'itemId'] ?? 0);
    $other = (int)($m[$opp ? 'itemId' : 'oppItemId'] ?? 0);
    if ($me <= 0 || $other <= 0 || !isset($codes[$me])) return;

    $won  = (int)($m[$opp ? 'oppWinner' : 'winner'] ?? 0) === 1;
    $lost = (int)($m[$opp ? 'winner' : 'oppWinner'] ?? 0) === 1;
    if (!$won && !$lost) return;   // not shot yet

    $club = $codes[$me];
    if (!isset($acc[$club])) {
        $acc[$club] = ['played' => 0, 'won' => 0, 'lost' => 0, 'soWon' => 0, 'soLost' => 0,
            'setsFor' => 0, 'setsAgainst' => 0, 'score' => 0, 'arrows' => 0, 'best' => 0,
            'stages' => [], 'results' => []];
    }
    $s = &$acc[$club];

    $score  = (int)($m[$opp ? 'oppScore' : 'score'] ?? 0);
    $arrows = tir_arrow_count($m[$opp ? 'oppArrowstring' : 'arrowstring'] ?? '');
    $tie    = (int)($m[$opp ? 'oppTie' : 'tie'] ?? 0);
    $oppTie = (int)($m[$opp ? 'tie' : 'oppTie'] ?? 0);

    $s['played']++;
    $s['won']  += $won ? 1 : 0;
    $s['lost'] += $won ? 0 : 1;
    $s['soWon']  += ($won && $tie === 1) ? 1 : 0;
    $s['soLost'] += (!$won && $oppTie === 1) ? 1 : 0;
    $s['setsFor']     += (int)($m[$opp ? 'oppSetScore' : 'setScore'] ?? 0);
    $s['setsAgainst'] += (int)($m[$opp ? 'setScore' : 'oppSetScore'] ?? 0);
    if ($arrows > 0) {
        $s['score']  += $score;
        $s['arrows'] += $arrows;
        $s['best'] = max($s['best'], round($score / $arrows, 2));
    }

    if (!isset($s['stages'][$stage])) {
        $s['stages'][$stage] = ['name' => $stage, 'played' => 0, 'won' => 0, 'lost' => 0, 'score' => 0, 'arrows' => 0, 'order' => $order];
    }
    $st = &$s['stages'][$stage];
    $st['played']++;
    $st['won']  += $won ? 1 : 0;
    $st['lost'] += $won ? 0 : 1;
    if ($arrows > 0) { $st['score'] += $score; $st['arrows'] += $arrows; }
    $st['order'] = min($st['order'], $order);

    $s['results'][] = [$order, $won ? 1 : 0];
}

/**
 * Turn raw counters into what a commentator reads.
 *
 * @param array $s
 * @return array
 */
function tir_finish_match_stats($s) {
    usort($s['results'], function ($a, $b) { return strcmp($a[0], $b[0]); });
    $seq = array_column($s['results'], 1);

    $streak = 0; $run = 0;
    foreach ($seq as $w) { $run = $w ? $run + 1 : 0; $streak = max($streak, $run); }

    $stages = array_values($s['stages']);
    usort($stages, function ($a, $b) { return strcmp($a['order'], $b['order']); });
    foreach ($stages as &$st) {
        $st['avg'] = $st['arrows'] ? round($st['score'] / $st['arrows'], 2) : null;
        unset($st['order'], $st['score'], $st['arrows']);
    }
    unset($st);

    return [
        'played'      => $s['played'],
        'won'         => $s['won'],
        'lost'        => $s['lost'],
        'pct'         => $s['played'] ? (int)round(100 * $s['won'] / $s['played']) : 0,
        'soWon'       => $s['soWon'],
        'soLost'      => $s['soLost'],
        'setsFor'     => $s['setsFor'],
        'setsAgainst' => $s['setsAgainst'],
        'avg'         => $s['arrows'] ? round($s['score'] / $s['arrows'], 2) : null,
        'best'        => $s['best'] ?: null,
        'streak'      => $streak,
        'last'        => array_slice($seq, -5),
        'stages'      => $stages,
    ];
}

/**
 * Division of each team event, to tell which national ranking an archer belongs to.
 *
 * @param int $src
 * @return array [event => division code]
 */
function tir_event_divisions($src) {
    $out = [];
    $q = safe_r_sql("SELECT EcCode, EcDivision FROM EventClass WHERE EcTournament=" . (int)$src . " AND EcTeamEvent=1 ORDER BY EcCode");
    while ($r = safe_fetch($q)) {
        if (!isset($out[$r->EcCode])) $out[$r->EcCode] = $r->EcDivision;
    }
    return $out;
}

/**
 * National scratch rankings of some archers, outdoor and indoor.
 *
 * @param array $wanted [licence => division code]
 * @return array [licence => ['ti' => row|null, 's' => row|null]]
 */
function tir_national_ranks($wanted) {
    if (!$wanted || !tir_table_exists('REP_Rangs') || !tir_table_exists('REP_Classements')) return [];

    // Words of the bow type in the federation's ranking labels, such as
    // "Arc Classique" or "Arc Nu": data to match, not text shown to anyone.
    $bows = ['CL' => 'classique', 'CO' => 'poulies', 'BB' => 'nu', 'TR' => 'chasse', 'LB' => 'droit'];

    $list = implode(',', array_map('StrSafe_DB', array_keys($wanted)));
    $q = safe_r_sql("SELECT CrLicence, CrRang, CrMoyenne, CcAnnee, CcDiscipline, CcArme, CcNbArchers
        FROM REP_Rangs INNER JOIN REP_Classements ON CcId=CrClassement
        WHERE CrLicence IN ($list) AND CcCategorie='Scratch' AND CcDiscipline IN ('TI','S')
        ORDER BY CcAnnee DESC, CrRang", false, true);
    if (!$q) return [];

    $out = [];
    while ($r = safe_fetch($q)) {
        $lic  = (string)$r->CrLicence;
        $disc = $r->CcDiscipline === 'TI' ? 'ti' : 's';
        if (isset($out[$lic][$disc])) continue;
        $bow = $bows[$wanted[$lic] ?? ''] ?? '';
        if ($bow !== '' && mb_stripos((string)$r->CcArme, $bow) === false) continue;
        $out[$lic][$disc] = [
            'year' => (int)$r->CcAnnee,
            'rank' => (int)$r->CrRang,
            'of'   => (int)$r->CcNbArchers,
            'avg'  => (int)$r->CrMoyenne,
        ];
    }
    return $out;
}

/**
 * Previous-season facts for every team of a show.
 *
 * @param array $show
 * @return array ['source' => competition or null, 'national' => bool, 'teams' => [teamId => facts]]
 */
function tir_facts($show) {
    $source = tir_source($show['source']);
    $result = ['source' => $source, 'national' => false, 'teams' => []];
    if (!$source) return $result;

    $cats   = tir_categories($show['id'], true);
    $events = [];
    foreach ($cats as $c) if ($c['event'] !== '') $events[] = $c['event'];
    $allEvents = array_column(array_filter(tir_source_events($source['id']), function ($e) { return $e['main']; }), 'code');

    $ranking   = tir_source_ranking($source['id'], array_unique(array_merge($events, $allEvents)), true);
    $matches   = tir_source_matches($source['id'], $events);
    $stages    = tir_source_stages($source['id']);
    $divisions = tir_event_divisions($source['id']);

    $wanted = [];
    foreach ($events as $e) {
        foreach (($ranking[$e]['items'] ?? []) as $item) {
            foreach (($item['athletes'] ?? []) as $a) {
                if (!empty($a['bib'])) $wanted[(string)$a['bib']] = $divisions[$e] ?? '';
            }
        }
    }
    $national = tir_national_ranks($wanted);
    $result['national'] = tir_table_exists('REP_Rangs');

    foreach ($cats as $c) {
        foreach ($c['teams'] as $t) {
            if ($t['club'] === '') continue;
            $facts = ['event' => $c['event'], 'found' => false, 'elsewhere' => []];

            foreach ($allEvents as $e) {
                if ($e === $c['event'] || !isset($ranking[$e]['items'][$t['club']])) continue;
                $facts['elsewhere'][] = ['event' => $ranking[$e]['name'], 'rank' => (int)$ranking[$e]['items'][$t['club']]['rank']];
            }

            $item = $c['event'] !== '' ? ($ranking[$c['event']]['items'][$t['club']] ?? null) : null;
            if ($item) {
                $facts['found']     = true;
                $facts['eventName'] = $ranking[$c['event']]['name'];
                $facts['rank']      = (int)$item['rank'];
                $facts['of']        = count($ranking[$c['event']]['items']);
                $facts['points']    = (int)$item['qualScore'];
                $facts['matches']   = $matches[$c['event']][$t['club']] ?? null;

                $facts['qualification'] = [];
                foreach (($item['stages'] ?? []) as $d => $q) {
                    if (!is_array($q) || ($q['score'] ?? '') === '' || $q['score'] === null) continue;
                    $facts['qualification'][] = [
                        'name'  => $stages['distances'][(int)$d] ?? (string)$d,
                        'score' => (int)$q['score'],
                        'rank'  => (int)$q['rank'],
                        'bonus' => (int)$q['bonus'],
                    ];
                }

                $facts['archers'] = [];
                foreach (($item['athletes'] ?? []) as $a) {
                    if (empty($a['id'])) continue;
                    $lic = (string)$a['bib'];
                    $facts['archers'][] = [
                        'name'   => trim((string)$a['athlete']),
                        'gender' => (int)$a['gender'],
                        'ti'     => $national[$lic]['ti'] ?? null,
                        's'      => $national[$lic]['s'] ?? null,
                    ];
                }
            }
            $result['teams'][$t['id']] = $facts;
        }
    }
    // An object in JSON even when empty or when the ids happen to follow each other.
    $result['teams'] = (object)$result['teams'];
    return $result;
}
