<?php
/**
 * Update and uninstall page of the scheduled upload module.
 *
 * Everything — checking the repository, updating the module and the shared
 * library, installing sibling modules, uninstalling — lives in
 * _shared/update-ui.php, so every module behaves and looks the same.
 *
 * Deliberately NOT booted through lib/boot.php: this page must stay reachable
 * when the module itself is broken, since updating is how it gets repaired.
 */

// Modules/ and Modules/Custom/ each hold a config.php that only relays to the
// real one, hence the Common/ folder that tells the ianseo root apart.
$_aus_root = dirname(__DIR__);
while ($_aus_root !== dirname($_aus_root)
       && !(is_file($_aus_root . '/config.php') && is_dir($_aus_root . '/Common'))) {
    $_aus_root = dirname($_aus_root);
}
define('HTDOCS', $_aus_root);
unset($_aus_root);

require_once HTDOCS . '/config.php';
require_once dirname(__DIR__) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/_shared/update-ui.php';

// AclRoot alone is not enough with an account module: upd_admin_guard() also
// requires the server administrator view.
upd_admin_guard();

upd_render_common_page(dirname(__DIR__), [
    'h1'    => aus_text('UpdateTitle'),
    'title' => aus_text('UpdateTitle'),
    'back'  => ['url' => $CFG->ROOT_DIR . 'Modules/Custom/' . basename(dirname(__DIR__)) . '/index.php', 'label' => aus_text('BackToSettings')],
]);
