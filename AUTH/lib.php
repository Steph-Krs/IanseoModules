<?php
/**
 * AUTH module — multi-account ianseo hosting (federation server).
 *
 * Library shared by:
 *  - the files deployed in Modules/Authentication/ (native ianseo hooks)
 *  - the module's pages (sharing, admin)
 *
 * WARNING: this file is included very early (from BlockFunction.php through
 * Common/BlockDefines.php) → definitions only, no side effect.
 *
 * Security (v1.1):
 *  - the session NEVER holds a reusable secret: AUTH_Pwd carries a random token per sign-in,
 *    stored hashed (SHA-256) in AuthSessions
 *  - expiry: 12 h of inactivity, 7 days absolute; individual or global revocation (password
 *    change/reset, deactivation, admin)
 *  - TOTP 2FA (RFC 6238), optional, MANDATORY for ADMIN accounts
 *  - anti brute force: 8 failures (password or TOTP) / 15 min per IP or identifier
 *  - database log + optional file (fail2ban)
 */

if (defined('AUT_LIB_LOADED')) return;
define('AUT_LIB_LOADED', true);

require_once __DIR__ . '/lang-lib.php';
require_once __DIR__ . '/names-lib.php';

define('AUT_ROLE_CLUB',  'CLUB');
define('AUT_ROLE_CD',    'CD');
define('AUT_ROLE_CR',    'CR');
define('AUT_ROLE_FED',   'FED');
define('AUT_ROLE_ADMIN', 'ADMIN');

define('AUT_SESSION_IDLE_H', 12);   // hours of inactivity before expiry
define('AUT_SESSION_ABS_D',  7);    // absolute lifetime in days
define('AUT_2FA_PENDING_S',  300);  // validity of the "password OK, code expected" step

function aut_roles() {
    return array(
        AUT_ROLE_CLUB  => aut_t('RoleClub'),
        AUT_ROLE_CD    => aut_t('RoleCD'),
        AUT_ROLE_CR    => aut_t('RoleCR'),
        AUT_ROLE_FED   => aut_t('RoleFED'),
        AUT_ROLE_ADMIN => aut_t('RoleADMIN'),
    );
}

function aut_module_dir() {
    return __DIR__;
}

function aut_is_localhost() {
    global $CFG;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return $ip == '127.0.0.1' || $ip == '::1' || in_array($ip, $CFG->ACLExcluded ?? array());
}

function aut_local_config() {
    static $cfg = null;
    if (is_null($cfg)) {
        $cfg = array();
        $f = aut_module_dir() . '/config.local.json';
        if (is_file($f)) {
            $cfg = json_decode(aut_json_strip_bom(file_get_contents($f)), true) ?: array();
        }
    }
    return $cfg;
}

/**
 * Strips a UTF-8 BOM at the head of a JSON text. Without it, a file saved by a Windows editor
 * (Notepad, PowerShell `Set-Content -Encoding utf8`…) makes json_decode fail and THE WHOLE local
 * configuration is silently ignored — the cron credentials included, with a misleading message
 * such as "credentials missing". A real trap, never obvious to diagnose: the file looks
 * perfectly valid.
 */
function aut_json_strip_bom($s) {
    $s = (string) $s;
    // bytes: the UTF-8 BOM is three raw bytes
    return (substr($s, 0, 3) === "\xEF\xBB\xBF") ? substr($s, 3) : $s;
}

/**
 * Timestamp of the cron logs, in the server's LOCAL time.
 *
 * ianseo forces PHP to UTC (config.php). Without this, the lines of our scripts show 1 to 2 h
 * apart from those of the system scripts (ianseo-maintenance-off…) IN THE SAME log file — a
 * time confusion already met on the test server. Override: config.local.json → "timezone".
 */
function aut_log_time($fmt = 'Y-m-d H:i:s') {
    static $tz = null;
    if ($tz === null) {
        $name = (string) (aut_local_config()['timezone'] ?? 'Europe/Paris');
        try { $tz = new DateTimeZone($name); } catch (\Throwable $e) { $tz = new DateTimeZone('UTC'); }
    }
    $d = new DateTime('now', $tz);
    return $d->format($fmt);
}

/**
 * Warning message BEFORE the nightly maintenance window, shown all the time during the N
 * minutes before it — so a user who is registering or typing does not land on a 503 page
 * without notice. Returns '' outside that range (so nearly always).
 *
 * The time is NOT read from the crontab (too fragile): it is declared in config.local.json →
 * "maintenance": {"notice": {"at": "03:15", "lead_minutes": 15}}. Without "at", the function
 * does nothing: no untimely message by default.
 *
 * LOCAL time required: ianseo forces PHP to UTC, a naive computation would announce the
 * maintenance 1 to 2 h off.
 */
function aut_maintenance_notice() {
    static $msg = null;
    if ($msg !== null) return $msg;
    $msg = '';

    $c = aut_local_config()['maintenance']['notice'] ?? array();
    $at = trim((string) ($c['at'] ?? ''));
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $at, $m)) return $msg;

    $lead = max(1, intval($c['lead_minutes'] ?? 15));
    try {
        $tz  = new DateTimeZone((string) (aut_local_config()['timezone'] ?? 'Europe/Paris'));
        $now = new DateTime('now', $tz);
        $start = new DateTime('now', $tz);
        $start->setTime(intval($m[1]), intval($m[2]), 0);
        // Time already past today → the next occurrence is tomorrow. During the maintenance
        // itself, Apache serves the 503 page: nothing to announce here.
        if ($start <= $now) $start->modify('+1 day');
        $left = (int) ceil(($start->getTimestamp() - $now->getTimestamp()) / 60);
        if ($left > $lead) return $msg;
    } catch (\Throwable $e) {
        return $msg;
    }

    $duration = trim((string) ($c['duration'] ?? ''));
    return aut_t('MaintNotice', array(
        'time'     => $at,
        'when'     => $left > 1 ? aut_t('MaintIn', $left) : aut_t('MaintImminent'),
        'duration' => $duration !== '' ? $duration : aut_t('MaintSomeMinutes'),
    ));
}

/* ------------------------------------------------------------------ */
/* Database schema                                                     */
/* ------------------------------------------------------------------ */

function aut_ensure_schema() {
    static $done = false;
    if ($done) return;
    $done = true;
    aut_table_names();   // before any CREATE: see names-lib.php
    if (!empty($_SESSION['_aut_schema_v10'])) return;

    $q = safe_r_sql("SHOW TABLES LIKE 'AuthUsers'");
    if (!safe_fetch($q)) {
        safe_w_sql("CREATE TABLE IF NOT EXISTS AuthUsers (
            AuId            INT AUTO_INCREMENT PRIMARY KEY,
            AuUsername      VARCHAR(64)  NOT NULL,
            AuPassword      VARCHAR(255) NOT NULL,
            AuName          VARCHAR(128) NOT NULL DEFAULT '',
            AuEmail         VARCHAR(128) NOT NULL DEFAULT '',
            AuRole          ENUM('CLUB','CD','CR','FED','ADMIN') NOT NULL DEFAULT 'CLUB',
            AuScope         VARCHAR(16)  NOT NULL DEFAULT '',
            AuActive        TINYINT      NOT NULL DEFAULT 1,
            AuMustChangePwd TINYINT      NOT NULL DEFAULT 1,
            AuTotpSecret    VARCHAR(64)  NOT NULL DEFAULT '',
            AuTotpEnabled   TINYINT      NOT NULL DEFAULT 0,
            AuTotpLastSlot  BIGINT       NOT NULL DEFAULT 0,
            AuStructs       TEXT         NULL,
            AuLastRole      VARCHAR(8)   NOT NULL DEFAULT '',
            AuLastScope     VARCHAR(16)  NOT NULL DEFAULT '',
            AuLastLogin     DATETIME     NULL,
            AuCreated       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY AuUsernameIdx (AuUsername)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        // migration v1 → v1.1
        $q = safe_r_sql("SHOW COLUMNS FROM AuthUsers LIKE 'AuTotpSecret'");
        if (!safe_fetch($q)) {
            safe_w_sql("ALTER TABLE AuthUsers
                ADD COLUMN AuTotpSecret   VARCHAR(64) NOT NULL DEFAULT '',
                ADD COLUMN AuTotpEnabled  TINYINT     NOT NULL DEFAULT 0,
                ADD COLUMN AuTotpLastSlot BIGINT      NOT NULL DEFAULT 0");
        }
        // migration v0.1.6 → v0.1.7: several views (SSO structures + last view)
        $q = safe_r_sql("SHOW COLUMNS FROM AuthUsers LIKE 'AuStructs'");
        if (!safe_fetch($q)) {
            safe_w_sql("ALTER TABLE AuthUsers
                ADD COLUMN AuStructs   TEXT        NULL,
                ADD COLUMN AuLastRole  VARCHAR(8)  NOT NULL DEFAULT '',
                ADD COLUMN AuLastScope VARCHAR(16) NOT NULL DEFAULT ''");
        }
    }

    // AuthShare = register of the competitions: owner (role + scope: club, CD, CR or FED) +
    // upward sharing flags. One row per competition.
    safe_w_sql("CREATE TABLE IF NOT EXISTS AuthShare (
        AsToCode     VARCHAR(50) NOT NULL PRIMARY KEY,
        AsOwnerRole  VARCHAR(8)  NOT NULL DEFAULT '',
        AsOwnerScope VARCHAR(16) NOT NULL DEFAULT '',
        AsOwnerUser  VARCHAR(64) NOT NULL DEFAULT '',
        AsShareCD    TINYINT NOT NULL DEFAULT 0,
        AsShareCR    TINYINT NOT NULL DEFAULT 0,
        AsShareFED   TINYINT NOT NULL DEFAULT 0,
        AsUpdated    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $q = safe_r_sql("SHOW COLUMNS FROM AuthShare LIKE 'AsOwnerScope'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AuthShare
            ADD COLUMN AsOwnerRole  VARCHAR(8)  NOT NULL DEFAULT '' AFTER AsToCode,
            ADD COLUMN AsOwnerScope VARCHAR(16) NOT NULL DEFAULT '' AFTER AsOwnerRole,
            ADD COLUMN AsOwnerUser  VARCHAR(64) NOT NULL DEFAULT '' AFTER AsOwnerScope");
    } else {
        $q = safe_r_sql("SHOW COLUMNS FROM AuthShare LIKE 'AsOwnerRole'");
        if (!safe_fetch($q)) {
            safe_w_sql("ALTER TABLE AuthShare
                ADD COLUMN AsOwnerRole VARCHAR(8) NOT NULL DEFAULT '' AFTER AsToCode");
            safe_w_sql("UPDATE AuthShare SET AsOwnerRole='CLUB' WHERE AsOwnerScope!='' AND AsOwnerRole=''");
        }
    }

    // downward sharing: clubs invited to access a competition
    // (several clubs possible; managed by the owner or an admin)
    safe_w_sql("CREATE TABLE IF NOT EXISTS AuthShareClub (
        AscToCode VARCHAR(50) NOT NULL,
        AscScope  VARCHAR(16) NOT NULL,
        AscAdded  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (AscToCode, AscScope),
        KEY AscScopeIdx (AscScope)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // claims of codes being created (cleaned at adoption or after 24 h)
    safe_w_sql("CREATE TABLE IF NOT EXISTS AuthClaim (
        CmCode  VARCHAR(50) NOT NULL PRIMARY KEY,
        CmRole  VARCHAR(8)  NOT NULL DEFAULT 'CLUB',
        CmScope VARCHAR(16) NOT NULL DEFAULT '',
        CmUser  VARCHAR(64) NOT NULL DEFAULT '',
        CmWhen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY CmUserIdx (CmUser)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $q = safe_r_sql("SHOW COLUMNS FROM AuthClaim LIKE 'CmRole'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AuthClaim ADD COLUMN CmRole VARCHAR(8) NOT NULL DEFAULT 'CLUB' AFTER CmCode");
    }

    safe_w_sql("CREATE TABLE IF NOT EXISTS AuthLog (
        AlId    INT AUTO_INCREMENT PRIMARY KEY,
        AlWhen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        AlUser  VARCHAR(64) NOT NULL DEFAULT '',
        AlIP    VARCHAR(45) NOT NULL DEFAULT '',
        AlEvent VARCHAR(32) NOT NULL,
        KEY AlWhenIdx (AlWhen),
        KEY AlUserIdx (AlUser)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS AuthSessions (
        AsnId        INT AUTO_INCREMENT PRIMARY KEY,
        AsnUser      INT NOT NULL,
        AsnTokenHash CHAR(64) NOT NULL,
        AsnRole      VARCHAR(8)  NOT NULL DEFAULT '',
        AsnScope     VARCHAR(16) NOT NULL DEFAULT '',
        AsnCreated   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        AsnLastSeen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        AsnIP        VARCHAR(45)  NOT NULL DEFAULT '',
        AsnUA        VARCHAR(160) NOT NULL DEFAULT '',
        UNIQUE KEY AsnTokenIdx (AsnTokenHash),
        KEY AsnUserIdx (AsnUser)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // migration v1.1 → v1.2 (role/scope per session, for the choice of SSO structure as on the
    // officers' space)
    $q = safe_r_sql("SHOW COLUMNS FROM AuthSessions LIKE 'AsnRole'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AuthSessions
            ADD COLUMN AsnRole  VARCHAR(8)  NOT NULL DEFAULT '' AFTER AsnTokenHash,
            ADD COLUMN AsnScope VARCHAR(16) NOT NULL DEFAULT '' AFTER AsnRole");
    }

    // v…: "from another account" observation (impersonation) — kept per session to survive
    // CreateTourSession (see aut_imp_*). JSON or NULL.
    $q = safe_r_sql("SHOW COLUMNS FROM AuthSessions LIKE 'AsnImp'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AuthSessions ADD COLUMN AsnImp TEXT NULL DEFAULT NULL AFTER AsnScope");
    }

    // v6: tickets (bugs / improvement requests) filed by the organisers, sorted by the server
    // administrator. TkScore = precision score computed when filed (favours well-described
    // requests).
    // v7: TkResponse = admin's answer visible to the author; TkChannel = origin
    // ('org' organiser / 'archer' competitor) — the author sees ONLY their own.
    safe_w_sql("CREATE TABLE IF NOT EXISTS AuthTickets (
        TkId       INT AUTO_INCREMENT PRIMARY KEY,
        TkCreated  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        TkUser     VARCHAR(64)  NOT NULL DEFAULT '',
        TkRole     VARCHAR(48)  NOT NULL DEFAULT '',
        TkChannel  VARCHAR(8)   NOT NULL DEFAULT 'org',
        TkKind     VARCHAR(10)  NOT NULL DEFAULT 'bug',
        TkTitle    VARCHAR(160) NOT NULL DEFAULT '',
        TkBody     TEXT NULL,
        TkExpected TEXT NULL,
        TkPage     VARCHAR(255) NOT NULL DEFAULT '',
        TkTour     VARCHAR(160) NOT NULL DEFAULT '',
        TkStatus   VARCHAR(12)  NOT NULL DEFAULT 'new',
        TkResponse TEXT NULL,
        TkScore    SMALLINT     NOT NULL DEFAULT 0,
        TkUpdated  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY TkCreatedIdx (TkCreated),
        KEY TkStatusIdx (TkStatus),
        KEY TkWhoIdx (TkChannel, TkUser)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // migration v6 → v7 for an existing table
    $q = safe_r_sql("SHOW COLUMNS FROM AuthTickets LIKE 'TkResponse'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AuthTickets
            ADD COLUMN TkChannel  VARCHAR(8) NOT NULL DEFAULT 'org' AFTER TkRole,
            ADD COLUMN TkResponse TEXT NULL AFTER TkStatus,
            ADD KEY TkWhoIdx (TkChannel, TkUser)");
    }
    // v8: competition concerned by the ticket ("Name (Code)" label), filled at creation when a
    // competition is selected (organiser: the open competition; archer: the page they come
    // from). Empty otherwise. Not editable afterwards.
    $q = safe_r_sql("SHOW COLUMNS FROM AuthTickets LIKE 'TkTour'");
    if (!safe_fetch($q)) {
        safe_w_sql("ALTER TABLE AuthTickets ADD COLUMN TkTour VARCHAR(160) NOT NULL DEFAULT '' AFTER TkPage");
    }

    // v10: payer trust index (trust-lib.php). AuthTrust = decisions taken by people: with
    // TuTournament = 0, the administrator's whitelist / forced level of a subject; with a
    // competition, the organiser's acceptance of that subject for it. Subject = "L:<licence>"
    // or "C:<club approval number>". Technical dates in UTC.
    safe_w_sql("CREATE TABLE IF NOT EXISTS AuthTrust (
        TuId         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        TuSubject    VARCHAR(27)  NOT NULL,
        TuTournament INT UNSIGNED NOT NULL DEFAULT 0,
        TuWhite      TINYINT      NOT NULL DEFAULT 0,
        TuForced     VARCHAR(8)   NOT NULL DEFAULT '',
        TuReason     VARCHAR(255) NOT NULL DEFAULT '',
        TuBy         VARCHAR(64)  NOT NULL DEFAULT '',
        TuUntil      DATE NULL,
        TuCreated    DATETIME NULL,
        TuUpdated    DATETIME NULL,
        UNIQUE KEY TuSubjectIdx (TuSubject, TuTournament),
        KEY TuTournamentIdx (TuTournament)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Incidents computed each night from the payments journal: one row per subject,
    // competition and kind ('unpaid', 'late', 'reject'). TnEnd = end of the competition,
    // TnSince = when it started to count, TnResolved = when a debt was settled (late).
    // TnChecked = last computation that confirmed it (the others are deleted).
    safe_w_sql("CREATE TABLE IF NOT EXISTS AuthTrustEvents (
        TnId         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        TnSubject    VARCHAR(27)  NOT NULL,
        TnTournament INT UNSIGNED NOT NULL,
        TnKind       VARCHAR(8)   NOT NULL,
        TnAmount     DECIMAL(9,2) NOT NULL DEFAULT 0,
        TnDays       SMALLINT     NOT NULL DEFAULT 0,
        TnCount      SMALLINT     NOT NULL DEFAULT 1,
        TnEnd        DATE NOT NULL,
        TnSince      DATE NOT NULL,
        TnResolved   DATE NULL,
        TnCreated    DATETIME NULL,
        TnChecked    DATETIME NULL,
        UNIQUE KEY TnKeyIdx (TnSubject, TnTournament, TnKind),
        KEY TnTournamentIdx (TnTournament),
        KEY TnCheckedIdx (TnChecked)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $_SESSION['_aut_schema_v10'] = true;
}

/* ------------------------------------------------------------------ */
/* Utilisateurs / journal                                              */
/* ------------------------------------------------------------------ */

function aut_get_user($username) {
    aut_ensure_schema();
    $q = safe_r_sql("SELECT * FROM AuthUsers WHERE AuUsername=" . StrSafe_DB($username));
    $r = safe_fetch($q);
    return $r ?: null;
}

function aut_log($event, $user = '', $ip = null) {
    aut_ensure_schema();
    if (is_null($ip)) $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    safe_w_sql("INSERT INTO AuthLog (AlEvent, AlUser, AlIP) VALUES ("
        . StrSafe_DB($event) . "," . StrSafe_DB(mb_substr($user, 0, 64)) . "," . StrSafe_DB(mb_substr($ip, 0, 45)) . ")");

    // optional file for fail2ban ({"log_file": "/var/log/ianseo-auth.log"})
    $f = aut_local_config()['log_file'] ?? '';
    if ($f) {
        $clean = function ($s) { return preg_replace('/[^\x20-\x7E]/', '', (string)$s); };
        @file_put_contents($f,
            date('Y-m-d H:i:s') . ' ianseo-auth ' . $clean($ip) . ' ' . $clean($user) . ' ' . $clean($event) . "\n",
            FILE_APPEND | LOCK_EX);
    }
}

/* ------------------------------------------------------------------ */
/* Retention of the logs (AuthLog + BookingLog)                            */
/*                                                                     */
/* Without a purge, the logs grow forever (at federation scale:        */
/* millions of rows a year) and the GDPR note "kept a few months"      */
/* would be false. Configurable length, 180 days by default.           */
/* Security (anti brute force) ONLY uses the 15-minute window → not    */
/* affected. Chunked (LIMIT) to avoid one massive deletion; repeated   */
/* runs catch up.                                                      */
/* ------------------------------------------------------------------ */

/** Retention of the logs in days (config.local.json → "log_retention_days", default 180). */
function aut_log_retention_days() {
    $d = intval(aut_local_config()['log_retention_days'] ?? 180);
    return ($d >= 7 && $d <= 3650) ? $d : 180;   // bounds: 1 week to 10 years; otherwise the default
}

/** Deletes the events older than the retention (AuthLog, and BookingLog when there). */
function aut_log_purge() {
    $days = aut_log_retention_days();
    safe_w_sql("DELETE FROM AuthLog WHERE AlWhen < DATE_SUB(NOW(), INTERVAL $days DAY) LIMIT 20000");
    $r = safe_fetch(safe_r_sql("SELECT 1 AS x FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BookingLog'"));
    if ($r) safe_w_sql("DELETE FROM BookingLog WHERE BlWhen < DATE_SUB(NOW(), INTERVAL $days DAY) LIMIT 20000");
    // Satisfaction survey: the licence is only needed while the survey is open.
    $sv = __DIR__ . '/booking/lib/survey.php';
    if (is_file($sv)) {
        require_once $sv;
        if (function_exists('bk_survey_anonymise')) bk_survey_anonymise();
    }
    // Waiting lists of competitions over or deleted: a licence and choices with no use left.
    // Plain SQL on purpose: booking/lib/waitlist.php pulls core files in, and this purge
    // runs from the bootstrap of any page. A day's margin spares the time-zone question.
    $wl = safe_fetch(safe_r_sql("SELECT 1 AS x FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BookingWaitlist'"));
    if ($wl) safe_w_sql("DELETE BookingWaitlist FROM BookingWaitlist LEFT JOIN Tournament ON ToId = BwTournament
        WHERE ToId IS NULL OR ToWhenTo < DATE_SUB(UTC_DATE(), INTERVAL 1 DAY)");
    // Audience measurement: UsageSeen follows the log retention, aggregates 25 months.
    require_once __DIR__ . '/stats-usage.php';
    if (function_exists('aut_stats_purge')) aut_stats_purge();
}

/** Purges AT MOST once a day (file marker), not to do it again at every request. */
function aut_log_purge_daily() {
    // bytes: tokens and hashes are ASCII hex
    $marker = sys_get_temp_dir() . '/aut_logpurge_' . substr(hash('sha256', __DIR__), 0, 16);
    $today  = date('Y-m-d');
    if (is_file($marker) && trim((string) @file_get_contents($marker)) === $today) return;
    @file_put_contents($marker, $today);   // marks before purging: one try a day even if the purge fails
    aut_log_purge();
}

/* ------------------------------------------------------------------ */
/* Tickets (bugs / improvement requests)                               */
/* ------------------------------------------------------------------ */

/** Types of tickets. */
function aut_ticket_kinds() {
    return array('bug' => aut_t('TicketBug'), 'evolution' => aut_t('TicketEvolution'));
}

/** Ticket statuses. The author can only edit while 'new'. */
function aut_ticket_statuses() {
    return array('new' => aut_t('TicketNew'), 'in_progress' => aut_t('TicketInProgress'),
                 'done' => aut_t('TicketDone'), 'rejected' => aut_t('TicketRejected'));
}

/** May its author edit a ticket? ('new' status + ownership). */
function aut_ticket_editable($t, $user, $channel) {
    if (!$t) return false;
    $channel = ($channel === 'archer') ? 'archer' : 'org';
    return $t->TkStatus === 'new' && $t->TkChannel === $channel
        && $t->TkUser === mb_substr((string) $user, 0, 64);
}

/**
 * Precision score (0-100): favours well-described requests — explicit title, detailed body,
 * "how / expected result" filled in.
 */
function aut_ticket_score($title, $body, $expected, $page) {
    // bytes: only when mbstring is missing, a rough count is good enough for a score
    $len = function ($s) { return function_exists('mb_strlen') ? mb_strlen(trim((string) $s)) : strlen(trim((string) $s)); };
    $s = 0;
    if ($len($title) >= 6) $s += 10;
    $s += min(35, intdiv($len($body), 6));
    if (trim((string) $expected) !== '') $s += 20;
    $s += min(25, intdiv($len($expected), 6));
    if (trim((string) $page) !== '') $s += 10;
    return max(0, min(100, $s));
}

/** Files a ticket. $channel: 'org' (organiser) or 'archer' (competitor).
 *  $tour: label of the competition concerned ("Name (Code)"), empty when none. */
function aut_ticket_add($kind, $title, $body, $expected, $page, $user, $role, $channel = 'org', $tour = '') {
    aut_ensure_schema();
    $kind = array_key_exists($kind, aut_ticket_kinds()) ? $kind : 'bug';
    $channel = ($channel === 'archer') ? 'archer' : 'org';
    $score = aut_ticket_score($title, $body, $expected, $page);
    safe_w_sql("INSERT INTO AuthTickets (TkUser, TkRole, TkChannel, TkKind, TkTitle, TkBody, TkExpected, TkPage, TkTour, TkScore)
        VALUES (" . StrSafe_DB(mb_substr((string) $user, 0, 64)) . ","
        . StrSafe_DB(mb_substr((string) $role, 0, 48)) . ","
        . StrSafe_DB($channel) . ","
        . StrSafe_DB($kind) . ","
        . StrSafe_DB(mb_substr(trim((string) $title), 0, 160)) . ","
        . StrSafe_DB(mb_substr((string) $body, 0, 5000)) . ","
        . StrSafe_DB(mb_substr((string) $expected, 0, 5000)) . ","
        . StrSafe_DB(mb_substr((string) $page, 0, 255)) . ","
        . StrSafe_DB(mb_substr((string) $tour, 0, 160)) . ","
        . intval($score) . ")");
    aut_log('TICKET_NEW', $user);
}

/** List of the tickets, sorted by date ('date') or precision ('score'), filtered by status. */
function aut_ticket_list($sort = 'date', $status = '') {
    aut_ensure_schema();
    $w = array_key_exists($status, aut_ticket_statuses()) ? "WHERE TkStatus = " . StrSafe_DB($status) : "";
    $order = ($sort === 'score') ? "TkScore DESC, TkCreated DESC" : "TkCreated DESC";
    $q = safe_r_sql("SELECT * FROM AuthTickets $w ORDER BY $order");
    $out = array();
    while ($r = safe_fetch($q)) $out[] = $r;
    return $out;
}

/** Tickets of an author (so they follow theirs and the answers). */
function aut_ticket_my($user, $channel = 'org') {
    aut_ensure_schema();
    $channel = ($channel === 'archer') ? 'archer' : 'org';
    $q = safe_r_sql("SELECT * FROM AuthTickets
        WHERE TkChannel = " . StrSafe_DB($channel) . "
          AND TkUser = " . StrSafe_DB(mb_substr((string) $user, 0, 64)) . "
        ORDER BY TkCreated DESC");
    $out = array();
    while ($r = safe_fetch($q)) $out[] = $r;
    return $out;
}

/** Admin's answer to the author (visible to them). */
function aut_ticket_set_response($id, $text) {
    aut_ensure_schema();
    safe_w_sql("UPDATE AuthTickets SET TkResponse = " . StrSafe_DB(mb_substr((string) $text, 0, 5000))
        . " WHERE TkId = " . intval($id));
}

/** A ticket by id, or null. */
function aut_ticket_get($id) {
    aut_ensure_schema();
    return safe_fetch(safe_r_sql("SELECT * FROM AuthTickets WHERE TkId = " . intval($id))) ?: null;
}

/**
 * A ticket edited by ITS author. Refuses (returns false) when the ticket does not belong to
 * ($user, $channel) or is no longer 'new' (taken in hand/closed) — guard checked HERE, never
 * left to the client. Computes the precision score again.
 */
function aut_ticket_update($id, $user, $channel, $kind, $title, $body, $expected, $page) {
    aut_ensure_schema();
    $id = intval($id);
    $t = aut_ticket_get($id);
    if (!aut_ticket_editable($t, $user, $channel)) return false;
    $kind = array_key_exists($kind, aut_ticket_kinds()) ? $kind : 'bug';
    $score = aut_ticket_score($title, $body, $expected, $page);
    safe_w_sql("UPDATE AuthTickets SET
        TkKind = " . StrSafe_DB($kind) . ",
        TkTitle = " . StrSafe_DB(mb_substr(trim((string) $title), 0, 160)) . ",
        TkBody = " . StrSafe_DB(mb_substr((string) $body, 0, 5000)) . ",
        TkExpected = " . StrSafe_DB(mb_substr((string) $expected, 0, 5000)) . ",
        TkPage = " . StrSafe_DB(mb_substr((string) $page, 0, 255)) . ",
        TkScore = " . intval($score) . "
        WHERE TkId = $id");
    return true;
}

/** Changes the status of a ticket. */
function aut_ticket_set_status($id, $status) {
    aut_ensure_schema();
    if (!array_key_exists($status, aut_ticket_statuses())) return;
    safe_w_sql("UPDATE AuthTickets SET TkStatus = " . StrSafe_DB($status) . " WHERE TkId = " . intval($id));
}

/** Deletes a ticket. */
function aut_ticket_delete($id) {
    aut_ensure_schema();
    safe_w_sql("DELETE FROM AuthTickets WHERE TkId = " . intval($id));
}

/** Counts per status (+ 'all'). */
function aut_ticket_counts() {
    aut_ensure_schema();
    $c = array('new' => 0, 'in_progress' => 0, 'done' => 0, 'rejected' => 0, 'all' => 0);
    $q = safe_r_sql("SELECT TkStatus, COUNT(*) n FROM AuthTickets GROUP BY TkStatus");
    while ($r = safe_fetch($q)) { if (isset($c[$r->TkStatus])) $c[$r->TkStatus] = intval($r->n); $c['all'] += intval($r->n); }
    return $c;
}

/** Counts the password AND TOTP failures of the last 15 minutes. */
function aut_too_many_failures($username) {
    aut_ensure_schema();
    $ip = StrSafe_DB($_SERVER['REMOTE_ADDR'] ?? '');
    $un = StrSafe_DB($username);
    $q = safe_r_sql("SELECT COUNT(*) AS n FROM AuthLog
        WHERE AlEvent IN ('LOGIN_FAIL','TOTP_FAIL') AND AlWhen > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        AND (AlIP=$ip OR AlUser=$un)");
    $r = safe_fetch($q);
    return $r && $r->n >= 8;
}

function aut_password_ok($pwd) {
    return strlen($pwd) >= 10 && preg_match('/[a-z]/i', $pwd) && preg_match('/[0-9]/', $pwd);
}

function aut_gen_password($len = 12) {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    // bytes: the password alphabet is ASCII
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

/**
 * Format of the federation approval numbers: LLDDCCC (league 2 + department 2 + club 3),
 * e.g. 0760171 = league 07, dept 60, club 171. Hence:
 *  - CLUB: scope = full approval number (prefix of the competition codes)
 *  - CD  : scope = 2 digits of the department → codes LIKE '__DD%'
 *  - CR  : scope = 2 digits of the league     → codes LIKE 'LL%'
 * A scope containing % or _ is used as is as a LIKE pattern (overseas or unusual areas, set
 * by an admin).
 */
function aut_scope_error($role, $scope) {
    if (in_array($role, array(AUT_ROLE_FED, AUT_ROLE_ADMIN))) return '';
    if (preg_match('/^[0-9A-Za-z_%]{2,12}$/', $scope) && preg_match('/[_%]/', $scope)) return '';
    if (!preg_match('/^[0-9A-Za-z]{2,10}$/', $scope)) {
        return aut_t('ScopeBadChars');
    }
    // bytes: the scope was just checked to be ASCII letters and digits
    if ($role == AUT_ROLE_CLUB && strlen($scope) < 5) return aut_t('ScopeClub');
    if ($role == AUT_ROLE_CD && strlen($scope) != 2)  return aut_t('ScopeCD');
    if ($role == AUT_ROLE_CR && strlen($scope) != 2)  return aut_t('ScopeCR');
    return '';
}

/** LIKE pattern of the competition codes covered by a role/scope. */
function aut_scope_like($role, $scope) {
    if (preg_match('/[_%]/', $scope)) return $scope;           // expert pattern as is
    if ($role == AUT_ROLE_CD) return '__' . $scope . '%';      // dept in position 3-4
    return $scope . '%';                                       // CLUB / CR: prefix
}

/**
 * Federation tree: region (2 digits, = league prefix of the approval numbers and number of
 * the CR) → its departments (dept number on 2 characters). Used to find the CDs of a league (a
 * CD's scope is its dept number, without league prefix). Editable through config.local.json →
 * "regions" (merged). Overseas CRs without a listed CD only attach the clubs (by prefix).
 */
function aut_ffta_regions() {
    static $map = null;
    if (!is_null($map)) return $map;
    $map = array(
        '01' => array('69','01','03','07','43','42','26','15','74','38','63','73'), // AURA
        '02' => array('21','25','39','70','58','71','90','89'),                     // Bourgogne-FC
        '03' => array('22','29','35','56'),                                         // Bretagne
        '04' => array('18','28','36','37','41','45'),                               // Centre-Val de Loire
        '05' => array('2A','2B'),                                                   // Corse
        '06' => array('08','10','67','52','51','54','55','57','88','68'),           // Grand Est
        '07' => array('02','59','60','62','80'),                                    // Hauts-de-France
        '08' => array('91','92','75','77','93','95','94','78'),                     // Ile-de-France
        '09' => array('14','27','61','50','76'),                                    // Normandie
        '10' => array('16','17','19','23','79','24','33','87','40','47','64','86'), // Nouvelle-Aquitaine
        '11' => array('11','12','48','30','32','31','65','34','46','66','81','82'), // Occitanie
        '12' => array('85','44','49','53','72'),                                    // Pays de la Loire
        '13' => array('04','06','13','83','84'),                                    // PACA
        '38' => array('992'),                                                       // New Caledonia
    );
    foreach ((aut_local_config()['regions'] ?? array()) as $reg => $depts) {
        if (is_array($depts)) $map[$reg] = $depts;
    }
    return $map;
}

/** Departments of a region (empty when unknown). */
function aut_region_depts($region) {
    $m = aut_ffta_regions();
    return $m[$region] ?? array();
}

/* ------------------------------------------------------------------ */
/* TOTP (RFC 6238) — no external dependency                            */
/* ------------------------------------------------------------------ */

function aut_base32_decode($b32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    $out = '';
    // bytes: base32 text and binary HMAC output
    foreach (str_split($b32) as $c) {
        $v = strpos($alphabet, $c);
        if ($v === false) continue;
        // bytes: base32 text and binary HMAC output
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    foreach (str_split($bits, 8) as $byte) {
        // bytes: base32 text and binary HMAC output
        if (strlen($byte) == 8) $out .= chr(bindec($byte));
    }
    return $out;
}

function aut_totp_new_secret() {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = '';
    for ($i = 0; $i < 32; $i++) $s .= $alphabet[random_int(0, 31)];
    return $s;
}

function aut_totp_code($secretB32, $slot) {
    $key = aut_base32_decode($secretB32);
    $bin = pack('N', 0) . pack('N', $slot);
    $hash = hash_hmac('sha1', $bin, $key, true);
    // bytes: base32 text and binary HMAC output
    $offset = ord(substr($hash, -1)) & 0x0F;
    $code = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

/**
 * Checks a code (window ±1 step of 30 s). $minSlot = last slot already used (anti-replay);
 * $usedSlot receives the accepted slot.
 */
function aut_totp_verify($secretB32, $code, $minSlot, &$usedSlot) {
    $code = preg_replace('/\D/', '', (string)$code);
    // bytes: a TOTP code is ASCII digits
    if (strlen($code) != 6 || $secretB32 === '') return false;
    $slot = (int)floor(time() / 30);
    foreach (array(0, -1, 1) as $d) {
        $s = $slot + $d;
        if ($s > $minSlot && hash_equals(aut_totp_code($secretB32, $s), $code)) {
            $usedSlot = $s;
            return true;
        }
    }
    return false;
}

/**
 * DIAGNOSIS (never an acceptance). A correct TOTP code refused nearly always comes from a
 * wrong SERVER CLOCK: the phone computes the code from the real time, the server from its own.
 * After a failure, the offset at which the code WOULD have matched is looked for in a wide
 * window (±$maxSlots steps of 30 s), to turn a puzzling "wrong code" into a clear diagnosis
 * ("clock off by ~N min, synchronise NTP"). Returns the offset in SECONDS (slot found − current
 * slot), or null. NEVER accepts: the check stays aut_totp_verify (window ±1). ±120 steps = ±1 h.
 */
function aut_totp_skew($secretB32, $code, $maxSlots = 120) {
    $code = preg_replace('/\D/', '', (string)$code);
    // bytes: a TOTP code is ASCII digits
    if (strlen($code) != 6 || (string)$secretB32 === '') return null;
    $slot = (int)floor(time() / 30);
    for ($d = -$maxSlots; $d <= $maxSlots; $d++) {
        if (abs($d) <= 1) continue;   // the normal window has already decided
        if (hash_equals(aut_totp_code($secretB32, $slot + $d), $code)) return $d * 30;
    }
    return null;
}

function aut_totp_uri($username, $secret) {
    $issuer = rawurlencode('ianseo FFTA');
    return 'otpauth://totp/' . $issuer . ':' . rawurlencode($username)
        . '?secret=' . $secret . '&issuer=' . $issuer . '&digits=6&period=30';
}

/**
 * QR code (inline SVG) of a text, through the TCPDF QR encoder already shipped with ianseo —
 * no external dependency, the secret never leaves the server. Returns '' when the encoder is
 * unavailable (manual entry as a fallback).
 */
function aut_qr_svg($text, $sizePx = 210) {
    global $CFG;
    $file = $CFG->DOCUMENT_PATH . 'Common/tcpdf/include/barcodes/qrcode.php';
    if (!is_file($file)) return '';
    require_once($file);
    try {
        $qr = new QRcode($text, 'M');
        $arr = $qr->getBarcodeArray();
    } catch (\Throwable $e) {
        return '';
    }
    if (empty($arr['num_rows']) || empty($arr['bcode'])) return '';
    $rows = $arr['num_rows'];
    $cols = $arr['num_cols'];
    $margin = 4;
    $dim = max($rows, $cols) + 2 * $margin;
    $path = '';
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            if (!empty($arr['bcode'][$r][$c])) {
                $path .= 'M' . ($c + $margin) . ' ' . ($r + $margin) . 'h1v1h-1z';
            }
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $sizePx . '" height="' . $sizePx . '"'
        . ' viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img" aria-label="QR code 2FA">'
        . '<rect width="' . $dim . '" height="' . $dim . '" fill="#fff"/>'
        . '<path fill="#000" d="' . $path . '"/></svg>';
}

/* ------------------------------------------------------------------ */
/* Application sessions (revocable tokens)                             */
/* ------------------------------------------------------------------ */

/**
 * Opens a session: random token stored hashed in the database, plain value only in the PHP
 * session (AUTH_Pwd key — the only key, with AUTH_User, that survives the core's
 * CreateTourSession/EraseTourSession). $role/$scope: structure chosen at SSO sign-in
 * (otherwise those of the account).
 */
function aut_session_open($u, $role = '', $scope = '') {
    $token = bin2hex(random_bytes(32));
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 160);
    safe_w_sql("INSERT INTO AuthSessions (AsnUser, AsnTokenHash, AsnRole, AsnScope, AsnIP, AsnUA) VALUES (
        {$u->AuId}, '" . hash('sha256', $token) . "',"
        . StrSafe_DB($role) . "," . StrSafe_DB($scope) . ","
        . StrSafe_DB(mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)) . "," . StrSafe_DB($ua) . ")");
    // opportunistic cleaning of dead sessions
    safe_w_sql("DELETE FROM AuthSessions WHERE AsnLastSeen < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $_SESSION['AUTH_User'] = $u->AuUsername;
    $_SESSION['AUTH_Pwd']  = $token;
    aut_extranet_bind();    // the extranet cookie opened at sign-in takes its final path
    aut_dirigeant_bind();   // same for the officers' space cookie captured from the SSO sign-in
    aut_session_apply($u, $role, $scope);
}

/**
 * Checks the current token. Returns the database session object or null.
 * Expiry computed in SQL (NOW()) to avoid time-zone offsets (ianseo changes the MySQL
 * time_zone per competition).
 */
function aut_session_validate($u) {
    $token = (string)($_SESSION['AUTH_Pwd'] ?? '');
    // bytes: tokens and hashes are ASCII hex
    if ($token === '' || strlen($token) != 64) return null;
    $hash = hash('sha256', $token);
    $q = safe_r_sql("SELECT *,
            (AsnCreated  < DATE_SUB(NOW(), INTERVAL " . AUT_SESSION_ABS_D . " DAY)
          OR AsnLastSeen < DATE_SUB(NOW(), INTERVAL " . AUT_SESSION_IDLE_H . " HOUR)) AS expired,
            (AsnLastSeen < DATE_SUB(NOW(), INTERVAL 1 MINUTE)) AS stale
        FROM AuthSessions WHERE AsnTokenHash='$hash' AND AsnUser={$u->AuId}");
    $s = safe_fetch($q);
    if (!$s) return null;
    if ($s->expired) {
        safe_w_sql("DELETE FROM AuthSessions WHERE AsnId={$s->AsnId}");
        return null;
    }
    if ($s->stale) {
        safe_w_sql("UPDATE AuthSessions SET AsnLastSeen=NOW(),
            AsnIP=" . StrSafe_DB(mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)) . " WHERE AsnId={$s->AsnId}");
    }
    return $s;
}

/** Revokes the sessions of a user ($exceptTokenHash: keep the current one). */
function aut_sessions_revoke($userId, $exceptTokenHash = null) {
    $userId = intval($userId);
    $sql = "DELETE FROM AuthSessions WHERE AsnUser=$userId";
    if ($exceptTokenHash && preg_match('/^[0-9a-f]{64}$/', $exceptTokenHash)) {
        $sql .= " AND AsnTokenHash != '$exceptTokenHash'";
    }
    safe_w_sql($sql);
}

function aut_current_token_hash() {
    $token = (string)($_SESSION['AUTH_Pwd'] ?? '');
    return $token !== '' ? hash('sha256', $token) : '';
}

/* ------------------------------------------------------------------ */
/* Session / droits                                                    */
/* ------------------------------------------------------------------ */

/**
 * AUTH_COMP list in the format the ianseo core expects (exact codes).
 * Competition naming is FREE: ownership comes from the AuthShare register (role + scope of the
 * creator: club, CD, CR or FED).
 *  - each one sees the competitions they OWN;
 *  - CLUB: + those where their approval number is INVITED (AuthShareClub — downward sharing
 *    from a CD/CR/FED or another club, e.g. help with data entry);
 *  - CD: + shared competitions of CLUBS of the department (AsShareCD);
 *  - CR: + shared competitions of CLUBS of the league (approval prefix) AND of CDs of the
 *    league (through the dept→region tree) (AsShareCR);
 *  - FED: + every competition shared with the federation.
 */
function aut_compute_comp($role, $scope) {
    if (!in_array($role, array(AUT_ROLE_CLUB, AUT_ROLE_CD, AUT_ROLE_CR, AUT_ROLE_FED))) return array();

    $owned = "(AsOwnerRole=" . StrSafe_DB($role) . " AND AsOwnerScope=" . StrSafe_DB($scope) . ")";
    if ($role == AUT_ROLE_CLUB) {
        $where = $owned;
    } elseif ($role == AUT_ROLE_FED) {
        $where = "($owned OR AsShareFED=1)";
    } elseif ($role == AUT_ROLE_CD) {
        $where = "($owned OR (AsShareCD=1 AND AsOwnerRole='CLUB' AND AsOwnerScope LIKE "
            . StrSafe_DB(aut_scope_like($role, $scope)) . "))";
    } else { // CR: clubs of the league (prefix) + CDs of the departments of the league
        $parts = array($owned);
        $parts[] = "(AsShareCR=1 AND AsOwnerRole='CLUB' AND AsOwnerScope LIKE "
            . StrSafe_DB(aut_scope_like($role, $scope)) . ")";
        $depts = aut_region_depts($scope);
        if ($depts) {
            $in = implode(',', array_map('StrSafe_DB', $depts));
            $parts[] = "(AsShareCR=1 AND AsOwnerRole='CD' AND AsOwnerScope IN ($in))";
        }
        $where = '(' . implode(' OR ', $parts) . ')';
    }

    $codes = array();
    $q = safe_r_sql("SELECT ToCode FROM Tournament
        INNER JOIN AuthShare ON AsToCode COLLATE utf8mb4_unicode_ci = ToCode
        WHERE $where");
    while ($r = safe_fetch($q)) $codes[$r->ToCode] = true;

    if ($role == AUT_ROLE_CLUB) {
        $q = safe_r_sql("SELECT ToCode FROM Tournament
            INNER JOIN AuthShareClub ON AscToCode COLLATE utf8mb4_unicode_ci = ToCode
            WHERE AscScope=" . StrSafe_DB($scope));
        while ($r = safe_fetch($q)) $codes[$r->ToCode] = true;
    }
    return array_keys($codes);
}

/**
 * State of a competition code for a structure (role + scope):
 * 'free' (available), 'own' (its competition), 'other' (another structure),
 * 'unowned' (exists without an owner → admin), 'invalid'.
 * Purges on the way the orphan register row of a deleted competition.
 */
function aut_code_status($code, $role, $scope) {
    aut_ensure_schema();
    $code = trim($code);
    if ($code === '') return 'invalid';

    $q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToCode=" . StrSafe_DB($code));
    $exists = (bool)safe_fetch($q);
    $q = safe_r_sql("SELECT AsOwnerRole, AsOwnerScope FROM AuthShare WHERE AsToCode=" . StrSafe_DB($code));
    $own = safe_fetch($q);

    if (!$exists) {
        if ($own) {
            safe_w_sql("DELETE FROM AuthShare WHERE AsToCode=" . StrSafe_DB($code));
            safe_w_sql("DELETE FROM AuthShareClub WHERE AscToCode=" . StrSafe_DB($code));
        }
        return 'free';
    }
    if (!$own || $own->AsOwnerRole === '') return 'unowned';
    return (strcasecmp($own->AsOwnerRole, $role) === 0 && strcasecmp($own->AsOwnerScope, $scope) === 0)
        ? 'own' : 'other';
}

/** User message for a refused code. */
function aut_code_reason($state, $code, $isImport = false) {
    $c = htmlspecialchars($code);
    switch ($state) {
        case 'invalid':
            return aut_t('CodeInvalid');
        case 'other':
            return aut_t('CodeOther', $c);
        case 'unowned':
            return aut_t('CodeUnowned', $c);
        case 'own':
            return $isImport ? '' : aut_t('CodeOwn', $c);
    }
    return '';
}

/**
 * May a structure (club, CD, CR, FED) create/import a competition under this code? Anti-overwrite
 * rule: a code already carried by an existing competition is NEVER reusable by another
 * structure; the owner may only reuse it by re-import (restoring their own backup), not by a
 * new creation. Free code → claim recorded, ownership is settled by aut_adopt_claims() once the
 * competition is created. $reason receives the message on refusal.
 */
function aut_can_use_code($code, $role, $scope, $user, $isImport, &$reason = '') {
    $state = aut_code_status($code, $role, $scope);
    if ($state == 'free') {
        safe_w_sql("INSERT INTO AuthClaim (CmCode, CmRole, CmScope, CmUser) VALUES ("
            . StrSafe_DB(trim($code)) . "," . StrSafe_DB($role) . "," . StrSafe_DB($scope) . "," . StrSafe_DB($user) . ")
            ON DUPLICATE KEY UPDATE CmRole=VALUES(CmRole), CmScope=VALUES(CmScope), CmUser=VALUES(CmUser), CmWhen=NOW()");
        return true;
    }
    if ($state == 'own' && $isImport) return true;
    $reason = aut_code_reason($state, $code, $isImport);
    return false;
}

/* ------------------------------------------------------------------ */
/* Views (one person = several structures, switched on the fly)        */
/* ------------------------------------------------------------------ */

/** Level of a view — used to find "the highest right". */
function aut_view_rank($role) {
    $ranks = array(AUT_ROLE_ADMIN => 5, AUT_ROLE_FED => 4, AUT_ROLE_CR => 3, AUT_ROLE_CD => 2, AUT_ROLE_CLUB => 1);
    return $ranks[$role] ?? 0;
}

/**
 * Views available to an account, sorted from the highest level to the lowest:
 *  - ADMIN account: Administrator view + their officers' space structures;
 *  - SSO account  : their structures (AuStructs, refreshed at each sign-in);
 *  - LOCAL non-admin account: their single structure (AuRole/AuScope).
 * A non-admin SSO account without an active structure has NO view (access refused).
 */
function aut_user_views($u) {
    $views = array();
    if ($u->AuRole == AUT_ROLE_ADMIN) {
        $views[] = array('role' => AUT_ROLE_ADMIN, 'scope' => '', 'label' => aut_t('RoleADMIN'));
    }
    foreach ((json_decode($u->AuStructs ?? '', true) ?: array()) as $st) {
        if (!is_array($st) || !in_array($st['role'] ?? '', array(AUT_ROLE_CLUB, AUT_ROLE_CD, AUT_ROLE_CR, AUT_ROLE_FED))) continue;
        $views[] = array('role' => $st['role'], 'scope' => (string)($st['scope'] ?? ''),
                         'label' => (string)($st['label'] ?? ($st['role'] . ' ' . ($st['scope'] ?? ''))));
    }
    if (!count($views) && $u->AuPassword !== '' && in_array($u->AuRole, array(AUT_ROLE_CLUB, AUT_ROLE_CD, AUT_ROLE_CR, AUT_ROLE_FED))) {
        $views[] = array('role' => $u->AuRole, 'scope' => $u->AuScope,
                         'label' => aut_roles()[$u->AuRole] . ($u->AuScope !== '' ? ' ' . $u->AuScope : ''));
    }
    // sorted by decreasing level (stable) + duplicates of role+scope removed
    $seen = array();
    $out = array();
    foreach (array(5, 4, 3, 2, 1) as $rank) {
        foreach ($views as $v) {
            $k = $v['role'] . '|' . mb_strtolower($v['scope']);
            if (aut_view_rank($v['role']) == $rank && empty($seen[$k])) {
                $seen[$k] = true;
                $out[] = $v;
            }
        }
    }
    return $out;
}

/**
 * View to turn on at sign-in: the last one used when still available, otherwise the highest
 * level. null when no view.
 */
function aut_pick_view($u, $views = null) {
    if (is_null($views)) $views = aut_user_views($u);
    if (!count($views)) return null;
    foreach ($views as $v) {
        if (strcasecmp($v['role'], $u->AuLastRole ?? '') === 0
            && strcasecmp($v['scope'], $u->AuLastScope ?? '') === 0) return $v;
    }
    return $views[0];   // already sorted by decreasing level
}

/** Short label of an owner (sharing page, admin). */
function aut_owner_label($role, $scope) {
    switch ($role) {
        case AUT_ROLE_CLUB: return $scope;
        case AUT_ROLE_CD:   return 'CD' . $scope;
        case AUT_ROLE_CR:   return 'CR' . $scope;
        case AUT_ROLE_FED:  return 'FED';
    }
    return '';
}

/** Parses the admin's entry of an owner: '', approval number, CD60, CR07, FED. */
function aut_parse_owner($str, &$role, &$scope) {
    $str = trim($str);
    $role = '';
    $scope = '';
    if ($str === '') return true;                                    // no owner
    if (preg_match('/^FED$/i', $str)) { $role = AUT_ROLE_FED; return true; }
    if (preg_match('/^(CD|CR)([0-9A-Za-z]{2})$/i', $str, $m)) {
        // bytes: a role code (CD, CR, FED) is ASCII
        $role = strtoupper($m[1]);
        $scope = $m[2];
        return true;
    }
    if (preg_match('/^[0-9A-Za-z]{5,12}$/', $str)) { $role = AUT_ROLE_CLUB; $scope = $str; return true; }
    return false;
}

/* "Flash" message shown once by the module's bar (menu.php). */
function aut_flash_set($msg) {
    $_SESSION['AUT_Flash'] = $msg;
}

function aut_flash_get() {
    $m = $_SESSION['AUT_Flash'] ?? '';
    unset($_SESSION['AUT_Flash']);
    return $m;
}

/**
 * ISK policy of an ONLINE SERVER: only "no ISK" and "ISK-NG lite" are allowed. The ng-pro and
 * ng-live modes fire (on the ianseo side) a trigger that REVOKES the server's licence —
 * unacceptable on a shared online server. These two functions are the source of truth, reused
 * by the UI (menu.php, SYNCHRO_FFTA) and the enforcement (back to lite).
 */
function aut_isk_blocked_modes() {
    return array('ng-pro', 'ng-live');
}

/** Removes the forbidden modes from an $IskType list (key => label). */
function aut_isk_filter($iskType) {
    if (!is_array($iskType)) return $iskType;
    foreach (aut_isk_blocked_modes() as $m) unset($iskType[$m]);
    return $iskType;
}

/**
 * Brings the ISK mode of a competition back to "lite" when it is pro/live (import of a
 * competition set to pro, or a choice forced on the core page that cannot be changed). Returns
 * true when a downgrade happened. Safe and cheap (one indexed SELECT).
 */
function aut_isk_enforce($tourId = 0) {
    if (!function_exists('getModuleParameter') || !function_exists('setModuleParameter')) return false;
    if (empty($tourId)) $tourId = intval($_SESSION['TourId'] ?? 0);
    if ($tourId <= 0) return false;
    $mode = getModuleParameter('ISK-NG', 'Mode', '', $tourId, true);
    if (in_array($mode, aut_isk_blocked_modes(), true)) {
        setModuleParameter('ISK-NG', 'Mode', 'ng-lite', $tourId);
        aut_log('ISK_DOWNGRADE', 'tour=' . $tourId . ' from=' . $mode);
        // Tell the organiser (the safety net was silent): "2nd message if detected" on the
        // import of a competition set to ISK Pro/Live. Do not overwrite a flash already waiting
        // (e.g. an import refusal — case without an open competition).
        if (($_SESSION['AUT_Flash'] ?? '') === '') {
            aut_flash_set(aut_t('IskDowngraded', $mode === 'ng-pro' ? 'Pro' : 'Live'));
        }
        return true;
    }
    return false;
}

/**
 * Adopts the competition the session points to when it has no owner yet. The ianseo core sets
 * $_SESSION['TourId'] at creation WITHOUT going through TourOn; for an organiser, a TourId can
 * only come from TourOn (filtered by AUTH_COMP → competition already owned/shared) or from a
 * creation by them → the adoption is safe.
 */
function aut_adopt_current($u, $role, $scope) {
    $tid = intval($_SESSION['TourId'] ?? 0);
    if ($tid <= 0 || $role === '') return;
    $q = safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId=$tid");
    if (!($t = safe_fetch($q))) return;
    $q = safe_r_sql("SELECT AsOwnerRole FROM AuthShare WHERE AsToCode=" . StrSafe_DB($t->ToCode));
    $own = safe_fetch($q);
    if ($own && $own->AsOwnerRole !== '') return;
    // NB: the assignments of an ON DUPLICATE run left to right → scope/user (depending on the
    // old AsOwnerRole) BEFORE AsOwnerRole
    safe_w_sql("INSERT INTO AuthShare (AsToCode, AsOwnerRole, AsOwnerScope, AsOwnerUser) VALUES ("
        . StrSafe_DB($t->ToCode) . "," . StrSafe_DB($role) . "," . StrSafe_DB($scope) . "," . StrSafe_DB($u->AuUsername) . ")
        ON DUPLICATE KEY UPDATE
            AsOwnerScope=IF(AsOwnerRole='', VALUES(AsOwnerScope), AsOwnerScope),
            AsOwnerUser =IF(AsOwnerRole='', VALUES(AsOwnerUser),  AsOwnerUser),
            AsOwnerRole =IF(AsOwnerRole='', VALUES(AsOwnerRole),  AsOwnerRole)");
    aut_log('COMP_ADOPT', $u->AuUsername . ' ' . $t->ToCode);
}

/**
 * Anti-overwrite barrier when a competition is saved.
 * The core's guard (Tournament/index.php:34) only applies when $_SESSION['TourId'] == -1: a
 * fresh session (no TourId) bypasses it entirely. So the check is made again here, BEFORE the
 * page's code (the bootstrap runs from config.php).
 */
function aut_guard_tournament_save($role) {
    global $CFG;
    if (!empty($_SESSION['AUTH_ROOT'])) return;
    if (strcasecmp(aut_script_rel(), '/Tournament/index.php') !== 0) return;
    if (($_REQUEST['Command'] ?? '') !== 'SAVE') return;

    // "From another account" view (read only): no competition saved, whatever the core's own
    // checks.
    if (!empty($_SESSION['AUTH_RO'])) {
        aut_log('SAVE_BLOCK', ($_SESSION['AUTH_User'] ?? '') . ' RO');
        aut_flash_set(aut_t('SaveReadOnly'));
        CD_redirect($CFG->ROOT_DIR . 'Tournament/index.php' . (isset($_REQUEST['New']) ? '?New=' : ''));
        die();
    }

    // same code normalisation as the core
    $newCode = preg_replace('/[^0-9a-z._-]+/sim', '_', $_REQUEST['d_ToCode'] ?? '');
    $reason = '';
    $scope = $_SESSION['AUTH_SCOPE'] ?? '';
    $user  = $_SESSION['AUTH_User'] ?? '';
    $organizer = in_array($role, array(AUT_ROLE_CLUB, AUT_ROLE_CD, AUT_ROLE_CR, AUT_ROLE_FED));

    if (isset($_REQUEST['New'])) {
        if (!$organizer) {
            $ok = false;
            $reason = aut_t('SaveNoCreate');
        } else {
            $ok = aut_can_use_code($newCode, $role, $scope, $user, false, $reason);
        }
    } else {
        // change: only a CHANGE of code must be checked again
        $cur = $_SESSION['TourCode'] ?? '';
        if ($newCode === '' || strcasecmp($newCode, $cur) === 0) {
            $ok = true;
        } elseif (!$organizer || aut_code_status($cur, $role, $scope) !== 'own') {
            // an invited club (help with data entry) may edit, but not rename
            $ok = false;
            $reason = aut_t('SaveOwnerOnly');
        } else {
            $ok = aut_can_use_code($newCode, $role, $scope, $user, false, $reason);
        }
    }
    if (!$ok) {
        aut_log('SAVE_BLOCK', ($_SESSION['AUTH_User'] ?? '') . ' ' . mb_substr($newCode, 0, 40));
        // the entry is kept: menu.php puts it back into the form
        $data = array();
        foreach ($_POST as $k => $v) {
            if (is_string($v) && preg_match('/^(d_|x_|xx_)/', $k)) $data[$k] = $v;
        }
        $_SESSION['AUT_SaveBlock'] = array('msg' => $reason, 'data' => $data);
        CD_redirect($CFG->ROOT_DIR . 'Tournament/index.php' . (isset($_REQUEST['New']) ? '?New=' : ''));
        die();
    }
}

/**
 * Settles the ownership of the competitions actually created from the user's claims (called at
 * every request of a CLUB — creation goes through the ianseo core, this hook is our only safe
 * point).
 */
function aut_adopt_claims($u) {
    $q = safe_r_sql("SELECT * FROM AuthClaim WHERE CmUser=" . StrSafe_DB($u->AuUsername));
    $claims = array();
    while ($r = safe_fetch($q)) $claims[] = $r;
    if (!count($claims)) {
        return;
    }
    foreach ($claims as $c) {
        $q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToCode=" . StrSafe_DB($c->CmCode));
        if (safe_fetch($q)) {
            // owner set only while still empty (race with an admin);
            // assignments run left to right → AsOwnerRole last
            safe_w_sql("INSERT INTO AuthShare (AsToCode, AsOwnerRole, AsOwnerScope, AsOwnerUser) VALUES ("
                . StrSafe_DB($c->CmCode) . "," . StrSafe_DB($c->CmRole) . "," . StrSafe_DB($c->CmScope) . "," . StrSafe_DB($c->CmUser) . ")
                ON DUPLICATE KEY UPDATE
                    AsOwnerScope=IF(AsOwnerRole='', VALUES(AsOwnerScope), AsOwnerScope),
                    AsOwnerUser =IF(AsOwnerRole='', VALUES(AsOwnerUser),  AsOwnerUser),
                    AsOwnerRole =IF(AsOwnerRole='', VALUES(AsOwnerRole),  AsOwnerRole)");
            safe_w_sql("DELETE FROM AuthClaim WHERE CmCode=" . StrSafe_DB($c->CmCode));
            aut_log('COMP_ADOPT', $u->AuUsername . ' ' . $c->CmCode);
        }
    }
    safe_w_sql("DELETE FROM AuthClaim WHERE CmWhen < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}

/** Non-empty $role/$scope = the session's view, otherwise those of the account. */
function aut_session_apply($u, $role = '', $scope = '') {
    if ($role === '') { $role = $u->AuRole; $scope = $u->AuScope; }
    $_SESSION['AUTH_ENABLE'] = 1;
    $_SESSION['AUTH_ROLE']   = $role;
    $_SESSION['AUTH_SCOPE']  = $scope;
    $_SESSION['AUTH_VIEWS']  = aut_user_views($u);   // for the view selector (bar)
    aut_extranet_publish();    // FFTA_EXTRANET_* convention: erased by the core, published again here
    aut_dirigeant_publish();   // FFTA_DIRIGEANT_* convention: same
    if ($role == AUT_ROLE_ADMIN) {
        $_SESSION['AUTH_ROOT'] = 1;
        $_SESSION['AUTH_COMP'] = array();
    } else {
        unset($_SESSION['AUTH_ROOT']);
        $_SESSION['AUTH_COMP'] = aut_compute_comp($role, $scope);
    }
}

function aut_session_clear() {
    foreach (array('AUTH_User', 'AUTH_Pwd', 'AUTH_ROOT', 'AUTH_ROLE', 'AUTH_SCOPE', 'AUTH_SSO', 'AUTH_VIEWS') as $k) {
        unset($_SESSION[$k]);
    }
    unset($_SESSION['AUTH_IMPERSONATE'], $_SESSION['AUTH_RO']);   // end of session = end of observation
    $_SESSION['AUTH_ENABLE'] = 1;
    $_SESSION['AUTH_COMP']   = array();
}

/* ------------------------------------------------------------------ */
/* "From another account" view (impersonation) — ADMIN, READ ONLY.    */
/* Opened ONLY by the admin page (admin/impersonate.php, guarded by    */
/* AclRoot + AUTH_ROOT). CANONICAL IN THE DATABASE (AuthSessions.AsnImp) */
/* because CreateTourSession empties the session at each opening of a  */
/* competition (only AUTH_User/AUTH_Pwd survive): a mere session flag  */
/* WOULD END the observation leaving the admin with read-WRITE access  */
/* to someone else's competition. The session only carries a MIRROR    */
/* (AUTH_IMPERSONATE), rebuilt at every request by the bootstrap from  */
/* the session row — the archer space (which only reads the session,   */
/* without depending on AUTH) relies on it. The organiser read-only    */
/* mode is ENFORCED BY THE CORE through AUTH_RO (ACL ceiling,          */
/* dist/BlockFunction.php), never merely hidden.                       */
/* ------------------------------------------------------------------ */
function aut_imp_get() {
    $i = $_SESSION['AUTH_IMPERSONATE'] ?? null;
    return is_array($i) ? $i : null;
}

/** Rebuilds the session mirror from the session row ($s->AsnImp). */
function aut_imp_load($s) {
    $raw = is_object($s) ? (string) ($s->AsnImp ?? '') : '';
    $i = $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($i) && isset($i['type'])) $_SESSION['AUTH_IMPERSONATE'] = $i;
    else unset($_SESSION['AUTH_IMPERSONATE'], $_SESSION['AUTH_RO']);
}

/** Opens an observation: kept in the database (survives CreateTourSession) + mirror. */
function aut_imp_store(array $imp) {
    $h = aut_current_token_hash();
    if ($h !== '') {
        safe_w_sql("UPDATE AuthSessions SET AsnImp=" . StrSafe_DB(json_encode($imp))
            . " WHERE AsnTokenHash='" . $h . "'");
    }
    $_SESSION['AUTH_IMPERSONATE'] = $imp;
}

/** Ferme l'observation : base + miroir + plafond ACL. */
function aut_imp_forget() {
    $h = aut_current_token_hash();
    if ($h !== '') safe_w_sql("UPDATE AuthSessions SET AsnImp=NULL WHERE AsnTokenHash='" . $h . "'");
    unset($_SESSION['AUTH_IMPERSONATE'], $_SESSION['AUTH_RO']);
}

/**
 * Applies the ORGANISER impersonation to the current session, AFTER aut_session_apply (which set
 * the admin view). The admin then sees the target account READ ONLY: role/scope/AUTH_COMP of
 * the target, AUTH_ROOT removed, AUTH_RO set (ACL ceiling). Guards: acts only while the admin
 * STILL is one and the target exists and is NOT an admin; otherwise closes the observation.
 */
function aut_imp_apply_org($u) {
    $i = aut_imp_get();
    if (!$i || ($i['type'] ?? '') !== 'org') return;
    if ($u->AuRole != AUT_ROLE_ADMIN) {              // the observer is no longer an admin → stop
        aut_log('IMPERSONATE_REVOKE', $u->AuUsername);
        aut_imp_forget();
        return;
    }
    $t = aut_get_user((string) ($i['user'] ?? ''));
    if (!$t || $t->AuRole == AUT_ROLE_ADMIN) {       // target gone or now an admin → stop
        aut_imp_forget();
        return;
    }
    $_SESSION['AUTH_ROLE']  = $t->AuRole;
    $_SESSION['AUTH_SCOPE'] = $t->AuScope;
    $_SESSION['AUTH_COMP']  = aut_compute_comp($t->AuRole, $t->AuScope);
    unset($_SESSION['AUTH_ROOT']);                   // no root in the observed view
    $_SESSION['AUTH_RO']    = 1;                      // read-only ceiling (BlockFunction)
    $_SESSION['AUTH_VIEWS'] = array();               // hides the view selector during the observation
}

/** Does a competition code match the session's rights? */
function aut_code_allowed($code, $list = null) {
    if (is_null($list)) $list = $_SESSION['AUTH_COMP'] ?? array();
    foreach ($list as $p) {
        if (strpos($p, '%') !== false || strpos($p, '_') !== false) {
            $rx = '/^' . str_replace(array('%', '_'), array('.*', '.'), preg_quote($p, '/')) . '$/i';
            if (preg_match($rx, $code)) return true;
        } elseif (strcasecmp($p, $code) === 0) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------ */
/* Request bootstrap (called by Modules/Authentication/AuthFunctions.php) */
/* ------------------------------------------------------------------ */

function aut_script_rel() {
    global $CFG;
    $s = $_SERVER['SCRIPT_NAME'] ?? '';
    $root = rtrim($CFG->ROOT_DIR ?? '/', '/');
    // bytes: a path or a file name, compared as the file system does
    if ($root && strpos($s, $root) === 0) $s = substr($s, strlen($root));
    return $s ?: '/';
}

/** Paths reachable without signing in (extensible through config.local.json). */
function aut_public_paths() {
    static $paths = null;
    if (!is_null($paths)) return $paths;
    // NB: /index.php (ianseo root) is NO LONGER public → an anonymous visitor is sent to the
    // unified sign-in page (see aut_request_bootstrap).
    $paths = array(
        '/noAccess.php', '/credits.php',
        '/Modules/Authentication/',            // organiser login/logout/2FA (deployed)
        '/Modules/Custom/AUTH/login.php',      // unified sign-in page
        '/Modules/Custom/AUTH/booking/public/',     // licensee space (public side, $SKIP_AUTH)
    );
    foreach ((aut_local_config()['public_paths'] ?? array()) as $p) {
        if (is_string($p) && $p !== '') $paths[] = $p;
    }
    return $paths;
}

/** URL of the unified sign-in page (organiser / competitor choice). */
function aut_unified_login_url() {
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php';
}

/**
 * Is a competitor (booking session) signed in? Loaded only when a booking token is there — no
 * dependency of AUTH on booking otherwise.
 */
function aut_booking_archer_logged() {
    global $CFG;
    if (empty($_SESSION['BK_Token'])) return false;
    $dir = $CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/';
    if (!is_file($dir . 'archer.php')) return false;
    require_once($dir . 'schema.php');
    require_once($dir . 'archer.php');
    return function_exists('bk_current_archer') && (bool) bk_current_archer();
}

/* ------------------------------------------------------------------ */
/* ORGANISER sign-in — handlers reused by login.php (unified page)     */
/* AND by the deployed LogIn.php. Single source of the flow.           */
/* ------------------------------------------------------------------ */

/**
 * Completes the organiser sign-in: the view turned on is the last one used when still
 * available, otherwise the highest level. Regenerates the session id, then redirects
 * (ChangePassword when the password is temporary) and ends.
 */
function aut_finish_login($u, $event = 'LOGIN_OK') {
    global $CFG;
    unset($_SESSION['AUT_2FA_User'], $_SESSION['AUT_2FA_Time']);
    session_regenerate_id(true);
    $view  = aut_pick_view($u);
    $role  = $view ? $view['role']  : '';
    $scope = $view ? $view['scope'] : '';
    aut_session_open($u, $role, $scope);
    safe_w_sql("UPDATE AuthUsers SET AuLastLogin=NOW(), AuLastRole=" . StrSafe_DB($role)
        . ", AuLastScope=" . StrSafe_DB($scope) . " WHERE AuId={$u->AuId}");
    aut_log($event, $u->AuUsername);
    CD_redirect($u->AuMustChangePwd
        ? $CFG->ROOT_DIR . 'Modules/Authentication/ChangePassword.php'
        : $CFG->ROOT_DIR);
    die();
}

/**
 * Organiser step 1 (identifier + password) — reads $_POST.
 * LOCAL account: password + TOTP if any. SSO account: relay to the officers' space (+ officers'
 * cookie captured, extranet opened as a silent fallback), structures synchronised. On success:
 * redirects and ends (aut_finish_login); otherwise fills $err, and $stage='totp' when a server
 * TOTP code is expected.
 */
function aut_handle_org_login(&$err, &$stage) {
    // bytes: identifiers have always been folded this way; mb_strtolower could make an existing account stop matching
    $username = strtolower(trim($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';
    $otp      = trim($_POST['otp'] ?? '');
    // dummy hash: constant response time whether the identifier exists or not
    $dummyHash = '$2y$10$abcdefghijklmnopqrstuvJltUS3sTfTLNQKQ2wZ2gJ0S9nO2/xdC';

    if ($username === '' || $password === '') {
        $err = aut_t('LoginRequired');
        return;
    }
    if (aut_too_many_failures($username)) {
        aut_log('LOGIN_BLOCK', $username);
        $err = aut_t('LoginTooMany');
        return;
    }
    $u = aut_get_user($username);

    if ($u && $u->AuPassword !== '') {
        /* Local account (ADMIN…): local password + TOTP if any */
        if (password_verify($password, $u->AuPassword) && $u->AuActive) {
            if ($u->AuTotpEnabled) {
                $_SESSION['AUT_2FA_User'] = $u->AuUsername;
                $_SESSION['AUT_2FA_Time'] = time();
                $stage = 'totp';
            } else {
                aut_finish_login($u);
            }
        } else {
            aut_log('LOGIN_FAIL', $username);
            $err = aut_t('LoginBad');
        }
    } elseif (aut_sso_enabled()) {
        /* SSO officers' space: relay + structures synchronised */
        $structures = array();
        $ssoErr = '';
        $ssoCode = '';
        $dirCookie = null;
        if (aut_ffta_verify($username, $password, $otp, $structures, $ssoErr, $dirCookie, $ssoCode)) {
            aut_ffta_outage_clear('dirigeant');   // the space answers: the banner is no longer needed
            aut_dirigeant_stash($dirCookie);      // reusable officers' session cookie (sync)
            aut_extranet_open($username, $password); // 2nd extranet sign-in, silent on failure
            $syncErr = '';
            $u = aut_sso_sync($username, $structures, $syncErr);
            if (!$u) {
                aut_log('LOGIN_FAIL', $username);
                // No usable structure: the PRECISE diagnosis comes from aut_ffta_verify
                // (insufficient role, with the roles really held) — much more useful than the
                // generic provisioning message.
                $err = (!count($structures) && $ssoErr !== '') ? $ssoErr : $syncErr;
            } elseif (!count(aut_user_views($u))) {
                aut_log('LOGIN_FAIL', $username);
                $err = $ssoErr ?: aut_ffta_no_structure_reason(array());
            } elseif ($u->AuTotpEnabled) {
                // 2FA (mandatory for ADMIN, optional for the others): code step.
                $_SESSION['AUT_2FA_User'] = $u->AuUsername;
                $_SESSION['AUT_2FA_Time'] = time();
                $stage = 'totp';
            } else {
                aut_finish_login($u, 'SSO_OK');   // admin without TOTP → Setup2FA forced by the bootstrap
            }
        } else {
            // Federation outage/maintenance, network cut, federation page changed, MFA code not
            // typed: these are NOT intrusion attempts. Counting them would lock the user out 15
            // more minutes ON TOP of the outage — and make them look for the mistake on their
            // side. Only BAD_CREDENTIALS counts.
            if (in_array($ssoCode, array('OUTAGE', 'NETWORK', 'NO_CSRF', 'MFA_NEEDED'), true)) {
                aut_log($ssoCode === 'MFA_NEEDED' ? 'SSO_MFA_STEP' : 'SSO_UNAVAILABLE', $username);
                if ($ssoCode !== 'MFA_NEEDED') aut_ffta_outage_note('dirigeant', $ssoErr);
            } else {
                aut_log('LOGIN_FAIL', $username);
            }
            $err = $ssoErr;
        }
    } else {
        password_verify($password, $dummyHash);   // constant time
        aut_log('LOGIN_FAIL', $username);
        $err = aut_t('LoginBad');
    }
}

/** Organiser step 2: the server's TOTP code (ADMIN accounts). Reads $_POST. */
function aut_handle_org_totp(&$err, &$stage) {
    $username = $_SESSION['AUT_2FA_User'] ?? '';
    if ($username === '' || (time() - ($_SESSION['AUT_2FA_Time'] ?? 0)) > AUT_2FA_PENDING_S) {
        unset($_SESSION['AUT_2FA_User'], $_SESSION['AUT_2FA_Time']);
        $err = aut_t('LoginExpired');
        return;
    }
    if (aut_too_many_failures($username)) {
        aut_log('LOGIN_BLOCK', $username);
        unset($_SESSION['AUT_2FA_User'], $_SESSION['AUT_2FA_Time']);
        $err = aut_t('LoginTooMany');
        return;
    }
    $u = aut_get_user($username);
    $usedSlot = 0;
    $code = $_POST['code'] ?? '';
    if ($u && $u->AuActive && aut_totp_verify($u->AuTotpSecret, $code, intval($u->AuTotpLastSlot), $usedSlot)) {
        safe_w_sql("UPDATE AuthUsers SET AuTotpLastSlot=$usedSlot WHERE AuId={$u->AuId}");
        aut_finish_login($u);
    }
    // Failure: a wrong code, or a wrong server clock? When the code matches a very distant
    // slot, it is the clock — SAY so (instead of a puzzling "wrong code") and do NOT count this
    // failure in the anti brute force (TOTP_SKEW, outside the LOGIN_FAIL/TOTP_FAIL filter): a
    // right code refused because of a clock offset must not lock the admin out.
    $skew = ($u && $u->AuActive) ? aut_totp_skew($u->AuTotpSecret, $code) : null;
    if ($skew !== null) {
        aut_log('TOTP_SKEW', $username);
        $mins = round(abs($skew) / 60);
        $err = aut_t('TotpSkew', $mins);
    } else {
        aut_log('TOTP_FAIL', $username);
        $err = aut_t('TotpBad');
    }
    $stage = 'totp';
}

function aut_is_public_script() {
    $s = aut_script_rel();
    if ($s == '/') return true;
    foreach (aut_public_paths() as $p) {
        // bytes: a path or a file name, compared as the file system does
        if (substr($p, -1) == '/') {
            if (stripos($s, $p) === 0) return true;
        } elseif (strcasecmp($s, $p) === 0) {
            return true;
        }
    }
    return false;
}

function aut_is_auth_script() {
    return stripos(aut_script_rel(), '/Modules/Authentication/') === 0;
}

/** Legal pages (reading + acceptance): exempt from the terms guard (no loop). */
function aut_is_legal_script() {
    $s = aut_script_rel();
    return stripos($s, '/Modules/Custom/AUTH/legal.php') === 0
        || stripos($s, '/Modules/Custom/AUTH/legal-accept.php') === 0;
}

/**
 * Scripts touching the WHOLE server (the whole database) → administrator only.
 * Central safety net: some of these ianseo core pages check NO ACL (e.g. RepairTables runs
 * REPAIR/OPTIMIZE on every table; RepairXAMPP restarts MySQL) — blocking them here keeps a mere
 * organiser from firing them and disturbing the others' competitions.
 * Can be overridden through config.local.json → "admin_only_paths" (merged).
 */
function aut_admin_only_paths() {
    static $paths = null;
    if (!is_null($paths)) return $paths;
    $paths = array(
        '/Update/',                            // database update (ALTER, migrations)
        '/Install/',                           // (re)installation
        '/Modules/Help/RepairTables.php',      // REPAIR + OPTIMIZE of every table
        '/Modules/Help/LoadDebug.php',
        '/RepairXAMPP.php',                    // aria_chk + mysqld restart
        '/info.php',                           // phpinfo(): versions, paths, and the visitor's own cookies
    );
    foreach ((aut_local_config()['admin_only_paths'] ?? array()) as $p) {
        if (is_string($p) && $p !== '') $paths[] = $p;
    }
    return $paths;
}

function aut_is_admin_only_script() {
    $s = aut_script_rel();
    foreach (aut_admin_only_paths() as $p) {
        // bytes: a path or a file name, compared as the file system does
        if (substr($p, -1) === '/') {
            if (stripos($s, $p) === 0) return true;
        } elseif (strcasecmp($s, $p) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Run on EVERY request (through config.php → AuthFunctions.php) when $CFG->USERAUTH is on.
 * Checks the user + their session token again and recomputes their rights: AUTH_ENABLE /
 * AUTH_ROOT / AUTH_COMP are erased by ianseo at each opening/closing of a competition
 * (CreateTourSession) — only AUTH_User / AUTH_Pwd survive, hence the systematic recomputation.
 */
/**
 * MANUAL entry of a participant (Partecipants/PopEdit.php…): as soon as an organiser saves an
 * archer, set their club's logo from the shared cache — exactly what the online registration
 * already does (bk_reg_club_id → aut_logos_ensure_club). Without it, this case was only covered
 * by the cron's nightly pass.
 *
 * ⚠️ Why here and not in PopEdit.php: that file belongs to the CORE, any change would be erased
 * by the next ianseo update. The bootstrap runs on every request, recognising the save there is
 * enough.
 *
 * The work is DEFERRED to the end of the request (register_shutdown_function): at bootstrap time
 * the archer is not saved yet. Purely LOCAL (cache read + one file written), never any network,
 * and isolated — cannot disturb the entry. A club still missing from the cache is caught by the
 * next cron run (which also sweeps the clubs of the competitions, not only the federation file).
 */
function aut_logos_hook_entry_save() {
    if (stripos(aut_script_rel(), '/Partecipants/') !== 0) return;
    // bytes: an ASCII request parameter (Command)
    $cmd = strtoupper((string) ($_REQUEST['Command'] ?? ''));
    if ($cmd !== 'SAVE' && $cmd !== 'SAVE_CONTINUE') return;

    // Club codes typed in the form (main + up to 2 secondary ones),
    // normalised as the core does just before writing into Countries.
    $codes = array();
    foreach (array('', '2', '3') as $v) {
        $c = trim((string) ($_REQUEST['d_c_CoCode' . $v . '_'] ?? ''));
        if ($c !== '') $codes[] = mb_convert_case($c, MB_CASE_UPPER, 'UTF-8');
    }
    $tid = intval($_SESSION['TourId'] ?? 0);
    if (!$codes || $tid <= 0) return;

    register_shutdown_function(function () use ($tid, $codes) {
        try {
            require_once __DIR__ . '/logos-lib.php';
            if (!function_exists('aut_logos_ensure_club')) return;
            foreach (array_unique($codes) as $c) aut_logos_ensure_club($tid, $c);
        } catch (\Throwable $e) {
            // setting a logo must never reach the user
        }
    });
}

function aut_request_bootstrap() {
    global $CFG;

    // First of all, even on the server console: every line below may read a table.
    aut_table_names();

    // Manual entry of a participant → logo of their club (see the function).
    // Placed BEFORE the early "localhost" return: the server console enters participants
    // too, and its competitions deserve their logos.
    aut_logos_hook_entry_save();

    // Session care: when a competition is created, the core sets $_SESSION['TourId'] WITHOUT
    // TourCode (Tournament/index.php) → warnings in define_session_flags() on every page.
    // Completed from the database.
    if (intval($_SESSION['TourId'] ?? 0) > 0 && !isset($_SESSION['TourCode'])) {
        $q = safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId=" . intval($_SESSION['TourId']));
        if ($r = safe_fetch($q)) {
            $_SESSION['TourCode'] = $r->ToCode;
        } else {
            $_SESSION['TourId'] = -1;
            $_SESSION['TourCode'] = '';
        }
    }

    if (aut_is_localhost()) return;   // server console: classic ianseo behaviour

    aut_ensure_schema();
    aut_log_purge_daily();   // log retention (at most once a day, see the marker)

    if (!empty($_SESSION['AUTH_User'])) {
        $u = aut_get_user($_SESSION['AUTH_User']);
        $s = ($u && $u->AuActive) ? aut_session_validate($u) : null;
        if ($s) {
            aut_imp_load($s);   // observation mirror (canonical in the database, survives CreateTourSession)
            $_SESSION['AUTH_SSO'] = ($u->AuPassword === '') ? 1 : 0;
            // current view = the session's (switched on the fly through switch-view.php);
            // falls back to the account's base role
            $role  = $s->AsnRole !== '' ? $s->AsnRole  : $u->AuRole;
            $scope = $s->AsnRole !== '' ? $s->AsnScope : $u->AuScope;
            // the Administrator view requires the account to still be one
            if ($role == AUT_ROLE_ADMIN && $u->AuRole != AUT_ROLE_ADMIN) {
                $role = $u->AuRole;
                $scope = $u->AuScope;
            }
            if (in_array($role, array(AUT_ROLE_CLUB, AUT_ROLE_CD, AUT_ROLE_CR, AUT_ROLE_FED))) {
                aut_adopt_claims($u);                   // competitions created through import/claims
                aut_adopt_current($u, $role, $scope);   // competition just created (TourId set by the core)
            }
            aut_session_apply($u, $role, $scope);
            aut_imp_apply_org($u);               // "from another account" view (admin, read only)
            aut_guard_tournament_save($role);    // anti-overwrite barrier (the core's one can be bypassed)
            // server pages (update / repair): administrator only
            if (empty($_SESSION['AUTH_ROOT']) && aut_is_admin_only_script()) {
                aut_log('ADMIN_PATH_BLOCK', $u->AuUsername . ' ' . aut_script_rel());
                CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
                die();
            }
            if (!aut_is_auth_script()) {
                if ($u->AuMustChangePwd) {
                    CD_redirect($CFG->ROOT_DIR . 'Modules/Authentication/ChangePassword.php');
                    die();
                }
                // 2FA mandatory for administrators
                if ($u->AuRole == AUT_ROLE_ADMIN && !$u->AuTotpEnabled) {
                    CD_redirect($CFG->ROOT_DIR . 'Modules/Authentication/Setup2FA.php');
                    die();
                }
                // Terms of use: acceptance required (blocking until accepted for the current version) —
                // ONLY when the operator has filled in their legal information (otherwise no one is
                // made to accept a text with gaps, and the admin can reach admin/legal.php).
                // Cached in the session to avoid one query per page. Legal pages are exempt.
                if (!aut_is_legal_script()) {
                    require_once __DIR__ . '/legal-lib.php';
                    if (aut_legal_configured()) {
                        $cguVer = aut_legal_version();
                        if (($_SESSION['AUTH_CGU_OK'] ?? '') !== $cguVer) {
                            if (aut_legal_org_ok($u->AuUsername)) {
                                $_SESSION['AUTH_CGU_OK'] = $cguVer;
                            } else {
                                CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/legal-accept.php');
                                die();
                            }
                        }
                    }
                }
            }
            // Audience measurement (aggregated, no personal data; the organiser is counted by
            // account identity, no cookie). Page really served (after the redirecting
            // guards). Self-guarded and isolated: never fatal.
            require_once __DIR__ . '/stats-usage.php';
            if (!aut_imp_get() && function_exists('aut_track')) aut_track('org', $u->AuId);   // no counter during an observation
            return;
        }
        // token expired/revoked, account deactivated or deleted
        aut_session_clear();
    }

    aut_session_clear();
    if (!aut_is_public_script()) {
        // Competitor already signed in (BOOKING session) → their space, never the organiser
        // login; otherwise → unified sign-in page (choice of roles).
        if (aut_booking_archer_logged()) {
            CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/index.php');
        } else {
            CD_redirect(aut_unified_login_url());
        }
        die();
    }
}

/* ------------------------------------------------------------------ */
/* SSO Espace Dirigeant FFTA (dirigeant.ffta.fr)                       */
/*                                                                      */
/* Credentials checked by a sign-in attempt on the officers' space     */
/* (credential relay, not a real OAuth SSO — see SERVEUR.md).          */
/* The password is NEVER stored nor logged.                            */
/* The attached structures (select-structure menu) give the role.      */
/* ------------------------------------------------------------------ */

// Can be overridden by defining it BEFORE lib.php is loaded (pre-production, offline tests
// of the outage diagnosis). Production value by default.
if (!defined('AUT_FFTA_BASE')) define('AUT_FFTA_BASE', 'https://dirigeant.ffta.fr');

function aut_sso_enabled() {
    $sso = aut_local_config()['sso'] ?? array();
    return !array_key_exists('enabled', $sso) || !empty($sso['enabled']);
}

/* ---- Debugging of the SSO flow (server cURL trace) ----------------- *
 * Turned on: either config.local.json → "sso":{"debug":true}, or the
 * presence of the switch file Modules/Custom/AUTH/ffta-debug.on
 * (handy in a demo: create it, reproduce, read ffta-debug.log, delete).
 * NEVER LOGS a password nor an MFA code — only URLs, HTTP codes, the
 * type of page reached and the names of the form fields.               */
function aut_ffta_debug_enabled() {
    if (!empty(aut_local_config()['sso']['debug'])) return true;
    return is_file(__DIR__ . '/ffta-debug.on');
}

function aut_ffta_debug($msg) {
    if (!aut_ffta_debug_enabled()) return;
    @file_put_contents(__DIR__ . '/ffta-debug.log',
        date('Y-m-d H:i:s') . '  ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/** "Safe" summary of an HTML page for the log: detected type + form fields. */
function aut_ffta_debug_page($html) {
    $html = (string)$html;
    // bytes: sizes in the debug log are byte counts
    $info = array('len=' . strlen($html));
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
        $info[] = 'title="' . trim(preg_replace('/\s+/', ' ', strip_tags($m[1]))) . '"';
    }
    $info[] = 'select-structure=' . (strpos($html, '/auth/select-structure/') !== false ? 'yes' : 'no');
    $info[] = 'password-field=' . (preg_match('/name=["\']password["\']/', $html) ? 'yes' : 'no');
    // markers of an MFA / two-factor step
    $mfa = preg_match('/(two[-_]?factor|double.?authentification|v[ée]rification|authenticator|code.?(de.?)?s[ée]curit|otp|2fa)/i', $html);
    $info[] = 'MFA-marker=' . ($mfa ? 'yes' : 'no');
    // form action + field names (values hidden)
    if (preg_match('#<form[^>]*action=["\']([^"\']+)["\']#i', $html, $m)) {
        $info[] = 'form-action="' . $m[1] . '"';
    }
    if (preg_match_all('/name=["\']([^"\']+)["\']/', $html, $m)) {
        $info[] = 'fields=[' . implode(',', array_slice(array_unique($m[1]), 0, 15)) . ']';
    }
    return implode('  ', $info);
}

/* ---- Unavailability of the FFTA space (maintenance, outage) --------- *
 * Real outage (Sept. 2026): during an FFTA maintenance, the sign-in POST
 * comes back in error WITHOUT leaving /auth/login. Yet a credentials
 * failure is detected precisely by "the final URL contains /login" →
 * everyone got "Officers' space credentials incorrect (or MFA required
 * and not filled in)". Doubly misleading message: the user types their
 * password again thinking they are at fault, and the anti brute force
 * ends up locking them out 15 min ON TOP of the outage. So the
 * unavailability is established BEFORE concluding to a refusal.        */

/** Lower case + folded accents: the markers are looked for in ASCII. */
function aut_ffta_fold($s) {
    $s = mb_strtolower((string) $s, 'UTF-8');
    return strtr($s, array('é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a',
                           'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'));
}

/** Does the page carry a usable sign-in form? */
function aut_ffta_is_login_page($html) {
    return (bool) preg_match('/(name|id)=["\']password["\']|type=["\']password["\']/i', (string) $html);
}

/**
 * Is the FFTA response usable? Returns '' when it is, otherwise a message to show.
 * $expected='login': the sign-in form was expected — a page that is not one is then also a sign
 * of unavailability.
 * ⚠ Call ONLY on a page known not to be a signed-in page: "maintenance" may very well appear in
 * the menu of a healthy page, and a false positive would refuse a valid sign-in.
 */
function aut_ffta_outage($ch, $html, $expected = '', $space = '') {
    if ($space === '') $space = aut_t('SsoSpace');
    $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));

    // 5xx = outage/maintenance on the federation side; 429 = rate limited; 408 = timeout.
    if ($code >= 500 || $code == 429 || $code == 408) return aut_ffta_outage_msg($space, $code);

    if (!aut_ffta_is_login_page($html)) {
        $t = aut_ffta_fold($html);
        foreach (array(
            'be right back',                      // Laravel's default 503 page
            'service unavailable', 'temporarily unavailable', 'web server is down',
            'en maintenance', 'maintenance en cours', 'maintenance planifiee',
            'momentanement indisponible', 'temporairement indisponible',
            'site indisponible', 'service indisponible',
        ) as $m) {
            if (strpos($t, $m) !== false) return aut_ffta_outage_msg($space, $code);
        }
        if ($expected === 'login') return aut_ffta_outage_msg($space, $code, true);
    }
    return '';
}

/** Unavailability message — says explicitly that the user has nothing to do with it. */
function aut_ffta_outage_msg($space, $code, $unexpected = false) {
    $tail = ' ' . aut_t('SsoNotYourFault');
    if ($unexpected) return aut_t('SsoNoLoginPage', $space) . $tail;
    if ($code == 429) return aut_t('SsoRateLimited', $space) . $tail;
    if ($code >= 400) {
        return aut_t($code == 503 ? 'SsoMaintenanceHttp' : 'SsoDownHttp', array('space' => $space, 'code' => $code)) . $tail;
    }
    // Detection by CONTENT (the site answers 200 but serves its maintenance page):
    // showing "HTTP 200" here would confuse more than help.
    return aut_t('SsoMaintenancePage', $space) . $tail;
}

/* ---- Unavailability memo (banner of the sign-in page) --------------- *
 * Only one user suffers the failure; the next ones must be warned BEFORE
 * typing their credentials. Memo deliberately outside the module folder
 * (never served by Apache) and outside the database (no query added to the
 * sign-in page). Unreadable or not writable, everything keeps working.  */
define('AUT_OUTAGE_TTL', 600);

function aut_ffta_outage_file() {
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ianseo-ffta-outage.json';
}

function aut_ffta_outage_all() {
    $raw = @file_get_contents(aut_ffta_outage_file());
    $a = $raw ? json_decode(aut_json_strip_bom($raw), true) : null;
    return is_array($a) ? $a : array();
}

function aut_ffta_outage_note($space, $msg) {
    $all = aut_ffta_outage_all();
    $all[$space] = array('at' => time(), 'msg' => (string) $msg);
    @file_put_contents(aut_ffta_outage_file(), json_encode($all, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function aut_ffta_outage_clear($space) {
    $all = aut_ffta_outage_all();
    if (!isset($all[$space])) return;
    unset($all[$space]);
    @file_put_contents(aut_ffta_outage_file(), json_encode($all, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Memo still fresh for this space ('dirigeant' / 'licencie'), or null. */
function aut_ffta_outage_recent($space) {
    $m = aut_ffta_outage_all()[$space] ?? null;
    if (!is_array($m) || (time() - intval($m['at'] ?? 0)) > AUT_OUTAGE_TTL) return null;
    return $m;
}

/**
 * Sign-in on the officers' space (flow taken from the existing French integration:
 * GET login → Laravel _token CSRF → POST credentials → failure when the final URL contains
 * /login). Returns a signed-in curl handle + the HTML of the landing page through $landing, or
 * null ($error filled).
 *
 * $errCode qualifies the failure for the caller: NETWORK, OUTAGE, NO_CSRF, BAD_CREDENTIALS,
 * MFA_NEEDED, MFA_BAD_CODE. Only BAD_CREDENTIALS is an attempt to count in the anti brute force.
 */
function aut_ffta_curl_login($username, $password, $otp, &$landing, &$error, &$cookieFileOut = null, &$errCode = null) {
    $error = '';
    $errCode = '';
    $landing = '';
    $cookieFile = tempnam(sys_get_temp_dir(), 'aut_ck_');
    @chmod($cookieFile, 0600);
    $cookieFileOut = $cookieFile;   // exposed to capture the officers' space session
    register_shutdown_function(function () use ($cookieFile) {
        if (file_exists($cookieFile)) @unlink($cookieFile);
    });

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ianseo-ffta/auth)',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ));

    aut_ffta_debug('--- login ' . $username . ' otp=' . ($otp !== '' ? 'given' : 'empty') . ' ---');
    curl_setopt($ch, CURLOPT_URL, AUT_FFTA_BASE . '/auth/login');
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    $loginPage = curl_exec($ch);
    aut_ffta_debug('GET /auth/login http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' ' . aut_ffta_debug_page($loginPage));
    if (!$loginPage || curl_errno($ch)) {
        $error = aut_t('SsoUnreachable', curl_error($ch));
        $errCode = 'NETWORK';
        curl_close($ch);
        return null;
    }
    if (($out = aut_ffta_outage($ch, $loginPage, 'login')) !== '') {
        aut_ffta_debug('=> unavailability detected on GET /auth/login');
        $error = $out;
        $errCode = 'OUTAGE';
        curl_close($ch);
        return null;
    }

    $csrf = null;
    foreach (array(
        '/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/',
        '/name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\']/',
        '/content=["\']([^"\']+)["\'][^>]*name=["\']csrf-token["\']/',
    ) as $p) {
        if (preg_match($p, $loginPage, $m)) { $csrf = $m[1]; break; }
    }
    aut_ffta_debug('CSRF ' . ($csrf ? 'found' : 'NOT FOUND'));
    if (!$csrf) {
        $error = aut_t('SsoNoCsrf');
        $errCode = 'NO_CSRF';
        curl_close($ch);
        return null;
    }

    $post = array('_token' => $csrf, 'username' => $username, 'password' => $password);
    if ($otp !== '') $post['otp'] = $otp;
    curl_setopt_array($ch, array(
        CURLOPT_URL        => AUT_FFTA_BASE . '/auth/login',
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
    ));
    $landing = curl_exec($ch);
    $post = null;
    $effUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    aut_ffta_debug('POST /auth/login http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . $effUrl . ' ' . aut_ffta_debug_page($landing));

    // Interrupted response: without this check, the final URL stays /auth/login and the cut
    // would be announced as a credentials refusal.
    if ($landing === false || curl_errno($ch)) {
        $error = aut_t('SsoInterrupted', curl_error($ch));
        $errCode = 'NETWORK';
        curl_close($ch);
        return null;
    }
    $signedIn = (strpos($landing, '/auth/select-structure/') !== false);
    if (!$signedIn && ($out = aut_ffta_outage($ch, $landing)) !== '') {
        aut_ffta_debug('=> unavailability detected on POST /auth/login');
        $error = $out;
        $errCode = 'OUTAGE';
        curl_close($ch);
        return null;
    }

    // $signedIn first: POSITIVE marker (select-structure menu). The URL heuristic alone would
    // take a home page served WITHOUT redirection from /auth/login for a failure.
    if (!$signedIn && strpos($effUrl, '/login') !== false) {
        // Back on /login WITHOUT a sign-in form: Laravel shows the form again when the password is
        // refused — anything else is not a credentials refusal (error page, captive portal,
        // maintenance).
        if (!aut_ffta_is_login_page($landing)) {
            $error = aut_ffta_outage_msg(aut_t('SsoSpace'), intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)), true);
            $errCode = 'OUTAGE';
        } else {
            $error = aut_t('SsoBadCredentials');
            $errCode = 'BAD_CREDENTIALS';
        }
        curl_close($ch);
        return null;
    }
    // Intermediate 2-step MFA page (Laravel Fortify: /auth/two-factor-challenge)
    if (!$signedIn
        && preg_match('/(two[-_]?factor|deux.?[ée]tapes|double.?authentification|authenticator|otp|2fa)/i', $landing)) {
        aut_ffta_debug('=> MFA challenge page detected');
        if ($otp === '') {
            $error = 'MFA_NEEDED';        // the code was not typed
            $errCode = 'MFA_NEEDED';
            curl_close($ch);
            return null;
        }
        $landing = aut_ffta_mfa_second_step($ch, $landing, $otp);
        $signedIn = (strpos($landing, '/auth/select-structure/') !== false);
        if (!$signedIn && ($out = aut_ffta_outage($ch, $landing)) !== '') {
            aut_ffta_debug('=> unavailability detected after the 2nd MFA step');
            $error = $out;
            $errCode = 'OUTAGE';
            curl_close($ch);
            return null;
        }
        // still the challenge page = code refused/expired; otherwise signed in
        if (!$signedIn && preg_match('/two[-_]?factor|deux.?[ée]tapes/i', $landing)) {
            aut_ffta_debug('=> MFA code refused (still the challenge page)');
            $error = 'MFA_BAD_CODE';
            $errCode = 'MFA_BAD_CODE';
            curl_close($ch);
            return null;
        }
        aut_ffta_debug('=> second MFA step accepted');
    }
    return $ch;   // signed in; the caller MUST curl_close()
}

/**
 * Second MFA step (best effort, not tested against the real FFTA): sends the MFA code back to
 * the form of the challenge page, discovering its action and the name of the field on the fly.
 * Returns the HTML of the resulting page.
 */
function aut_ffta_mfa_second_step($ch, $page, $otp) {
    // fresh CSRF of the challenge page
    $csrf = null;
    foreach (array(
        '/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/',
        '/name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\']/',
        '/content=["\']([^"\']+)["\'][^>]*name=["\']csrf-token["\']/',
    ) as $p) {
        if (preg_match($p, $page, $m)) { $csrf = $m[1]; break; }
    }
    // form action (falls back to the login endpoint)
    $action = AUT_FFTA_BASE . '/auth/login';
    if (preg_match('#<form[^>]*action=["\']([^"\']+)["\']#i', $page, $m) && $m[1] !== '') {
        $action = (strpos($m[1], 'http') === 0) ? $m[1] : AUT_FFTA_BASE . '/' . ltrim($m[1], '/');
    }
    // name of the code field: 'code' (Fortify) is PREFERRED and 'recovery_code'
    // (backup codes, not the app's code) EXCLUDED
    $names = array();
    if (preg_match_all('#<input\b[^>]*>#i', $page, $inp)) {
        foreach ($inp[0] as $tag) {
            if (preg_match('/type=["\'](hidden|submit|password|checkbox|radio)["\']/i', $tag)) continue;
            if (preg_match('/name=["\']([^"\']+)["\']/', $tag, $mm)) $names[] = $mm[1];
        }
    }
    $field = '';
    foreach (array('code', 'otp', 'two_factor_code', 'authenticator_code', 'pin') as $cand) {
        if (in_array($cand, $names, true)) { $field = $cand; break; }
    }
    if ($field === '') {
        foreach ($names as $n) {
            // bytes: an HTML field name is ASCII
            if (stripos($n, 'recovery') !== false || strtolower($n) === '_token') continue;
            if (preg_match('/(code|otp|2fa|pin|digit|chiffre)/i', $n)) { $field = $n; break; }
        }
    }
    if ($field === '') $field = 'code';
    aut_ffta_debug('MFA step2 action=' . $action . ' field=' . $field . ' csrf=' . ($csrf ? 'yes' : 'no'));

    $post = array($field => $otp);
    if ($csrf) $post['_token'] = $csrf;
    curl_setopt_array($ch, array(
        CURLOPT_URL        => $action,
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
    ));
    $res = curl_exec($ch);
    $post = null;
    aut_ffta_debug('MFA step2 POST http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' ' . aut_ffta_debug_page($res));
    return (string)$res;
}

/**
 * Extracts the structures from the "select-structure" menu of the officers' space.
 * Returns: array of array('id','code','name','roles').
 */
function aut_ffta_parse_structures($html) {
    $out = array();
    if (!preg_match_all('#<a\s+href="[^"]*/auth/select-structure/(\d+)".*?</a>#s', $html, $blocks, PREG_SET_ORDER)) {
        return $out;
    }
    foreach ($blocks as $b) {
        $s = array('id' => intval($b[1]), 'code' => '', 'name' => '', 'roles' => '');
        // ⚠️ The badge is NOT always numeric: REGIONAL committees carry "CR07" (not "0700000").
        // A [0-9]+ pattern made the whole preg_match fail → code AND name empty → the structure
        // was SILENTLY dropped further down, so no regional committee could get access (real
        // bug: a league's "Gestionnaire Sportif" account did not see its CR view).
        // aut_ffta_map_structure already handles "CR07" (it only keeps the digits): it is the
        // READING that had to be widened.
        if (preg_match('#<span class="badge[^"]*"[^>]*>\s*([^<]+?)\s*</span>\s*([^<]+)#', $b[0], $m)) {
            $s['code'] = trim($m[1]);
            // Extraction by regex on the raw HTML: an apostrophe in the name arrives encoded as an
            // entity ("Tir à l&#039;Arc") — never decoded without this, it went through as is up
            // to the bar (#aut-bar showed "&#039;" instead of the apostrophe, real bug reported
            // and checked).
            $s['name'] = html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('#<span class="ml-3[^"]*">\s*(.*?)\s*</span>#s', $b[0], $m)) {
            $s['roles'] = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($m[1]))), ENT_QUOTES, 'UTF-8');
        }
        if ($s['code'] !== '' || $s['name'] !== '') $out[] = $s;
    }
    return $out;
}

/* Officers' space roles REQUIRED to manage competitions here: the SPORTS roles,
 * "Gestionnaire Sportif" or "Administrateur Sportif".
 *
 * ⚠️ The "Sportif" qualifier is MANDATORY in the pattern. The old pattern
 * "Gestionnaire|Administrateur" looked for a substring and so let "Gestionnaire CLUB",
 * "Administrateur" alone, etc. through. Here "Consultant Club", "Gestionnaire Club" and every
 * other role are refused, with an explicit message. Override: config.local.json →
 * "sso": {"required_role_regex": "...", "required_role_label": "..."} (the label is only a text
 * to show). */
define('AUT_FFTA_ROLE_REGEX', '(Gestionnaire|Administrateur)\s+Sportif');

function aut_ffta_role_regex() {
    $cfg = aut_local_config()['sso'] ?? array();
    return (string) ($cfg['required_role_regex'] ?? AUT_FFTA_ROLE_REGEX);
}

function aut_ffta_role_label() {
    $cfg = aut_local_config()['sso'] ?? array();
    return (string) ($cfg['required_role_label'] ?? aut_t('SsoRolesDefault'));
}

/**
 * Does the roles label of a structure give the right to manage? Single source of truth (used by
 * the mapping AND by the refusal message). The non-breaking spaces of the FFTA page are
 * normalised: "Gestionnaire<nbsp>Sportif" must match as an ordinary space.
 */
function aut_ffta_role_ok($roles) {
    $required = aut_ffta_role_regex();
    if ($required === '') return true;                       // filter turned off (config)
    $roles = str_replace(array("\xC2\xA0", "\xE2\x80\xAF"), ' ', (string) $roles);
    return (bool) preg_match('/' . str_replace('/', '\/', $required) . '/iu', $roles);
}

/**
 * Officers' space structure → ianseo role/scope, or null when not managed.
 * Told apart by the roles label (the CD/CR codes look alike):
 *  - "Fédération"            → FED
 *  - "Comité Départemental"  → CD, scope = first 2 digits (60000 → 60)
 *  - "Comité Régional"       → CR, scope = first 2 digits
 *  - otherwise badge ≥ 5 digits → CLUB, scope = full approval number
 * The person must also hold the required role (aut_ffta_role_ok).
 */
function aut_ffta_map_structure($st) {
    if (!aut_ffta_role_ok($st['roles'])) {
        return null;
    }
    $hay = $st['roles'] . ' ' . $st['name'];
    // dept/league number = first 2 digits of the code (robust whether the badge is
    // "60000", "CR07" or "0700000" — the digits are kept, 2A/2B preserved)
    $digits = preg_replace('/[^0-9AB]/i', '', strtoupper($st['code']));
    // bytes: the digits of a structure code are ASCII
    $twoDigits = substr($digits, 0, 2);
    if (preg_match('/F[ée]d[ée]ration/iu', $st['roles']) || $st['code'] === '0') {
        return array('role' => AUT_ROLE_FED, 'scope' => '', 'label' => $st['name']);
    }
    if (preg_match('/Comit[ée]\s+D[ée]partemental/iu', $hay)) {
        return array('role' => AUT_ROLE_CD, 'scope' => $twoDigits,
                     'label' => aut_t('StructDept', array('name' => $st['name'], 'n' => $twoDigits)));
    }
    if (preg_match('/Comit[ée]\s+R[ée]gional/iu', $hay)) {
        return array('role' => AUT_ROLE_CR, 'scope' => $twoDigits,
                     'label' => aut_t('StructLeague', array('name' => $st['name'], 'n' => $twoDigits)));
    }
    // bytes: an approval number is ASCII digits
    if (strlen($st['code']) >= 5) {
        return array('role' => AUT_ROLE_CLUB, 'scope' => $st['code'],
                     'label' => $st['name'] . ' (' . $st['code'] . ')');
    }
    return null;
}

/**
 * Checks the credentials on the officers' space and returns the usable ianseo structures.
 * true = OK ($structures filled), false = refusal/error.
 */
function aut_ffta_verify($username, $password, $otp, &$structures, &$error, &$cookieFileOut = null, &$errCode = null) {
    $structures = array();
    $landing = '';
    $error = '';
    $errCode = '';
    $ch = aut_ffta_curl_login($username, $password, $otp, $landing, $error, $cookieFileOut, $errCode);
    if (!$ch) {
        // readable MFA messages for the user
        if ($error === 'MFA_NEEDED') {
            $error = aut_t('SsoMfaNeeded');
        } elseif ($error === 'MFA_BAD_CODE') {
            $error = aut_t('SsoMfaBad');
        }
        return false;
    }

    $raw = aut_ffta_parse_structures($landing);
    if (!count($raw)) {
        // the landing page varies (after MFA especially): try again on the home page
        curl_setopt_array($ch, array(CURLOPT_URL => AUT_FFTA_BASE . '/', CURLOPT_HTTPGET => true, CURLOPT_POST => false));
        $home = curl_exec($ch);
        aut_ffta_debug('GET / (retry structures) http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
            . ' ' . aut_ffta_debug_page($home));
        $raw = aut_ffta_parse_structures($home);
    }
    curl_close($ch);

    foreach ($raw as $st) {
        if ($m = aut_ffta_map_structure($st)) $structures[] = $m;
    }
    aut_ffta_debug('verify: raw structures=' . count($raw) . ' usable=' . count($structures));

    // authentication SUCCEEDED even without a usable structure: the caller decides
    // (an ADMIN account keeps its admin view; a mere account is refused)
    if (!count($structures)) {
        $error = aut_ffta_no_structure_reason($raw);
        $errCode = 'NO_STRUCTURE';
    }
    return true;
}

/**
 * Refusal message when no structure is usable. The COMMON case — the person does have
 * structures, but with an insufficient role (Consultant Club, Gestionnaire Club…) — is told
 * apart from an unmanaged type of structure or an unreadable page: without that, the user does
 * not know what to ask for. The labels come from the FFTA page; they are escaped when shown
 * (login.php).
 */
function aut_ffta_no_structure_reason($raw) {
    if (!count($raw)) {
        return aut_t('SsoNoStructFound');
    }
    $bad = array();
    foreach ($raw as $st) {
        if (aut_ffta_role_ok($st['roles'] ?? '')) continue;   // role OK → it is the TYPE that is not managed
        $name  = trim((string) ($st['name'] ?? '')) ?: trim((string) ($st['code'] ?? ''));
        $roles = trim((string) ($st['roles'] ?? ''));
        $bad[] = $name . ($roles !== '' ? ' — ' . $roles : '');
    }
    if (!count($bad)) {
        return aut_t('SsoNoManagedStruct');
    }
    return aut_t('SsoRolesRequired', aut_ffta_role_label()) . ' '
         . aut_t('SsoRolesNone', implode(' ; ', array_slice($bad, 0, 4)) . (count($bad) > 4 ? ' …' : '')) . ' '
         . aut_t('SsoRolesAsk');
}

/**
 * Creates/refreshes the account of an SSO user after FFTA authentication: stores the list of
 * their structures (AuStructs). For a non-admin account, AuRole/AuScope = highest-level
 * structure (fallback base). The ADMIN role is NEVER set nor removed here (explicit grant only).
 * Returns the user object or null ($error filled).
 */
function aut_sso_sync($username, $structures, &$error) {
    $error = '';
    // bytes: identifiers have always been folded this way; mb_strtolower could make an existing account stop matching
    $username = strtolower(trim($username));
    $clean = array();
    foreach ($structures as $st) {
        if (($st['role'] ?? '') == AUT_ROLE_ADMIN) continue;   // never an admin through SSO
        $clean[] = array('role' => $st['role'], 'scope' => (string)$st['scope'], 'label' => (string)$st['label']);
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);

    // highest-level structure (fallback base for non-admin accounts)
    $best = null;
    foreach ($clean as $st) {
        if (!$best || aut_view_rank($st['role']) > aut_view_rank($best['role'])) $best = $st;
    }

    $u = aut_get_user($username);
    if ($u) {
        if (!$u->AuActive) { $error = aut_t('SsoAccountDisabled'); return null; }
        if ($u->AuPassword !== '') {
            $error = aut_t('SsoLocalAccount');
            return null;
        }
        $set = "AuStructs=" . StrSafe_DB($json);
        if ($u->AuRole != AUT_ROLE_ADMIN && $best) {
            $set .= ", AuRole=" . StrSafe_DB($best['role']) . ", AuScope=" . StrSafe_DB($best['scope']);
        }
        safe_w_sql("UPDATE AuthUsers SET $set WHERE AuId={$u->AuId}");
    } else {
        if (!$best) {
            $error = aut_t('SsoRolesRequired', aut_ffta_role_label()) . ' ' . aut_t('SsoRolesNoneShort');
            return null;
        }
        safe_w_sql("INSERT INTO AuthUsers (AuUsername, AuPassword, AuRole, AuScope, AuMustChangePwd, AuName, AuStructs)
            VALUES (" . StrSafe_DB($username) . ", '', " . StrSafe_DB($best['role']) . ","
            . StrSafe_DB($best['scope']) . ", 0, " . StrSafe_DB(aut_t('SsoAccountName')) . ", " . StrSafe_DB($json) . ")");
        aut_log('SSO_PROVISION', $username);
    }
    return aut_get_user($username);
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

/* ------------------------------------------------------------------ */
/* Session extranet FFTA (extranet.ffta.fr) — convention inter-modules  */
/*                                                                      */
/* The extranet is an application DISTINCT from the officers' space     */
/* (Kareline/PHPSESSID, no MFA): the cookie of one opens nothing on the */
/* other. The credentials being synchronised, AUTH opens at sign-in     */
/* a SECOND session, on the extranet, and only keeps its cookie.        */
/*                                                                      */
/* Session convention published for the other modules:                 */
/*   $_SESSION['FFTA_EXTRANET_COOKIE'] = path of the cookie jar (0600)  */
/*   $_SESSION['FFTA_EXTRANET_BASE']   = base URL of the extranet       */
/* The consuming modules use them WHEN they exist, and keep their own   */
/* sign-in form as a fallback (AUTH may be missing, the account may be  */
/* local, the extranet session may have expired).                       */
/* ------------------------------------------------------------------ */

define('AUT_EXTRANET_BASE', 'https://extranet.ffta.fr');

function aut_extranet_base() {
    $c = aut_local_config()['extranet'] ?? array();
    return rtrim($c['base'] ?? AUT_EXTRANET_BASE, '/');
}

function aut_extranet_enabled() {
    $c = aut_local_config()['extranet'] ?? array();
    return !array_key_exists('enabled', $c) || !empty($c['enabled']);
}

/**
 * Path of the cookie jar, derived from the session token (AUTH_Pwd): it therefore survives the
 * core's CreateTourSession/EraseTourSession, which empty everything else.
 */
function aut_extranet_cookie_path() {
    $token = (string)($_SESSION['AUTH_Pwd'] ?? '');
    if ($token === '') return '';
    return sys_get_temp_dir() . '/ffta_ext_' . hash('sha256', 'extranet|' . $token) . '.ck';
}

/**
 * Opens the extranet session with the officers' space credentials.
 * Called during sign-in, BEFORE the ianseo session exists: the cookie lands in a temporary file
 * that aut_extranet_bind() will move.
 * Silent failure: the extranet must never block the sign-in to ianseo.
 */
function aut_extranet_open($username, $password) {
    if (!aut_extranet_enabled()) return false;

    $base = aut_extranet_base();
    $tmp  = tempnam(sys_get_temp_dir(), 'aut_ex_');
    @chmod($tmp, 0600);

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $tmp,
        CURLOPT_COOKIEFILE     => $tmp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ianseo-ffta/auth)',
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
    ));

    // GET: sets the PHPSESSID, then POST of the identification form
    curl_setopt($ch, CURLOPT_URL, $base . '/');
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_exec($ch);

    curl_setopt_array($ch, array(
        CURLOPT_URL        => $base . '/',
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query(array(
            'login[identifiant]' => $username,
            'login[idpassword]'  => $password,
        )),
    ));
    $body = curl_exec($ch);
    $fail = curl_errno($ch) || $body === false
        || strpos((string)$body, 'name="login[identifiant]"') !== false;   // sign-in page served again
    curl_close($ch);

    if ($fail) {
        @unlink($tmp);
        aut_log('EXTRANET_FAIL', $username);
        return false;
    }

    $_SESSION['FFTA_EXTRANET_TMP'] = $tmp;
    aut_log('EXTRANET_OK', $username);
    return true;
}

/** ianseo session open (token set): the extranet cookie takes its final path. */
function aut_extranet_bind() {
    $tmp = $_SESSION['FFTA_EXTRANET_TMP'] ?? '';
    unset($_SESSION['FFTA_EXTRANET_TMP']);
    $path = aut_extranet_cookie_path();
    if ($tmp !== '' && $path !== '' && file_exists($tmp)) {
        @rename($tmp, $path);
        @chmod($path, 0600);
    }
}

/** Publishes the convention in the session (called at every request). */
function aut_extranet_publish() {
    $path = aut_extranet_cookie_path();
    if ($path !== '' && file_exists($path)) {
        $_SESSION['FFTA_EXTRANET_COOKIE'] = $path;
        $_SESSION['FFTA_EXTRANET_BASE']   = aut_extranet_base();
    } else {
        unset($_SESSION['FFTA_EXTRANET_COOKIE'], $_SESSION['FFTA_EXTRANET_BASE']);
    }
}

/** ianseo sign-out: the extranet cookie is destroyed with the session. */
function aut_extranet_forget() {
    $path = aut_extranet_cookie_path();
    if ($path !== '' && file_exists($path)) @unlink($path);
    $tmp = $_SESSION['FFTA_EXTRANET_TMP'] ?? '';
    if ($tmp !== '' && file_exists($tmp)) @unlink($tmp);
    unset($_SESSION['FFTA_EXTRANET_COOKIE'], $_SESSION['FFTA_EXTRANET_BASE'], $_SESSION['FFTA_EXTRANET_TMP']);
}

/* ------------------------------------------------------------------ */
/* Officers' space session (dirigeant.ffta.fr) — shared convention      */
/*                                                                      */
/* Unlike the extranet, NO new sign-in is made: the SSO sign-in         */
/* (aut_ffta_curl_login, MFA included) already opens an officers'       */
/* space session. ITS cookie is captured (aut_dirigeant_stash) to       */
/* publish it — the MFA code being single-use, a second sign-in         */
/* would fail. Publishes:                                                */
/*   $_SESSION['FFTA_DIRIGEANT_COOKIE'] = path of the cookie jar (0600)  */
/*   $_SESSION['FFTA_DIRIGEANT_BASE']   = base URL of the space          */
/* ------------------------------------------------------------------ */

define('AUT_DIRIGEANT_BASE', AUT_FFTA_BASE);   // dirigeant.ffta.fr

function aut_dirigeant_enabled() {
    $c = aut_local_config()['dirigeant'] ?? array();
    return !array_key_exists('enabled', $c) || !empty($c['enabled']);
}
function aut_dirigeant_base() {
    $c = aut_local_config()['dirigeant'] ?? array();
    return rtrim($c['base'] ?? AUT_DIRIGEANT_BASE, '/');
}
function aut_dirigeant_cookie_path() {
    $token = (string)($_SESSION['AUTH_Pwd'] ?? '');
    if ($token === '') return '';
    return sys_get_temp_dir() . '/ffta_dir_' . hash('sha256', 'dirigeant|' . $token) . '.ck';
}
/**
 * Captures the cookie of the officers' space session opened by the SSO sign-in.
 * Called at sign-in (before the ianseo session): copied to a temporary file that
 * aut_dirigeant_bind() will move to the path derived from the token.
 */
function aut_dirigeant_stash($cookieFile) {
    if (!aut_dirigeant_enabled() || !$cookieFile || !file_exists($cookieFile)) return;
    $tmp = tempnam(sys_get_temp_dir(), 'aut_dir_');
    @chmod($tmp, 0600);
    if (@copy($cookieFile, $tmp)) {
        $_SESSION['FFTA_DIRIGEANT_TMP'] = $tmp;
    } else {
        @unlink($tmp);
    }
}
function aut_dirigeant_bind() {
    $tmp = $_SESSION['FFTA_DIRIGEANT_TMP'] ?? '';
    unset($_SESSION['FFTA_DIRIGEANT_TMP']);
    $path = aut_dirigeant_cookie_path();
    if ($tmp !== '' && $path !== '' && file_exists($tmp)) {
        @rename($tmp, $path);
        @chmod($path, 0600);
    }
}
function aut_dirigeant_publish() {
    $path = aut_dirigeant_cookie_path();
    if ($path !== '' && file_exists($path)) {
        $_SESSION['FFTA_DIRIGEANT_COOKIE'] = $path;
        $_SESSION['FFTA_DIRIGEANT_BASE']   = aut_dirigeant_base();
    } else {
        unset($_SESSION['FFTA_DIRIGEANT_COOKIE'], $_SESSION['FFTA_DIRIGEANT_BASE']);
    }
}
function aut_dirigeant_forget() {
    $path = aut_dirigeant_cookie_path();
    if ($path !== '' && file_exists($path)) @unlink($path);
    $tmp = $_SESSION['FFTA_DIRIGEANT_TMP'] ?? '';
    if ($tmp !== '' && file_exists($tmp)) @unlink($tmp);
    unset($_SESSION['FFTA_DIRIGEANT_COOKIE'], $_SESSION['FFTA_DIRIGEANT_BASE'], $_SESSION['FFTA_DIRIGEANT_TMP']);
}

function aut_csrf_token() {
    if (empty($_SESSION['AUT_CSRF'])) $_SESSION['AUT_CSRF'] = bin2hex(random_bytes(16));
    return $_SESSION['AUT_CSRF'];
}

function aut_csrf_field() {
    return '<input type="hidden" name="aut_csrf" value="' . aut_csrf_token() . '">';
}

function aut_csrf_check() {
    return isset($_POST['aut_csrf'], $_SESSION['AUT_CSRF'])
        && hash_equals($_SESSION['AUT_CSRF'], $_POST['aut_csrf']);
}

/* ------------------------------------------------------------------ */
/* Deployment of the dist/ files to Modules/Authentication/             */
/* ------------------------------------------------------------------ */

function aut_dist_files() {
    return array('AuthFunctions.php', 'BlockFunction.php', 'LogIn.php', 'LogOut.php',
        'ChangePassword.php', 'Setup2FA.php', 'index.php');
}

function aut_dist_dir() {
    return aut_module_dir() . '/dist';
}

function aut_auth_dir() {
    global $CFG;
    return $CFG->DOCUMENT_PATH . 'Modules/Authentication';
}

function aut_dist_status() {
    $st = array('deployed' => true, 'drift' => false, 'files' => array());
    foreach (aut_dist_files() as $f) {
        $src = aut_dist_dir() . '/' . $f;
        $dst = aut_auth_dir() . '/' . $f;
        $ok = is_file($dst);
        $same = $ok && is_file($src) && md5_file($src) === md5_file($dst);
        if (!$ok) $st['deployed'] = false;
        if ($ok && !$same) $st['drift'] = true;
        $st['files'][$f] = array('deployed' => $ok, 'same' => $same);
    }
    return $st;
}

function aut_deploy(&$errors = array()) {
    $dst = aut_auth_dir();
    if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
        $errors[] = aut_t('DeployMkdir', $dst);
        return false;
    }
    $ok = true;
    foreach (aut_dist_files() as $f) {
        if (!@copy(aut_dist_dir() . '/' . $f, $dst . '/' . $f)) {
            $errors[] = aut_t('DeployCopy', $f);
            $ok = false;
        }
    }
    // Sets the self-redeployment safety net (best effort: does not block the deployment).
    $e = '';
    if (!aut_ensure_selfheal($e)) $errors[] = aut_t('DeploySelfheal', $e);
    return $ok;
}

/**
 * PHP SELF-REDEPLOYMENT block to write into Common/config.inc.php (local file, kept through
 * ianseo updates, loaded BEFORE Common/BlockDefines.php). When USERAUTH is on but
 * Modules/Authentication/BlockFunction.php was erased by an ianseo update, it copies the hooks
 * back from dist/ (kept) → no more "fail-closed" fatal error, no more manual redeployment.
 * Framed by markers for an IDEMPOTENT insertion.
 */
function aut_selfheal_block() {
    return "// === AUTH-SELFHEAL BEGIN (module Custom/AUTH — do not edit by hand) ===\n"
        . "if (!empty(\$CFG->USERAUTH)) {\n"
        . "    \$bkAuthDir  = \$CFG->DOCUMENT_PATH . 'Modules/Authentication';\n"
        . "    \$bkAuthDist = \$CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/dist';\n"
        . "    if (!is_file(\$bkAuthDir . '/BlockFunction.php') && is_dir(\$bkAuthDist)) {\n"
        . "        @mkdir(\$bkAuthDir, 0755, true);\n"
        . "        foreach (array('AuthFunctions.php','BlockFunction.php','LogIn.php','LogOut.php','ChangePassword.php','Setup2FA.php','index.php') as \$bkF) @copy(\$bkAuthDist.'/'.\$bkF, \$bkAuthDir.'/'.\$bkF);\n"
        . "        unset(\$bkF);\n"
        . "    }\n"
        . "    unset(\$bkAuthDir, \$bkAuthDist);\n"
        . "}\n"
        . "// === AUTH-SELFHEAL END ===";
}

/**
 * Makes sure the self-redeployment block is in Common/config.inc.php.
 * Idempotent: does nothing when already there (AUTH-SELFHEAL marker). Inserted just before the
 * last PHP closing tag. Called at deployment and at activation.
 */
function aut_ensure_selfheal(&$error = '') {
    global $CFG;
    $f = $CFG->DOCUMENT_PATH . 'Common/config.inc.php';
    if (!is_file($f)) { $error = aut_t('CfgMissing'); return false; }
    $c = file_get_contents($f);
    if ($c === false) { $error = aut_t('CfgRead'); return false; }
    if (strpos($c, 'AUTH-SELFHEAL') !== false) return true;   // already there

    $block = aut_selfheal_block();
    $pos = strrpos($c, '?>');
    if ($pos !== false) {
        // bytes: offsets come from strrpos(), which counts bytes
        $c = substr($c, 0, $pos) . $block . "\n" . substr($c, $pos);
    } else {
        $c = rtrim($c) . "\n\n" . $block . "\n";
    }
    @copy($f, $f . '.bak');
    if (@file_put_contents($f, $c) === false) { $error = aut_t('CfgWrite'); return false; }
    return true;
}

/** State of the USERAUTH flag in Common/config.inc.php: 'on' | 'off' | 'absent' | 'nofile' */
function aut_userauth_flag_state() {
    global $CFG;
    $f = $CFG->DOCUMENT_PATH . 'Common/config.inc.php';
    if (!is_file($f)) return 'nofile';
    $c = file_get_contents($f);
    if (!preg_match('/\$CFG->USERAUTH\s*=\s*(true|false)/i', $c, $m)) return 'absent';
    // bytes: "true" or "false" from the pattern above
    return strtolower($m[1]) == 'true' ? 'on' : 'off';
}

/**
 * Turns USERAUTH on/off in Common/config.inc.php (survives ianseo updates, unlike config.php
 * which is overwritten). .bak copy before writing.
 */
function aut_set_userauth($on, &$error = '') {
    global $CFG;
    $f = $CFG->DOCUMENT_PATH . 'Common/config.inc.php';
    if (!is_file($f)) { $error = aut_t('CfgMissing'); return false; }
    $c = file_get_contents($f);
    $line = '$CFG->USERAUTH = ' . ($on ? 'true' : 'false') . ';';
    if (preg_match('/\$CFG->USERAUTH\s*=\s*(true|false)\s*;/i', $c)) {
        $new = preg_replace('/\$CFG->USERAUTH\s*=\s*(true|false)\s*;/i', $line, $c, 1);
    } elseif (strpos($c, '?>') !== false) {
        $new = str_replace('?>', "\n// Multi-account hosting (module Custom/AUTH)\n$line\n?>", $c);
    } else {
        $new = $c . "\n// Multi-account hosting (module Custom/AUTH)\n$line\n";
    }
    if (!@copy($f, $f . '.bak')) { $error = aut_t('CfgBak'); return false; }
    if (@file_put_contents($f, $new) === false) { $error = aut_t('CfgWrite'); return false; }
    // On activation, set the self-redeployment safety net (survives ianseo updates).
    if ($on) { $e = ''; aut_ensure_selfheal($e); }
    return true;
}
