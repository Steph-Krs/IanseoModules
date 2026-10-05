<?php
/**
 * Deployed from Modules/Custom/AUTH/dist/ — turning on the TOTP two-factor authentication
 * (mandatory for ADMIN accounts).
 *
 * Identity confirmed before turning it on:
 *  - local account (stored password): the local password is checked;
 *  - SSO account (no local password): authentication again with the FFTA officers' space
 *    (the password is not kept).
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

$isSso = ($u->AuPassword === '');   // account provisioned through the officers' space
$isAdmin = ($u->AuRole == AUT_ROLE_ADMIN);
$err = '';
$done = false;
$off  = false;
$mandatory = ($isAdmin && !$u->AuTotpEnabled);   // ADMIN without 2FA: mandatory

/* 2FA can be turned on by EVERY account (security option); it stays MANDATORY, and cannot be
   turned off, for the administrators. */
$allowed = true;

/* Turning off (NON-admin accounts only): confirmed by a valid code. */
if (!$isAdmin && $u->AuTotpEnabled && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['disable'])) {
    $usedSlot = 0;
    if (aut_too_many_failures($u->AuUsername)) {
        aut_log('LOGIN_BLOCK', $u->AuUsername);
        $err = aut_t('LoginTooMany');
    } elseif (!aut_totp_verify($u->AuTotpSecret, $_POST['code'] ?? '', intval($u->AuTotpLastSlot), $usedSlot)) {
        aut_log('TOTP_FAIL', $u->AuUsername);
        $err = aut_t('TfBadCodeStays');
    } else {
        safe_w_sql("UPDATE AuthUsers SET AuTotpSecret='', AuTotpEnabled=0, AuTotpLastSlot=0 WHERE AuId={$u->AuId}");
        aut_sessions_revoke($u->AuId, aut_current_token_hash());   // revokes the other sessions
        aut_log('TOTP_DISABLE', $u->AuUsername);
        $u->AuTotpEnabled = 0;
        $off = true;
    }
}

if ($allowed && !$off && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm'])) {
    // provisional secret in the session until confirmed by a valid code
    $secret = $_SESSION['AUT_2FA_NewSecret'] ?? '';
    $usedSlot = 0;
    if ($secret === '') {
        $err = aut_t('TfExpired');
    } elseif (aut_too_many_failures($u->AuUsername)) {
        aut_log('LOGIN_BLOCK', $u->AuUsername);
        $err = aut_t('LoginTooMany');
    } elseif (!aut_totp_verify($secret, $_POST['code'] ?? '', 0, $usedSlot)) {
        aut_log('TOTP_FAIL', $u->AuUsername);
        $err = aut_t('TfBadCodeSync');
    } else {
        // identity confirmation
        $identityOk = false;
        if ($isSso) {
            $structs = array();
            $e = '';
            $identityOk = aut_ffta_verify($u->AuUsername, $_POST['password'] ?? '', trim($_POST['fftaotp'] ?? ''), $structs, $e);
            if (!$identityOk) $err = $e ?: aut_t('TfBadSsoPwd');
        } else {
            $identityOk = password_verify($_POST['password'] ?? '', $u->AuPassword);
            if (!$identityOk) $err = aut_t('TfBadPwd');
        }
        if (!$identityOk) {
            aut_log('TOTP_FAIL', $u->AuUsername);
        } else {
            safe_w_sql("UPDATE AuthUsers SET AuTotpSecret=" . StrSafe_DB($secret)
                . ", AuTotpEnabled=1, AuTotpLastSlot=$usedSlot WHERE AuId={$u->AuId}");
            aut_sessions_revoke($u->AuId, aut_current_token_hash());   // revokes the other sessions
            aut_log('TOTP_ENABLE', $u->AuUsername);
            unset($_SESSION['AUT_2FA_NewSecret']);
            $done = true;
        }
    }
}

// (re)generates a provisional secret for the display
if ($allowed && !$done && !$off && (empty($_SESSION['AUT_2FA_NewSecret']) || isset($_POST['regen']))) {
    $_SESSION['AUT_2FA_NewSecret'] = aut_totp_new_secret();
}
$secret = $_SESSION['AUT_2FA_NewSecret'] ?? '';
$secretDisplay = $secret !== '' ? trim(chunk_split($secret, 4, ' ')) : '';
$uri = $secret !== '' ? aut_totp_uri($u->AuUsername, $secret) : '';
$qr  = $uri !== '' ? aut_qr_svg($uri) : '';

$e = function ($s) { return htmlspecialchars((string) $s); };
echo '<!DOCTYPE html>
<html lang="' . $e(aut_lang_code()) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>' . $e(aut_t('TfPageTitle')) . '</title>
';
?>
<style>
body { margin:0; font-family:Verdana,Arial,sans-serif; background:#eef2f6;
       display:flex; align-items:center; justify-content:center; min-height:100vh; }
.card { background:#fff; border:1px solid #c9d4df; border-radius:8px; padding:32px 36px;
        box-shadow:0 4px 16px rgba(0,0,0,.08); width:420px; }
h1 { font-size:18px; margin:0 0 4px; color:#1a4f8b; }
.sub { font-size:11px; color:#667; margin-bottom:16px; }
label { display:block; font-size:12px; margin:12px 0 4px; color:#334; }
input[type=text], input[type=password] { width:100%; box-sizing:border-box; padding:8px;
        border:1px solid #b6c2cf; border-radius:4px; font-size:14px; }
button { margin-top:14px; width:100%; padding:9px; background:#1a4f8b; color:#fff;
        border:0; border-radius:4px; font-size:14px; cursor:pointer; }
.qr { text-align:center; margin:14px 0; }
.qr svg { border:1px solid #e0e6ec; border-radius:6px; }
.secret { font-family:monospace; font-size:15px; background:#f4f7fa; border:1px dashed #b6c2cf;
        padding:8px; text-align:center; border-radius:4px; letter-spacing:1px; }
.err  { background:#fde8e8; border:1px solid #e8b4b4; color:#8b1a1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.warn { background:#fff6df; border:1px solid #e8d8a4; color:#6b5a1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.ok   { background:#e8f4e8; border:1px solid #b4d8b4; color:#1a5c1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
ol { font-size:12px; color:#334; padding-left:18px; margin:8px 0; }
details { margin-top:8px; font-size:11px; }
.uri { font-size:10px; word-break:break-all; color:#889; margin-top:4px; }
.links { margin-top:14px; font-size:11px; text-align:center; }
.links a { color:#1a4f8b; }
</style>
<?php
$home = '<a href="' . $CFG->ROOT_DIR . '">' . $e(aut_t('TfGoIanseo')) . '</a>';
echo "</head>\n<body>\n" . '<div class="card">' . "\n"
    . '<h1>' . $e(aut_t('TfTitle')) . "</h1>\n"
    . '<div class="sub">' . aut_t('TfAccount', '<b>' . $e($u->AuUsername) . '</b>') . "</div>\n";
if (!$isAdmin) echo '<div class="sub">' . aut_t($isSso ? 'TfOptionalSso' : 'TfOptionalPwd') . "</div>\n";
if ($mandatory) echo '<div class="warn">' . aut_t('TfMandatory') . "</div>\n";
if ($err)  echo '<div class="err">' . $e($err) . "</div>\n";
if ($done) echo '<div class="ok">' . $e(aut_t('TfDone')) . ' ' . $home . "</div>\n";
if ($off)  echo '<div class="ok">' . $e(aut_t('TfOff')) . ' ' . $home . "</div>\n";

if (!$done && !$off) {
    $reconf = !empty($u->AuTotpEnabled);
    if ($reconf) {
        echo '<div class="ok">🔒 ' . aut_t('TfActive') . "</div>\n";
        if (!$isAdmin) {
            echo '<form method="post" action="">'
                . '<label for="dcode">' . $e(aut_t('TfDisableLabel')) . '</label>'
                . '<input type="text" id="dcode" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">'
                . '<button type="submit" name="disable" value="1" style="background:#a33;">' . $e(aut_t('TfDisableBtn')) . '</button>'
                . "</form>\n";
        }
        echo '<details style="margin-top:10px"><summary>' . $e(aut_t('TfChangeDevice')) . "</summary>\n";
    }
    echo '<ol><li>' . aut_t('TfStep1') . '</li><li>' . aut_t('TfStep2') . "</li></ol>\n"
        . ($qr ? '<div class="qr">' . $qr . "</div>\n" : '')
        . '<details' . ($qr ? '' : ' open') . '><summary>' . $e(aut_t('TfManual')) . '</summary>'
        . '<div class="secret">' . $secretDisplay . '</div>'
        . '<div class="uri">' . $e($uri) . "</div></details>\n"
        . '<form method="post" action="">'
        . '<label for="code">' . $e(aut_t('TfCodeLabel')) . '</label>'
        . '<input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus>';
    if ($isSso) {
        echo '<label for="password">' . $e(aut_t('TfConfirmSsoPwd')) . '</label>'
            . '<input type="password" id="password" name="password" autocomplete="current-password">'
            . '<label for="fftaotp">' . $e(aut_t('TfSsoMfa')) . ' <small>(' . $e(aut_t('LoginOtpHint')) . ')</small></label>'
            . '<input type="text" id="fftaotp" name="fftaotp" inputmode="numeric" maxlength="8" autocomplete="one-time-code">';
    } else {
        echo '<label for="password">' . $e(aut_t('TfConfirmPwd')) . '</label>'
            . '<input type="password" id="password" name="password" autocomplete="current-password">';
    }
    echo '<button type="submit" name="confirm" value="1">' . $e(aut_t($reconf ? 'TfReconfigure' : 'TfEnable')) . "</button></form>\n"
        . '<form method="post" action=""><button type="submit" name="regen" value="1" style="background:#889;">'
        . $e(aut_t('TfNewKey')) . "</button></form>\n";
    if ($reconf) echo "</details>\n";
}
echo '<div class="links"><a href="' . $CFG->ROOT_DIR . 'Modules/Authentication/LogOut.php">' . $e(aut_t('TfSignOut')) . "</a></div>\n"
    . "</div>\n</body>\n</html>\n";
