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
 *
 * Le tracking ne doit JAMAIS interrompre une page : stats-usage.php est chargé
 * depuis des chemins critiques (bootstrap organisateur, bk_require_archer). Toute
 * la partie écriture est donc enveloppée dans un try/catch(\Throwable) — une
 * table absente ou une panne DB rend la mesure muette, pas le site (cf. la règle
 * « une requête SQL en erreur tue la page » du CLAUDE.md racine).
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

/** Crée les tables de mesure (idempotent, statique). Sûr en tout contexte. */
function aut_stats_ensure_schema() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_Usage (
            UsDay   DATE            NOT NULL,
            UsHour  TINYINT UNSIGNED NOT NULL,
            UsSpace VARCHAR(8)      NOT NULL,
            UsPage  VARCHAR(48)     NOT NULL,
            UsViews INT UNSIGNED    NOT NULL DEFAULT 0,
            PRIMARY KEY (UsDay, UsHour, UsSpace, UsPage)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_UsageSeen (
            UzDay   DATE        NOT NULL,
            UzSpace VARCHAR(8)  NOT NULL,
            UzRef   VARCHAR(64) NOT NULL,
            PRIMARY KEY (UzDay, UzSpace, UzRef)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $e) { /* mesure non critique */ }
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
        aut_stats_ensure_schema();

        $now  = new DateTime('now', aut_stats_tz());
        $day  = $now->format('Y-m-d');
        $hour = (int) $now->format('G');
        $page = aut_stats_page_key($space);
        $sp   = StrSafe_DB($space);

        safe_w_sql("INSERT INTO AUT_Usage (UsDay, UsHour, UsSpace, UsPage, UsViews)
            VALUES (" . StrSafe_DB($day) . ", $hour, $sp, " . StrSafe_DB($page) . ", 1)
            ON DUPLICATE KEY UPDATE UsViews = UsViews + 1");

        $ref = ($uid !== null && $uid !== '') ? ('u:' . $uid) : ('a:' . aut_stats_audience_id());
        safe_w_sql("INSERT IGNORE INTO AUT_UsageSeen (UzDay, UzSpace, UzRef)
            VALUES (" . StrSafe_DB($day) . ", $sp, " . StrSafe_DB(substr($ref, 0, 64)) . ")");
    } catch (\Throwable $e) {
        // la mesure d'audience ne doit jamais interrompre une page
    }
}

/** Purge de la mesure : UsageSeen à la rétention des journaux, agrégats à 25 mois. */
function aut_stats_purge() {
    try {
        $seen = (int) aut_stats_seen_days();
        $agg  = (int) aut_stats_agg_days();
        safe_w_sql("DELETE FROM AUT_UsageSeen WHERE UzDay < DATE_SUB(CURDATE(), INTERVAL $seen DAY) LIMIT 50000");
        safe_w_sql("DELETE FROM AUT_Usage     WHERE UsDay < DATE_SUB(CURDATE(), INTERVAL $agg DAY)  LIMIT 50000");
    } catch (\Throwable $e) { /* non critique */ }
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

/** Total des pages vues sur la fenêtre. */
function aut_stats_views($space, $days) {
    $q = safe_r_sql("SELECT COALESCE(SUM(UsViews),0) AS v FROM AUT_Usage
        WHERE UsSpace=" . StrSafe_DB($space) . " AND UsDay >= " . StrSafe_DB(aut_stats_from($days)), false, true);
    $r = $q ? safe_fetch($q) : null;
    return $r ? (int) $r->v : 0;
}

/** Visiteurs uniques (distincts) sur la fenêtre. */
function aut_stats_uniques($space, $days) {
    $q = safe_r_sql("SELECT COUNT(DISTINCT UzRef) AS u FROM AUT_UsageSeen
        WHERE UzSpace=" . StrSafe_DB($space) . " AND UzDay >= " . StrSafe_DB(aut_stats_from($days)), false, true);
    $r = $q ? safe_fetch($q) : null;
    return $r ? (int) $r->u : 0;
}

/** Série quotidienne : [ ['day'=>..., 'views'=>..., 'uniques'=>...], ... ] pour tous
 *  les jours de la fenêtre (jours sans trafic inclus à 0). */
function aut_stats_daily($space, $days) {
    $from = aut_stats_from($days);
    $sp = StrSafe_DB($space);
    $views = array();
    $q = safe_r_sql("SELECT UsDay AS d, SUM(UsViews) AS v FROM AUT_Usage
        WHERE UsSpace=$sp AND UsDay >= " . StrSafe_DB($from) . " GROUP BY UsDay", false, true);
    while ($q && ($r = safe_fetch($q))) $views[$r->d] = (int) $r->v;
    $uniq = array();
    $q = safe_r_sql("SELECT UzDay AS d, COUNT(DISTINCT UzRef) AS u FROM AUT_UsageSeen
        WHERE UzSpace=$sp AND UzDay >= " . StrSafe_DB($from) . " GROUP BY UzDay", false, true);
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
function aut_stats_hourly($space, $days) {
    $out = array_fill(0, 24, 0);
    $q = safe_r_sql("SELECT UsHour AS h, SUM(UsViews) AS v FROM AUT_Usage
        WHERE UsSpace=" . StrSafe_DB($space) . " AND UsDay >= " . StrSafe_DB(aut_stats_from($days)) . "
        GROUP BY UsHour", false, true);
    while ($q && ($r = safe_fetch($q))) { $h = (int) $r->h; if ($h >= 0 && $h < 24) $out[$h] = (int) $r->v; }
    return $out;
}

/** Pages les plus consultées : [ ['page'=>..., 'views'=>...], ... ]. */
function aut_stats_top_pages($space, $days, $limit = 8) {
    $out = array();
    $limit = max(1, min(30, (int) $limit));
    $q = safe_r_sql("SELECT UsPage AS p, SUM(UsViews) AS v FROM AUT_Usage
        WHERE UsSpace=" . StrSafe_DB($space) . " AND UsDay >= " . StrSafe_DB(aut_stats_from($days)) . "
        GROUP BY UsPage ORDER BY v DESC LIMIT $limit", false, true);
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
