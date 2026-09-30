<?php
/**
 * Stores the visitor's choice of mode or palette, then goes back to the page
 * the choice was made from.
 *
 * Open to every visitor: the choice only changes how this browser draws ianseo,
 * it reaches nobody else and nothing on the server. Values outside the known
 * lists are ignored, and the way back only ever leads to a page of this server.
 */

// Modules/ and Modules/Custom/ each hold a config.php that only relays to the
// real one, hence the Common/ folder that tells the ianseo root apart.
$_thm_root = __DIR__;
while ($_thm_root !== dirname($_thm_root)
       && !(is_file($_thm_root . '/config.php') && is_dir($_thm_root . '/Common'))) {
    $_thm_root = dirname($_thm_root);
}
define('HTDOCS', $_thm_root);
unset($_thm_root);

require_once HTDOCS . '/config.php';
require_once __DIR__ . '/lib/theme.php';

$choice = thm_choice();
$mode = (string)($_GET['mode'] ?? '');
$palette = (string)($_GET['palette'] ?? '');
if (in_array($mode, thm_modes(), true)) $choice['mode'] = $mode;
if (in_array($palette, thm_palette_ids(), true)) $choice['palette'] = $palette;
thm_store($choice);

// Back to the referring page when it belongs to this server, to the module's
// page otherwise: never to an address another site could have planted.
$back = thm_url() . 'index.php';
$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
if ($referer !== '') {
    $parts = parse_url($referer);
    if (is_array($parts) && isset($parts['host'], $parts['path'])
        && strcasecmp($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''), (string)($_SERVER['HTTP_HOST'] ?? '')) === 0
        && strpos($parts['path'], (string)$CFG->ROOT_DIR) === 0) {
        $back = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
header('Location: ' . $back);
exit;
