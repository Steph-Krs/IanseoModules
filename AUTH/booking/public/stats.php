<?php
/**
 * booking/public/stats.php — the competitor's statistics.
 *
 * Every competition of THIS server the archer (their licence) took part in, EXCEPT those with
 * "No publication" (level 1 — private to the organiser): a competition not published in the
 * calendar keeps its scores out of the statistics. Unofficial data: warning at the top.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';

$archer = bk_require_archer();
$labels = bk_disc_labels();

$rs = safe_r_sql("SELECT ToId, ToName, ToWhere, ToWhenFrom, ToWhenTo,
            ToType, ToTypeName, ToTypeSubRule,
            EnDivision, EnClass, DivDescription, ClDescription,
            QuScore, QuClRank
    FROM Entries
    INNER JOIN Tournament ON ToId = EnTournament
    /* Qualifications is 1:1 with Entries (the core repairs the relation at the top of
       Partecipants/index.php) → INNER JOIN, never LEFT + IS NULL. */
    INNER JOIN Qualifications ON QuId = EnId
    LEFT  JOIN Divisions  ON DivTournament = ToId AND DivId = EnDivision
    LEFT  JOIN Classes   ON ClTournament = ToId AND ClId = EnClass
    LEFT  JOIN BookingCompetitions ON BcTournament = ToId
    WHERE EnCode = " . StrSafe_DB($archer->BaLicence) . " AND EnAthlete = 1
      AND COALESCE(BcPublishLevel, 2) <> 1
    ORDER BY ToWhenFrom DESC, ToId DESC");

$rows = array();
$comps = array();          // distinct ToId
$best = null;
$today = bk_today();    // YYYY-MM-DD string comparison, server-zone date (date() alone is UTC)
while ($r = safe_fetch($rs)) {
    // Statistics only cover competitions UNDER WAY or PAST: those not held yet (start date
    // after today) are left out.
    if (substr((string) $r->ToWhenFrom, 0, 10) > $today) continue;   // bytes: ASCII date
    $rows[] = $r;
    $comps[intval($r->ToId)] = true;
    $sc = intval($r->QuScore);
    if ($sc > 0 && ($best === null || $sc > $best)) $best = $sc;
}

bk_head(bk_t('NavStatsTitle'));
?>
<style>
#bk .bk-stat-note { margin:0 0 16px; padding:11px 14px; font-size:13px; border-radius:8px;
    background:#fdf0e6; border:1px solid #cb8137; color:#8a5a26; }
#bk .bk-stat-sum { display:flex; flex-wrap:wrap; gap:12px; margin:0 0 16px; }
#bk .bk-stat-card { flex:1 1 140px; background:#fff; border:1px solid #d2d4d6; border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:12px 14px; text-align:center; }
#bk .bk-stat-n { font-size:24px; font-weight:700; color:#01367c; }
#bk .bk-stat-l { font-size:12px; color:#7d8183; margin-top:2px; }
#bk .bk-stat-list { display:flex; flex-direction:column; gap:8px; }
#bk .bk-stat-row { display:flex; flex-wrap:wrap; gap:6px 14px; align-items:baseline;
    background:#fff; border:1px solid #d2d4d6; border-left:4px solid #0254a8; border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:10px 14px; }
#bk .bk-stat-ic { color:#0254a8; display:inline-flex; align-items:center; flex:0 0 auto; }
#bk .bk-stat-main { flex:1 1 240px; min-width:0; }
#bk .bk-stat-name { font-weight:600; color:#20263d; }
#bk .bk-stat-meta { font-size:12px; color:#7d8183; margin-top:2px; }
#bk .bk-stat-cat { display:inline-block; padding:2px 8px; border-radius:5px; font-size:12px;
    background:#f0f4ff; border:1px solid #a7d6ff; color:#01367c; }
#bk .bk-stat-res { flex:0 0 auto; text-align:right; }
#bk .bk-stat-score { font-size:18px; font-weight:700; color:#01367c; }
#bk .bk-stat-rank { font-size:12px; color:#7d8183; }
</style>
<?php
$out = '<div class="bk-stat-note">' . bk_t('StatNote') . '</div>';
if (!$rows) {
    echo $out . '<p class="bk-empty">' . bk_e(bk_t('StatNone')) . ' <a href="' . bk_e(bk_public_url('calendar.php')) . '">'
        . bk_e(bk_t('SeeOpenComps')) . '</a>.</p>';
    bk_foot();
    exit;
}
$card = function ($n, $label) {
    return '<div class="bk-stat-card"><div class="bk-stat-n">' . $n . '</div><div class="bk-stat-l">' . bk_e($label) . '</div></div>';
};
$out .= '<div class="bk-stat-sum">'
    . $card(count($comps), bk_t(count($comps) > 1 ? 'StatComps' : 'StatComp'))
    . $card(count($rows), bk_t(count($rows) > 1 ? 'StatParts' : 'StatPart'))
    . $card($best !== null ? intval($best) : '—', bk_t('StatBest'))
    . '</div><div class="bk-stat-list">';
foreach ($rows as $r) {
    $dd = bk_comp_discipline($r->ToType, $r->ToTypeSubRule, $r->ToTypeName);
    $disc = ($labels[$dd['key']] ?? '') . ($dd['para'] ? ' — Para' : '');
    $cat = trim(($r->DivDescription ?: $r->EnDivision) . ' / ' . ($r->ClDescription ?: $r->EnClass), ' /');
    $sc = intval($r->QuScore);
    $rk = intval($r->QuClRank);
    $out .= '<div class="bk-stat-row"><span class="bk-stat-ic">' . bk_disc_icon($dd['key'], 22) . ($dd['para'] ? bk_disc_icon_para(14) : '') . '</span>'
        . '<div class="bk-stat-main"><div class="bk-stat-name">' . bk_e($r->ToName) . '</div>'
        . '<div class="bk-stat-meta">' . bk_e(bk_date_range($r->ToWhenFrom, $r->ToWhenTo))
        . ($disc !== '' ? ' · ' . bk_e($disc) : '') . ($r->ToWhere ? ' · ' . bk_e($r->ToWhere) : '') . '</div>'
        . ($cat !== '' ? '<span class="bk-stat-cat">' . bk_e($cat) . '</span>' : '') . '</div>'
        . '<div class="bk-stat-res"><div class="bk-stat-score">' . ($sc > 0 ? $sc : '—') . '</div>'
        . '<div class="bk-stat-rank">' . ($rk > 0 ? bk_t('StatRanked', $rk) : bk_e(bk_t('StatUnranked'))) . '</div></div></div>';
}
echo $out . '</div><p class="bk-hint" style="margin-top:12px">' . bk_e(bk_t('StatHint')) . '</p>';
bk_foot();
