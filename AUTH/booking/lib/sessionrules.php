<?php
/**
 * lib/sessionrules.php — opening of each departure (ianseo Session) to the online registration.
 *
 * At level 3 the organiser may, departure by departure:
 *  - open it (default), close it, or let it open by itself once every earlier departure still
 *    offered is full — all the departures can be planned in ianseo and on the programme, the
 *    later ones only taking archers when needed;
 *  - give it its own opening and/or closing date, each one replacing the general period of the
 *    competition (BcOpenFrom / BcOpenTo) for this departure; empty = the general one.
 *
 * BookingSessionRules holds one row per departure set up. A departure without a row is open over
 * the general period, and so is every departure below level 3: a competition never set up here
 * behaves as before the table existed.
 *
 * The competition counts as open when at least one departure is (bk_comp_calc_sql, BcIsOpen);
 * bk_reg_blocked() refuses a registration or a waiting row on a departure that is not.
 *
 * A departure closes BK_ARCHER_CUTOFF_MIN before it starts at the latest, whatever its closing
 * date (or without one) — bk_session_limit_at: its time minus one hour, else its day at 00:00 when
 * no time is typed, else the competition's first day at 00:00 (until the eve when the time is
 * unknown). The archer may still register in the morning for the afternoon departure; nothing is
 * possible any more for them once the last one is that close.
 *
 * Until when a registration is changed or cancelled by the archer: bk_change_window().
 */

if (defined('BK_SESSIONRULES_LOADED')) return;
define('BK_SESSIONRULES_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/clock.php';

define('BK_SES_CLOSED', 0);
define('BK_SES_OPEN', 1);
define('BK_SES_AFTER_FULL', 2);
// BK_ARCHER_CUTOFF_MIN: lib/competition.php (the SQL of BcIsOpen needs it too).

/** Rows of BookingSessionRules of a competition, by departure. $fresh: read again after a save. */
function bk_session_rules($tourId, $fresh = false)
{
    static $cache = array();
    $tourId = intval($tourId);
    if (!$fresh && isset($cache[$tourId])) return $cache[$tourId];
    $out = array();
    if (bk_session_rules_ready()) {
        $rs = safe_r_sql("SELECT BdSession, BdState, BdOpenFrom, BdOpenTo
            FROM BookingSessionRules WHERE BdTournament = $tourId", false, true);
        while ($rs && ($r = safe_fetch($rs))) $out[intval($r->BdSession)] = $r;
    }
    return $cache[$tourId] = $out;
}

/**
 * State of every departure for the archers, now. $cfg: bk_comp_config(); $sessions:
 * bk_comp_sessions() when the caller has them. Returns [SesOrder => [
 *   'open'  => bool,
 *   'state' => BK_SES_*,
 *   'from'  => opening applied ('Y-m-d H:i:s' or null), 'to' => closing applied,
 *   'own'   => true when the departure has dates of its own,
 *   'why'   => '' (open) | 'comp' (competition not published) | 'closed' | 'after_full'
 *              (waits for the earlier departures) | 'soon' (opens at 'from') | 'ended'
 * ]]
 */
function bk_session_states($tourId, $cfg, $sessions = null)
{
    $tourId = intval($tourId);
    if ($sessions === null) $sessions = bk_comp_sessions($tourId);
    $rules = intval($cfg->BcPublishLevel ?? 1) === 3 ? bk_session_rules($tourId) : array();
    $now = bk_comp_now($tourId);
    $published = intval($cfg->BcOpen ?? 0) === 1;

    $out = array();
    $earlierFull = true;   // every earlier departure still offered is full
    foreach ($sessions as $s) {
        $o = intval($s->SesOrder);
        $r = $rules[$o] ?? null;
        $state = $r ? intval($r->BdState) : BK_SES_OPEN;
        $ownFrom = $r ? trim((string) $r->BdOpenFrom) : '';
        $ownTo   = $r ? trim((string) $r->BdOpenTo) : '';
        $from = $ownFrom !== '' ? $ownFrom : (trim((string) ($cfg->BcOpenFrom ?? '')) ?: null);
        $to   = $ownTo !== ''   ? $ownTo   : (trim((string) ($cfg->BcOpenTo ?? '')) ?: null);
        $start = bk_session_limit_at($tourId, $o, $s);
        // Closed an hour before it starts at the latest (user's rule): a later closing date, or
        // none, gives way.
        $atStart = $start !== '' && ($to === null || $to >= $start);
        if ($atStart) $to = $start;

        $why = '';
        if (!$published) $why = 'comp';
        elseif ($state === BK_SES_CLOSED) $why = 'closed';
        elseif ($state === BK_SES_AFTER_FULL && !$earlierFull) $why = 'after_full';
        elseif ($from !== null && $from > $now) $why = 'soon';
        elseif ($to !== null && ($atStart ? $to <= $now : $to < $now)) $why = 'ended';
        $out[$o] = array('open' => $why === '', 'state' => $state, 'from' => $from, 'to' => $to,
                         'own' => ($ownFrom !== '' || $ownTo !== ''), 'why' => $why,
                         'start' => $start, 'atStart' => $atStart);

        if ($state !== BK_SES_CLOSED && intval($s->Places) - intval($s->Pris) > 0) $earlierFull = false;
    }
    return $out;
}

/** "Now" in the competition's local time, 'Y-m-d H:i:s' (lib/clock.php). */
function bk_comp_now($tourId)
{
    static $cache = array();
    $tourId = intval($tourId);
    if (!isset($cache[$tourId])) {
        $n = safe_fetch(safe_r_sql("SELECT DATE_FORMAT("
            . bk_local_now_sql("(SELECT ToTimeZone FROM Tournament WHERE ToId = $tourId)")
            . ", '%Y-%m-%d %H:%i:%s') AS N"));
        $cache[$tourId] = $n ? (string) $n->N : '';
    }
    return $cache[$tourId];
}

/**
 * Start of a departure, 'Y-m-d H:i:s': its time as the core keeps it (bk_session_start), else the
 * competition's first day at 00:00 — never later than the competition itself. $s: its row of
 * bk_comp_sessions() when the caller has it.
 */
function bk_session_start_at($tourId, $order, $s = null)
{
    static $first = array();
    $tourId = intval($tourId);
    if ($s === null) {
        foreach (bk_comp_sessions($tourId) as $x) if (intval($x->SesOrder) === intval($order)) $s = $x;
    }
    $v = $s ? bk_session_start($s) : '';
    // bytes: an ASCII date and time
    if ($v !== '') return strlen($v) === 16 ? $v . ':00' : $v;
    if (!isset($first[$tourId])) {
        $t = safe_fetch(safe_r_sql("SELECT ToWhenFrom FROM Tournament WHERE ToId = $tourId"));
        $first[$tourId] = $t ? substr((string) $t->ToWhenFrom, 0, 10) . ' 00:00:00' : '';   // bytes: ASCII date
    }
    return $first[$tourId];
}

/**
 * Last moment an archer acts on departure $order: its start minus BK_ARCHER_CUTOFF_MIN when its
 * time is typed; its day at 00:00 (the eve) when it is not — a start at exactly 00:00 counts as
 * "no time", as everywhere in the module.
 */
function bk_session_limit_at($tourId, $order, $s = null)
{
    $start = bk_session_start_at($tourId, $order, $s);
    // bytes: an ASCII date and time
    if ($start === '' || substr($start, 11, 8) === '00:00:00') return $start;
    return date('Y-m-d H:i:s', strtotime($start) - 60 * BK_ARCHER_CUTOFF_MIN);
}

/**
 * Until when the archer may change and cancel a registration on departure $order:
 *  - change: by default until the registration of that departure closes (its own or general
 *    closing date, its start at the latest) and never once the competition has started; with
 *    BcModUntilStart, until the departure starts, even after the registration closed;
 *  - cancel: by default until the eve of the competition (its first day at 00:00); with
 *    BcCancelUntilStart, until the departure starts.
 * Every limit is at most an hour before the departure starts (bk_session_limit_at; its day at
 * 00:00 when no time is typed), so the archer does nothing any more once the last one is that close;
 * the organiser does what they want in ianseo.
 * Returns ['modify' => ['ok' => bool, 'until' => 'Y-m-d H:i:s'], 'cancel' => [...], 'now' => …].
 * Locked participants, imports and suspended registrations are the callers' business.
 */
function bk_change_window($tourId, $cfg, $order)
{
    $tourId = intval($tourId);
    $now = bk_comp_now($tourId);
    $start = bk_session_limit_at($tourId, $order);
    $t = safe_fetch(safe_r_sql("SELECT ToWhenFrom FROM Tournament WHERE ToId = $tourId"));
    $firstDay = $t ? substr((string) $t->ToWhenFrom, 0, 10) . ' 00:00:00' : '';   // bytes: ASCII date
    $published = intval($cfg->BcOpen ?? 0) === 1;

    if (!empty($cfg->BcModUntilStart)) {
        $mod = array('ok' => $published && $start !== '' && $now < $start, 'until' => $start);
    } else {
        $st = bk_session_states($tourId, $cfg);
        $s = $st[intval($order)] ?? null;
        $until = $s && $s['to'] !== null ? (string) $s['to'] : $firstDay;
        if ($firstDay !== '' && $firstDay < $until) $until = $firstDay;
        $mod = array('ok' => $published && $s && in_array($s['why'], array('', 'after_full', 'soon'), true) && $now < $until,
                     'until' => $until);
    }
    $cUntil = !empty($cfg->BcCancelUntilStart) ? $start : $firstDay;
    if ($start !== '' && $cUntil > $start) $cUntil = $start;
    return array('modify' => $mod, 'cancel' => array('ok' => $cUntil !== '' && $now < $cUntil, 'until' => $cUntil), 'now' => $now);
}

/**
 * May a registration being changed move to departure $order? The departure must be open as for a
 * new registration — or, with BcModUntilStart, only closed by its dates while it has not started.
 */
function bk_session_open_for_change($tourId, $cfg, $st)
{
    if (!empty($st['open'])) return true;
    return !empty($cfg->BcModUntilStart) && ($st['why'] ?? '') === 'ended'
        && ($st['start'] ?? '') !== '' && bk_comp_now($tourId) < $st['start'];
}

/**
 * The line under the registration button: until when the archer will be able to change and cancel
 * this registration. Plain text.
 */
function bk_change_note($tourId, $cfg)
{
    $t = safe_fetch(safe_r_sql("SELECT ToWhenFrom FROM Tournament WHERE ToId = " . intval($tourId)));
    $eve = $t ? bk_date_fr(date('Y-m-d', strtotime(substr((string) $t->ToWhenFrom, 0, 10) . ' -1 day'))) : '';   // bytes: ASCII date
    $close = trim((string) ($cfg->BcOpenTo ?? ''));
    if (!empty($cfg->BcModUntilStart)) {
        $mod = bk_t('ChgUntilStart');
    } elseif ($close !== '' && $t && substr($close, 0, 10) < substr((string) $t->ToWhenFrom, 0, 10)) {   // bytes: ASCII dates
        $mod = bk_t('ChgUntilClose', bk_date_fr($close) . ' ' . date(bk_t('TimeFormat'), strtotime($close)));
    } else {
        $mod = bk_t('ChgUntilEve', $eve);
    }
    $cancel = !empty($cfg->BcCancelUntilStart) ? bk_t('ChgUntilStart') : bk_t('ChgUntilEve', $eve);
    return $mod === $cancel ? bk_t('RegChangeNoteSame', $mod) : bk_t('RegChangeNote', array('mod' => $mod, 'cancel' => $cancel));
}

/** Is this departure open to the registration now? (bk_session_open is the archers' sign-in.) */
function bk_session_is_open($tourId, $cfg, $order)
{
    $st = bk_session_states($tourId, $cfg);
    return !empty($st[intval($order)]['open']);
}

/** Why a departure is not open, for the archer ('' when it is). */
function bk_session_state_text($st)
{
    switch ($st['why'] ?? '') {
        case 'closed':     return bk_t('SesStClosed');
        case 'after_full': return bk_t('SesStAfterFull');
        case 'soon':       return bk_t('SesStSoon', bk_date_fr($st['from']) . ' ' . date(bk_t('TimeFormat'), strtotime($st['from'])));
        case 'ended':      return bk_t('SesStEnded');
        case 'comp':       return bk_t('SesStClosed');
    }
    return '';
}

/**
 * Saves the rules posted by the settings page: $rows = [SesOrder => ['state', 'from', 'to']].
 * A departure open without dates of its own needs no row: it is removed, so the table only holds
 * what differs from the general period.
 */
function bk_session_rules_save($tourId, $rows)
{
    $tourId = intval($tourId);
    $dt = function ($v) {
        $v = trim(str_replace('T', ' ', (string) $v));
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $v) ? StrSafe_DB($v) : 'NULL';
    };
    foreach ($rows as $o => $r) {
        $o = intval($o);
        if ($o < 1 || $o > 255) continue;
        $state = intval($r['state'] ?? BK_SES_OPEN);
        if (!in_array($state, array(BK_SES_CLOSED, BK_SES_OPEN, BK_SES_AFTER_FULL), true)) $state = BK_SES_OPEN;
        $from = $dt($r['from'] ?? '');
        $to   = $dt($r['to'] ?? '');
        if ($state === BK_SES_OPEN && $from === 'NULL' && $to === 'NULL') {
            safe_w_sql("DELETE FROM BookingSessionRules WHERE BdTournament = $tourId AND BdSession = $o");
            continue;
        }
        safe_w_sql("INSERT INTO BookingSessionRules SET BdTournament = $tourId, BdSession = $o,
                BdState = $state, BdOpenFrom = $from, BdOpenTo = $to
            ON DUPLICATE KEY UPDATE BdState = $state, BdOpenFrom = $from, BdOpenTo = $to");
    }
    bk_session_rules($tourId, true);
}
