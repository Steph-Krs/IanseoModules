<?php
/**
 * public/boot.php — bootstrap of the archer space (public side).
 *
 * $SKIP_AUTH bypasses the ORGANISER authentication bootstrap (config.php): these pages must
 * stay reachable by an anonymous archer — no allow-list to maintain elsewhere. Mechanism of
 * the ianseo core, already used by Api/ISK-NG.
 *
 * The price, accepted: these pages have NO ianseo ACL. Every read and write is therefore
 * guarded explicitly by bk_current_archer() and bk_csrf_check(), and limited to what the
 * signed-in licensee may see.
 */

$SKIP_AUTH = 1;

define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/archer.php';
require_once dirname(__DIR__) . '/lib/ui.php';

bk_schema();

// "Seen from another account" (server administrator, READ ONLY): the single guard of the
// whole archer space. Any POST (so any write, the module's rule being "CSRF on every POST")
// is refused while the administrator looks at someone else's space.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && function_exists('bk_impersonating') && bk_impersonating()) {
    bk_impersonation_block();
}
