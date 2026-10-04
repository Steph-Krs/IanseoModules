<?php
/**
 * Deployed from Modules/Custom/AUTH/dist/ — password change.
 */
if (basename(__DIR__) !== 'Authentication') {
    http_response_code(403);
    die('This file must run from Modules/Authentication/.');
}
define('HTDOCS', dirname(__DIR__, 2));
require_once(HTDOCS . '/config.php');
require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/lib.php');

if (empty($_SESSION['AUTH_User'])) {
    CD_redirect($CFG->ROOT_DIR . 'Modules/Authentication/LogIn.php');
    die();
}

$u = aut_get_user($_SESSION['AUTH_User']);
if (!$u) {
    CD_redirect($CFG->ROOT_DIR . 'Modules/Authentication/LogOut.php');
    die();
}

$err = '';
$done = false;
$forced = !empty($u->AuMustChangePwd);
$e = function ($s) { return htmlspecialchars((string) $s); };

// SSO account (no local password): the password is managed on the FFTA officers' space, not
// here.
if ($u->AuPassword === '') {
    $PAGE_TITLE = aut_t('BarPassword');
    include('Common/Templates/head-min.php');
    echo '<div class="Center" style="padding:24px; font-family:Verdana,Arial,sans-serif;">'
        . '<p>' . aut_t('CpSso') . '</p>'
        . '<p>' . aut_t('CpSsoWhere', '<a href="https://dirigeant.ffta.fr" target="_blank" rel="noopener">dirigeant.ffta.fr</a>') . '</p>'
        . '<p><a href="' . $CFG->ROOT_DIR . '">' . $e(aut_t('CpBack')) . '</a></p></div>';
    include('Common/Templates/tail-min.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $old = $_POST['old'] ?? '';
    $new1 = $_POST['new1'] ?? '';
    $new2 = $_POST['new2'] ?? '';
    if (!password_verify($old, $u->AuPassword)) {
        $err = aut_t('CpBadOld');
    } elseif ($new1 !== $new2) {
        $err = aut_t('CpMismatch');
    } elseif (!aut_password_ok($new1)) {
        $err = aut_t('CpWeak');
    } elseif (password_verify($new1, $u->AuPassword)) {
        $err = aut_t('CpSame');
    } else {
        $hash = password_hash($new1, PASSWORD_DEFAULT);
        safe_w_sql("UPDATE AUT_Users SET AuPassword=" . StrSafe_DB($hash) . ", AuMustChangePwd=0 WHERE AuId={$u->AuId}");
        // revokes every other session (the current token stays valid)
        aut_sessions_revoke($u->AuId, aut_current_token_hash());
        aut_log('PWD_CHANGE', $u->AuUsername);
        $done = true;
        $forced = false;
    }
}

echo '<!DOCTYPE html>
<html lang="' . $e(aut_lang_code()) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . $e(aut_t('CpPageTitle')) . '</title>
';
?>
<style>
body { margin:0; font-family:Verdana,Arial,sans-serif; background:#eef2f6;
       display:flex; align-items:center; justify-content:center; min-height:100vh; }
.card { background:#fff; border:1px solid #c9d4df; border-radius:8px; padding:32px 36px;
        box-shadow:0 4px 16px rgba(0,0,0,.08); width:340px; }
h1 { font-size:18px; margin:0 0 4px; color:#1a4f8b; }
.sub { font-size:11px; color:#667; margin-bottom:20px; }
label { display:block; font-size:12px; margin:12px 0 4px; color:#334; }
input[type=password] { width:100%; box-sizing:border-box; padding:8px;
        border:1px solid #b6c2cf; border-radius:4px; font-size:14px; }
button { margin-top:18px; width:100%; padding:9px; background:#1a4f8b; color:#fff;
        border:0; border-radius:4px; font-size:14px; cursor:pointer; }
.err  { background:#fde8e8; border:1px solid #e8b4b4; color:#8b1a1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.info { background:#fff6df; border:1px solid #e8d8a4; color:#6b5a1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.ok   { background:#e8f4e8; border:1px solid #b4d8b4; color:#1a5c1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.links { margin-top:14px; font-size:11px; text-align:center; }
.links a { color:#1a4f8b; }
</style>
<?php
echo "</head>\n<body>\n" . '<div class="card">' . "\n"
    . '<h1>' . $e(aut_t('CpTitle')) . "</h1>\n"
    . '<div class="sub">' . aut_t('TfAccount', '<b>' . $e($u->AuUsername) . '</b>') . "</div>\n";
if ($forced) echo '<div class="info">' . $e(aut_t('CpForced')) . "</div>\n";
if ($err)  echo '<div class="err">' . $e($err) . "</div>\n";
if ($done) echo '<div class="ok">' . $e(aut_t('CpDone')) . ' <a href="' . $CFG->ROOT_DIR . '">' . $e(aut_t('TfGoIanseo')) . "</a></div>\n";
if (!$done) {
    echo '<form method="post" action="">'
        . '<label for="old">' . $e(aut_t('CpOld')) . '</label>'
        . '<input type="password" id="old" name="old" autocomplete="current-password" autofocus>'
        . '<label for="new1">' . $e(aut_t('CpNew')) . ' <small>(' . $e(aut_t('CpNewHint')) . ')</small></label>'
        . '<input type="password" id="new1" name="new1" autocomplete="new-password">'
        . '<label for="new2">' . $e(aut_t('CpNew2')) . '</label>'
        . '<input type="password" id="new2" name="new2" autocomplete="new-password">'
        . '<button type="submit">' . $e(aut_t('CpSubmit')) . '</button>'
        . "</form>\n";
}
echo '<div class="links"><a href="LogOut.php">' . $e(aut_t('TfSignOut')) . "</a></div>\n"
    . "</div>\n</body>\n</html>\n";
