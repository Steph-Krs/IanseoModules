<?php
/**
 * public/profile.php — profile of an archer WITHOUT an FFTA licence (lib/other.php): the club
 * always; names, sex, birth year and country when they were typed (an identity taken from World
 * Archery stays as it is); the password. FFTA licensees have nothing to edit here: their identity
 * is the federation's.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/other.php';

$archer = bk_require_archer();
if (($archer->BaKind ?? 'FFTA') !== 'OTHER') bk_redirect('');

$readonly = function_exists('bk_impersonating') && bk_impersonating();
$countries = bk_other_countries();
$fromWa = $archer->BaSource === 'wa';
$ok = ''; $err = '';

if (!$readonly && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif (($_POST['action'] ?? '') === 'password') {
        $cur = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['password'] ?? '');
        if (bk_too_many(array('PWD_FAIL'), BK_MAX_LOGIN_FAIL, $archer->BaLicence)) {
            $err = bk_t('TooManyTries');
        } elseif (!password_verify($cur, $archer->BaPassword)) {
            bk_log('PWD_FAIL', $archer->BaLicence);
            $err = bk_t('OtPwdCurrentBad');
        } elseif (mb_strlen($new) < 8) {
            $err = bk_t('OtPwdShort');
        } elseif ($new !== (string) ($_POST['password2'] ?? '')) {
            $err = bk_t('OtPwdDiffer');
        } else {
            safe_w_sql("UPDATE BookingArchers SET BaPassword = " . StrSafe_DB(password_hash($new, PASSWORD_DEFAULT))
                . " WHERE BaId = " . intval($archer->BaId) . " AND BaKind = 'OTHER'");
            // The other signed-in devices are closed: the old password no longer opens anything.
            safe_w_sql("DELETE FROM BookingSessions WHERE BkArcher = " . intval($archer->BaId)
                . " AND BkTokenHash <> " . StrSafe_DB(bk_current_token_hash()));
            bk_log('PWD_CHANGE', $archer->BaLicence);
            $ok = bk_t('OtPwdChanged');
        }
    } else {
        $in = array(
            'club'    => trim((string) ($_POST['club'] ?? '')),
            'family'  => $fromWa ? $archer->BaFamilyName : trim((string) ($_POST['family'] ?? '')),
            'given'   => $fromWa ? $archer->BaName : trim((string) ($_POST['given'] ?? '')),
            'sex'     => $fromWa ? intval($archer->BaSex) : (intval($_POST['sex'] ?? 0) ? 1 : 0),
            'year'    => $fromWa ? intval($archer->BaBirthYear) : intval($_POST['year'] ?? 0),
            'country' => $fromWa ? $archer->BaCountry : (string) ($_POST['country'] ?? ''),
        );
        $err = bk_other_validate($in, false);
        if ($err === '' && !$fromWa) {
            $dup = bk_other_duplicate($in + array('licence' => '', 'wa' => 0), $archer->BaId);
            if ($dup !== '') $err = bk_other_dup_message($dup);
        }
        if ($err === '') {
            bk_other_update($archer, $in);
            $archer = bk_get_archer($archer->BaId);
            $ok = bk_t('OtSaved');
        }
    }
}

$dis = ($fromWa || $readonly) ? ' disabled' : '';
list($yFrom, $yTo) = bk_other_year_range();
bk_head(bk_t('OtProfileTitle'));
$opt = '';
foreach ($countries as $k => $v) $opt .= '<option value="' . bk_e($k) . '"' . ($archer->BaCountry === $k ? ' selected' : '') . '>' . bk_e($v) . '</option>';
$out = '<div class="bk-block bk-other" style="max-width:560px"><h1>' . bk_e(bk_t('OtProfileTitle')) . '</h1>'
    . ($ok !== '' ? bk_msg('ok', $ok) : '') . ($err !== '' ? bk_msg('err', $err) : '')
    . '<p class="bk-hint">' . bk_e(bk_t($fromWa ? 'OtProfileWaId' : 'OtProfileLicence', $archer->BaLicence)) . '</p>'
    . ($fromWa ? '<p class="bk-hint">' . bk_e(bk_t('OtProfileFromWa')) . ' <a href="'
        . bk_e(bk_wa_profile_url($archer->BaWaId, $archer->BaName, $archer->BaFamilyName)) . '" target="_blank" rel="noopener noreferrer">'
        . bk_e(bk_t('OtWaPage')) . ' ↗</a></p>' : '')
    . '<form method="post" action="">' . bk_csrf_field() . '<input type="hidden" name="action" value="profile">'
    . '<label for="family">' . bk_e(bk_t('OtFamily')) . '</label><input type="text" id="family" name="family" value="' . bk_e($archer->BaFamilyName) . '" maxlength="60"' . $dis . '>'
    . '<label for="given">' . bk_e(bk_t('OtGiven')) . '</label><input type="text" id="given" name="given" value="' . bk_e($archer->BaName) . '" maxlength="30"' . $dis . '>'
    . '<label for="sex">' . bk_e(bk_t('OtSex')) . '</label><select id="sex" name="sex"' . $dis . '>'
    . '<option value="0"' . (!intval($archer->BaSex) ? ' selected' : '') . '>' . bk_e(bk_t('OtMan')) . '</option>'
    . '<option value="1"' . (intval($archer->BaSex) ? ' selected' : '') . '>' . bk_e(bk_t('OtWoman')) . '</option></select>'
    . '<label for="year">' . bk_e(bk_t('OtYear')) . '</label><input type="number" id="year" name="year" value="' . intval($archer->BaBirthYear) . '" min="' . $yFrom . '" max="' . $yTo . '"' . $dis . '>'
    . '<label for="country">' . bk_e(bk_t('OtCountry')) . '</label><select id="country" name="country"' . $dis . '>' . $opt . '</select>'
    . '<label for="club">' . bk_e(bk_t('OtClub')) . '</label><input type="text" id="club" name="club" value="' . bk_e($archer->BaClubName) . '" maxlength="80" required' . ($readonly ? ' disabled' : '') . '>'
    . ($readonly ? '' : '<button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('OtSave')) . '</button>') . '</form>';
if (!$readonly) {
    $out .= '<h2 style="margin-top:26px">' . bk_e(bk_t('OtPwdTitle')) . '</h2><form method="post" action="">' . bk_csrf_field()
        . '<input type="hidden" name="action" value="password">'
        . '<label for="current">' . bk_e(bk_t('OtPwdCurrent')) . '</label><input type="password" id="current" name="current" autocomplete="current-password" required>'
        . '<label for="password">' . bk_e(bk_t('OtPassword')) . '</label><input type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>'
        . '<label for="password2">' . bk_e(bk_t('OtPassword2')) . '</label><input type="password" id="password2" name="password2" autocomplete="new-password" minlength="8" required>'
        . '<button type="submit" class="bk-btn">' . bk_e(bk_t('OtPwdChange')) . '</button></form>';
}
$out .= '<p class="bk-hint" style="margin-top:20px">' . bk_e(bk_t('OtKeepNote')) . '</p>'
    . '<p><a class="bk-btn bk-btn-danger" href="' . bk_e(bk_public_url('delete-account.php')) . '">' . bk_e(bk_t('DelTitle')) . '</a></p></div>';
echo $out;
bk_foot();
