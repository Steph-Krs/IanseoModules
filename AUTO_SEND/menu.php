<?php
/**
 * Modules menu entries of the scheduled upload module.
 *
 * get_which_menu() includes this file on EVERY ianseo page, which is what makes
 * it dangerous: an error here takes the whole installation down. So it reads no
 * table, writes nothing, and only builds menu entries.
 *
 * The settings page works on the open competition, so its entry appears only
 * when one is open, for whoever may send it to ianseo.net — the right the
 * core's own upload entry asks for.
 */

require_once __DIR__ . '/lib/lang.php';

$_aus_url = $CFG->ROOT_DIR . 'Modules/Custom/' . basename(__DIR__) . '/';

// $acl is not defined on every menu path; the pages check the right themselves in any case.
if (($_SESSION['TourId'] ?? 0) > 0
    && (!isset($acl) || !function_exists('subFeatureAcl') || subFeatureAcl($acl, AclInternetPublish, 'ipSend') == AclReadWrite)) {
    if (!isset($ret['MODS']['AUTO_SEND'])) $ret['MODS']['AUTO_SEND'][] = aus_text('ModuleName');
    $ret['MODS']['AUTO_SEND'][] = aus_text('MenuSettings') . '|' . $_aus_url . 'index.php';
}

// Updating or removing a module is for the server administrator. With an
// account module, AclRoot is granted to every signed-in organiser outside a
// competition, hence the additional AUTH_ROOT test read straight from the
// session, which keeps this module independent of any account module.
if ((!isset($acl) || !function_exists('subFeatureAcl') || subFeatureAcl($acl, AclRoot, '') >= AclReadWrite)
    && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
    if (!isset($ret['MODS']['AUTO_SEND'])) $ret['MODS']['AUTO_SEND'][] = aus_text('ModuleName');
    $ret['MODS']['AUTO_SEND'][] = aus_text('MenuUpdate') . '|' . $_aus_url . 'admin/update.php';
}
unset($_aus_url);
