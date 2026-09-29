<?php
/**
 * lib/clock.php — "now" and "today" in the right time zone.
 *
 * ianseo forces PHP to UTC (config.php) and sets every MySQL connection to that same UTC
 * (Common/Fun_DB.inc.php: SET time_zone = date('P')). Only OPENING a competition
 * switches the connection to the competition's zone (Common/Globals.inc.php, ToTimeZone).
 * So organiser pages (a competition is open) and archer pages (none is) do NOT share the
 * same NOW()/CURDATE(): they are two hours apart in summer.
 *
 * Real bug, 2026-09-30 00:37: the survey showed "closed" to the organiser, yet accepted
 * an answer from an archer — whose page still believed it was the 29th (UTC). The same
 * gap made registration windows open and close up to two hours late for archers.
 *
 * Rule: a time set by the organiser (registration window, shop deadline) or a date of the
 * competition (ToWhenTo) is compared with the CURRENT LOCAL TIME OF THAT COMPETITION,
 * computed from UTC and its ToTimeZone — never with NOW()/CURDATE()/date() alone.
 * CONVERT_TZ works with numeric offsets (what ianseo stores: '+02:00') without the MySQL
 * time-zone tables; if ToTimeZone is empty or not understood, the server's zone is used.
 *
 * ToTimeZone is a FIXED offset, pre-filled from the browser's offset on the day the
 * competition was CREATED (Tournament/Fun_Index.js): a December competition created in
 * August says '+02:00'. Taken literally, a registration opening set for 20:00 would open
 * at 21:00 once the clocks have gone back. So an offset that is one of the server zone's
 * own (standard or summer) means "the server's zone", with today's offset; any other
 * offset (overseas: -04:00, +04:00…, no summer time there) is used as it is.
 */

if (defined('BK_CLOCK_LOADED')) return;
define('BK_CLOCK_LOADED', true);

/** Server time zone: config.local.json of the AUTH module → "timezone" (default Europe/Paris). */
function bk_server_tz()
{
    static $tz = null;
    if ($tz instanceof DateTimeZone) return $tz;
    $name = 'Europe/Paris';
    $f = dirname(__DIR__, 2) . '/config.local.json';
    if (is_file($f)) {
        $raw = (string) @file_get_contents($f);
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);   // strip a BOM (3 bytes)
        $c = json_decode($raw, true);
        if (is_array($c) && !empty($c['timezone']) && is_string($c['timezone'])) $name = $c['timezone'];
    }
    try { $tz = new DateTimeZone($name); } catch (\Throwable $e) { $tz = new DateTimeZone('Europe/Paris'); }
    return $tz;
}

/** Today (YYYY-MM-DD) in the server's zone, for PHP-side "today" (calendar, map, finished). */
function bk_today()
{
    return (new DateTime('now', bk_server_tz()))->format('Y-m-d');
}

/**
 * SQL expression: current local date-time of a competition. $tzExpr is an SQL expression
 * giving its ToTimeZone — the column itself when Tournament is in the query, or a
 * subquery "(SELECT ToTimeZone FROM Tournament WHERE ToId = …)".
 */
function bk_local_now_sql($tzExpr = 'ToTimeZone')
{
    $tz  = bk_server_tz();
    $now = new DateTime('now', $tz);
    $srv = $now->format('P');
    $y   = $now->format('Y');
    $own = array_unique(array(
        (new DateTime("$y-01-15 12:00", $tz))->format('P'),
        (new DateTime("$y-07-15 12:00", $tz))->format('P'),
    ));
    $own = "'', '" . implode("', '", $own) . "'";
    return "COALESCE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00',"
         . " CASE WHEN COALESCE($tzExpr, '') IN ($own) THEN '$srv' ELSE $tzExpr END),"
         . " CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '$srv'))";
}

/** SQL expression: current local date of a competition (see bk_local_now_sql). */
function bk_local_today_sql($tzExpr = 'ToTimeZone')
{
    return 'DATE(' . bk_local_now_sql($tzExpr) . ')';
}
