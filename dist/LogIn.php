<?php
/**
 * Déployé depuis Modules/Custom/AUTH/dist/ — ALIAS de compatibilité.
 *
 * La connexion est désormais UNIFIÉE : Modules/Custom/AUTH/login.php (onglet
 * Organisateur = Espace Dirigeant, onglet Compétiteur = Espace Licencié). Le
 * flux organisateur lui-même vit dans lib.php (aut_handle_org_login/…),
 * réutilisé par la page unifiée. Ce fichier ne fait plus que rediriger, pour
 * les anciens liens et les redirections du cœur ianseo.
 */
if (basename(__DIR__) !== 'Authentication') {
    http_response_code(403);
    die('Ce fichier doit être exécuté depuis Modules/Authentication/ (voir admin/deploy.php).');
}
define('HTDOCS', dirname(__DIR__, 2));
require_once(HTDOCS . '/config.php');

header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php?p=org');
exit;
