<?php
/**
 * lib/desk.php — check-in desk of a competition: documents checked by the registry, what an
 * archer owes collected, equipment checked by the judges. Used on the volunteers' phones and by
 * the organiser (desk/index.php), summed up for the organiser (desk/list.php).
 *
 * WHO: the volunteers of the points of sale (lib/staff.php) with rights for the whole
 * competition in ShopStaff.SfDesk — reg (documents), pay (collect), equip (equipment, draw
 * weights); an ORGANISER volunteer has them all. Checked here, by every endpoint: hiding a
 * button is only comfort.
 *
 * WHAT: decisions per archer in BookingChecks, keyed by the payment account (licence, or
 * '#<EnId>' without one) so that they survive a re-import: division '' = documents, a division
 * = the equipment of that weapon (an archer shooting twice with the same bow is checked once).
 * Everything written goes to BookingCheckLog (decisions, draw weights, notes), never changed.
 *
 * STATUS of the participant (Entries.EnStatus), entry by entry, after every decision:
 *   a refusal (documents, or equipment of that weapon) → 8 "cannot participate, incomplete
 *   documentation"; documents accepted, no refusal → 0 "can participate"; nothing decided any
 *   more (undone) → the status the desk found (BookingCheckEntries). Never changed: 9 (the
 *   licence does not allow to shoot: the federation's file decides), 6 (withdrawn), 7 (not
 *   accredited). The nightly licence checks leave 8 alone, as they leave 6 and 7
 *   (cron/sync-licences.php, booking/lib/licences.php).
 *
 * Times shown are the competition's local time (shp_local_now_sql); the connection of these
 * pages is in UTC.
 */

if (defined('SHP_DESK_LOADED')) return;
define('SHP_DESK_LOADED', true);

require_once __DIR__ . '/staff-session.php';   // staff, common
require_once __DIR__ . '/pay.php';             // shp_pay_account, bk_account_*
require_once dirname(__DIR__, 2) . '/licence-lib.php';
require_once dirname(__DIR__, 2) . '/booking/lib/ui.php';   // bk_date_time, bk_date_fr

define('SHP_DESK_NOTE_MAX', 500);     // characters of a note
define('SHP_DESK_POWER_MAX', 99.9);   // pounds

/* ------------------------------------------------------------------ */
/* Rights                                                              */
/* ------------------------------------------------------------------ */

/** Desk rights of a volunteer: ['reg', …]; an ORGANISER has them all. */
function shp_desk_rights($staff)
{
    $s = shp_staff_row($staff);
    if (!$s || !shp_staff_working($s)) return array();
    if ($s->SfKind === 'ORGANISER') return shp_desk_perms_all();
    $c = shp_desk_perms_clean($s->SfDesk ?? '');
    return $c === '' ? array() : explode(',', $c);
}

/** May this volunteer do $perm at the desk? '' = any desk right. */
function shp_desk_can($staff, $perm = '')
{
    $r = shp_desk_rights($staff);
    return $perm === '' ? (bool) $r : in_array((string) $perm, $r, true);
}

/**
 * The volunteer holding this phone, allowed at the desk ($perm '' = any right). Otherwise stops:
 * JSON envelope {error: 1, code, msg} for an endpoint, or a page telling what to do.
 */
function shp_require_desk($perm = '')
{
    $st = shp_staff_session_state();
    $reason = $st['reason'];
    if ($st['staff']) {
        if (!shp_desk_on(intval($st['staff']->SfTournament))) $reason = 'desk_off';
        elseif (!shp_desk_can($st['staff'], $perm)) $reason = 'forbidden';
    }
    if ($st['staff'] && $reason === 'ok') return $st['staff'];

    $http = in_array($reason, array('signed_out', 'expired'), true) ? 401 : 403;
    $msg = $reason === 'desk_off' ? shp_t('DkErrOff') : ($reason === 'forbidden' ? shp_t('DkErrForbidden') : shp_staff_reason_msg($reason));
    if (shp_wants_json()) shp_json_error($reason, $msg, $http);
    $tour = $st['row'] ? intval($st['row']->SfTournament) : shp_tour_by_key((string) ($_GET['k'] ?? ''));
    if (!headers_sent()) http_response_code($http);
    $title = shp_t('DkTitle');
    shp_head($title, array('layout' => 'card'));
    echo '  <main class="shp-main"><div class="shp-card"><h1>' . shp_e($title) . '</h1>'
        . shp_msg(in_array($reason, array('desk_off', 'shop_off'), true) ? 'info' : 'warn', $msg);
    $s = $tour > 0 ? shp_settings($tour) : null;
    if ($s && in_array($reason, array('signed_out', 'expired', 'locked'), true)) {
        echo '<p><a class="shp-btn shp-btn-primary shp-btn-block" href="'
            . shp_e(shp_url('staff/login.php?k=' . rawurlencode($s->SgPublicKey) . '&to=desk')) . '">' . shp_e(shp_t('ShStfLoginAgain')) . '</a></p>';
    } elseif ($reason === 'pending') {
        echo '<p><a class="shp-btn shp-btn-primary shp-btn-block" href="' . shp_e(shp_url('staff/join.php')) . '">'
            . shp_e(shp_t('ShStfBackToWait')) . '</a></p>';
    } elseif ($reason === 'signed_out') {
        echo '<p class="shp-muted">' . shp_e(shp_t('DkScanToSignIn')) . '</p>';
    }
    echo "</div></main>\n";
    shp_foot();
    exit;
}

/** Means of payment at the desk: those of the stands, without "on the account". */
function shp_desk_pay_methods()
{
    $m = shp_pay_methods();
    unset($m['tab']);
    return $m;
}

/* ------------------------------------------------------------------ */
/* Archers and their entries                                           */
/* ------------------------------------------------------------------ */

/** Is this an account key: a licence (letters, digits, dash) or '#<EnId>'? */
function shp_desk_account_ok($account)
{
    return (bool) preg_match('/^(#[0-9]{1,10}|[A-Za-z0-9][A-Za-z0-9-]{0,24})$/', (string) $account);
}

/** SQL condition on Entries: the participants of an account. */
function shp_desk_account_sql($account)
{
    $id = bk_account_enid($account);
    return $id ? "EnId = $id AND EnCode = ''" : "EnCode = " . StrSafe_DB($account);
}

/** SQL expression: the account key of an Entries row (bk_account_key). */
function shp_desk_key_sql()
{
    return "IF(EnCode <> '', EnCode, CONCAT('#', EnId))";
}

/** Individual events of the competition: [EvCode => name]. */
function shp_desk_events($tourId)
{
    static $cache = array();
    $tourId = intval($tourId);
    if (isset($cache[$tourId])) return $cache[$tourId];
    $out = array();
    $rs = safe_r_sql("SELECT EvCode, EvEventName FROM Events WHERE EvTournament = $tourId AND EvTeamEvent = 0 ORDER BY EvProgr, EvCode");
    while ($r = safe_fetch($rs)) $out[(string) $r->EvCode] = (string) $r->EvEventName;
    return $cache[$tourId] = $out;
}

/** Departures of the competition: [SesOrder => ['label', 'start']]. */
function shp_desk_sessions($tourId)
{
    static $cache = array();
    $tourId = intval($tourId);
    if (isset($cache[$tourId])) return $cache[$tourId];
    $out = array();
    foreach (bk_comp_sessions($tourId) as $s) {
        $o = intval($s->SesOrder);
        $label = bk_t('DepCap', $o) . (trim((string) $s->SesName) !== '' ? ' — ' . trim((string) $s->SesName) : '');
        $out[$o] = array('label' => $label, 'start' => bk_date_time(bk_session_start($s)));
    }
    return $cache[$tourId] = $out;
}

/** Target of a QuTargetNo ("1004A" → "4A"), '' when none. */
function shp_desk_target($targetNo)
{
    $t = (string) $targetNo;
    // bytes: target numbers and letters are ASCII
    if (strlen($t) < 4) return '';
    $n = intval(substr($t, 1, -1));
    return $n > 0 ? $n . substr($t, -1) : '';
}

/** Label of a participant's status (Entries.EnStatus). */
function shp_desk_status_label($status)
{
    $s = intval($status);
    return in_array($s, array(0, 1, 5, 6, 7, 8, 9), true) ? shp_t('DkStatus' . $s) : (string) $s;
}

/**
 * Entries of an account in a competition, oldest departure first: participants only (EnAthlete),
 * with their departure, target, weapon, class, events and status.
 */
function shp_desk_entries($tourId, $account)
{
    $tourId = intval($tourId);
    $out = array();
    if (!shp_desk_account_ok($account)) return $out;
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnDob, EnStatus, EnDivision, EnClass, EnIocCode,
            CoCode, CoName, QuSession, QuTargetNo, DivDescription, ClDescription
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry
        LEFT JOIN Divisions ON DivTournament = EnTournament AND DivId = EnDivision
        LEFT JOIN Classes ON ClTournament = EnTournament AND ClId = EnClass
        WHERE EnTournament = $tourId AND EnAthlete = 1 AND " . shp_desk_account_sql($account) . "
        ORDER BY QuSession, EnId");
    $ids = array();
    while ($r = safe_fetch($rs)) { $out[intval($r->EnId)] = $r; $ids[] = intval($r->EnId); }
    foreach ($out as $r) $r->Events = array();
    if ($ids) {
        $rs = safe_r_sql("SELECT IndId, IndEvent FROM Individuals WHERE IndTournament = $tourId AND IndId IN (" . implode(',', $ids) . ")");
        while ($r = safe_fetch($rs)) $out[intval($r->IndId)]->Events[] = (string) $r->IndEvent;
    }
    return $out;
}

/** Decisions of an account: [division => 1 accepted | 2 refused] ('' = documents). */
function shp_desk_states($tourId, $account, $write = false)
{
    $sql = "SELECT CkDivision, CkState FROM BookingChecks
        WHERE CkTournament = " . intval($tourId) . " AND CkAccount = " . StrSafe_DB($account);
    $rs = $write ? safe_w_sql($sql) : safe_r_sql($sql);
    $out = array();
    while ($r = safe_fetch($rs)) $out[(string) $r->CkDivision] = intval($r->CkState);
    return $out;
}

/**
 * Brings the status of each entry of an account in line with its decisions (see the head of
 * this file). Writes only what changes. Called under the desk lock after a decision, and when
 * an archer's file is opened (an entry added since then follows the decisions).
 */
function shp_desk_apply($tourId, $account)
{
    $tourId = intval($tourId);
    if (!shp_desk_account_ok($account)) return;
    $states = shp_desk_states($tourId, $account, true);
    $reg = intval($states[''] ?? 0);
    $now = date('Y-m-d H:i:s');
    // Write connection: inside the lock, what another phone has just written must be seen.
    $rs = safe_w_sql("SELECT EnId, EnStatus, EnDivision, CeWas FROM Entries
        LEFT JOIN BookingCheckEntries ON CeEntry = EnId
        WHERE EnTournament = $tourId AND EnAthlete = 1 AND " . shp_desk_account_sql($account));
    $rows = array();
    while ($r = safe_fetch($rs)) $rows[] = $r;
    foreach ($rows as $r) {
        $cur = intval($r->EnStatus);
        if (in_array($cur, array(6, 7, 9), true)) continue;
        $equip = intval($states[(string) $r->EnDivision] ?? 0);
        if ($reg === 2 || $equip === 2) $to = 8;
        elseif ($reg === 1) $to = 0;
        elseif ($r->CeWas !== null) $to = intval($r->CeWas);
        else continue;
        if ($to === $cur) continue;
        $id = intval($r->EnId);
        if ($r->CeWas === null) {
            safe_w_sql("INSERT IGNORE INTO BookingCheckEntries SET CeEntry = $id, CeTournament = $tourId, CeWas = $cur");
        }
        safe_w_sql("UPDATE Entries SET EnStatus = $to, EnTimestamp = '$now' WHERE EnId = $id AND EnTournament = $tourId");
    }
}

/* ------------------------------------------------------------------ */
/* Search                                                              */
/* ------------------------------------------------------------------ */

/**
 * Account of a scanned back-number QR code, '' when none. ianseo writes the licence there with
 * other parts ("licence|country|division" by default, or the competition's own pattern such as
 * "licence-division-class"): every part, and every beginning of a dashed text (licences of other
 * federations hold a dash), is tried as a licence of the competition. '$<EnId>' is the core's
 * own form.
 */
function shp_desk_scan($tourId, $text)
{
    $tourId = intval($tourId);
    $text = trim((string) $text);
    if ($text === '' || preg_match('/\s/u', $text)) return '';
    if (preg_match('/^\$([0-9]{1,10})$/', $text, $m)) {
        $r = safe_fetch(safe_r_sql("SELECT EnId, EnCode FROM Entries WHERE EnTournament = $tourId AND EnAthlete = 1 AND EnId = " . intval($m[1])));
        return $r ? bk_account_key($r->EnCode, $r->EnId) : '';
    }
    if (!preg_match('/[|\/;\-]/', $text)) return '';
    $cands = array();
    foreach (preg_split('/[|\/;]+/', $text) as $part) {
        $bits = explode('-', $part);
        for ($i = 1; $i <= count($bits); $i++) $cands[] = implode('-', array_slice($bits, 0, $i));
        foreach ($bits as $b) $cands[] = $b;
    }
    $cands = array_values(array_unique(array_filter(array_map('trim', $cands), function ($c) {
        return $c !== '' && mb_strlen($c) <= 25;
    })));
    if (!$cands) return '';
    $rs = safe_r_sql("SELECT DISTINCT EnCode FROM Entries WHERE EnTournament = $tourId AND EnAthlete = 1
        AND EnCode IN (" . implode(',', array_map('StrSafe_DB', $cands)) . ")");
    $found = array();
    while ($r = safe_fetch($rs)) $found[] = (string) $r->EnCode;
    // The first part that is a licence wins (the licence comes first in ianseo's patterns).
    foreach ($cands as $c) {
        foreach ($found as $f) if (strcasecmp($f, $c) === 0) return $f;
    }
    return '';
}

/**
 * Archers of the competition matching a search: every word must be found in the family name,
 * the given name, the licence, the club code or the club name, in any order (the collation of
 * ianseo's columns ignores case and accents). A scanned QR code gives its archer directly.
 * Returns ['rows' => [archer summaries], 'scan' => bool, 'more' => bool].
 */
function shp_desk_search($tourId, $q, $limit = 50)
{
    $tourId = intval($tourId);
    $q = trim(mb_substr((string) $q, 0, 200));
    $out = array('rows' => array(), 'scan' => false, 'more' => false);
    if ($q === '') return $out;
    $where = array();
    $acc = shp_desk_scan($tourId, $q);
    if ($acc !== '') {
        $out['scan'] = true;
        $where[] = shp_desk_account_sql($acc);
    } else {
        $words = array_slice(array_values(array_unique(array_filter(preg_split('/[\s,;]+/u', $q), 'strlen'))), 0, 6);
        foreach ($words as $w) {
            $like = StrSafe_DB('%' . addcslashes($w, '%_\\') . '%');
            $where[] = "(EnFirstName LIKE $like OR EnName LIKE $like OR EnCode LIKE $like OR CoCode LIKE $like OR CoName LIKE $like)";
        }
        if (!$where) return $out;
    }
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnDivision, CoCode, CoName, QuSession, QuTargetNo
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry
        WHERE EnTournament = $tourId AND EnAthlete = 1 AND " . implode(' AND ', $where) . "
        ORDER BY EnFirstName, EnName, EnCode, QuSession
        LIMIT " . (intval($limit) * 4 + 1));
    $byAcc = array();
    $n = 0;
    while ($r = safe_fetch($rs)) {
        $n++;
        $k = bk_account_key($r->EnCode, $r->EnId);
        if (!isset($byAcc[$k])) {
            if (count($byAcc) >= $limit) { $out['more'] = true; break; }
            $byAcc[$k] = array('account' => $k, 'family' => (string) $r->EnFirstName, 'given' => (string) $r->EnName,
                'licence' => (string) $r->EnCode, 'club' => trim($r->CoCode . ' ' . $r->CoName), 'places' => array());
        }
        $byAcc[$k]['places'][] = array('session' => intval($r->QuSession), 'target' => shp_desk_target($r->QuTargetNo));
    }
    if ($n > intval($limit) * 4) $out['more'] = true;
    // Where each one stands at the desk (one query for the page of results).
    if ($byAcc) {
        $rs = safe_r_sql("SELECT CkAccount, CkDivision, CkState FROM BookingChecks WHERE CkTournament = $tourId
            AND CkAccount IN (" . implode(',', array_map('StrSafe_DB', array_keys($byAcc))) . ")");
        $st = array();
        while ($r = safe_fetch($rs)) $st[(string) $r->CkAccount][(string) $r->CkDivision] = intval($r->CkState);
        foreach ($byAcc as $k => &$a) {
            $a['reg'] = intval($st[$k][''] ?? 0);
            $eq = $st[$k] ?? array();
            unset($eq['']);
            $a['equip'] = in_array(2, $eq, true) ? 2 : ($eq ? 1 : 0);
        }
        unset($a);
    }
    $out['rows'] = array_values($byAcc);
    return $out;
}

/* ------------------------------------------------------------------ */
/* An archer's file                                                    */
/* ------------------------------------------------------------------ */

/** What the federation's file says of a licence: ['state' => ok|absent|unknown|none|other, 'text']. */
function shp_desk_licence_info($code, $ioc)
{
    $code = (string) $code;
    if ($code === '') return array('state' => 'none', 'text' => shp_t('DkLicNone'));
    if (!aut_lic_ffta_code($code) || !in_array((string) $ioc, array('', 'FRA'), true)) {
        return array('state' => 'other', 'text' => shp_t('DkLicOther'));
    }
    $r = safe_fetch(safe_r_sql("SELECT LueStatus FROM LookUpEntries WHERE LueCode = " . StrSafe_DB($code) . " AND LueIocCode = 'FRA' LIMIT 1"));
    if ($r) {
        return array('state' => 'ok', 'text' => intval($r->LueStatus) === 9 ? shp_t('DkLicInFile9') : shp_t('DkLicInFile'));
    }
    if (!aut_lic_file_ok()) return array('state' => 'unknown', 'text' => shp_t('DkLicUnknown'));
    return array('state' => 'absent', 'text' => shp_t('DkLicAbsent'));
}

/** Journal of an account, newest first: [['kind', 'post', 'division', 'power', 'text', 'by', 'when']]. */
function shp_desk_journal($tourId, $account)
{
    $out = array();
    $rs = safe_r_sql("SELECT * FROM BookingCheckLog WHERE KlTournament = " . intval($tourId) . "
        AND KlAccount = " . StrSafe_DB($account) . " ORDER BY KlId DESC LIMIT 200");
    while ($r = safe_fetch($rs)) {
        $by = intval($r->KlStaff) > 0 ? shp_staff_label(intval($r->KlStaff)) : (string) $r->KlBy;
        $out[] = array('kind' => (string) $r->KlKind, 'post' => (string) $r->KlPost, 'division' => (string) $r->KlDivision,
            'power' => $r->KlPower === null ? null : (float) $r->KlPower, 'text' => (string) $r->KlText,
            'by' => $by, 'when' => bk_date_time((string) $r->KlWhen));
    }
    return $out;
}

/**
 * Everything the desk shows of an archer, for volunteer $me (what they may see and do), or null
 * when the account has no participant in the competition.
 */
function shp_desk_file($me, $account)
{
    $tourId = intval($me->SfTournament);
    $account = (string) $account;
    if (!shp_desk_account_ok($account)) return null;
    $entries = shp_desk_entries($tourId, $account);
    if (!$entries) return null;
    // An entry added since the last decision follows it (writes only when a status differs).
    if (!shp_impersonating() && shp_desk_states($tourId, $account)) {
        shp_desk_apply($tourId, $account);
        $entries = shp_desk_entries($tourId, $account);
    }
    $first = reset($entries);
    $sessions = shp_desk_sessions($tourId);
    $events = shp_desk_events($tourId);
    $states = shp_desk_states($tourId, $account);
    $journal = shp_desk_journal($tourId, $account);

    $list = array();
    $divs = array();
    foreach ($entries as $e) {
        $o = intval($e->QuSession);
        $evs = array();
        foreach ($e->Events as $ev) $evs[] = $events[$ev] ?? $ev;
        $list[] = array('id' => intval($e->EnId), 'session' => $o,
            'session_label' => $o > 0 ? ($sessions[$o]['label'] ?? bk_t('DepCap', $o)) : shp_t('DkNoSession'),
            'start' => $o > 0 ? ($sessions[$o]['start'] ?? '') : '',
            'target' => shp_desk_target($e->QuTargetNo), 'division' => (string) $e->EnDivision,
            'division_label' => (string) ($e->DivDescription ?: $e->EnDivision), 'class_label' => (string) ($e->ClDescription ?: $e->EnClass),
            'events' => $evs, 'status' => intval($e->EnStatus), 'status_label' => shp_desk_status_label($e->EnStatus));
        $d = (string) $e->EnDivision;
        if (!isset($divs[$d])) $divs[$d] = array('division' => $d, 'label' => (string) ($e->DivDescription ?: $d),
            'state' => intval($states[$d] ?? 0), 'powers' => array());
    }
    foreach ($journal as $j) {
        if ($j['kind'] === 'power' && isset($divs[$j['division']])) $divs[$j['division']]['powers'][] = $j;
    }
    $dob = (string) $first->EnDob;
    $file = array(
        'account' => $account, 'family' => (string) $first->EnFirstName, 'given' => (string) $first->EnName,
        'licence' => (string) $first->EnCode, 'dob' => preg_match('/^[12][0-9]{3}-/', $dob) ? bk_date_fr($dob) : '',
        'club_code' => (string) $first->CoCode, 'club_name' => (string) $first->CoName,
        'licence_info' => shp_desk_licence_info($first->EnCode, $first->EnIocCode),
        'status9' => (bool) array_filter($entries, function ($e) { return intval($e->EnStatus) === 9; }),
        'entries' => $list, 'reg' => intval($states[''] ?? 0), 'equip' => array_values($divs),
        'notes' => array_values(array_filter($journal, function ($j) { return $j['kind'] === 'note'; })),
        'history' => array_values(array_filter($journal, function ($j) { return in_array($j['kind'], array('ok', 'ko', 'undo'), true); })),
        'pay' => null,
    );
    if (shp_desk_can($me, 'pay') || shp_desk_can($me, 'reg')) {
        $a = bk_account($tourId, $account);
        $st = shp_account_state($tourId, $account);
        $file['pay'] = array('whole' => $st['whole'], 'reg' => $st['whole'] ? $a['reg'] : 0.0, 'shop' => $a['shp'],
            'due' => $st['due'], 'paid' => $st['paid'], 'remaining' => $st['remaining']);
    }
    return $file;
}

/* ------------------------------------------------------------------ */
/* Writing                                                             */
/* ------------------------------------------------------------------ */

/** One desk write at a time per competition (decision and status of an archer). False after 10 s. */
function shp_desk_lock($tourId)
{
    $r = safe_fetch(safe_w_sql("SELECT GET_LOCK(CONCAT('bkdesk:', DATABASE(), ':', " . intval($tourId) . "), 10) AS l"));
    return $r && intval($r->l) === 1;
}

function shp_desk_unlock($tourId)
{
    safe_w_sql("SELECT RELEASE_LOCK(CONCAT('bkdesk:', DATABASE(), ':', " . intval($tourId) . "))");
}

/** Was a line with this key already written (the same request sent again)? */
function shp_desk_seen($tourId, $idem)
{
    return (bool) safe_fetch(safe_w_sql("SELECT KlId FROM BookingCheckLog WHERE KlTournament = " . intval($tourId)
        . " AND KlIdem = " . StrSafe_DB($idem)));
}

/** Writes a journal line. Returns false when the key was already used (nothing written). */
function shp_desk_log($tourId, $account, $division, $post, $kind, $power, $text, $staffId, $idem)
{
    $tourId = intval($tourId);
    safe_w_sql("INSERT INTO BookingCheckLog SET KlTournament = $tourId, KlAccount = " . StrSafe_DB($account)
        . ", KlDivision = " . StrSafe_DB((string) $division) . ", KlPost = " . StrSafe_DB((string) $post)
        . ", KlKind = " . StrSafe_DB((string) $kind)
        . ", KlPower = " . ($power === null ? 'NULL' : StrSafe_DB(number_format((float) $power, 1, '.', '')))
        . ", KlText = " . StrSafe_DB(mb_substr((string) $text, 0, SHP_DESK_NOTE_MAX))
        . ", KlStaff = " . intval($staffId) . ", KlBy = " . StrSafe_DB(mb_substr(shp_pay_by($staffId), 0, 64))
        . ", KlWhen = " . shp_local_now_sql($tourId) . ", KlIdem = " . StrSafe_DB($idem), false, array(1062));
    return safe_w_affected_rows() > 0;
}

/** A note typed by a person: one line of plain text, control characters removed. */
function shp_desk_text($s)
{
    return trim((string) preg_replace('/[\p{C}\s]+/u', ' ', mb_substr((string) $s, 0, SHP_DESK_NOTE_MAX * 2)));
}

/** Common checks of a write: the account and, for the equipment, its weapon. Error array or null. */
function shp_desk_target_check($tourId, $account, $division)
{
    if (!shp_desk_account_ok($account)) return shp_err('account', 'DkErrArcher');
    $entries = shp_desk_entries($tourId, $account);
    if (!$entries) return shp_err('account', 'DkErrArcher');
    if ($division !== '') {
        foreach ($entries as $e) if ((string) $e->EnDivision === $division) return null;
        return shp_err('division', 'DkErrDivision');
    }
    return null;
}

/**
 * A decision of the desk on an archer: $division '' = documents (right reg), a division = the
 * equipment of that weapon (right equip). $decision 'ok', 'ko' or 'undo' (back to "not seen").
 * $note: optional reason, kept in the journal. Then the statuses follow (shp_desk_apply).
 * Sent again with the same key: done once. Returns ['error' => 0] or an error.
 */
function shp_desk_decide($me, $account, $division, $decision, $idem, $note = '')
{
    $tourId = intval($me->SfTournament);
    $account = (string) $account;
    $division = (string) $division;
    $post = $division === '' ? 'reg' : 'equip';
    if (!shp_desk_can($me, $post)) return shp_err('forbidden', 'DkErrForbidden');
    if (!in_array($decision, array('ok', 'ko', 'undo'), true)) return shp_err('bad_request', 'ShErrBadRequest');
    $idem = shp_pay_idem($idem);
    if ($idem === false) return shp_err('bad_request', 'ShErrBadRequest');
    if ($err = shp_desk_target_check($tourId, $account, $division)) return $err;
    if (!shp_desk_lock($tourId)) return shp_err('busy', 'DkErrBusy');
    try {
        if (shp_desk_seen($tourId, $idem)) return array('error' => 0, 'existing' => true);
        $key = "CkTournament = $tourId, CkAccount = " . StrSafe_DB($account) . ", CkDivision = " . StrSafe_DB($division);
        if ($decision === 'undo') {
            safe_w_sql("DELETE FROM BookingChecks WHERE CkTournament = $tourId AND CkAccount = " . StrSafe_DB($account)
                . " AND CkDivision = " . StrSafe_DB($division));
        } else {
            safe_w_sql("REPLACE INTO BookingChecks SET $key, CkState = " . ($decision === 'ok' ? 1 : 2)
                . ", CkStaff = " . intval($me->SfId) . ", CkWhen = " . shp_local_now_sql($tourId));
        }
        shp_desk_log($tourId, $account, $division, $post, $decision, null, shp_desk_text($note), intval($me->SfId), $idem);
        shp_desk_apply($tourId, $account);
    } finally {
        shp_desk_unlock($tourId);
    }
    return array('error' => 0);
}

/** A draw weight measured by a judge (pounds), kept with the others. Right equip. */
function shp_desk_power($me, $account, $division, $power, $idem)
{
    $tourId = intval($me->SfTournament);
    if (!shp_desk_can($me, 'equip')) return shp_err('forbidden', 'DkErrForbidden');
    $v = str_replace(',', '.', trim((string) $power));
    if (!preg_match('/^[0-9]{1,2}(\.[0-9])?$/', $v) || (float) $v <= 0 || (float) $v > SHP_DESK_POWER_MAX) {
        return shp_err('power', 'DkErrPower');
    }
    $idem = shp_pay_idem($idem);
    if ($idem === false) return shp_err('bad_request', 'ShErrBadRequest');
    if ($err = shp_desk_target_check($tourId, (string) $account, (string) $division)) return $err;
    if ((string) $division === '') return shp_err('division', 'DkErrDivision');
    shp_desk_log($tourId, (string) $account, (string) $division, 'equip', 'power', (float) $v, '', intval($me->SfId), $idem);
    return array('error' => 0);
}

/** A note on an archer, by the registry (reg) or a judge (equip). */
function shp_desk_note($me, $account, $post, $text, $idem)
{
    $tourId = intval($me->SfTournament);
    $post = (string) $post;
    if (!in_array($post, array('reg', 'equip'), true)) $post = shp_desk_can($me, 'reg') ? 'reg' : 'equip';
    if (!shp_desk_can($me, $post)) return shp_err('forbidden', 'DkErrForbidden');
    $text = shp_desk_text($text);
    if ($text === '') return shp_err('note', 'DkErrNote');
    $idem = shp_pay_idem($idem);
    if ($idem === false) return shp_err('bad_request', 'ShErrBadRequest');
    if ($err = shp_desk_target_check($tourId, (string) $account, '')) return $err;
    shp_desk_log($tourId, (string) $account, '', $post, 'note', null, $text, intval($me->SfId), $idem);
    return array('error' => 0);
}

/** Collects what an archer owes (registrations and stands, as the till settles an account). Right pay. */
function shp_desk_pay($me, $account, $method, $amount, $idem)
{
    if (!shp_desk_can($me, 'pay')) return shp_err('forbidden', 'ShPayErrRight');
    if (!isset(shp_desk_pay_methods()[(string) $method])) return shp_err('method', 'ShPayErrMethod');
    if (!shp_desk_account_ok($account) || !shp_desk_entries(intval($me->SfTournament), (string) $account)) {
        return shp_err('account', 'DkErrArcher');
    }
    return shp_pay_account(intval($me->SfTournament), (string) $account, $amount, (string) $method, intval($me->SfId), (string) $idem, 0);
}

/* ------------------------------------------------------------------ */
/* Lists                                                               */
/* ------------------------------------------------------------------ */

/**
 * Participants of the competition with where they stand at the desk, one row per entry, by
 * departure, target and name: ['account', 'entry', 'family', 'given', 'licence', 'club',
 * 'club_name', 'session', 'target', 'division', 'division_label', 'class', 'class_label',
 * 'events' => [codes], 'status', 'reg', 'equip', 'power' (last measured, or null), 'notes'].
 */
function shp_desk_rows($tourId)
{
    $tourId = intval($tourId);
    $rs = safe_r_sql("SELECT EnId, EnCode, EnFirstName, EnName, EnStatus, EnDivision, EnClass, CoCode, CoName,
            QuSession, QuTargetNo, DivDescription, ClDescription
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry
        LEFT JOIN Divisions ON DivTournament = EnTournament AND DivId = EnDivision
        LEFT JOIN Classes ON ClTournament = EnTournament AND ClId = EnClass
        WHERE EnTournament = $tourId AND EnAthlete = 1
        ORDER BY QuSession = 0, QuSession, QuTargetNo = '', QuTargetNo, EnFirstName, EnName");
    $rows = array();
    while ($r = safe_fetch($rs)) $rows[intval($r->EnId)] = $r;
    $ev = array();
    $rs = safe_r_sql("SELECT IndId, IndEvent FROM Individuals WHERE IndTournament = $tourId");
    while ($r = safe_fetch($rs)) $ev[intval($r->IndId)][] = (string) $r->IndEvent;
    $st = array();
    $rs = safe_r_sql("SELECT CkAccount, CkDivision, CkState FROM BookingChecks WHERE CkTournament = $tourId");
    while ($r = safe_fetch($rs)) $st[(string) $r->CkAccount][(string) $r->CkDivision] = intval($r->CkState);
    $power = array();
    $notes = array();
    $rs = safe_r_sql("SELECT KlAccount, KlDivision, KlKind, KlPower FROM BookingCheckLog
        WHERE KlTournament = $tourId AND KlKind IN ('power', 'note') ORDER BY KlId");
    while ($r = safe_fetch($rs)) {
        if ($r->KlKind === 'power') $power[(string) $r->KlAccount][(string) $r->KlDivision] = (float) $r->KlPower;
        else $notes[(string) $r->KlAccount] = ($notes[(string) $r->KlAccount] ?? 0) + 1;
    }
    $out = array();
    foreach ($rows as $id => $r) {
        $k = bk_account_key($r->EnCode, $id);
        $d = (string) $r->EnDivision;
        $out[] = array('account' => $k, 'entry' => $id, 'family' => (string) $r->EnFirstName, 'given' => (string) $r->EnName,
            'licence' => (string) $r->EnCode, 'club' => (string) $r->CoCode, 'club_name' => (string) $r->CoName,
            'session' => intval($r->QuSession), 'target' => shp_desk_target($r->QuTargetNo),
            'division' => $d, 'division_label' => (string) ($r->DivDescription ?: $d),
            'class' => (string) $r->EnClass, 'class_label' => (string) ($r->ClDescription ?: $r->EnClass),
            'events' => $ev[$id] ?? array(), 'status' => intval($r->EnStatus),
            'reg' => intval($st[$k][''] ?? 0), 'equip' => intval($st[$k][$d] ?? 0),
            'power' => $power[$k][$d] ?? null, 'notes' => intval($notes[$k] ?? 0));
    }
    return $out;
}

/**
 * The judges' view: entries whose equipment was not checked yet (no decision for that weapon),
 * participants still in the competition (not withdrawn, not accredited, licence allowing to
 * shoot). $f: ['session', 'division', 'class', 'event'] ('' = any). Each filter only offers the
 * values left once the OTHER filters are applied — a departure, weapon, class or event with
 * nobody left to check is not offered. Returns ['rows', 'facets' => [name => [[value, label,
 * count]]], 'left' (all entries still to check)].
 */
function shp_desk_todo($tourId, array $f)
{
    $tourId = intval($tourId);
    $all = array_values(array_filter(shp_desk_rows($tourId), function ($r) {
        return $r['equip'] === 0 && !in_array($r['status'], array(6, 7, 9), true);
    }));
    $sessions = shp_desk_sessions($tourId);
    $events = shp_desk_events($tourId);
    $want = array();
    foreach (array('session', 'division', 'class', 'event') as $k) $want[$k] = trim((string) ($f[$k] ?? ''));
    $match = function ($r, $k, $v) {
        if ($v === '') return true;
        if ($k === 'session') return (string) $r['session'] === $v;
        if ($k === 'event') return in_array($v, $r['events'], true);
        return (string) $r[$k] === $v;
    };
    $facets = array();
    foreach (array_keys($want) as $dim) {
        $opts = array();
        foreach ($all as $r) {
            $ok = true;
            foreach ($want as $k => $v) if ($k !== $dim && !$match($r, $k, $v)) { $ok = false; break; }
            if (!$ok) continue;
            if ($dim === 'event') {
                foreach ($r['events'] as $e) {
                    if (!isset($opts[$e])) $opts[$e] = array($e, $events[$e] ?? $e, 0);
                    $opts[$e][2]++;
                }
                continue;
            }
            if ($dim === 'session') {
                $v = (string) $r['session'];
                $l = $r['session'] > 0 ? ($sessions[$r['session']]['label'] ?? bk_t('DepCap', $r['session'])) : shp_t('DkNoSession');
            } else {
                $v = (string) $r[$dim];
                $l = $r[$dim . '_label'];
            }
            if (!isset($opts[$v])) $opts[$v] = array($v, $l, 0);
            $opts[$v][2]++;
        }
        // The value chosen stays offered even when nothing is left in it (it can be unticked).
        if ($want[$dim] !== '' && !isset($opts[$want[$dim]])) $opts[$want[$dim]] = array($want[$dim], $want[$dim], 0);
        if ($dim === 'session') ksort($opts, SORT_NUMERIC);
        elseif ($dim === 'event') {
            $order = array_flip(array_keys($events));
            uksort($opts, function ($a, $b) use ($order) { return ($order[$a] ?? 999) <=> ($order[$b] ?? 999) ?: strcmp($a, $b); });
        } else {
            uasort($opts, function ($a, $b) { return strcasecmp($a[1], $b[1]); });
        }
        $facets[$dim] = array_values($opts);
    }
    $rows = array();
    foreach ($all as $r) {
        $ok = true;
        foreach ($want as $k => $v) if (!$match($r, $k, $v)) { $ok = false; break; }
        if ($ok) $rows[] = $r;
    }
    return array('rows' => $rows, 'facets' => $facets, 'left' => count($all));
}
