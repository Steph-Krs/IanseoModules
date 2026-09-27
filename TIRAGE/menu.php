<?php
/**
 * Modules menu entries of the live draw module.
 *
 * get_which_menu() includes this file on EVERY ianseo page, with or without a
 * competition open, which is exactly what a draw needs: it belongs to no
 * competition. It is also what makes this file dangerous — an error here takes
 * the whole installation down — so it reads no table, writes nothing, and only
 * builds menu entries.
 */

require_once __DIR__ . '/lib/lang.php';

// $acl is not defined on every menu path (the development build skips it when no
// competition is open); the pages check the right themselves in any case.
if (!isset($acl) || !function_exists('subFeatureAcl') || subFeatureAcl($acl, AclRoot, '') >= AclReadWrite) {
    $_tir_url = $CFG->ROOT_DIR . 'Modules/Custom/' . basename(__DIR__) . '/';

    if (!isset($ret['MODS']['TIRAGE'])) $ret['MODS']['TIRAGE'][] = tir_text('ModuleName');
    $ret['MODS']['TIRAGE'][] = tir_text('MenuShows') . '|' . $_tir_url . 'index.php';

    // Updating or removing a module is for the server administrator. With an
    // account module, AclRoot is granted to every signed-in organiser outside a
    // competition, hence the additional AUTH_ROOT test read straight from the
    // session, which keeps this module independent of any account module.
    if (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT'])) {
        $ret['MODS']['TIRAGE'][] = tir_text('MenuUpdate') . '|' . $_tir_url . 'admin/update.php';
    }
    unset($_tir_url);
}
