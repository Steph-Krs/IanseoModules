<?php
/**
 * booking/public/stats.php — the competitor's statistics, overview.
 *
 * A few figures over every shot of THEIR licence on this server, then one folding block per
 * discipline listing its combinations (bow, distance, target face or peg): scores shot at another
 * distance or on another face do not compare, each combination has its own page
 * (stats-detail.php). Competitions with "No publication" (level 1, private to the organiser) and
 * those not started are left out (lib/stats.php). Unofficial data: warning at the top.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/stats.php';

$archer = bk_require_archer();
$rounds = bk_stats_rounds($archer->BaLicence);
$byDisc = bk_stats_combos($rounds);
$badges = bk_stats_badges($rounds);
$base = bk_public_url();

bk_head(bk_t('NavStatsTitle'));
$out = '<link rel="stylesheet" href="' . bk_e(bk_public_url('assets/stats.css')) . '?v=' . bk_e(bk_version()) . '">'
    . '<div class="bk-stat-note">' . bk_t('StatNote') . '</div>';
if (!$rounds) {
    echo $out . '<p class="bk-empty">' . bk_e(bk_t('StatNone')) . ' <a href="' . bk_e(bk_public_url('calendar.php')) . '">'
        . bk_e(bk_t('SeeOpenComps')) . '</a>.</p>';
    bk_foot();
    exit;
}

$n = function ($v, $one, $many) { return bk_t($v > 1 ? $many : $one, $v); };
$seps = bk_number_seps();
$num = function ($v, $dec = 0) use ($seps) { return number_format($v, $dec, $seps['dec'], $seps['thousands']); };
$card = function ($v, $label) { return '<div class="bk-stat-card"><div class="bk-stat-n">' . $v . '</div><div class="bk-stat-l">' . bk_e($label) . '</div></div>'; };

$tot = bk_stats_totals($rounds);
$out .= '<div class="bk-stat-sum">'
    . $card($num($tot['tours']), bk_t($tot['tours'] > 1 ? 'StatComps' : 'StatComp'))
    . $card($num(count($rounds)), bk_t(count($rounds) > 1 ? 'StatRounds' : 'StatRound'))
    . $card($num($tot['arrows']), bk_t('StatArrows'))
    . ($tot['walk'] > 0 ? $card(bk_e(bk_t('StatKm', $num($tot['walk'] / 1000, 1))), bk_t('StatWalk')) : '')
    . $card($num(count($byDisc)), bk_t(count($byDisc) > 1 ? 'StatDiscs' : 'StatDisc'))
    . $card(bk_e(substr($rounds[0]['date'], 0, 4)), bk_t('StatSince'))   // bytes: ASCII date
    . '</div>';

$caret = '<svg class="bk-sd-caret" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
    . ' stroke-width="2.2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
$first = true;
foreach ($byDisc as $disc => $combos) {
    $all = array();
    foreach ($combos as $c) $all = array_merge($all, $c['rounds']);
    $t = bk_stats_totals($all);

    // The highest distinction of the most recent combination that has one.
    $top = '';
    foreach ($combos as $c) {
        $b = bk_stats_combo_badges($c, $badges);
        if ($b && ($lv = bk_stats_top($b))) {
            $top = bk_dist_visual($b['ctx'], $lv['id'], bk_t('DistLvl' . $lv['id']), $base, 38);
            break;
        }
    }

    $out .= '<details class="bk-sd"' . ($first ? ' open' : '') . '><summary>'
        . '<span class="bk-sd-ic">' . bk_disc_icon(bk_stats_icon_key($disc), 26) . '</span>'
        . '<span class="bk-sd-t"><b>' . bk_e(bk_t('DistDisc' . $disc)) . '</b><span>'
        . bk_e($n(count($all), 'StatRoundN1', 'StatRoundN') . ' · ' . $n($t['tours'], 'StatCompN1', 'StatCompN')) . '</span></span>'
        . '<span class="bk-sd-top">' . $top . '</span>' . $caret . '</summary><div class="bk-sd-list">';
    $first = false;

    foreach ($combos as $c) {
        $best = bk_stats_best($c['rounds']);
        $last = end($c['rounds']);
        $b = bk_stats_combo_badges($c, $badges);
        $lv = $b ? bk_stats_top($b) : null;
        $from = substr($c['rounds'][0]['date'], 0, 4); $to = substr($last['date'], 0, 4);   // bytes: ASCII dates
        $where = bk_stats_combo_where($c);
        $out .= '<a class="bk-sd-combo" href="' . bk_e(bk_public_url('stats-detail.php?k=' . rawurlencode($c['key']))) . '">'
            . '<span class="bk-sd-main"><b>' . bk_e($c['bowName']) . '</b>' . ($where !== '' ? ' <span class="w">' . bk_e($where) . '</span>' : '')
            . '<small>' . bk_e($n(count($c['rounds']), 'StatRoundN1', 'StatRoundN') . ' · ' . ($from === $to ? $from : $from . ' – ' . $to)) . '</small></span>'
            . '<span class="bk-sd-num"><span class="v">' . ($best ? intval($best['score']) : '—') . '</span><span class="l">' . bk_e(bk_t('StatRecord')) . '</span></span>'
            . '<span class="bk-sd-num bk-sd-last"><span class="v">' . intval($last['score']) . '</span><span class="l">' . bk_e(bk_t('StatLast')) . '</span></span>'
            . '<span class="bk-sd-badge">' . ($lv ? bk_dist_visual($b['ctx'], $lv['id'], bk_t('DistLvl' . $lv['id']), $base, 40)
                : '<span class="bk-sd-none" title="' . bk_e(bk_t($b ? 'StatNoBadgeYet' : 'StatNoBadge')) . '">—</span>') . '</span>'
            . '<span class="bk-sd-go" aria-hidden="true">›</span></a>';
    }
    $out .= '</div></details>';
}

// The last competitions, newest first, each opening its combination.
$out .= '<h2 class="bk-stat-h">' . bk_e(bk_t('StatRecent')) . '</h2><div class="bk-stat-list">';
foreach (array_slice(array_reverse($rounds), 0, 5) as $r) {
    $rk = $r['rank'];
    $out .= '<a class="bk-stat-row" href="' . bk_e(bk_public_url('stats-detail.php?k=' . rawurlencode($r['combo']))) . '">'
        . '<span class="bk-stat-ic">' . bk_disc_icon(bk_stats_icon_key($r['disc']), 22) . '</span>'
        . '<div class="bk-stat-main"><div class="bk-stat-name">' . bk_e($r['name']) . '</div>'
        . '<div class="bk-stat-meta">' . bk_e(bk_date_range($r['date'], $r['dateTo'])) . ' · ' . bk_e(bk_t('DistDisc' . $r['disc']))
        . ($r['where'] !== '' ? ' · ' . bk_e($r['where']) : '') . '</div>'
        . '<span class="bk-stat-cat">' . bk_e(trim($r['bowName'] . ' / ' . $r['clsName'] . ($r['distText'] !== '' ? ' · ' . $r['dist'] . ' m' : ''), ' /')) . '</span></div>'
        . '<div class="bk-stat-res"><div class="bk-stat-score">' . intval($r['score']) . '</div>'
        . '<div class="bk-stat-rank">' . ($r['complete'] ? ($rk > 0 ? bk_t('StatRanked', $rk) : bk_e(bk_t('StatUnranked'))) : bk_e(bk_t('StatIncomplete')))
        . '</div></div></a>';
}
echo $out . '</div><p class="bk-hint" style="margin-top:12px">' . bk_e(bk_t('StatHint')) . '</p>';
bk_foot();
