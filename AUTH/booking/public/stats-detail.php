<?php
/**
 * booking/public/stats-detail.php — one combination of the competitor's statistics.
 *
 * A combination = a discipline, a bow, a distance and a target face (or a peg): the scores of
 * one page compare. Chart of every round, personal record, the next national distinction and
 * how far it is, the distinctions earned, a few figures, and the history (the chart's table).
 * ?k = the combination key (lib/stats.php), checked against the archer's own rounds.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/stats.php';

$archer = bk_require_archer();
$rounds = bk_stats_rounds($archer->BaLicence);
$byDisc = bk_stats_combos($rounds);
$key = (string) ($_GET['k'] ?? '');
$combo = null;
foreach ($byDisc as $combos) if (isset($combos[$key])) $combo = $combos[$key];
if (!$combo) bk_redirect('stats.php');

$badges = bk_stats_badges($rounds);
$b = bk_stats_combo_badges($combo, $badges);
$all = $combo['rounds'];
$done = array_values(array_filter($all, function ($r) { return $r['complete']; }));
$best = bk_stats_best($all);
$last = end($all);
$base = bk_public_url();
$honours = $b && $b['measure'] === 'honours';
$seps = bk_number_seps();
$num = function ($v, $dec = 0) use ($seps) { return number_format($v, $dec, $seps['dec'], $seps['thousands']); };
$pts = function ($v) use ($honours) { return bk_t($honours ? 'DistHonours' : 'DistPts', $v); };

$discName = bk_t('DistDisc' . $combo['disc']);
bk_head($discName);
$out = '<link rel="stylesheet" href="' . bk_e(bk_public_url('assets/stats.css')) . '?v=' . bk_e(bk_version()) . '">'
    . '<a class="bk-sx-back" href="' . bk_e(bk_public_url('stats.php')) . '">← ' . bk_e(bk_t('NavStatsTitle')) . '</a>';

$where = bk_stats_combo_where($combo);
$sex = $last['sex'] !== '' ? bk_t('StatSex' . $last['sex']) . ' - ' : '';
$out .= '<div class="bk-sx-head"><h1>' . bk_e($discName) . '</h1>'
    . '<div class="bk-sx-sub">' . bk_e($sex . $combo['bowName']) . ($where !== '' ? ' · <span>' . bk_e($where) . '</span>' : '') . '</div>'
    . '<div class="bk-sx-cat">' . bk_e(bk_t('StatLatestCat', $last['clsName'])) . '</div></div>';

// ---- Distinctions earned, the highest first (under the chart) ----
$earned = '';
if ($b && $b['earned']) {
    $earned = '<div class="bk-sx-card"><h2>' . bk_e(bk_t('StatEarned')) . '</h2><div class="bk-sx-earned">';
    foreach (array_reverse($b['ladder']) as $lv) {
        if (!isset($b['earned'][$lv['id']])) continue;
        $r = $b['earned'][$lv['id']];
        $name = bk_t('DistLvl' . $lv['id']);
        $earned .= '<div class="bk-sx-e" title="' . bk_e($r['name'] . ' — ' . bk_date_fr($r['date'])) . '"><div class="p">'
            . bk_dist_visual($b['ctx'], $lv['id'], $name, $base, 80) . '</div>'
            . '<b>' . bk_e($name) . '</b>' . bk_e($pts($lv['score'])) . ' <span class="ok" aria-hidden="true">✓</span>'
            . '<div>' . bk_e(bk_t('StatEarnedOn', bk_date_fr($r['date']))) . '</div></div>';
    }
    $earned .= '</div></div>';
}

// ---- Chart and the distinctions earned; the record, the next distinction and the figures beside ----
$chart = bk_stats_chart($done, $b);
$out .= '<div class="bk-sx-grid"><div class="bk-sx-main"><div class="bk-sx-card"><h2>' . bk_e(bk_t('StatChartTitle')) . '</h2>'
    . ($chart !== '' ? $chart : '<p class="bk-sx-msg">' . bk_e(bk_t('StatChartNone')) . '</p>') . '</div>' . $earned
    . '</div><div class="bk-sx-side">';

if ($best) {
    $out .= '<div class="bk-sx-card bk-sx-rec"><h2>' . bk_e(bk_t('StatPB')) . '</h2>' . bk_stats_gauge($best['score'], $best['max'])
        . '<div class="n">' . bk_e($best['name']) . '</div>'
        . '<div class="m">' . bk_e(trim($best['where'] . ' · ' . bk_date_range($best['date'], $best['dateTo']), ' ·')) . '</div>';
    $series = 0;
    foreach ($done as $r) $series = max($series, $r['best1']);
    if ($series > 0) $out .= '<div class="s">' . bk_e(bk_t('StatBestSeries', $series)) . '</div>';
    $out .= '</div>';
}

$out .= '<div class="bk-sx-card"><h2>' . bk_e(bk_t('StatNext')) . '</h2>';
$out .= ($b ? bk_stats_next_html($b, $base, 80) : '<p class="bk-sx-msg">' . bk_e(bk_t('StatNoBadge')) . '</p>') . '</div>';

// Figures: rounds, arrows, metres walked to fetch them, this season against the last one.
$tot = bk_stats_totals($all);
$season = bk_stats_season(bk_today());
$avg = function ($y) use ($done) {
    $s = array();
    foreach ($done as $r) if (bk_stats_season($r['date']) === $y) $s[] = $r['score'];
    return $s ? array('avg' => array_sum($s) / count($s), 'best' => max($s)) : null;
};
$now = $avg($season); $prev = $avg($season - 1);
$fig = function ($v, $l) { return '<div><dt>' . bk_e($l) . '</dt><dd>' . $v . '</dd></div>'; };
$out .= '<div class="bk-sx-card"><h2>' . bk_e(bk_t('StatFigures')) . '</h2><dl class="bk-sx-figs">'
    . $fig($num(count($all)), bk_t(count($all) > 1 ? 'StatRounds' : 'StatRound'))
    . $fig($num($tot['arrows']), bk_t('StatArrows'))
    . ($tot['walk'] > 0 ? $fig(bk_e(bk_t('StatKm', $num($tot['walk'] / 1000, 2))), bk_t('StatWalk')) : '')
    . ($now ? $fig($num($now['avg'], 1), bk_t('StatSeasonAvg', $season)) . $fig(intval($now['best']), bk_t('StatSeasonBest', $season)) : '')
    . ($prev ? $fig($num($prev['avg'], 1), bk_t('StatSeasonAvg', $season - 1)) : '')
    . '</dl></div></div></div>';

// ---- History: every round of the combination (the chart's table) ----
$out .= '<div class="bk-sx-card"><h2>' . bk_e(bk_t('StatHistory')) . '</h2><div class="bk-sx-hist"><table><thead><tr>'
    . '<th>' . bk_e(bk_t('StatThDate')) . '</th><th>' . bk_e(bk_t('StatThComp')) . '</th><th>' . bk_e(bk_t('StatThCat')) . '</th>'
    . '<th class="num">' . bk_e(bk_t('StatThScore')) . '</th><th class="num">' . bk_e(bk_t('StatThRank')) . '</th></tr></thead><tbody>';
foreach (array_reverse($all) as $r) {
    $isRec = $best && $r === $best;
    // Imported from the federation record: said so, with what makes it count less.
    $from = array();
    if ($r['src'] === 'pal') {
        $from[] = bk_t('StatFromPal');
        if (!in_array($r['conf'], array('exact', 'data'), true)) $from[] = bk_t('StatGuessed');
        if (!$r['official']) $from[] = bk_t('StatUnofficial');
    }
    $out .= '<tr class="' . ($isRec ? 'rec' : '') . (!$r['complete'] ? ' inc' : '') . '">'
        . '<td>' . bk_e(bk_date_fr($r['date'])) . '</td>'
        . '<td>' . bk_e($r['name']) . ($r['where'] !== '' ? '<br><small>' . bk_e($r['where']) . '</small>' : '')
        . ($from ? '<br><small class="pal">' . bk_e(implode(' · ', $from)) . '</small>' : '') . '</td>'
        . '<td>' . bk_e($r['clsName']) . '</td>'
        . '<td class="num"><b>' . intval($r['score']) . '</b>' . ($honours && $r['honours'] !== null ? '<br><small>' . bk_e(bk_t('DistHonours', $r['honours'])) . '</small>' : '')
        . ($isRec ? '<span class="tag">' . bk_e(bk_t('StatRecord')) . '</span>' : '')
        . (!$r['complete'] ? '<span class="tag">' . bk_e(bk_t('StatIncomplete')) . '</span>' : '') . '</td>'
        . '<td class="num">' . ($r['rank'] > 0 ? bk_t('StatRanked', $r['rank']) : '—') . '</td></tr>';
}
$out .= '</tbody></table></div></div>'
    . '<p class="bk-hint" style="margin-top:12px">' . bk_e(bk_t('StatDetailHint')) . '</p>'
    . '<script src="' . bk_e(bk_public_url('assets/stats.js')) . '?v=' . bk_e(bk_version()) . '"></script>';
echo $out;
bk_foot();
