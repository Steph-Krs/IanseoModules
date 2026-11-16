<?php
/**
 * public/stats-comp.php?e=<EnId> — the archer's statistics on one competition (lib/compstats.php):
 * points end by end (target by target on a course), average arrow, success zone at the percentage
 * the archer sets, spread of the arrows, compared with the competition, the category, or an archer
 * of the competition picked in a list (?cmp=<EnId>: that archer's figures, as JSON).
 *
 * Only the archer's own shoots. The others appear once the results are public (competition over,
 * or results shown by the organiser) — the same results ianseo publishes.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/compstats.php';

$archer = bk_require_archer();
$row = bk_cs_entry(intval($_GET['e'] ?? 0), $archer->BaLicence);
$canCmp = $row ? bk_cs_can_compare($row) : false;
$layout = $row ? bk_cs_layout($row->ToId) : array();
$nDist = $row ? intval($row->ToNumDist) : 0;

// An archer of the competition picked for comparison: their figures only.
if (isset($_GET['cmp'])) {
    if (!$row || !$canCmp) JsonOut(array('error' => 1, 'msg' => bk_t('CsNoCompare')));
    $one = bk_cs_one($row->ToId, intval($_GET['cmp']), $nDist, $layout);
    JsonOut($one ? array('error' => 0, 'msg' => '', 'series' => $one) : array('error' => 1, 'msg' => bk_t('CsNoStats')));
}

if (!$row) {
    bk_head(bk_t('CsTitle'), 'card');
    echo '<div class="bk-card"><h1>' . bk_e(bk_t('CsTitle')) . '</h1>' . bk_msg('err', bk_t('CsNoStats'))
        . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('stats.php')) . '">' . bk_e(bk_t('NavStatsTitle')) . '</a></p></div>';
    bk_foot();
    exit;
}

$tourId = intval($row->ToId);
$mine = bk_cs_parse($row, $nDist, $layout);
$me = bk_cs_figures(array($mine));
$me['name'] = bk_t('CsMe');
$comp = $cat = null;
$others = array();
if ($canCmp && $mine) {
    $pAll = $pCat = array();
    foreach (bk_cs_entries($tourId) as $r) {
        $p = bk_cs_parse($r, $nDist, $layout);
        $pAll[] = $p;
        if ($r->EnDivision === $row->EnDivision && $r->EnClass === $row->EnClass) $pCat[] = $p;
        if (intval($r->EnId) !== intval($row->EnId)) {
            $others[] = array('id' => intval($r->EnId), 'name' => trim($r->EnFirstName . ' ' . $r->EnName),
                'cat' => trim(($r->DivDescription ?: $r->EnDivision) . ' ' . ($r->ClDescription ?: $r->EnClass)));
        }
    }
    $comp = bk_cs_figures($pAll);
    $comp['name'] = bk_t('CsComp');
    $cat = bk_cs_figures($pCat);
    $cat['name'] = bk_t('CsCat');
}

$field = in_array(intval($row->ToCategory), array(4, 8), true);
$labels = bk_cs_dist_labels($row);
$dists = array();
foreach ($me['ends'] as $d => $ends) $dists[] = array('d' => $d, 'label' => $labels[$d], 'n' => count($ends));
$num = function ($v, $dec = 1) {
    $s = bk_number_seps();
    return $v === null ? '—' : number_format((float) $v, $dec, $s['dec'], $s['thousands']);
};

bk_head(bk_t('CsTitle'));
$dd = bk_comp_discipline($row->ToType, $row->ToTypeSubRule, $row->ToTypeName);
$out = '<link rel="stylesheet" href="' . bk_e(bk_public_url('assets/stats.css')) . '?v=' . bk_e(bk_version()) . '">'
    . '<p class="bk-back"><a href="' . bk_e(bk_public_url('stats.php')) . '">← ' . bk_e(bk_t('NavStatsTitle')) . '</a></p>'
    . '<div class="bk-detail"><div class="bk-detail-head"><span class="bk-detail-ic">' . bk_disc_icon($dd['key'], 34) . '</span><div>'
    . '<h1>' . bk_e(bk_t('CsTitle')) . '</h1><p class="bk-detail-sub"><span>' . bk_e($row->ToName) . '</span>'
    . '<span>' . bk_e(bk_date_range($row->ToWhenFrom, $row->ToWhenTo)) . '</span>'
    . '<span>' . bk_e(($row->DivDescription ?: $row->EnDivision) . ' · ' . ($row->ClDescription ?: $row->EnClass)) . '</span></p></div></div>';

// Headline figures.
$best = null;
foreach ($me['ends'] as $ends) foreach ($ends as $v) if ($v !== null && ($best === null || $v > $best)) $best = $v;
$out .= '<div class="bk-stat-sum">'
    . '<div class="bk-stat-card"><div class="bk-stat-n">' . intval($row->QuScore) . '</div><div class="bk-stat-l">' . bk_e(bk_t('CsScore'))
    . (intval($row->QuClRank) > 0 ? ' · ' . bk_e(bk_t('StatRanked', intval($row->QuClRank))) : '') . '</div></div>'
    . '<div class="bk-stat-card"><div class="bk-stat-n">' . bk_e($num($me['avg'], 2)) . '</div><div class="bk-stat-l">' . bk_e(bk_t('CsAvgArrow'))
    . ($cat && $cat['avg'] !== null ? ' · ' . bk_e(bk_t('CsCatShort', $num($cat['avg'], 2))) : '') . '</div></div>'
    . '<div class="bk-stat-card"><div class="bk-stat-n">' . intval($me['arrows']) . '</div><div class="bk-stat-l">' . bk_e(bk_t('CsArrows')) . '</div></div>'
    . '<div class="bk-stat-card"><div class="bk-stat-n">' . ($best === null ? '—' : intval($best)) . '</div><div class="bk-stat-l">'
    . bk_e(bk_t($field ? 'CsBestTarget' : 'CsBestEnd')) . '</div></div></div>';

if (!$mine) {
    // Keyed in as totals: no arrow to read.
    $out .= '<p class="bk-note">' . bk_e(bk_t('CsTotalsOnly')) . '</p></div>';
    echo $out;
    bk_foot();
    exit;
}

$data = array(
    'field' => $field, 'face' => bk_cs_face_kind($row), 'dists' => $dists,
    'deerImg' => bk_public_url('assets/target-3d.png') . '?v=' . bk_version(),
    'bslImg' => bk_public_url('assets/target-beursault.png') . '?v=' . bk_version(),
    'series' => array('me' => $me, 'comp' => $comp, 'cat' => $cat), 'others' => $others,
    'cmpUrl' => bk_public_url('stats-comp.php?e=' . intval($row->EnId) . '&cmp='),
    't' => array('end' => bk_t($field ? 'CsTargetN' : 'CsEndN'), 'pts' => bk_t('CsPoints'), 'zone' => bk_t('CsZoneResult'),
        'zoneNone' => bk_t('CsZoneNone'), 'miss' => bk_t('CsMiss'), 'err' => bk_t('CsNoStats'), 'mine' => bk_t('CsMe')) + bk_number_seps(),
);

// Evolution, with what it can be compared with.
$cmp = '';
if ($canCmp) {
    $cmp = '<div class="bk-cs-cmp"><span>' . bk_e(bk_t('CsCompareWith')) . '</span>'
        . '<label><input type="checkbox" data-series="comp" checked> ' . bk_e(bk_t('CsComp')) . ' <small>(' . bk_e(bk_t('CsCount', intval($comp['count']))) . ')</small></label>'
        . '<label><input type="checkbox" data-series="cat" checked> ' . bk_e(bk_t('CsCat')) . ' <small>(' . bk_e(bk_t('CsCount', intval($cat['count']))) . ')</small></label>';
    if ($others) {
        $cmp .= '<select id="bk-cs-pick" aria-label="' . bk_e(bk_t('CsChooseArcher')) . '"><option value="">' . bk_e(bk_t('CsChooseArcher')) . '</option>';
        $grp = null;
        foreach ($others as $o) {
            if ($o['cat'] !== $grp) { $cmp .= ($grp !== null ? '</optgroup>' : '') . '<optgroup label="' . bk_e($o['cat']) . '">'; $grp = $o['cat']; }
            $cmp .= '<option value="' . $o['id'] . '">' . bk_e($o['name']) . '</option>';
        }
        $cmp .= '</optgroup></select>';
    }
    $cmp .= '</div>';
} else {
    $cmp = '<p class="bk-hint">' . bk_e(bk_t('CsNoCompare')) . '</p>';
}
$out .= '<div id="bk-cs" data-cs="' . bk_e(json_encode($data, JSON_UNESCAPED_UNICODE)) . '">'
    . '<div class="bk-sx-card"><h2>' . bk_e(bk_t($field ? 'CsEvolTargets' : 'CsEvolEnds')) . '</h2>' . $cmp
    . '<div class="bk-cs-legend" id="bk-cs-legend"></div><div class="bk-cs-chart" id="bk-cs-chart"><p class="bk-hint">' . bk_e(bk_t('CsChartNoJs')) . '</p></div></div>';

// Success zone and spread of the arrows.
$out .= '<div class="bk-sx-card"><h2>' . bk_e(bk_t('CsZone')) . '</h2><p class="bk-hint" style="margin-top:0">' . bk_e(bk_t('CsZoneHint')) . '</p>'
    . '<div class="bk-cs-zone"><label for="bk-cs-pct">' . bk_e(bk_t('CsZonePct')) . '</label>'
    . '<input type="range" id="bk-cs-pct" min="0" max="100" step="1" value="80"><output id="bk-cs-pctv" for="bk-cs-pct">80 %</output></div>'
    . '<p class="bk-cs-zres" id="bk-cs-zres" aria-live="polite"></p>'
    . '<div class="bk-cs-zbox"><div id="bk-cs-face"></div><div class="bk-cs-spread"><h3>' . bk_e(bk_t('CsSpread')) . '</h3><div id="bk-cs-bars"></div></div></div></div>';

// The chart's table (also what is read without script or colours).
$out .= '<div class="bk-sx-card"><h2>' . bk_e(bk_t('CsTable')) . '</h2><div class="bk-sx-hist"><table id="bk-cs-table"><thead><tr><th>'
    . bk_e(bk_t($field ? 'CsTargetCol' : 'CsEndCol')) . '</th><th class="num">' . bk_e(bk_t('CsMe')) . '</th>'
    . ($comp ? '<th class="num">' . bk_e(bk_t('CsComp')) . '</th><th class="num">' . bk_e(bk_t('CsCat')) . '</th>' : '')
    . '</tr></thead><tbody>';
foreach ($me['ends'] as $d => $ends) {
    if (count($me['ends']) > 1) $out .= '<tr class="bk-cs-dist"><th colspan="' . ($comp ? 4 : 2) . '">' . bk_e($labels[$d]) . '</th></tr>';
    foreach ($ends as $e => $v) {
        $out .= '<tr><td>' . ($e + 1) . '</td><td class="num"><b>' . ($v === null ? '—' : intval($v)) . '</b></td>'
            . ($comp ? '<td class="num">' . bk_e($num($comp['ends'][$d][$e] ?? null)) . '</td><td class="num">' . bk_e($num($cat['ends'][$d][$e] ?? null)) . '</td>' : '')
            . '</tr>';
    }
}
$out .= '</tbody></table></div></div></div></div>'
    . '<script src="' . bk_e(bk_public_url('assets/compstats.js')) . '?v=' . bk_e(bk_version()) . '"></script>';
echo $out;
bk_foot();
