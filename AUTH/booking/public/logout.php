<?php
require_once __DIR__ . '/boot.php';

if (bk_current_archer()) {
    bk_log('LOGOUT', bk_current_archer()->BaLicence);
    bk_logout();
}
bk_redirect('login.php');
