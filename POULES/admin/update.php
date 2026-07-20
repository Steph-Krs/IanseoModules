<?php
// admin/update.php — Mise à jour / désinstallation du module POULES.
// Toute la logique est mutualisée dans _shared/update-ui.php.
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once dirname(__DIR__, 2) . '/_shared/update-ui.php';

// AclRoot seul ne suffit pas avec un module de comptes : upd_admin_guard()
// exige en plus la vue Administrateur serveur (AUTH_ROOT).
upd_admin_guard();

upd_render_common_page(dirname(__DIR__), [
    'h1'    => 'Poules — Mise à jour du module',
    'title' => 'Poules — Mise à jour',
    'back'  => ['url' => $CFG->ROOT_DIR . 'Modules/Custom/POULES/index.php', 'label' => 'Retour à la vue commentateur'],
]);
