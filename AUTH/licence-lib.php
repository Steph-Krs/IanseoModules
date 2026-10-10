<?php
/**
 * AUTH module — licences of the archers of a competition (read only): who is not in today's FFTA
 * file, and who registered online without an FFTA licence (the online registration's tables are
 * read guarded: this file works without them).
 *
 * The file of the federation carries no validity date: being in the file of the day IS the
 * licence. cron/sync-licences.php reloads it every night and records whether the new file can be
 * trusted (AutLicFileOk): a download cut short must not make everybody "unlicensed". Nothing
 * here is said while the file is not trusted or not fresh.
 *
 * Read from menu.php (Main.php notice): every query is guarded and nothing is written.
 */

if (defined('AUT_LICENCE_LIB_LOADED')) return;
define('AUT_LICENCE_LIB_LOADED', true);

/** Is this an FFTA licence number (7 digits and a letter)? Other numbers are never checked. */
function aut_lic_ffta_code($code)
{
    return (bool) preg_match('/^[0-9]{7}[A-Z]$/', (string) $code);
}

/** SQL condition of an FFTA licence number on Entries. */
function aut_lic_ffta_sql()
{
    return "EnCode REGEXP '^[0-9]{7}[A-Z]$' AND EnIocCode IN ('', 'FRA')";
}

/**
 * Can the absence of a licence from the file be believed? The last import was judged complete
 * (AutLicFileOk, read with an empty default: GetParameter writes a non-empty one) and the file is
 * less than three days old (a server whose nightly sync stopped says nothing).
 */
function aut_lic_file_ok()
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    if (GetParameter('AutLicFileOk', false, '') !== '1') return $ok;
    $rs = safe_r_sql("SELECT LupLastUpdate FROM LookUpPaths WHERE LupIocCode = 'FRA'
        AND LupLastUpdate >= DATE_SUB(NOW(), INTERVAL 3 DAY)", false, true);
    $ok = $rs && safe_fetch($rs);
    return $ok;
}

/**
 * Archers WITHOUT an FFTA licence registered online for a competition (booking/lib/other.php):
 * their identity is not checked by the federation as an FFTA licensee's is (sign-in through the
 * licensee space), so a false account may hide behind a foreign name — the organiser is told.
 * [EnId => row (EnId, EnCode, EnIocCode, EnFirstName, EnName, BrByRole, BrBy, BaSource — null for
 * a clubmate without an account, taken from a federation file)]. Registrations by the organiser
 * (taken over at a re-import) are left out. Guarded: [] while the module tables are missing.
 */
function aut_lic_foreign($tourId)
{
    $t = intval($tourId);
    $rs = safe_r_sql("SELECT EnId, EnCode, EnIocCode, EnFirstName, EnName, BrByRole, BrBy, BaSource
        FROM BookingRegistrations
        INNER JOIN Entries ON EnId = BrEnId AND EnTournament = $t
        LEFT JOIN BookingArchers ON BaLicence = BrLicence
        WHERE BrTournament = $t AND BrByRole <> 'IMPORT' AND EnAthlete = 1 AND NOT (" . aut_lic_ffta_sql() . ")
        ORDER BY EnFirstName, EnName", false, true);
    $out = array();
    while ($rs && ($r = safe_fetch($rs))) $out[intval($r->EnId)] = $r;
    return $out;
}

/**
 * Archers of a competition whose FFTA licence is not in today's file: [EnId => row (EnId,
 * EnCode, EnFirstName, EnName, EnStatus)]. Empty while the file cannot be believed.
 */
function aut_lic_absent($tourId)
{
    if (!aut_lic_file_ok()) return array();
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnStatus
        FROM Entries
        WHERE EnTournament = " . intval($tourId) . " AND EnAthlete = 1 AND " . aut_lic_ffta_sql() . "
          AND NOT EXISTS (SELECT 1 FROM LookUpEntries WHERE LueCode = EnCode AND LueIocCode = 'FRA')
        ORDER BY EnFirstName, EnName", false, true);
    $out = array();
    while ($rs && ($r = safe_fetch($rs))) $out[intval($r->EnId)] = $r;
    return $out;
}
