<?php
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/ffta.php';

if (bk_current_archer()) {
    bk_log('LOGOUT', bk_current_archer()->BaLicence);
    bk_ffta_espace_forget();   // détruit le cookie monespace conservé (tant que le jeton BK existe)
    bk_logout();
}
bk_redirect('login.php');
