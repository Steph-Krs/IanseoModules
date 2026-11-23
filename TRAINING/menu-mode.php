<?php
/**
 * Full or simplified menu, for this browser only.
 *
 * The coaches and the person who prepares the formats share the same ianseo
 * without accounts: the choice is kept by each browser (cookie TRN_FULL, scoped
 * to this installation's path), so that one computer shows the full menu while
 * the coaches keep the simplified one on theirs.
 */

require_once __DIR__ . '/lib/boot.php';

$full = !empty($_GET['full']);
$path = $CFG->ROOT_DIR !== '' ? $CFG->ROOT_DIR : '/';
setcookie('TRN_FULL', $full ? '1' : '', [
    'expires'  => $full ? time() + 5 * 365 * 86400 : time() - 3600,
    'path'     => $path,
    'samesite' => 'Lax',
    'httponly' => true,
]);
trn_redirect(intval($_SESSION['TourId'] ?? 0) > 0 ? 'dashboard.php' : 'index.php');
