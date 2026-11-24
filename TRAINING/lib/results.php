<?php
/**
 * Results of the archers, for the coaches' follow-up.
 *
 * One line per archer and per competition: qualification score, arrows, golds,
 * rank, individual final rank and duels won and lost, read from the core's
 * tables (Qualifications, Individuals, Finals) — the ranks are those the core's
 * Rank classes wrote, nothing is ranked again here.
 *
 * The trainings are kept in TrainingResults, refreshed whenever their scores
 * change, so that they outlive the competition: once a training is deleted
 * from ianseo, its results stay in the follow-up. Real competitions are read
 * live, they stay on the server. An archer is recognised by the licence, or by
 * the name when there is none. Fictitious competitors are left out.
 */

/**
 * Key of an archer across competitions.
 *
 * @param string $code Licence.
 * @param string $family
 * @param string $given
 * @return string
 */
function trn_archer_key($code, $family, $given) {
    $code = trim((string)$code);
    return $code !== '' ? 'L:' . $code : 'N:' . mb_strtoupper(trim($family) . '|' . trim($given));
}

/**
 * SQL condition on Entries selecting one archer by key.
 *
 * @param string $key From trn_archer_key().
 * @return string
 */
function trn_archer_where($key) {
    if (strncmp($key, 'L:', 2) === 0) return "EnCode = " . StrSafe_DB(mb_substr($key, 2));
    $p = explode('|', mb_substr($key, 2), 2);
    return "EnCode = '' AND EnFirstName = " . StrSafe_DB($p[0]) . " AND EnName = " . StrSafe_DB($p[1] ?? '');
}

/**
 * Result lines of the entries matching a condition, ghosts and other clubs of
 * fictitious competitors left out.
 *
 * @param string $where SQL condition on Entries (and Tournament).
 * @return array List of associative arrays (Rs* names without the prefix).
 */
function trn_results_rows($where) {
    require_once __DIR__ . '/placement.php';
    $rows = [];
    $rs = safe_r_sql("SELECT ToId, ToCode, ToName, ToWhenFrom, ToType, ToTypeName, ToNumDist,
            EnId, EnCode, EnFirstName, EnName, EnDivision, EnClass, EnTargetFace,
            QuSession, QuScore, QuGold, QuXnine, QuHits, QuClRank, CoCode, TfName,
            (SELECT SUM(DiEnds * DiArrows) FROM DistanceInformation
                WHERE DiTournament = ToId AND DiSession = QuSession AND DiType = 'Q') AS Planned
        FROM Entries
        INNER JOIN Tournament ON ToId = EnTournament
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        LEFT JOIN TargetFaces ON TfTournament = EnTournament AND TfId = EnTargetFace
        WHERE ($where) AND EnAthlete = 1 AND COALESCE(CoCode, '') <> '" . TRN_GHOST_CLUB . "'
        ORDER BY ToWhenFrom, ToId, EnId");
    $ids = [];
    while ($r = safe_fetch($rs)) {
        $dist = trn_category_distances($r->ToId, $r->ToType, $r->EnDivision, $r->EnClass, $r->ToNumDist);
        // A series with no distance in figures (0) says nothing about the shoot.
        $dist = array_values(array_unique(array_filter($dist)));
        $rows[intval($r->EnId)] = [
            'Tournament' => intval($r->ToId), 'Entry' => intval($r->EnId), 'Code' => (string)$r->ToCode,
            'Name' => (string)$r->ToName, 'Date' => (string)$r->ToWhenFrom,
            'TypeName' => (string)$r->ToTypeName,
            'Key' => trn_archer_key($r->EnCode, $r->EnFirstName, $r->EnName), 'Licence' => (string)$r->EnCode,
            'Family' => (string)$r->EnFirstName, 'Given' => (string)$r->EnName,
            'Division' => (string)$r->EnDivision, 'Class' => (string)$r->EnClass,
            'Distances' => $dist ? implode('/', $dist) . 'm' : '', 'Face' => (string)$r->TfName,
            'Score' => intval($r->QuScore), 'Golds' => intval($r->QuGold), 'XNine' => intval($r->QuXnine),
            // Arrows really shot when the scores were entered arrow by arrow, else those planned.
            'Arrows' => intval($r->QuHits) > 0 ? intval($r->QuHits) : intval($r->Planned),
            'QualRank' => intval($r->QuClRank), 'Event' => '', 'FinalRank' => 0, 'Won' => 0, 'Lost' => 0,
        ];
        $ids[] = intval($r->EnId);
    }
    if (!$ids) return [];
    $list = implode(',', $ids);

    // Individual event: the one with a final rank first.
    $rs = safe_r_sql("SELECT IndId, IndEvent, IndRank, IndRankFinal FROM Individuals
        WHERE IndId IN ($list) ORDER BY IndRankFinal = 0, IndEvent");
    while ($r = safe_fetch($rs)) {
        $row = &$rows[intval($r->IndId)];
        if ($row['Event'] === '') {
            $row['Event'] = (string)$r->IndEvent;
            $row['FinalRank'] = intval($r->IndRankFinal);
            if (intval($r->IndRank) > 0) $row['QualRank'] = intval($r->IndRank);
        }
        unset($row);
    }

    // Duels: a match is a pair of match numbers (n, n XOR 1); a bye (no opponent) does not count.
    $rs = safe_r_sql("SELECT Mine.FinAthlete AS Ath, SUM(Mine.FinWinLose = 1) AS Won, SUM(Other.FinWinLose = 1) AS Lost
        FROM Finals AS Mine
        INNER JOIN Finals AS Other ON Other.FinTournament = Mine.FinTournament AND Other.FinEvent = Mine.FinEvent
            AND Other.FinMatchNo = (Mine.FinMatchNo ^ 1)
        WHERE Mine.FinAthlete IN ($list) AND Other.FinAthlete > 0
        GROUP BY Mine.FinAthlete");
    while ($r = safe_fetch($rs)) {
        $rows[intval($r->Ath)]['Won'] = intval($r->Won);
        $rows[intval($r->Ath)]['Lost'] = intval($r->Lost);
    }
    return array_values($rows);
}

/**
 * Keep the results of a training in TrainingResults (replacing what was kept).
 *
 * @param int $tourId
 */
function trn_archive_run($tourId) {
    $tourId = intval($tourId);
    $run = trn_run($tourId);
    if (!$run) return;
    $rows = trn_results_rows("EnTournament = $tourId AND " . trn_not_ghost_sql($tourId));
    safe_w_sql("DELETE FROM TrainingResults WHERE RsTournament = $tourId");
    foreach ($rows as $r) {
        $set = ["RsFormat = " . StrSafe_DB(mb_substr((string)$run->TmName, 0, 100)), "RsSim = " . intval($run->RnQualSim), "RsUpdated = NOW()"];
        foreach ($r as $k => $v) {
            if ($k === 'TypeName') continue;
            $set[] = 'Rs' . $k . ' = ' . (is_int($v) ? $v : StrSafe_DB(mb_substr((string)$v, 0, 200)));
        }
        safe_w_sql("INSERT INTO TrainingResults SET " . implode(', ', $set));
    }
}

/**
 * Bring TrainingResults up to date for every training still in ianseo whose
 * scores changed since it was last kept.
 */
function trn_archive_refresh() {
    $rs = safe_r_sql("SELECT RnTournament,
            (SELECT MAX(QuTimestamp) FROM Qualifications INNER JOIN Entries ON EnId = QuId WHERE EnTournament = RnTournament) AS Changed,
            (SELECT MAX(FinDateTime) FROM Finals WHERE FinTournament = RnTournament) AS Duels,
            (SELECT MAX(RsUpdated) FROM TrainingResults WHERE RsTournament = RnTournament) AS Kept,
            (SELECT COUNT(*) FROM Entries WHERE EnTournament = RnTournament) AS Archers,
            (SELECT COUNT(*) FROM TrainingResults WHERE RsTournament = RnTournament) AS KeptRows
        FROM TrainingRuns INNER JOIN Tournament ON ToId = RnTournament");
    while ($r = safe_fetch($rs)) {
        $kept = (string)$r->Kept;
        if ($kept === '' || (string)$r->Changed > $kept || mb_substr((string)$r->Duels, 0, 19) > $kept
            || intval($r->KeptRows) === 0 && intval($r->Archers) > 0) {
            trn_archive_run($r->RnTournament);
        }
    }
}

/**
 * Every result of an archer: the trainings kept, and the real competitions
 * still on the server.
 *
 * @param string $key
 * @return array Lines, most recent first, with 'Kind' => 'training'|'competition' and 'Format'.
 */
function trn_archer_history($key) {
    $out = [];
    $rs = safe_r_sql("SELECT * FROM TrainingResults WHERE RsKey = " . StrSafe_DB($key) . " ORDER BY RsDate DESC, RsTournament DESC");
    while ($r = safe_fetch($rs)) {
        $line = [];
        $ints = ['Tournament', 'Entry', 'Sim', 'Score', 'Golds', 'XNine', 'Arrows', 'QualRank', 'FinalRank', 'Won', 'Lost'];
        foreach ((array)$r as $k => $v) {
            $name = mb_substr($k, 2);
            $line[$name] = in_array($name, $ints, true) ? intval($v) : (string)$v;
        }
        $line['Kind'] = 'training';
        $out[] = $line;
    }
    foreach (trn_results_rows(trn_archer_where($key) . " AND EnTournament NOT IN (SELECT RnTournament FROM TrainingRuns)") as $r) {
        $r['Kind'] = 'competition';
        $r['Format'] = trim(get_text($r['TypeName'], 'Tournament'));
        $r['Sim'] = 0;
        $out[] = $r;
    }
    usort($out, function ($a, $b) { return [$b['Date'], $b['Tournament']] <=> [$a['Date'], $a['Tournament']]; });
    return $out;
}

/**
 * Results grouped by situation: kind, format, distances and face.
 *
 * @param array $history From trn_archer_history().
 * @return array List of ['kind', 'format', 'distances', 'face', 'n', 'best', 'avg', 'perArrow', 'last', 'won', 'lost'].
 */
function trn_situations($history) {
    $g = [];
    foreach ($history as $h) {
        $k = $h['Kind'] . '|' . $h['Format'] . '|' . $h['Distances'] . '|' . $h['Face'];
        if (!isset($g[$k])) {
            $g[$k] = ['kind' => $h['Kind'], 'format' => $h['Format'], 'distances' => $h['Distances'], 'face' => $h['Face'],
                      'n' => 0, 'scored' => 0, 'best' => 0, 'sum' => 0, 'arrows' => 0, 'last' => null, 'won' => 0, 'lost' => 0];
        }
        $s = &$g[$k];
        $s['n']++;
        $s['won'] += intval($h['Won']);
        $s['lost'] += intval($h['Lost']);
        // A simulated qualification (one point each) says nothing of the archer's level.
        if (!intval($h['Sim'] ?? 0) && intval($h['Score']) > 0) {
            $s['scored']++;
            $s['best'] = max($s['best'], intval($h['Score']));
            $s['sum'] += intval($h['Score']);
            $s['arrows'] += intval($h['Arrows']);
            if ($s['last'] === null) $s['last'] = intval($h['Score']);
        }
        unset($s);
    }
    foreach ($g as &$s) {
        $s['avg'] = $s['scored'] ? $s['sum'] / $s['scored'] : null;
        $s['perArrow'] = $s['arrows'] ? $s['sum'] / $s['arrows'] : null;
    }
    unset($s);
    return array_values($g);
}

/**
 * Send result lines as a CSV file (semicolon, UTF-8 with a byte order mark, as
 * spreadsheets expect it) and stop.
 *
 * @param array $lines
 * @param string $name File name without extension.
 */
function trn_csv_out($lines, $name) {
    $cols = ['Date', 'Code', 'Name', 'Kind', 'Format', 'Licence', 'Family', 'Given', 'Division', 'Class', 'Distances', 'Face',
             'Score', 'Arrows', 'Golds', 'XNine', 'QualRank', 'Event', 'FinalRank', 'Won', 'Lost'];
    $head = [];
    foreach ($cols as $c) $head[] = trn_text('Csv' . $c);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $name) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $head, ';');
    foreach ($lines as $l) {
        $row = [];
        foreach ($cols as $c) {
            $v = $l[$c] ?? '';
            if ($c === 'Kind') $v = trn_text($v === 'competition' ? 'KindCompetition' : 'KindTraining');
            $row[] = $v;
        }
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}
