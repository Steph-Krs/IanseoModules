<?php
/**
 * AUTH module — usage statistics of the server (ADMIN only).
 *
 * Read-only view of the aggregated counters of stats-usage.php (no personal data) + a few
 * business figures taken from the accounts/registrations. Two tabs, Organisers / Archers, to
 * tell the audiences apart.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/legal-lib.php');
require_once(dirname(__DIR__) . '/stats-usage.php');

checkFullACL(AclRoot, '', AclReadWrite);
// same lock as Update/index.php: reserved to the ADMIN account when the authentication is on
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

aut_ensure_schema();
aut_stats_ensure_schema();

$root = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/';
$hasArchers = (bool) safe_fetch(safe_r_sql("SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BookingArchers'", false, true));

$days = intval($_GET['days'] ?? 30);
if (!in_array($days, array(7, 30, 90), true)) $days = 30;
$activeTab = (($_REQUEST['tab'] ?? '') === 'archers' && $hasArchers) ? 'archers' : 'org';
// Device filter: every figure of the page (both tabs) is restricted to that device.
$dev = aut_stats_dev($_GET['dev'] ?? '');

/* ---- Data ---- */
$publicUniq  = aut_stats_uniques('public', $days, $dev);
$publicViews = aut_stats_views('public', $days, $dev);

$data = array();
foreach (array('org', 'archer') as $sp) {
    $data[$sp] = array(
        'views'   => aut_stats_views($sp, $days, $dev),
        'uniques' => aut_stats_uniques($sp, $days, $dev),
        'daily'   => aut_stats_daily($sp, $days, $dev),
        'hourly'  => aut_stats_hourly($sp, $days, $dev),
        'top'     => aut_stats_top_pages($sp, $days, 8, $dev),
        'devices' => aut_stats_devices($sp, $days),   // never filtered: it is the selector
    );
}
$orgBiz = aut_stats_org_business($days);
$arcBiz = $hasArchers ? aut_stats_archer_business() : null;

/* ---- Rendering helpers ---- */
function st_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

function st_kpi($value, $label, $sub = '') {
    return '<div class="st-kpi"><div class="st-kv">' . st_h($value) . '</div>'
       . '<div class="st-kl">' . st_h($label) . '</div>'
       . ($sub !== '' ? '<div class="st-ks">' . st_h($sub) . '</div>' : '') . '</div>';
}

/** Vertical bars. $items = [ ['label'=>, 'value'=>, 'title'=>], ... ] */
function st_vbars($items) {
    $vals = array_map(function ($i) { return $i['value']; }, $items);
    $max = max(1, $vals ? max($vals) : 0);
    $out = '<div class="st-chart">';
    foreach ($items as $i) {
        $hpc = round(100 * $i['value'] / $max);
        $out .= '<div class="st-col" title="' . st_h($i['title']) . '">'
           . '<div class="st-bar" style="height:' . $hpc . '%"></div>'
           . '<span class="st-xl">' . st_h($i['label']) . '</span></div>';
    }
    return $out . '</div>';
}

/** Horizontal bars for the top pages. */
function st_hbars($items) {
    if (!$items) return '<p class="st-empty">' . st_h(aut_t('StNoData')) . '</p>';
    $max = max(1, max(array_map(function ($i) { return $i['views']; }, $items)));
    $out = '<div class="st-hb">';
    foreach ($items as $i) {
        $w = round(100 * $i['views'] / $max);
        $out .= '<div class="st-row"><span class="st-rl">' . st_h($i['page']) . '</span>'
           . '<span class="st-track"><span class="st-fill" style="width:' . $w . '%"></span></span>'
           . '<span class="st-rv">' . st_h($i['views']) . '</span></div>';
    }
    return $out . '</div>';
}

function st_dev_labels() { return array('mobile' => aut_t('StPhone'), 'tablet' => aut_t('StTablet'), 'desktop' => aut_t('StComputer')); }

/** '2026-09-29' → '29/09/2026'. */
function st_dmy($d) {
    // bytes: a SQL date is ASCII
    return substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4);
}

/**
 * Split by device, one bar per device class. Each row is a link that filters the whole
 * page on that device; clicking the selected row again removes the filter.
 */
function st_devices($dev, $tab, $days, $sel) {
    $totU = 0; $totV = 0;
    foreach ($dev['rows'] as $r) { $totU += $r['uniques']; $totV += $r['views']; }
    $out = '<h3 class="st-h3">' . st_h(aut_t('StDevices')) . ' <span class="st-note">('
       . st_h(aut_t('StDevicesNote') . ($dev['since'] ? ' ; ' . aut_t('StDevicesSince', st_dmy($dev['since'])) : '') . ' ; ' . aut_t('StDevicesIpad'))
       . ')</span></h3>';
    if ($totU === 0) return $out . '<p class="st-empty">' . st_h(aut_t('StNoData')) . '</p>';
    $out .= '<div class="st-hb">';
    foreach (st_dev_labels() as $k => $lab) {
        $r = $dev['rows'][$k];
        $pc = round(100 * $r['uniques'] / $totU);
        $pv = $totV ? round(100 * $r['views'] / $totV) : 0;
        $on = ($sel === $k);
        $href = '?tab=' . $tab . '&days=' . (int) $days . ($on ? '' : '&dev=' . $k);
        $out .= '<a class="st-row st-devrow' . ($on ? ' on' : '') . '" href="' . st_h($href) . '"'
           . ' title="' . st_h($on ? aut_t('StFilterOff') : aut_t('StFilterOn', $lab)) . '">'
           . '<span class="st-rl">' . ($on ? '✓ ' : '') . st_h($lab) . '</span>'
           . '<span class="st-track"><span class="st-fill" style="width:' . $pc . '%"></span></span>'
           . '<span class="st-rv" style="min-width:210px">' . st_h(aut_t('StDevShare', array('u' => $pc, 'v' => $pv))) . '</span></a>';
    }
    return $out . '</div>';
}

/** Items of a daily series. */
function st_daily_items($daily) {
    $out = array(); $i = 0;
    foreach ($daily as $d) {
        // bytes: a SQL date is ASCII
        $lab = ($i % 5 === 0) ? substr($d['day'], 8, 2) : '';
        $dm  = substr($d['day'], 8, 2) . '/' . substr($d['day'], 5, 2);
        $out[] = array('label' => $lab, 'value' => $d['views'],
            'title' => aut_t('StDayTip', array('day' => $dm, 'v' => $d['views'], 'u' => $d['uniques'])));
        $i++;
    }
    return $out;
}

/** Items of an hourly split. */
function st_hourly_items($hours) {
    $out = array();
    for ($h = 0; $h < 24; $h++) {
        $out[] = array('label' => ($h % 3 === 0) ? sprintf('%02d', $h) : '',
            'value' => $hours[$h], 'title' => aut_t('StHourTip', array('h' => sprintf('%02d', $h), 'v' => $hours[$h])));
    }
    return $out;
}

/** Renders a whole tab (audience charts shared by org/archer). */
function st_render_traffic($d, $days, $tab, $sel) {
    $on = $sel !== '' ? ' · ' . mb_strtolower(st_dev_labels()[$sel]) : '';
    return '<div class="st-cards">'
        . st_kpi($d['views'], aut_t('StViews'), aut_t('StOverDays', $days) . $on)
        . st_kpi($d['uniques'], aut_t('StUniques'), aut_t('StOverDays', $days) . $on)
        . "</div>\n"
        . st_devices($d['devices'], $tab, $days, $sel) . "\n"
        . '<h3 class="st-h3">' . st_h(aut_t('StDaily')) . "</h3>\n"
        . st_vbars(st_daily_items($d['daily'])) . "\n"
        . '<h3 class="st-h3">' . st_h(aut_t('StHourly')) . ' <span class="st-note">(' . st_h(aut_t('StHourlyNote')) . ")</span></h3>\n"
        . st_vbars(st_hourly_items($d['hourly'])) . "\n"
        . '<h3 class="st-h3">' . st_h(aut_t('StTopPages')) . "</h3>\n"
        . st_hbars($d['top']) . "\n";
}

$PAGE_TITLE = aut_t('MenuStats');
include('Common/Templates/head.php');
$cookieUrl = function_exists('aut_legal_url') ? aut_legal_url('cookies') : '';
?>
<style>
#aut-tabs { display:flex; gap:8px; margin:6px 0 14px; }
#aut-tabs button { padding:9px 18px; border:1px solid #c9d4df; border-radius:6px 6px 0 0;
    background:#eef2f6; color:#334; font-size:14px; cursor:pointer; }
#aut-tabs button.on { background:#1a4f8b; color:#fff; border-color:#1a4f8b; font-weight:600; }
.aut-pane { display:none; }
.aut-pane.on { display:block; }
.st-intro { font-size:12.5px; color:#4c4e50; background:#f2f6fb; border:1px solid #d5e2f0;
    border-radius:6px; padding:9px 12px; margin:0 0 14px; }
.st-period { margin:0 0 14px; font-size:13px; }
.st-period a { display:inline-block; padding:4px 12px; border:1px solid #c9d4df; border-radius:16px;
    margin-right:6px; text-decoration:none; color:#33506f; }
.st-period a.on { background:#0254a8; color:#fff; border-color:#0254a8; font-weight:600; }
.st-cards { display:flex; flex-wrap:wrap; gap:12px; margin:4px 0 8px; }
.st-kpi { flex:1 1 130px; min-width:130px; background:#fff; border:1px solid #d5dee8;
    border-radius:8px; padding:12px 14px; }
.st-kv { font-size:26px; font-weight:700; color:#01367c; line-height:1.1; }
.st-kl { font-size:13px; color:#33506f; margin-top:2px; }
.st-ks { font-size:11px; color:#8a97a5; margin-top:1px; }
.st-h3 { color:#01367c; font-size:14px; margin:20px 0 8px; }
.st-note { font-weight:400; color:#8a97a5; font-size:11px; }
.st-chart { display:flex; align-items:flex-end; gap:2px; height:130px; padding:8px 4px 0;
    background:#fbfcfe; border:1px solid #e5ebf2; border-radius:6px; }
.st-col { flex:1 1 0; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; }
.st-bar { width:72%; min-height:2px; background:linear-gradient(#4a86c6,#0254a8); border-radius:3px 3px 0 0; }
.st-xl { font-size:9px; color:#8a97a5; margin-top:3px; height:11px; }
.st-hb { display:flex; flex-direction:column; gap:6px; }
.st-row { display:flex; align-items:center; gap:8px; font-size:12.5px; }
.st-rl { flex:0 0 190px; color:#33506f; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.st-track { flex:1 1 auto; height:14px; background:#eef2f6; border-radius:7px; overflow:hidden; }
.st-fill { display:block; height:100%; background:linear-gradient(90deg,#4a86c6,#0254a8); }
.st-rv { flex:0 0 auto; color:#01367c; font-weight:600; min-width:38px; text-align:right; }
.st-empty { color:#8a97a5; font-size:13px; font-style:italic; }
.st-roles { font-size:12.5px; color:#4c4e50; margin:6px 0 0; }
.st-roles code { background:#eef2f6; padding:1px 5px; border-radius:4px; }
.st-devrow { text-decoration:none; color:inherit; border-radius:6px; padding:3px 6px; margin:0 -6px; }
.st-devrow:hover { background:#eef4fb; }
.st-devrow.on { background:#e2ecf8; box-shadow:inset 0 0 0 1px #9dbbe0; }
.st-devrow.on .st-rl { color:#01367c; font-weight:600; }
.st-filter { font-size:13px; color:#123a63; background:#fdf6e3; border:1px solid #ecd9a4;
    border-radius:6px; padding:8px 12px; margin:0 0 14px; }
.st-filter a { font-weight:600; margin-left:6px; }
@media (max-width:600px){ .st-rl { flex-basis:120px; } }
</style>
<?php
echo '<div class="st-intro">' . aut_t('StIntro')
    . ($cookieUrl ? ' ' . aut_t('StIntroCookies', st_h($cookieUrl)) : '') . ".</div>\n";

echo '<div class="st-period">' . st_h(aut_t('StPeriod')) . ' ';
foreach (array(7, 30, 90) as $k) {
    echo '<a href="?tab=' . st_h($activeTab) . '&amp;days=' . $k . ($dev !== '' ? '&amp;dev=' . st_h($dev) : '') . '" class="' . ($days === $k ? 'on' : '') . '">'
        . st_h(aut_t('StDays', $k)) . '</a> ';
}
echo '&nbsp;·&nbsp; <span class="st-note">' . st_h(aut_t('StPublic', array('u' => (int) $publicUniq, 'v' => (int) $publicViews))) . "</span></div>\n";

if ($dev !== '') {
    $since = aut_stats_devices('org', $days)['since'];
    echo '<div class="st-filter">' . aut_t('StFilterIs', '<b>' . st_h(st_dev_labels()[$dev]) . '</b>')
       . ($since && $since > aut_stats_from($days) ? ' ' . st_h(aut_t('StFilterSince', st_dmy($since))) : '')
       . ' <a href="?tab=' . st_h($activeTab) . '&amp;days=' . (int) $days . '">✕ ' . st_h(aut_t('StAllDevices')) . "</a></div>\n";
}

echo '<div id="aut-tabs">'
    . '<button type="button" data-pane="org" class="' . ($activeTab === 'org' ? 'on' : '') . '">🏹 ' . st_h(aut_t('UsOrgTitle')) . '</button>'
    . ($hasArchers ? '<button type="button" data-pane="archers" class="' . ($activeTab === 'archers' ? 'on' : '') . '">🎯 ' . st_h(aut_t('StArchers')) . '</button>' : '')
    . "</div>\n";

$lbl = array('CLUB' => 'Club', 'CD' => 'CD', 'CR' => 'CR', 'FED' => 'FFTA', 'ADMIN' => 'Admin');
$parts = array();
foreach ($lbl as $k => $v) if (!empty($orgBiz['roles'][$k])) $parts[] = '<code>' . st_h($v) . ' ' . (int) $orgBiz['roles'][$k] . '</code>';
echo '<div class="aut-pane' . ($activeTab === 'org' ? ' on' : '') . '" id="pane-org">'
    . '<div class="st-cards">'
    . st_kpi($orgBiz['total'], aut_t('StOrgAccounts'))
    . st_kpi($orgBiz['active'], aut_t('StActiveAccounts'))
    . st_kpi($orgBiz['logins'], aut_t('StLogins'), aut_t('StOverDays', $days))
    . "</div>\n"
    . '<p class="st-roles">' . st_h(aut_t('StByRole')) . ' '
    . ($parts ? implode(' ', $parts) : '<span class="st-empty">' . st_h(aut_t('StNoAccount')) . '</span>')
    . "</p>\n"
    . st_render_traffic($data['org'], $days, 'org', $dev)
    . "</div>\n";

if ($hasArchers) {
    echo '<div class="aut-pane' . ($activeTab === 'archers' ? ' on' : '') . '" id="pane-archers">'
        . '<div class="st-cards">'
        . st_kpi($arcBiz['total'], aut_t('StArcAccounts'))
        . st_kpi($arcBiz['active'], aut_t('StActiveAccounts'))
        . st_kpi($arcBiz['conv_rate'] . ' %', aut_t('StConversion'), aut_t('StConverted', array('n' => $arcBiz['converted'], 'total' => $arcBiz['total'])))
        . st_kpi($arcBiz['registrars'], aut_t('StRegistrars'))
        . "</div>\n"
        . st_render_traffic($data['archer'], $days, 'archers', $dev)
        . "</div>\n";
}
?>
<script>
(function () {
    var tabs = [].slice.call(document.querySelectorAll('#aut-tabs button'));
    tabs.forEach(function (b) {
        b.addEventListener('click', function () {
            var p = b.getAttribute('data-pane');
            tabs.forEach(function (x) { x.classList.toggle('on', x === b); });
            [].forEach.call(document.querySelectorAll('.aut-pane'), function (x) { x.classList.toggle('on', x.id === 'pane-' + p); });
        });
    });
})();
</script>
<?php
include('Common/Templates/tail.php');
