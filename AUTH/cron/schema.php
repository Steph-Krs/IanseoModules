<?php
/**
 * AUTH module — brings the tables of the module up to date (CLI only).
 *
 * The pages do it themselves, once per session. This script is for the moments when the
 * database changes under open sessions: ianseo-restore runs it right after loading a copy, so
 * a copy taken by an older version (tables AUT_* and BK_*, see names-lib.php) is migrated
 * before the site reopens, instead of on the first new session.
 *
 *   sudo -u www-data php /var/www/ianseo/Modules/Custom/AUTH/cron/schema.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Cron script: command line only.');
}

$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
if (!function_exists('aut_table_names')) {
    // Files restored from an older version, which still uses the old names: nothing to do.
    echo "Module older than this script: tables left as they are.\n";
    exit(0);
}
require_once(dirname(__DIR__) . '/legal-lib.php');
require_once(dirname(__DIR__) . '/logos-lib.php');
require_once(dirname(__DIR__) . '/stats-usage.php');
require_once(dirname(__DIR__) . '/booking/lib/schema.php');
$_SESSION = array();   // no session flag: every step checks the database

aut_table_names();
aut_ensure_schema();
aut_legal_ensure_schema();
aut_logos_schema();
aut_stats_ensure_schema();
bk_schema();
echo '[' . aut_log_time() . "] Tables of the module up to date.\n";
exit(0);
