<?php
/**
 * logos-lib.php — shared club logos (ianseo flags).
 *
 * PROBLEM SOLVED: natively, each organiser has to go through "Participants › Load lookup
 * table" (Partecipants/LookupTableLoad.php) and tick "Flags" for the club logos to be
 * downloaded — FOR THEIR OWN competition only. The same logos are therefore downloaded again
 * from the FFTA and duplicated again in the database for each competition (seen: 678 Flags
 * rows for 357 distinct logos), and an organiser who forgets the step prints documents
 * without logos.
 *
 * SOLUTION, in two clearly separate layers:
 *   1. a GLOBAL CACHE (AUT_ClubLogos, one logo per approval number) filled ONCE a day by
 *      cron/sync-logos.php — the only layer that goes out on the network;
 *   2. a purely LOCAL PROPAGATION (no network) cache → `Flags` table + files
 *      "TV/Photos/{ToCode}-Fl-{approval}.jpg", which is what the core's printouts really
 *      read (bibs, badges…). The organiser has nothing left to do.
 *
 * ⚠️ updateFlag() (Common/CheckPictures.php) is NOT called to write the files: that function
 * keeps the competition code in a `static` variable it never computes again — in a loop over
 * several competitions, every file would be written with the code of the FIRST one. The
 * files are therefore written here.
 *
 * Format: the FFTA endpoint only serves PNG (`?png=`); `?jpg=` and `?svg=` return nothing
 * (checked). They are converted to JPEG, as the core does, but flattening the transparency
 * on WHITE (otherwise a transparent logo turns black in JPEG).
 */

if (function_exists('aut_logos_schema')) return;

define('AUT_LOGOS_URL_FALLBACK', 'https://extranet.ffta.fr/ianseo/logo.php');

/**
 * Local config: config.local.json → "logos": {...}
 *
 * ⚠️ This lib is also called from the BOOKING side (registration), which does NOT load
 * AUTH's lib.php: aut_local_config() cannot be relied upon. It is reused when there,
 * otherwise the same file is read directly.
 */
function aut_logos_config()
{
    static $c = null;
    if ($c !== null) return $c;
    if (function_exists('aut_local_config')) {
        $all = aut_local_config();
    } else {
        // Standalone fallback: same file, and same protection against the UTF-8 BOM
        // (see aut_json_strip_bom — a BOM would make json_decode fail silently).
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
 * URL of the logos endpoint. By default the one ianseo already knows
 * (LookUpPaths.LupFlagsPath of the FRA set) — no hard-coded value when the database has it.
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

/** "Country" code of the set used (FlIocCode of the Flags rows). */
function aut_logos_ioc()
{
    $c = aut_logos_config();
    return (string) ($c['ioc'] ?? 'FRA');
}

/** Cache table (created on demand — outside the hot path of the web requests). */
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
 * Club approval numbers to know: ALL those of the federation file (LookUpEntries, the
 * exhaustive list of the clubs having at least one licensee) + those actually used by the
 * competitions (in case a club were not in it yet).
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
 * Downloads the logo of a club and stores it in the cache.
 * Returns: 'ok' (new/updated), 'same' (unchanged), 'none' (no logo), 'fail'.
 */
function aut_logos_fetch_one($code, $timeout = 15)
{
    aut_logos_schema();
    $code = trim((string) $code);
    if ($code === '') return 'fail';
    $q = StrSafe_DB($code);

    // Handle reused from one call to the next (keep-alive): over 1600 clubs, it saves as many
    // TLS handshakes — faster here, and lighter for the FFTA.
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

    // Club without a logo: the endpoint answers 200 with an EMPTY body (text/html) — not an
    // outage, it is remembered so as not to mistake it for a failure.
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
 * PNG (or other) → JPEG, transparency flattened on WHITE. The core does a direct
 * imagejpeg(), which turns BLACK the background of transparent logos (most club logos):
 * the image is therefore composed on a white background first. Returns null when the
 * image cannot be read.
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
    imagealphablending($canvas, true);          // composes the alpha of the source on the white
    imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
    imagedestroy($img);

    ob_start();
    imagejpeg($canvas, null, 92);
    $out = ob_get_clean();
    imagedestroy($canvas);
    return ($out !== '' && $out !== false) ? $out : null;
}

/** "Safe" competition code for a file name — same filter as the core. */
function aut_logos_safe_code($toCode)
{
    return preg_replace('/[^a-z0-9_.-]/sim', '', (string) $toCode);
}

/**
 * PROPAGATION (no network): for ONE competition, sets in `Flags` and on disk the logos of
 * the clubs of its participants, from the cache. This step makes the logos usable by
 * ianseo's native printouts.
 * Returns: array('written', 'current', 'missing', 'failed').
 */
function aut_logos_sync_tournament($tourId)
{
    aut_logos_schema();
    global $CFG;
    $tourId = intval($tourId);
    $res = array('written' => 0, 'current' => 0, 'missing' => 0, 'failed' => 0);

    $t = safe_fetch(safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId = $tourId"));
    if (!$t) return $res;
    $safe = aut_logos_safe_code($t->ToCode);
    if ($safe === '') return $res;

    // Clubs of the participants of THIS competition.
    $codes = array();
    $rs = safe_r_sql("SELECT DISTINCT c.CoCode AS c FROM Entries e
        INNER JOIN Countries c ON c.CoId = e.EnCountry
        WHERE e.EnTournament = $tourId AND c.CoCode <> ''");
    while ($r = safe_fetch($rs)) $codes[] = trim($r->c);
    if (!$codes) return $res;

    // Matching cache. No JOIN between a custom column and an ianseo column: an IN of escaped
    // values avoids the collation question (error 1267).
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
        if (!isset($logos[$code])) { $res['missing']++; continue; }
        $jpg  = $logos[$code];
        $file = $dir . $safe . '-Fl-' . $code . '.jpg';

        // Flags row (base64, as the core does): keeps consistency with the native tools, which
        // can rebuild the files from the database.
        // ⚠️ FlSVG and FlContAssoc are NOT NULL WITHOUT a default: they must be filled at
        // INSERT (otherwise a failure on a server in strict SQL mode). They are left out of
        // the UPDATE on purpose, not to erase an existing SVG.
        $set = "FlIocCode = $ioc, FlJPG = " . StrSafe_DB(base64_encode($jpg));
        safe_w_sql("INSERT INTO Flags SET FlTournament = $tourId, FlCode = " . StrSafe_DB($code)
            . ", $set, FlSVG = '', FlContAssoc = '' ON DUPLICATE KEY UPDATE $set");

        if (is_file($file) && md5_file($file) === md5($jpg)) { $res['current']++; continue; }
        // A write failure must SHOW: on a hardened server, TV/Photos may be read-only for the
        // web server, and the logos would then never be set — without any message if they
        // were merely not counted.
        if (@file_put_contents($file, $jpg) !== false) $res['written']++;
        else $res['failed']++;
    }
    return $res;
}

/** Competitions to feed: those not over yet (like the licence sync). */
function aut_logos_active_tournaments()
{
    $out = array();
    $rs = safe_r_sql("SELECT ToId FROM Tournament WHERE ToWhenTo >= CURDATE() ORDER BY ToId");
    while ($r = safe_fetch($rs)) $out[] = intval($r->ToId);
    return $out;
}

/**
 * "On demand" entry point, without network: called after a registration so that the logo of
 * the club of the newly registered archer is available at once, without waiting for the
 * cron. Fully isolated: a failure here must never interrupt a registration.
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
        if (!$r) return false;                       // not in the cache yet → the cron will deal with it

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

/** Statistics of the cache (administration page / cron log). */
function aut_logos_stats()
{
    aut_logos_schema();
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS total,
            SUM(ClgBytes > 0) AS withLogo, SUM(ClgMissing = 1) AS withoutLogo,
            MAX(ClgFetched) AS lastFetch, SUM(ClgBytes) AS bytes
        FROM AUT_ClubLogos", false, true));
    return $r ? array(
        'total'   => intval($r->total), 'with' => intval($r->withLogo),
        'without' => intval($r->withoutLogo), 'last' => $r->lastFetch,
        'bytes'   => intval($r->bytes),
    ) : array('total' => 0, 'with' => 0, 'without' => 0, 'last' => null, 'bytes' => 0);
}
