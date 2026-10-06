<?php
/**
 * lib/purge.php — erasing the day after a competition (local time of the competition).
 *
 *   volunteers without a licence   name, given name, password erased; status 'purged'. The
 *                                  history keeps "Volunteer no. X" (shp_staff_label);
 *   licensees and organisers       access ended (status 'ended'); the licensee account stays;
 *   sessions and QR codes          deleted;
 *   visitors (nicknames)           their access is closed (the token of their phone is
 *                                  replaced, SuClosed = 1): nothing can be ordered nor seen from
 *                                  that phone any more. The nickname stays, on the visitor and on
 *                                  their orders: the organiser must be able to tell which orders
 *                                  were collected or paid, and who still owes what;
 *   notifications                  the subscriptions of the browsers (ShopPush) are deleted.
 * Also, at every pass: join requests never approved in SHP_PENDING_MIN (status 'ended', name
 * and password of a person who never worked erased), expired QR codes, and the rows of
 * competitions deleted from ianseo.
 *
 * No ShopStaff row is ever DELETED while its competition exists: "Volunteer no. X" is the rank
 * of the row in its competition (shp_staff_label), and the payment journal writes that same
 * label in BlgBy — deleting a row would shift the numbers of the volunteers after it.
 *
 * Orders, their lines and the payment journal are never deleted (accounts), but keep nothing
 * personal about visitors.
 *
 * Idempotent: running it again changes nothing. Called by the nightly task
 * (cron/maintenance.php → shop/cron/purge.php) and, opportunistically, by the volunteer, public
 * and organiser pages of the points of sale — never by menu.php. shp_purge_due() limits itself
 * to one pass an hour.
 */

if (defined('SHP_PURGE_LOADED')) return;
define('SHP_PURGE_LOADED', true);

require_once __DIR__ . '/staff.php';

/** One pass an hour at most (file marker, as aut_log_purge_daily); $force for the nightly task. */
function shp_purge_due($force = false)
{
    if (!$force) {
        // bytes: tokens and hashes are ASCII hex
        $marker = sys_get_temp_dir() . '/shp_purge_' . substr(hash('sha256', __DIR__), 0, 16);
        $hour = gmdate('Y-m-d H');
        if (is_file($marker) && trim((string) @file_get_contents($marker)) === $hour) return null;
        @file_put_contents($marker, $hour);   // marked first: one try an hour even if the pass fails
    }
    shp_schema();
    return shp_purge_run();
}

/** One pass. Returns counters (for the nightly log and the tests). */
function shp_purge_run()
{
    $n = array('requests' => 0, 'competitions' => 0, 'staff' => 0, 'guests' => 0, 'orphans' => 0);

    // Join requests never approved in time: closed, and nothing personal kept for a person
    // without a licence. Rows kept (stable volunteer numbers, see above).
    $old = array();
    $rs = safe_r_sql("SELECT SfId FROM ShopStaff WHERE SfStatus = 'pending'
        AND (SfCreated IS NULL OR SfCreated < DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . SHP_PENDING_MIN . " MINUTE))");
    while ($r = safe_fetch($rs)) $old[] = intval($r->SfId);
    if ($old) {
        $in = implode(',', $old);
        safe_w_sql("DELETE FROM ShopStaffSessions WHERE SkStaff IN ($in)");
        safe_w_sql("DELETE FROM ShopStaffStands WHERE StStaff IN ($in)");
        safe_w_sql("UPDATE ShopStaff SET SfStatus = 'ended', SfCode = '',
                SfFamilyName = IF(SfKind = 'LOCAL', '', SfFamilyName), SfGivenName = IF(SfKind = 'LOCAL', '', SfGivenName),
                SfPassword = ''
            WHERE SfId IN ($in) AND SfStatus = 'pending'");
        $n['requests'] = safe_w_affected_rows();
    }
    safe_w_sql("DELETE FROM ShopInvites WHERE SqExpires < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)");

    // Competitions holding something to erase, and whose day after has come.
    $tours = array();
    $rs = safe_r_sql("SELECT SfTournament AS t FROM ShopStaff WHERE SfStatus IN ('pending', 'active', 'locked', 'revoked')
        UNION SELECT SuTournament FROM ShopGuests WHERE SuClosed = 0
        UNION SELECT SqTournament FROM ShopInvites
        UNION SELECT SyTournament FROM ShopPush");
    while ($r = safe_fetch($rs)) $tours[] = intval($r->t);
    foreach (array_unique($tours) as $t) {
        $w = shp_window($t, true);
        if (!$w || !$w['purge_due']) continue;   // deleted competition: handled below
        $c = shp_purge_tour($t);
        $n['competitions']++;
        $n['staff'] += $c['staff'];
        $n['guests'] += $c['guests'];
    }

    $n['orphans'] = shp_purge_orphans();
    return $n;
}

/** Erasing of one competition whose day after has come. */
function shp_purge_tour($tourId)
{
    $t = intval($tourId);
    $c = array('staff' => 0, 'guests' => 0);

    safe_w_sql("UPDATE ShopStaff SET SfFamilyName = '', SfGivenName = '', SfPassword = '', SfCode = '', SfStatus = 'purged'
        WHERE SfTournament = $t AND SfKind = 'LOCAL' AND (SfStatus <> 'purged' OR SfFamilyName <> '' OR SfGivenName <> '' OR SfPassword <> '')");
    $c['staff'] += safe_w_affected_rows();
    safe_w_sql("UPDATE ShopStaff SET SfStatus = 'ended', SfCode = ''
        WHERE SfTournament = $t AND SfKind <> 'LOCAL' AND SfStatus NOT IN ('ended', 'purged')");
    $c['staff'] += safe_w_affected_rows();
    safe_w_sql("DELETE ShopStaffSessions FROM ShopStaffSessions INNER JOIN ShopStaff ON SfId = SkStaff WHERE SfTournament = $t");
    safe_w_sql("DELETE FROM ShopInvites WHERE SqTournament = $t");
    safe_w_sql("DELETE FROM ShopPush WHERE SyTournament = $t");

    $rs = safe_r_sql("SELECT SuId FROM ShopGuests WHERE SuTournament = $t AND SuClosed = 0");
    while ($r = safe_fetch($rs)) {
        shp_purge_guest(intval($r->SuId));
        $c['guests']++;
    }
    return $c;
}

/**
 * Closes the access of a visitor: the token of their phone is replaced by the hash of a random
 * value nobody holds (the UNIQUE key stays satisfied). Their nickname is kept.
 */
function shp_purge_guest($guestId)
{
    list(, $dead) = shp_token_new();
    safe_w_sql("UPDATE ShopGuests SET SuTokenHash = '$dead', SuClosed = 1 WHERE SuId = " . intval($guestId));
}

/**
 * Rows of competitions deleted from ianseo: volunteers, their rights, sessions, codes, journal,
 * visitors — and the nicknames copied on orders kept for the accounts. Returns rows touched.
 *
 * Not a competition being RE-IMPORTED: ianseo deletes the old version and creates a new one with
 * the same code and another id; the online registration then moves everything, the points of
 * sale included, onto the new id (booking/lib/adopt.php). Until it has, the old id is left alone.
 */
function shp_purge_orphans()
{
    $n = 0;
    $gone = "NOT IN (SELECT ToId FROM Tournament
        UNION SELECT BcTournament FROM BookingCompetitions INNER JOIN Tournament ON ToCode = BcCode" . bk_coll() . " WHERE BcCode <> '')";
    safe_w_sql("DELETE ShopStaffSessions FROM ShopStaffSessions INNER JOIN ShopStaff ON SfId = SkStaff WHERE SfTournament $gone");
    $n += safe_w_affected_rows();
    safe_w_sql("DELETE ShopStaffStands FROM ShopStaffStands INNER JOIN ShopStaff ON SfId = StStaff WHERE SfTournament $gone");
    $n += safe_w_affected_rows();
    safe_w_sql("DELETE FROM ShopStaff WHERE SfTournament $gone");
    $n += safe_w_affected_rows();
    safe_w_sql("DELETE FROM ShopStaffLog WHERE SjTournament $gone");
    $n += safe_w_affected_rows();
    safe_w_sql("DELETE FROM ShopInvites WHERE SqTournament $gone");
    $n += safe_w_affected_rows();
    safe_w_sql("DELETE FROM ShopGuests WHERE SuTournament $gone");
    $n += safe_w_affected_rows();
    safe_w_sql("DELETE FROM ShopPush WHERE SyTournament $gone");
    $n += safe_w_affected_rows();
    safe_w_sql("UPDATE ShopOrders SET ShCustLabel = '' WHERE ShCustKind = 'GUEST' AND ShCustLabel <> '' AND ShTournament $gone");
    $n += safe_w_affected_rows();
    return $n;
}
