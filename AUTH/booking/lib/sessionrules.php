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
 */

if (defined('BK_SESSIONRULES_LOADED')) return;
define('BK_SESSIONRULES_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/clock.php';

define('BK_SES_CLOSED', 0);
define('BK_SES_OPEN', 1);
define('BK_SES_AFTER_FULL', 2);

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
    $n = safe_fetch(safe_r_sql("SELECT DATE_FORMAT("
        . bk_local_now_sql("(SELECT ToTimeZone FROM Tournament WHERE ToId = $tourId)")
        . ", '%Y-%m-%d %H:%i:%s') AS N"));
    $now = $n ? $n->N : '';
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

        $why = '';
        if (!$published) $why = 'comp';
        elseif ($state === BK_SES_CLOSED) $why = 'closed';
        elseif ($state === BK_SES_AFTER_FULL && !$earlierFull) $why = 'after_full';
        elseif ($from !== null && $from > $now) $why = 'soon';
        elseif ($to !== null && $to < $now) $why = 'ended';
        $out[$o] = array('open' => $why === '', 'state' => $state, 'from' => $from, 'to' => $to,
                         'own' => ($ownFrom !== '' || $ownTo !== ''), 'why' => $why);

        if ($state !== BK_SES_CLOSED && intval($s->Places) - intval($s->Pris) > 0) $earlierFull = false;
    }
    return $out;
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
