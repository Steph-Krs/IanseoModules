<?php
/**
 * public/other-signup.php — account of an archer WITHOUT an FFTA licence (lib/other.php).
 *
 *  1) the country first, then:
 *     - a country whose federation publishes its licensee file (lib/fedlic.php): the licence,
 *       checked here against the format of that country (never sent to the browser: the page
 *       shows a mask only) and looked up in the file. Not in the file: no account;
 *     - another country: names and sex; the World Archery athletes close to them (same country
 *       and sex, typos forgiven) are shown, sorted alphabetically, with their WA identifier, a
 *       link to their page on World Archery's site and their photo when WA has one. The list is
 *       kept in the session: a choice is only taken from it, never from the form.
 *  2) - from the file: names, sex, birth year and club are the file's, shown and not editable;
 *       only the password is asked (and the birth year when the file has none);
 *     - picked at World Archery: names and sex are WA's; asked: birth year (one of the two the
 *       WA age allows), club, password;
 *     - otherwise (or WA unreachable): national licence, birth year, club, password.
 * The identifier is "COUNTRY-licence" or "WA-id" (bk_other_login_id). The account is then created
 * and signed in; duplicates are refused (lib/other.php). The password is hashed as soon as it is
 * received.
 *
 * The photos are loaded by the archer's browser from World Archery (no load on this server; no
 * referrer sent), only while the list is shown.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/other.php';

if (bk_current_archer()) bk_redirect('');

$countries = bk_other_countries();
$fedc = bk_fed_active();
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
/** Password of the second step: '' when fine, else the message. */
$passwordErr = function ($a) {
    if (mb_strlen($a['password']) < 8) return bk_t('OtPwdShort');
    return $a['password'] !== $a['password2'] ? bk_t('OtPwdDiffer') : '';
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $step = (string) ($_POST['stage'] ?? 'identity');
    if (!bk_csrf_check()) {
        $err = bk_t('SessionExpired');
    } elseif ($step === 'identity') {
        foreach (array('family', 'given', 'country') as $k) $f[$k] = trim((string) ($_POST[$k] ?? ''));
        $f['sex'] = intval($_POST['sex'] ?? 0) ? 1 : 0;
        $c = $f['country'];
        if (!isset($countries[$c])) {
            $err = bk_t('OtNeedCountry');
        } elseif (isset($fedc[$c])) {
            // 1a: the licence, looked up in the federation file.
            $f['licence'] = bk_fed_clean($c, $_POST['licence'] ?? '');
            $row = null;
            if (!bk_fed_format_ok($c, $f['licence'])) {
                $err = bk_t('OtFedBadFormat', $fedc[$c]['ex']);
            } elseif (!($row = bk_fed_find($c, $f['licence']))) {
                $err = bk_t('OtFedNotFound');
            } else {
                $dup = bk_other_duplicate(array('licence' => bk_other_login_id($c, $f['licence']), 'wa' => 0,
                    'family' => $row->BflFamilyName, 'given' => $row->BflName, 'country' => $c,
                    'year' => intval(mb_substr((string) $row->BflBirth, 0, 4)), 'sex' => intval($row->BflSex)));
                if ($dup !== '') {
                    $err = bk_other_dup_message($dup);
                } else {
                    $_SESSION['BK_OTHER'] = array('fed' => array('country' => $c, 'code' => $f['licence']),
                        'id' => array('country' => $c), 'cand' => array(), 'time' => time());
                    $stage = 'fed';
                }
            }
        } else {
            // 1b: names and sex, searched at World Archery.
            $err = bk_other_check_identity($f);
            if ($err === '') {
                $id = array('family' => $f['family'], 'given' => $f['given'], 'sex' => $f['sex'], 'country' => $c);
                $cand = bk_wa_search($c, $id['family'], $id['given'], $id['sex']);
                $_SESSION['BK_OTHER'] = array('id' => $id, 'cand' => $cand ?: array(), 'time' => time());
                $stage = $cand ? 'pick' : 'own';
            }
        }
    } elseif (!is_array($pend)) {
        $err = bk_t('OtExpired');
    } elseif ($step === 'fed' && !empty($pend['fed'])) {
        // 2 (file): everything from the file but the password (and the birth year if it has none).
        $row = bk_fed_find($pend['fed']['country'], $pend['fed']['code']);
        $a = $account();
        list($yFrom, $yTo) = bk_other_year_range();
        $year = $row && $row->BflBirth ? intval(mb_substr((string) $row->BflBirth, 0, 4)) : $a['year'];
        if (!$row) {
            $err = bk_t('OtFedNotFound');
        } elseif ($year < $yFrom || $year > $yTo) {
            $err = bk_t('OtNeedYear');
        } elseif (($err = $passwordErr($a)) === '') {
            $err = $finish(array('licence' => bk_other_login_id($row->BflCountry, $row->BflCode), 'wa' => 0, 'source' => 'fed',
                'family' => $row->BflFamilyName, 'given' => $row->BflName, 'sex' => intval($row->BflSex),
                'country' => $row->BflCountry, 'year' => $year, 'club' => $row->BflClubName, 'club_code' => $row->BflClub,
                'hash' => password_hash($a['password'], PASSWORD_DEFAULT)));
        }
        $f['year'] = $a['year'];
        $stage = 'fed';
    } elseif ($step === 'pick') {
        $pick = (string) ($_POST['wa'] ?? '');
        if ($pick === 'none') {
            $stage = 'own';
        } elseif (isset($pend['cand'][$pick])) {
            if (bk_other_duplicate(array('licence' => bk_other_login_id('WA', $pick), 'wa' => intval($pick), 'family' => '',
                    'given' => '', 'country' => '', 'year' => 0, 'sex' => 0)) !== '') {
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
        // 2 (World Archery): identity from WA, the identifier "WA-id".
        $c = $pend['cand'][$pend['pick']];
        $a = $account();
        $years = bk_other_birth_years($c['age']);
        $err = bk_other_check_account($a, false, true);
        if ($err === '' && $years && !in_array($a['year'], $years, true)) $err = bk_t('OtYearPick');
        if ($err === '') {
            $err = $finish(array('licence' => bk_other_login_id('WA', $c['id']), 'wa' => $c['id'], 'source' => 'wa',
                'family' => $c['family'], 'given' => mb_strlen($c['given']) > 2 ? $c['given'] : $pend['id']['given'],
                'sex' => $c['sex'], 'country' => $pend['id']['country'], 'year' => $a['year'], 'club' => $a['club'],
                'hash' => password_hash($a['password'], PASSWORD_DEFAULT)));
        }
        $f = array_merge($f, $a);
        $stage = 'wa';
    } elseif ($step === 'own' && isset($pend['id']['family'])) {
        // 2 (typed): what was typed, with the national licence.
        $a = $account();
        $a['licence'] = bk_fed_clean($pend['id']['country'], $a['licence']);
        $err = bk_other_check_account($a, true, true);
        if ($err === '') {
            $err = $finish($pend['id'] + array('licence' => bk_other_login_id($pend['id']['country'], $a['licence']), 'wa' => 0,
                'source' => 'own', 'year' => $a['year'], 'club' => $a['club'], 'hash' => password_hash($a['password'], PASSWORD_DEFAULT)));
        }
        $f = array_merge($f, $a);
        $stage = 'own';
    }
}
$pend = $_SESSION['BK_OTHER'] ?? null;

/** Birth year field: free (bounded) or one of the two WA allows. */
$yearField = function ($years) use ($f) {
    $h = '<label for="year">' . bk_e(bk_t('OtYear')) . '</label>';
    if ($years) {
        $h .= '<select id="year" name="year" required><option value="">' . bk_e(bk_t('Choose')) . '</option>';
        foreach ($years as $y) $h .= '<option value="' . $y . '"' . (intval($f['year']) === $y ? ' selected' : '') . '>' . $y . '</option>';
        return $h . '</select><p class="bk-hint">' . bk_e(bk_t('OtYearWa')) . '</p>';
    }
    list($from, $to) = bk_other_year_range();
    return $h . '<input type="number" id="year" name="year" value="' . bk_e($f['year'] ?: '') . '" min="' . $from . '" max="' . $to . '" required>';
};
/** Password fields, privacy note and the button. */
$passwordFields = function () {
    return '<label for="password">' . bk_e(bk_t('OtPassword')) . '</label><input type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>'
        . '<label for="password2">' . bk_e(bk_t('OtPassword2')) . '</label><input type="password" id="password2" name="password2" autocomplete="new-password" minlength="8" required>'
        . '<p class="bk-hint">' . bk_e(bk_t('OtPrivacy')) . '</p>'
        . '<button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('OtCreateBtn')) . '</button>';
};
/** Second step fields of the typed and World Archery ways: year, club, licence (typed), password. */
$fields2 = function ($years, $withLicence) use ($f, $yearField, $passwordFields) {
    $h = $yearField($years)
        . '<label for="club">' . bk_e(bk_t('OtClub')) . '</label><input type="text" id="club" name="club" value="' . bk_e($f['club']) . '" maxlength="80" required>';
    if ($withLicence) {
        $h .= '<label for="licence">' . bk_e(bk_t('OtLicence')) . '</label><input type="text" id="licence" name="licence" value="'
            . bk_e($f['licence']) . '" maxlength="21" autocomplete="username" required><p class="bk-hint">' . bk_e(bk_t('OtLicenceHint')) . '</p>';
    }
    return $h . $passwordFields();
};

bk_head(bk_t('OtSignupTitle'), 'card');
$out = '<div class="bk-card bk-other"><h1>' . bk_e(bk_t('OtSignupTitle')) . '</h1>' . ($err !== '' ? bk_msg('err', $err) : '');

if ($stage === 'fed') {
    $row = bk_fed_find($pend['fed']['country'], $pend['fed']['code']);
    $login = bk_other_login_id($pend['fed']['country'], $pend['fed']['code']);
    $out .= '<p class="bk-sub">' . bk_e(bk_t('OtFedSub', $login)) . '</p>';
    if ($row && intval($row->BflStatus) === 9) $out .= bk_msg('warn', bk_t('OtFedArchive'));
    $out .= '<dl class="bk-dl bk-fed">';
    if ($row) {
        $out .= '<dt>' . bk_e(bk_t('OtFamily')) . '</dt><dd>' . bk_e(mb_strtoupper($row->BflFamilyName, 'UTF-8')) . '</dd>'
            . '<dt>' . bk_e(bk_t('OtGiven')) . '</dt><dd>' . bk_e($row->BflName) . '</dd>'
            . '<dt>' . bk_e(bk_t('OtSex')) . '</dt><dd>' . bk_e(bk_t(intval($row->BflSex) ? 'OtWoman' : 'OtMan')) . '</dd>'
            . ($row->BflBirth ? '<dt>' . bk_e(bk_t('OtYear')) . '</dt><dd>' . bk_e(mb_substr((string) $row->BflBirth, 0, 4)) . '</dd>' : '')
            . '<dt>' . bk_e(bk_t('OtClub')) . '</dt><dd>' . bk_e($row->BflClubName !== '' ? $row->BflClubName : '—') . '</dd>'
            . '<dt>' . bk_e(bk_t('OtCountry')) . '</dt><dd>' . bk_e($countries[$row->BflCountry] ?? $row->BflCountry) . '</dd>';
    }
    $out .= '</dl><form method="post" action="">' . bk_csrf_field() . '<input type="hidden" name="stage" value="fed">'
        . ($row && !$row->BflBirth ? $yearField(array()) : '') . $passwordFields() . '</form>';
} elseif ($stage === 'pick') {
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
    $out .= '<p class="bk-sub">' . bk_e(bk_t('OtWaChosen', array('name' => mb_strtoupper($c['family'], 'UTF-8') . ' ' . $c['given'],
            'id' => intval($c['id']), 'login' => bk_other_login_id('WA', $c['id'])))) . '</p>'
        . '<form method="post" action="">' . bk_csrf_field() . '<input type="hidden" name="stage" value="wa">'
        . $fields2(bk_other_birth_years($c['age']), false) . '</form>';
} elseif ($stage === 'own') {
    $out .= '<p class="bk-sub">' . bk_e(bk_t($pend && !$pend['cand'] ? 'OtOwnNoWa' : 'OtOwnSub', array(
            'name' => mb_strtoupper($pend['id']['family'] ?? '', 'UTF-8') . ' ' . ($pend['id']['given'] ?? ''),
            'country' => $pend['id']['country'] ?? ''))) . '</p>'
        . '<form method="post" action="">' . bk_csrf_field() . '<input type="hidden" name="stage" value="own">'
        . $fields2(array(), true) . '</form>';
} else {
    // Country first; its federation file decides between the licence and the names (script below;
    // without it both are shown and the country decides here).
    $opt = '<option value="">' . bk_e(bk_t('Choose')) . '</option>';
    foreach ($countries as $k => $v) {
        $fc = $fedc[$k] ?? null;
        $opt .= '<option value="' . bk_e($k) . '"' . ($f['country'] === $k ? ' selected' : '')
            . ($fc ? ' data-ex="' . bk_e($fc['ex']) . '"' : '')
            . '>' . bk_e($v) . '</option>';
    }
    $out .= '<p class="bk-sub">' . bk_e(bk_t('OtSignupSub')) . '</p><form method="post" action="" id="bk-ot-form">' . bk_csrf_field()
        . '<input type="hidden" name="stage" value="identity">'
        . '<label for="country">' . bk_e(bk_t('OtCountry')) . '</label><select id="country" name="country" required>' . $opt . '</select>'
        . '<div id="bk-ot-lic">'
        . '<label for="licence">' . bk_e(bk_t('OtFedLicence')) . '</label><input type="text" id="licence" name="licence" value="'
        . bk_e($f['licence']) . '" maxlength="21" autocomplete="off">'
        . '<p class="bk-hint" id="bk-ot-ex" data-tpl="' . bk_e(bk_t('OtFedLicenceHint', '§')) . '">' . bk_e(bk_t('OtFedLicenceAny')) . '</p></div>'
        . '<div id="bk-ot-names">'
        . '<label for="family">' . bk_e(bk_t('OtFamily')) . '</label><input type="text" id="family" name="family" value="' . bk_e($f['family']) . '" maxlength="60">'
        . '<label for="given">' . bk_e(bk_t('OtGiven')) . '</label><input type="text" id="given" name="given" value="' . bk_e($f['given']) . '" maxlength="30">'
        . '<label for="sex">' . bk_e(bk_t('OtSex')) . '</label><select id="sex" name="sex">'
        . '<option value="0"' . (!$f['sex'] ? ' selected' : '') . '>' . bk_e(bk_t('OtMan')) . '</option>'
        . '<option value="1"' . ($f['sex'] ? ' selected' : '') . '>' . bk_e(bk_t('OtWoman')) . '</option></select></div>'
        . '<button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('OtContinue')) . '</button></form>'
        . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('other.php')) . '">' . bk_e(bk_t('OtHaveAccount')) . '</a></p>';
    $out .= <<<'JS'
<script>
(function () {
    var sel = document.getElementById('country'), lic = document.getElementById('licence'),
        hint = document.getElementById('bk-ot-ex'), blocks = { lic: document.getElementById('bk-ot-lic'), names: document.getElementById('bk-ot-names') };
    function show() {
        var o = sel.options[sel.selectedIndex], ex = o ? o.getAttribute('data-ex') : null, fed = ex !== null;
        blocks.lic.style.display = fed ? '' : 'none';
        blocks.names.style.display = (fed || !sel.value) ? 'none' : '';
        lic.required = fed;
        if (fed) {
            lic.placeholder = ex;
            hint.textContent = hint.getAttribute('data-tpl').replace('§', ex);
        }
        ['family', 'given'].forEach(function (id) { document.getElementById(id).required = !fed && !!sel.value; });
    }
    lic.addEventListener('input', function () {
        var v = lic.value.toUpperCase().replace(/\s+/g, '');
        if (v !== lic.value) lic.value = v;
    });
    sel.addEventListener('change', show);
    show();
})();
</script>
JS;
}
if ($stage !== 'identity') {
    $out .= '<p class="bk-alt"><a href="' . bk_e(bk_public_url('other-signup.php')) . '">' . bk_e(bk_t('OtRestart')) . '</a></p>';
}
echo $out . '</div>';
bk_foot();
