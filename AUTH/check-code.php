<?php
/**
 * Module AUTH — vérification AJAX de disponibilité d'un code de compétition.
 * Utilisé par le contrôle en direct du formulaire de création/renommage
 * (injecté par menu.php). Lecture seule : n'enregistre aucune revendication.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/lib.php');
require_once('Common/Fun_FormatText.inc.php');

// même normalisation que le cœur (Tournament/index.php)
$code = preg_replace('/[^0-9a-z._-]+/sim', '_', $_REQUEST['code'] ?? '');

// admin / console locale / auth inactive : pas de restriction (le cœur écrase
// en connaissance de cause) — on signale quand même un code existant
if (!empty($_SESSION['AUTH_ROOT']) || aut_is_localhost() || empty($CFG->USERAUTH)) {
    $q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToCode=" . StrSafe_DB($code));
    $exists = (bool)safe_fetch($q);
    JsonOut(array('free' => !$exists, 'msg' => $exists
        ? 'Attention : ce code est déjà utilisé — en mode administrateur, enregistrer ÉCRASERA la compétition existante.'
        : ''));
}

if (empty($_SESSION['AUTH_User'])) {
    JsonOut(array('free' => false, 'msg' => 'Session expirée : reconnectez-vous.'));
}

// code inchangé (édition sans renommage) : rien à vérifier
if ($code !== '' && strcasecmp($code, $_SESSION['TourCode'] ?? '') === 0) {
    JsonOut(array('free' => true, 'msg' => ''));
}

$state = aut_code_status($code, $_SESSION['AUTH_ROLE'] ?? '', $_SESSION['AUTH_SCOPE'] ?? '');
JsonOut(array(
    'free' => $state == 'free',
    'msg'  => $state == 'free' ? '' : aut_code_reason($state, $code, false),
));
