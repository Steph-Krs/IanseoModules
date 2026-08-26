<?php
// admin/update.php — Mise à jour / désinstallation du module SELEC.
// Toute la logique est mutualisée dans _shared/update-ui.php.
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once dirname(__DIR__, 2) . '/_shared/update-ui.php';

// AclRoot seul ne suffit pas avec un module de comptes : upd_admin_guard()
// exige en plus la vue Administrateur serveur (AUTH_ROOT).
upd_admin_guard();

upd_render_common_page(dirname(__DIR__), [
    'h1'    => 'Sélection Équipe de France — Mise à jour du module',
    'title' => 'Sélection — Mise à jour',
    'back'  => ['url' => $CFG->ROOT_DIR . 'Modules/Custom/SELEC/index.php',
                'label' => 'Retour à la configuration de la sélection'],
    'after_update' => function () {
        return 'Les modes de sélection livrés (dossier <code>modes/</code>) ont pu changer. '
             . 'Les compétitions déjà rattachées conservent le mode figé au moment de leur '
             . 'rattachement : leurs classements ne bougent pas. Pour appliquer une nouvelle '
             . 'version d\'un mode à une compétition, il faut la ré-ancrer explicitement '
             . 'depuis sa page de configuration.';
    },
]);
