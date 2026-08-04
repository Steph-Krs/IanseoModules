<?php
// admin/update.php — Mise à jour / désinstallation du module PRONO.
// Toute la logique est mutualisée dans _shared/update-ui.php.
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once dirname(__DIR__, 2) . '/_shared/update-ui.php';

// AclRoot seul ne suffit pas avec un module de comptes : upd_admin_guard()
// exige en plus la vue Administrateur serveur (AUTH_ROOT).
upd_admin_guard();

upd_render_common_page(dirname(__DIR__), [
    'h1'    => 'Pronostics — Mise à jour du module',
    'title' => 'Pronostics — Mise à jour',
    'back'  => ['url' => $CFG->ROOT_DIR . 'Modules/Custom/PRONO/index.php', 'label' => 'Retour à la console'],
    'after_update' => function () {
        return 'Pensez à relancer un recalcul depuis la console si le moteur de probabilités a changé.';
    },
]);
