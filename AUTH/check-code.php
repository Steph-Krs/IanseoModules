<?php
/**
 * AUTH module — AJAX check that a competition code is available.
 * Used by the live check of the creation/renaming form (injected by menu.php). Read only:
 * records no claim.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/lib.php');
require_once('Common/Fun_FormatText.inc.php');

// same normalisation as the core (Tournament/index.php)
$code = preg_replace('/[^0-9a-z._-]+/sim', '_', $_REQUEST['code'] ?? '');

// admin / local console / authentication off: no restriction (the core overwrites knowingly)
// — an existing code is still reported
if (!empty($_SESSION['AUTH_ROOT']) || aut_is_localhost() || empty($CFG->USERAUTH)) {
    $q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToCode=" . StrSafe_DB($code));
    $exists = (bool)safe_fetch($q);
    JsonOut(array('free' => !$exists, 'msg' => $exists
        ? aut_t('CodeAdminOverwrite')
        : ''));
}

if (empty($_SESSION['AUTH_User'])) {
    JsonOut(array('free' => false, 'msg' => aut_t('CodeSessionExpired')));
}

// unchanged code (edited without renaming): nothing to check
if ($code !== '' && strcasecmp($code, $_SESSION['TourCode'] ?? '') === 0) {
    JsonOut(array('free' => true, 'msg' => ''));
}

$state = aut_code_status($code, $_SESSION['AUTH_ROLE'] ?? '', $_SESSION['AUTH_SCOPE'] ?? '');
JsonOut(array(
    'free' => $state == 'free',
    'msg'  => $state == 'free' ? '' : aut_code_reason($state, $code, false),
));
