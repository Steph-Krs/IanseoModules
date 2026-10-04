<?php
/**
 * public/logout.php — signs the archer out of the licensee space, then back to the sign-in page.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/ffta.php';

if (bk_current_archer()) {
    bk_log('LOGOUT', bk_current_archer()->BaLicence);
    bk_ffta_espace_forget();   // destroys the kept licensee-space cookie (while the BK token still exists)
    bk_logout();
}
bk_redirect('login.php');
