<?php
/**
 * Déployé depuis Modules/Custom/AUTH/dist/ — déconnexion.
 */
if (basename(__DIR__) !== 'Authentication') {
    http_response_code(403);
    die('Ce fichier doit être exécuté depuis Modules/Authentication/.');
}
define('HTDOCS', dirname(__DIR__, 2));
require_once(HTDOCS . '/config.php');
require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/lib.php');

if (!empty($_SESSION['AUTH_User'])) {
    aut_log('LOGOUT', $_SESSION['AUTH_User']);
    aut_extranet_forget();    // détruit le cookie de session extranet
    aut_dirigeant_forget();   // et celui de l'Espace Dirigeant
    // révoque le jeton de cette session côté serveur
    $h = aut_current_token_hash();
    if ($h) safe_w_sql("DELETE FROM AUT_Sessions WHERE AsnTokenHash='$h'");
}
$_SESSION = array();
if (session_id()) session_destroy();

header('Location: ' . $CFG->ROOT_DIR . 'Modules/Authentication/LogIn.php');
exit;
