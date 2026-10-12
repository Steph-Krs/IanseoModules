<?php
/**
 * public/live-hide.php — closes the banner of a competition taking place today, or the one of the
 * volunteer space (k=vol, t = the competitions it showed), until the archer signs in again (close
 * button of the banner, lib/ui.php bk_live_banner).
 *
 * Writes only to the PHP session of the signed-in archer, never to the database.
 */
require_once __DIR__ . '/boot.php';

if (!bk_current_archer() || !bk_csrf_check()) JsonOut(array('error' => 1, 'msg' => 'denied'));
bk_live_hide(explode(',', (string) ($_POST['t'] ?? '')), (string) ($_POST['k'] ?? '') === 'vol' ? 'vol' : 'ids');
JsonOut(array('error' => 0, 'msg' => ''));
