<?php
/**
 * Opening and closing the scoring of ISK-NG, the way Api/ISK-NG/Sessions.php does.
 *
 * ISK-NG keeps, per competition, the list of LOCKED sessions in the core's
 * ModulesParameters (module 'ISK-NG', parameter 'LockedSessions'), and refuses
 * any score sent for a session in that list. Opening removes the plan's keys
 * from the list, closing adds them back; keys the plan does not manage are left
 * exactly as they are. Reading and writing go through the core's
 * getModuleParameter() / setModuleParameter().
 *
 * Each happens once per schedule (at the start, at the end), never again on the
 * following runs: an operator who locks a session by hand during the challenge
 * is not overridden a minute later.
 */

/**
 * Scoring sessions of the open competition, as listed by the core page.
 *
 * GetLockableSessions() reads $_SESSION['TourId'], so this is for pages, where
 * the competition is the one open.
 *
 * @return array List of ['key', 'label'].
 */
function aus_sessions_catalog() {
    global $CFG;
    require_once $CFG->DOCUMENT_PATH . 'Api/ISK-NG/Lib.php';

    $out = [];
    $q = safe_r_sql(GetLockableSessions());
    while ($r = safe_fetch($q)) {
        $type = $r->SesType[0];
        if ($type === 'Q' || $type === 'E') {
            $detail = get_text('PopupStatusDistance', 'Api', $r->Distance);
        } elseif ($type === 'R') {
            $detail = get_text('RoundNum', 'RoundRobin', $r->Distance);
        } else {
            $detail = get_text($r->Distance . '_Phase');
        }
        $out[] = [
            'key'   => $r->LockKey,
            'label' => get_text($type . '-Session', 'Tournament') . ' — ' . $r->Description . ' — ' . $detail,
        ];
    }
    return $out;
}

/**
 * Keys currently locked for a competition.
 *
 * @param int $tour
 * @return array
 */
function aus_sessions_locked($tour) {
    $locked = getModuleParameter('ISK-NG', 'LockedSessions', [], (int)$tour, true);
    return is_array($locked) ? array_values($locked) : [];
}

/**
 * Open (unlock) or close (lock) the given scoring sessions of a competition.
 *
 * @param int $tour
 * @param array $keys LockKeys, such as "Q|1|1".
 * @param bool $open True to open, false to close.
 * @return array The list of locked keys after the change.
 */
function aus_sessions_set($tour, array $keys, $open) {
    $locked = aus_sessions_locked($tour);
    if ($open) {
        $locked = array_values(array_diff($locked, $keys));
    } else {
        $locked = array_values(array_unique(array_merge($locked, $keys)));
    }
    setModuleParameter('ISK-NG', 'LockedSessions', $locked, (int)$tour);
    return $locked;
}
