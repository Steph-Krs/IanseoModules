<?php
/**
 * AUTH module — payer trust index, shared by the online registration and the food & shop.
 *
 * Two facts are told apart, as the organisers asked: UNPAID (something is still owed some
 * days after the end of a competition, or a payment was rejected — cheque refused, card
 * dispute) and LATE (settled, but long after the end). They come from the payments journal
 * (BookingLedger) and the amount due computed by the booking payments (bk_accounts), once a
 * night (cron/trust.php), and are kept as incidents in AuthTrustEvents.
 *
 * Only competitions whose organiser records the payments here count: a competition that is
 * over without any payment recorded would otherwise turn every participant into a debtor
 * (paid on site and never recorded is the common case — same rule as the payments page).
 *
 * A SUBJECT is a person ("L:<licence>") or a club ("C:<approval number>"): what a club
 * officer registered for the club's archers, or what was entered in ianseo by the organiser
 * (registrations sent by the clubs), is owed by the club, not by the archer.
 *
 * Explicit levels (green / orange / red), readable rules, settings in config.local.json
 * ("trust"). The index only ever slows down CREDIT — a registration (paid later), a pre-order
 * or "on my account" at the food & shop — never a payment made on the spot. Modes:
 *   off      nothing is computed, the incidents are deleted;
 *   observe  computed, visible to the server administrator only (default);
 *   alert    the organiser sees a warning, nothing is refused;
 *   block    a red subject is refused credit, with what to do to settle it.
 * Ways out: the administrator whitelists or forces a level (reason, end date); the
 * organiser of a competition accepts a person for that competition only (reason, logged).
 * Incidents older than 24 months are forgotten. The person sees their level and why.
 */

if (defined('AUT_TRUST_LOADED')) return;
define('AUT_TRUST_LOADED', true);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lang-lib.php';
require_once __DIR__ . '/booking/lib/clock.php';   // bk_today, bk_server_tz: the server's day, not UTC

define('AUT_TRUST_MONTHS', 24);   // retention of the incidents, and the window looked at

/* ------------------------------------------------------------------ */
/* Settings (config.local.json → "trust")                              */
/* ------------------------------------------------------------------ */

/** Default settings, and the bounds of each number: [key => [default, min, max]]. */
function aut_trust_bounds()
{
    return array(
        'grace_days'      => array(30, 1, 365),    // unpaid: still owed this many days after the end
        'late_days'       => array(15, 0, 365),    // late: settled more than this many days after the end
        'min_amount'      => array(1.0, 0, 1000),  // a remainder below this is ignored (rounding, a coin)
        'orange_late'     => array(2, 1, 20),      // orange from this many late payments over 12 months
        'red_unpaid'      => array(2, 1, 20),      // red from this many open unpaid
        'red_unpaid_days' => array(90, 1, 730),    // red when one unpaid has been open this long
        'red_reject'      => array(2, 1, 20),      // red from this many rejected payments
    );
}

function aut_trust_modes()
{
    return array('off', 'observe', 'alert', 'block');
}

/**
 * Current settings, checked: ['mode', 'grace_days', 'late_days', …]. $use: settings to apply
 * for the rest of the request (just saved — the file is read once per request — or a test).
 */
function aut_trust_config($use = null)
{
    static $forced = null;
    if (is_array($use)) $forced = $use;
    $raw = $forced ?? (aut_local_config()['trust'] ?? array());
    $raw = is_array($raw) ? $raw : array();
    $out = array('mode' => in_array($raw['mode'] ?? '', aut_trust_modes(), true) ? $raw['mode'] : 'observe');
    foreach (aut_trust_bounds() as $k => $b) {
        $v = isset($raw[$k]) && is_numeric($raw[$k]) ? $raw[$k] + 0 : $b[0];
        $out[$k] = ($v < $b[1] || $v > $b[2]) ? $b[0] : (is_float($b[0]) ? round((float) $v, 2) : intval($v));
    }
    return $out;
}

function aut_trust_mode()
{
    return aut_trust_config()['mode'];
}

/** Saves the settings (admin/trust.php). $in: raw form values. Returns true, or false with $err. */
function aut_trust_config_save($in, &$err = '')
{
    require_once __DIR__ . '/config-lib.php';
    $err = '';
    $t = array('mode' => in_array($in['mode'] ?? '', aut_trust_modes(), true) ? $in['mode'] : 'observe');
    foreach (aut_trust_bounds() as $k => $b) {
        $v = str_replace(',', '.', trim((string) ($in[$k] ?? '')));
        if (!is_numeric($v) || $v < $b[1] || $v > $b[2]) {
            $err = aut_t('TrBadNumber', array('field' => aut_t('TrSet_' . $k), 'min' => $b[1], 'max' => $b[2]));
            return false;
        }
        $t[$k] = is_float($b[0]) ? round((float) $v, 2) : intval($v);
    }
    $cfg = aut_cfg_read($err);
    if ($cfg === null) return false;
    $cfg['trust'] = $t;
    if (!aut_cfg_save($cfg, $err)) return false;
    aut_trust_config($t);
    return true;
}

/* ------------------------------------------------------------------ */
/* Subjects                                                            */
/* ------------------------------------------------------------------ */

/**
 * Subject of a licence ("L:…"), or of a club when $club is given ("C:…"). A value that already
 * is a subject is kept. Accounts that are nobody — '#<EnId>' (no licence), 'ANON', 'G<id>'
 * (visitor of the shop), 'C' (counter) — give ''.
 */
function aut_trust_subject($licence, $club = '')
{
    $club = trim((string) $club);
    if ($club !== '') return preg_match('/^[A-Za-z0-9_-]{1,20}$/', $club) ? 'C:' . $club : '';
    $l = trim((string) $licence);
    if (preg_match('/^[LC]:[A-Za-z0-9_-]{1,25}$/', $l)) return $l;
    if ($l === '' || $l === 'C' || strcasecmp($l, 'ANON') === 0 || preg_match('/^(#\d+|G\d+)$/', $l)) return '';
    return preg_match('/^[A-Za-z0-9_-]{1,25}$/', $l) ? 'L:' . $l : '';
}

/** 'L' or 'C', and the licence / approval number of a subject. */
function aut_trust_subject_parts($subject)
{
    return array(substr((string) $subject, 0, 1), (string) substr((string) $subject, 2));   // bytes: ASCII prefix "L:" / "C:"
}

/** Today (YYYY-MM-DD) in the server's zone. */
function aut_trust_today()
{
    return bk_today();
}

/** Days from $a to $b (dates YYYY-MM-DD…), negative when $b is before $a. */
function aut_trust_days($a, $b)
{
    return intval(floor((strtotime(substr((string) $b, 0, 10) . ' 12:00:00') - strtotime(substr((string) $a, 0, 10) . ' 12:00:00')) / 86400));   // bytes: ASCII dates
}

/** 'YYYY-MM-DD…' → 'DD/MM/YYYY'. */
function aut_trust_dmy($d)
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $d, $m) ? "$m[3]/$m[2]/$m[1]" : '';
}

function aut_trust_money($n, $tourId = null)
{
    if (function_exists('bk_eur')) return bk_eur($n, false, $tourId);
    return number_format((float) $n, 2, ',', ' ');
}

/* ------------------------------------------------------------------ */
/* Levels                                                              */
/* ------------------------------------------------------------------ */

/**
 * Reads the overrides and incidents of several subjects in two queries, kept for the request
 * (lists of the organiser pages). Returns [subject => level array, see aut_trust_level].
 * $reset: forget what was read (after a change).
 */
function aut_trust_preload(array $subjects, $reset = false)
{
    static $cache = array();
    if ($reset) $cache = array();
    $want = array();
    foreach ($subjects as $s) if ($s !== '' && !array_key_exists($s, $cache)) $want[$s] = true;
    if ($want) {
        aut_ensure_schema();
        $in = implode(',', array_map('StrSafe_DB', array_keys($want)));
        $over = array(); $events = array();
        $rs = safe_r_sql("SELECT * FROM AuthTrust WHERE TuTournament = 0 AND TuSubject IN ($in)");
        while ($r = safe_fetch($rs)) $over[$r->TuSubject] = $r;
        $rs = safe_r_sql("SELECT AuthTrustEvents.*, ToName, ToCode, AsOwnerScope FROM AuthTrustEvents
            LEFT JOIN Tournament ON ToId = TnTournament
            LEFT JOIN AuthShare ON AsToCode COLLATE utf8mb4_unicode_ci = ToCode
            WHERE TnSubject IN ($in) ORDER BY TnEnd DESC, TnId DESC");
        while ($r = safe_fetch($rs)) $events[$r->TnSubject][] = $r;
        foreach (array_keys($want) as $s) $cache[$s] = aut_trust_eval($s, $over[$s] ?? null, $events[$s] ?? array());
    }
    $out = array();
    foreach ($subjects as $s) if ($s !== '') $out[$s] = $cache[$s];
    return $out;
}

/**
 * Level of a subject (or of a licence): ['subject', 'level' (green|orange|red|white),
 * 'computed' (green|orange|red, before the overrides), 'white' (bool), 'forced' ('' or a
 * level), 'override' (row or null), 'reasons' (incidents, newest first), 'counts'].
 */
function aut_trust_level($subject)
{
    $s = aut_trust_subject($subject);
    if ($s === '') return aut_trust_eval('', null, array());
    return aut_trust_preload(array($s))[$s];
}

/** The rules of the levels, applied to the incidents of one subject. */
function aut_trust_eval($subject, $override, array $events)
{
    $c = aut_trust_config();
    $today = aut_trust_today();
    $year = date('Y-m-d', strtotime($today . ' -12 months'));
    $n = array('unpaid' => 0, 'unpaid_old' => 0, 'late' => 0, 'late12' => 0, 'reject' => 0);
    $reasons = array();
    foreach ($events as $e) {
        $k = $e->TnKind;
        if (!isset($n[$k])) continue;
        $n[$k]++;
        if ($k === 'late' && $e->TnEnd >= $year) $n['late12']++;
        if ($k === 'unpaid' && aut_trust_days($e->TnSince, $today) >= $c['red_unpaid_days']) $n['unpaid_old']++;
        $reasons[] = array('kind' => $k, 'tour' => intval($e->TnTournament), 'name' => (string) ($e->ToName ?? ''),
            'end' => (string) $e->TnEnd, 'since' => (string) $e->TnSince, 'resolved' => (string) ($e->TnResolved ?? ''),
            'amount' => (float) $e->TnAmount, 'days' => intval($e->TnDays), 'count' => intval($e->TnCount),
            'owner' => (string) ($e->AsOwnerScope ?? ''), 'created' => (string) $e->TnCreated);
    }
    if ($n['unpaid'] >= $c['red_unpaid'] || $n['unpaid_old'] > 0 || $n['reject'] >= $c['red_reject']) $computed = 'red';
    elseif ($n['unpaid'] > 0 || $n['late12'] >= $c['orange_late'] || $n['reject'] > 0) $computed = 'orange';
    else $computed = 'green';

    $valid = $override && ($override->TuUntil === null || $override->TuUntil === '' || $override->TuUntil >= $today);
    $white = $valid && intval($override->TuWhite) === 1;
    $forced = $valid && in_array($override->TuForced, array('green', 'orange', 'red'), true) ? $override->TuForced : '';
    $level = $white ? 'white' : ($forced !== '' ? $forced : $computed);
    return array('subject' => $subject, 'level' => $level, 'computed' => $computed, 'white' => $white, 'forced' => $forced,
        'override' => $override ?: null, 'reasons' => $reasons, 'counts' => $n);
}

/** Acceptance of a subject by the organiser of a competition (row), or null. */
function aut_trust_accepted($subject, $tourId)
{
    $s = aut_trust_subject($subject);
    if ($s === '' || intval($tourId) <= 0) return null;
    aut_ensure_schema();
    return safe_fetch(safe_r_sql("SELECT * FROM AuthTrust WHERE TuSubject = " . StrSafe_DB($s)
        . " AND TuTournament = " . intval($tourId))) ?: null;
}

/* ------------------------------------------------------------------ */
/* Gate, acceptance, badge (called by the registration and the shop)   */
/* ------------------------------------------------------------------ */

/**
 * May this licence (or this club, when $club is given: a club officer registering) get
 * CREDIT on this competition? $context: 'registration', 'shop_tab' or 'preorder'. Never
 * called for a payment made on the spot. Returns ['allow' => bool, 'warn' => bool (the
 * organiser should look), 'level', 'accepted' => bool, 'msg' => text for the person refused].
 */
function aut_trust_gate($licence, $tourId, $context, $club = '')
{
    $ok = array('allow' => true, 'warn' => false, 'level' => '', 'accepted' => false, 'msg' => '');
    $mode = aut_trust_mode();
    if ($mode === 'off' || $mode === 'observe') return $ok;
    $tourId = intval($tourId);
    $subject = aut_trust_subject($licence, $club);
    if ($subject === '' || $tourId <= 0) return $ok;

    if ($context === 'shop_tab' || $context === 'preorder') {
        // The competition chose not to apply the index to its shop.
        $rs = safe_r_sql("SELECT SgTrustGate FROM ShopSettings WHERE SgTournament = $tourId", false, true);
        $r = $rs ? safe_fetch($rs) : null;
        if ($r && intval($r->SgTrustGate) !== 1) return $ok;
    } elseif ($context === 'registration' && function_exists('bk_comp_config')) {
        // Nothing to pay, nothing lent.
        $cfg = bk_comp_config($tourId);
        if ($cfg && (float) ($cfg->BcFee ?? 0) <= 0 && trim((string) ($cfg->BcPricing ?? '')) === '') return $ok;
    }

    $lv = aut_trust_level($subject);
    $ok['level'] = $lv['level'];
    if (aut_trust_accepted($subject, $tourId)) {
        $ok['accepted'] = true;
        return $ok;
    }
    if (!in_array($lv['level'], array('orange', 'red'), true)) return $ok;
    $ok['warn'] = true;
    if ($mode === 'block' && $lv['level'] === 'red') {
        $ok['allow'] = false;
        $ok['msg'] = aut_t($club !== '' ? 'TrGateRefusedClub' : 'TrGateRefused');
    }
    return $ok;
}

/**
 * The organiser of a competition accepts a subject for that competition only (reason
 * required). $licence: a licence, or a subject "L:…" / "C:…". Returns true when recorded.
 */
function aut_trust_accept($licence, $tourId, $by, $reason)
{
    $s = aut_trust_subject($licence);
    $reason = trim((string) $reason);
    $tourId = intval($tourId);
    if ($s === '' || $tourId <= 0 || $reason === '') return false;
    aut_ensure_schema();
    safe_w_sql("INSERT INTO AuthTrust SET TuSubject = " . StrSafe_DB($s) . ", TuTournament = $tourId,
            TuReason = " . StrSafe_DB(mb_substr($reason, 0, 255)) . ", TuBy = " . StrSafe_DB(mb_substr((string) $by, 0, 64)) . ",
            TuCreated = UTC_TIMESTAMP(), TuUpdated = UTC_TIMESTAMP()
        ON DUPLICATE KEY UPDATE TuReason = VALUES(TuReason), TuBy = VALUES(TuBy), TuUpdated = UTC_TIMESTAMP()");
    aut_trust_preload(array(), true);
    aut_log('TRUST_ACCEPT', mb_substr((string) $by, 0, 64));
    return true;
}

/** Withdraws an acceptance. */
function aut_trust_unaccept($subject, $tourId, $by)
{
    $s = aut_trust_subject($subject);
    if ($s === '' || intval($tourId) <= 0) return false;
    aut_ensure_schema();
    safe_w_sql("DELETE FROM AuthTrust WHERE TuSubject = " . StrSafe_DB($s) . " AND TuTournament = " . intval($tourId));
    aut_trust_preload(array(), true);
    aut_log('TRUST_UNACCEPT', mb_substr((string) $by, 0, 64));
    return true;
}

/**
 * Small text badge for the organiser lists: '' while the index is off or observing, and for
 * green, whitelisted or nobody. Text and colour, never a symbol alone. Lists: call
 * aut_trust_preload() first with every subject of the page.
 */
function aut_trust_badge($licence, $tourId = 0, $always = false)
{
    $mode = aut_trust_mode();
    if (!$always && ($mode === 'off' || $mode === 'observe')) return '';
    $s = aut_trust_subject($licence);
    if ($s === '') return '';
    $lv = aut_trust_level($s);
    if (!in_array($lv['level'], array('orange', 'red'), true)) return '';
    $accepted = intval($tourId) > 0 && aut_trust_accepted($s, $tourId);
    $color = $lv['level'] === 'red' ? '#a80000;background:#ffd6db;border-color:#bb7575' : '#8a5a12;background:#fdf0e6;border-color:#cb8137';
    $text = aut_t($lv['level'] === 'red' ? 'TrBadgeRed' : 'TrBadgeOrange') . ($accepted ? ' · ' . aut_t('TrBadgeAccepted') : '');
    return ' <span class="aut-trust" style="display:inline-block;padding:0 6px;border:1px solid;border-radius:5px;font-size:11px;color:'
        . $color . '" title="' . htmlspecialchars(aut_t('TrBadgeTitle'), ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
}

/* ------------------------------------------------------------------ */
/* Administrator overrides                                             */
/* ------------------------------------------------------------------ */

/**
 * Whitelist and/or forced level of a subject, with a reason (required) and an optional end
 * date (YYYY-MM-DD). Returns '' or an error text.
 */
function aut_trust_set_override($subject, $white, $forced, $reason, $until, $by)
{
    $s = aut_trust_subject($subject);
    if ($s === '') return aut_t('TrBadSubject');
    $reason = trim((string) $reason);
    if ($reason === '') return aut_t('TrReasonRequired');
    $forced = in_array($forced, array('green', 'orange', 'red'), true) ? $forced : '';
    if (!$white && $forced === '') return aut_t('TrNothingToSet');
    $until = trim((string) $until);
    if ($until !== '' && !(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $until, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
        return aut_t('TrBadDate');
    }
    aut_ensure_schema();
    safe_w_sql("INSERT INTO AuthTrust SET TuSubject = " . StrSafe_DB($s) . ", TuTournament = 0,
            TuWhite = " . ($white ? 1 : 0) . ", TuForced = " . StrSafe_DB($forced) . ",
            TuReason = " . StrSafe_DB(mb_substr($reason, 0, 255)) . ", TuBy = " . StrSafe_DB(mb_substr((string) $by, 0, 64)) . ",
            TuUntil = " . ($until !== '' ? StrSafe_DB($until) : 'NULL') . ", TuCreated = UTC_TIMESTAMP(), TuUpdated = UTC_TIMESTAMP()
        ON DUPLICATE KEY UPDATE TuWhite = VALUES(TuWhite), TuForced = VALUES(TuForced), TuReason = VALUES(TuReason),
            TuBy = VALUES(TuBy), TuUntil = VALUES(TuUntil), TuUpdated = UTC_TIMESTAMP()");
    aut_trust_preload(array(), true);
    aut_log('TRUST_OVERRIDE', mb_substr((string) $by, 0, 64));
    return '';
}

function aut_trust_delete_override($subject, $by)
{
    $s = aut_trust_subject($subject);
    if ($s === '') return;
    aut_ensure_schema();
    safe_w_sql("DELETE FROM AuthTrust WHERE TuSubject = " . StrSafe_DB($s) . " AND TuTournament = 0");
    aut_trust_preload(array(), true);
    aut_log('TRUST_OVERRIDE', mb_substr((string) $by, 0, 64));
}

/* ------------------------------------------------------------------ */
/* Nightly computation                                                 */
/* ------------------------------------------------------------------ */

/**
 * Recomputes every incident of the competitions ended within the last 24 months, then
 * deletes what is no longer true or too old. Returns ['tours', 'incidents', 'deleted',
 * 'subjects' => [level => n]]. Mode off: deletes every incident and stops there.
 */
function aut_trust_compute()
{
    aut_ensure_schema();
    $cfg = aut_trust_config();
    $stats = array('tours' => 0, 'incidents' => 0, 'deleted' => 0, 'subjects' => array());
    if ($cfg['mode'] === 'off') {
        safe_w_sql("DELETE FROM AuthTrustEvents");
        $stats['deleted'] = intval(safe_w_affected_rows());
        aut_trust_preload(array(), true);
        return $stats;
    }
    require_once __DIR__ . '/booking/lib/payment.php';
    if (is_file(__DIR__ . '/shop/lib/pay.php')) require_once __DIR__ . '/shop/lib/pay.php';
    bk_schema();
    @set_time_limit(0);

    $today = aut_trust_today();
    $from  = date('Y-m-d', strtotime($today . ' -' . AUT_TRUST_MONTHS . ' months'));
    $stamp = safe_fetch(safe_r_sql("SELECT UTC_TIMESTAMP() AS n"))->n;

    // Competitions over, within the window, where money was recorded on the server (journal)
    // or lent by the shop ("on my account" orders).
    $ids = array();
    $rs = safe_r_sql("SELECT DISTINCT BlgTournament AS t FROM BookingLedger");
    while ($r = safe_fetch($rs)) $ids[intval($r->t)] = true;
    $rs = safe_r_sql("SELECT DISTINCT ShTournament AS t FROM ShopOrders WHERE ShPayMode = 'tab'", false, true);
    if ($rs) while ($r = safe_fetch($rs)) $ids[intval($r->t)] = true;
    $tours = array();
    if ($ids) {
        $rs = safe_r_sql("SELECT ToId, ToWhenTo FROM Tournament WHERE ToId IN (" . implode(',', array_keys($ids)) . ")
            AND ToWhenTo >= " . StrSafe_DB($from) . " AND ToWhenTo < " . StrSafe_DB($today) . " ORDER BY ToWhenTo");
        while ($r = safe_fetch($rs)) $tours[] = $r;
    }

    foreach ($tours as $t) {
        foreach (aut_trust_tour_incidents(intval($t->ToId), substr((string) $t->ToWhenTo, 0, 10), $cfg, $today) as $subject => $kinds) {   // bytes: ASCII date
            foreach ($kinds as $kind => $i) {
                safe_w_sql("INSERT INTO AuthTrustEvents SET TnSubject = " . StrSafe_DB($subject) . ",
                        TnTournament = " . intval($t->ToId) . ", TnKind = " . StrSafe_DB($kind) . ",
                        TnAmount = " . StrSafe_DB(number_format($i['amount'], 2, '.', '')) . ", TnDays = " . intval($i['days']) . ",
                        TnCount = " . intval($i['count']) . ", TnEnd = " . StrSafe_DB($i['end']) . ", TnSince = " . StrSafe_DB($i['since']) . ",
                        TnResolved = " . ($i['resolved'] !== '' ? StrSafe_DB($i['resolved']) : 'NULL') . ",
                        TnCreated = UTC_TIMESTAMP(), TnChecked = " . StrSafe_DB($stamp) . "
                    ON DUPLICATE KEY UPDATE TnAmount = VALUES(TnAmount), TnDays = VALUES(TnDays), TnCount = VALUES(TnCount),
                        TnEnd = VALUES(TnEnd), TnSince = VALUES(TnSince), TnResolved = VALUES(TnResolved), TnChecked = VALUES(TnChecked)");
                $stats['incidents']++;
            }
        }
        $stats['tours']++;
    }

    // Everything not confirmed by this run: settled since, competition gone or no longer
    // followed, older than the window.
    safe_w_sql("DELETE FROM AuthTrustEvents WHERE TnChecked < " . StrSafe_DB($stamp));
    $stats['deleted'] = intval(safe_w_affected_rows());
    // Expired overrides, and acceptances of competitions out of the window (or deleted).
    safe_w_sql("DELETE FROM AuthTrust WHERE TuTournament = 0 AND TuUntil IS NOT NULL AND TuUntil < " . StrSafe_DB($today));
    safe_w_sql("DELETE FROM AuthTrust WHERE TuTournament > 0 AND TuTournament NOT IN (
        SELECT ToId FROM Tournament WHERE ToWhenTo >= " . StrSafe_DB($from) . ")");

    aut_trust_preload(array(), true);
    $subjects = array();
    $rs = safe_r_sql("SELECT DISTINCT TnSubject FROM AuthTrustEvents");
    while ($r = safe_fetch($rs)) $subjects[] = $r->TnSubject;
    foreach (array_chunk($subjects, 500) as $chunk) {
        foreach (aut_trust_preload($chunk) as $lv) $stats['subjects'][$lv['level']] = ($stats['subjects'][$lv['level']] ?? 0) + 1;
    }
    aut_log('TRUST_CALC', php_sapi_name() === 'cli' ? 'cron' : (string) ($_SESSION['AUTH_User'] ?? ''));
    return $stats;
}

/**
 * Incidents of one competition, ended on $end: [subject => [kind => ['amount', 'days',
 * 'count', 'end', 'since', 'resolved']]], one line per subject and kind (ten archers of a
 * club unpaid on one competition are ONE unpaid of the club).
 */
function aut_trust_tour_incidents($tourId, $end, $cfg, $today)
{
    $min = (float) $cfg['min_amount'];

    // Does the organiser record the REGISTRATION payments here? Lines written by the shop
    // (counter, visitors, stands) do not tell: they are recorded whatever the organiser does.
    $hasStand = (bool) safe_fetch(safe_r_sql("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BookingLedger' AND COLUMN_NAME = 'BlgStand'"));
    $regTracked = (bool) safe_fetch(safe_r_sql("SELECT BlgId FROM BookingLedger WHERE BlgTournament = $tourId
        AND BlgAccount NOT IN ('C', 'ANON') AND BlgAccount NOT LIKE 'G%'" . ($hasStand ? " AND BlgStand = 0" : "") . " LIMIT 1"));

    // Journal lines, per account, in time order.
    $moves = array(); $byId = array();
    $rs = safe_r_sql("SELECT BlgId, BlgAccount, BlgKind, BlgAmount, BlgWhen, BlgCreated, BlgGroup, BlgCancels
        FROM BookingLedger WHERE BlgTournament = $tourId ORDER BY BlgWhen, BlgId");
    while ($r = safe_fetch($rs)) { $moves[$r->BlgAccount][] = $r; $byId[intval($r->BlgId)] = $r; }

    // What each account owes.
    $accounts = array();   // licence => ['due', 'reg', 'club']
    if ($regTracked) {
        foreach (bk_accounts($tourId) as $k => $a) {
            if (aut_trust_subject($k) === '' || $a['due'] <= 0) continue;
            $accounts[$k] = array('due' => $a['due'], 'reg' => $a['reg'], 'club' => (string) $a['club_code']);
        }
    } elseif (function_exists('shp_account_due')) {
        // Only the shop lends on this competition: its part of the account, nothing else.
        $rs = safe_r_sql("SELECT DISTINCT ShLicence FROM ShopOrders WHERE ShTournament = $tourId
            AND ShPayMode = 'tab' AND ShLicence <> ''", false, true);
        if ($rs) while ($r = safe_fetch($rs)) {
            $due = round((float) shp_account_due($tourId, $r->ShLicence), 2);
            if ($due > 0 && aut_trust_subject($r->ShLicence) !== '') {
                $accounts[$r->ShLicence] = array('due' => $due, 'reg' => 0.0, 'club' => '');
            }
        }
    }
    if (!$accounts && !$moves) return array();

    // Registrations owed by the club (D7): entered by a club officer, or entered in ianseo by
    // the organiser (registrations sent by the clubs) — not online by the archer.
    $clubBorne = array();
    $rs = safe_r_sql("SELECT EnCode, BrByRole FROM Entries LEFT JOIN BookingRegistrations ON BrEnId = EnId
        WHERE EnTournament = $tourId AND EnAthlete = 1 AND EnCode <> ''");
    while ($r = safe_fetch($rs)) {
        if ($r->BrByRole === null || $r->BrByRole === 'MANAGER') $clubBorne[$r->EnCode] = true;
    }

    $graceEnd = date('Y-m-d', strtotime($end . ' +' . intval($cfg['grace_days']) . ' days'));
    $out = array();
    $add = function ($subject, $kind, $amount, $days, $since, $resolved) use (&$out, $end) {
        if ($subject === '') return;
        if (!isset($out[$subject][$kind])) {
            $out[$subject][$kind] = array('amount' => 0.0, 'days' => 0, 'count' => 0, 'end' => $end, 'since' => $since, 'resolved' => $resolved);
        }
        $i = &$out[$subject][$kind];
        $i['amount'] = round($i['amount'] + $amount, 2);
        $i['days'] = max($i['days'], $days);
        $i['count']++;
        if ($since < $i['since']) $i['since'] = $since;
        if ($resolved > $i['resolved']) $i['resolved'] = $resolved;
    };

    foreach ($accounts as $lic => $a) {
        $club = $a['club'];
        $hasGroup = false;
        foreach ($moves[$lic] ?? array() as $m) if (intval($m->BlgGroup) > 0) $hasGroup = true;
        $toClub = $club !== '' && $a['reg'] > 0 && (!empty($clubBorne[$lic]) || $hasGroup);
        $archer = aut_trust_subject($lic);
        $clubSubject = $toClub ? aut_trust_subject('', $club) : '';

        // When was the account settled? The last time the payments reached what is due.
        $paid = 0.0; $settledAt = '';
        foreach ($moves[$lic] ?? array() as $m) {
            $before = $paid;
            $paid = round($paid + (float) $m->BlgAmount, 2);
            $when = substr((string) ($m->BlgWhen ?: $m->BlgCreated), 0, 10);   // bytes: ASCII date
            if ($before < $a['due'] - $min && $paid >= $a['due'] - $min) $settledAt = $when;
            elseif ($paid < $a['due'] - $min) $settledAt = '';
        }
        $remaining = round($a['due'] - $paid, 2);
        // Payments count on the registrations first (as on the payments page).
        $leftReg = max(0.0, min($remaining, round($a['reg'] - $paid, 2)));
        $leftOwn = round($remaining - $leftReg, 2);

        if ($remaining > $min) {
            if ($today > $graceEnd) {
                $days = aut_trust_days($end, $today);
                if ($toClub) {
                    if ($leftReg > $min) $add($clubSubject, 'unpaid', $leftReg, $days, $graceEnd, '');
                    if ($leftOwn > $min) $add($archer, 'unpaid', $leftOwn, $days, $graceEnd, '');
                } else {
                    $add($archer, 'unpaid', $remaining, $days, $graceEnd, '');
                }
            }
        } elseif ($settledAt !== '') {
            $days = aut_trust_days($end, $settledAt);
            if ($days > min(intval($cfg['late_days']), intval($cfg['grace_days']))) {
                $own = round($a['due'] - $a['reg'], 2);
                if ($toClub) $add($clubSubject, 'late', $a['reg'], $days, $settledAt, $settledAt);
                if (!$toClub || $own > $min) $add($archer, 'late', $toClub ? $own : $a['due'], $days, $settledAt, $settledAt);
            }
        }
    }

    // Rejected payments (cheque refused, card dispute): a counter-line 'reject' of a payment.
    foreach ($moves as $acc => $list) {
        $archer = aut_trust_subject($acc);
        if ($archer === '') continue;
        foreach ($list as $m) {
            if ($m->BlgKind !== 'reject') continue;
            $orig = $byId[intval($m->BlgCancels)] ?? null;
            $club = $accounts[$acc]['club'] ?? '';
            $byClub = $club !== '' && (($orig && intval($orig->BlgGroup) > 0)
                || (!empty($clubBorne[$acc]) && isset($accounts[$acc]) && $accounts[$acc]['due'] <= $accounts[$acc]['reg'] + $min));
            $when = substr((string) ($m->BlgWhen ?: $m->BlgCreated), 0, 10);   // bytes: ASCII date
            $add($byClub ? aut_trust_subject('', $club) : $archer, 'reject', abs((float) $m->BlgAmount), 0, $when, '');
        }
    }
    return $out;
}

/**
 * Date-time of the last computation in the server's zone ('DD/MM/YYYY HH:MM'), '' if never.
 * AuthLog is written outside any competition, hence in UTC.
 */
function aut_trust_last_run()
{
    aut_ensure_schema();
    $r = safe_fetch(safe_r_sql("SELECT MAX(AlWhen) AS w FROM AuthLog WHERE AlEvent = 'TRUST_CALC'"));
    if (!$r || !$r->w) return '';
    try {
        return (new DateTime((string) $r->w, new DateTimeZone('UTC')))->setTimezone(bk_server_tz())->format('d/m/Y H:i');
    } catch (\Throwable $e) {
        return (string) $r->w;
    }
}

/* ------------------------------------------------------------------ */
/* Display                                                             */
/* ------------------------------------------------------------------ */

/** Names of subjects: [subject => 'NAME First name' or club name]. */
function aut_trust_subject_labels(array $subjects)
{
    $lic = array(); $clubs = array(); $out = array();
    foreach ($subjects as $s) {
        list($t, $v) = aut_trust_subject_parts($s);
        if ($t === 'L') $lic[$v] = $s; elseif ($t === 'C') $clubs[$v] = $s;
        $out[$s] = '';
    }
    if ($lic) {
        $in = implode(',', array_map('StrSafe_DB', array_keys($lic)));
        $rs = safe_r_sql("SELECT LueCode, LueFamilyName, LueName FROM LookUpEntries WHERE LueCode IN ($in) ORDER BY LueDefault");
        while ($r = safe_fetch($rs)) if (isset($lic[$r->LueCode])) $out[$lic[$r->LueCode]] = trim($r->LueFamilyName . ' ' . $r->LueName);
        $rs = safe_r_sql("SELECT BaLicence, BaFamilyName, BaName FROM BookingArchers WHERE BaLicence IN ($in)", false, true);
        if ($rs) while ($r = safe_fetch($rs)) if (isset($lic[$r->BaLicence]) && $out[$lic[$r->BaLicence]] === '') {
            $out[$lic[$r->BaLicence]] = trim($r->BaFamilyName . ' ' . $r->BaName);
        }
    }
    if ($clubs) {
        $in = implode(',', array_map('StrSafe_DB', array_keys($clubs)));
        $rs = safe_r_sql("SELECT DISTINCT LueCountry, LueCoDescr FROM LookUpEntries WHERE LueCountry IN ($in) AND LueCoDescr <> ''");
        while ($r = safe_fetch($rs)) if (isset($clubs[$r->LueCountry])) $out[$clubs[$r->LueCountry]] = (string) $r->LueCoDescr;
        $rs = safe_r_sql("SELECT DISTINCT CoCode, CoName FROM Countries WHERE CoCode IN ($in) AND CoName <> ''");
        while ($r = safe_fetch($rs)) if (isset($clubs[$r->CoCode]) && $out[$clubs[$r->CoCode]] === '') $out[$clubs[$r->CoCode]] = (string) $r->CoName;
    }
    return $out;
}

/** Label of a level (text, never a colour alone). */
function aut_trust_level_label($level)
{
    return aut_t('TrLevel_' . (in_array($level, array('green', 'orange', 'red', 'white'), true) ? $level : 'green'));
}

/** Inline style of a level pill. */
function aut_trust_level_style($level)
{
    $c = array('green' => 'color:#04ac0b;background:#d2f4cd;border-color:#75ae77', 'white' => 'color:#01367c;background:#f0f4ff;border-color:#0254a8',
        'orange' => 'color:#8a5a12;background:#fdf0e6;border-color:#cb8137', 'red' => 'color:#a80000;background:#ffd6db;border-color:#bb7575');
    return 'display:inline-block;padding:1px 8px;border:1px solid;border-radius:5px;font-weight:600;' . ($c[$level] ?? $c['green']);
}

/** One incident in words, in full (for the person concerned and the administrator). */
function aut_trust_reason_text($r)
{
    $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $a = array('name' => $h($r['name'] !== '' ? $r['name'] : '#' . $r['tour']), 'end' => aut_trust_dmy($r['end']),
        'amount' => $h(aut_trust_money($r['amount'], $r['tour'])), 'days' => $r['days'], 'date' => aut_trust_dmy($r['resolved'] ?: $r['since']));
    return aut_t('TrReason_' . $r['kind'], $a);
}

/**
 * "My payment reliability" for the archer's space: level, dated reasons, what to do. ''
 * while the index is off or observing (nothing visible outside the administrator's page).
 */
function aut_trust_archer_html($licence)
{
    $mode = aut_trust_mode();
    if ($mode === 'off' || $mode === 'observe') return '';
    $s = aut_trust_subject($licence);
    if ($s === '') return '';
    $lv = aut_trust_level($s);
    $h = '<div class="aut-trust-me"><h2>' . htmlspecialchars(aut_t('TrMeTitle'), ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p><span style="' . aut_trust_level_style($lv['level']) . '">' . htmlspecialchars(aut_trust_level_label($lv['level']), ENT_QUOTES, 'UTF-8') . '</span></p>';
    if ($lv['reasons']) {
        $h .= '<ul>';
        foreach ($lv['reasons'] as $r) $h .= '<li>' . aut_trust_reason_text($r) . '</li>';
        $h .= '</ul>';
    } else {
        $h .= '<p>' . aut_t('TrMeNone') . '</p>';
    }
    if ($lv['counts']['unpaid'] > 0 || $lv['counts']['reject'] > 0) $h .= '<p>' . aut_t('TrMeHowTo') . '</p>';
    $h .= '<p style="font-size:12px;color:#7d8183">' . aut_t('TrMeRules', array('grace' => aut_trust_config()['grace_days'],
        'months' => AUT_TRUST_MONTHS)) . '</p>';
    return $h . '</div>';
}

/**
 * Section of the organiser's registration page (alert and block modes only): the people
 * registered who are orange or red, the acceptances for this competition, a form to accept
 * someone (a refused person has no registration yet). Details only for incidents on
 * competitions of the same owner; elsewhere the level and a generic reason, never the club
 * nor the amount. $csrf: hidden field of the page's own form protection.
 */
function aut_trust_org_html($tourId, $csrf)
{
    $mode = aut_trust_mode();
    if ($mode === 'off' || $mode === 'observe') return '';
    $tourId = intval($tourId);
    $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    aut_ensure_schema();
    $owner = '';
    $r = safe_fetch(safe_r_sql("SELECT AsOwnerScope FROM AuthShare INNER JOIN Tournament ON ToCode = AsToCode COLLATE utf8mb4_unicode_ci
        WHERE ToId = $tourId"));
    if ($r) $owner = (string) $r->AsOwnerScope;

    $people = array();   // subject => display name
    $rs = safe_r_sql("SELECT EnCode, EnFirstName, EnName, CoCode FROM Entries LEFT JOIN Countries ON CoId = EnCountry
        WHERE EnTournament = $tourId AND EnAthlete = 1 AND EnCode <> ''");
    while ($e = safe_fetch($rs)) {
        $s = aut_trust_subject($e->EnCode);
        if ($s !== '') $people[$s] = trim($e->EnFirstName . ' ' . $e->EnName);
        $c = aut_trust_subject('', (string) $e->CoCode);
        if ($c !== '' && !isset($people[$c])) $people[$c] = (string) $e->CoCode;
    }
    $accepted = array();
    $rs = safe_r_sql("SELECT * FROM AuthTrust WHERE TuTournament = $tourId ORDER BY TuCreated");
    while ($a = safe_fetch($rs)) $accepted[$a->TuSubject] = $a;
    $levels = aut_trust_preload(array_unique(array_merge(array_keys($people), array_keys($accepted))));
    $labels = aut_trust_subject_labels(array_keys($accepted));

    $out = '<div class="bk-sec" id="aut-trust-org"><h2>' . $h(aut_t('TrOrgTitle')) . '</h2>'
        . '<p class="bk-hint" style="margin-top:0">' . $h(aut_t($mode === 'block' ? 'TrOrgHintBlock' : 'TrOrgHintAlert')) . '</p>';
    $rows = '';
    foreach ($levels as $s => $lv) {
        if (!in_array($lv['level'], array('orange', 'red'), true) || !isset($people[$s])) continue;
        $own = 0; $other = 0; $detail = '';
        foreach ($lv['reasons'] as $x) {
            if ($owner !== '' && $x['owner'] === $owner) { $own++; $detail .= '<br>' . aut_trust_reason_text($x); } else { $other++; }
        }
        $rows .= '<tr><td>' . $h($people[$s]) . '</td><td>' . $h(aut_trust_subject_parts($s)[1]) . '</td>'
            . '<td><span style="' . aut_trust_level_style($lv['level']) . '">' . $h(aut_trust_level_label($lv['level'])) . '</span>'
            . (isset($accepted[$s]) ? ' ' . $h(aut_t('TrBadgeAccepted')) : '') . '</td>'
            . '<td>' . ($other ? $h(aut_t('TrOrgGeneric', $other)) : '') . $detail . '</td></tr>';
    }
    $out .= $rows !== ''
        ? '<table class="bk-t"><tr><th>' . $h(aut_t('TrColWho')) . '</th><th>' . $h(aut_t('TrColCode')) . '</th><th>'
            . $h(aut_t('TrColLevel')) . '</th><th>' . $h(aut_t('TrColWhy')) . '</th></tr>' . $rows . '</table>'
        : '<p class="bk-hint">' . $h(aut_t('TrOrgNobody')) . '</p>';

    $out .= '<h2 style="margin-top:14px">' . $h(aut_t('TrOrgAcceptTitle')) . '</h2>'
        . '<form method="post" style="font-size:13px">' . $csrf . '<input type="hidden" name="action" value="trust_accept">'
        . '<label>' . $h(aut_t('TrOrgAcceptWho')) . ' <input type="text" name="trust_who" maxlength="25" required style="width:110px"></label> '
        . '<label><input type="checkbox" name="trust_club" value="1"> ' . $h(aut_t('TrOrgAcceptIsClub')) . '</label> '
        . '<label>' . $h(aut_t('TrOrgAcceptReason')) . ' <input type="text" name="trust_reason" maxlength="255" required style="width:280px"></label> '
        . '<button type="submit" class="bk-btn bk-btn-primary">' . $h(aut_t('TrOrgAcceptBtn')) . '</button></form>'
        . '<p class="bk-hint">' . $h(aut_t('TrOrgAcceptHint')) . '</p>';
    if ($accepted) {
        $out .= '<table class="bk-t"><tr><th>' . $h(aut_t('TrColWho')) . '</th><th>' . $h(aut_t('TrColCode')) . '</th><th>'
            . $h(aut_t('TrColReason')) . '</th><th>' . $h(aut_t('TrColBy')) . '</th><th></th></tr>';
        foreach ($accepted as $s => $a) {
            $out .= '<tr><td>' . $h($people[$s] ?? $labels[$s] ?? '') . '</td><td>' . $h($s) . '</td><td>' . $h($a->TuReason) . '</td>'
                . '<td>' . $h($a->TuBy) . ' · ' . $h(aut_trust_dmy($a->TuCreated)) . '</td>'
                . '<td><form method="post" style="margin:0">' . $csrf . '<input type="hidden" name="action" value="trust_unaccept">'
                . '<input type="hidden" name="trust_who" value="' . $h($s) . '">'
                . '<button type="submit" class="bk-btn">' . $h(aut_t('TrOrgUnaccept')) . '</button></form></td></tr>';
        }
        $out .= '</table>';
    }
    return $out . '</div>';
}

/**
 * Handles the forms of aut_trust_org_html() (the page has checked its own form protection
 * and the organiser's rights on the competition). Returns ['msg' => …, 'err' => …].
 */
function aut_trust_org_post($tourId, $by)
{
    $act = (string) ($_POST['action'] ?? '');
    $who = trim((string) ($_POST['trust_who'] ?? ''));
    if ($act === 'trust_accept') {
        $s = !empty($_POST['trust_club']) ? aut_trust_subject('', $who) : aut_trust_subject($who);
        if ($s === '') return array('msg' => '', 'err' => aut_t('TrBadSubject'));
        if (trim((string) ($_POST['trust_reason'] ?? '')) === '') return array('msg' => '', 'err' => aut_t('TrReasonRequired'));
        aut_trust_accept($s, $tourId, $by, (string) $_POST['trust_reason']);
        return array('msg' => aut_t('TrOrgAccepted', aut_trust_subject_parts($s)[1]), 'err' => '');
    }
    if ($act === 'trust_unaccept') {
        aut_trust_unaccept($who, $tourId, $by);
        return array('msg' => aut_t('TrOrgUnaccepted'), 'err' => '');
    }
    return array('msg' => '', 'err' => '');
}

/** Paragraph of the privacy policy (legal-lib.php), '' when the index is off. */
function aut_trust_privacy_html()
{
    if (aut_trust_mode() === 'off') return '';
    $c = aut_trust_config();
    return '<h2>' . htmlspecialchars(aut_t('TrLgH'), ENT_QUOTES, 'UTF-8') . '</h2><p>'
        . aut_t('TrLg', array('months' => AUT_TRUST_MONTHS, 'grace' => $c['grace_days'], 'late' => $c['late_days'])) . '</p>';
}
