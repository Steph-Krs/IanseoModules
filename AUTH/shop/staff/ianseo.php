<?php
/**
 * staff/ianseo.php — the volunteer's way into ianseo's own screens, and back out (AUTH/vol-lib.php).
 *
 *   (no query)  the volunteer of this browser (cookie of the volunteer space) with groups of
 *               ianseo's screens usable now: opens their session of those screens in the PHP
 *               session (AUTH_User = vol:<SfId>, AUTH_Pwd = token), then the core opens their
 *               competition (Common/TourOn.php) and its home page;
 *   ?out=<t>    signs out: the ianseo session, the volunteer space and the licensee space of this
 *               browser (as the volunteer space's own sign-out), then the volunteers' sign-in page.
 *               <t> ties the link to the session (aut_vol_out_token): another site cannot use it;
 *   ?end=<why>  the ianseo session ended (sent here by the bootstrap): why, and the way back;
 *   ?denied=1   a screen the volunteer was not given (sent here by the bootstrap); =codes: sending
 *               the results before the organiser set the online codes (the core sends to their setup).
 *
 * $SKIP_AUTH (boot.php): reached whatever the state of the ianseo session.
 */

require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/posts.php';
require_once dirname(__DIR__, 2) . '/booking/lib/ffta.php';   // bk_ffta_espace_forget (sign-out)
require_once dirname(__DIR__, 2) . '/vol-lib.php';

$title = shp_t('IanTitle');
$main = $CFG->ROOT_DIR . 'Main.php';
$space = shp_url('staff/index.php');

/** A card with a message and buttons ([label, address, primary]), then the end of the request. */
function ia_page($title, $type, $msg, array $buttons, $http = 200)
{
    if ($http !== 200 && !headers_sent()) http_response_code(intval($http));
    shp_head($title, array('layout' => 'card'));
    $html = '  <main class="shp-main"><div class="shp-card"><h1>' . shp_e($title) . '</h1>' . shp_msg($type, $msg);
    foreach ($buttons as $b) {
        $html .= '<p><a class="shp-btn shp-btn-block' . (!empty($b[2]) ? ' shp-btn-primary' : '') . '" href="' . shp_e($b[1]) . '">'
            . shp_e($b[0]) . '</a></p>';
    }
    echo $html . shp_licensee_space_btn() . "</div></main>\n";
    shp_foot();
    exit;
}

/* ---- Sign-out ---- */
if (isset($_GET['out'])) {
    $tour = 0;
    if (aut_vol_session() && hash_equals(aut_vol_out_token(), (string) $_GET['out'])) {
        $tour = intval($_SESSION['AUTH_VOL']['tour'] ?? 0);
        shp_staff_core_close((string) ($_SESSION['AUTH_Pwd'] ?? ''));
        aut_vol_forget();
        unset($_SESSION['TourId'], $_SESSION['TourCode']);
        // As the volunteer space's own sign-out (staff/api/logout.php): nobody stays signed in here.
        $st = shp_staff_session_state();
        if (!$tour && $st['row']) $tour = intval($st['row']->SfTournament);
        shp_staff_session_close();
        $archer = bk_current_archer();
        if ($archer && empty($archer->BK_IMP)) {
            bk_log('LOGOUT', $archer->BaLicence);
            bk_ffta_espace_forget();
            bk_logout();
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    } elseif (aut_vol_session()) {
        header('Location: ' . $main);   // a link from elsewhere: nothing happens
        exit;
    }
    $set = $tour > 0 ? shp_settings($tour) : null;
    header('Location: ' . ($set ? shp_url('staff/login.php?k=' . rawurlencode((string) $set->SgPublicKey)) : $space));
    exit;
}

/* ---- The session ended, a screen refused ---- */
if (isset($_GET['end'])) {
    $why = (string) $_GET['end'];
    $msg = $why === 'deploy' ? shp_t('IanErrDeploy') : shp_staff_reason_msg($why);
    ia_page($title, in_array($why, array('ianseo_off', 'deploy'), true) ? 'info' : 'warn', $msg,
        array(array(shp_t('ShStfSpaceTitle'), $space, true)));
}
if (isset($_GET['denied'])) {
    ia_page($title, 'warn', shp_t((string) $_GET['denied'] === 'codes' ? 'IanErrCodes' : 'IanErrDenied'), array(array(shp_t('IanBackMain'), $main, true), array(shp_t('ShStfSpaceTitle'), $space)));
}

/* ---- The way in ---- */
if (shp_impersonating()) shp_page_message($title, shp_t('ShErrReadOnly'));
$st = shp_staff_session_state();
if (!$st['staff']) {
    $tour = $st['row'] ? intval($st['row']->SfTournament) : 0;
    shp_staff_denied_page($st['reason'], $tour, in_array($st['reason'], array('signed_out', 'expired'), true) ? 401 : 403);
}
$me = $st['staff'];
$tour = intval($me->SfTournament);
if (!aut_vol_available()) ia_page($title, 'info', shp_t('IanErrDeploy'), array(array(shp_t('ShStfSpaceTitle'), $space, true)));
if (!shp_ianseo_rights($me)) {
    $off = !shp_ianseo_on($tour) || $me->SfKind === 'ORGANISER';
    ia_page($title, 'info', shp_t($off ? 'IanErrOff' : 'ShStfErrForbidden'), array(array(shp_t('ShStfSpaceTitle'), $space, true)));
}
// An organiser signed in on this browser would lose their session: they sign out first.
if (!empty($_SESSION['AUTH_User']) && !aut_vol_session()) {
    ia_page($title, 'warn', shp_t('IanErrOrganiser'), array(array(shp_t('ShStfSpaceTitle'), $space, true)));
}

$open = $CFG->ROOT_DIR . 'Common/TourOn.php?ToId=' . $tour;
$mine = AUT_VOL_PREFIX . intval($me->SfId);
if (($_SESSION['AUTH_User'] ?? '') === $mine) {
    $cs = shp_staff_core_state(intval($me->SfId), (string) ($_SESSION['AUTH_Pwd'] ?? ''));
    if ($cs['staff']) {
        header('Location: ' . (intval($_SESSION['TourId'] ?? 0) === $tour ? $main : $open));
        exit;
    }
}
if (aut_vol_session()) {
    // Another volunteer, or an ended session, on this browser: closed before opening this one.
    shp_staff_core_close((string) ($_SESSION['AUTH_Pwd'] ?? ''));
    aut_vol_forget();
}
if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
$_SESSION['AUTH_User'] = $mine;
$_SESSION['AUTH_Pwd'] = shp_staff_core_open(intval($me->SfId));
unset($_SESSION['AUTH_VOL'], $_SESSION['AUTH_VOL_BLK']);
header('Location: ' . $open);
exit;
