<?php
/**
 * public/login.php — "I am a licensee: sign in" from the shop. Remembers the shop page, then
 * hands over to the licensee sign-in, which brings the customer back (bk_next_after_login()).
 * Only the public key of a competition of this server is kept, never an address.
 */

require_once __DIR__ . '/boot.php';

$key = (string) ($_GET['k'] ?? '');
if (shp_tour_by_key($key) > 0 && !shp_impersonating()) {
    $_SESSION['BK_NEXT'] = array('page' => 'shop/public/index.php?k=' . $key, 'at' => time());
}
header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php?p=comp');
exit;
