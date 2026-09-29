<?php
/**
 * stats-usage.php — mesure d'audience du serveur partagé (agrégée, respectueuse).
 *
 * Objectif : une page de statistiques d'usage (admin/stats.php) SANS pister les
 * personnes. Deux principes, conformes à la doctrine CNIL sur la mesure
 * d'audience exemptée de consentement
 * (https://www.cnil.fr/fr/cookies-solutions-pour-les-outils-de-mesure-daudience) :
 *
 *  1. On ne stocke que des COMPTEURS AGRÉGÉS (pages vues par heure/jour/espace,
 *     AUT_Usage) — aucune donnée personnelle, aucune IP, aucun parcours nominatif.
 *  2. Les visiteurs uniques sont dédupliqués par jour dans AUT_UsageSeen :
 *     - un utilisateur CONNECTÉ est compté par son identité de compte (déjà
 *       connue, aucun cookie) ;
 *     - un visiteur ANONYME (page d'accueil) reçoit un cookie de mesure
 *       d'audience de première partie, opaque, non partagé entre sites, de durée
 *       ≤ 13 mois, jamais lu côté client (HttpOnly). C'est le SEUL cookie non
 *       essentiel, et il relève de l'exemption ci-dessus.
 *  3. Le TYPE D'APPAREIL (téléphone / tablette / ordinateur) est déduit du navigateur
 *     à chaque page et seul ce mot est conservé, dans la clé des deux tables — jamais
 *     la chaîne User-Agent elle-même. Il sert à adapter l'ergonomie à l'usage réel, et
 *     chaque chiffre de la page de statistiques peut être filtré par appareil. Les robots
 *     (moteurs de recherche, supervision…) ne sont plus comptés du tout : sans cookie,
 *     chacun de leurs passages créait un « visiteur unique » de plus.
 *
 * Le tracking ne doit JAMAIS interrompre une page : stats-usage.php est chargé
 * depuis des chemins critiques (bootstrap organisateur, bk_require_archer). Le seul
 * filet réel est la liste d'erreurs tolérées passée à safe_w_sql() (aut_stats_soft) :
 * le try/catch ne rattrape PAS safe_error(), qui sort par exit : une requête SQL en
 * erreur tue la page.
 */

if (!function_exists('safe_r_sql')) return;   // hors contexte ianseo : ne rien faire

/** Mesure activable/désactivable via config.local.json → "stats_enabled" (défaut : activée). */
function aut_stats_enabled() {
    $c = function_exists('aut_local_config') ? aut_local_config() : array();
    return !array_key_exists('stats_enabled', $c) || !empty($c['stats_enabled']);
}

/** Fuseau des seaux jour/heure : stable et lisible (indépendant du time_zone MySQL
 *  que ianseo change par compétition, et de l'UTC forcé de PHP). Défaut Europe/Paris. */
function aut_stats_tz() {
    static $tz = null;
    if ($tz instanceof DateTimeZone) return $tz;
    $name = 'Europe/Paris';
    $c = function_exists('aut_local_config') ? aut_local_config() : array();
    if (!empty($c['stats_timezone']) && is_string($c['stats_timezone'])) $name = $c['stats_timezone'];
    try { $tz = new DateTimeZone($name); } catch (\Throwable $e) { $tz = new DateTimeZone('Europe/Paris'); }
    return $tz;
}

/** Rétention de la mesure. UsageSeen (pseudonyme) suit la rétention des journaux ;
 *  AUT_Usage (agrégats non personnels) est conservé jusqu'à 25 mois (limite CNIL). */
function aut_stats_seen_days()  { return function_exists('aut_log_retention_days') ? aut_log_retention_days() : 180; }
function aut_stats_agg_days()   { return 760; }   // ~25 mois

/**
 * Erreurs SQL tolérées par la mesure d'audience.
 *
 * ⚠️ `try/catch` ne protège de RIEN ici : `safe_w_sql()` appelle `safe_error()`, qui
 * fait `header('HTTP/1.0 404')` + `exit` — aucune exception n'est levée, donc rien à
 * rattraper. Le seul filet est le 3ᵉ argument de `safe_w_sql()` : la liste des numéros
 * d'erreur ACCEPTÉS. 0 = pas d'erreur, 1146 = table absente, 1142/1044 = droits
 * insuffisants. Une mesure d'audience ne doit jamais faire tomber une page.
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
    $flag = '_aut_stats_schema_v2';
    if (!empty($_SESSION[$flag])) return;
    $soft = aut_stats_soft();
    // Two first requests may migrate at the same time: the loser gets "duplicate column"
    // (1060), "multiple primary key" (1068) or "can't drop" (1091) — all harmless.
    $mig = array_merge($soft, array(1060, 1068, 1091));
    try {
        safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_Usage (
            UsDay    DATE             NOT NULL,
            UsHour   TINYINT UNSIGNED NOT NULL,
            UsSpace  VARCHAR(8)       NOT NULL,
            UsPage   VARCHAR(48)      NOT NULL,
            UsDevice VARCHAR(8)       NOT NULL DEFAULT '',
            UsViews  INT UNSIGNED     NOT NULL DEFAULT 0,
            PRIMARY KEY (UsDay, UsHour, UsSpace, UsPage, UsDevice)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", false, $soft);
        safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_UsageSeen (
            UzDay    DATE        NOT NULL,
            UzSpace  VARCHAR(8)  NOT NULL,
            UzRef    VARCHAR(64) NOT NULL,
            UzDevice VARCHAR(8)  NOT NULL DEFAULT '',
            PRIMARY KEY (UzDay, UzSpace, UzRef, UzDevice)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", false, $soft);
        if (!aut_stats_has_column('AUT_Usage', 'UsDevice')) {
            safe_w_sql("ALTER TABLE AUT_Usage ADD COLUMN UsDevice VARCHAR(8) NOT NULL DEFAULT '' AFTER UsPage,
                DROP PRIMARY KEY, ADD PRIMARY KEY (UsDay, UsHour, UsSpace, UsPage, UsDevice)", false, $mig);
        }
        if (!aut_stats_has_column('AUT_UsageSeen', 'UzDevice')) {
            safe_w_sql("ALTER TABLE AUT_UsageSeen ADD COLUMN UzDevice VARCHAR(8) NOT NULL DEFAULT '' AFTER UzRef,
                DROP PRIMARY KEY, ADD PRIMARY KEY (UzDay, UzSpace, UzRef, UzDevice)", false, $mig);
        }
        // v1.1.8 kept the device in a separate table, without hour nor page: it could not
        // be filtered on, and its few days of data cannot be spread over hours and pages.
        safe_w_sql("DROP TABLE IF EXISTS AUT_UsageDevice", false, $mig);
        if (aut_stats_has_column('AUT_Usage', 'UsDevice') && aut_stats_has_column('AUT_UsageSeen', 'UzDevice')) {
            $_SESSION[$flag] = true;
        }
    } catch (\Throwable $e) { /* PHP errors only: SQL errors are neutralised by $soft */ }
}

/** Cette requête est-elle une consultation de page à mesurer ? (GET, pas XHR,
 *  pas un asset/API/logo). Sert de garde universelle au tracking. */
function aut_stats_is_page() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') return false;
    $s = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
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

/** Clé de page normalisée. Pour l'espace organisateur (cœur ianseo, beaucoup de
 *  scripts nommés index.php), on préfixe du dossier parent pour désambiguïser. */
function aut_stats_page_key($space) {
    $s = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $parts = array_values(array_filter(explode('/', trim($s, '/')), 'strlen'));
    $base = preg_replace('/\.php$/i', '', end($parts) ?: 'index');
    if ($space === 'org' && count($parts) >= 2) $base = $parts[count($parts) - 2] . '/' . $base;
    $base = preg_replace('#[^A-Za-z0-9_/.\-]#', '', $base);
    return substr($base !== '' ? $base : 'index', 0, 48);
}

/** Cookie de mesure d'audience (anonymes uniquement). Opaque, 1re partie, ≤ 13 mois,
 *  HttpOnly (jamais exposé au client). Retourne l'identifiant pseudonyme. */
function aut_stats_audience_id() {
    static $id = null;
    if ($id !== null) return $id;
    $raw = $_COOKIE['aud'] ?? '';
    if (preg_match('/^[a-f0-9]{32}$/', $raw)) { $id = $raw; return $id; }
    $id = bin2hex(random_bytes(16));
    if (!headers_sent()) {
        global $CFG;
        $path = (isset($CFG->ROOT_DIR) && $CFG->ROOT_DIR !== '') ? $CFG->ROOT_DIR : '/';
        $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
        @setcookie('aud', $id, array(
            'expires'  => time() + 34128000,   // 13 mois : limite CNIL du cookie de mesure d'audience
            'path'     => $path,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }
    $_COOKIE['aud'] = $id;   // disponible dès cette requête
    return $id;
}

/**
 * Enregistre une consultation de page.
 *   $space : 'org' | 'archer' | 'public'
 *   $uid   : identifiant de compte si connecté (compté par identité, sans cookie) ;
 *            null → visiteur anonyme (compté via le cookie de mesure d'audience).
 * Auto-gardé (aut_stats_is_page) et totalement isolé (aucune erreur ne remonte).
 */
function aut_track($space, $uid = null) {
    try {
        if (!aut_stats_enabled() || !aut_stats_is_page()) return;
        $device = aut_stats_device();
        if ($device === 'bot') return;   // pas un utilisateur : ni vue, ni visiteur, ni cookie
        aut_stats_ensure_schema();

        $now  = new DateTime('now', aut_stats_tz());
        $day  = $now->format('Y-m-d');
        $hour = (int) $now->format('G');
        $page = aut_stats_page_key($space);
        $sp   = StrSafe_DB($space);

        // 3ᵉ argument = erreurs tolérées : c'est le SEUL filet (le catch ci-dessous ne
        // rattrape pas safe_error(), qui sort par exit).
        $soft = aut_stats_soft();
        $dev  = StrSafe_DB($device);
        safe_w_sql("INSERT INTO AUT_Usage (UsDay, UsHour, UsSpace, UsPage, UsDevice, UsViews)
            VALUES (" . StrSafe_DB($day) . ", $hour, $sp, " . StrSafe_DB($page) . ", $dev, 1)
            ON DUPLICATE KEY UPDATE UsViews = UsViews + 1", false, $soft);

        $ref = ($uid !== null && $uid !== '') ? ('u:' . $uid) : ('a:' . aut_stats_audience_id());
        // $ref is ASCII (u:<id> or a:<32 hex>): cutting at 64 bytes is safe.
        safe_w_sql("INSERT IGNORE INTO AUT_UsageSeen (UzDay, UzSpace, UzRef, UzDevice)
            VALUES (" . StrSafe_DB($day) . ", $sp, " . StrSafe_DB(substr($ref, 0, 64)) . ", $dev)", false, $soft);
    } catch (\Throwable $e) {
        // erreurs PHP seulement (date, cookie…) : les erreurs SQL, elles, sont
        // neutralisées par $soft — safe_error() ne lève rien, il sort.
    }
}

/**
 * Purge de la mesure : UsageSeen à la rétention des journaux, agrégats à 25 mois.
 *
 * ⚠️ PANNE RÉELLE (serveur d'un utilisateur, sept. 2026) : « Error 1146: Table
 * 'xxx.AUT_UsageSeen' doesn't exist » en pleine page. Une installation mise à jour
 * depuis une version antérieure à la mesure d'audience n'a pas ces tables ; elles
 * n'étaient créées que par aut_track(), et la purge — appelée AVANT, depuis
 * aut_log_purge() — tombait donc sur une table absente. safe_w_sql() a alors fait
 * safe_error() → 404 + exit : page morte, malgré le try/catch (voir aut_stats_soft).
 * Symptôme trompeur : une seule requête par jour échoue (le marqueur de
 * aut_log_purge_daily est posé AVANT la purge), la suivante passe.
 */
function aut_stats_purge() {
    aut_stats_ensure_schema();          // d'abord créer, ensuite purger
    $soft = aut_stats_soft();
    $seen = (int) aut_stats_seen_days();
    $agg  = (int) aut_stats_agg_days();
    safe_w_sql("DELETE FROM AUT_UsageSeen WHERE UzDay < DATE_SUB(CURDATE(), INTERVAL $seen DAY) LIMIT 50000", false, $soft);
    safe_w_sql("DELETE FROM AUT_Usage     WHERE UsDay < DATE_SUB(CURDATE(), INTERVAL $agg DAY)  LIMIT 50000", false, $soft);
}

/* ------------------------------------------------------------------ */
/* Lectures pour la page de statistiques (admin/stats.php)             */
/* Toutes en lecture forcée ($force) : une table absente rend 0/[],    */
/* jamais une page en erreur.                                          */
/* ------------------------------------------------------------------ */

/** Date de début (AAAA-MM-JJ) d'une fenêtre de $days jours, dans le fuseau de mesure. */
function aut_stats_from($days) {
    $d = (new DateTime('now', aut_stats_tz()))->modify('-' . (max(1, (int) $days) - 1) . ' days');
    return $d->format('Y-m-d');
}

/** Page views over the window ($device: '' = all devices). */
function aut_stats_views($space, $days, $device = '') {
    $q = safe_r_sql("SELECT COALESCE(SUM(UsViews),0) AS v FROM AUT_Usage
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
    $q = safe_r_sql("SELECT UsDevice AS d, SUM(UsViews) AS v FROM AUT_Usage
        WHERE UsSpace=$sp AND UsDay >= $from AND UsDevice <> '' GROUP BY UsDevice", false, true);
    while ($q && ($r = safe_fetch($q))) if (isset($out['rows'][$r->d])) $out['rows'][$r->d]['views'] = (int) $r->v;
    $q = safe_r_sql("SELECT UzDevice AS d, COUNT(DISTINCT UzRef) AS u FROM AUT_UsageSeen
        WHERE UzSpace=$sp AND UzDay >= $from AND UzDevice <> '' GROUP BY UzDevice", false, true);
    while ($q && ($r = safe_fetch($q))) if (isset($out['rows'][$r->d])) $out['rows'][$r->d]['uniques'] = (int) $r->u;
    $q = safe_r_sql("SELECT MIN(UsDay) AS m FROM AUT_Usage WHERE UsDevice <> ''", false, true);
    $r = $q ? safe_fetch($q) : null;
    if ($r && $r->m) $out['since'] = $r->m;
    return $out;
}

/** Visiteurs uniques (distincts) sur la fenêtre. */
function aut_stats_uniques($space, $days, $device = '') {
    $q = safe_r_sql("SELECT COUNT(DISTINCT UzRef) AS u FROM AUT_UsageSeen
        WHERE UzSpace=" . StrSafe_DB($space) . " AND UzDay >= " . StrSafe_DB(aut_stats_from($days))
        . aut_stats_dev_sql('UzDevice', $device), false, true);
    $r = $q ? safe_fetch($q) : null;
    return $r ? (int) $r->u : 0;
}

/** Série quotidienne : [ ['day'=>..., 'views'=>..., 'uniques'=>...], ... ] pour tous
 *  les jours de la fenêtre (jours sans trafic inclus à 0). */
function aut_stats_daily($space, $days, $device = '') {
    $from = aut_stats_from($days);
    $sp = StrSafe_DB($space);
    $views = array();
    $q = safe_r_sql("SELECT UsDay AS d, SUM(UsViews) AS v FROM AUT_Usage
        WHERE UsSpace=$sp AND UsDay >= " . StrSafe_DB($from) . aut_stats_dev_sql('UsDevice', $device)
        . " GROUP BY UsDay", false, true);
    while ($q && ($r = safe_fetch($q))) $views[$r->d] = (int) $r->v;
    $uniq = array();
    $q = safe_r_sql("SELECT UzDay AS d, COUNT(DISTINCT UzRef) AS u FROM AUT_UsageSeen
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

/** Répartition horaire (0..23) des pages vues sur la fenêtre — « pics d'usage ». */
function aut_stats_hourly($space, $days, $device = '') {
    $out = array_fill(0, 24, 0);
    $q = safe_r_sql("SELECT UsHour AS h, SUM(UsViews) AS v FROM AUT_Usage
        WHERE UsSpace=" . StrSafe_DB($space) . " AND UsDay >= " . StrSafe_DB(aut_stats_from($days))
        . aut_stats_dev_sql('UsDevice', $device) . " GROUP BY UsHour", false, true);
    while ($q && ($r = safe_fetch($q))) { $h = (int) $r->h; if ($h >= 0 && $h < 24) $out[$h] = (int) $r->v; }
    return $out;
}

/** Pages les plus consultées : [ ['page'=>..., 'views'=>...], ... ]. */
function aut_stats_top_pages($space, $days, $limit = 8, $device = '') {
    $out = array();
    $limit = max(1, min(30, (int) $limit));
    $q = safe_r_sql("SELECT UsPage AS p, SUM(UsViews) AS v FROM AUT_Usage
        WHERE UsSpace=" . StrSafe_DB($space) . " AND UsDay >= " . StrSafe_DB(aut_stats_from($days))
        . aut_stats_dev_sql('UsDevice', $device) . " GROUP BY UsPage ORDER BY v DESC LIMIT $limit", false, true);
    while ($q && ($r = safe_fetch($q))) $out[] = array('page' => $r->p, 'views' => (int) $r->v);
    return $out;
}

/** Métriques métier ARCHERS (indépendantes de la mesure d'audience). */
function aut_stats_archer_business() {
    $one = function ($sql) {
        $q = safe_r_sql($sql, false, true);
        $r = $q ? safe_fetch($q) : null;
        return $r ? (int) $r->n : 0;
    };
    $total   = $one("SELECT COUNT(*) AS n FROM BK_Archers");
    $active  = $one("SELECT COUNT(*) AS n FROM BK_Archers WHERE BaActive=1");
    // Archers ayant AU MOINS une inscription à leur nom (conversion) — jointure BK↔BK, même collation.
    $conv    = $one("SELECT COUNT(*) AS n FROM BK_Archers a
        WHERE EXISTS (SELECT 1 FROM BK_Registrations r WHERE r.BrLicence = a.BaLicence)");
    // Archers qui inscrivent d'AUTRES archers (pair « CLUB » ou gestionnaire « MANAGER »).
    $inscr   = $one("SELECT COUNT(DISTINCT BrArcher) AS n FROM BK_Registrations
        WHERE BrArcher > 0 AND BrByRole IN ('CLUB','MANAGER')");
    return array(
        'total' => $total, 'active' => $active, 'converted' => $conv, 'registrars' => $inscr,
        'conv_rate' => $total > 0 ? round(100 * $conv / $total) : 0,
    );
}

/** Métriques métier ORGANISATEURS. */
function aut_stats_org_business($days = 30) {
    $one = function ($sql) {
        $q = safe_r_sql($sql, false, true);
        $r = $q ? safe_fetch($q) : null;
        return $r ? (int) $r->n : 0;
    };
    $total  = $one("SELECT COUNT(*) AS n FROM AUT_Users");
    $active = $one("SELECT COUNT(*) AS n FROM AUT_Users WHERE AuActive=1");
    $roles = array();
    $q = safe_r_sql("SELECT AuRole AS r, COUNT(*) AS n FROM AUT_Users GROUP BY AuRole", false, true);
    while ($q && ($x = safe_fetch($q))) $roles[$x->r] = (int) $x->n;
    // Connexions réussies sur la fenêtre (journal AUT_Log). Table toujours présente ici.
    $from = aut_stats_from($days);
    $logins = $one("SELECT COUNT(*) AS n FROM AUT_Log
        WHERE AlEvent IN ('LOGIN_OK','SSO_OK') AND AlWhen >= " . StrSafe_DB($from . ' 00:00:00'));
    return array('total' => $total, 'active' => $active, 'roles' => $roles, 'logins' => $logins);
}
