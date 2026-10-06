<?php
/**
 * admin/shop.php — former page of the shop of the online registration. The shop now lives in
 * the points of sale (AUTH/shop, menu "Food & shop"), where its items and orders were moved:
 * this address leads to their catalogue.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/shop/admin/catalog.php');
exit;
