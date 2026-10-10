<?php
/**
 * UNIFIED sign-in page (shared server) — single entry point, handled ENTIRELY here (no
 * delegation): both flows live in this page.
 *
 *   • Organiser  → FFTA officers' space (dirigeant.ffta.fr) — handlers
 *     aut_handle_org_login()/aut_handle_org_totp() of lib.php (single source, MFA/2FA/extranet/
 *     officers' cookie included).
 *   • Competitor → FFTA licensee space (monespace.ffta.fr) — functions
 *     bk_ffta_login()/bk_provision_archer()… of the online registration.
 *
 * $SKIP_AUTH: reachable anonymously even when the organiser authentication is on.
 * No password is stored nor logged (both relays erase it).
 */
$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/lib.php');
require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/legal-lib.php');

$root = $CFG->ROOT_DIR;
aut_ensure_schema();

/* ---- Modules present ---- */
$hasOrganiser = !empty($CFG->USERAUTH);
$hasCompetitor = is_file($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/archer.php');
$compEnabled = true;
if ($hasCompetitor) {
    require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/schema.php');
    require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/archer.php');
    require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/ffta.php');
    require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/totp.php');   // licensee 2FA (optional)
    bk_schema();
    $compEnabled = bk_ffta_enabled();
    if (bk_current_archer()) { CD_redirect($root . 'Modules/Custom/AUTH/booking/public/' . bk_next_after_login()); die(); }
}

$errO = $errC = '';
$stage = 'password';        // organiser: 'password' or 'totp'
$stageC = 'password';       // competitor: 'password' or 'totp' (licensee's local 2FA)
$needOtpC = false;          // competitor: MFA code asked
// COMPETITOR tab by default (the vast majority of visitors are licensees). The organiser
// stays reachable through ?p=org or the tab.
$active = ($_GET['p'] ?? '') === 'org' ? 'org' : 'comp';
if (!$hasCompetitor && $hasOrganiser) $active = 'org';   // fallback when the competitor side is missing
if (!$hasOrganiser && $hasCompetitor) $active = 'comp';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Audience measurement of the home page (anonymous visitor → exempt measurement cookie;
// self-guarded on page GETs, isolated, never fatal). Must come before any HTML output.
require_once(__DIR__ . '/stats-usage.php');
if (function_exists('aut_track')) aut_track('public');

/* ---- Organiser POST ---- */
if ($method === 'POST' && ($_POST['role'] ?? '') === 'org' && $hasOrganiser) {
    $active = 'org';
    if (!aut_csrf_check()) {
        $errO = aut_t('LoginSessionExpired');
    } elseif (($_POST['stage'] ?? '') === 'totp') {
        $stage = 'totp';
        aut_handle_org_totp($errO, $stage);      // success → redirects and ends
    } else {
        aut_handle_org_login($errO, $stage);     // success → redirects and ends
    }
}

/* ---- Competitor POST ---- */
if ($method === 'POST' && ($_POST['role'] ?? '') === 'comp' && $hasCompetitor
        && ($_POST['stage'] ?? '') === 'totp') {
    /* Step 2: the licensee's local 2FA code. The FFTA credentials were already checked at
       step 1; only the server's TOTP code is left to check, taking back the FFTA cookies put
       on hold (the FFTA MFA code being single-use, the federation sign-in is not run again). */
    $active = 'comp';
    $stageC = 'totp';
    $pend = $_SESSION['BK_2FA'] ?? null;
    if (!aut_csrf_check()) {
        $errC = aut_t('LoginSessionExpired');
    } elseif (!is_array($pend) || (time() - intval($pend['time'] ?? 0)) > 300) {
        unset($_SESSION['BK_2FA']);
        $stageC = 'password';
        $errC = aut_t('LoginCompExpired');
    } elseif (bk_too_many(array('LOGIN_FAIL', 'TOTP_FAIL'), BK_MAX_LOGIN_FAIL, (string) $pend['licence'])) {
        bk_log('LOGIN_BLOCK', (string) $pend['licence']);
        $errC = aut_t('LoginCompTooMany');
    } else {
        $a = bk_get_archer(intval($pend['archer']));
        $usedSlot = 0;
        $code = (string) ($_POST['code'] ?? '');
        if ($a && $a->BaActive && $a->BaTotpEnabled
                && bk_totp_verify($a->BaTotpSecret, $code, intval($a->BaTotpLastSlot), $usedSlot)) {
            safe_w_sql("UPDATE BookingArchers SET BaTotpLastSlot=$usedSlot WHERE BaId={$a->BaId}");
            unset($_SESSION['BK_2FA']);
            session_regenerate_id(true);
            bk_session_open($a);
            bk_ffta_espace_store((string) $pend['cookies'], (string) $pend['exalto'], $a->BaId);
            bk_log('LOGIN_OK', $a->BaLicence);
            CD_redirect($root . 'Modules/Custom/AUTH/booking/public/' . bk_next_after_login());
            die();
        }
        // Failure: server clock off (a diagnosis, never an acceptance) or a wrong code.
        $skew = ($a && $a->BaTotpEnabled) ? bk_totp_skew($a->BaTotpSecret, $code) : null;
        if ($skew !== null) {
            bk_log('TOTP_SKEW', (string) $pend['licence']);
            $errC = aut_t('LoginCompSkew', round(abs($skew) / 60));
        } else {
            bk_log('TOTP_FAIL', (string) $pend['licence']);
            $errC = aut_t('TotpBad');
        }
    }
} elseif ($method === 'POST' && ($_POST['role'] ?? '') === 'comp' && $hasCompetitor) {
    $active = 'comp';
    $ident = trim((string) ($_POST['identifiant'] ?? ''));
    $pwd   = (string) ($_POST['password'] ?? '');
    $otp   = trim((string) ($_POST['otp'] ?? ''));

    if (!aut_csrf_check()) {
        $errC = aut_t('LoginSessionExpired');
    } elseif (!$compEnabled) {
        $errC = aut_t('LoginCompOff');
    } elseif ($ident === '' || $pwd === '') {
        $errC = aut_t('LoginCompRequired');
    } elseif (bk_too_many(array('LOGIN_FAIL'), BK_MAX_LOGIN_FAIL, $ident)) {
        bk_log('LOGIN_BLOCK', $ident);
        $errC = aut_t('LoginCompTooMany');
    } else {
        $res = bk_ffta_login($ident, $pwd, $otp);
        $pwd = null;                              // the password does not outlive the call
        if (!empty($res['ok'])) {
            aut_ffta_outage_clear('licencie');    // the space answers: the banner is no longer needed
            $licence = $res['licence'];           // comes from the FFTA, never from the entry
            $lue = bk_lookup_licence($licence);
            if (!$lue) {
                bk_log('LICENCE_UNKNOWN', $licence);
                $errC = aut_t('LoginCompUnknown');
            } elseif (!bk_ffta_name_matches($res['displayName'] ?? '', $lue)) {
                bk_log('NAME_MISMATCH', $licence);
                $errC = aut_t('LoginCompMismatch');
            } else {
                $id = bk_provision_archer($lue);
                $a = $id ? bk_get_archer($id) : null;
                if ($a && $a->BaActive && !empty($a->BaTotpEnabled)) {
                    // Local 2FA on: the FFTA cookies (already captured) are put on hold and the
                    // code is asked before the session opens. See the 'totp' branch.
                    $_SESSION['BK_2FA'] = array(
                        'archer'  => intval($a->BaId),
                        'licence' => $licence,
                        'cookies' => (string) ($res['cookies'] ?? ''),
                        'exalto'  => (string) ($res['exaltoId'] ?? ''),
                        'time'    => time(),
                    );
                    $stageC = 'totp';
                } elseif ($a && $a->BaActive) {
                    session_regenerate_id(true);
                    bk_session_open($a);
                    // Keeps the monespace session cookie + the Exalto id (licence certificate).
                    bk_ffta_espace_store($res['cookies'] ?? '', $res['exaltoId'] ?? '', $a->BaId);
                    bk_log('LOGIN_OK', $licence);
                    CD_redirect($root . 'Modules/Custom/AUTH/booking/public/' . bk_next_after_login());
                    die();
                } else {
                    $errC = aut_t($a ? 'LoginCompDisabled' : 'LoginCompCreateFail');
                    if ($a) bk_log('LOGIN_DISABLED', $licence);
                }
            }
        } elseif (($res['err'] ?? '') === 'MFA_NEEDED') {
            $needOtpC = true;                     // not a credentials failure: not counted
            $errC = $res['msg'];
        } else {
            if (($res['err'] ?? '') === 'MFA_BAD_CODE') $needOtpC = true;
            // FFTA maintenance: nothing could be checked → the next visitors are warned
            // BEFORE they type their credentials.
            if (in_array($res['err'] ?? '', array('UNAVAILABLE', 'NETWORK'), true)) {
                aut_ffta_outage_note('licencie', (string) ($res['msg'] ?? ''));
            }
            // network / FFTA page / licence reading: not a fraudulent attempt → not counted
            if (!in_array($res['err'] ?? '', array('NETWORK', 'UNAVAILABLE', 'NO_CSRF', 'NO_LICENCE', 'AMBIGUOUS_LICENCE'), true)) {
                bk_log('LOGIN_FAIL', $ident);
            } else {
                bk_log('READ_' . ($res['err'] ?? 'ERR'), $ident);
            }
            $errC = $res['msg'];
        }
    }
}

$csrf = aut_csrf_field();
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };

/* "The FFTA was unavailable a moment ago" banner: the first visitor suffers the failure, the
   next ones are warned BEFORE typing their credentials (and believing they have the wrong
   password). Memo erased as soon as a sign-in to the same space succeeds. */
$warnBox = function ($o) use ($e) {
    $min = max(1, (int) round((time() - intval($o['at'] ?? 0)) / 60));
    return '<div class="warn">⚠ ' . $e($o['msg'] ?? '')
         . ' <span class="when">(' . $e(aut_t('LoginSeenAgo', $min)) . ')</span></div>';
};
$err = function ($msg) use ($e) {
    return $msg !== '' ? '<div class="err">' . $e($msg) . "</div>\n" : '';
};

/* Second step of a 2FA (server code): $role 'org' (administrator) or 'comp' (licensee). */
$totpForm = function ($role, $sub, $msg) use ($e, $err, $csrf) {
    $id = $role === 'org' ? 't-code' : 'c-code';
    return '<div class="sub">' . $e($sub) . "</div>\n"
        . $err($msg)
        . '<form method="post" action="login.php">' . $csrf
        . '<input type="hidden" name="role" value="' . $role . '">'
        . '<input type="hidden" name="stage" value="totp">'
        . '<label for="' . $id . '">' . $e(aut_t('LoginAppCode')) . '</label>'
        . '<input type="text" id="' . $id . '" name="code" inputmode="numeric" pattern="[0-9]{6}"'
        . ' maxlength="6" autocomplete="one-time-code" autofocus>'
        . '<button type="submit">' . $e(aut_t('LoginValidate')) . '</button>'
        . "</form>\n"
        . '<div class="foot"><a href="login.php">' . $e(aut_t('LoginCancel')) . "</a></div>\n";
};

/* Sign-in form of one space. */
$signForm = function ($role, $userField, $userLabel, $host, $value, $otpFocus) use ($e, $csrf) {
    $p = $role === 'org' ? 'o' : 'c';
    return '<form method="post" action="login.php">' . $csrf
        . '<input type="hidden" name="role" value="' . $role . '">'
        . ($role === 'org' ? '<input type="hidden" name="stage" value="password">' : '')
        . '<label for="' . $p . '-user">' . $e($userLabel) . '</label>'
        . '<input type="text" id="' . $p . '-user" name="' . $userField . '" autocomplete="username" value="' . $e($value) . '">'
        . '<label for="' . $p . '-pwd">' . $e(aut_t('LoginPassword')) . '</label>'
        . '<input type="password" id="' . $p . '-pwd" name="password" autocomplete="current-password">'
        . '<label for="' . $p . '-otp">' . $e(aut_t('LoginOtp'))
        . ' <span class="optional">(' . $e(aut_t('LoginOtpHint')) . ')</span></label>'
        . '<input type="text" id="' . $p . '-otp" name="otp" inputmode="numeric"'
        . ($role === 'org' ? ' maxlength="8"' : '') . ' autocomplete="one-time-code"' . ($otpFocus ? ' autofocus' : '') . '>'
        . '<button type="submit">' . $e(aut_t('LoginSubmit')) . "</button>\n"
        . "</form>\n"
        . '<div class="foot">' . $e(aut_t('LoginCheckedBy', $host)) . '<br>'
        . '<a href="https://' . $host . '/retrouver-mes-identifiants" target="_blank" rel="noopener noreferrer">'
        . $e(aut_t('LoginForgotten')) . "</a></div>\n";
};

/* Argument of one tab: a title, four points (icon + text with simple markup), a closing line. */
$pitch = function ($role, $title, $points, $closing) use ($e, $active) {
    $out = '<div class="pitch' . ($active === $role ? ' active' : '') . '" id="pitch-' . $role . '">'
         . '<h2>' . $e($title) . "</h2>\n<ul>\n";
    foreach ($points as $icon => $key) {
        $out .= '<li><span class="pi">' . $icon . '</span><span>' . aut_t($key) . "</span></li>\n";
    }
    return $out . "</ul>\n" . '<p class="pt">' . $e($closing) . "</p>\n</div>\n";
};

echo '<!DOCTYPE html>
<html lang="' . $e(aut_lang_code()) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>' . $e(aut_t('LoginTitle')) . '</title>
';
?>
<style>
* { box-sizing:border-box; }
body { margin:0; font-family:Verdana,Arial,sans-serif; color:#20263d;
       background:linear-gradient(160deg,#eaf1fb 0%,#eef2f6 55%,#e7eef6 100%);
       min-height:100vh; display:flex; flex-direction:column; }
.page { flex:1; display:flex; align-items:center; justify-content:center; padding:28px 14px; }
.landing { display:flex; flex-direction:row-reverse; align-items:center; justify-content:center;
    gap:34px; flex-wrap:wrap; width:100%; max-width:940px; }

/* Sign-in column (card) */
.auth-col { flex:0 0 auto; }
.card { background:#fff; border:1px solid #c9d4df; border-radius:10px; padding:26px 30px;
        box-shadow:0 8px 26px rgba(20,60,120,.12); width:372px; max-width:100%; }
@media (max-width:420px){ .card { padding:22px 18px; } }
h1 { font-size:19px; margin:0 0 2px; color:#1a4f8b; }
.sub { font-size:11px; color:#667; margin-bottom:14px; }
.relay-note { font-size:11px; color:#7a6a3a; background:#fdf7e6; border:1px solid #eadfb8;
    border-radius:6px; padding:7px 9px; margin:0 0 14px; line-height:1.45; }
.tabs { display:flex; gap:8px; margin-bottom:16px; }
.tab { flex:1; text-align:center; padding:9px 6px; border:1px solid #c9d4df; border-radius:6px;
       background:#f4f7fa; color:#334; font-size:13px; cursor:pointer; text-decoration:none; }
.tab .ic { display:block; font-size:20px; margin-bottom:2px; }
.tab.active { background:#1a4f8b; color:#fff; border-color:#1a4f8b; }
label { display:block; font-size:12px; margin:12px 0 4px; color:#334; }
input[type=text], input[type=password] { width:100%; padding:8px;
        border:1px solid #b6c2cf; border-radius:4px; font-size:14px; }
button { margin-top:18px; width:100%; padding:9px; background:#1a4f8b; color:#fff;
        border:0; border-radius:4px; font-size:14px; cursor:pointer; }
button:hover { background:#14396b; }
button[disabled] { opacity:.9; cursor:progress; }
.optional { font-size:10px; color:#889; }
.err  { background:#fde8e8; border:1px solid #e8b4b4; color:#8b1a1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.warn { background:#fdf4e3; border:1px solid #e8cf9a; color:#7a5b12; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; line-height:1.45; }
.warn .when { color:#9a8154; }
.foot { margin-top:14px; font-size:11px; text-align:center; color:#667; }
.foot a { color:#1a4f8b; }
.pane { display:none; } .pane.active { display:block; }
.other-q { margin:18px 0 8px; font-size:12px; color:#556; text-align:center; }
.logo-btn { display:flex; align-items:center; justify-content:center; gap:6px; padding:4px 10px;
    width:fit-content; max-width:100%; box-sizing:border-box; margin:0 auto;
    border:1px solid #c9d4df; border-radius:4px; background:#fff; color:#1a4f8b; font-size:10px;
    text-decoration:none; }
.logo-btn:hover { background:#f4f7fa; border-color:#1a4f8b; }
.logo-btn img { height:16px; width:auto; }

/* Argument column (landing) */
.pitch-col { flex:1 1 320px; max-width:440px; }
.pitch-brand { font-size:13px; color:#1a4f8b; font-weight:700; letter-spacing:.3px; margin:0 0 4px; }
.pitch { display:none; }
.pitch.active { display:block; }
.pitch h2 { font-size:23px; color:#01367c; margin:0 0 14px; line-height:1.25; }
.pitch ul { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:11px; }
.pitch li { display:flex; gap:11px; font-size:14px; line-height:1.45; color:#33404f; }
.pitch li .pi { flex:0 0 auto; font-size:18px; line-height:1.2; }
.pitch .pt { margin:16px 0 0; font-size:12.5px; color:#5b6470; }

/* Legal footer */
.site-foot { text-align:center; padding:16px 14px 26px; font-size:11px; color:#8a92a0; line-height:1.7; }
.site-foot .legal a { color:#1a4f8b; text-decoration:none; margin:0 7px; white-space:nowrap; }
.site-foot .legal a:hover { text-decoration:underline; }
.site-foot .credits { max-width:560px; margin:8px auto 0; }
.site-foot .credits b { color:#66707e; font-weight:600; }
.site-foot .credits a { color:#1a4f8b; text-decoration:none; }

#dots { display:inline-flex; gap:5px; vertical-align:middle; margin-left:7px; }
#dots i { width:7px; height:7px; border-radius:50%; background:#fff; opacity:.5;
    animation:bnc 1s ease-in-out infinite; }
#dots i:nth-child(2){ animation-delay:.16s; } #dots i:nth-child(3){ animation-delay:.32s; }
@keyframes bnc { 0%,80%,100%{ transform:translateY(0); opacity:.5; } 40%{ transform:translateY(-5px); opacity:1; } }
@media (max-width:760px){
    .landing { flex-direction:column; gap:22px; align-items:center; }
    /* .card has a fixed width (372px) which, through flex:0 0 auto, went past the phone
       screen. The columns go back to a capped 100% → no overflow, clean centring. */
    .auth-col { width:100%; max-width:372px; }
    .card { width:100%; }
    .pitch-col { width:100%; max-width:440px; flex-basis:auto; }
    .pitch h2 { font-size:20px; }
}
</style>
<?php
echo "</head>\n<body>\n"
    . '<div class="page"><div class="landing"><div class="auth-col"><div class="card">' . "\n"
    . '<h1>' . $e(aut_t('LoginHeading')) . "</h1>\n";

if ($stage === 'totp') {
    // server 2FA step (administrator account)
    echo $totpForm('org', aut_t('LoginTotpAdmin'), $errO);
} elseif ($stageC === 'totp') {
    // licensee's local 2FA step
    echo $totpForm('comp', aut_t('LoginTotpComp'), $errC);
} else {
    echo '<div class="sub">' . $e(aut_t('LoginIntro')) . "</div>\n"
        . '<p class="relay-note">' . $e(aut_t('LoginRelayNote')) . "</p>\n";

    if ($hasOrganiser && $hasCompetitor) {
        echo '<div class="tabs">'
            . '<a class="tab' . ($active === 'org' ? ' active' : '') . '" data-pane="org" href="?p=org"><span class="ic">🏹</span>' . $e(aut_t('LoginTabOrg')) . '</a>'
            . '<a class="tab' . ($active === 'comp' ? ' active' : '') . '" data-pane="comp" href="?p=comp"><span class="ic">🎯</span>' . $e(aut_t('LoginTabComp')) . '</a>'
            . "</div>\n";
    }

    if ($hasOrganiser) {
        $o = $errO === '' ? aut_ffta_outage_recent('dirigeant') : null;
        echo '<div class="pane' . ($active === 'org' ? ' active' : '') . '" id="pane-org">'
            . '<div class="sub">' . aut_t('LoginOrgSub') . "</div>\n"
            . ($errO !== '' ? $err($errO) : ($o ? $warnBox($o) : ''))
            . $signForm('org', 'username', aut_t('LoginOrgUser'), 'dirigeant.ffta.fr',
                        $active === 'org' ? ($_POST['username'] ?? '') : '', false)
            . "</div>\n";
    }

    if ($hasCompetitor) {
        echo '<div class="pane' . ($active === 'comp' ? ' active' : '') . '" id="pane-comp">'
            . '<div class="sub">' . aut_t('LoginCompSub') . "</div>\n";
        if (!$compEnabled) {
            echo $err(aut_t('LoginCompOff'));
        } else {
            $o = $errC === '' ? aut_ffta_outage_recent('licencie') : null;
            echo ($errC !== '' ? $err($errC) : ($o ? $warnBox($o) : ''))
                . $signForm('comp', 'identifiant', aut_t('LoginCompUser'), 'monespace.ffta.fr',
                            $active === 'comp' ? ($_POST['identifiant'] ?? '') : '', $needOtpC);
        }
        // Archers of another federation: their own account (booking/public/other.php).
        if (is_file(__DIR__ . '/booking/public/other.php')) {
            echo '<div class="other-q">' . $e(aut_t('LoginOtherText')) . '</div><a class="logo-btn" href="'
                . $e($root . 'Modules/Custom/AUTH/booking/public/other.php') . '"><img src="'
                . $e($root . 'Modules/Custom/AUTH/booking/public/assets/logo-wa.png') . '" alt="">'
                . $e(aut_t('LoginOtherLink')) . "</a>\n";
        }
        echo "</div>\n";
    }
}
echo "</div></div>\n";   // card, auth-col

echo '<div class="pitch-col">' . "\n"
    . '<p class="pitch-brand">' . $e(aut_t('LoginBrand')) . "</p>\n";
if ($hasOrganiser) {
    echo $pitch('org', aut_t('LoginPitchOrgTitle'), array(
        '🗂️' => 'LoginPitchOrg1', '🚀' => 'LoginPitchOrg2', '🔄' => 'LoginPitchOrg3', '🛡️' => 'LoginPitchOrg4',
    ), aut_t('LoginPitchOrgEnd'));
}
if ($hasCompetitor) {
    echo $pitch('comp', aut_t('LoginPitchCompTitle'), array(
        '🎯' => 'LoginPitchComp1', '🗺️' => 'LoginPitchComp2', '📄' => 'LoginPitchComp3', '📊' => 'LoginPitchComp4',
    ), aut_t('LoginPitchCompEnd'));
}
echo "</div>\n</div></div>\n";   // pitch-col, landing, page

echo '<footer class="site-foot"><div class="legal">'
    . '<a href="' . $e(aut_legal_url('mentions')) . '">' . $e(aut_t('LegalNotice')) . '</a> · '
    . '<a href="' . $e(aut_legal_url('cgu')) . '">' . $e(aut_t('LegalTerms')) . '</a> · '
    . '<a href="' . $e(aut_legal_url('confidentialite')) . '">' . $e(aut_t('LegalPrivacy')) . '</a> · '
    . '<a href="' . $e(aut_legal_url('cookies')) . '">' . $e(aut_t('LegalCookies')) . '</a>'
    . "</div>\n"
    . '<div class="credits">' . aut_t('LoginCredits') . "</div>\n"
    . "</footer>\n"
    . '<script>var AUT_LOGIN_BUSY = ' . json_encode(aut_t('LoginBusy')) . ";</script>\n";
?>
<script>
document.querySelectorAll('.tab').forEach(function (t) {
    t.addEventListener('click', function (ev) {
        ev.preventDefault();
        var pane = t.getAttribute('data-pane');
        document.querySelectorAll('.tab').forEach(function (x) { x.classList.toggle('active', x === t); });
        document.querySelectorAll('.pane').forEach(function (p) { p.classList.toggle('active', p.id === 'pane-' + pane); });
        document.querySelectorAll('.pitch').forEach(function (p) { p.classList.toggle('active', p.id === 'pitch-' + pane); });
        history.replaceState(null, '', '?p=' + pane);
        var inp = document.querySelector('#pane-' + pane + ' input'); if (inp) inp.focus();
    });
});
document.querySelectorAll('.pane form, form[action="login.php"]').forEach(function (f) {
    f.addEventListener('submit', function () {
        var b = f.querySelector('button[type=submit]');
        if (b) {
            b.disabled = true;
            b.textContent = AUT_LOGIN_BUSY + ' ';
            var dots = document.createElement('span');
            dots.id = 'dots';
            dots.innerHTML = '<i></i><i></i><i></i>';
            b.appendChild(dots);
        }
    });
});
</script>
</body>
</html>
