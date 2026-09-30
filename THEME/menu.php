<?php
/**
 * Menu entries of the theme, and the theme itself on every page with a menu.
 *
 * get_which_menu() includes this file on EVERY ianseo page, which is what makes
 * it dangerous: an error here takes the whole installation down. So it reads no
 * table, writes nothing, and only reads the visitor's cookie.
 *
 * The entries switch the mode straight from the menu; the palettes, which need
 * to be seen to be chosen, are on the module's page. Every visitor may choose:
 * it is a display preference kept by their own browser.
 *
 * The stylesheets are printed here, once per page, unless hook.php already adds
 * them to the page's head (pages without a menu enabled by the administrator):
 * the menu comes after the head, so this is the fallback, not the best place.
 */

require_once __DIR__ . '/lib/lang.php';
require_once __DIR__ . '/lib/theme.php';

$_thm_url    = thm_url();
$_thm_choice = thm_choice();

if (!isset($ret['MODS']['THEME'])) $ret['MODS']['THEME'][] = thm_esc(thm_text('ModuleName'));
foreach (thm_modes() as $_thm_mode) {
    $ret['MODS']['THEME'][] = ($_thm_choice['mode'] === $_thm_mode ? '&#10003; ' : '')
        . thm_esc(thm_text('Mode_' . $_thm_mode)) . '|' . $_thm_url . 'set.php?mode=' . $_thm_mode;
}
$ret['MODS']['THEME'][] = thm_esc(thm_text('MenuColours')) . '|' . $_thm_url . 'index.php';

// Updating or removing a module is for the server administrator. With an
// account module, AclRoot is granted to every signed-in organiser outside a
// competition, hence the additional AUTH_ROOT test read straight from the
// session, which keeps this module independent of any account module.
if (isset($acl) && function_exists('subFeatureAcl') && subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
    && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
    $ret['MODS']['THEME'][] = thm_esc(thm_text('MenuUpdate')) . '|' . $_thm_url . 'admin/update.php';
}
unset($_thm_url, $_thm_choice, $_thm_mode);

if (empty($GLOBALS['_thm_css_done'])) {
    $GLOBALS['_thm_css_done'] = true;
    if (!thm_hook_active()) echo thm_page_markup();
}
