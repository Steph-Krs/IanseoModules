<?php
/**
 * AJAX: is a newer version of these modules published? For the home page notice.
 *
 * Asked by the script upd_notice_menu() leaves on the ianseo home page, after
 * the page has loaded, with every module whose cached answer was too old in a
 * single request: ?m=AUTH,GUIDE. Answers only an administrator, the same one
 * the update screens accept.
 *
 * The session is closed before the network is touched. PHP holds a lock on the
 * session file for as long as a request runs: without this, a slow or missing
 * connection would block every other page the administrator opens until the
 * timeout — the very slowdown this notice must never cause.
 *
 * Answer: {"error": 0, "msg": "", "notices": [{"m", "text", "link", "url"}]},
 * only the modules for which a newer version exists.
 */

// Walk up to the ianseo root instead of counting directory levels, so this keeps
// working if the modules are installed somewhere other than Modules/Custom/.
$_upd_root = __DIR__;
while ($_upd_root !== dirname($_upd_root) && !is_file($_upd_root . '/config.php')) {
    $_upd_root = dirname($_upd_root);
}
define('HTDOCS', $_upd_root);
unset($_upd_root);

require_once(HTDOCS . '/config.php');
require_once __DIR__ . '/update-lib.php';
require_once __DIR__ . '/notice-lib.php';

if (!upd_is_admin()) JsonOut(['error' => 1, 'msg' => upd_text('ErrNotAllowed')]);

// Module names come from the request: upd_valid_module() is the whitelist and
// requires a module.json, so nothing but a managed module can be named. Bounded,
// because each one may cost a request to GitHub.
$modules = [];
foreach (explode(',', (string)($_GET['m'] ?? '')) as $name) {
    $dir = upd_valid_module($name);
    if ($dir !== null && upd_notice_is_on(basename($dir))) $modules[basename($dir)] = $dir;
    if (count($modules) >= 20) break;
}

session_write_close();

$cache   = upd_notice_state()['cache'];
$store   = [];
$notices = [];
foreach ($modules as $name => $dir) {
    $local = upd_notice_local($dir);
    if ($local === null) continue;

    // Another tab may have read it in the meantime.
    $entry = $cache[$name] ?? null;
    if (!upd_notice_fresh($entry)) {
        $store[$name] = upd_notice_fetch(upd_load_config($dir));
        $entry = ['v' => $store[$name]];
    }

    $item = upd_notice_item($dir, $local, (string)$entry['v']);
    if ($item !== null) $notices[] = $item;
}

if ($store) upd_notice_store($store);

JsonOut(['error' => 0, 'msg' => '', 'notices' => $notices]);
