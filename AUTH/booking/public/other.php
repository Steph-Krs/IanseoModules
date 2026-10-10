<?php
/**
 * public/other.php — sign-in of an archer WITHOUT an FFTA licence (lib/other.php): national
 * licence and password, then the code of their authenticator app when they turned it on
 * (security.php). FFTA licensees sign in with the federation (Modules/Custom/AUTH/login.php).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/other.php';
require_once dirname(__DIR__) . '/lib/totp.php';

if (bk_current_archer()) bk_redirect('');

$err = '';
$stage = 'password';
$licence = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif (($_POST['stage'] ?? '') === 'totp') {
        // Second step: the code of the authenticator app.
        $pend = $_SESSION['BK_OTHER_2FA'] ?? null;
        if (!is_array($pend) || (time() - intval($pend['time'] ?? 0)) > 300) {
            unset($_SESSION['BK_OTHER_2FA']);
            $err = bk_t('OtExpired');
        } elseif (bk_too_many(array('LOGIN_FAIL', 'TOTP_FAIL'), BK_MAX_LOGIN_FAIL, (string) $pend['licence'])) {
            bk_log('LOGIN_BLOCK', (string) $pend['licence']);
            $err = bk_t('TooManyTries');
        } else {
            $a = bk_get_archer(intval($pend['archer']));
            $slot = 0;
            if ($a && $a->BaActive && $a->BaTotpEnabled
                    && bk_totp_verify($a->BaTotpSecret, $_POST['code'] ?? '', intval($a->BaTotpLastSlot), $slot)) {
                safe_w_sql("UPDATE BookingArchers SET BaTotpLastSlot = $slot WHERE BaId = " . intval($a->BaId));
                unset($_SESSION['BK_OTHER_2FA']);
                session_regenerate_id(true);
                bk_session_open($a);
                bk_log('LOGIN_OK', $a->BaLicence);
                bk_redirect(bk_next_after_login());
            }
            bk_log('TOTP_FAIL', (string) $pend['licence']);
            $err = bk_t('OtTotpBad');
            $stage = 'totp';
        }
    } else {
        $licence = bk_clean_licence($_POST['licence'] ?? '');
        $pwd = (string) ($_POST['password'] ?? '');
        if ($licence === '' || $pwd === '') {
            $err = bk_t('OtNeedBoth');
        } elseif (bk_too_many(array('LOGIN_FAIL'), BK_MAX_LOGIN_FAIL, $licence)) {
            bk_log('LOGIN_BLOCK', $licence);
            $err = bk_t('TooManyTries');
        } else {
            $a = bk_other_check($licence, $pwd);
            $pwd = null;
            if (!$a) {
                bk_log('LOGIN_FAIL', $licence);
                $err = bk_t('OtBadLogin');
            } elseif (!$a->BaActive) {
                bk_log('LOGIN_DISABLED', $licence);
                $err = bk_t('OtDisabled');
            } elseif (!empty($a->BaTotpEnabled)) {
                $_SESSION['BK_OTHER_2FA'] = array('archer' => intval($a->BaId), 'licence' => $licence, 'time' => time());
                $stage = 'totp';
            } else {
                session_regenerate_id(true);
                bk_session_open($a);
                bk_log('LOGIN_OK', $licence);
                bk_redirect(bk_next_after_login());
            }
        }
    }
}

bk_head(bk_t('OtLoginTitle'), 'card');
$out = '<div class="bk-card"><h1>' . bk_e(bk_t('OtLoginTitle')) . '</h1>'
    . '<p class="bk-sub">' . bk_e(bk_t('OtLoginSub')) . '</p>'
    . ($err !== '' ? bk_msg('err', $err) : '')
    . '<form method="post" action="">' . bk_csrf_field();
if ($stage === 'totp') {
    $out .= '<input type="hidden" name="stage" value="totp">'
        . '<label for="code">' . bk_e(bk_t('OtTotpCode')) . '</label>'
        . '<input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>';
} else {
    $out .= '<label for="licence">' . bk_e(bk_t('OtLoginId')) . '</label>'
        . '<input type="text" id="licence" name="licence" value="' . bk_e($licence) . '" autocomplete="username" required autofocus>'
        . '<label for="password">' . bk_e(bk_t('OtPassword')) . '</label>'
        . '<input type="password" id="password" name="password" autocomplete="current-password" required>';
}
$out .= '<button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('OtSignIn')) . '</button></form>'
    . '<p class="bk-alt">' . bk_e(bk_t('OtNoAccount')) . ' <a href="' . bk_e(bk_public_url('other-signup.php')) . '">'
    . bk_e(bk_t('OtCreate')) . '</a></p>'
    . '<p class="bk-alt"><a href="' . bk_e($GLOBALS['CFG']->ROOT_DIR . 'Modules/Custom/AUTH/login.php?p=comp') . '">'
    . bk_e(bk_t('OtFftaLink')) . '</a></p>'
    . '<p class="bk-hint">' . bk_e(bk_t('OtForgot')) . '</p></div>';
echo $out;
bk_foot();
