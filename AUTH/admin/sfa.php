<?php
/**
 * admin/sfa.php — option "competitions created only through SYNCHRO_FFTA".
 *
 * Offered only when SYNCHRO_FFTA is installed (aut_sfa_present). On, the core's "New" is hidden
 * and refused for everyone but the administrator, and an import may only bring back a
 * competition already on the server (lib.php: aut_sfa_guard, aut_can_use_code). Stored in the
 * core's Parameters table, key SfaCreateOnly ('1' / '0').
 *
 * Same guard as the update pages: AclRoot, plus the server administrator view when an account
 * is signed in.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__, 2) . '/_shared/update-lib.php');

upd_admin_guard();

$ok = ''; $err = '';
$present = aut_sfa_present();

if ($present && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!aut_csrf_check()) {
        $err = aut_t('LoginSessionExpired');
    } else {
        $on = !empty($_POST['create_only']);
        SetParameter('SfaCreateOnly', $on ? '1' : '0');
        aut_log('SFA_ONLY_SET', ($_SESSION['AUTH_User'] ?? '') . ' ' . ($on ? 'on' : 'off'));
        CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/sfa.php?ok=1');
        die();
    }
}
if (isset($_GET['ok'])) $ok = aut_t('SfaSaved');

$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
$on = aut_sfa_create_only();

$PAGE_TITLE = aut_t('MenuSfaOnly');
include('Common/Templates/head.php');

echo '<style>
#aut-sfa h1 { font-size:22px; color:#01367c; margin:0 0 4px; }
#aut-sfa .lead { color:#4c4e50; font-size:14px; margin:0 0 16px; }
#aut-sfa .sec { background:#fff; border:1px solid #d2d4d6; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08);
    padding:16px 18px; margin:0 0 14px; }
#aut-sfa label { display:flex; gap:8px; align-items:flex-start; font-size:14px; color:#20263d; font-weight:600; }
#aut-sfa label input { margin-top:3px; }
#aut-sfa ul { margin:10px 0 0; padding-left:20px; color:#4c4e50; font-size:13px; line-height:1.6; }
#aut-sfa .msg { padding:10px 13px; border-radius:6px; margin:0 0 14px; font-size:14px; }
#aut-sfa .ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#aut-sfa .err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#aut-sfa .warn { background:#fff8e1; border:1px solid #e0a800; color:#5b4300; border-radius:6px; padding:10px 13px; font-size:13px; }
#aut-sfa .btn { margin-top:14px; padding:9px 18px; border:1px solid #0254a8; border-radius:6px; background:#0254a8;
    color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
#aut-sfa .btn:hover { background:#01367c; }
</style>' . "\n";

echo '<div id="aut-sfa"><h1>' . $e(aut_t('SfaTitle')) . '</h1>'
    . '<p class="lead">' . $e(aut_t('SfaLead')) . '</p>';
if ($ok)  echo '<div class="msg ok">' . $e($ok) . '</div>';
if ($err) echo '<div class="msg err">' . $e($err) . '</div>';

if (!$present) {
    echo '<div class="warn">' . $e(aut_t('SfaAbsent')) . '</div></div>';
} else {
    echo '<form method="post" action="" class="sec">' . aut_csrf_field()
        . '<label><input type="checkbox" name="create_only" value="1"' . ($on ? ' checked' : '') . '> '
        . $e(aut_t('SfaBox')) . '</label><ul>'
        . '<li>' . $e(aut_t('SfaNew')) . ' <a href="' . $e(aut_sfa_create_url()) . '">' . $e(aut_t('SfaCreatePage')) . ' →</a></li>'
        . '<li>' . $e(aut_t('SfaAdminKeeps')) . '</li>'
        . '<li>' . $e(aut_t('SfaImport')) . '</li>'
        . '<li>' . $e(aut_t('SfaUninstalled')) . '</li>'
        . '</ul><button type="submit" class="btn">' . $e(aut_t('SfaSave')) . '</button></form></div>';
}

include('Common/Templates/tail.php');
