<?php
/**
 * AUTH module — update / uninstallation.
 * Shared logic in _shared/update-ui.php. The uninstallation warning and the list of the AUT_*
 * tables live in version.json.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once dirname(__DIR__, 2) . '/_shared/update-ui.php';
require_once dirname(__DIR__) . '/lang-lib.php';

// AclRoot alone is not enough with an accounts module: upd_admin_guard() also requires the
// server Administrator view (AUTH_ROOT).
upd_admin_guard();

$deployUrl = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/deploy.php';
upd_render_common_page(dirname(__DIR__), [
    'h1'    => aut_t('UpdH1'),
    'title' => aut_t('UpdTitle'),
    'back'  => ['url' => $CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/', 'label' => aut_t('UpdBack')],
    'after_update' => function () use ($deployUrl) {
        return aut_t('UpdAfter', htmlspecialchars($deployUrl));
    },
]);
