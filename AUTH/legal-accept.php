<?php
/**
 * legal-accept.php — acceptance of the terms of use by a signed-in ORGANISER.
 *
 * BLOCKING screen: the bootstrap (aut_request_bootstrap) sends here every signed-in organiser
 * who has not accepted the current version of the terms. The acceptance is recorded
 * TIME-STAMPED (AuthUsers.AuCguAt) and VERSIONED (AuCguVer). This page is exempt from the guard
 * (aut_is_legal_script) to avoid any loop.
 *
 * The wording is the competitor's one (booking/public/legal-accept.php), read from the
 * "booking" section of the language files.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/legal-lib.php');

// Reserved to a signed-in organiser (the bootstrap checked the session).
if (empty($_SESSION['AUTH_User'])) {
    CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php'); die();
}
$user = (string) $_SESSION['AUTH_User'];

// Already accepted → back to the home page.
if (aut_legal_org_ok($user)) {
    $_SESSION['AUTH_CGU_OK'] = aut_legal_version();
    CD_redirect($CFG->ROOT_DIR); die();
}

$bk = function ($key, $a = null) { return aut_text($key, $a, 'booking'); };
$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!aut_csrf_check()) {
        $err = aut_t('LoginSessionExpired');
    } elseif (empty($_POST['accept'])) {
        $err = $bk('CguTickBox');
    } else {
        aut_legal_org_record($user);
        $_SESSION['AUTH_CGU_OK'] = aut_legal_version();
        aut_log('CGU_ACCEPT', $user);
        CD_redirect($CFG->ROOT_DIR); die();
    }
}

$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
$root = $CFG->ROOT_DIR;
echo '<!DOCTYPE html>
<html lang="' . $e(aut_lang_code()) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>' . $e($bk('CguTitle')) . '</title>
';
?>
<style>
* { box-sizing:border-box; }
body { margin:0; font-family:Verdana,Arial,sans-serif; background:#eef2f6; color:#20263d; line-height:1.5; }
.wrap { max-width:760px; margin:0 auto; padding:24px 16px 60px; }
h1 { font-size:22px; color:#01367c; margin:4px 0 6px; }
.lead { font-size:14px; color:#4c4e50; margin:0 0 16px; }
.err { background:#fde8e8; border:1px solid #e8b4b4; color:#8b1a1a; padding:9px 12px; border-radius:6px; font-size:13px; margin:0 0 12px; }
.box { background:#fff; border:1px solid #c9d4df; border-radius:10px; padding:6px 18px; max-height:46vh; overflow:auto;
    box-shadow:inset 0 -10px 12px -12px rgba(0,0,0,.15); overflow-wrap:anywhere; }
.box h2 { font-size:15px; color:#0254a8; margin:16px 0 5px; }
.box h2:first-child { margin-top:10px; }
.box p, .box li { font-size:13px; }
.box ul { padding-left:20px; }
.box a { color:#0254a8; }
.lg-note { margin:16px 0 0; padding:10px 12px; background:#fdf7e6; border:1px solid #eadfb8; border-radius:8px; }
.lg-note p { margin:0; font-size:12px; color:#6a5a2a; }
.lg-ver { color:#7d8183; font-size:12px; }
.links { margin:12px 0; font-size:13px; }
.links a { color:#0254a8; margin-right:14px; }
.accept { display:flex; align-items:flex-start; gap:10px; margin:14px 0; font-size:14px; }
.accept input { margin-top:3px; width:18px; height:18px; }
.row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
button { padding:11px 22px; background:#1a4f8b; color:#fff; border:0; border-radius:6px; font-size:15px; font-weight:600; cursor:pointer; }
button:hover { background:#14396b; }
.logout { color:#8a92a0; text-decoration:none; font-size:13px; }
.logout:hover { color:#5b6470; }
</style>
<?php
$link = function ($doc, $label) use ($e) {
    return '<a href="' . $e(aut_legal_url($doc)) . '" target="_blank" rel="noopener">' . $e($label) . "</a>\n";
};
echo "</head>\n<body>\n"
    . '<div class="wrap">' . "\n"
    . '<h1>' . $e($bk('CguTitle')) . "</h1>\n"
    . '<p class="lead">' . $e($bk('CguIntro')) . "</p>\n"
    . ($err ? '<div class="err">' . $e($err) . "</div>\n" : '')
    . '<div class="box">' . aut_legal_render('cgu') . "</div>\n"
    . '<p class="links">' . $e($bk('CguFullDocs')) . "\n"
    . $link('cgu', $bk('CguShort'))
    . $link('confidentialite', $bk('Privacy'))
    . $link('mentions', $bk('LegalNotice'))
    . $link('cookies', $bk('Cookies'))
    . "</p>\n"
    . '<form method="post">' . aut_csrf_field() . "\n"
    . '<label class="accept"><input type="checkbox" name="accept" value="1">'
    . '<span>' . $bk('CguAccept', $e(aut_legal_version())) . "</span></label>\n"
    . '<div class="row">'
    . '<button type="submit">' . $e($bk('CguAcceptBtn')) . '</button>'
    . '<a class="logout" href="' . $e($root) . 'Modules/Authentication/LogOut.php">' . $e($bk('CguRefuse')) . '</a>'
    . "</div>\n</form>\n</div>\n</body>\n</html>\n";
