<?php
/**
 * staff/api/logout.php — the volunteer signs out on this phone (POST, JSON): this phone's session
 * of the volunteer space AND of the licensee space, so that the volunteers' sign-in page that
 * follows lets nobody in by themselves (user's choice, 2026-10-12). The volunteer stays in the
 * team and may sign in again.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 3) . '/booking/lib/ffta.php';

shp_api_guard(true);

shp_staff_session_close();
$archer = bk_current_archer();
if ($archer && empty($archer->BK_IMP)) {
    // As the licensee space's own sign-out (booking/public/logout.php).
    bk_log('LOGOUT', $archer->BaLicence);
    bk_ffta_espace_forget();
    bk_logout();
}
shp_json(array('error' => 0, 'msg' => ''));
