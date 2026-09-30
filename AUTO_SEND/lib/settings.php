<?php
/**
 * Validation and saving of the settings form.
 *
 * Saving never uploads nor opens anything by itself, with one exception: during
 * a running period, a scoring session newly ticked is opened at once, since the
 * opening of the period has already happened and would not come back for it.
 * A session unticked during the period is left as it is.
 *
 * Moving the start, the end, or switching the schedule on again starts the
 * period over: the opening, the closing and the final upload are all redone.
 */

/**
 * Validate the posted form and save it into the plan.
 *
 * @param object $plan Current plan row.
 * @param array $post $_POST.
 * @param array $sessionsCatalog aus_sessions_catalog().
 * @param bool $canSessions May the visitor manage the ISK-NG scoring?
 * @return array [errors (list of strings), values to show again, flash key]
 */
function aus_settings_save($plan, array $post, array $sessionsCatalog, $canSessions) {
    $errors = [];
    $now = time();

    $enabled    = !empty($post['enabled']);
    $simulation = !empty($post['simulation']);

    $tz = (string)($post['timezone'] ?? '');
    if (!aus_valid_zone($tz)) {
        $errors[] = aus_text('ErrTimeZone');
        $tz = aus_valid_zone($plan->AsTimeZone) ? $plan->AsTimeZone : 'UTC';
    }

    $startLocal = trim((string)($post['start'] ?? ''));
    $endLocal   = trim((string)($post['end'] ?? ''));
    $start = $end = null;
    if ($startLocal !== '') {
        [$start, $err] = aus_local_to_utc($startLocal, $tz);
        if ($err) $errors[] = aus_text('Start') . ' : ' . aus_text($err);
    }
    if ($endLocal !== '') {
        [$end, $err] = aus_local_to_utc($endLocal, $tz);
        if ($err) $errors[] = aus_text('End') . ' : ' . aus_text($err);
    }
    if ($start !== null && $end !== null && aus_ts($end) <= aus_ts($start)) {
        $errors[] = aus_text('ErrEndBeforeStart');
    }

    $interval = (int)($post['interval'] ?? 0);
    if ($interval < AUS_INTERVAL_MIN || $interval > AUS_INTERVAL_MAX) {
        $errors[] = aus_text('ErrInterval', ['min' => AUS_INTERVAL_MIN, 'max' => AUS_INTERVAL_MAX]);
    }

    if ($canSessions) {
        $manage = !empty($post['sessions']);
        $valid = array_column($sessionsCatalog, 'key');
        $keys = array_values(array_intersect(array_map('strval', (array)($post['keys'] ?? [])), $valid));
    } else {
        $manage = (bool)$plan->AsSessions;
        $keys = aus_json_array($plan->AsSessionKeys);
    }

    $items = aus_items_clean($post);

    $ping = trim((string)($post['ping'] ?? ''));
    if ($ping !== '' && (!filter_var($ping, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $ping)
            || mb_strlen($ping) > 255)) {
        $errors[] = aus_text('ErrPingUrl');
    }

    if ($enabled) {
        if ($start === null || $end === null) {
            $errors[] = aus_text('ErrDatesRequired');
        } elseif (aus_ts($end) <= $now) {
            $errors[] = aus_text('ErrEndPast');
        }
        if (!aus_items_count($items)) $errors[] = aus_text('OutErrNothingSelected');
        if ($manage && !$keys) $errors[] = aus_text('ErrNoSession');
        if ((int)$_SESSION['TourType'] === 48) $errors[] = aus_text('ErrRunArchery');
    }

    $values = [
        'enabled' => $enabled, 'simulation' => $simulation, 'timezone' => $tz,
        'start' => $startLocal, 'end' => $endLocal, 'interval' => $interval,
        'sessions' => $manage, 'keys' => $keys, 'items' => $items, 'ping' => $ping,
    ];
    if ($errors) return [$errors, $values, ''];

    $tour = (int)$plan->AsTournament;
    $restart = $enabled !== (bool)$plan->AsEnabled || $start !== $plan->AsStart || $end !== $plan->AsEnd
        || $manage !== (bool)$plan->AsSessions;

    // Newly ticked sessions during a period whose opening already happened.
    $opened = $plan->AsOpened !== null && $plan->AsClosed === null;
    if (!$restart && $enabled && $manage && $opened && $start !== null && $end !== null
            && $now >= aus_ts($start) && $now < aus_ts($end)) {
        $added = array_values(array_diff($keys, aus_json_array($plan->AsSessionKeys)));
        if ($added) aus_sessions_set($tour, $added, true);
    }

    safe_w_sql("UPDATE AutoSendPlans SET
            AsEnabled=" . ($enabled ? 1 : 0) . ",
            AsSimulation=" . ($simulation ? 1 : 0) . ",
            AsTimeZone=" . StrSafe_DB($tz) . ",
            AsStart=" . ($start === null ? 'NULL' : StrSafe_DB($start)) . ",
            AsEnd=" . ($end === null ? 'NULL' : StrSafe_DB($end)) . ",
            AsInterval=$interval,
            AsSessions=" . ($manage ? 1 : 0) . ",
            AsSessionKeys=" . StrSafe_DB(json_encode($keys)) . ",
            AsItems=" . StrSafe_DB(json_encode($items)) . ",
            AsPingUrl=" . StrSafe_DB($ping) . ",
            AsNextSend=NULL,
            AsUpdated=" . StrSafe_DB(aus_utc($now))
            . ($restart ? ", AsOpened=NULL, AsClosed=NULL, AsFinalSent=NULL" : '') . "
        WHERE AsTournament=$tour");

    if (!$enabled) {
        $flash = 'SavedDisabled';
    } elseif ($now >= aus_ts($start)) {
        $flash = 'SavedRunning';
    } else {
        $flash = 'SavedWaiting';
    }
    return [[], $values, $flash];
}
