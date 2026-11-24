<?php
/**
 * Trainings created from a real World Archery competition.
 *
 * World Archery publishes, for the competitions whose results it runs, the
 * categories, the qualification of every athlete (score, golds, X/9: no arrows)
 * and every match of the brackets arrow by arrow, individual and team. Its
 * phases and match numbers are ianseo's own (World Archery runs ianseo).
 *
 * A training made from such a competition is a competition of the core's
 * international set (target outdoor, indoor, field or 3D) with the events of
 * the real competition, the match formats of its brackets, and every athlete
 * and team as simulated competitors (lib/ghosts.php, GhKind 1). The coach adds
 * the archers of the training: they slot into the real field.
 *
 * Qualification: each athlete's real score, golds and X/9, spread over arrows of
 * the competition's faces, shown at the pace of the training's archers.
 *
 * Duels, for an athlete (or team) of the real competition — "reality first":
 *   1. the two sides really met: the arrows of that match;
 *   2. the athlete shot this phase: their real arrows of that phase;
 *   3. otherwise the athlete has gone further than in reality: the arrows of
 *      this phase of the one who eliminated them, or of the one who eliminated
 *      that one, and so on up the bracket (the champion shot every phase, the
 *      bronze match is shot by a semi-finalist of that chain);
 *   4. otherwise (a bye in reality, for instance): their own arrows of the
 *      nearest phase they shot;
 *   5. otherwise: arrows drawn among their own arrows of the competition.
 * A set match stops once decided: ends missing from the arrows taken (a match
 * won 6-0 in three ends) are drawn among the athlete's own arrows.
 */

/** World Archery's public API. */
const TRN_WA_API = 'https://api.worldarchery.org/v3/';

/**
 * ianseo competition types (international set) of each World Archery discipline: the
 * single round and its highest score, then the double round taken when the real scores
 * do not fit the single one (championships shoot 2 x 24 field or 3D targets).
 */
const TRN_WA_TYPES = [
    1 => [3, 720, 1],     // 70m round, then the 1440 round
    2 => [6, 600, 6],     // 18m
    3 => [9, 432, 10],    // field 12+12, then 24+24
    4 => [11, 528, 13],   // 3D one round, then two
];

/**
 * The ianseo type for a competition: the single round, the double one when a real
 * qualification score is beyond what the single round allows.
 *
 * @param int $disc World Archery discipline.
 * @param array $iquals Qualification items of the API.
 * @return int 0 when the discipline is not handled.
 */
function trn_wa_type($disc, $iquals) {
    $t = TRN_WA_TYPES[intval($disc)] ?? null;
    if (!$t) return 0;
    $best = 0;
    foreach ($iquals as $cat) foreach ($cat['Results'] ?? [] as $r) $best = max($best, intval($r['Score'] ?? 0));
    return $best > $t[1] ? $t[2] : $t[0];
}

/**
 * A call to World Archery's API.
 *
 * @param string $path E.g. "COMPETITIONS/".
 * @param array $params
 * @return array|null Decoded answer, null when it cannot be read.
 */
function trn_wa_get($path, $params = []) {
    $url = TRN_WA_API . $path . ($params ? '?' . http_build_query($params) : '');
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'ianseo-training', CURLOPT_ENCODING => '']);
        $body = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $body = false;
    } else {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 45, 'user_agent' => 'ianseo-training']]));
    }
    if ($body === false || $body === '') return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * Lower case without accents, to compare names.
 *
 * @param string $s
 * @return string
 */
function trn_wa_fold($s) {
    $s = mb_strtolower((string)$s);
    if (class_exists('Transliterator')) {
        $t = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        if ($t) $s = $t->transliterate($s);
    }
    return $s;
}

/**
 * Competitions with results published by World Archery, filtered.
 *
 * World Archery filters on dates, country and results itself; level, discipline
 * and name are filtered here, on what it sent.
 *
 * @param array $f ['from' => Y-m-d, 'to' => Y-m-d, 'country' => NOC, 'level' => 1-8, 'disc' => 1-4, 'name' => text]
 * @return array|null List of competitions (ID, Name, Place, Country, DFrom, DTo, ComDis, ComLevel), newest first; null when unreachable.
 */
function trn_wa_search($f) {
    $p = ['RBP' => 1000, 'WithResults' => 1];
    if (!empty($f['from'])) $p['StartDate'] = $f['from'];
    if (!empty($f['to'])) $p['EndDate'] = $f['to'];
    if (!empty($f['country'])) $p['Country'] = mb_strtoupper($f['country']);
    $data = trn_wa_get('COMPETITIONS/', $p);
    if (!$data || !isset($data['items']) || !is_array($data['items'])) return null;
    $name = trn_wa_fold(trim((string)($f['name'] ?? '')));
    $out = [];
    foreach ($data['items'] as $c) {
        if (!empty($c['IsCancelled'])) continue;
        if (!isset(TRN_WA_TYPES[intval($c['ComDis'] ?? 0)])) continue;
        if (!empty($f['level']) && intval($c['ComLevel'] ?? 0) !== intval($f['level'])) continue;
        if (!empty($f['disc']) && intval($c['ComDis'] ?? 0) !== intval($f['disc'])) continue;
        if ($name !== '' && mb_strpos(trn_wa_fold(($c['Name'] ?? '') . ' ' . ($c['Place'] ?? '') . ' ' . ($c['CountryName'] ?? '')), $name) === false) continue;
        $out[] = ['ID' => intval($c['ID']), 'Name' => (string)$c['Name'], 'Place' => (string)($c['Place'] ?? ''),
            'Country' => (string)($c['Country'] ?? ''), 'CountryName' => (string)($c['CountryName'] ?? ''),
            'DFrom' => (string)($c['DFrom'] ?? ''), 'DTo' => (string)($c['DTo'] ?? ''),
            'ComDis' => intval($c['ComDis']), 'ComLevel' => intval($c['ComLevel'] ?? 0)];
    }
    usort($out, function ($a, $b) { return strcmp($b['DFrom'], $a['DFrom']); });
    return $out;
}

/**
 * Everything World Archery publishes on a competition's results.
 *
 * @param int $compId
 * @return array|null ['cats', 'iquals', 'imatches', 'tquals', 'tmatches'] (the API's items), null when unreachable.
 */
function trn_wa_fetch($compId) {
    $out = [];
    $paths = ['cats' => 'CATEGORIES/', 'iquals' => 'INDIVIDUALQUALIFICATIONS/', 'imatches' => 'INDIVIDUALMATCHES/',
        'tquals' => 'TEAMQUALIFICATIONS/', 'tmatches' => 'TEAMMATCHES/'];
    foreach ($paths as $k => $path) {
        $d = trn_wa_get($path, ['CompId' => intval($compId), 'RBP' => 10000]);
        if ($d === null) return null;
        $out[$k] = isset($d['items']) && is_array($d['items']) && !isset($d['items']['error']) ? $d['items'] : [];
    }
    return $out;
}

/**
 * Data of the World Archery competition behind a training.
 *
 * @param int $tourId
 * @param string $kind As in trn_wa_fetch().
 * @return array
 */
function trn_wa_data($tourId, $kind) {
    static $cache = [];
    $k = intval($tourId) . '|' . $kind;
    if (!isset($cache[$k])) {
        $r = safe_fetch(safe_r_sql("SELECT WdData FROM TrainingWaData WHERE WdTournament = " . intval($tourId) . " AND WdKind = " . StrSafe_DB($kind)));
        $d = $r ? json_decode((string)$r->WdData, true) : null;
        $cache[$k] = is_array($d) ? $d : [];
    }
    return $cache[$k];
}

/**
 * Real qualification of an athlete.
 *
 * @param int $tourId
 * @param string $waId
 * @return array|null ['score', 'gold', 'xnine', 'gh' => what World Archery counts as gold ("10+X"), 'xh' => as X/9 ("X")]
 */
function trn_wa_qual($tourId, $waId) {
    static $idx = [];
    $tourId = intval($tourId);
    if (!isset($idx[$tourId])) {
        $idx[$tourId] = [];
        foreach (trn_wa_data($tourId, 'iquals') as $cat) {
            foreach ($cat['Results'] ?? [] as $r) {
                $id = (string)($r['Athlete']['Id'] ?? '');
                if ($id !== '' && !isset($idx[$tourId][$id])) {
                    $idx[$tourId][$id] = ['score' => intval($r['Score'] ?? 0), 'gold' => intval($r['Gold'] ?? 0), 'xnine' => intval($r['Xnine'] ?? 0),
                        'gh' => (string)($cat['GoldHeader'] ?? ''), 'xh' => (string)($cat['XnineHeader'] ?? '')];
                }
            }
        }
    }
    return $idx[$tourId][(string)$waId] ?? null;
}

/**
 * The real brackets of a competition, by event and by athlete (or team).
 *
 * @param int $tourId
 * @param int $team
 * @return array code => ['by' => [who => [phase => ['arr', 'tb', 'opp', 'win', 'bye']]], 'loss' => [who => opp], 'pool' => [who => [tokens]]]
 */
function trn_wa_index($tourId, $team) {
    static $cache = [];
    require_once 'Common/Lib/Fun_Phases.inc.php';
    $k = intval($tourId) . '|' . intval($team);
    if (isset($cache[$k])) return $cache[$k];
    $out = [];
    foreach (trn_wa_data($tourId, $team ? 'tmatches' : 'imatches') as $cat) {
        $code = (string)($cat['Code'] ?? '');
        $lossPhase = [];
        foreach ($cat['Matches'] ?? [] as $m) {
            // World Archery gives the event's phase (48, 24…), the grid of the core its power of two.
            $phase = intval(valueFirstPhase(intval($m['Phase'] ?? 0)));
            $c = [$m['Competitor1'] ?? [], $m['Competitor2'] ?? []];
            $who = [];
            foreach ($c as $i => $x) $who[$i] = $team ? (string)($x['NOC'] ?? '') : (string)($x['Athlete']['Id'] ?? '');
            foreach ($c as $i => $x) {
                if ($who[$i] === '') continue;
                $arr = array_map('trim', explode(',', (string)($x['Arr'] ?? '')));
                $tb = array_values(array_filter(array_map('trim', explode(',', (string)($x['ArrTB'] ?? ''))), 'strlen'));
                $bye = !empty($x['Bye']) || $who[1 - $i] === '';
                $out[$code]['by'][$who[$i]][$phase] = ['arr' => $arr, 'tb' => $tb, 'opp' => $who[1 - $i], 'win' => !empty($x['WinLose']), 'bye' => $bye];
                foreach (array_merge($arr, $tb) as $t) if ($t !== '') $out[$code]['pool'][$who[$i]][] = $t;
                // The match that put them out of the main bracket: the first one lost.
                if (!$bye && empty($x['WinLose']) && $who[1 - $i] !== '' && (!isset($lossPhase[$who[$i]]) || $phase > $lossPhase[$who[$i]])) {
                    $lossPhase[$who[$i]] = $phase;
                    $out[$code]['loss'][$who[$i]] = $who[1 - $i];
                }
            }
        }
    }
    return $cache[$k] = $out;
}

/**
 * Arrows of an athlete (or team) of the real competition in a match of the
 * training, by the rules of "reality first" (header of this file).
 *
 * @param int $tourId
 * @param int $team
 * @param string $event Event code, the same as World Archery's category code.
 * @param int $phase
 * @param string $who Athlete id, or NOC of a team.
 * @param string $opp The opponent's, when it is also from the real competition.
 * @param int $n Arrows of the match.
 * @param int $tbCount Shoot-off arrows to draw.
 * @param array $letters Face letters.
 * @param callable $rnd
 * @return array ['a', 't', 'c' => closest flag by shoot-off arrow, 'src']
 */
function trn_wa_match_arrows($tourId, $team, $event, $phase, $who, $opp, $n, $tbCount, $letters, $rnd) {
    $idx = trn_wa_index($tourId, $team)[$event] ?? ['by' => [], 'loss' => [], 'pool' => []];
    $mine = $idx['by'][$who] ?? [];
    $src = null;
    $how = '';
    $shot = function ($x) { return !$x['bye'] && count(array_filter($x['arr'], 'strlen')) > 0; };

    if ($opp !== '') {
        foreach ($mine as $ph => $x) {
            if ($x['opp'] === $opp && $shot($x)) { $src = $x; $how = 'met'; break; }
        }
    }
    if (!$src && isset($mine[$phase]) && $shot($mine[$phase])) {
        $src = $mine[$phase];
        $how = 'own';
    }
    if (!$src) {
        $seen = [$who => true];
        for ($x = $idx['loss'][$who] ?? ''; $x !== '' && !isset($seen[$x]); $x = $idx['loss'][$x] ?? '') {
            $seen[$x] = true;
            if (isset($idx['by'][$x][$phase]) && $shot($idx['by'][$x][$phase])) {
                $src = $idx['by'][$x][$phase];
                $how = 'chain';
                break;
            }
        }
    }
    if (!$src && $mine) {
        $best = null;
        foreach ($mine as $ph => $x) {
            if (!$shot($x)) continue;
            $d = abs(log(max(1, $ph) + 1) - log(max(1, $phase) + 1));
            if ($best === null || $d < $best) { $best = $d; $src = $x; }
        }
        if ($src) $how = 'near';
    }

    $pool = $idx['pool'][$who] ?? [];
    if (!$pool && $src) $pool = array_values(array_filter(array_merge($src['arr'], $src['tb']), 'strlen'));
    $pick = function () use (&$pool, $rnd, $letters) {
        if ($pool) return $pool[intval($rnd() * count($pool))];
        // Nobody to take arrows from: about nine tenths of the face.
        $l = trn_arrows_for_total($letters, 1, intval(round(0.9 * trn_face_max($letters))), $rnd);
        return (string)($GLOBALS['LetterPoint'][$l]['P'] ?? 'M');
    };
    if (!$src) $how = 'pool';

    $tokens = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $src['arr'][$i] ?? '';
        $tokens[] = $t !== '' ? $t : $pick();
    }
    $tb = [];
    for ($i = 0; $i < $tbCount; $i++) {
        $t = $src['tb'][$i] ?? '';
        $tb[] = $t !== '' ? $t : $pick();
    }
    $a = trn_letters_from_print($tokens, $letters);
    $t = trn_letters_from_print($tb, $letters);
    // The arrow judged closest to the centre in reality (a star) keeps winning a tie.
    $c = '';
    for ($i = 0; $i < $tbCount; $i++) $c .= strpos((string)($src['tb'][$i] ?? ''), '*') !== false ? '1' : '0';
    // An arrow the face does not know (a value written differently): drawn again among the face's.
    $fix = function ($s) use ($letters, $rnd) {
        $out = '';
        // bytes: arrow letters are ASCII.
        foreach (str_split($s) as $c) $out .= isset($letters[$c]) ? $c : trn_arrows_for_total($letters, 1, intval(round(0.9 * trn_face_max($letters))), $rnd);
        return $out;
    };
    return ['a' => $fix($a), 't' => $fix($t), 'c' => $c, 'src' => 'wa-' . $how];
}

/**
 * Category of the real competition proposed for an archer of the training: the
 * same bow (recurve when none is recorded), their sex, Under 21 when their age
 * allows it and the competition has it.
 *
 * @param array $cats code => ['name', 'sex'], individual categories of the competition.
 * @param object $archer TrainingArchers row.
 * @param int $year Year of the training.
 * @return string Category code, '' when the competition has none.
 */
function trn_wa_default_category($cats, $archer, $year) {
    if (!$cats) return '';
    $div = (string)$archer->ArDivision !== '' ? (string)$archer->ArDivision : TRN_DEFAULT_DIVISION;
    $div = TRN_DIVISION_ALIASES[$div] ?? $div;
    // bytes: division codes are ASCII.
    if (strlen($div) !== 1) $div = 'R';
    $sex = intval($archer->ArSex) === 1 ? 'W' : 'M';
    $young = trn_is_date($archer->ArDob ?? null) && intval($year) - intval(mb_substr($archer->ArDob, 0, 4)) < 21;
    foreach ($young ? [$div . 'U21' . $sex, $div . $sex] : [$div . $sex] as $code) {
        if (isset($cats[$code])) return $code;
    }
    foreach ($cats as $code => $c) if ($c['sex'] === intval($archer->ArSex)) return $code;
    return (string)array_key_first($cats);
}

/**
 * Can the core rank teams on this database? ianseo 2026-10-26 writes the team
 * members with a MySQL 8 row alias ("INSERT … AS cols ON DUPLICATE KEY UPDATE",
 * Common/Rank/Obj_Rank_AbsTeam_calc.php) that MariaDB refuses: every team ranking
 * then stops the page as soon as a team has a score. Checked on the core's file,
 * so that a corrected core is used as soon as it is installed.
 *
 * @return bool
 */
function trn_core_ranks_teams() {
    global $CFG;
    $v = safe_fetch(safe_r_sql("SELECT VERSION() AS V"));
    if (!$v || stripos((string)$v->V, 'mariadb') === false) return true;
    $src = @file_get_contents($CFG->INCLUDE_PATH . '/Common/Rank/Obj_Rank_AbsTeam_calc.php');
    return $src === false || !preg_match('/\bas\s+cols\s+ON\s+DUPLICATE\s+KEY/i', $src);
}

/**
 * Create a training from a World Archery competition: the competition of the
 * core's international set, its events set to the real ones, every athlete and
 * team entered as simulated competitors. The competition is opened.
 *
 * @param array $comp A row of trn_wa_search().
 * @param string $date Y-m-d, day of the training.
 * @param string $time H:i.
 * @return array ['id', 'code', 'athletes', 'skipped', 'noteams' => team events left out] or ['error' => language key].
 */
function trn_wa_create($comp, $date, $time) {
    global $CFG;
    if (!isset(TRN_WA_TYPES[intval($comp['ComDis'] ?? 0)])) return ['error' => 'WaErrDisc'];
    $data = trn_wa_fetch(intval($comp['ID']));
    if (!$data) return ['error' => 'WaErrApi'];
    if (!$data['iquals']) return ['error' => 'WaErrNoResults'];
    $type = trn_wa_type(intval($comp['ComDis']), $data['iquals']);
    $code = trn_new_code($date);
    if ($code === '') return ['error' => 'ErrNoCode'];

    require_once 'Common/Fun_ScriptsOnNewTour.inc.php';
    require_once 'Common/Fun_Sessions.inc.php';
    require_once 'Common/Fun_Various.inc.php';
    require_once 'Common/Lib/Fun_Phases.inc.php';
    require_once 'Tournament/Fun_ManSessions.inc.php';
    require_once 'Scheduler/LibScheduler.php';
    require_once 'Common/UpdatePreOpen.inc.php';

    $name = trim((string)$comp['Name']);
    $where = trim((string)$comp['Place']) !== '' ? trim((string)$comp['Place']) : $name;
    // Written as the core's competition settings write a new competition (Tournament/index.php).
    safe_w_sql("INSERT INTO Tournament SET ToType = " . intval($type) . ", ToCode = " . StrSafe_DB($code)
        . ", ToName = " . StrSafe_DB($name) . ", ToNameShort = " . StrSafe_DB(mb_substr($name, 0, 60))
        . ", ToIocCode = 'FITA', ToCommitee = 'WA', ToComDescr = 'World Archery', ToWhere = " . StrSafe_DB($where)
        . ", ToTimeZone = " . StrSafe_DB(date('P')) . ", ToWhenFrom = " . StrSafe_DB($date) . ", ToWhenTo = " . StrSafe_DB($date)
        . ", ToCurrency = 'EUR', ToPrintLang = '', ToPrintChars = 0, ToPrintPaper = 0, ToUseHHT = 0"
        . ", ToDbVersion = " . StrSafe_DB(GetParameter('DBUpdate')) . ", ToTypeSubRule = 'SetAllClass', ToLocRule = 'default'"
        . ", ToIsORIS = '', ToVenue = " . StrSafe_DB(mb_substr($where, 0, 100)) . ", ToCountry = " . StrSafe_DB(mb_substr((string)$comp['Country'], 0, 3)));
    $tid = intval(safe_w_last_id());
    if (!$tid) return ['error' => 'ErrImport'];

    $_SESSION['TourId'] = $tid;
    $_SESSION['TourCode'] = $code;
    $_SESSION['TourRealWhenFrom'] = $date;
    $_SESSION['TourRealWhenTo'] = $date;
    GetSetupFile($tid, $type, 'default', 1, 'SetAllClass');
    calcMaxTeamPerson([], true, $tid);
    insertSession($tid, 1, 'Q', '', null, 4, 4, 1, 0);
    InsertSchedDate([1 => [1 => $date]]);
    InsertSchedTime([1 => [1 => $time]]);
    UpdatePreOpen($tid);
    CreateTourSession($tid);

    // Events: those of the real competition, with the format of its brackets. No team event
    // where the core cannot rank teams: it would stop every page once the scores arrive.
    $teams = trn_core_ranks_teams();
    if (!$teams) $data['tquals'] = $data['tmatches'] = [];
    $keep = ['0' => [], '1' => []];
    foreach (['0' => ['iquals', 'imatches'], '1' => ['tquals', 'tmatches']] as $team => $kinds) {
        $phases = [];
        foreach ($data[$kinds[1]] as $cat) {
            foreach ($cat['Matches'] ?? [] as $m) {
                $c = (string)$cat['Code'];
                $phases[$c]['max'] = max($phases[$c]['max'] ?? 0, intval($m['Phase']));
                $phases[$c]['fmt'] = [intval($m['MatchMode'] ?? 0), intval($m['NumEnds'] ?? 0), intval($m['NumArrowsEnd'] ?? 0), intval($m['NumArrowsTB'] ?? 1)];
            }
        }
        foreach ($data[$kinds[0]] as $cat) {
            $c = (string)$cat['Code'];
            $keep[$team][$c] = true;
            $evCode = StrSafe_DB($c);
            if (isset($phases[$c]) && $phases[$c]['fmt'][1] > 0) {
                list($mode, $ends, $arrows, $so) = $phases[$c]['fmt'];
                $first = $phases[$c]['max'];
                safe_w_sql("UPDATE Events SET EvFinalFirstPhase = $first, EvNumQualified = " . intval(numQualifiedByPhase($first)) . ",
                    EvFirstQualified = 1, EvElimType = 0, EvElim1 = 0, EvElim2 = 0, EvMatchMode = $mode, EvMatchArrowsNo = 0,
                    EvFinEnds = $ends, EvFinArrows = $arrows, EvFinSO = $so, EvElimEnds = $ends, EvElimArrows = $arrows, EvElimSO = $so
                    WHERE EvTournament = $tid AND EvTeamEvent = $team AND EvCode = $evCode");
            } else {
                safe_w_sql("UPDATE Events SET EvFinalFirstPhase = 0 WHERE EvTournament = $tid AND EvTeamEvent = $team AND EvCode = $evCode");
            }
        }
    }
    // The set's other events would only show empty lists to the coach.
    $rs = safe_r_sql("SELECT EvCode, EvTeamEvent FROM Events WHERE EvTournament = $tid");
    while ($r = safe_fetch($rs)) {
        if (isset($keep[(string)intval($r->EvTeamEvent)][(string)$r->EvCode])) continue;
        safe_w_sql("DELETE FROM EventClass WHERE EcTournament = $tid AND EcTeamEvent = " . intval($r->EvTeamEvent) . " AND EcCode = " . StrSafe_DB($r->EvCode));
        safe_w_sql("DELETE FROM Events WHERE EvTournament = $tid AND EvTeamEvent = " . intval($r->EvTeamEvent) . " AND EvCode = " . StrSafe_DB($r->EvCode));
    }

    // Athletes. The country of a team gives its full name.
    $nocName = [];
    foreach ($data['tquals'] as $cat) foreach ($cat['Results'] ?? [] as $r) $nocName[(string)($r['NOC'] ?? '')] = (string)($r['Name'] ?? '');
    $gender = [];
    foreach ($data['cats'] as $c) $gender[(string)$c['Code']] = in_array('W', (array)($c['Gender'] ?? []), true) && !in_array('M', (array)($c['Gender'] ?? []), true) ? 1 : 0;
    $byWa = [];
    $added = $skipped = 0;
    foreach ($data['iquals'] as $cat) {
        $c = (string)$cat['Code'];
        // bytes: World Archery category codes are ASCII (division letter, then class).
        $div = substr($c, 0, 1);
        $cls = substr($c, 1);
        foreach ($cat['Results'] ?? [] as $r) {
            $id = (string)($r['Athlete']['Id'] ?? '');
            if ($id === '' || intval($r['Score'] ?? 0) <= 0) continue;
            $noc = (string)($r['Athlete']['NOC'] ?? '');
            $archer = (object)['ArCode' => $id, 'ArIocCode' => 'FITA',
                'ArFamilyName' => (string)($r['Athlete']['FName'] ?? ''), 'ArGivenName' => (string)($r['Athlete']['GName'] ?? ''),
                'ArSex' => $gender[$c] ?? 0, 'ArDob' => null,
                'ArClubCode' => $noc, 'ArClubName' => ($nocName[$noc] ?? '') !== '' ? $nocName[$noc] : $noc];
            $enId = trn_add_entry($tid, $archer, $div, $cls, 1);
            if (!$enId) { $skipped++; continue; }
            safe_w_sql("INSERT INTO TrainingGhosts SET GhEntry = $enId, GhTournament = $tid, GhKind = 1, GhWaId = " . StrSafe_DB($id));
            $byWa[$id][] = $enId;
            $added++;
        }
    }

    // Teams of the real competition.
    $mixed = [];
    foreach ($data['cats'] as $c) $mixed[(string)$c['Code']] = !empty($c['Mixed']);
    $posted = ['team' => [], 'mixed' => []];
    foreach ($data['tquals'] as $cat) {
        $kind = !empty($mixed[(string)$cat['Code']]) ? 'mixed' : 'team';
        foreach ($cat['Results'] ?? [] as $r) {
            $members = [];
            foreach ($r['Members'] ?? [] as $mb) {
                foreach ($byWa[(string)($mb['Id'] ?? '')] ?? [] as $en) { $members[] = $en; break; }
            }
            if ($members) $posted[$kind][] = ['id' => 0, 'name' => (string)($r['NOC'] ?? ''), 'members' => $members];
        }
    }
    trn_teams_save($tid, $posted);

    trn_isk_setup($tid);
    safe_w_sql("INSERT INTO TrainingRuns SET RnTournament = $tid, RnTemplate = 0, RnSimple = 1, RnQualSim = 0, RnWaComp = " . intval($comp['ID']) . ", RnCreated = NOW()");
    foreach ($data as $kind => $items) {
        safe_w_sql("REPLACE INTO TrainingWaData SET WdTournament = $tid, WdKind = " . StrSafe_DB($kind)
            . ", WdData = " . StrSafe_DB(json_encode($items, JSON_UNESCAPED_UNICODE)) . ", WdFetched = NOW()");
    }
    safe_w_sql("REPLACE INTO TrainingWaData SET WdTournament = $tid, WdKind = 'comp', WdData = "
        . StrSafe_DB(json_encode($comp, JSON_UNESCAPED_UNICODE)) . ", WdFetched = NOW()");
    return ['id' => $tid, 'code' => $code, 'athletes' => $added, 'skipped' => $skipped, 'noteams' => !$teams];
}

/**
 * Letters of a face that a World Archery column header counts ("10+X", "X", "6").
 *
 * @param string $header
 * @param array $letters Face letters.
 * @return string Letters, '' when the header names nothing on this face.
 */
function trn_wa_header_letters($header, $letters) {
    $out = '';
    foreach (preg_split('/\s*\+\s*/', trim((string)$header)) as $tok) {
        if ($tok === '') continue;
        $l = trn_letters_from_print([$tok], $letters);
        if ($l !== ' ' && strpos($out, $l) === false) $out .= $l;
    }
    return $out;
}
