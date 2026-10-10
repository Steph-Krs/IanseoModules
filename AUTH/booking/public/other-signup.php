<?php
/**
 * public/other-signup.php — account of an archer WITHOUT an FFTA licence (lib/other.php).
 *
 *  1) names, sex and country; the World Archery athletes close to them (same country and sex,
 *     typos forgiven) are shown, sorted alphabetically, with their WA identifier, a link to their
 *     page on World Archery's site and their photo when WA has one. The list is kept in the
 *     session: a choice is only taken from it, never from the form.
 *  2a) found at World Archery: names, sex and country are WA's, the WA identifier is the licence
 *      and the sign-in identifier; asked: birth year (one of the two the WA age allows), club,
 *      password.
 *  2b) not found (or WA unreachable): national licence, birth year, club, password.
 * The account is then created and signed in; duplicates are refused (lib/other.php). The password
 * is hashed as soon as it is received.
 *
 * The photos are loaded by the archer's browser from World Archery (no load on this server; no
 * referrer sent), only while the list is shown.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/other.php';

if (bk_current_archer()) bk_redirect('');

$countries = bk_other_countries();
$err = '';
$stage = 'identity';
$f = array('family' => '', 'given' => '', 'sex' => 0, 'country' => '', 'year' => '', 'club' => '', 'licence' => '');
$pend = $_SESSION['BK_OTHER'] ?? null;
if (is_array($pend) && (time() - intval($pend['time'] ?? 0)) > 1800) { unset($_SESSION['BK_OTHER']); $pend = null; }

/** Creates the account and signs it in (ends the script); returns the error otherwise. */
$finish = function ($d) {
    $dup = bk_other_duplicate($d);
    if ($dup !== '') return bk_other_dup_message($dup);
    $id = bk_other_create($d);
    $a = $id ? bk_get_archer($id) : null;
    if (!$a) return bk_t('OtCreateFail');
    unset($_SESSION['BK_OTHER']);
    session_regenerate_id(true);
    bk_session_open($a);
    bk_log('LOGIN_OK', $a->BaLicence);
    bk_redirect('');
    return '';
};
/** Fields of the second step, from the form. */
$account = function () {
    return array('year' => intval($_POST['year'] ?? 0), 'club' => trim((string) ($_POST['club'] ?? '')),
        'licence' => bk_clean_licence($_POST['licence'] ?? ''), 'password' => (string) ($_POST['password'] ?? ''),
        'password2' => (string) ($_POST['password2'] ?? ''));
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $step = (string) ($_POST['stage'] ?? 'identity');
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif ($step === 'identity') {
        foreach (array('family', 'given', 'country') as $k) $f[$k] = trim((string) ($_POST[$k] ?? ''));
        $f['sex'] = intval($_POST['sex'] ?? 0) ? 1 : 0;
        $err = bk_other_check_identity($f);
        if ($err === '') {
            $id = array('family' => $f['family'], 'given' => $f['given'], 'sex' => $f['sex'], 'country' => $f['country']);
            $cand = bk_wa_search($id['country'], $id['family'], $id['given'], $id['sex']);
            $_SESSION['BK_OTHER'] = array('id' => $id, 'cand' => $cand ?: array(), 'time' => time());
            $stage = $cand ? 'pick' : 'own';
        }
    } elseif (!is_array($pend)) {
        $err = bk_t('OtExpired');
    } elseif ($step === 'pick') {
        $pick = (string) ($_POST['wa'] ?? '');
        if ($pick === 'none') {
            $stage = 'own';
        } elseif (isset($pend['cand'][$pick])) {
            if (bk_other_duplicate(array('licence' => $pick, 'wa' => intval($pick), 'family' => '', 'given' => '',
                    'country' => '', 'year' => 0, 'sex' => 0)) !== '') {
                $err = bk_t('OtDupWa');
                $stage = 'pick';
            } else {
                $_SESSION['BK_OTHER']['pick'] = $pick;
                $stage = 'wa';
            }
        } else {
            $err = bk_t('OtPickOne');
            $stage = 'pick';
        }
    } elseif ($step === 'wa' && isset($pend['pick'], $pend['cand'][$pend['pick']])) {
        // 2a: identity from World Archery, the WA identifier as licence.
        $c = $pend['cand'][$pend['pick']];
        $a = $account();
        $years = bk_other_birth_years($c['age']);
        $err = bk_other_check_account($a, false, true);
        if ($err === '' && $years && !in_array($a['year'], $years, true)) $err = bk_t('OtYearPick');
        if ($err === '') {
            $err = $finish(array('licence' => (string) $c['id'], 'wa' => $c['id'], 'source' => 'wa',
                'family' => $c['family'], 'given' => mb_strlen($c['given']) > 2 ? $c['given'] : $pend['id']['given'],
                'sex' => $c['sex'], 'country' => $pend['id']['country'], 'year' => $a['year'], 'club' => $a['club'],
                'hash' => password_hash($a['password'], PASSWORD_DEFAULT)));
        }
        $f = array_merge($f, $a);
        $stage = 'wa';
    } elseif ($step === 'own') {
        // 2b: what was typed, with the national licence.
        $a = $account();
        $err = bk_other_check_account($a, true, true);
        if ($err === '') {
            $err = $finish($pend['id'] + array('licence' => $a['licence'], 'wa' => 0, 'source' => 'own',
                'year' => $a['year'], 'club' => $a['club'], 'hash' => password_hash($a['password'], PASSWORD_DEFAULT)));
        }
        $f = array_merge($f, $a);
        $stage = 'own';
    }
}
$pend = $_SESSION['BK_OTHER'] ?? null;

/** Second step fields: year (free or one of the two WA allows), club, licence (2b), password. */
$fields2 = function ($years, $withLicence) use ($f) {
    $h = '<label for="year">' . bk_e(bk_t('OtYear')) . '</label>';
    if ($years) {
        $h .= '<select id="year" name="year" required><option value="">' . bk_e(bk_t('Choose')) . '</option>';
        foreach ($years as $y) $h .= '<option value="' . $y . '"' . (intval($f['year']) === $y ? ' selected' : '') . '>' . $y . '</option>';
        $h .= '</select><p class="bk-hint">' . bk_e(bk_t('OtYearWa')) . '</p>';
    } else {
        list($from, $to) = bk_other_year_range();
        $h .= '<input type="number" id="year" name="year" value="' . bk_e($f['year'] ?: '') . '" min="' . $from . '" max="' . $to . '" required>';
    }
    $h .= '<label for="club">' . bk_e(bk_t('OtClub')) . '</label><input type="text" id="club" name="club" value="' . bk_e($f['club']) . '" maxlength="80" required>';
    if ($withLicence) {
        $h .= '<label for="licence">' . bk_e(bk_t('OtLicence')) . '</label><input type="text" id="licence" name="licence" value="'
            . bk_e($f['licence']) . '" maxlength="25" autocomplete="username" required><p class="bk-hint">' . bk_e(bk_t('OtLicenceHint')) . '</p>';
    }
    return $h . '<label for="password">' . bk_e(bk_t('OtPassword')) . '</label><input type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>'
        . '<label for="password2">' . bk_e(bk_t('OtPassword2')) . '</label><input type="password" id="password2" name="password2" autocomplete="new-password" minlength="8" required>'
        . '<p class="bk-hint">' . bk_e(bk_t('OtPrivacy')) . '</p>'
        . '<button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('OtCreateBtn')) . '</button>';
};

bk_head(bk_t('OtSignupTitle'), 'card');
$out = '<div class="bk-card bk-other"><h1>' . bk_e(bk_t('OtSignupTitle')) . '</h1>' . ($err !== '' ? bk_msg('err', $err) : '');

if ($stage === 'pick') {
    $out .= '<p class="bk-sub">' . bk_e(bk_t('OtPickSub')) . '</p><form method="post" action="">' . bk_csrf_field()
        . '<input type="hidden" name="stage" value="pick"><div class="bk-wa-list">';
    foreach ($pend['cand'] as $id => $c) {
        $out .= '<label class="bk-wa"><input type="radio" name="wa" value="' . intval($id) . '" required>'
            . ($c['photo'] ? '<img src="' . bk_e(BK_WA_PHOTO . intval($id)) . '" alt="" loading="lazy" referrerpolicy="no-referrer">'
                           : '<span class="bk-wa-nophoto" aria-hidden="true"></span>')
            . '<span><b>' . bk_e(mb_strtoupper($c['family'], 'UTF-8')) . ' ' . bk_e($c['given']) . '</b><br>'
            . '<span class="bk-hint">' . bk_e(bk_t('OtWaId', intval($id)) . ($c['age'] > 0 ? ' — ' . bk_t('OtAge', $c['age']) : '')) . '</span><br>'
            . '<a class="bk-hint" href="' . bk_e(bk_wa_profile_url($id, $c['given'], $c['family'])) . '" target="_blank" rel="noopener noreferrer">'
            . bk_e(bk_t('OtWaPage')) . ' ↗</a></span></label>';
    }
    $out .= '<label class="bk-wa bk-wa-none"><input type="radio" name="wa" value="none" required><span>'
        . bk_e(bk_t('OtNotMe')) . '</span></label></div>'
        . '<button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('OtContinue')) . '</button></form>'
        . '<p class="bk-hint">' . bk_e(bk_t('OtWaNote')) . '</p>';
} elseif ($stage === 'wa') {
    $c = $pend['cand'][$pend['pick']];
    $out .= '<p class="bk-sub">' . bk_e(bk_t('OtWaChosen', array('name' => mb_strtoupper($c['family'], 'UTF-8') . ' ' . $c['given'], 'id' => intval($c['id'])))) . '</p>'
        . '<form method="post" action="">' . bk_csrf_field() . '<input type="hidden" name="stage" value="wa">'
        . $fields2(bk_other_birth_years($c['age']), false) . '</form>';
} elseif ($stage === 'own') {
    $out .= '<p class="bk-sub">' . bk_e(bk_t($pend && !$pend['cand'] ? 'OtOwnNoWa' : 'OtOwnSub',
            mb_strtoupper($pend['id']['family'] ?? '', 'UTF-8') . ' ' . ($pend['id']['given'] ?? ''))) . '</p>'
        . '<form method="post" action="">' . bk_csrf_field() . '<input type="hidden" name="stage" value="own">'
        . $fields2(array(), true) . '</form>';
} else {
    $opt = '<option value="">' . bk_e(bk_t('Choose')) . '</option>';
    foreach ($countries as $k => $v) $opt .= '<option value="' . bk_e($k) . '"' . ($f['country'] === $k ? ' selected' : '') . '>' . bk_e($v) . '</option>';
    $out .= '<p class="bk-sub">' . bk_e(bk_t('OtSignupSub')) . '</p><form method="post" action="">' . bk_csrf_field()
        . '<input type="hidden" name="stage" value="identity">'
        . '<label for="family">' . bk_e(bk_t('OtFamily')) . '</label><input type="text" id="family" name="family" value="' . bk_e($f['family']) . '" maxlength="60" required>'
        . '<label for="given">' . bk_e(bk_t('OtGiven')) . '</label><input type="text" id="given" name="given" value="' . bk_e($f['given']) . '" maxlength="30" required>'
        . '<label for="sex">' . bk_e(bk_t('OtSex')) . '</label><select id="sex" name="sex">'
        . '<option value="0"' . (!$f['sex'] ? ' selected' : '') . '>' . bk_e(bk_t('OtMan')) . '</option>'
        . '<option value="1"' . ($f['sex'] ? ' selected' : '') . '>' . bk_e(bk_t('OtWoman')) . '</option></select>'
        . '<label for="country">' . bk_e(bk_t('OtCountry')) . '</label><select id="country" name="country" required>' . $opt . '</select>'
        . '<button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('OtContinue')) . '</button></form>'
        . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('other.php')) . '">' . bk_e(bk_t('OtHaveAccount')) . '</a></p>';
}
if ($stage !== 'identity') {
    $out .= '<p class="bk-alt"><a href="' . bk_e(bk_public_url('other-signup.php')) . '">' . bk_e(bk_t('OtRestart')) . '</a></p>';
}
echo $out . '</div>';
bk_foot();
