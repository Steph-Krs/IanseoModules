<?php
/**
 * admin/survey.php — results of the archers' satisfaction survey, for the competition
 * open in ianseo. Organiser page (ianseo template and ACL).
 *
 * Only aggregated figures and anonymous comments: nothing on this page tells who
 * answered what. Benchmark: average of the other competitions of the server (each one
 * weighs the same). Markup produced in PHP (no template alternation).
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadOnly);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/archer.php';
require_once dirname(__DIR__) . '/lib/ui.php';
require_once dirname(__DIR__) . '/lib/survey.php';

bk_schema();

$TOUR = intval($_SESSION['TourId']);
$comp = bk_survey_access($TOUR, '')['comp'];
$res  = bk_survey_results($TOUR);
$part = bk_survey_participants($TOUR);
$bench = bk_survey_server_avg($TOUR);
$cfgUrl = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php';

/** Average 1-5 → "4,2" and a colour class. */
function sv_avg($v)
{
    return $v === null ? '—' : number_format($v, 1, ',', '');
}
function sv_tone($v)
{
    if ($v === null) return 'sv-none';
    return $v >= 4 ? 'sv-good' : ($v >= 3 ? 'sv-mid' : 'sv-low');
}

$h = '<div id="bksv">';
$h .= '<h1>Satisfaction des archers</h1>';

// Status of the survey for this competition.
if ($comp) {
    $st = '';
    if (intval($comp->Level) < 2) {
        $st = 'Les inscriptions en ligne de cette compétition sont <b>fermées</b> sur ce serveur : aucun questionnaire '
            . 'n\'est proposé aux archers.';
    } elseif (!intval($comp->SurveyOn)) {
        $st = 'Questionnaire <b>désactivé</b> dans les <a href="' . bk_e($cfgUrl) . '">réglages détaillés</a> de la compétition.';
    } elseif (!intval($comp->Started)) {
        $st = 'Le questionnaire s\'ouvrira le <b>' . bk_e(bk_date_fr($comp->OpenOn)) . '</b>, au lendemain de la compétition, '
            . 'pour ' . intval(BK_SURVEY_DAYS) . ' jours. Chaque archer classé le trouvera dans son espace licencié.';
    } elseif (!intval($comp->Ended)) {
        $st = 'Questionnaire <b>ouvert</b> jusqu\'au <b>' . bk_e(bk_date_fr($comp->CloseOn)) . '</b> : chaque archer classé '
            . 'le trouve dans son espace licencié.';
    } else {
        $st = 'Questionnaire <b>clos</b> depuis le ' . bk_e(bk_date_fr($comp->CloseOn)) . '.';
    }
    $h .= '<div class="sv-status">' . $st . '</div>';
}

$rate = $part > 0 ? round(100 * $res['answers'] / $part) : 0;
$h .= '<div class="sv-cards">'
    . '<div class="sv-kpi"><div class="sv-kv">' . intval($res['answers']) . '</div><div class="sv-kl">Réponses</div></div>'
    . '<div class="sv-kpi"><div class="sv-kv">' . intval($part) . '</div><div class="sv-kl">Archers classés</div></div>'
    . '<div class="sv-kpi"><div class="sv-kv">' . $rate . ' %</div><div class="sv-kl">Taux de réponse</div></div>'
    . '</div>';

if ($res['answers'] === 0) {
    $h .= '<p class="sv-empty">Aucune réponse pour l\'instant.</p>';
} else {
    $h .= '<p class="sv-legend">Note moyenne sur 5, puis la répartition des notes : '
        . '<span class="sv-sw sv-c1"></span>1 <span class="sv-sw sv-c2"></span>2 <span class="sv-sw sv-c3"></span>3 '
        . '<span class="sv-sw sv-c4"></span>4 <span class="sv-sw sv-c5"></span>5'
        . ($bench['comps'] > 0 ? ' — et la moyenne des ' . intval($bench['comps']) . ' autre(s) compétition(s) du serveur ayant des réponses.' : '.')
        . '</p>';
    $n = 0;
    foreach (bk_survey_questions() as $sec) {
        $n++;
        $h .= '<h2>' . $n . ') ' . bk_e($sec['title']) . '</h2><div class="sv-list">';
        foreach ($sec['items'] as $it) {
            $q = $res['q'][$it['col']];
            $h .= '<div class="sv-row"><div class="sv-lab">' . bk_e($it['label'])
                . (!empty($it['hint']) ? '<span>' . bk_e($it['hint']) . '</span>' : '')
                . (!empty($it['low']) ? '<span>1 = « ' . bk_e($it['low']) . ' », 5 = « Excellent »</span>' : '') . '</div>'
                . '<div class="sv-avg ' . sv_tone($q['avg']) . '">' . sv_avg($q['avg']) . '<small>/5</small></div>'
                . '<div class="sv-dist">';
            if ($q['n'] > 0) {
                $h .= '<div class="sv-bar">';
                for ($v = 1; $v <= 5; $v++) {
                    $c = $q['dist'][$v];
                    if ($c === 0) continue;
                    $w = round(100 * $c / $q['n'], 1);
                    $h .= '<span class="sv-c' . $v . '" style="width:' . $w . '%" title="' . $c . ' × ' . $v . '/5">'
                        . ($w >= 8 ? $c : '') . '</span>';
                }
                $h .= '</div>';
            }
            $b = $bench['avg'][$it['col']] ?? null;
            $h .= '<div class="sv-sub">' . intval($q['n']) . ' réponse' . ($q['n'] > 1 ? 's' : '')
                . ($b !== null ? ' · autres compétitions : <b>' . sv_avg($b) . '</b>' : '') . '</div>'
                . '</div></div>';
        }
        $h .= '</div>';
        foreach ($sec['texts'] as $tx) {
            $list = $res['texts'][$tx['col']];
            if (!$list) continue;
            $h .= '<h3>' . bk_e($tx['label']) . ' <span class="sv-count">(' . count($list) . ')</span></h3><div class="sv-texts">';
            foreach ($list as $t) $h .= '<blockquote>' . nl2br(bk_e($t)) . '</blockquote>';
            $h .= '</div>';
        }
    }
    $h .= '<p class="sv-note">Résultats anonymes : rien sur cette page ne permet de savoir qui a répondu quoi.</p>';
}
$h .= '</div>';

$PAGE_TITLE = 'Satisfaction des archers';
include('Common/Templates/head.php');
?>
<style>
#bksv { max-width:980px; margin:0 auto; padding:6px 10px 30px; color:#1f2d3d; }
#bksv h1 { font-size:22px; color:#01367c; margin:10px 0 12px; }
#bksv h2 { font-size:16px; color:#0254a8; margin:22px 0 8px; }
#bksv h3 { font-size:14px; color:#01367c; margin:16px 0 6px; }
#bksv .sv-status { font-size:13px; background:#eef4fb; border:1px solid #cddff2; border-radius:6px; padding:9px 12px; margin:0 0 14px; }
#bksv .sv-cards { display:flex; flex-wrap:wrap; gap:12px; margin:0 0 10px; }
#bksv .sv-kpi { flex:1 1 150px; background:#fff; border:1px solid #d5dee8; border-radius:8px; padding:10px 14px; }
#bksv .sv-kv { font-size:26px; font-weight:700; color:#01367c; line-height:1.1; }
#bksv .sv-kl { font-size:13px; color:#33506f; }
#bksv .sv-empty, #bksv .sv-note { font-size:13px; color:#7d8183; font-style:italic; }
#bksv .sv-legend { font-size:12px; color:#4c4e50; margin:6px 0 0; }
#bksv .sv-sw { display:inline-block; width:11px; height:11px; border-radius:2px; vertical-align:-1px; margin:0 2px 0 6px; }
#bksv .sv-list { display:flex; flex-direction:column; gap:8px; }
#bksv .sv-row { display:grid; grid-template-columns:minmax(180px, 2fr) 78px minmax(200px, 3fr); gap:12px; align-items:center;
  background:#fff; border:1px solid #e1e7ee; border-radius:8px; padding:9px 12px; }
#bksv .sv-lab { font-size:13.5px; font-weight:600; color:#1f3b5a; }
#bksv .sv-lab span { display:block; font-weight:400; font-size:11.5px; color:#7d8183; margin-top:2px; }
#bksv .sv-avg { font-size:24px; font-weight:700; text-align:center; }
#bksv .sv-avg small { font-size:12px; font-weight:400; color:#7d8183; margin-left:2px; }
#bksv .sv-good { color:#1c7a3a; } #bksv .sv-mid { color:#a86b00; } #bksv .sv-low { color:#b3261e; } #bksv .sv-none { color:#aab4bf; }
#bksv .sv-bar { display:flex; height:20px; border-radius:5px; overflow:hidden; background:#eef2f6; }
#bksv .sv-bar span { display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:#fff; }
#bksv .sv-c1 { background:#c8453b; } #bksv .sv-c2 { background:#e3843a; } #bksv .sv-c3 { background:#d8b43a; }
#bksv .sv-c4 { background:#7fb65a; } #bksv .sv-c5 { background:#2f9a57; }
#bksv .sv-sub { font-size:11.5px; color:#7d8183; margin-top:4px; }
#bksv .sv-count { font-weight:400; color:#7d8183; font-size:12px; }
#bksv .sv-texts blockquote { margin:0 0 8px; padding:8px 12px; background:#f7f9fb; border-left:4px solid #9dbbe0;
  border-radius:0 6px 6px 0; font-size:13px; color:#2b3a4a; }
@media (max-width:700px) { #bksv .sv-row { grid-template-columns:1fr 70px; } #bksv .sv-dist { grid-column:1 / -1; } }
</style>
<?php
echo $h;
include('Common/Templates/tail.php');
