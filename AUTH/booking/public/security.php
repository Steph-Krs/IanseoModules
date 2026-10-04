<?php
/**
 * public/security.php — the licensee's two-factor authentication (2FA/TOTP), OPTIONAL.
 *
 * The connected archer may TURN ON a 2FA (authenticator app) to strengthen their sign-in, or TURN
 * IT OFF. Never forced. Lost phone: reset by the administrator (Accounts page). See lib/totp.php.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/totp.php';

$archer = bk_require_archer();

// Administrator's observation (read only): no 2FA management from this view.
$readonly = function_exists('bk_impersonating') && bk_impersonating();

$msg = '';
$err = '';
$done = false;      // turned on
$off  = false;      // turned off

if (!$readonly && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif (isset($_POST['disable']) && $archer->BaTotpEnabled) {
        // Turning off: needs a valid code (proof of possession).
        $usedSlot = 0;
        if (bk_too_many(array('TOTP_FAIL'), BK_MAX_LOGIN_FAIL, $archer->BaLicence)) {
            bk_log('LOGIN_BLOCK', $archer->BaLicence);
            $err = bk_t('TooManyTries');
        } elseif (!bk_totp_verify($archer->BaTotpSecret, $_POST['code'] ?? '', intval($archer->BaTotpLastSlot), $usedSlot)) {
            bk_log('TOTP_FAIL', $archer->BaLicence);
            $err = bk_t('SecBadCodeStays');
        } else {
            safe_w_sql("UPDATE BK_Archers SET BaTotpSecret='', BaTotpEnabled=0, BaTotpLastSlot=0 WHERE BaId=" . intval($archer->BaId));
            safe_w_sql("DELETE FROM BK_Sessions WHERE BsArcher=" . intval($archer->BaId)
                . " AND BsTokenHash <> '" . bk_current_token_hash() . "'");   // revokes the other sessions
            bk_log('TOTP_DISABLE', $archer->BaLicence);
            $archer->BaTotpEnabled = 0;
            $off = true;
        }
    } elseif (isset($_POST['confirm'])) {
        // Turning on: temporary secret in the session until a valid code confirms it.
        $secret = $_SESSION['BK_2FA_NewSecret'] ?? '';
        $usedSlot = 0;
        if ($secret === '') {
            $err = bk_t('SecRegen');
        } elseif (bk_too_many(array('TOTP_FAIL'), BK_MAX_LOGIN_FAIL, $archer->BaLicence)) {
            bk_log('LOGIN_BLOCK', $archer->BaLicence);
            $err = bk_t('TooManyTries');
        } elseif (!bk_totp_verify($secret, $_POST['code'] ?? '', 0, $usedSlot)) {
            bk_log('TOTP_FAIL', $archer->BaLicence);
            $err = bk_t('SecBadCodeClock');
        } else {
            safe_w_sql("UPDATE BK_Archers SET BaTotpSecret=" . StrSafe_DB($secret)
                . ", BaTotpEnabled=1, BaTotpLastSlot=$usedSlot WHERE BaId=" . intval($archer->BaId));
            safe_w_sql("DELETE FROM BK_Sessions WHERE BsArcher=" . intval($archer->BaId)
                . " AND BsTokenHash <> '" . bk_current_token_hash() . "'");   // revokes the other sessions
            bk_log('TOTP_ENABLE', $archer->BaLicence);
            unset($_SESSION['BK_2FA_NewSecret']);
            $archer->BaTotpEnabled = 1;
            $done = true;
        }
    }
}

$enabled = !empty($archer->BaTotpEnabled);

// (re)generates a temporary secret for the enrolment display
if (!$readonly && !$done && (empty($_SESSION['BK_2FA_NewSecret']) || isset($_POST['regen']))) {
    $_SESSION['BK_2FA_NewSecret'] = bk_totp_new_secret();
}
$secret = $_SESSION['BK_2FA_NewSecret'] ?? '';
$secretDisplay = $secret !== '' ? trim(chunk_split($secret, 4, ' ')) : '';
$uri = $secret !== '' ? bk_totp_uri($archer->BaLicence, $secret) : '';
$qr  = $uri !== '' ? bk_qr_svg($uri) : '';

// Enrolment block (QR + secret + confirmation), used as is for the first activation AND the
// reconfiguration (new device, inside a <details>).
$renderEnroll = function () use ($qr, $secretDisplay, $uri, $enabled) {
    return '<ol class="bk-hint" style="line-height:1.7"><li>' . bk_e(bk_t('SecStep1')) . '</li><li>' . bk_t('SecStep2') . '</li></ol>'
        . ($qr ? '<div class="sec-qr">' . $qr . '</div>' : '')
        . '<details' . ($qr ? '' : ' open') . '><summary class="bk-hint" style="cursor:pointer">' . bk_e(bk_t('SecManual')) . '</summary>'
        . '<div class="sec-secret">' . bk_e($secretDisplay) . '</div><div class="sec-uri">' . bk_e($uri) . '</div></details>'
        . '<form method="post" style="margin-top:12px">' . bk_csrf_field()
        . '<label for="code2" class="bk-hint" style="display:block;margin-bottom:4px">' . bk_e(bk_t('SecCodeLabel')) . '</label>'
        . '<input type="text" id="code2" name="code" class="sec-code" inputmode="numeric" pattern="[0-9]{6}"'
        . ' maxlength="6" autocomplete="one-time-code" placeholder="123456" autofocus> '
        . '<button type="submit" name="confirm" value="1" class="bk-btn bk-btn-primary">' . bk_e(bk_t($enabled ? 'SecReconfigure' : 'SecEnable')) . '</button>'
        . '</form><form method="post" style="margin-top:6px">' . bk_csrf_field()
        . '<button type="submit" name="regen" value="1" class="bk-btn">' . bk_e(bk_t('SecNewKey')) . '</button></form>';
};

bk_head(bk_t('SecHead'));
?>
<style>
#bk .sec-qr { text-align:center; margin:14px 0; }
#bk .sec-qr svg { border:1px solid #e0e6ec; border-radius:6px; }
#bk .sec-secret { font-family:monospace; font-size:15px; background:#f4f7fa; border:1px dashed #b6c2cf;
    padding:8px; text-align:center; border-radius:4px; letter-spacing:1px; }
#bk .sec-uri { font-size:10px; word-break:break-all; color:#889; margin-top:4px; }
#bk .sec-code { max-width:220px; }
#bk .sec-status { display:inline-block; padding:3px 10px; border-radius:12px; font-weight:600; font-size:13px; }
#bk .sec-on  { background:#d2f4cd; color:#1a7a2b; }
#bk .sec-off { background:#eef1f4; color:#5b6470; }
</style>
<?php
$out = '<div class="bk-block" style="max-width:560px"><h1>' . bk_e(bk_t('SecTitle')) . '</h1>'
    . '<p class="bk-hint">' . bk_t('SecIntro') . '</p>';
if ($readonly) {
    $out .= bk_msg('err', bk_t('SecReadOnly'));
} else {
    if ($msg) $out .= bk_msg('ok', $msg);
    if ($err) $out .= bk_msg('err', $err);
    $out .= '<p>' . bk_e(bk_t('SecState')) . ' <span class="sec-status ' . ($enabled ? 'sec-on' : 'sec-off') . '">'
        . bk_e(bk_t($enabled ? 'SecOn' : 'SecOff')) . '</span></p>';
    if ($done) $out .= bk_msg('ok', bk_t('SecEnabledMsg'));
    elseif ($off) $out .= bk_msg('ok', bk_t('SecDisabledMsg'));
    if ($enabled && !$done) {
        $out .= '<p class="bk-hint">' . bk_e(bk_t('SecDisableHint')) . '</p>'
            . '<form method="post" style="margin-bottom:16px">' . bk_csrf_field()
            . '<input type="text" name="code" class="sec-code" inputmode="numeric" pattern="[0-9]{6}"'
            . ' maxlength="6" autocomplete="one-time-code" placeholder="123456"> '
            . '<button type="submit" name="disable" value="1" class="bk-btn bk-btn-danger">' . bk_e(bk_t('SecDisable')) . '</button></form>'
            . '<details><summary class="bk-hint" style="cursor:pointer">' . bk_e(bk_t('SecNewDevice')) . '</summary>'
            . '<div style="margin-top:10px">' . $renderEnroll() . '</div></details>';
    } elseif (!$done) {
        $out .= $renderEnroll();
    }
}
echo $out . '<p style="margin-top:18px"><a class="bk-btn" href="' . bk_e(bk_public_url()) . '">' . bk_e(bk_t('BackMySpace')) . '</a></p></div>';
bk_foot();
