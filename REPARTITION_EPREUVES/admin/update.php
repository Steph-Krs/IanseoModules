<?php
// admin/update.php — Mise à jour / désinstallation du module REPARTITION_EPREUVES.
// Toute la logique est mutualisée dans _shared/update-ui.php.
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once dirname(__DIR__, 2) . '/_shared/update-ui.php';

// AclRoot seul ne suffit pas avec un module de comptes : upd_admin_guard()
// exige en plus la vue Administrateur serveur (AUTH_ROOT).
upd_admin_guard();

upd_render_common_page(dirname(__DIR__), [
    'h1'    => 'Répartition des épreuves — Mise à jour du module',
    'title' => 'Répartition des épreuves — Mise à jour',
    'back'  => [
        'url'   => $CFG->ROOT_DIR . 'Modules/Custom/REPARTITION_EPREUVES/index.php',
        'label' => 'Retour au plan des départs',
    ],
    'after_update' => function () {
        return 'Les tables <code>REP_*</code> sont créées ou complétées automatiquement '
             . 'à la première ouverture d\'une page du module.';
    },
]);
