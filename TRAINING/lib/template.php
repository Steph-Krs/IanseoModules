<?php
/**
 * Reference formats: snapshot of a competition, and new competitions made from it.
 *
 * A format is the core's own export of a competition (export_tournament(), the
 * function behind "Export competition"), stripped of its archers and results,
 * and a new training is that snapshot given back to the core's own import
 * (tour_import()), which renumbers every table consistently. Copying the tables
 * here instead would miss every table the core adds in later versions; the
 * import also runs UpdateTournament(), so a format saved before an ianseo
 * update is brought up to date when it is used.
 *
 * Nothing that identifies the source competition outside this server survives
 * the snapshot: the ianseo.net identifier (ToOnlineId) and the publishing
 * credentials are removed, the code is replaced at each creation.
 */

/**
 * Tables that hold archers, teams, results or logs: never part of a format.
 */
const TRN_DROP_TABLES = [
    'Entries', 'Countries', 'Qualifications', 'QualOldPositions', 'ElabQualifications',
    'Individuals', 'IndOldPositions', 'Eliminations', 'ExtraData', 'ExtraDataCountries',
    'Photos', 'Vegas', 'AccEntries', 'Awarded', 'HhtData', 'GateLog', 'Logs', 'RecBroken',
    'TeamComponent', 'TeamEligibleComponent', 'TeamFinComponent', 'TeamFinComponentLog',
    'Teams', 'TeamQualificationDistances', 'ClubTeam', 'ClubTeamScore', 'CasTeam',
    'CasScore', 'CasTeamFinal', 'CasTeamTarget', 'TournamentInvolved', 'RunArchery',
    'RunArcheryRank', 'RunArcheryParticipants', 'HeartBeat', 'IskData', 'OnLineIds',
    'DocumentVersions', 'Reviews', 'FinalReportA', 'FinOdfTiming', 'OdfDocuments',
    'OdfMessageStatus', 'Flags', 'Rankings',
];

/**
 * Match tables kept as empty brackets: the listed columns survive, the others
 * fall back to their defaults, and the given ones are forced.
 */
const TRN_RESET_TABLES = [
    'Finals' => [
        'keep' => ['FinEvent', 'FinMatchNo', 'FinTournament', 'FinTarget'],
        'set'  => [],
    ],
    'TeamFinals' => [
        'keep' => ['TfEvent', 'TfMatchNo', 'TfTournament', 'TfSession', 'TfTarget'],
        'set'  => [],
    ],
    'RoundRobinMatches' => [
        'keep' => ['RrMatchTournament', 'RrMatchTeam', 'RrMatchEvent', 'RrMatchLevel', 'RrMatchGroup',
                   'RrMatchRound', 'RrMatchMatchNo', 'RrMatchSwapped', 'RrMatchTarget',
                   'RrMatchScheduledDate', 'RrMatchScheduledTime', 'RrMatchScheduledLength'],
        'set'  => ['RrMatchAthlete' => 0, 'RrMatchSubTeam' => 0],
    ],
    'RoundRobinParticipants' => [
        'keep' => ['RrPartTournament', 'RrPartTeam', 'RrPartEvent', 'RrPartLevel', 'RrPartGroup',
                   'RrPartSourceLevel', 'RrPartSourceGroup', 'RrPartSourceRank', 'RrPartDestItem'],
        'set'  => ['RrPartParticipant' => 0, 'RrPartSubTeam' => 0],
    ],
];

/**
 * Remove from a core export everything that is not the format itself.
 *
 * @param array $gara The array built by export_tournament(), modified in place.
 */
function trn_tpl_strip(&$gara) {
    foreach (TRN_DROP_TABLES as $tab) unset($gara[$tab]);

    foreach (TRN_RESET_TABLES as $tab => $rule) {
        if (empty($gara[$tab]) || !is_array($gara[$tab])) continue;
        $rows = [];
        foreach ($gara[$tab] as $row) {
            $new = [];
            foreach ($rule['keep'] as $col) {
                if (array_key_exists($col, $row)) $new[$col] = $row[$col];
            }
            foreach ($rule['set'] as $col => $val) $new[$col] = $val;
            $rows[] = $new;
        }
        $gara[$tab] = $rows;
    }

    foreach (['Events' => ['EvShootOff' => 0, 'EvE1ShootOff' => 0, 'EvE2ShootOff' => 0,
                           'EvQualLastUpdate' => null, 'EvFinalLastUpdate' => null],
              'RoundRobinGroup' => ['RrGrSoSolved' => 0],
              'RoundRobinLevel' => ['RrLevSoSolved' => 0]] as $tab => $set) {
        if (empty($gara[$tab]) || !is_array($gara[$tab])) continue;
        foreach ($gara[$tab] as $k => $row) {
            foreach ($set as $col => $val) {
                if (array_key_exists($col, $row)) $gara[$tab][$k][$col] = $val;
            }
        }
    }

    // Publishing identity and per-competition device state: they belong to the
    // source competition alone.
    if (!empty($gara['ModulesParameters']) && is_array($gara['ModulesParameters'])) {
        $keep = [];
        foreach ($gara['ModulesParameters'] as $row) {
            $mod = (string)($row['MpModule'] ?? '');
            $par = (string)($row['MpParameter'] ?? '');
            if (in_array($mod, ['SendToIanseo', 'ODF'], true)) continue;
            if (in_array($mod, ['ISK', 'ISK-NG'], true) && in_array($par, ['LockedSessions', 'Sequence'], true)) continue;
            $keep[] = $row;
        }
        $gara['ModulesParameters'] = $keep;
    }

    $gara['Tournament']['ToOnlineId'] = 0;
    $gara['Tournament']['ToBlock'] = 0;
}

/**
 * Is this a real date (not empty, not the zero date)?
 *
 * @param mixed $d
 * @return bool
 */
function trn_is_date($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}/', $d) && strncmp($d, '0000-00-00', 10) !== 0;
}

/**
 * Is this a time of day that was actually entered?
 *
 * @param mixed $t
 * @return bool
 */
function trn_is_time($t) {
    return is_string($t) && preg_match('/^\d{2}:\d{2}/', $t) && strncmp($t, '00:00', 5) !== 0;
}

/**
 * The first moment of the competition: the earliest date and time found in its
 * sessions, programme, distances and match schedule.
 *
 * @param array $gara
 * @return string Y-m-d H:i:s, at midnight of the first day when no time is set.
 */
function trn_tpl_ref_start($gara) {
    $found = [];
    foreach ($gara['Session'] ?? [] as $r) {
        if (trn_is_date($r['SesDtStart'] ?? null) && trn_is_time(mb_substr($r['SesDtStart'], 11))) $found[] = $r['SesDtStart'];
    }
    foreach (trn_shift_pairs() as $tab => $pairs) {
        foreach ($gara[$tab] ?? [] as $r) {
            foreach ($pairs as $p) {
                if (!empty($p[2])) continue;
                if (trn_is_date($r[$p[0]] ?? null) && trn_is_time($r[$p[1]] ?? null)) {
                    $found[] = mb_substr($r[$p[0]], 0, 10) . ' ' . mb_substr($r[$p[1]], 0, 8);
                }
            }
        }
    }
    if ($found) {
        sort($found);
        return mb_substr($found[0], 0, 19);
    }
    $from = $gara['Tournament']['ToWhenFrom'] ?? '';
    return (trn_is_date($from) ? mb_substr($from, 0, 10) : date('Y-m-d')) . ' 00:00:00';
}

/**
 * Columns holding a date and a time of the same moment: [date, time, keep the date].
 * "Keep the date" marks a second time of the same row, whose day is the row's own.
 *
 * @return array
 */
function trn_shift_pairs() {
    return [
        'Scheduler'           => [['SchDay', 'SchStart']],
        'FinSchedule'         => [['FSScheduledDate', 'FSScheduledTime']],
        'DistanceInformation' => [['DiDay', 'DiStart'], ['DiDay', 'DiWarmStart', true]],
        'FinWarmup'           => [['FwDay', 'FwTime'], ['FwDay', 'FwMatchTime', true]],
        'RoundRobinMatches'   => [['RrMatchScheduledDate', 'RrMatchScheduledTime']],
    ];
}

/**
 * Move every date and time of a snapshot so that it starts at a new moment,
 * keeping all the gaps of the reference competition.
 *
 * @param array $gara Modified in place.
 * @param string $newStart Y-m-d H:i.
 * @param string $refStart Y-m-d H:i:s, from trn_tpl_ref_start().
 */
function trn_tpl_shift(&$gara, $newStart, $refStart) {
    $utc = new DateTimeZone('UTC');
    $new = new DateTime($newStart, $utc);
    $ref = new DateTime($refStart, $utc);
    $delta = $new->getTimestamp() - $ref->getTimestamp();
    $newDay = new DateTime($new->format('Y-m-d'), $utc);
    $refDay = new DateTime($ref->format('Y-m-d'), $utc);
    $days = intval(round(($newDay->getTimestamp() - $refDay->getTimestamp()) / 86400));

    $moveDate = function ($d) use ($days, $utc) {
        if (!trn_is_date($d)) return $d;
        $x = new DateTime(mb_substr($d, 0, 10), $utc);
        $x->modify(($days >= 0 ? '+' : '') . $days . ' days');
        return $x->format('Y-m-d');
    };
    $moveStamp = function ($dt) use ($delta, $utc) {
        $x = new DateTime($dt, $utc);
        $x->setTimestamp($x->getTimestamp() + $delta);
        return $x;
    };

    foreach (['ToWhenFrom', 'ToWhenTo'] as $col) {
        if (isset($gara['Tournament'][$col])) $gara['Tournament'][$col] = $moveDate($gara['Tournament'][$col]);
    }
    foreach (['RoundRobinGroup' => 'RrGrDateTime', 'RoundRobinLevel' => 'RrLevDateTime'] as $tab => $col) {
        foreach ($gara[$tab] ?? [] as $k => $r) {
            if (isset($r[$col])) $gara[$tab][$k][$col] = $moveDate($r[$col]);
        }
    }
    foreach (['Session' => ['SesDtStart', 'SesDtEnd'], 'Scheduler' => ['SchDateStart', 'SchDateEnd']] as $tab => $cols) {
        foreach ($gara[$tab] ?? [] as $k => $r) {
            foreach ($cols as $col) {
                $v = $r[$col] ?? null;
                if (!trn_is_date($v)) continue;
                $gara[$tab][$k][$col] = trn_is_time(mb_substr($v, 11))
                    ? $moveStamp($v)->format('Y-m-d H:i:s')
                    : $moveDate($v) . mb_substr($v, 10);
            }
        }
    }
    foreach (trn_shift_pairs() as $tab => $pairs) {
        foreach ($gara[$tab] ?? [] as $k => $r) {
            $out = [];
            foreach ($pairs as $p) {
                $d = $r[$p[0]] ?? null;
                $t = $r[$p[1]] ?? null;
                if (!trn_is_date($d)) continue;
                if (trn_is_time($t)) {
                    $x = $moveStamp(mb_substr($d, 0, 10) . ' ' . mb_substr($t, 0, 8));
                    if (empty($p[2])) $out[$p[0]] = $x->format('Y-m-d');
                    $out[$p[1]] = $x->format('H:i:s');
                } elseif (empty($p[2])) {
                    $out[$p[0]] = $moveDate($d);
                }
            }
            foreach ($out as $col => $v) $gara[$tab][$k][$col] = $v;
        }
    }
}

/**
 * Season offset baked into the class bounds of a French competition.
 *
 * The French rules count a competition from September to December in the next
 * season, and the French ruleset applies it when it creates the classes:
 * every age bound moves by -1 (Modules/Sets/FR/lib.php, $NextYearClass), the
 * open bounds 1 and 127 excepted. The core then compares year(ToWhenTo) minus
 * year of birth to those bounds. Other rulesets have no such offset.
 *
 * @param string $locRule ToLocRule of the competition.
 * @param string $from ToWhenFrom, Y-m-d.
 * @return int -1 or 0.
 */
function trn_season_shift($locRule, $from) {
    return ((string)$locRule === 'FR' && trn_is_date($from) && intval(mb_substr($from, 5, 2)) >= 9) ? -1 : 0;
}

/**
 * Move an age bound by a season offset, the open bounds 1 and 127 excepted.
 *
 * @param int $bound
 * @param int $delta
 * @return int
 */
function trn_shift_bound($bound, $delta) {
    $bound = intval($bound);
    return ($bound === 1 || $bound === 127) ? $bound : $bound + $delta;
}

/**
 * Year to subtract the year of birth from, for bounds that carry no season
 * offset (the summary of a format): the season year in French rules, which is
 * the next year from September on; the year of the last day otherwise.
 *
 * @param bool $fr
 * @param string $from First day, Y-m-d.
 * @param string $to Last day, Y-m-d.
 * @return int
 */
function trn_season_year($fr, $from, $to) {
    return intval(mb_substr($to, 0, 4)) - ($fr ? trn_season_shift('FR', $from) : 0);
}

/**
 * What the creation form needs to know about a format, kept beside the snapshot
 * so that the form never unpacks it.
 *
 * @param array $gara Stripped snapshot.
 * @return array
 */
function trn_tpl_summary($gara) {
    // Class bounds are kept without the season offset of the source competition, so that the forms
    // can apply the one of the date chosen (trn_season_year()).
    $locRule = (string)($gara['Tournament']['ToLocRule'] ?? '');
    $srcShift = trn_season_shift($locRule, (string)($gara['Tournament']['ToWhenFrom'] ?? ''));
    $div = [];
    foreach ($gara['Divisions'] ?? [] as $r) {
        if (!intval($r['DivAthlete'] ?? 1)) continue;
        $div[(string)$r['DivId']] = ['name' => (string)($r['DivDescription'] ?? ''), 'order' => intval($r['DivViewOrder'] ?? 0)];
    }
    uasort($div, function ($a, $b) { return $a['order'] <=> $b['order']; });

    $cls = [];
    foreach ($gara['Classes'] ?? [] as $r) {
        if (!intval($r['ClAthlete'] ?? 1)) continue;
        $cls[(string)$r['ClId']] = [
            'name'  => (string)($r['ClDescription'] ?? ''),
            'order' => intval($r['ClViewOrder'] ?? 0),
            'sex'   => intval($r['ClSex'] ?? 0),
            'from'  => trn_shift_bound($r['ClAgeFrom'] ?? 0, -$srcShift),
            'to'    => trn_shift_bound($r['ClAgeTo'] ?? 0, -$srcShift),
            'divs'  => (string)($r['ClDivisionsAllowed'] ?? ''),
        ];
    }
    uasort($cls, function ($a, $b) { return $a['order'] <=> $b['order']; });

    $cats = [];
    foreach ($div as $d => $dv) {
        foreach ($cls as $c => $cv) {
            if ($cv['divs'] !== '' && !in_array($d, explode(',', $cv['divs']), true)) continue;
            $cats[] = ['div' => $d, 'cls' => $c, 'sex' => $cv['sex'], 'from' => $cv['from'], 'to' => $cv['to']];
        }
    }

    $sessions = [];
    foreach ($gara['Session'] ?? [] as $r) {
        if (($r['SesType'] ?? '') !== 'Q') continue;
        $sessions[intval($r['SesOrder'])] = (string)($r['SesName'] ?? '');
    }
    ksort($sessions);

    $events = [];
    $team = $mixed = false;
    foreach ($gara['Events'] ?? [] as $r) {
        if ((string)($r['EvCodeParent'] ?? '') !== '') continue;
        $isTeam = intval($r['EvTeamEvent'] ?? 0) === 1;
        $isMixed = $isTeam && intval($r['EvMixedTeam'] ?? 0) === 1;
        if ($isMixed) $mixed = true; elseif ($isTeam) $team = true;
        $events[] = ['code' => (string)$r['EvCode'], 'name' => (string)($r['EvEventName'] ?? ''),
                     'team' => $isTeam ? 1 : 0, 'mixed' => $isMixed ? 1 : 0,
                     'duels' => intval($r['EvFinalFirstPhase'] ?? 0) > 0 ? 1 : 0];
    }

    return [
        'season'   => 1,
        'fr'       => $locRule === 'FR',
        'type'     => (string)($gara['Tournament']['ToTypeName'] ?? ''),
        'subrule'  => (string)($gara['Tournament']['ToTypeSubRule'] ?? ''),
        'dist'     => intval($gara['Tournament']['ToNumDist'] ?? 0),
        'days'     => trn_is_date($gara['Tournament']['ToWhenFrom'] ?? null) && trn_is_date($gara['Tournament']['ToWhenTo'] ?? null)
            ? 1 + intval(round((strtotime($gara['Tournament']['ToWhenTo']) - strtotime($gara['Tournament']['ToWhenFrom'])) / 86400)) : 1,
        'divs'     => $div,
        'classes'  => $cls,
        'cats'     => $cats,
        'sessions' => $sessions,
        'events'   => $events,
        'team'     => $team,
        'mixed'    => $mixed,
    ];
}

/**
 * Save the open competition as a format, new or replacing an existing one.
 *
 * @param int $tourId
 * @param string $name
 * @param bool $simple Simplified menu for the trainings made from it.
 * @param int $replace Format to overwrite, 0 for a new one.
 * @return int The format, 0 on failure.
 */
function trn_tpl_save($tourId, $name, $simple, $replace = 0) {
    require_once 'Common/Lib/Fun_Export.php';
    $gara = export_tournament(intval($tourId), false);
    if (empty($gara['Tournament'])) return 0;
    $source = (string)$gara['Tournament']['ToCode'];
    trn_tpl_strip($gara);
    $summary = trn_tpl_summary($gara);
    $ref = trn_tpl_ref_start($gara);
    $blob = gzcompress(serialize($gara), 9);

    $set = "TmName = " . StrSafe_DB(mb_substr(trim($name), 0, 100))
        . ", TmSimple = " . ($simple ? 1 : 0)
        . ", TmSource = " . StrSafe_DB($source)
        . ", TmRefStart = " . StrSafe_DB($ref)
        . ", TmSummary = " . StrSafe_DB(json_encode($summary))
        . ", TmData = " . StrSafe_DB($blob)
        . ", TmUpdated = NOW()";
    $replace = intval($replace);
    if ($replace) {
        safe_w_sql("UPDATE TrainingTemplates SET $set WHERE TmId = $replace");
        return $replace;
    }
    safe_w_sql("INSERT INTO TrainingTemplates SET $set, TmCreated = NOW()");
    return intval(safe_w_last_id());
}

/**
 * The formats, without their snapshot.
 *
 * @return array TmId => row, summary decoded.
 */
function trn_tpl_list() {
    $out = [];
    $rs = safe_r_sql("SELECT TmId, TmName, TmSimple, TmSource, TmRefStart, TmSummary, TmUpdated
        FROM TrainingTemplates ORDER BY TmName, TmId");
    while ($r = safe_fetch($rs)) {
        $r->Summary = json_decode((string)$r->TmSummary, true) ?: [];
        if (empty($r->Summary['season'])) {
            $data = safe_fetch(safe_r_sql("SELECT TmData FROM TrainingTemplates WHERE TmId = " . intval($r->TmId)));
            $gara = $data ? @unserialize(@gzuncompress((string)$data->TmData), ['allowed_classes' => false]) : false;
            if (is_array($gara) && !empty($gara['Tournament'])) {
                $r->Summary = trn_tpl_summary($gara);
                safe_w_sql("UPDATE TrainingTemplates SET TmSummary = " . StrSafe_DB(json_encode($r->Summary)) . " WHERE TmId = " . intval($r->TmId));
            }
        }
        $out[intval($r->TmId)] = $r;
    }
    return $out;
}

/**
 * Next free competition code for a date: YYMMDD and a two-digit number.
 *
 * @param string $date Y-m-d.
 * @return string Empty when the 99 numbers of the day are taken.
 */
function trn_new_code($date) {
    $base = date('ymd', strtotime($date));
    $taken = [];
    $rs = safe_r_sql("SELECT ToCode FROM Tournament WHERE ToCode LIKE " . StrSafe_DB($base . '__'));
    while ($r = safe_fetch($rs)) $taken[(string)$r->ToCode] = true;
    for ($i = 1; $i <= 99; $i++) {
        $code = $base . sprintf('%02d', $i);
        if (!isset($taken[$code])) return $code;
    }
    return '';
}

/**
 * Create a competition from a format.
 *
 * @param int $tmId
 * @param string $name
 * @param string $date Y-m-d.
 * @param string $time H:i.
 * @param bool $qualSim Duels only: the qualification round will be simulated.
 * @return array ['id' => ToId, 'code' => ToCode] or ['error' => language key].
 */
function trn_create_competition($tmId, $name, $date, $time, $qualSim) {
    $rs = safe_r_sql("SELECT TmData, TmRefStart, TmSimple FROM TrainingTemplates WHERE TmId = " . intval($tmId));
    $tpl = safe_fetch($rs);
    if (!$tpl || !$tpl->TmData) return ['error' => 'ErrNoFormat'];
    $gara = @unserialize(@gzuncompress($tpl->TmData), ['allowed_classes' => false]);
    if (!is_array($gara) || empty($gara['Tournament'])) return ['error' => 'ErrNoFormat'];

    $code = trn_new_code($date);
    if ($code === '') return ['error' => 'ErrNoCode'];

    // French rules: the class bounds follow the season of the new date, not that of the source.
    $delta = trn_season_shift((string)($gara['Tournament']['ToLocRule'] ?? ''), $date)
        - trn_season_shift((string)($gara['Tournament']['ToLocRule'] ?? ''), (string)($gara['Tournament']['ToWhenFrom'] ?? ''));
    if ($delta) {
        foreach ($gara['Classes'] ?? [] as $k => $c) {
            $gara['Classes'][$k]['ClAgeFrom'] = trn_shift_bound($c['ClAgeFrom'] ?? 0, $delta);
            $gara['Classes'][$k]['ClAgeTo'] = trn_shift_bound($c['ClAgeTo'] ?? 0, $delta);
        }
    }

    $gara['Tournament']['ToCode'] = $code;
    $gara['Tournament']['ToName'] = $name;
    $gara['Tournament']['ToNameShort'] = mb_substr($name, 0, 60);
    trn_tpl_shift($gara, $date . ' ' . $time, $tpl->TmRefStart ?: ($date . ' ' . $time . ':00'));

    require_once 'Common/Fun_TourDelete.php';
    $id = intval(tour_import(gzcompress(serialize($gara)), true));
    if (!$id) return ['error' => 'ErrImport'];

    trn_isk_setup($id);
    safe_w_sql("INSERT INTO TrainingRuns SET RnTournament = $id, RnTemplate = " . intval($tmId)
        . ", RnSimple = " . intval($tpl->TmSimple) . ", RnQualSim = " . ($qualSim ? 1 : 0) . ", RnCreated = NOW()");
    return ['id' => $id, 'code' => $code];
}

/**
 * The training row of a competition, or null if the module did not create it.
 *
 * @param int $tourId
 * @return object|null
 */
function trn_run($tourId) {
    $rs = safe_r_sql("SELECT RnTournament, RnTemplate, RnSimple, RnQualSim, TmName
        FROM TrainingRuns LEFT JOIN TrainingTemplates ON TmId = RnTemplate
        WHERE RnTournament = " . intval($tourId));
    return safe_fetch($rs) ?: null;
}

/** Parameter (core table Parameters) holding the server address given to ISK-Lite. */
const TRN_ISK_PARAM = 'TrnIskUrl';

/**
 * Server address the trainings give to ISK-Lite, set on the formats page only.
 *
 * Read with an empty default: GetParameter() writes the default when the row is
 * missing and a default is given.
 *
 * @return string
 */
function trn_isk_url() {
    return (string)GetParameter(TRN_ISK_PARAM, false, '');
}

/**
 * Store the server address, written the way the core's competition settings
 * write it (Tournament/index.php): scheme, host and the ianseo folder. The port,
 * which the core drops, is kept: a server behind another port would not be
 * reached without it.
 *
 * @param string $raw As typed; empty removes the address.
 * @return bool False when the address cannot be read.
 */
function trn_isk_url_set($raw) {
    global $CFG;
    $raw = trim((string)$raw);
    if ($raw === '') {
        DelParameter(TRN_ISK_PARAM);
        return true;
    }
    if (!preg_match('#^https?://#i', $raw)) $raw = 'http://' . $raw;
    $p = parse_url($raw);
    if (!$p || empty($p['host']) || !preg_match('/^[A-Za-z0-9.\-]+$/', $p['host'])) return false;
    // bytes: the scheme is http or https, ASCII by the test above.
    $url = strtolower($p['scheme']) . '://' . $p['host'] . (!empty($p['port']) ? ':' . intval($p['port']) : '') . $CFG->ROOT_DIR;
    SetParameter(TRN_ISK_PARAM, $url);
    return true;
}

/**
 * Make a new training ready for ISK-Lite: scoring by the ISK-NG app in its free
 * mode (the core's "ng-lite", API 11), the server address of the module when
 * one is set. Written as the core's competition settings write them.
 *
 * @param int $tourId
 */
function trn_isk_setup($tourId) {
    $tourId = intval($tourId);
    require_once 'Common/Lib/Fun_Modules.php';
    setModuleParameter('ISK-NG', 'Mode', 'ng-lite', $tourId);
    $url = trn_isk_url();
    if ($url !== '') setModuleParameter('ISK-NG', 'ServerUrl', $url, $tourId);
    delModuleParameter('ISK-NG', 'LicenseNumber', $tourId);
    Set_Tournament_Option('UseApi', 11, false, $tourId);
}

