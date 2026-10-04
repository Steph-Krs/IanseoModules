<?php
/**
 * AUTH module — deployment of the authentication files.
 *
 * Copies dist/ to htdocs/Modules/Authentication/ and manages the $CFG->USERAUTH flag in
 * Common/config.inc.php (which survives ianseo updates).
 *
 * To run again after each official ianseo update if the files were overwritten/deleted (the
 * admin bar shows a warning when it happens).
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once('Common/Fun_FormatText.inc.php');

checkFullACL(AclRoot, '', AclReadWrite);
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

aut_ensure_schema();

$msgOk = '';
$msgErr = '';

$q = safe_r_sql("SELECT COUNT(*) AS n FROM AUT_Users WHERE AuRole='ADMIN' AND AuActive=1");
$r = safe_fetch($q);
$nbAdmins = $r ? intval($r->n) : 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!aut_csrf_check()) {
        $msgErr = htmlspecialchars(aut_t('UsSessionExpired'));
    } else {
        $action = $_POST['action'] ?? '';
        if ($action == 'deploy') {
            $errors = array();
            if (aut_deploy($errors)) {
                aut_log('DEPLOY', $_SESSION['AUTH_User'] ?? 'local');
                $msgOk = htmlspecialchars(aut_t('DpDone'));
            } else {
                $msgErr = htmlspecialchars(aut_t('DpIncomplete', implode(' ; ', $errors)));
            }
        }
        if ($action == 'enable') {
            $st = aut_dist_status();
            if (!$st['deployed']) {
                $msgErr = htmlspecialchars(aut_t('DpDeployFirst'));
            } elseif (!$nbAdmins) {
                $msgErr = htmlspecialchars(aut_t('DpAdminFirst'));
            } else {
                $e = '';
                if (aut_set_userauth(true, $e)) {
                    aut_log('USERAUTH_ON', $_SESSION['AUTH_User'] ?? 'local');
                    $msgOk = htmlspecialchars(aut_t('DpEnabled'));
                } else {
                    $msgErr = htmlspecialchars($e);
                }
            }
        }
        if ($action == 'disable') {
            $e = '';
            if (aut_set_userauth(false, $e)) {
                aut_log('USERAUTH_OFF', $_SESSION['AUTH_User'] ?? 'local');
                $msgOk = htmlspecialchars(aut_t('DpDisabled'));
            } else {
                $msgErr = htmlspecialchars($e);
            }
        }
    }
}

$st = aut_dist_status();
$flag = aut_userauth_flag_state();

$PAGE_TITLE = aut_t('MenuTitle') . ' — ' . aut_t('MenuDeploy');
include('Common/Templates/head.php');

$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
$action = function ($name, $label, $attrs = '') use ($e) {
    return '<form method="post" action="" style="display:inline;">' . aut_csrf_field()
        . '<input type="hidden" name="action" value="' . $name . '">'
        . '<button type="submit"' . $attrs . '>' . $e($label) . "</button></form>\n";
};
$confirm = function ($text) use ($e) {
    return ' data-confirm="' . $e($text) . '" onclick="return confirm(this.dataset.confirm);"';
};

echo '<table class="Tabella">' . "\n"
    . '<tr><th class="Title" colspan="3">' . $e(aut_t('DpTitle')) . "</th></tr>\n";
if ($msgOk)  echo '<tr><td colspan="3" class="Center" style="background:#e8f4e8; color:#1a5c1a;">' . $msgOk . "</td></tr>\n";
if ($msgErr) echo '<tr><td colspan="3" class="Center" style="background:#fde8e8; color:#8b1a1a;">' . $msgErr . "</td></tr>\n";
echo '<tr><td colspan="3" style="font-size:11px;">' . aut_t('DpIntro') . "</td></tr>\n"
    . '<tr><th class="Title" colspan="3">' . $e(aut_t('DpFiles')) . "</th></tr>\n"
    . '<tr><th class="Title w-40">' . $e(aut_t('DpFile')) . '</th><th class="Title w-30">' . $e(aut_t('DpDeployed'))
    . '</th><th class="Title w-30">' . $e(aut_t('DpSame')) . "</th></tr>\n";
foreach ($st['files'] as $f => $s) {
    echo '<tr><td><code>Modules/Authentication/' . $f . '</code></td>'
        . '<td class="Center">' . ($s['deployed'] ? '✅' : '❌ ' . $e(aut_t('DpMissing'))) . '</td>'
        . '<td class="Center">' . ($s['deployed'] ? ($s['same'] ? '✅' : '⚠ ' . $e(aut_t('DpDiffers'))) : '—') . "</td></tr>\n";
}
$flags = array(
    'on'     => '<b style="color:#1a5c1a;">' . $e(aut_t('DpFlagOn')) . '</b>',
    'off'    => '<b style="color:#8b1a1a;">' . $e(aut_t('DpFlagOff')) . '</b>',
    'absent' => '<b style="color:#8b1a1a;">' . $e(aut_t('DpFlagAbsent')) . '</b>',
    'nofile' => '<b>' . $e(aut_t('DpFlagNoFile')) . '</b>',
);
echo '<tr><th class="Title" colspan="3">' . $e(aut_t('DpActivation')) . "</th></tr>\n"
    . '<tr><td colspan="3">'
    . aut_t('DpFlagLine', $flags[$flag])
    . ' — ' . aut_t('DpEffective', '<b>' . $e(aut_t(!empty($CFG->USERAUTH) ? 'DpActive' : 'DpInactive')) . '</b>')
    . ' — ' . aut_t('DpAdmins', '<b>' . $nbAdmins . '</b>')
    . "</td></tr>\n"
    . '<tr><td colspan="3" class="Center">'
    . $action('deploy', aut_t('DpBtnDeploy'))
    . $action('enable', aut_t('DpBtnEnable'),
        ((!$st['deployed'] || !$nbAdmins) ? ' disabled title="' . $e(aut_t('DpEnableNeeds')) . '"' : '') . $confirm(aut_t('DpConfirmEnable')))
    . $action('disable', aut_t('DpBtnDisable'), $confirm(aut_t('DpConfirmDisable')))
    . "</td></tr>\n"
    . '<tr><td colspan="3" style="font-size:11px;">' . aut_t('DpSelfheal') . "</td></tr>\n"
    . '<tr><td colspan="3" class="Center"><a href="config.php">' . $e(aut_t('DpConfigLink')) . " →</a></td></tr>\n"
    . "</table>\n";
include('Common/Templates/tail.php');
