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
require_once(dirname(__DIR__) . '/adm-lib.php');   // navigation and save button of the organiser pages
require_once(dirname(__DIR__) . '/config-lib.php');
require_once(dirname(__DIR__) . '/backup-lib.php');
require_once(dirname(__DIR__) . '/health-lib.php');
require_once(dirname(__DIR__) . '/logos-lib.php');

checkFullACL(AclRoot, '', AclReadWrite);
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

function acf_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function acf_int($v, $min, $max, $def) { $v = trim((string) $v); return ($v === '' || !is_numeric($v)) ? $def : max($min, min($max, intval($v))); }

/** Last line of the jobs journal for $job, or null. */
function acf_last_job($job)
{
    return safe_fetch(safe_r_sql("SELECT AjWhen, AjStatus, AjMessage FROM AuthJobs WHERE AjJob = " . StrSafe_DB($job)
        . " ORDER BY AjId DESC LIMIT 1")) ?: null;
}

/**
 * Deletes the FFTA licences (the LookUpEntries rows of cron/sync-licences.php) and the memory of
 * the last file: the next run imports the file again even if it has not changed, and nobody is
 * suspended while no file is loaded (AutLicFileOk unknown, licence-lib.php). Returns the rows deleted.
 */
function acf_lic_purge()
{
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM LookUpEntries WHERE LueIocCode = 'FRA'"));
    safe_w_sql("DELETE FROM LookUpEntries WHERE LueIocCode = 'FRA'");
    foreach (array('AutLicHash', 'AutLicAt', 'AutLicFileOk') as $p) SetParameter($p, '');
    return $r ? intval($r->n) : 0;
}

/**
 * When the nightly job runs: read in its cron file when the site may read it (open_basedir
 * usually forbids /etc), else the hour of the notice set below, which should be the same.
 */
function acf_night_when($notice)
{
    foreach (preg_split('/\R/', aut_health_read('/etc/cron.d/ianseo-nightly')) as $l) {
        if (preg_match('/^\s*(\d{1,2})\s+(\d{1,2})\s+\*\s+\*\s+\*\s.*maintenance\.php/', $l, $m)) {
            return aut_t('CfCronAt', sprintf('%02d:%02d', $m[2], $m[1]));
        }
    }
    $at = trim((string) ($notice['at'] ?? ''));
    return $at !== '' ? aut_t('CfCronNotice', $at) : aut_t('CfCronUnknown');
}

$msgOk = ''; $msgErr = ''; $testOut = null; $rawPosted = null;
// Licensee files of foreign federations (booking/lib/fedlic.php), when the module has them.
$acfFed = is_file(dirname(__DIR__) . '/booking/lib/fedlic.php');
if ($acfFed) require_once(dirname(__DIR__) . '/booking/lib/other.php');   // country names, then fedlic.php
$readErr = '';
$cfg = aut_cfg_read($readErr);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cfg !== null) {
    $action = (string) ($_POST['action'] ?? '');
    // Buttons of a foreign file: "fed_force:ITA", "fed_purge:ITA".
    $src = '';
    if (preg_match('/^(fed_force|fed_purge):([A-Z]{2,5})$/', $action, $mm)) { $action = $mm[1]; $src = $mm[2]; }
    $new = null;
    if (!aut_csrf_check()) {
        $msgErr = aut_t('ShSessionExpired');
    } elseif ($action === 'save_settings') {
        // The page's save button: nightly window, licensee files and backups together.
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
        $m = is_array($cfg['maintenance'] ?? null) ? $cfg['maintenance'] : array();
        // Keys set by hand in the file (a "fed" step…) are kept.
        $m['steps'] = is_array($m['steps'] ?? null) ? $m['steps'] : array();
        foreach (array('core', 'modules') as $s) $m['steps'][$s] = !empty($_POST['st_' . $s]);
        $m['steps']['licences'] = !empty($_POST['lf_licences']);
        $m['steps']['logos']    = !empty($_POST['lf_logos']);
        if ($acfFed) {
            $fed = array();
            $posted = is_array($_POST['lf_fed'] ?? null) ? $_POST['lf_fed'] : array();
            foreach (array_keys(bk_fed_sources()) as $s) $fed[$s] = !empty($posted[$s]);
            $m['fed'] = $fed;
        }
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
    } elseif ($acfFed && $action === 'fed_sync') {
        // The nightly step, at once (about a minute: downloads of several megabytes).
        @set_time_limit(600);
        $r = bk_fed_sync_all();
        $okN = count(array_filter($r['states'], function ($x) { return $x === 'ok'; }));
        $acfA = array('ok' => $okN, 'all' => count($r['states']), 'acc' => $r['accounts']);
        if (!$r['states']) $msgOk = aut_t('JbFedAllOff', $acfA);
        elseif ($okN === count($r['states'])) $msgOk = aut_t('CfFedSynced', $acfA);
        else $msgErr = aut_t('CfFedSynced', $acfA);
    } elseif ($acfFed && $action === 'fed_force' && isset(bk_fed_sources()[$src])) {
        // A file under 90 % of the day before, taken anyway: the federation may have cleaned it.
        @set_time_limit(600);
        $st = bk_fed_sync_source($src, true);
        if ($st === 'ok') {
            bk_fed_refresh_accounts();
            aut_log('FED_FORCE', ($_SESSION['AUTH_User'] ?? 'local') . ' ' . $src);
            $msgOk = aut_t('CfFedForced', $src);
        } else {
            $msgErr = aut_t('CfFedForceKo', $src);
        }
    } elseif ($acfFed && $action === 'fed_purge' && isset(bk_fed_sources()[$src])) {
        // A file still ticked comes back with the next automatic load (the confirmation says so).
        bk_fed_purge_source($src);
        aut_log('FED_PURGE', ($_SESSION['AUTH_User'] ?? 'local') . ' ' . $src);
        $msgOk = aut_t('CfFedPurged', $src);
    } elseif ($action === 'lic_purge') {
        $n = acf_lic_purge();
        aut_log('LIC_PURGE', $_SESSION['AUTH_User'] ?? 'local');
        aut_job_log('licences', 'skip', 'JbFedPurged', array('n' => $n));
        $msgOk = aut_t('CfLicPurged', number_format($n, 0, ',', ' '));
    } elseif ($action === 'logos_purge') {
        $n = aut_logos_purge();
        aut_log('LOGOS_PURGE', $_SESSION['AUTH_User'] ?? 'local');
        aut_job_log('logos', 'skip', 'JbLogosPurged', array('n' => $n));
        $msgOk = aut_t('CfLogosPurged', number_format($n, 0, ',', ' '));
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
$JS_SCRIPT = aut_adm_assets(array(), false);
include('Common/Templates/head.php');
echo aut_adm_open_plain('admin/config.php');

echo '<style>
#acf .ok{color:#1a5c1a} #acf .ko{color:#8b1a1a} #acf .warn{color:#8a5a00}
#acf code.cmd{display:block;background:#f4f6f8;border:1px solid #d2d4d6;padding:6px 8px;margin:4px 0;white-space:pre-wrap;word-break:break-all}
#acf td.lbl{width:34%;font-weight:bold}
#acf td.sub{font-weight:bold;font-style:italic}
#acf .hint{font-size:11px;color:#555}
#acf .acf-auto{margin-top:6px}
#acf textarea{width:100%;min-height:360px;font-family:monospace;font-size:12px}
#acf table.acf-jobs{width:100%;border-collapse:collapse;font-size:12px}
#acf table.acf-jobs th,#acf table.acf-jobs td{border-bottom:1px solid #e3e6e9;padding:3px 6px;text-align:left;vertical-align:top}
#acf input[type=text]{width:95%}
</style>';

$dis = $cfg === null ? ' disabled' : '';
$row = function ($label, $field, $hint = '') {
    return '<tr><td class="lbl">' . acf_h($label) . '</td><td>' . $field . ($hint !== '' ? '<div class="hint">' . $hint . '</div>' : '') . '</td></tr>';
};
$check = function ($name, $on, $label) use ($dis) {
    return '<label><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . $dis . '> ' . $label . '</label>';
};
$acfJobIcon = array('ok' => '<span class="ok">✔</span>', 'warn' => '<span class="ko">⚠</span>', 'fail' => '<span class="ko">✘</span>', 'skip' => '<span class="warn">–</span>');
aut_ensure_schema();

/* ---------------- Server configuration ---------------- */
echo '<div id="acf"><table class="Tabella">';
echo '<tr><th class="Title" colspan="2">' . acf_h(aut_t('CfTitle')) . '</th></tr>';
if ($msgOk)   echo '<tr><td colspan="2" class="Center" style="background:#e8f4e8;color:#1a5c1a;">' . acf_h($msgOk) . '</td></tr>';
if ($msgErr)  echo '<tr><td colspan="2" class="Center" style="background:#fde8e8;color:#8b1a1a;">' . acf_h($msgErr) . '</td></tr>';
if ($readErr) echo '<tr><td colspan="2" class="Center" style="background:#fde8e8;color:#8b1a1a;">' . acf_h($readErr)
    . ' — ' . acf_h(aut_t('CfReadOnly')) . '</td></tr>';
if ($testOut) echo '<tr><td colspan="2"><code class="cmd">' . acf_h(implode("\n", array_slice($testOut, 0, 30))) . '</code></td></tr>';
echo '<tr><td colspan="2" class="hint">' . aut_t('CfIntro') . '</td></tr>';

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
echo '</table>';

// One form for the three sections below; the buttons of a section send their own "action"
// (they come after the hidden "save_settings": their value is the one PHP keeps).
echo '<form method="post" action="" id="acf-settings">' . aut_adm_default_submit() . aut_csrf_field()
    . '<input type="hidden" name="action" value="save_settings">';

/* ---------------- Nightly window ---------------- */
echo '<table class="Tabella">';
echo '<tr><th class="Title" colspan="2">' . acf_h(aut_t('CfNight')) . '</th></tr>';
$lbl = array(
    'core'    => array(aut_t('CfStepCore'), false),
    'modules' => array(aut_t('CfStepModules'), true),
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
echo '</table>';

/* ---------------- Licensee files ---------------- */
$num = function ($n) { return number_format(intval($n), 0, ',', ' '); };
// State of a line: last check and, when it did not go well, what the journal says.
$jobState = function ($job) use ($acfJobIcon) {
    $j = acf_last_job($job);
    if (!$j) return '';
    $h = '';
    if ($j->AjStatus !== 'ok') $h .= '<div>' . ($acfJobIcon[$j->AjStatus] ?? '') . ' ' . acf_h(aut_job_text($j)) . '</div>';
    return $h . '<div class="hint">' . acf_h(aut_t('CfFedChecked', aut_job_when($j->AjWhen))) . '</div>';
};
$auto = function ($name, $on) use ($check) {
    return '<div class="acf-auto">' . $check($name, $on, acf_h(aut_t('CfLicAuto'))) . '</div>';
};
// Delete button of a line; the confirmation says whether the data will come back by itself.
$purge = function ($action, $label, $confirm, $on) use ($dis) {
    $confirm .= ' ' . aut_t($on ? 'CfPurgeReload' : 'CfPurgeNoReload') . ' ' . aut_t('CfPurgeGo');
    return '<div style="margin:6px 0 0"><button type="submit" name="action" value="' . acf_h($action) . '" data-confirm="'
        . acf_h($confirm) . '" onclick="return confirm(this.dataset.confirm);"' . $dis . '>' . acf_h($label) . '</button></div>';
};
$publish = (string) (($cfg['licsync'] ?? array())['publish_at'] ?? '08:00');
if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $publish)) $publish = '08:00';

echo '<table class="Tabella">';
echo '<tr><th class="Title" colspan="2">' . acf_h(aut_t('CfLicTitle')) . '</th></tr>';
echo '<tr><td colspan="2" class="hint">' . acf_h(aut_t('CfLicIntro', array('file' => '/etc/cron.d/ianseo-nightly',
    'when' => acf_night_when($notice), 'publish' => $publish))) . '</td></tr>';

// FFTA licences (cron/sync-licences.php → LookUpEntries).
$r = safe_fetch(safe_r_sql("SELECT COUNT(DISTINCT LueCode) AS n FROM LookUpEntries WHERE LueIocCode = 'FRA'"));
$acfN = $r ? intval($r->n) : 0;
$acfAt = (string) GetParameter('AutLicAt', false, '');   // local time; empty default: never writes
$acfAtD = $acfAt !== '' ? DateTime::createFromFormat('Y-m-d H:i:s', $acfAt) : false;
echo '<tr><td class="lbl">' . acf_h(aut_t('CfLicFra')) . '</td><td>';
if ($acfN === 0) echo '<span class="warn">ℹ ' . acf_h(aut_t('CfLicNever')) . '</span>';
elseif ($acfAtD) echo '<span class="ok">✔ ' . acf_h(aut_t('CfFedLoaded', array('n' => $num($acfN), 'when' => $acfAtD->format('d/m/Y H:i')))) . '</span>';
else echo '<span class="ok">✔ ' . acf_h(aut_t('CfLicCount', $num($acfN))) . '</span>';
$acfOn = array_key_exists('licences', $steps) ? !empty($steps['licences']) : true;
echo $jobState('licences');
if ($acfN > 0) echo $purge('lic_purge', aut_t('CfFedPurge', $num($acfN)), aut_t('CfLicPurgeConfirm', $num($acfN)), $acfOn);
echo $auto('lf_licences', $acfOn) . '</td></tr>';

// Club logos (cron/sync-logos.php → AuthClubLogos): taken from the clubs of the FFTA file.
$rs = safe_r_sql("SELECT SUM(LgBytes > 0) AS n, MAX(LgFetched) AS w FROM AuthClubLogos", false, true);
$r = $rs ? safe_fetch($rs) : null;
echo '<tr><td class="lbl">' . acf_h(aut_t('CfLicLogos')) . '</td><td>';
if (!$r || !intval($r->n)) echo '<span class="warn">ℹ ' . acf_h(aut_t('CfLicNever')) . '</span>';
else echo '<span class="ok">✔ ' . acf_h(aut_t('CfLogosLoaded', array('n' => $num($r->n), 'when' => aut_job_when($r->w)))) . '</span>';
$acfOn = array_key_exists('logos', $steps) ? !empty($steps['logos']) : true;
echo $jobState('logos');
if ($r && intval($r->n)) echo $purge('logos_purge', aut_t('CfLogosPurge', $num($r->n)), aut_t('CfLogosPurgeConfirm', $num($r->n)), $acfOn);
echo $auto('lf_logos', $acfOn) . '</td></tr>';

// Files of foreign federations (booking/lib/fedlic.php → BookingFedLicences).
if ($acfFed) {
    echo '<tr><td colspan="2" class="sub">' . acf_h(aut_t('CfFedTitle')) . '<div class="hint" style="font-weight:normal;font-style:normal">'
        . acf_h(aut_t('CfFedIntro')) . '</div></td></tr>';
    $acfNames = function_exists('bk_other_countries') ? bk_other_countries() : array();
    foreach (bk_fed_state() as $src => $st) {
        $on = bk_fed_enabled($src, $cfg ?: array());
        $ctry = array();
        foreach (bk_fed_sources()[$src] as $c) $ctry[] = $acfNames[$c] ?? $c;
        echo '<tr><td class="lbl">' . acf_h($src) . '<div class="hint">' . acf_h(implode(', ', $ctry)) . '</div></td><td>';
        $loaded = $st && $st->BfsLoaded ? aut_t('CfFedLoaded', array('n' => $num($st->BfsCount), 'when' => aut_job_when($st->BfsLoaded))) : '';
        if (!$st) {
            echo '<span class="warn">ℹ ' . acf_h(aut_t('CfFedNever')) . '</span>';
        } elseif ($st->BfsState === 'ok') {
            echo '<span class="ok">✔ ' . acf_h($loaded) . '</span>';
        } elseif ($st->BfsState === 'short') {
            echo '<span class="ko">⚠ ' . acf_h(aut_t('CfFedShort', array('n' => $num($st->BfsPending), 'b' => $num($st->BfsCount)))) . '</span>'
                . '<div style="margin:6px 0 0"><button type="submit" name="action" value="fed_force:' . acf_h($src) . '" data-confirm="'
                . acf_h(aut_t('CfFedForceConfirm', $src)) . '" onclick="return confirm(this.dataset.confirm);">'
                . acf_h(aut_t('CfFedForce', $num($st->BfsPending))) . '</button></div>';
            if ($loaded !== '') echo '<div class="hint">' . acf_h($loaded) . '</div>';
        } elseif ($st->BfsState === 'nourl') {
            echo '<span class="warn">– ' . acf_h(aut_t('CfFedNoUrl')) . '</span>';
        } else {
            echo '<span class="ko">✘ ' . acf_h(aut_t('CfFedFail', $st->BfsMessage)) . '</span>';
            if ($loaded !== '') echo '<div class="hint">' . acf_h($loaded) . '</div>';
        }
        if ($st && $st->BfsChecked) echo '<div class="hint">' . acf_h(aut_t('CfFedChecked', aut_job_when($st->BfsChecked))) . '</div>';
        if (!$on && $st && intval($st->BfsCount) > 0) echo '<div class="warn">' . acf_h(aut_t('CfFedOff')) . '</div>';
        if ($st && intval($st->BfsCount) > 0) {
            echo $purge('fed_purge:' . $src, aut_t('CfFedPurge', $num($st->BfsCount)),
                aut_t('CfFedPurgeConfirm', array('n' => $num($st->BfsCount), 'src' => $src)), $on);
        }
        echo $auto('lf_fed[' . acf_h($src) . ']', $on) . '</td></tr>';
    }
    // Disabled after the click is sent, not before: a disabled button sends no "action".
    echo '<tr><td colspan="2" class="Center"><button type="submit" name="action" value="fed_sync"'
        . ' onclick="var b=this;setTimeout(function(){b.disabled=true;},0);">'
        . acf_h(aut_t('CfFedSyncNow')) . '</button><div class="hint">' . acf_h(aut_t('CfFedSyncHint')) . '</div></td></tr>';
}
echo '</table>';

/* ---------------- Backup ---------------- */
$fix = '';
$dirPb = aut_backup_dir_problem($bk['dir'], $fix, false);
$dump = aut_backup_mysqldump_bin();
$rcl  = aut_backup_rclone_bin();
$list = aut_backup_list($bk['dir']);

echo '<table class="Tabella">';
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
if ($bk['remote'] !== '') {
    echo '<tr><td colspan="2" class="Center"><button type="submit" name="action" value="test_remote"' . $dis . '>' . acf_h(aut_t('CfBkTest')) . '</button></td></tr>';
}
echo '<tr><td colspan="2" class="hint"><details><summary><b>' . acf_h(aut_t('CfRcloneHow')) . '</b></summary>'
    . '<p>' . aut_t('CfRclone1') . '</p>'
    . '<p>⚠️ ' . aut_t('CfRclone2') . '</p>'
    . '<p>' . acf_h(aut_t('CfRclone3')) . '</p>'
    . '<code class="cmd">sudo apt install rclone
sudo install -d -o www-data -g www-data -m 0700 /var/www/.config
sudo -u www-data rclone config</code>'
    . '<p>' . aut_t('CfRclone4') . '</p>'
    . '</details></td></tr>';
echo '</table></form>' . ($dis === '' ? aut_adm_save('acf-settings') : '');

/* ---------------- Raw editor ---------------- */
echo '<table class="Tabella">';
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

/* ---------------- Journal of the scheduled jobs ---------------- */
echo '<tr><th class="Title" colspan="2">' . acf_h(aut_t('CfJobs')) . '</th></tr>';
echo '<tr><td colspan="2" class="hint">' . acf_h(aut_t('CfJobsIntro')) . '</td></tr>';
$acfRs = safe_r_sql("SELECT * FROM AuthJobs WHERE AjWhen > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY) ORDER BY AjId DESC LIMIT 80");
$acfJobs = '';
while ($j = safe_fetch($acfRs)) {
    $acfJobs .= '<tr><td style="white-space:nowrap">' . acf_h(aut_job_when($j->AjWhen)) . '</td><td>' . acf_h(aut_backup_step_label($j->AjJob))
        . '</td><td class="Center">' . ($acfJobIcon[$j->AjStatus] ?? acf_h($j->AjStatus)) . '</td><td>' . acf_h(aut_job_text($j)) . '</td></tr>';
}
echo '<tr><td colspan="2">' . ($acfJobs === '' ? '<i>' . acf_h(aut_t('CfJobsEmpty')) . '</i>'
    : '<table class="acf-jobs"><tr><th>' . acf_h(aut_t('CfJobWhen')) . '</th><th>' . acf_h(aut_t('CfJobTask')) . '</th><th></th><th>'
        . acf_h(aut_t('CfJobResult')) . '</th></tr>' . $acfJobs . '</table>') . '</td></tr>';

echo '</table></div>';
echo aut_adm_close();
include('Common/Templates/tail.php');
