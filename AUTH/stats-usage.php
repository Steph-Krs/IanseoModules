<?php
/**
 * stats-usage.php — audience measurement of the shared server (aggregated, respectful).
 *
 * Goal: a usage statistics page (admin/stats.php) WITHOUT tracking people. Two principles,
 * in line with the CNIL doctrine on audience measurement exempt from consent
 * (https://www.cnil.fr/fr/cookies-solutions-pour-les-outils-de-mesure-daudience):
 *
 *  1. Only AGGREGATED COUNTERS are stored (page views per hour/day/space, AuthUsage) — no
 *     personal data, no IP, no named path.
 *  2. Unique visitors are deduplicated per day in AuthUsageSeen:
 *     - a SIGNED-IN user is counted by their account identity (already known, no cookie);
 *     - an ANONYMOUS visitor (home page) receives a first-party audience measurement cookie,
 *       opaque, not shared between sites, lasting ≤ 13 months, never read on the client side
 *       (HttpOnly). It is the ONLY non-essential cookie, and it falls under the exemption
 *       above.
 *  3. The TYPE OF DEVICE (phone / tablet / computer) is derived from the browser at each page
 *     and only this word is kept, in the key of both tables — never the User-Agent string
 *     itself. It helps fit the ergonomics to the real use, and every figure of the statistics
 *     page can be filtered by device. Robots (search engines, monitoring…) are no longer
 *     counted at all: without a cookie, each of their passes created one more "unique
 *     visitor".
 *
 * The tracking must NEVER interrupt a page: stats-usage.php is loaded from critical paths
 * (organiser bootstrap, bk_require_archer). The only real safety net is the list of tolerated
 * errors passed to safe_w_sql() (aut_stats_soft): try/catch does NOT catch safe_error(),
 * which leaves through exit: a SQL query in error kills the page.
 */

if (!function_exists('safe_r_sql')) return;   // outside an ianseo context: do nothing

/** Measurement turned on/off through config.local.json → "stats_enabled" (default: on). */
function aut_stats_enabled() {
    $c = function_exists('aut_local_config') ? aut_local_config() : array();
    return !array_key_exists('stats_enabled', $c) || !empty($c['stats_enabled']);
}

/** Time zone of the day/hour buckets: stable and readable (independent of the MySQL
 *  time_zone that ianseo changes per competition, and of the UTC forced on PHP). Default
 *  Europe/Paris. */
function aut_stats_tz() {
    static $tz = null;
    if ($tz instanceof DateTimeZone) return $tz;
    $name = 'Europe/Paris';
    $c = function_exists('aut_local_config') ? aut_local_config() : array();
    if (!empty($c['stats_timezone']) && is_string($c['stats_timezone'])) $name = $c['stats_timezone'];
    try { $tz = new DateTimeZone($name); } catch (\Throwable $e) { $tz = new DateTimeZone('Europe/Paris'); }
    return $tz;
}

/** Retention of the measurement. UsageSeen (pseudonymous) follows the log retention;
 *  AuthUsage (non-personal aggregates) is kept up to 25 months (CNIL limit). */
function aut_stats_seen_days()  { return function_exists('aut_log_retention_days') ? aut_log_retention_days() : 180; }
function aut_stats_agg_days()   { return 760; }   // ~25 months

/**
 * SQL errors tolerated by the audience measurement.
 *
 * ⚠️ `try/catch` protects from NOTHING here: `safe_w_sql()` calls `safe_error()`, which does
 * `header('HTTP/1.0 404')` + `exit` — no exception is raised, so nothing to catch. The only
 * safety net is the 3rd argument of `safe_w_sql()`: the list of ACCEPTED error numbers.
 * 0 = no error, 1146 = missing table, 1142/1044 = insufficient rights. An audience
 * measurement must never bring a page down.
 */
function aut_stats_soft() { return array(0, 1044, 1054, 1142, 1146); }   // 1054 = column not migrated yet

/** Device classes that are stored and can be filtered on ('' = measured before v1.1.8). */
function aut_stats_devices_list() { return array('mobile', 'tablet', 'desktop'); }

/** Validated device filter: one of aut_stats_devices_list(), or '' for all devices. */
function aut_stats_dev($device) {
    $device = (string) $device;
    return in_array($device, aut_stats_devices_list(), true) ? $device : '';
}

/** SQL fragment restricting a query to one device ('' = no restriction). */
function aut_stats_dev_sql($col, $device) {
    $d = aut_stats_dev($device);
    return $d === '' ? '' : " AND $col = " . StrSafe_DB($d);
}

function aut_stats_has_column($table, $column) {
    $q = safe_r_sql("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = " . StrSafe_DB($table) . " AND COLUMN_NAME = " . StrSafe_DB($column), false, true);
    $r = $q ? safe_fetch($q) : null;
    return $r && (int) $r->n > 0;
}

/**
 * Creates the measurement tables and migrates them (idempotent). Safe in any context.
 *
 * The device class is PART OF THE KEY of both tables, so that every figure of the
 * statistics page (views, visitors, days, hours, top pages) can be filtered by device.
 * Rows recorded before v1.1.8 keep an empty device: they count in "all devices" only.
 * Runs once per session (flag with a version, replayed after an update): this is
 * called on every tracked page, and the checks cost a few metadata queries.
 */
function aut_stats_ensure_schema() {
    static $done = false;
    if ($done) return;
    $done = true;
    require_once __DIR__ . '/names-lib.php';
    aut_table_names();   // before any CREATE: see names-lib.php
    $flag = '_aut_stats_schema_v3';
    if (!empty($_SESSION[$flag])) return;
    $soft = aut_stats_soft();
    // Two first requests may migrate at the same time: the loser gets "duplicate column"
    // (1060), "multiple primary key" (1068) or "can't drop" (1091) — all harmless.
    $mig = array_merge($soft, array(1060, 1068, 1091));
    try {
        safe_w_sql("CREATE TABLE IF NOT EXISTS AuthUsage (
            UsDay    DATE             NOT NULL,
            UsHour   TINYINT UNSIGNED NOT NULL,
            UsSpace  VARCHAR(8)       NOT NULL,
            UsPage   VARCHAR(48)      NOT NULL,
            UsDevice VARCHAR(8)       NOT NULL DEFAULT '',
            UsViews  INT UNSIGNED     NOT NULL DEFAULT 0,
            PRIMARY KEY (UsDay, UsHour, UsSpace, UsPage, UsDevice)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", false, $soft);
        safe_w_sql("CREATE TABLE IF NOT EXISTS AuthUsageSeen (
            UzDay    DATE        NOT NULL,
            UzSpace  VARCHAR(8)  NOT NULL,
            UzRef    VARCHAR(64) NOT NULL,
            UzDevice VARCHAR(8)  NOT NULL DEFAULT '',
            PRIMARY KEY (UzDay, UzSpace, UzRef, UzDevice)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", false, $soft);
        if (!aut_stats_has_column('AuthUsage', 'UsDevice')) {
            safe_w_sql("ALTER TABLE AuthUsage ADD COLUMN UsDevice VARCHAR(8) NOT NULL DEFAULT '' AFTER UsPage,
                DROP PRIMARY KEY, ADD PRIMARY KEY (UsDay, UsHour, UsSpace, UsPage, UsDevice)", false, $mig);
        }
        if (!aut_stats_has_column('AuthUsageSeen', 'UzDevice')) {
            safe_w_sql("ALTER TABLE AuthUsageSeen ADD COLUMN UzDevice VARCHAR(8) NOT NULL DEFAULT '' AFTER UzRef,
                DROP PRIMARY KEY, ADD PRIMARY KEY (UzDay, UzSpace, UzRef, UzDevice)", false, $mig);
        }
        // v1.1.8 kept the device in a separate table, without hour nor page: it could not
        // be filtered on, and its few days of data cannot be spread over hours and pages.
        safe_w_sql("DROP TABLE IF EXISTS AUT_UsageDevice", false, $mig);
        if (aut_stats_has_column('AuthUsage', 'UsDevice') && aut_stats_has_column('AuthUsageSeen', 'UzDevice')) {
            $_SESSION[$flag] = true;
        }
    } catch (\Throwable $e) { /* PHP errors only: SQL errors are neutralised by $soft */ }
}

/** Is this request a page view to measure? (GET, not XHR, not an asset/API/logo). Used as
 *  the universal guard of the tracking. */
function aut_stats_is_page() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
    // bytes: an ASCII server variable
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') return false;
    $s = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    // bytes: a path or a file name, compared as the file system does
    if ($s === '' || substr($s, -4) !== '.php') return false;
    if (stripos($s, '/Api/') !== false) return false;
    $base = basename($s);
    if (preg_match('/(ajax|autocomplete|tourlogo|logo|barcode|qrcode)/i', $base)) return false;
    return true;
}

/**
 * Device class of the current request: 'mobile' | 'tablet' | 'desktop' | 'bot'.
 * Only this word is ever stored, never the User-Agent string.
 *
 * Order matters: robots first (not users); then tablets, because an Android tablet
 * says "Android" without "Mobile"; then phones, using the Chromium client hint
 * Sec-CH-UA-Mobile when present (sent by default, more reliable than the UA string).
 * Known limit: iPadOS 13+ in its default "desktop" mode announces itself as a Mac and
 * is counted as a computer — telling them apart needs JavaScript (touch points).
 */
function aut_stats_device($ua = null, $chMobile = null) {
    $ua = (string) ($ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $chMobile = (string) ($chMobile ?? ($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? ''));
    if ($ua === '') return 'bot';
    if (preg_match('/bot\b|crawl|spider|slurp|facebookexternalhit|bingpreview|preview|monitor|uptime|curl\/|wget|python-|go-http|java\/|libwww|httpclient|headless|lighthouse/i', $ua)) return 'bot';
    if (preg_match('/iPad|Tablet|PlayBook|Kindle|Silk\/|Nexus (7|9|10)\b|SM-[TX]\d|Lenovo Tab|\bTab [A-Z0-9]/i', $ua)) return 'tablet';
    if (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false) return 'tablet';
    if ($chMobile === '?1') return 'mobile';
    if (preg_match('/Mobi|iPhone|iPod|Windows Phone|BlackBerry|BB10|Opera Mini|IEMobile/i', $ua)) return 'mobile';
    return 'desktop';
}

/** Normalised page key. For the organiser space (ianseo core, many scripts named
 *  index.php), the parent folder is prefixed to tell them apart. */
function aut_stats_page_key($space) {
    $s = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $parts = array_values(array_filter(explode('/', trim($s, '/')), 'strlen'));
    $base = preg_replace('/\.php$/i', '', end($parts) ?: 'index');
    if ($space === 'org' && count($parts) >= 2) $base = $parts[count($parts) - 2] . '/' . $base;
    $base = preg_replace('#[^A-Za-z0-9_/.\-]#', '', $base);
    return mb_substr($base !== '' ? $base : 'index', 0, 48);
}

/** Audience measurement cookie (anonymous visitors only). Opaque, first party, ≤ 13 months,
 *  HttpOnly (never exposed to the client). Returns the pseudonymous identifier. */
function aut_stats_audience_id() {
    static $id = null;
    if ($id !== null) return $id;
    $raw = $_COOKIE['aud'] ?? '';
    if (preg_match('/^[a-f0-9]{32}$/', $raw)) { $id = $raw; return $id; }
    $id = bin2hex(random_bytes(16));
    if (!headers_sent()) {
        global $CFG;
        $path = (isset($CFG->ROOT_DIR) && $CFG->ROOT_DIR !== '') ? $CFG->ROOT_DIR : '/';
        // bytes: an ASCII server variable
        $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
        @setcookie('aud', $id, array(
            'expires'  => time() + 34128000,   // 13 months: CNIL limit of the audience measurement cookie
            'path'     => $path,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }
    $_COOKIE['aud'] = $id;   // available from this request on
    return $id;
}

/**
 * Records a page view.
 *   $space: 'org' | 'archer' | 'public'
 *   $uid  : account identifier when signed in (counted by identity, no cookie);
 *           null → anonymous visitor (counted through the audience measurement cookie).
 * Self-guarded (aut_stats_is_page) and fully isolated (no error goes up).
 */
function aut_track($space, $uid = null) {
    try {
        if (!aut_stats_enabled() || !aut_stats_is_page()) return;
        $device = aut_stats_device();
        if ($device === 'bot') return;   // not a user: no view, no visitor, no cookie
        aut_stats_ensure_schema();

        $now  = new DateTime('now', aut_stats_tz());
        $day  = $now->format('Y-m-d');
        $hour = (int) $now->format('G');
        $page = aut_stats_page_key($space);
        $sp   = StrSafe_DB($space);

        // 3rd argument = tolerated errors: it is the ONLY safety net (the catch below does not
        // catch safe_error(), which leaves through exit).
        $soft = aut_stats_soft();
        $dev  = StrSafe_DB($device);
        safe_w_sql("INSERT INTO AuthUsage (UsDay, UsHour, UsSpace, UsPage, UsDevice, UsViews)
            VALUES (" . StrSafe_DB($day) . ", $hour, $sp, " . StrSafe_DB($page) . ", $dev, 1)
            ON DUPLICATE KEY UPDATE UsViews = UsViews + 1", false, $soft);

        $ref = ($uid !== null && $uid !== '') ? ('u:' . $uid) : ('a:' . aut_stats_audience_id());
        // $ref is ASCII (u:<id> or a:<32 hex>): cutting at 64 bytes is safe.
        safe_w_sql("INSERT IGNORE INTO AuthUsageSeen (UzDay, UzSpace, UzRef, UzDevice)
            VALUES (" . StrSafe_DB($day) . ", $sp, " . StrSafe_DB(substr($ref, 0, 64)) . ", $dev)", false, $soft);
    } catch (\Throwable $e) {
        // PHP errors only (date, cookie…): the SQL errors are neutralised by $soft —
        // safe_error() raises nothing, it leaves.
    }
}

/**
 * Purge of the measurement: UsageSeen at the log retention, aggregates at 25 months.
 *
 * ⚠️ REAL OUTAGE (a user's server, Sept. 2026): "Error 1146: Table 'xxx.AuthUsageSeen'
 * doesn't exist" in the middle of a page. An installation updated from a version older than
 * the audience measurement does not have these tables; they were only created by
 * aut_track(), and the purge — called BEFORE, from aut_log_purge() — hit a missing table.
 * safe_w_sql() then did safe_error() → 404 + exit: dead page, despite the try/catch (see
 * aut_stats_soft). Misleading symptom: only one request a day fails (the marker of
 * aut_log_purge_daily is set BEFORE the purge), the next one passes.
 */
function aut_stats_purge() {
    aut_stats_ensure_schema();          // create first, purge next
    $soft = aut_stats_soft();
    $seen = (int) aut_stats_seen_days();
    $agg  = (int) aut_stats_agg_days();
    safe_w_sql("DELETE FROM AuthUsageSeen WHERE UzDay < DATE_SUB(CURDATE(), INTERVAL $seen DAY) LIMIT 50000", false, $soft);
    safe_w_sql("DELETE FROM AuthUsage     WHERE UsDay < DATE_SUB(CURDATE(), INTERVAL $agg DAY)  LIMIT 50000", false, $soft);
}

/* ------------------------------------------------------------------ */
/* Reads for the statistics page (admin/stats.php)                     */
/* All of them forced reads ($force): a missing table gives 0/[],      */
/* never a page in error.                                              */
/* ------------------------------------------------------------------ */

/** Start date (YYYY-MM-DD) of a window of $days days, in the measurement time zone. */
function aut_stats_from($days) {
    $d = (new DateTime('now', aut_stats_tz()))->modify('-' . (max(1, (int) $days) - 1) . ' days');
    return $d->format('Y-m-d');
}

/** Page views over the window ($device: '' = all devices). */
function aut_stats_views($space, $days, $device = '') {
    $q = safe_r_sql("SELECT COALESCE(SUM(UsViews),0) AS v FROM AuthUsage
        WHERE UsSpace=" . StrSafe_DB($space) . " AND UsDay >= " . StrSafe_DB(aut_stats_from($days))
        . aut_stats_dev_sql('UsDevice', $device), false, true);
    $r = $q ? safe_fetch($q) : null;
    return $r ? (int) $r->v : 0;
}

/**
 * Device split over the window: ['since' => 'YYYY-MM-DD'|null (first day measured with
 * a device), 'rows' => ['mobile' => ['views' => n, 'uniques' => n], 'tablet' => …,
 * 'desktop' => …]]. A person seen on a phone and on a computer counts in both rows: the
 * shares are shares of "visitor × device" pairs, which is what ergonomics needs.
 */
function aut_stats_devices($space, $days) {
    $out = array('since' => null, 'rows' => array());
    foreach (aut_stats_devices_list() as $d) $out['rows'][$d] = array('views' => 0, 'uniques' => 0);
    $sp = StrSafe_DB($space);
    $from = StrSafe_DB(aut_stats_from($days));
    $q = safe_r_sql("SELECT UsDevice AS d, SUM(UsViews) AS v FROM AuthUsage
        WHERE UsSpace=$sp AND UsDay >= $from AND UsDevice <> '' GROUP BY UsDevice", false, true);
    while ($q && ($r = safe_fetch($q))) if (isset($out['rows'][$r->d])) $out['rows'][$r->d]['views'] = (int) $r->v;
    $q = safe_r_sql("SELECT UzDevice AS d, COUNT(DISTINCT UzRef) AS u FROM AuthUsageSeen
        WHERE UzSpace=$sp AND UzDay >= $from AND UzDevice <> '' GROUP BY UzDevice", false, true);
    while ($q && ($r = safe_fetch($q))) if (isset($out['rows'][$r->d])) $out['rows'][$r->d]['uniques'] = (int) $r->u;
    $q = safe_r_sql("SELECT MIN(UsDay) AS m FROM AuthUsage WHERE UsDevice <> ''", false, true);
    $r = $q ? safe_fetch($q) : null;
    if ($r && $r->m) $out['since'] = $r->m;
    return $out;
}

/** Unique (distinct) visitors over the window. */
function aut_stats_uniques($space, $days, $device = '') {
    $q = safe_r_sql("SELECT COUNT(DISTINCT UzRef) AS u FROM AuthUsageSeen
        WHERE UzSpace=" . StrSafe_DB($space) . " AND UzDay >= " . StrSafe_DB(aut_stats_from($days))
        . aut_stats_dev_sql('UzDevice', $device), false, true);
    $r = $q ? safe_fetch($q) : null;
    return $r ? (int) $r->u : 0;
}

/** Daily series: [ ['day'=>..., 'views'=>..., 'uniques'=>...], ... ] for every day of the
 *  window (days without traffic included at 0). */
function aut_stats_daily($space, $days, $device = '') {
    $from = aut_stats_from($days);
    $sp = StrSafe_DB($space);
    $views = array();
    $q = safe_r_sql("SELECT UsDay AS d, SUM(UsViews) AS v FROM AuthUsage
        WHERE UsSpace=$sp AND UsDay >= " . StrSafe_DB($from) . aut_stats_dev_sql('UsDevice', $device)
        . " GROUP BY UsDay", false, true);
    while ($q && ($r = safe_fetch($q))) $views[$r->d] = (int) $r->v;
    $uniq = array();
    $q = safe_r_sql("SELECT UzDay AS d, COUNT(DISTINCT UzRef) AS u FROM AuthUsageSeen
        WHERE UzSpace=$sp AND UzDay >= " . StrSafe_DB($from) . aut_stats_dev_sql('UzDevice', $device)
        . " GROUP BY UzDay", false, true);
    while ($q && ($r = safe_fetch($q))) $uniq[$r->d] = (int) $r->u;

    $out = array();
    $cur = new DateTime($from, aut_stats_tz());
    $end = new DateTime('now', aut_stats_tz());
    while ($cur->format('Y-m-d') <= $end->format('Y-m-d')) {
        $d = $cur->format('Y-m-d');
        $out[] = array('day' => $d, 'views' => $views[$d] ?? 0, 'uniques' => $uniq[$d] ?? 0);
        $cur->modify('+1 day');
    }
    return $out;
}

/** Hourly split (0..23) of the page views over the window — "usage peaks". */
function aut_stats_hourly($space, $days, $device = '') {
    $out = array_fill(0, 24, 0);
    $q = safe_r_sql("SELECT UsHour AS h, SUM(UsViews) AS v FROM AuthUsage
        WHERE UsSpace=" . StrSafe_DB($space) . " AND UsDay >= " . StrSafe_DB(aut_stats_from($days))
        . aut_stats_dev_sql('UsDevice', $device) . " GROUP BY UsHour", false, true);
    while ($q && ($r = safe_fetch($q))) { $h = (int) $r->h; if ($h >= 0 && $h < 24) $out[$h] = (int) $r->v; }
    return $out;
}

/** Most viewed pages: [ ['page'=>..., 'views'=>...], ... ]. */
function aut_stats_top_pages($space, $days, $limit = 8, $device = '') {
    $out = array();
    $limit = max(1, min(30, (int) $limit));
    $q = safe_r_sql("SELECT UsPage AS p, SUM(UsViews) AS v FROM AuthUsage
        WHERE UsSpace=" . StrSafe_DB($space) . " AND UsDay >= " . StrSafe_DB(aut_stats_from($days))
        . aut_stats_dev_sql('UsDevice', $device) . " GROUP BY UsPage ORDER BY v DESC LIMIT $limit", false, true);
    while ($q && ($r = safe_fetch($q))) $out[] = array('page' => $r->p, 'views' => (int) $r->v);
    return $out;
}

/** ARCHER business figures (independent of the audience measurement). */
function aut_stats_archer_business() {
    $one = function ($sql) {
        $q = safe_r_sql($sql, false, true);
        $r = $q ? safe_fetch($q) : null;
        return $r ? (int) $r->n : 0;
    };
    $total   = $one("SELECT COUNT(*) AS n FROM BookingArchers");
    $active  = $one("SELECT COUNT(*) AS n FROM BookingArchers WHERE BaActive=1");
    // Archers with AT LEAST one registration in their name (conversion) — BK↔BK join, same collation.
    $conv    = $one("SELECT COUNT(*) AS n FROM BookingArchers
        WHERE EXISTS (SELECT 1 FROM BookingRegistrations WHERE BrLicence = BaLicence)");
    // Archers who register OTHER archers (peer "CLUB" or "MANAGER").
    $inscr   = $one("SELECT COUNT(DISTINCT BrArcher) AS n FROM BookingRegistrations
        WHERE BrArcher > 0 AND BrByRole IN ('CLUB','MANAGER')");
    return array(
        'total' => $total, 'active' => $active, 'converted' => $conv, 'registrars' => $inscr,
        'conv_rate' => $total > 0 ? round(100 * $conv / $total) : 0,
    );
}

/** ORGANISER business figures. */
function aut_stats_org_business($days = 30) {
    $one = function ($sql) {
        $q = safe_r_sql($sql, false, true);
        $r = $q ? safe_fetch($q) : null;
        return $r ? (int) $r->n : 0;
    };
    $total  = $one("SELECT COUNT(*) AS n FROM AuthUsers");
    $active = $one("SELECT COUNT(*) AS n FROM AuthUsers WHERE AuActive=1");
    $roles = array();
    $q = safe_r_sql("SELECT AuRole AS r, COUNT(*) AS n FROM AuthUsers GROUP BY AuRole", false, true);
    while ($q && ($x = safe_fetch($q))) $roles[$x->r] = (int) $x->n;
    // Successful sign-ins over the window (AuthLog). Table always there here.
    $from = aut_stats_from($days);
    $logins = $one("SELECT COUNT(*) AS n FROM AuthLog
        WHERE AlEvent IN ('LOGIN_OK','SSO_OK') AND AlWhen >= " . StrSafe_DB($from . ' 00:00:00'));
    return array('total' => $total, 'active' => $active, 'roles' => $roles, 'logins' => $logins);
}
