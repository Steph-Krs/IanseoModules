<?php
/**
 * Déployé depuis Modules/Custom/AUTH/dist/ — ne pas modifier ici, modifier la
 * copie source dans le module puis redéployer (admin/deploy.php).
 *
 * Inclus par config.php sur toutes les pages quand $CFG->USERAUTH est actif.
 */
if (!isset($CFG)) die();

require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/lib.php');
aut_request_bootstrap();
