<?php
/**
 * public/delete-account.php — the archer deletes their account (lib/other.php,
 * bk_other_self_delete). What is to come is erased for good: their own registrations for the
 * competitions not started (unless the organiser locked the participants) and their waiting
 * requests. Nothing that is done or under way changes: competitions started, past results,
 * accounts and payments due. An FFTA licensee who signs in again later gets a new, empty account.
 *
 * Confirmed by the password for an archer without an FFTA licence, by a checkbox otherwise (their
 * password is the federation's, never known here).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/other.php';

$archer = bk_require_archer();
$readonly = function_exists('bk_impersonating') && bk_impersonating();
$other = ($archer->BaKind ?? 'FFTA') === 'OTHER';
$err = '';

if (!$readonly && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif (empty($_POST['confirm'])) {
        $err = bk_t('DelNeedConfirm');
    } elseif ($other && bk_too_many(array('PWD_FAIL'), BK_MAX_LOGIN_FAIL, $archer->BaLicence)) {
        $err = bk_t('TooManyTries');
    } elseif ($other && !password_verify((string) ($_POST['password'] ?? ''), $archer->BaPassword)) {
        bk_log('PWD_FAIL', $archer->BaLicence);
        $err = bk_t('OtPwdCurrentBad');
    } else {
        $res = bk_other_self_delete($archer);
        unset($_SESSION['BK_Token']);
        session_regenerate_id(true);
        bk_head(bk_t('DelTitle'), 'card');
        echo '<div class="bk-card"><h1>' . bk_e(bk_t('DelDoneTitle')) . '</h1>'
            . bk_msg('ok', bk_t('DelDone', intval($res['deleted'])))
            . ($res['kept'] ? '<p class="bk-hint">' . bk_e(bk_t('DelKept', implode(', ', $res['kept']))) . '</p>' : '')
            . '<p class="bk-alt"><a href="' . bk_e($GLOBALS['CFG']->ROOT_DIR . 'Modules/Custom/AUTH/login.php?p=comp') . '">'
            . bk_e(bk_t('DelBack')) . '</a></p></div>';
        bk_foot();
        exit;
    }
}

// What the deletion would remove: own registrations for the competitions not started.
$rs = safe_r_sql("SELECT ToName, ToWhenFrom FROM BookingRegistrations
    INNER JOIN Tournament ON ToId = BrTournament
    WHERE BrLicence = " . StrSafe_DB(bk_clean_licence($archer->BaLicence)) . " AND BrByRole <> 'IMPORT'
      AND ToWhenFrom > " . bk_local_today_sql('ToTimeZone') . "
    ORDER BY ToWhenFrom");
$coming = array();
while ($r = safe_fetch($rs)) $coming[] = $r;

bk_head(bk_t('DelTitle'));
$out = '<div class="bk-block bk-other" style="max-width:640px"><h1>' . bk_e(bk_t('DelTitle')) . '</h1>'
    . ($err !== '' ? bk_msg('err', $err) : '')
    . '<p>' . bk_e(bk_t('DelIntro')) . '</p><ul class="bk-del-list">'
    . '<li>' . bk_e(bk_t('DelGone')) . '</li><li>' . bk_e(bk_t('DelStays')) . '</li>'
    . (!$other ? '<li>' . bk_e(bk_t('DelFfta')) . '</li>' : '') . '</ul>';
if ($coming) {
    $out .= '<p><b>' . bk_e(bk_t('DelComing')) . '</b></p><ul>';
    foreach ($coming as $c) $out .= '<li>' . bk_e($c->ToName) . ' — ' . bk_e(bk_date_fr($c->ToWhenFrom)) . '</li>';
    $out .= '</ul>';
}
if ($readonly) {
    $out .= '<p class="bk-hint">' . bk_e(bk_t('ImpReadOnly')) . '</p>';
} else {
    $out .= '<form method="post" action="">' . bk_csrf_field()
        . '<label class="bk-chk"><input type="checkbox" name="confirm" value="1" required> ' . bk_e(bk_t('DelConfirmBox')) . '</label>'
        . ($other ? '<label for="password">' . bk_e(bk_t('OtPwdCurrent')) . '</label>'
            . '<input type="password" id="password" name="password" autocomplete="current-password" required>' : '')
        . '<button type="submit" class="bk-btn bk-btn-danger">' . bk_e(bk_t('DelButton')) . '</button></form>';
}
echo $out . '</div>';
bk_foot();
