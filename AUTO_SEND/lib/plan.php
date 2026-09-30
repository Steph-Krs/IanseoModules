<?php
/**
 * Plans (one per competition) and the clock arithmetic they need.
 *
 * TIME ZONES
 * The organiser types local times; the database keeps UTC; the scheduled task
 * compares UTC timestamps. The time zone is a NAMED zone (Europe/Paris), never
 * the competition's ToTimeZone: ianseo stores that one as a fixed offset
 * (+01:00 or +02:00), which cannot know that a challenge starting in winter
 * time ends in summer time. With a named zone PHP applies the offset in force
 * on each date, so an end typed as 18:00 stays 18:00 on the wall clock after
 * the clock change.
 *
 * Two local times need a decision on the days the clocks change: one that does
 * not exist (02:30 on the spring change) is refused; one that exists twice
 * (02:30 on the autumn change) is resolved by PHP to the second, after the
 * change, and the page shows the offset next to every time so the choice is
 * visible.
 */

const AUS_INTERVAL_MIN = 5;
const AUS_INTERVAL_MAX = 1440;
const AUS_INTERVAL_DEFAULT = 10;
// How long after the end the final upload keeps being retried when it fails.
const AUS_FINAL_GRACE = 172800;
// Upload history kept per competition.
const AUS_RUNS_KEEP_DAYS = 60;

/**
 * UTC "Y-m-d H:i:s" for a timestamp (now by default), as stored in the tables.
 *
 * @param int|null $ts
 * @return string
 */
function aus_utc($ts = null) {
    return gmdate('Y-m-d H:i:s', $ts === null ? time() : $ts);
}

/**
 * Timestamp of a UTC DATETIME read from the tables.
 *
 * @param string|null $utc
 * @return int|null Null for an empty value.
 */
function aus_ts($utc) {
    if ($utc === null || $utc === '' || $utc === '0000-00-00 00:00:00') return null;
    try {
        return (new DateTime($utc, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Is this a time zone PHP knows by name?
 *
 * @param string $tz
 * @return bool
 */
function aus_valid_zone($tz) {
    return in_array($tz, DateTimeZone::listIdentifiers(), true);
}

/**
 * Convert a local "Y-m-d\TH:i" (the value of a datetime-local field) to UTC.
 *
 * @param string $local
 * @param string $tz Named time zone.
 * @return array [UTC "Y-m-d H:i:s" or null, error key or null]
 */
function aus_local_to_utc($local, $tz) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/', $local, $m)
        || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59) {
        return [null, 'ErrDate'];
    }
    if (!aus_valid_zone($tz)) return [null, 'ErrTimeZone'];

    $dt = DateTime::createFromFormat('!Y-m-d\TH:i', $local, new DateTimeZone($tz));
    // PHP moves a time that falls in the spring gap forward by an hour; reading
    // it back is how that is detected.
    if (!$dt || $dt->format('Y-m-d\TH:i') !== $local) return [null, 'ErrTimeGap'];

    $dt->setTimezone(new DateTimeZone('UTC'));
    return [$dt->format('Y-m-d H:i:s'), null];
}

/**
 * Format a UTC DATETIME in a named zone.
 *
 * @param string|null $utc
 * @param string $tz
 * @param string $format DateTime format.
 * @return string Empty for an empty value.
 */
function aus_utc_to_local($utc, $tz, $format = 'Y-m-d\TH:i') {
    $ts = aus_ts($utc);
    if ($ts === null) return '';
    $dt = new DateTime('@' . $ts);
    $dt->setTimezone(new DateTimeZone(aus_valid_zone($tz) ? $tz : 'UTC'));
    return $dt->format($format);
}

/**
 * Local date, time and offset for display: "2027-03-28 18:00 (UTC+02:00)".
 *
 * @param string|null $utc
 * @param string $tz
 * @return string
 */
function aus_display_time($utc, $tz) {
    $s = aus_utc_to_local($utc, $tz, 'Y-m-d H:i');
    return $s === '' ? '' : $s . ' (UTC' . aus_utc_to_local($utc, $tz, 'P') . ')';
}

/**
 * The plan of a competition, or null.
 *
 * @param int $tour
 * @return object|null
 */
function aus_plan_load($tour) {
    $q = safe_r_sql("SELECT * FROM AutoSendPlans WHERE AsTournament=" . (int)$tour);
    return ($r = safe_fetch($q)) ? $r : null;
}

/**
 * Create the plan of a competition if it has none, disabled, with defaults.
 *
 * @param int $tour
 * @param array $keys Scoring sessions opened and closed by default.
 * @param array $items What is uploaded by default.
 */
function aus_plan_ensure($tour, array $keys, array $items) {
    safe_w_sql("INSERT IGNORE INTO AutoSendPlans (AsTournament, AsInterval, AsSessionKeys, AsItems, AsUpdated)
        VALUES (" . (int)$tour . ", " . AUS_INTERVAL_DEFAULT . ", "
        . StrSafe_DB(json_encode(array_values($keys))) . ", "
        . StrSafe_DB(json_encode($items)) . ", "
        . StrSafe_DB(aus_utc()) . ")");
}

/**
 * Decode a JSON column into an array.
 *
 * @param string|null $json
 * @return array
 */
function aus_json_array($json) {
    $v = json_decode((string)$json, true);
    return is_array($v) ? $v : [];
}

/**
 * Where a plan stands, for the status panel.
 *
 * @param object $p
 * @param int $now
 * @return string 'disabled', 'incomplete', 'waiting', 'running', 'finishing' or 'done'.
 */
function aus_plan_phase($p, $now) {
    if (!$p->AsEnabled) return 'disabled';
    $start = aus_ts($p->AsStart);
    $end   = aus_ts($p->AsEnd);
    if ($start === null || $end === null) return 'incomplete';
    if ($now < $start) return 'waiting';
    if ($now < $end) return 'running';
    if ($p->AsFinalSent === null && $now < $end + AUS_FINAL_GRACE) return 'finishing';
    return 'done';
}

/**
 * Minutes to wait before retrying after consecutive failures: 1, 2, 4, 8…,
 * never more than the interval.
 *
 * A network outage fails fast (the credentials check comes before the costly
 * part), so retrying early is cheap and the upload resumes within a minute or
 * two of the connection coming back; a failure that persists settles at the
 * normal pace instead of hammering the server.
 *
 * @param int $failures Consecutive failures, including the one just recorded.
 * @param int $interval Minutes.
 * @return int Minutes.
 */
function aus_backoff($failures, $interval) {
    $delay = 2 ** max(0, min(10, $failures - 1));
    return (int)max(1, min($interval, $delay));
}
