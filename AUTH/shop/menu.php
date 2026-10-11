<?php
/**
 * menu.php — menu entries of the points of sale (refreshment bar, food, shop).
 *
 * WARNING: included (through AUTH/menu.php) by get_which_menu() on EVERY ianseo page. A fatal
 * error or a failed query here breaks the whole site: no write of any kind, the only read is
 * guarded ($force = true returns false instead of stopping the page), optional calls checked.
 *
 * Everything sits under "Modules › Food & shop". Until the shop is switched on for the open
 * competition — or while its tables do not exist yet — only the settings entry shows: opening
 * that page creates the tables and unlocks the rest.
 *
 * The check-in desk (desk/) has its own section, "Modules › Check-in desk": its list page is
 * always there (it switches the desk on), the volunteers once the desk is on. SELECT * on
 * purpose: SgDesk may not exist yet on a server not updated, and the menu must still read.
 */

if (!function_exists('shp_t')) require_once __DIR__ . '/lib/lang.php';   // functions only, no query

$shpEntries = array();
$shpDesk = array();

if (!empty($on) && isset($acl) && function_exists('subFeatureAcl')) {
    $shpRw = subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadWrite;
    $shpRo = subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadOnly;
    $shpOn = $shpDeskOn = false;
    $shpRs = safe_r_sql("SELECT * FROM ShopSettings WHERE SgTournament = " . intval($_SESSION['TourId'] ?? 0), false, true);
    if ($shpRs && ($shpRow = safe_fetch($shpRs))) {
        $shpOn = intval($shpRow->SgEnabled) === 1;
        $shpDeskOn = intval($shpRow->SgDesk ?? 0) === 1;
    }
    $shpUrl = $CFG->ROOT_DIR . 'Modules/Custom/AUTH/shop/admin/';
    if ($shpRw) $shpEntries[] = shp_t('MnuSettings') . '|' . $shpUrl . 'index.php';
    if ($shpOn && $shpRw) {
        $shpEntries[] = shp_t('MnuCatalog') . '|' . $shpUrl . 'catalog.php';
        $shpEntries[] = shp_t('MnuStaff') . '|' . $shpUrl . 'staff.php';
    }
    if ($shpOn && $shpRo) {
        $shpEntries[] = shp_t('MnuOrders') . '|' . $shpUrl . 'orders.php';
        $shpEntries[] = shp_t('MnuReports') . '|' . $shpUrl . 'reports.php';
    }
    if ($shpOn && $shpRw) $shpEntries[] = shp_t('MnuPosters') . '|' . $shpUrl . 'posters.php';
    if ($shpRo) $shpDesk[] = shp_t('DkMnuList') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/shop/desk/list.php';
    if ($shpDeskOn && $shpRw) $shpDesk[] = shp_t('MnuStaff') . '|' . $shpUrl . 'staff.php';
}

if ($shpEntries) {
    // CLICKABLE section title (getSubMenuItem handles "Title|URL"): the first screen allowed.
    $ret['MODS']['SHOP'][] = shp_t('MnuTitle') . '|' . explode('|', $shpEntries[0], 2)[1];
    foreach ($shpEntries as $shpE) $ret['MODS']['SHOP'][] = $shpE;
}

if ($shpDesk) {
    $ret['MODS']['DESK'][] = shp_t('DkMenu') . '|' . explode('|', $shpDesk[0], 2)[1];
    foreach ($shpDesk as $shpE) $ret['MODS']['DESK'][] = $shpE;
}

unset($shpEntries, $shpDesk, $shpE, $shpRw, $shpRo, $shpOn, $shpDeskOn, $shpRs, $shpRow, $shpUrl);
