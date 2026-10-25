<?php
/**
 * Modules menu entries of the scorecard reader.
 *
 * get_which_menu() includes this file on EVERY ianseo page, which is what makes
 * it dangerous: an error here takes the whole installation down. So it reads one
 * row of the core's Parameters at most (lib/dev.php, the development tools shown or
 * not), writes nothing, and only adds entries. The module works on the open
 * competition, so its entries appear while one is open, for whoever may see the
 * qualification round — the right the core's own scorecard check asks for.
 */

require_once __DIR__ . '/lib/lang.php';
// Guarded: an update that brought this menu but not yet its library must not stop ianseo.
if (is_file(__DIR__ . '/lib/dev.php')) require_once __DIR__ . '/lib/dev.php';

if (!empty($on) && intval($_SESSION['TourId'] ?? 0) > 0 && isset($acl) && function_exists('subFeatureAcl')
    && subFeatureAcl($acl, AclQualification, '') >= AclReadOnly) {
    $_ocr_url = $CFG->ROOT_DIR . 'Modules/Custom/' . basename(__DIR__) . '/';

    if (!isset($ret['MODS']['OCR'])) $ret['MODS']['OCR'][] = ocr_text('ModuleName');
    $ret['MODS']['OCR'][] = ocr_text('MenuQualification') . '|' . $_ocr_url . 'qualification.php';
    // The development tools, when the administrator has shown them: the series capture
    // stores photos on the server, the cells to check are typed in, both need the right to
    // write scores.
    if (function_exists('ocr_dev_on') && subFeatureAcl($acl, AclQualification, '') >= AclReadWrite && ocr_dev_on()) {
        $ret['MODS']['OCR'][] = ocr_text('MenuCapture') . '|' . $_ocr_url . 'capture.php';
        $ret['MODS']['OCR'][] = ocr_text('MenuVerify') . '|' . $_ocr_url . 'verify.php';
    }

    // Updating or removing a module is for the server administrator. With an
    // account module, AclRoot is granted to every signed-in organiser outside a
    // competition, hence the additional AUTH_ROOT test read straight from the
    // session, which keeps this module independent of any account module.
    if (subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
        && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
        $ret['MODS']['OCR'][] = ocr_text('MenuUpdate') . '|' . $_ocr_url . 'admin/update.php';
    }
    unset($_ocr_url);
}
