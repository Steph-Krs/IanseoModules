<?php
/**
 * AUTH module — payer trust index: settings, people and clubs in orange or red with the
 * detail of their incidents, whitelist and forced levels, acceptances by the organisers,
 * computation on demand. Server administrator only (same guard as config.php). Rules and
 * computation: trust-lib.php.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/trust-lib.php');

checkFullACL(AclRoot, '', AclReadWrite);
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

function atr_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function atr_pill($level) { return '<span style="' . aut_trust_level_style($level) . '">' . atr_h(aut_trust_level_label($level)) . '</span>'; }

$self = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/trust.php';
$user = (string) ($_SESSION['AUTH_User'] ?? 'local');

/* ---------------- Actions (post, then redirect) ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $back = $self;
    $flash = array('ok' => '', 'err' => '');
    if (!aut_csrf_check()) {
        $flash['err'] = aut_t('TrSessionExpired');
    } else {
        $act = (string) ($_POST['action'] ?? '');
        if ($act === 'settings') {
            $err = '';
            if (aut_trust_config_save($_POST, $err)) {
                aut_log('TRUST_SETTINGS', $user);
                $flash['ok'] = aut_t('TrSaved');
            } else {
                $flash['err'] = $err;
            }
        } elseif ($act === 'compute') {
            $st = aut_trust_compute();
            $flash['ok'] = aut_t('TrComputed', array('tours' => $st['tours'], 'incidents' => $st['incidents'], 'deleted' => $st['deleted']));
        } elseif ($act === 'override') {
            $s = aut_trust_subject((string) ($_POST['subject'] ?? ''));
            $e = aut_trust_set_override($s, !empty($_POST['white']), (string) ($_POST['forced'] ?? ''),
                (string) ($_POST['reason'] ?? ''), (string) ($_POST['until'] ?? ''), $user);
            if ($e === '') $flash['ok'] = aut_t('TrOverrideSaved'); else $flash['err'] = $e;
            if ($s !== '') $back .= '?s=' . rawurlencode($s);
        } elseif ($act === 'override_del') {
            $s = aut_trust_subject((string) ($_POST['subject'] ?? ''));
            aut_trust_delete_override($s, $user);
            $flash['ok'] = aut_t('TrOverrideDeleted');
            if ($s !== '') $back .= '?s=' . rawurlencode($s);
        }
    }
    $_SESSION['AUT_TRUST_FLASH'] = $flash;
    header('Location: ' . $back);
    exit;
}
$flash = $_SESSION['AUT_TRUST_FLASH'] ?? array('ok' => '', 'err' => '');
unset($_SESSION['AUT_TRUST_FLASH']);

aut_ensure_schema();
$cfg = aut_trust_config();

// A subject asked for: "L:…", "C:…", a licence, or a club approval number (?club=1).
$askRaw = trim((string) ($_GET['s'] ?? ''));
$ask = !empty($_GET['club']) ? aut_trust_subject('', $askRaw) : aut_trust_subject($askRaw);

// Every subject with an incident or an override, and their level.
$subjects = array();
$rs = safe_r_sql("SELECT DISTINCT TnSubject AS s FROM AuthTrustEvents");
while ($r = safe_fetch($rs)) $subjects[$r->s] = true;
$overrides = array();
$rs = safe_r_sql("SELECT * FROM AuthTrust WHERE TuTournament = 0 ORDER BY TuUpdated DESC");
while ($r = safe_fetch($rs)) { $overrides[$r->TuSubject] = $r; $subjects[$r->TuSubject] = true; }
if ($ask !== '') $subjects[$ask] = true;
$levels = aut_trust_preload(array_keys($subjects));
$labels = aut_trust_subject_labels(array_keys($subjects));
$count = array('green' => 0, 'orange' => 0, 'red' => 0, 'white' => 0);
foreach ($levels as $lv) $count[$lv['level']]++;

$accepts = array();
$rs = safe_r_sql("SELECT AuthTrust.*, ToName, ToWhenFrom FROM AuthTrust LEFT JOIN Tournament ON ToId = TuTournament
    WHERE TuTournament > 0 ORDER BY TuCreated DESC LIMIT 200");
while ($r = safe_fetch($rs)) $accepts[] = $r;

$PAGE_TITLE = aut_t('MenuTitle') . ' — ' . aut_t('MenuTrust');
include('Common/Templates/head.php');

$out = '<style>
#atr .hint{font-size:11px;color:#555}
#atr .ok{background:#e8f4e8;color:#1a5c1a} #atr .ko{background:#fde8e8;color:#8b1a1a}
#atr .warn{background:#fff8e1;color:#5b4300}
#atr ul{margin:4px 0 4px 18px}
#atr td{vertical-align:top}
#atr input[type=number]{width:80px}
#atr .modes label{display:block;margin:2px 0}
</style>';
$out .= '<div id="atr"><table class="Tabella">';
$out .= '<tr><th class="Title" colspan="2">' . atr_h(aut_t('MenuTrust')) . '</th></tr>';
$out .= '<tr><td colspan="2" class="hint">' . aut_t('TrIntro') . '</td></tr>';
if ($flash['ok'] !== '') $out .= '<tr><td colspan="2" class="Center ok">' . atr_h($flash['ok']) . '</td></tr>';
if ($flash['err'] !== '') $out .= '<tr><td colspan="2" class="Center ko">' . atr_h($flash['err']) . '</td></tr>';

/* ---------------- State and computation ---------------- */
$last = aut_trust_last_run();
$out .= '<tr><td class="Bold" style="width:28%">' . atr_h(aut_t('TrState')) . '</td><td>'
    . atr_h(aut_t('TrMode_' . $cfg['mode'])) . ' · '
    . atr_h($last !== '' ? aut_t('TrLastRun', $last) : aut_t('TrNeverRun')) . '<br>'
    . atr_pill('red') . ' ' . $count['red'] . ' &nbsp; ' . atr_pill('orange') . ' ' . $count['orange']
    . ' &nbsp; ' . atr_pill('white') . ' ' . $count['white']
    . '<form method="post" action="' . atr_h($self) . '" style="margin-top:6px">' . aut_csrf_field()
    . '<input type="hidden" name="action" value="compute"><button type="submit">' . atr_h(aut_t('TrComputeNow')) . '</button></form>'
    . '<div class="hint">' . atr_h(aut_t('TrComputeHint')) . '</div></td></tr>';
if (in_array($cfg['mode'], array('alert', 'block'), true)) {
    $out .= '<tr><td colspan="2" class="warn">' . aut_t('TrDpoActive') . '</td></tr>';
}

/* ---------------- Settings ---------------- */
$modes = '';
foreach (aut_trust_modes() as $m) {
    $modes .= '<label><input type="radio" name="mode" value="' . $m . '"' . ($cfg['mode'] === $m ? ' checked' : '') . '> <b>'
        . atr_h(aut_t('TrMode_' . $m)) . '</b> — ' . atr_h(aut_t('TrModeHelp_' . $m)) . '</label>';
}
$nums = '';
foreach (aut_trust_bounds() as $k => $b) {
    $nums .= '<tr><td>' . atr_h(aut_t('TrSet_' . $k)) . '</td><td><input type="number" name="' . $k . '" value="' . atr_h($cfg[$k])
        . '" min="' . $b[1] . '" max="' . $b[2] . '" step="' . (is_float($b[0]) ? '0.01' : '1') . '" required> '
        . '<span class="hint">' . atr_h(aut_t('TrSetHelp_' . $k)) . '</span></td></tr>';
}
$out .= '<tr><td class="Bold">' . atr_h(aut_t('TrSettings')) . '</td><td><form method="post" action="' . atr_h($self) . '">'
    . aut_csrf_field() . '<input type="hidden" name="action" value="settings">'
    . '<div class="modes">' . $modes . '</div><div class="hint warn" style="padding:4px;margin:4px 0">' . aut_t('TrDpoNote') . '</div>'
    . '<table>' . $nums . '</table>'
    . '<div class="hint">' . aut_t('TrRulesSummary', array('late' => $cfg['orange_late'], 'red' => $cfg['red_unpaid'],
        'days' => $cfg['red_unpaid_days'], 'reject' => $cfg['red_reject'], 'months' => AUT_TRUST_MONTHS)) . '</div>'
    . '<button type="submit">' . atr_h(aut_t('TrSave')) . '</button></form></td></tr>';

/* ---------------- One subject ---------------- */
$out .= '<tr><td class="Bold">' . atr_h(aut_t('TrSearch')) . '</td><td><form method="get" action="' . atr_h($self) . '">'
    . '<input type="text" name="s" value="' . atr_h($askRaw) . '" maxlength="27" placeholder="' . atr_h(aut_t('TrSearchPlaceholder')) . '"> '
    . '<label><input type="checkbox" name="club" value="1"' . (!empty($_GET['club']) ? ' checked' : '') . '> ' . atr_h(aut_t('TrOrgAcceptIsClub')) . '</label> '
    . '<button type="submit">' . atr_h(aut_t('TrShow')) . '</button></form></td></tr>';
if ($askRaw !== '' && $ask === '') {
    $out .= '<tr><td colspan="2" class="Center ko">' . atr_h(aut_t('TrBadSubject')) . '</td></tr>';
}
if ($ask !== '') {
    $lv = $levels[$ask];
    $o = $lv['override'];
    $inc = '';
    foreach ($lv['reasons'] as $r) {
        $inc .= '<li>' . aut_trust_reason_text($r) . ' <span class="hint">' . atr_h(aut_t('TrDetected', aut_trust_dmy($r['created']))) . '</span></li>';
    }
    $forced = '<option value="">' . atr_h(aut_t('TrNoForced')) . '</option>';
    foreach (array('green', 'orange', 'red') as $l) {
        $forced .= '<option value="' . $l . '"' . ($o && $o->TuForced === $l ? ' selected' : '') . '>' . atr_h(aut_trust_level_label($l)) . '</option>';
    }
    $out .= '<tr><td class="Bold">' . atr_h($labels[$ask] !== '' ? $labels[$ask] : $ask) . '<br><span class="hint">' . atr_h($ask) . '</span></td><td>'
        . atr_pill($lv['level'])
        . ($lv['level'] !== $lv['computed'] ? ' <span class="hint">' . atr_h(aut_t('TrComputedLevel', aut_trust_level_label($lv['computed']))) . '</span>' : '')
        . ($inc !== '' ? '<ul>' . $inc . '</ul>' : '<p>' . atr_h(aut_t('TrNoIncident')) . '</p>')
        . '<form method="post" action="' . atr_h($self) . '">' . aut_csrf_field()
        . '<input type="hidden" name="action" value="override"><input type="hidden" name="subject" value="' . atr_h($ask) . '">'
        . '<label><input type="checkbox" name="white" value="1"' . ($o && intval($o->TuWhite) === 1 ? ' checked' : '') . '> '
        . atr_h(aut_t('TrWhitelist')) . '</label><br>'
        . '<label>' . atr_h(aut_t('TrForced')) . ' <select name="forced">' . $forced . '</select></label><br>'
        . '<label>' . atr_h(aut_t('TrColReason')) . ' <input type="text" name="reason" maxlength="255" style="width:320px" required value="'
        . atr_h($o ? $o->TuReason : '') . '"></label><br>'
        . '<label>' . atr_h(aut_t('TrUntil')) . ' <input type="date" name="until" value="' . atr_h($o && $o->TuUntil ? $o->TuUntil : '') . '"></label> '
        . '<span class="hint">' . atr_h(aut_t('TrUntilHint')) . '</span><br>'
        . '<button type="submit">' . atr_h(aut_t('TrSave')) . '</button></form>'
        . ($o ? '<form method="post" action="' . atr_h($self) . '" style="margin-top:4px">' . aut_csrf_field()
            . '<input type="hidden" name="action" value="override_del"><input type="hidden" name="subject" value="' . atr_h($ask) . '">'
            . '<button type="submit">' . atr_h(aut_t('TrOverrideDelete')) . '</button> <span class="hint">'
            . atr_h(aut_t('TrOverrideBy', array('by' => $o->TuBy, 'date' => aut_trust_dmy($o->TuUpdated)))) . '</span></form>' : '')
        . '</td></tr>';
}

/* ---------------- Orange and red ---------------- */
// Red first, then orange; a subject whitelisted or forced to green stays listed by what was
// computed, so that the administrator sees what the override hides.
$rank = array('red' => 2, 'orange' => 1);
$rows = '';
foreach (array(2, 1) as $want) {
    foreach ($levels as $s => $lv) {
        if (max($rank[$lv['level']] ?? 0, $rank[$lv['computed']] ?? 0) !== $want) continue;
        $n = $lv['counts'];
        $rows .= '<tr><td><a href="' . atr_h($self . '?s=' . rawurlencode($s)) . '">' . atr_h($labels[$s] !== '' ? $labels[$s] : $s) . '</a>'
            . ' <span class="hint">' . atr_h($s) . '</span></td><td>' . atr_pill($lv['level'])
            . ($lv['level'] !== $lv['computed'] ? ' <span class="hint">' . atr_h(aut_t('TrComputedLevel', aut_trust_level_label($lv['computed']))) . '</span>' : '')
            . ' — ' . atr_h(aut_t('TrCounts', array('unpaid' => $n['unpaid'], 'late' => $n['late'], 'reject' => $n['reject']))) . '</td></tr>';
    }
}
$out .= '<tr><th class="Title" colspan="2">' . atr_h(aut_t('TrListTitle')) . '</th></tr>'
    . ($rows !== '' ? $rows : '<tr><td colspan="2" class="hint">' . atr_h(aut_t('TrListEmpty')) . '</td></tr>');

/* ---------------- Overrides ---------------- */
$out .= '<tr><th class="Title" colspan="2">' . atr_h(aut_t('TrOverridesTitle')) . '</th></tr>';
if (!$overrides) $out .= '<tr><td colspan="2" class="hint">' . atr_h(aut_t('TrOverridesEmpty')) . '</td></tr>';
foreach ($overrides as $s => $o) {
    $what = array();
    if (intval($o->TuWhite) === 1) $what[] = aut_t('TrWhitelist');
    if ($o->TuForced !== '') $what[] = aut_t('TrForced') . ' ' . aut_trust_level_label($o->TuForced);
    $out .= '<tr><td><a href="' . atr_h($self . '?s=' . rawurlencode($s)) . '">' . atr_h($labels[$s] !== '' ? $labels[$s] : $s) . '</a>'
        . ' <span class="hint">' . atr_h($s) . '</span></td><td>' . atr_h(implode(' · ', $what)) . ' — ' . atr_h($o->TuReason)
        . ' <span class="hint">' . atr_h(aut_t('TrOverrideBy', array('by' => $o->TuBy, 'date' => aut_trust_dmy($o->TuUpdated))))
        . ($o->TuUntil ? ' · ' . atr_h(aut_t('TrUntilDate', aut_trust_dmy($o->TuUntil))) : '') . '</span></td></tr>';
}

/* ---------------- Acceptances by the organisers ---------------- */
$out .= '<tr><th class="Title" colspan="2">' . atr_h(aut_t('TrAcceptsTitle')) . '</th></tr>';
if (!$accepts) $out .= '<tr><td colspan="2" class="hint">' . atr_h(aut_t('TrAcceptsEmpty')) . '</td></tr>';
foreach ($accepts as $a) {
    $out .= '<tr><td>' . atr_h(aut_trust_dmy($a->TuCreated)) . ' — ' . atr_h($a->ToName ?? ('#' . $a->TuTournament)) . '</td><td>'
        . '<a href="' . atr_h($self . '?s=' . rawurlencode($a->TuSubject)) . '">' . atr_h($a->TuSubject) . '</a> — ' . atr_h($a->TuReason)
        . ' <span class="hint">' . atr_h($a->TuBy) . '</span></td></tr>';
}

echo $out . '</table></div>';
include('Common/Templates/tail.php');
