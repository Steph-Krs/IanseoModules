<?php
/**
 * AUTH module — server settings (config.local.json) edited from ianseo.
 *
 * Server admin only (same guard as deploy.php). Every safety rule lives in
 * config-lib.php, on the server side: masked secrets, locked commands/paths/URLs,
 * atomic write with a .bak copy. This page is only a view on top of it.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/config-lib.php');
require_once(dirname(__DIR__) . '/backup-lib.php');
require_once(dirname(__DIR__) . '/health-lib.php');

checkFullACL(AclRoot, '', AclReadWrite);
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

function acf_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function acf_int($v, $min, $max, $def) { $v = trim((string) $v); return ($v === '' || !is_numeric($v)) ? $def : max($min, min($max, intval($v))); }

$msgOk = ''; $msgErr = ''; $testOut = null; $rawPosted = null;
$readErr = '';
$cfg = aut_cfg_read($readErr);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cfg !== null) {
    $action = (string) ($_POST['action'] ?? '');
    $new = null;
    if (!aut_csrf_check()) {
        $msgErr = aut_t('ShSessionExpired');
    } elseif ($action === 'save_backup') {
        $d = aut_backup_defaults();
        $new = $cfg;
        $new['backup'] = array_merge(is_array($cfg['backup'] ?? null) ? $cfg['backup'] : array(), array(
            'enabled'           => !empty($_POST['bk_enabled']),
            'dir'               => trim((string) ($_POST['bk_dir'] ?? $d['dir'])),
            'keep_days'         => acf_int($_POST['bk_keep'] ?? '', 1, 3650, $d['keep_days']),
            'files'             => !empty($_POST['bk_files']),
            'logos'             => !empty($_POST['bk_logos']),
            'required_for_core' => !empty($_POST['bk_required']),
            'remote'            => trim((string) ($_POST['bk_remote'] ?? '')),
            'remote_keep_days'  => acf_int($_POST['bk_rkeep'] ?? '', 1, 3650, $d['remote_keep_days']),
            'live'              => !empty($_POST['bk_live']),
            'live_keep_hours'   => acf_int($_POST['bk_lkeep'] ?? '', 6, 720, $d['live_keep_hours']),
        ));
    } elseif ($action === 'save_maint') {
        $new = $cfg;
        $m = is_array($cfg['maintenance'] ?? null) ? $cfg['maintenance'] : array();
        $m['steps'] = array();
        foreach (array('core', 'modules', 'licences', 'logos') as $s) $m['steps'][$s] = !empty($_POST['st_' . $s]);
        $at = trim((string) ($_POST['nt_at'] ?? ''));
        if ($at !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at)) {
            $msgErr = aut_t('CfBadTime');
            $new = null;
        } else {
            $m['notice'] = array('at' => $at, 'lead_minutes' => acf_int($_POST['nt_lead'] ?? '', 0, 240, 15));
            $new['maintenance'] = $m;
            $new['log_retention_days'] = acf_int($_POST['log_days'] ?? '', 7, 3650, 180);
            $new['stats_enabled'] = !empty($_POST['stats_enabled']);
        }
    } elseif ($action === 'save_raw') {
        $rawPosted = (string) ($_POST['raw'] ?? '');
        $d = json_decode($rawPosted, true);
        if (!is_array($d)) $msgErr = aut_t('CfBadJson', json_last_error_msg());
        else $new = $d;
    } elseif ($action === 'test_remote') {
        $remote = aut_backup_config($cfg)['remote'];
        $out = array();
        if ($remote === '') $msgErr = aut_t('CfNoRemote');
        elseif (aut_backup_remote_test($remote, $out)) { $msgOk = aut_t('CfRemoteOk', $remote); $testOut = $out; }
        else { $msgErr = aut_t('CfRemoteKo', $remote); $testOut = $out; }
    }

    if ($new !== null && $msgErr === '') {
        $e = ''; $changed = array();
        if (aut_cfg_save($new, $e, $changed)) {
            if ($changed) {
                aut_log('CONFIG_EDIT', $_SESSION['AUTH_User'] ?? 'local');
                $msgOk = aut_t('CfSaved', implode(', ', $changed));
            } else {
                $msgOk = aut_t('CfNoChange');
            }
            $rawPosted = null;
            $cfg = aut_cfg_read($readErr);
        } else {
            $msgErr = $e;
        }
    }
}

$bk = aut_backup_config($cfg ?: array());
$mt = is_array($cfg['maintenance'] ?? null) ? $cfg['maintenance'] : array();
$steps = is_array($mt['steps'] ?? null) ? $mt['steps'] : array();
$notice = is_array($mt['notice'] ?? null) ? $mt['notice'] : array();

$PAGE_TITLE = aut_t('MenuTitle') . ' — ' . aut_t('MenuConfig');
include('Common/Templates/head.php');

echo '<style>
#acf .ok{color:#1a5c1a} #acf .ko{color:#8b1a1a} #acf .warn{color:#8a5a00}
#acf code.cmd{display:block;background:#f4f6f8;border:1px solid #d2d4d6;padding:6px 8px;margin:4px 0;white-space:pre-wrap;word-break:break-all}
#acf td.lbl{width:34%;font-weight:bold}
#acf .hint{font-size:11px;color:#555}
#acf textarea{width:100%;min-height:360px;font-family:monospace;font-size:12px}
#acf input[type=text]{width:95%}
</style>';

echo '<div id="acf"><table class="Tabella">';
echo '<tr><th class="Title" colspan="2">' . acf_h(aut_t('CfTitle')) . '</th></tr>';
if ($msgOk)   echo '<tr><td colspan="2" class="Center" style="background:#e8f4e8;color:#1a5c1a;">' . acf_h($msgOk) . '</td></tr>';
if ($msgErr)  echo '<tr><td colspan="2" class="Center" style="background:#fde8e8;color:#8b1a1a;">' . acf_h($msgErr) . '</td></tr>';
if ($readErr) echo '<tr><td colspan="2" class="Center" style="background:#fde8e8;color:#8b1a1a;">' . acf_h($readErr)
    . ' — ' . acf_h(aut_t('CfReadOnly')) . '</td></tr>';
if ($testOut) echo '<tr><td colspan="2"><code class="cmd">' . acf_h(implode("\n", array_slice($testOut, 0, 30))) . '</code></td></tr>';
echo '<tr><td colspan="2" class="hint">' . aut_t('CfIntro') . '</td></tr>';

/* ---------------- Server health ---------------- */
echo '<tr><th class="Title" colspan="2">' . acf_h(aut_t('CfHealth')) . '</th></tr>';
echo '<tr><td colspan="2" class="hint">' . aut_t('CfHealthIntro') . '</td></tr>';
$acfIcon = array('ok' => '<span class="ok">✔</span>', 'warn' => '<span class="ko">⚠</span>', 'info' => '<span class="warn">ℹ</span>');
foreach (aut_health_checks() as $h) {
    echo '<tr><td class="lbl">' . $acfIcon[$h['level']] . ' ' . acf_h($h['title']) . '</td><td>' . acf_h($h['text']);
    if ($h['list']) {
        echo '<ul style="margin:4px 0 0 18px">';
        foreach ($h['list'] as $x) echo '<li>' . acf_h($x) . '</li>';
        echo '</ul>';
    }
    if ($h['fix'] !== '') echo '<code class="cmd">' . acf_h($h['fix']) . '</code>';
    echo '</td></tr>';
}

/* ---------------- Backup ---------------- */
$fix = '';
$dirPb = aut_backup_dir_problem($bk['dir'], $fix, false);
$dump = aut_backup_mysqldump_bin();
$rcl  = aut_backup_rclone_bin();
$list = aut_backup_list($bk['dir']);

echo '<tr><th class="Title" colspan="2">' . acf_h(aut_t('CfBackups')) . '</th></tr>';
echo '<tr><td colspan="2" class="hint">' . aut_t('CfBackupsIntro') . '</td></tr>';

echo '<tr><td class="lbl">' . acf_h(aut_t('CfState')) . '</td><td>';
echo $dirPb === '' ? '<span class="ok">✔ ' . acf_h(aut_t('CfDirOk')) . '</span>' : '<span class="ko">✘ ' . acf_h($dirPb) . '</span>';
if ($fix !== '') echo '<br>' . acf_h(aut_t('CfRunOnce')) . '<code class="cmd">' . acf_h($fix) . '</code>';
echo '<br>' . ($dump !== '' ? '<span class="ok">✔ ' . acf_h(aut_t('CfDumpOk')) . '</span>' : '<span class="ko">✘ ' . acf_h(aut_t('CfDumpKo')) . '</span>');
echo '<br>' . ($rcl !== '' ? '<span class="ok">✔ ' . acf_h(aut_t('CfRcloneOk')) . '</span>' : '<span class="warn">– ' . acf_h(aut_t('CfRcloneKo')) . '</span>');
if ($dirPb === '') {
    $free = @disk_free_space($bk['dir']);
    if ($free !== false) echo '<br>' . acf_h(aut_t('CfFreeSpace', round($free / 1073741824, 1)));
}
if ($list) {
    $acfNight = array_values(array_filter($list, function ($b) { return $b['kind'] !== 'live'; }));
    $acfLive  = array_values(array_filter($list, function ($b) { return $b['kind'] === 'live'; }));
    foreach (array(aut_t('CfLastNight') => array_slice($acfNight, 0, 4),
                   aut_t('CfLastLive') => array_slice($acfLive, 0, 4)) as $acfT => $acfL) {
        if (!$acfL) continue;
        echo '<br><br><b>' . acf_h($acfT) . '</b><br>';
        foreach ($acfL as $b) echo acf_h(basename($b['file'])) . ' — ' . acf_h(aut_t('CfMb', round($b['size'] / 1048576, 1))) . '<br>';
    }
} elseif ($dirPb === '') {
    echo '<br><br>' . acf_h(aut_t('CfNoBackup'));
}
echo '</td></tr>';

$dis = $cfg === null ? ' disabled' : '';
$row = function ($label, $field, $hint = '') {
    return '<tr><td class="lbl">' . acf_h($label) . '</td><td>' . $field . ($hint !== '' ? '<div class="hint">' . $hint . '</div>' : '') . '</td></tr>';
};
$check = function ($name, $on, $label) use ($dis) {
    return '<label><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . $dis . '> ' . $label . '</label>';
};
echo '</table><form method="post" action="">' . aut_csrf_field() . '<input type="hidden" name="action" value="save_backup"><table class="Tabella">';
echo $row(aut_t('CfBkEnabled'), $check('bk_enabled', $bk['enabled'], acf_h(aut_t('CfBkEnabledBox'))));
echo $row(aut_t('CfBkDir'), '<input type="text" name="bk_dir" value="' . acf_h($bk['dir']) . '"' . $dis . '>', aut_t('CfBkDirHint'));
echo $row(aut_t('CfBkKeep'), '<input type="number" name="bk_keep" min="1" max="3650" value="' . intval($bk['keep_days']) . '"' . $dis . '>', acf_h(aut_t('CfBkKeepHint')));
echo $row(aut_t('CfBkFiles'), $check('bk_files', $bk['files'], acf_h(aut_t('CfBkFilesBox'))), acf_h(aut_t('CfBkFilesHint')));
echo $row(aut_t('CfBkLogos'), $check('bk_logos', $bk['logos'], acf_h(aut_t('CfBkLogosBox'))), aut_t('CfBkLogosHint'));
echo $row(aut_t('CfBkLive'), $check('bk_live', $bk['live'], acf_h(aut_t('CfBkLiveBox'))) . ', ' . acf_h(aut_t('CfBkLiveKept')) . ' '
    . '<input type="number" name="bk_lkeep" min="6" max="720" style="width:5em" value="' . intval($bk['live_keep_hours']) . '"' . $dis . '> '
    . acf_h(aut_t('CfHours')), acf_h(aut_t('CfBkLiveHint')));
echo $row(aut_t('CfBkCore'), $check('bk_required', $bk['required_for_core'], acf_h(aut_t('CfBkCoreBox'))));
echo $row(aut_t('CfBkRemote'), '<input type="text" name="bk_remote" placeholder="' . acf_h(aut_t('CfBkRemotePh')) . '" value="'
    . acf_h($bk['remote']) . '"' . $dis . '>', aut_t('CfBkRemoteHint'));
echo $row(aut_t('CfBkRkeep'), '<input type="number" name="bk_rkeep" min="1" max="3650" value="' . intval($bk['remote_keep_days']) . '"' . $dis . '>');
echo '<tr><td colspan="2" class="Center"><button type="submit"' . $dis . '>' . acf_h(aut_t('CfBkSave')) . '</button></td></tr></table></form>';
if ($bk['remote'] !== '') {
    echo '<form method="post" action="" class="Center" style="margin:4px 0">' . aut_csrf_field()
        . '<input type="hidden" name="action" value="test_remote"><button type="submit"' . $dis . '>' . acf_h(aut_t('CfBkTest')) . '</button></form>';
}
echo '<table class="Tabella">';

echo '<tr><td colspan="2" class="hint"><details><summary><b>' . acf_h(aut_t('CfRcloneHow')) . '</b></summary>'
    . '<p>' . aut_t('CfRclone1') . '</p>'
    . '<p>⚠️ ' . aut_t('CfRclone2') . '</p>'
    . '<p>' . acf_h(aut_t('CfRclone3')) . '</p>'
    . '<code class="cmd">sudo apt install rclone
sudo install -d -o www-data -g www-data -m 0700 /var/www/.config
sudo -u www-data rclone config</code>'
    . '<p>' . aut_t('CfRclone4') . '</p>'
    . '</details></td></tr>';

/* ---------------- Nightly window ---------------- */
echo '</table><form method="post" action="">' . aut_csrf_field() . '<input type="hidden" name="action" value="save_maint"><table class="Tabella">';
echo '<tr><th class="Title" colspan="2">' . acf_h(aut_t('CfNight')) . '</th></tr>';
$lbl = array(
    'core'     => array(aut_t('CfStepCore'), false),
    'modules'  => array(aut_t('CfStepModules'), true),
    'licences' => array(aut_t('CfStepLicences'), true),
    'logos'    => array(aut_t('CfStepLogos'), true),
);
$stepsHtml = '';
foreach ($lbl as $k => $l) {
    $stepsHtml .= $check('st_' . $k, array_key_exists($k, $steps) ? !empty($steps[$k]) : $l[1], acf_h($l[0])) . '<br>';
}
echo $row(aut_t('CfSteps'), $stepsHtml, acf_h(aut_t('CfStepsHint')));
echo $row(aut_t('CfNotice'), aut_t('CfNoticeField', array(
        'at'   => '<input type="text" name="nt_at" style="width:5em" placeholder="03:15" value="' . acf_h($notice['at'] ?? '') . '"' . $dis . '>',
        'lead' => '<input type="number" name="nt_lead" min="0" max="240" style="width:5em" value="' . intval($notice['lead_minutes'] ?? 15) . '"' . $dis . '>',
    )), aut_t('CfNoticeHint'));
echo $row(aut_t('CfLogDays'), '<input type="number" name="log_days" min="7" max="3650" value="'
    . intval($cfg['log_retention_days'] ?? 180) . '"' . $dis . '>');
echo $row(aut_t('CfStats'), $check('stats_enabled', !array_key_exists('stats_enabled', (array) $cfg) || !empty($cfg['stats_enabled']), acf_h(aut_t('CfStatsBox'))));
$cmds = '';
foreach (array('on', 'off', 'unlock', 'lock') as $k) {
    $cmds .= '<code>' . $k . '</code> : ' . (trim((string) ($mt[$k] ?? '')) !== '' ? '<code>' . acf_h($mt[$k]) . '</code>' : '<i>' . acf_h(aut_t('CfNone')) . '</i>') . '<br>';
}
echo '<tr><td class="lbl">' . acf_h(aut_t('CfCommands')) . '</td><td class="hint">' . $cmds . acf_h(aut_t('CfCliOnly')) . '</td></tr>';
echo '<tr><td class="lbl">' . acf_h(aut_t('CfPing')) . '</td><td class="hint">'
    . (trim((string) ($mt['ping_url'] ?? '')) !== '' ? '<code>' . acf_h($mt['ping_url']) . '</code>' : '<i>' . acf_h(aut_t('CfNoneM')) . '</i>')
    . '<br>' . aut_t('CfPingHint') . '</td></tr>';
echo '<tr><td colspan="2" class="Center"><button type="submit"' . $dis . '>' . acf_h(aut_t('ShSave')) . '</button></td></tr></table></form><table class="Tabella">';

/* ---------------- Raw editor ---------------- */
echo '<tr><th class="Title" colspan="2">' . acf_h(aut_t('CfRaw')) . '</th></tr>';
$locked = array();
foreach (aut_cfg_flatten($cfg ?: array()) as $p => $v) if (aut_cfg_is_locked($p, $v)) $locked[] = $p;
echo '<tr><td colspan="2"><details' . ($rawPosted !== null ? ' open' : '') . '><summary>' . acf_h(aut_t('CfRawShow')) . '</summary>'
    . '<p class="hint">' . aut_t('CfRawHint', '<code>' . AUT_CFG_MASK . '</code>')
    . ($locked ? ' ' . aut_t('CfRawLocked', '<code>' . acf_h(implode(', ', $locked)) . '</code>') : '')
    . '</p><form method="post" action="">' . aut_csrf_field() . '<input type="hidden" name="action" value="save_raw">'
    . '<textarea name="raw" spellcheck="false"' . $dis . '>'
    . acf_h($rawPosted !== null ? $rawPosted
        : json_encode(aut_cfg_masked($cfg ?: array()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
    . '</textarea><div class="Center"><button type="submit"' . $dis
    . ' data-confirm="' . acf_h(aut_t('CfRawConfirm')) . '" onclick="return confirm(this.dataset.confirm);">'
    . acf_h(aut_t('CfRawSave')) . '</button></div></form></details></td></tr>';

echo '</table></div>';
include('Common/Templates/tail.php');
