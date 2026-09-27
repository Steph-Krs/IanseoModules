<?php
/**
 * Update and uninstall page of the live draw module.
 *
 * Everything — checking the repository, updating the module and the shared
 * library, installing sibling modules, uninstalling — lives in
 * _shared/update-ui.php, so every module behaves and looks the same.
 *
 * Deliberately NOT booted through lib/boot.php: this page must stay reachable
 * when the module itself is broken, since updating is how it gets repaired.
 */

$_tir_root = dirname(__DIR__);
while ($_tir_root !== dirname($_tir_root) && !is_file($_tir_root . '/config.php')) {
    $_tir_root = dirname($_tir_root);
}
define('HTDOCS', $_tir_root);
unset($_tir_root);

require_once HTDOCS . '/config.php';
require_once dirname(__DIR__) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/_shared/update-ui.php';

// AclRoot alone is not enough with an account module: upd_admin_guard() also
// requires the server administrator view.
upd_admin_guard();

upd_render_common_page(dirname(__DIR__), [
    'h1'    => tir_text('UpdateTitle'),
    'title' => tir_text('UpdateTitle'),
    'back'  => ['url' => $CFG->ROOT_DIR . 'Modules/Custom/' . basename(dirname(__DIR__)) . '/index.php', 'label' => tir_text('BackToShows')],
]);
