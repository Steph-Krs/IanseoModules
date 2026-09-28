<?php
/**
 * Modules menu entries of the scorecard splitter.
 *
 * get_which_menu() includes this file on EVERY ianseo page, which is what makes
 * it dangerous: an error here takes the whole installation down. So it reads no
 * table, writes nothing, and only adds entries. The module works on the open
 * competition, so its entries appear while one is open, for whoever may print
 * that competition's scorecards — the right the core's own printout asks for.
 */

require_once __DIR__ . '/lib/lang.php';

if (!empty($on) && !empty($_SESSION['TourId']) && isset($acl) && function_exists('subFeatureAcl')
    && subFeatureAcl($acl, AclQualification, '') >= AclReadOnly) {
    $_scs_url = $CFG->ROOT_DIR . 'Modules/Custom/' . basename(__DIR__) . '/';

    if (!isset($ret['MODS']['SCORECARD_SPLITTER'])) $ret['MODS']['SCORECARD_SPLITTER'][] = scs_text('ModuleName');
    $ret['MODS']['SCORECARD_SPLITTER'][] = scs_text('MenuPrint') . '|' . $_scs_url . 'index.php';

    // Updating or removing a module is for the server administrator. With an
    // account module, AclRoot is granted to every signed-in organiser outside a
    // competition, hence the additional AUTH_ROOT test read straight from the
    // session, which keeps this module independent of any account module.
    if (subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
        && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
        $ret['MODS']['SCORECARD_SPLITTER'][] = scs_text('MenuUpdate') . '|' . $_scs_url . 'admin/update.php';
    }
    unset($_scs_url);
}
