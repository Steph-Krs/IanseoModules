<?php
/**
 * Coaches' follow-up of the archers: every shoot of an archer on this server,
 * trainings and real competitions, grouped by situation (kind, format,
 * distances, face), with the trend of the average per arrow and CSV exports.
 */

require_once __DIR__ . '/lib/boot.php';
trn_require_admin();

trn_archive_refresh();

$csv = (string)($_GET['csv'] ?? '');
if ($csv === 'run') {
    $tourId = intval($_SESSION['TourId'] ?? 0);
    if ($tourId > 0) {
        $code = (string)safe_fetch(safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId = $tourId"))->ToCode;
        $lines = trn_results_rows("EnTournament = $tourId AND " . trn_not_ghost_sql($tourId));
        $run = trn_run($tourId);
        foreach ($lines as &$l) { $l['Kind'] = $run ? 'training' : 'competition'; $l['Format'] = $run ? (string)$run->TmName : get_text($l['TypeName'], 'Tournament'); }
        unset($l);
        trn_csv_out($lines, 'training-' . $code);
    }
}
if ($csv === 'all') {
    $lines = [];
    $rs = safe_r_sql("SELECT * FROM TrainingResults ORDER BY RsDate, RsTournament, RsFamily, RsGiven");
    while ($r = safe_fetch($rs)) {
        $l = ['Kind' => 'training'];
        foreach ((array)$r as $k => $v) $l[mb_substr($k, 2)] = $v;
        $lines[] = $l;
    }
    trn_csv_out($lines, 'trainings-' . date('Ymd'));
}
$key = (string)($_GET['key'] ?? '');
if ($csv === 'archer' && $key !== '') {
    trn_csv_out(trn_archer_history($key), 'archer-' . $key);
}

/**
 * Average per arrow of a result, or null when it says nothing (simulated round, no score).
 */
$perArrow = function ($h) {
    if (intval($h['Sim'] ?? 0) || intval($h['Score']) <= 0 || intval($h['Arrows']) <= 0) return null;
    return intval($h['Score']) / intval($h['Arrows']);
};
$num = function ($v, $dec = 2) {
    return $v === null ? '' : number_format($v, $dec, trn_text('DecimalSep'), '');
};

if ($key !== '') {
    $history = trn_archer_history($key);
    $first = $history[0] ?? null;
    $name = $first ? trim($first['Family'] . ' ' . $first['Given']) : $key;
    trn_page_open(trn_text('FollowupOf', $name));
    echo '<p><a class="trn-btn" href="followup.php">' . trn_t('BackFollowup') . '</a> '
        . ($history ? '<a class="trn-btn" href="followup.php?csv=archer&amp;key=' . rawurlencode($key) . '">' . trn_t('CsvArcher') . '</a>' : '') . '</p>';
    if ($first && $first['Licence'] !== '') echo '<p class="trn-note">' . trn_t('FieldCode') . ' ' . trn_esc($first['Licence']) . '</p>';
    if (!$history) {
        echo '<p class="trn-note">' . trn_t('FollowupNone') . '</p>';
        trn_page_close();
        exit;
    }

    // By situation.
    echo '<h2>' . trn_t('SituationsTitle') . '</h2>';
    echo '<table class="Tabella trn-table"><tr><th>' . trn_t('ColKind') . '</th><th>' . trn_t('ColFormat') . '</th><th>' . trn_t('CsvDistances')
        . '</th><th>' . trn_t('CsvFace') . '</th><th>' . trn_t('ColShoots') . '</th><th>' . trn_t('ColAvg') . '</th><th>' . trn_t('ColPerArrow')
        . '</th><th>' . trn_t('ColBest') . '</th><th>' . trn_t('ColLast') . '</th><th>' . trn_t('ColDuels') . '</th></tr>';
    foreach (trn_situations($history) as $s) {
        echo '<tr><td>' . trn_t($s['kind'] === 'competition' ? 'KindCompetition' : 'KindTraining') . '</td><td>' . trn_esc($s['format'])
            . '</td><td>' . trn_esc($s['distances']) . '</td><td>' . trn_esc($s['face']) . '</td><td class="Center">' . intval($s['n'])
            . '</td><td class="Right">' . $num($s['avg'], 1) . '</td><td class="Right">' . $num($s['perArrow'])
            . '</td><td class="Right">' . ($s['best'] ?: '') . '</td><td class="Right">' . ($s['last'] ?? '')
            . '</td><td class="Center">' . ($s['won'] + $s['lost'] ? intval($s['won']) . ' - ' . intval($s['lost']) : '') . '</td></tr>';
    }
    echo '</table>';

    // Average per arrow over time, one dot per shoot.
    $points = [];
    foreach (array_reverse($history) as $h) {
        $v = $perArrow($h);
        if ($v !== null) $points[] = [$h, $v];
    }
    if (count($points) >= 2) {
        $vals = array_column($points, 1);
        $lo = floor(min($vals) * 2) / 2;
        $hi = ceil(max($vals) * 2) / 2;
        if ($hi - $lo < 0.5) $hi = $lo + 0.5;
        $w = 640; $h = 180; $pl = 40; $pr = 10; $pt = 10; $pb = 24;
        $x = function ($i) use ($points, $w, $pl, $pr) { return $pl + ($w - $pl - $pr) * (count($points) > 1 ? $i / (count($points) - 1) : 0.5); };
        $y = function ($v) use ($lo, $hi, $h, $pt, $pb) { return $pt + ($h - $pt - $pb) * (1 - ($v - $lo) / ($hi - $lo)); };
        $svg = '<svg class="trn-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . trn_t('ChartTitle') . '">';
        foreach ([$lo, ($lo + $hi) / 2, $hi] as $g) {
            $svg .= '<line class="trn-grid-line" x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . round($y($g), 1) . '" y2="' . round($y($g), 1) . '"/>'
                . '<text class="trn-axis" x="' . ($pl - 4) . '" y="' . round($y($g) + 4, 1) . '" text-anchor="end">' . $num($g, 1) . '</text>';
        }
        $line = [];
        foreach ($points as $i => $p) $line[] = round($x($i), 1) . ',' . round($y($p[1]), 1);
        $svg .= '<polyline class="trn-line" points="' . implode(' ', $line) . '"/>';
        foreach ($points as $i => $p) {
            $svg .= '<circle class="trn-dot trn-dot-' . trn_esc($p[0]['Kind']) . '" cx="' . round($x($i), 1) . '" cy="' . round($y($p[1]), 1) . '" r="4">'
                . '<title>' . trn_esc(trn_date($p[0]['Date']) . ' - ' . $p[0]['Name'] . ' - ' . $p[0]['Score'] . ' (' . $num($p[1]) . ')') . '</title></circle>';
        }
        $svg .= '<text class="trn-axis" x="' . $pl . '" y="' . ($h - 6) . '">' . trn_esc(trn_date($points[0][0]['Date'])) . '</text>'
            . '<text class="trn-axis" x="' . ($w - $pr) . '" y="' . ($h - 6) . '" text-anchor="end">' . trn_esc(trn_date(end($points)[0]['Date'])) . '</text></svg>';
        echo '<h2>' . trn_t('ChartTitle') . '</h2><div class="trn-card">' . $svg
            . '<p class="trn-note"><span class="trn-key trn-dot-training"></span> ' . trn_t('KindTraining')
            . ' &nbsp; <span class="trn-key trn-dot-competition"></span> ' . trn_t('KindCompetition') . '</p></div>';
    }

    // Every shoot.
    echo '<h2>' . trn_t('HistoryTitle') . '</h2>';
    echo '<table class="Tabella trn-table"><tr><th>' . trn_t('ColDate') . '</th><th>' . trn_t('ColName') . '</th><th>' . trn_t('ColKind')
        . '</th><th>' . trn_t('ColCategory') . '</th><th>' . trn_t('CsvDistances') . '</th><th>' . trn_t('CsvScore') . '</th><th>'
        . trn_t('ColPerArrow') . '</th><th>' . trn_t('CsvQualRank') . '</th><th>' . trn_t('CsvFinalRank') . '</th><th>' . trn_t('ColDuels') . '</th></tr>';
    foreach ($history as $h) {
        echo '<tr><td>' . trn_esc(trn_date($h['Date'])) . '</td><td>' . trn_esc($h['Name']) . ' <span class="trn-note">' . trn_esc($h['Format']) . '</span></td>'
            . '<td>' . trn_t($h['Kind'] === 'competition' ? 'KindCompetition' : 'KindTraining') . (intval($h['Sim'] ?? 0) ? ' <span class="trn-badge">' . trn_t('DuelsOnly') . '</span>' : '') . '</td>'
            . '<td>' . trn_esc($h['Division'] . $h['Class']) . '</td><td>' . trn_esc($h['Distances']) . '</td>'
            . '<td class="Right">' . (intval($h['Sim'] ?? 0) ? '' : intval($h['Score'])) . '</td><td class="Right">' . $num($perArrow($h)) . '</td>'
            . '<td class="Center">' . (intval($h['QualRank']) ?: '') . '</td><td class="Center">' . (intval($h['FinalRank']) ?: '') . '</td>'
            . '<td class="Center">' . (intval($h['Won']) + intval($h['Lost']) ? intval($h['Won']) . ' - ' . intval($h['Lost']) : '') . '</td></tr>';
    }
    echo '</table>';
    trn_page_close();
    exit;
}

// List of the archers.
$q = trim((string)($_GET['q'] ?? ''));
trn_page_open(trn_text('FollowupTitle'));
echo '<p class="trn-lead">' . trn_t('FollowupLead') . '</p>';
echo '<form method="get" class="trn-toolbar"><input type="search" name="q" size="30" value="' . trn_esc($q) . '" placeholder="' . trn_t('LookupPlaceholder')
    . '"> <button type="submit" class="trn-btn">' . trn_t('Search') . '</button> <a class="trn-btn" href="followup.php?csv=all">' . trn_t('CsvAll') . '</a></form>';

$people = [];   // key => ['name', 'club', 'trainings', 'competitions', 'last']
$add = function ($key, $name, $club) use (&$people) {
    if (!isset($people[$key])) $people[$key] = ['name' => $name, 'club' => $club, 'trainings' => 0, 'competitions' => 0, 'last' => ''];
};
if ($q !== '') {
    if (mb_strlen($q) >= 2) {
        $like = StrSafe_DB($q . '%');
        $rs = safe_r_sql("SELECT DISTINCT EnCode, EnFirstName, EnName FROM Entries
            WHERE EnAthlete = 1 AND (EnCode = " . StrSafe_DB($q) . " OR EnFirstName LIKE $like OR EnName LIKE $like)
            ORDER BY EnFirstName, EnName LIMIT 60");
        while ($r = safe_fetch($rs)) $add(trn_archer_key($r->EnCode, $r->EnFirstName, $r->EnName), trim($r->EnFirstName . ' ' . $r->EnName), '');
        $rs = safe_r_sql("SELECT DISTINCT RsKey, RsFamily, RsGiven FROM TrainingResults
            WHERE RsLicence = " . StrSafe_DB($q) . " OR RsFamily LIKE $like OR RsGiven LIKE $like LIMIT 60");
        while ($r = safe_fetch($rs)) $add($r->RsKey, trim($r->RsFamily . ' ' . $r->RsGiven), '');
    }
} else {
    foreach (trn_archers(true) as $a) $add(trn_archer_key($a->ArCode, $a->ArFamilyName, $a->ArGivenName), trim($a->ArFamilyName . ' ' . $a->ArGivenName), $a->ArClubCode);
    $rs = safe_r_sql("SELECT RsKey, MAX(RsFamily) AS F, MAX(RsGiven) AS G FROM TrainingResults GROUP BY RsKey");
    while ($r = safe_fetch($rs)) $add($r->RsKey, trim($r->F . ' ' . $r->G), '');
}

if ($people) {
    $keys = array_map('strval', array_keys($people));
    $in = implode(',', array_map('StrSafe_DB', $keys));
    $rs = safe_r_sql("SELECT RsKey, COUNT(*) AS N, MAX(RsDate) AS D FROM TrainingResults WHERE RsKey IN ($in) GROUP BY RsKey");
    while ($r = safe_fetch($rs)) { $people[$r->RsKey]['trainings'] = intval($r->N); $people[$r->RsKey]['last'] = (string)$r->D; }
    $codes = [];
    foreach ($keys as $k) if (strncmp($k, 'L:', 2) === 0) $codes[] = StrSafe_DB(mb_substr($k, 2));
    if ($codes) {
        $rs = safe_r_sql("SELECT EnCode, COUNT(*) AS N, MAX(ToWhenFrom) AS D FROM Entries INNER JOIN Tournament ON ToId = EnTournament
            WHERE EnCode IN (" . implode(',', $codes) . ") AND EnTournament NOT IN (SELECT RnTournament FROM TrainingRuns)
            GROUP BY EnCode");
        while ($r = safe_fetch($rs)) {
            $k = 'L:' . $r->EnCode;
            $people[$k]['competitions'] = intval($r->N);
            if ((string)$r->D > $people[$k]['last']) $people[$k]['last'] = (string)$r->D;
        }
    }
    uasort($people, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    echo '<table class="Tabella trn-table"><tr><th>' . trn_t('ColArcher') . '</th><th>' . trn_t('FieldClub') . '</th><th>' . trn_t('ColTrainings')
        . '</th><th>' . trn_t('ColCompetitions') . '</th><th>' . trn_t('ColLastShoot') . '</th><th></th></tr>';
    foreach ($people as $k => $p) {
        echo '<tr><td>' . trn_esc($p['name']) . '</td><td>' . trn_esc($p['club']) . '</td><td class="Center">' . ($p['trainings'] ?: '')
            . '</td><td class="Center">' . ($p['competitions'] ?: '') . '</td><td>' . trn_esc(trn_date($p['last'])) . '</td>'
            . '<td><a class="trn-btn" href="followup.php?key=' . rawurlencode($k) . '">' . trn_t('Open') . '</a></td></tr>';
    }
    echo '</table>';
} else {
    echo '<p class="trn-note">' . trn_t($q !== '' ? 'LookupNone' : 'FollowupEmpty') . '</p>';
}
trn_page_close();
