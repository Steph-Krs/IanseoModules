<?php
/**
 * logos-lib.php — logos de club mutualisés (drapeaux ianseo).
 *
 * PROBLÈME RÉSOLU : nativement, chaque organisateur doit passer par
 * « Participants › Charger table de correspondance » (Partecipants/LookupTableLoad.php)
 * et cocher « Drapeaux » pour que les logos de club soient téléchargés — POUR SA SEULE
 * compétition. Les mêmes logos sont donc retéléchargés chez la FFTA et redupliqués en
 * base pour chaque compétition (constaté : 678 lignes Flags pour 357 logos distincts),
 * et un organisateur qui oublie l'étape imprime des documents sans logo.
 *
 * SOLUTION, en deux couches nettement séparées :
 *   1. un CACHE GLOBAL (AUT_ClubLogos, un logo par agrément) alimenté UNE fois par jour
 *      par cron/sync-logos.php — c'est la seule couche qui sort sur le réseau ;
 *   2. une PROPAGATION purement LOCALE (aucun réseau) cache → table `Flags` + fichiers
 *      « TV/Photos/{ToCode}-Fl-{agrément}.jpg », qui est ce que lisent réellement les
 *      impressions du cœur (dossards, badges…). L'organisateur n'a plus rien à faire.
 *
 * ⚠️ On n'appelle PAS updateFlag() (Common/CheckPictures.php) pour écrire les fichiers :
 * cette fonction mémorise le code de compétition dans une variable `static` qu'elle ne
 * recalcule jamais — dans une boucle sur plusieurs compétitions, tous les fichiers
 * seraient écrits avec le code de la PREMIÈRE. On écrit donc les fichiers ici.
 *
 * Format : l'endpoint FFTA ne sert que du PNG (`?png=`) ; `?jpg=` et `?svg=` renvoient
 * du vide (vérifié). On convertit donc en JPEG, comme le fait le cœur, mais en aplatissant
 * la transparence sur du BLANC (sans quoi un logo transparent vire au noir en JPEG).
 */

if (function_exists('aut_logos_schema')) return;

define('AUT_LOGOS_URL_FALLBACK', 'https://extranet.ffta.fr/ianseo/logo.php');

/**
 * Config locale : config.local.json → "logos": {...}
 *
 * ⚠️ Cette lib est aussi appelée depuis la face BOOKING (inscription), qui ne charge
 * PAS lib.php d'AUTH : on ne peut donc pas dépendre de aut_local_config(). On la
 * réutilise si elle est là, sinon on lit le même fichier directement.
 */
function aut_logos_config()
{
    static $c = null;
    if ($c !== null) return $c;
    if (function_exists('aut_local_config')) {
        $all = aut_local_config();
    } else {
        // Repli autonome : même fichier, et même protection contre le BOM UTF-8
        // (voir aut_json_strip_bom — un BOM ferait échouer json_decode en silence).
        $f = __DIR__ . '/config.local.json';
        $raw = is_file($f) ? (string) @file_get_contents($f) : '';
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
        $all = $raw !== '' ? (json_decode($raw, true) ?: array()) : array();
    }
    $c = (is_array($all) && isset($all['logos']) && is_array($all['logos'])) ? $all['logos'] : array();
    return $c;
}

function aut_logos_enabled()
{
    $c = aut_logos_config();
    return !array_key_exists('enabled', $c) || !empty($c['enabled']);
}

/**
 * URL de l'endpoint des logos. Par défaut celle que ianseo connaît déjà
 * (LookUpPaths.LupFlagsPath du set FRA) — pas de valeur en dur si la base la porte.
 */
function aut_logos_url()
{
    static $url = null;
    if ($url !== null) return $url;
    $c = aut_logos_config();
    $url = trim((string) ($c['url'] ?? ''));
    if ($url === '') {
        $r = safe_fetch(safe_r_sql("SELECT LupFlagsPath FROM LookUpPaths
            WHERE LupIocCode = " . StrSafe_DB(aut_logos_ioc()), false, true));
        $url = $r ? trim((string) $r->LupFlagsPath) : '';
    }
    if ($url === '' || !preg_match('#^https?://#i', $url)) $url = AUT_LOGOS_URL_FALLBACK;
    return $url;
}

/** Code « pays » du set utilisé (FlIocCode des lignes Flags). */
function aut_logos_ioc()
{
    $c = aut_logos_config();
    return (string) ($c['ioc'] ?? 'FRA');
}

/** Table de cache (créée à la demande — hors du chemin chaud des requêtes web). */
function aut_logos_schema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    safe_w_sql("CREATE TABLE IF NOT EXISTS AUT_ClubLogos (
        ClgCode    VARCHAR(10) NOT NULL,
        ClgJpg     MEDIUMBLOB NULL,
        ClgHash    CHAR(32)  NOT NULL DEFAULT '',
        ClgBytes   INT       NOT NULL DEFAULT 0,
        ClgMissing TINYINT   NOT NULL DEFAULT 0,
        ClgFetched DATETIME  NULL,
        ClgTried   DATETIME  NULL,
        PRIMARY KEY (ClgCode)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Agréments de club à connaître : TOUS ceux du fichier fédéral (LookUpEntries, la
 * liste exhaustive des clubs ayant au moins un licencié) + ceux réellement utilisés
 * par les compétitions (au cas où un club n'y figurerait pas encore).
 */
function aut_logos_club_codes()
{
    $out = array();
    $rs = safe_r_sql("SELECT DISTINCT LueCountry AS c FROM LookUpEntries WHERE LueCountry <> ''", false, true);
    while ($rs && ($r = safe_fetch($rs))) $out[trim($r->c)] = true;
    $rs = safe_r_sql("SELECT DISTINCT c.CoCode AS c FROM Entries e
        INNER JOIN Countries c ON c.CoId = e.EnCountry WHERE c.CoCode <> ''", false, true);
    while ($rs && ($r = safe_fetch($rs))) $out[trim($r->c)] = true;
    unset($out['']);
    return array_keys($out);
}

/**
 * Télécharge le logo d'un club et le range dans le cache.
 * Retour : 'ok' (nouveau/à jour), 'same' (inchangé), 'none' (pas de logo), 'fail'.
 */
function aut_logos_fetch_one($code, $timeout = 15)
{
    aut_logos_schema();
    $code = trim((string) $code);
    if ($code === '') return 'fail';
    $q = StrSafe_DB($code);

    // Handle réutilisé d'un appel à l'autre (keep-alive) : sur 1600 clubs, cela évite
    // autant de poignées de main TLS — plus rapide ici, et plus léger pour la FFTA.
    static $ch = null;
    if ($ch === null) $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL            => aut_logos_url() . '?png=' . rawurlencode($code),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'ianseo-auth (sync logos clubs)',
    ));
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    safe_w_sql("INSERT INTO AUT_ClubLogos (ClgCode, ClgTried) VALUES ($q, NOW())
        ON DUPLICATE KEY UPDATE ClgTried = NOW()");

    if ($body === false || $http < 200 || $http >= 300) return 'fail';

    // Club sans logo : l'endpoint répond 200 avec un corps VIDE (text/html) — ce
    // n'est pas une panne, on le mémorise pour ne pas le confondre avec un échec.
    if ($body === '' || stripos($ctype, 'image/') !== 0) {
        safe_w_sql("UPDATE AUT_ClubLogos SET ClgMissing = 1, ClgJpg = NULL, ClgHash = '',
            ClgBytes = 0, ClgFetched = NOW() WHERE ClgCode = $q");
        return 'none';
    }

    $jpg = aut_logos_to_jpeg($body);
    if ($jpg === null) return 'fail';

    $hash = md5($jpg);
    $cur = safe_fetch(safe_r_sql("SELECT ClgHash FROM AUT_ClubLogos WHERE ClgCode = $q", false, true));
    $same = ($cur && (string) $cur->ClgHash === $hash);
    safe_w_sql("UPDATE AUT_ClubLogos SET ClgJpg = " . StrSafe_DB($jpg) . ", ClgHash = " . StrSafe_DB($hash)
        . ", ClgBytes = " . strlen($jpg) . ", ClgMissing = 0, ClgFetched = NOW() WHERE ClgCode = $q");
    return $same ? 'same' : 'ok';
}

/**
 * PNG (ou autre) → JPEG, transparence aplatie sur du BLANC. Le cœur fait un
 * imagejpeg() direct, qui rend NOIR le fond des logos transparents (majorité des
 * logos de club) : on compose donc d'abord sur un fond blanc. Retourne null si
 * l'image est illisible.
 */
function aut_logos_to_jpeg($bin)
{
    if (!function_exists('imagecreatefromstring')) return null;
    $img = @imagecreatefromstring($bin);
    if (!$img) return null;
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 1 || $h < 1) { imagedestroy($img); return null; }

    $canvas = imagecreatetruecolor($w, $h);
    $white  = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
    imagealphablending($canvas, true);          // compose l'alpha de la source sur le blanc
    imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
    imagedestroy($img);

    ob_start();
    imagejpeg($canvas, null, 92);
    $out = ob_get_clean();
    imagedestroy($canvas);
    return ($out !== '' && $out !== false) ? $out : null;
}

/** Code de compétition « sûr » pour un nom de fichier — même filtre que le cœur. */
function aut_logos_safe_code($toCode)
{
    return preg_replace('/[^a-z0-9_.-]/sim', '', (string) $toCode);
}

/**
 * PROPAGATION (aucun réseau) : pour UNE compétition, pose dans `Flags` et sur le
 * disque les logos des clubs de ses participants, depuis le cache. C'est cette étape
 * qui rend les logos utilisables par les impressions natives de ianseo.
 * Retour : array('ecrits','deja','absents').
 */
function aut_logos_sync_tournament($tourId)
{
    aut_logos_schema();
    global $CFG;
    $tourId = intval($tourId);
    $res = array('ecrits' => 0, 'deja' => 0, 'absents' => 0, 'echecs' => 0);

    $t = safe_fetch(safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId = $tourId"));
    if (!$t) return $res;
    $safe = aut_logos_safe_code($t->ToCode);
    if ($safe === '') return $res;

    // Clubs des participants de CETTE compétition.
    $codes = array();
    $rs = safe_r_sql("SELECT DISTINCT c.CoCode AS c FROM Entries e
        INNER JOIN Countries c ON c.CoId = e.EnCountry
        WHERE e.EnTournament = $tourId AND c.CoCode <> ''");
    while ($r = safe_fetch($rs)) $codes[] = trim($r->c);
    if (!$codes) return $res;

    // Cache correspondant. Pas de JOIN entre colonne custom et colonne ianseo :
    // un IN de valeurs échappées évite la question des collations (erreur 1267).
    $in = array();
    foreach ($codes as $c) $in[] = StrSafe_DB($c);
    $logos = array();
    $rs = safe_r_sql("SELECT ClgCode, ClgJpg FROM AUT_ClubLogos
        WHERE ClgMissing = 0 AND ClgBytes > 0 AND ClgCode IN (" . implode(',', $in) . ")", false, true);
    while ($rs && ($r = safe_fetch($rs))) $logos[trim($r->ClgCode)] = $r->ClgJpg;

    $dir = $CFG->DOCUMENT_PATH . 'TV/Photos/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ioc = StrSafe_DB(aut_logos_ioc());

    foreach ($codes as $code) {
        if (!isset($logos[$code])) { $res['absents']++; continue; }
        $jpg  = $logos[$code];
        $file = $dir . $safe . '-Fl-' . $code . '.jpg';

        // Ligne Flags (base64, comme le cœur) : garde la cohérence avec les outils
        // natifs, qui savent reconstruire les fichiers depuis la base.
        // ⚠️ FlSVG et FlContAssoc sont NOT NULL SANS valeur par défaut : il faut les
        // renseigner à l'INSERT (sinon échec sur un serveur en mode SQL strict). Ils
        // sont volontairement absents de l'UPDATE, pour ne pas effacer un SVG existant.
        $set = "FlIocCode = $ioc, FlJPG = " . StrSafe_DB(base64_encode($jpg));
        safe_w_sql("INSERT INTO Flags SET FlTournament = $tourId, FlCode = " . StrSafe_DB($code)
            . ", $set, FlSVG = '', FlContAssoc = '' ON DUPLICATE KEY UPDATE $set");

        if (is_file($file) && md5_file($file) === md5($jpg)) { $res['deja']++; continue; }
        // Un échec d'écriture doit se VOIR : sur un serveur durci, TV/Photos peut être
        // en lecture seule pour le serveur web, et les logos ne seraient alors jamais
        // posés — sans le moindre message si l'on se contentait de ne pas compter.
        if (@file_put_contents($file, $jpg) !== false) $res['ecrits']++;
        else $res['echecs']++;
    }
    return $res;
}

/** Compétitions à alimenter : celles qui ne sont pas terminées (comme la synchro licences). */
function aut_logos_active_tournaments()
{
    $out = array();
    $rs = safe_r_sql("SELECT ToId FROM Tournament WHERE ToWhenTo >= CURDATE() ORDER BY ToId");
    while ($r = safe_fetch($rs)) $out[] = intval($r->ToId);
    return $out;
}

/**
 * Point d'entrée « à la demande », sans réseau : appelé après une inscription pour que
 * le logo du club du nouvel inscrit soit disponible immédiatement, sans attendre le
 * cron. Totalement isolé : une panne ici ne doit jamais interrompre une inscription.
 */
function aut_logos_ensure_club($tourId, $clubCode)
{
    try {
        aut_logos_schema();
        global $CFG;
        $tourId = intval($tourId);
        $code = trim((string) $clubCode);
        if (!$tourId || $code === '') return false;

        $r = safe_fetch(safe_r_sql("SELECT ClgJpg FROM AUT_ClubLogos WHERE ClgCode = " . StrSafe_DB($code)
            . " AND ClgMissing = 0 AND ClgBytes > 0", false, true));
        if (!$r) return false;                       // pas encore en cache → le cron s'en chargera

        $t = safe_fetch(safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId = $tourId"));
        if (!$t) return false;
        $safe = aut_logos_safe_code($t->ToCode);
        if ($safe === '') return false;

        $set = "FlIocCode = " . StrSafe_DB(aut_logos_ioc()) . ", FlJPG = " . StrSafe_DB(base64_encode($r->ClgJpg));
        safe_w_sql("INSERT INTO Flags SET FlTournament = $tourId, FlCode = " . StrSafe_DB($code)
            . ", $set, FlSVG = '', FlContAssoc = '' ON DUPLICATE KEY UPDATE $set");

        $dir = $CFG->DOCUMENT_PATH . 'TV/Photos/';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . $safe . '-Fl-' . $code . '.jpg';
        if (!is_file($file) || md5_file($file) !== md5($r->ClgJpg)) {
            @file_put_contents($file, $r->ClgJpg);
        }
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/** Statistiques du cache (page d'administration / journal du cron). */
function aut_logos_stats()
{
    aut_logos_schema();
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS total,
            SUM(ClgBytes > 0) AS avec, SUM(ClgMissing = 1) AS sans,
            MAX(ClgFetched) AS dernier, SUM(ClgBytes) AS octets
        FROM AUT_ClubLogos", false, true));
    return $r ? array(
        'total'   => intval($r->total), 'avec' => intval($r->avec),
        'sans'    => intval($r->sans),  'dernier' => $r->dernier,
        'octets'  => intval($r->octets),
    ) : array('total' => 0, 'avec' => 0, 'sans' => 0, 'dernier' => null, 'octets' => 0);
}
