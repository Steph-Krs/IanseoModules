<?php
/**
 * staff/api/logout.php — the volunteer signs out on this phone (POST, JSON): this phone's session
 * of the volunteer space AND of the licensee space, so that the volunteers' sign-in page that
 * follows lets nobody in by themselves (user's choice, 2026-10-12). The volunteer stays in the
 * team and may sign in again.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 3) . '/booking/lib/ffta.php';

shp_api_guard(true, true);

shp_staff_session_close();
// The same volunteer's session of ianseo's screens in this browser (AUTH/vol-lib.php) ends too.
require_once dirname(__DIR__, 3) . '/vol-lib.php';
if (aut_vol_session()) {
    shp_staff_core_close((string) ($_SESSION['AUTH_Pwd'] ?? ''));
    aut_vol_forget();
    unset($_SESSION['TourId'], $_SESSION['TourCode']);
}
$archer = bk_current_archer();
if ($archer && empty($archer->BK_IMP)) {
    // As the licensee space's own sign-out (booking/public/logout.php).
    bk_log('LOGOUT', $archer->BaLicence);
    bk_ffta_espace_forget();
    bk_logout();
}
shp_json(array('error' => 0, 'msg' => ''));
