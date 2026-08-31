<?php
/**
 * Module AUTH — Mise à jour / désinstallation.
 * Logique mutualisée dans _shared/update-ui.php. L'avertissement de
 * désinstallation et la liste des tables AUT_* vivent dans version.json.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once dirname(__DIR__, 2) . '/_shared/update-ui.php';

// AclRoot seul ne suffit pas avec un module de comptes : upd_admin_guard()
// exige en plus la vue Administrateur serveur (AUTH_ROOT).
upd_admin_guard();

$deployUrl = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/deploy.php';
upd_render_common_page(dirname(__DIR__), [
    'h1'    => 'Multi-comptes — Mise à jour du module',
    'title' => 'Multi-comptes — Mises à jour',
    'back'  => ['url' => $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/', 'label' => 'Retour aux comptes'],
    'after_update' => function () use ($deployUrl) {
        return 'Si les fichiers <code>dist/</code> ont changé, pensez à les <a href="'
             . htmlspecialchars($deployUrl) . '">redéployer</a>.';
    },
]);
