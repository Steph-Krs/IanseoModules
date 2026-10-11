<?php
/**
 * AUTH module — synchronisation of the FFTA licences by cron (command line only).
 *
 * Downloads parametres_ianseo.ffta from the officers' space with a service account
 * (config.local.json → "licsync") and imports it into LookUpEntries. The organisers thus
 * never have to synchronise by themselves: the licensee lookup database is kept up to date
 * on the server side.
 *
 * Import flow taken from the existing French integration (FFTAAjax.php).
 *
 * crontab (every day at 03:15):
 *   15 3 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/sync-licences.php >> /var/log/ianseo-licsync.log 2>&1
 *
 * config.local.json (chmod 600):
 *   { "licsync": { "username": "service-account", "password": "…", "otp": "" } }
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Cron script: command line only.');
}

$SKIP_AUTH = 1;   // no web bootstrap in CLI
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
aut_table_names();   // module updated since the last page opened: see names-lib.php
require_once('Common/Fun_FormatText.inc.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Lib/Fun_DateTime.inc.php');

ini_set('memory_limit', '512M');

function lic_log($msg) {
    // LOCAL time (ianseo forces PHP to UTC) — see aut_log_time().
    echo '[' . aut_log_time() . '] ' . $msg . "\n";
}

function lic_fail($msg) {
    lic_log('ERROR: ' . $msg);
    aut_log('LICSYNC_FAIL', 'cron', 'cli');
    aut_job_log('licences', 'fail', 'JbLicFail', $msg);
    exit(1);
}

/**
 * Officers' space under maintenance: this is NOT a failure of the sync. Distinct log entry
 * (LICSYNC_SKIP) and exit 0, not to raise a needless alarm — the federation file of the day
 * before stays in the database, the next night will catch up.
 */
function lic_skip($msg) {
    lic_log('POSTPONED: ' . $msg);
    aut_log('LICSYNC_SKIP', 'cron', 'cli');
    aut_job_log('licences', 'skip', 'JbLicSkip', $msg);
    exit(0);
}

/* ---- Lock against a double run ---- */
$lock = fopen(__DIR__ . '/.sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    lic_fail('a synchronisation is already running.');
}

/* ---- Credentials of the service account ---- */
$cfg = aut_local_config()['licsync'] ?? array();
$username = $cfg['username'] ?? '';
$password = $cfg['password'] ?? '';
$otp      = $cfg['otp'] ?? '';
if ($username === '' || $password === '') {
    lic_fail('credentials missing from config.local.json ("licsync" key).');
}

/* ---- Sign-in + download ---- */
lic_log('Signing in to the FFTA officers\' space…');
$landing = '';
$error = '';
$errCode = '';
$ch = aut_ffta_curl_login($username, $password, $otp, $landing, $error, $ckOut, $errCode);
$password = '';
if (!$ch) {
    if ($errCode === 'OUTAGE' || $errCode === 'NETWORK') lic_skip($error);
    lic_fail($error);
}

lic_log('Signed in. Downloading parametres_ianseo.ffta…');
curl_setopt_array($ch, array(
    CURLOPT_URL     => AUT_FFTA_BASE . '/ianseo/download/parametres_ianseo.ffta',
    CURLOPT_HTTPGET => true,
    CURLOPT_TIMEOUT => 120,
));
$data = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

if (strpos($finalUrl, '/login') !== false) lic_fail('session expired during the download (MFA on the service account?).');
if (!$data || $http !== 200) lic_fail("download failed (HTTP $http).");
// bytes: the size of a download
lic_log('File received (' . number_format(strlen($data)) . ' bytes).');

if ($u = @gzuncompress($data)) $data = $u;

/* ---- Import (JSON or tab-separated 2.0 formats, as the FR set) ---- */
$licCount = function () {
    $r = safe_fetch(safe_r_sql("SELECT COUNT(DISTINCT LueCode) AS n FROM LookUpEntries WHERE LueIocCode = 'FRA'"));
    return $r ? intval($r->n) : 0;
};
$before = $licCount();
$archers = json_decode($data);
if ($archers !== null) {
    $n = lic_import_json($archers);
} else {
    $n = lic_import_tabulated($data);
}
unset($data, $archers);
lic_log(number_format($n) . ' licensees imported into LookUpEntries.');

// The file is the licence: an archer missing from it has none today. A file cut short would
// suspend registrations by the hundred, so it is believed only when it holds at least 90 % of
// the licensees of the day before (booking/lib/licences.php, licence-lib.php read this verdict).
$after = $licCount();
$fileOk = $after > 0 && ($before === 0 || $after >= 0.9 * $before);
SetParameter('AutLicFileOk', $fileOk ? '1' : '0');
if (!$fileOk) {
    lic_log("WARNING: $after licensees against $before the day before: missing licences are not acted upon.");
    aut_log('LICSYNC_SHRINK', 'cron', 'cli');
}

/* ---- Update of the statuses for the competitions not over yet ---- */
$_SESSION = array();   // the registration swaps the competition session (bk_with_tournament)
require_once(dirname(__DIR__) . '/booking/lib/licences.php');
$q = safe_r_sql("SELECT ToId, ToCode, ToWhenTo >= CURDATE() AS NotOver FROM Tournament
    WHERE ToWhenTo >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)");
$nComp = 0; $nHeld = 0;
while ($t = safe_fetch($q)) {
    $nComp++;
    lic_entries_check($t->ToId);
    lic_log("Statuses updated: {$t->ToCode}");
    // Licences missing from the file (competitions not started, FFTA numbers only).
    if ($fileOk && intval($t->NotOver)) {
        $res = bk_licence_daily($t->ToId);
        $nHeld += $res['suspended'];
        if ($res['suspended'] || $res['status'] || $res['back']) {
            lic_log("Licences of {$t->ToCode}: {$res['suspended']} registration(s) suspended, "
                . "{$res['status']} status set, {$res['back']} licence(s) back.");
        }
    }
}

aut_log('LICSYNC_OK', 'cron', 'cli');
aut_job_log('licences', $fileOk ? 'ok' : 'warn', $fileOk ? 'JbLicOk' : 'JbLicShrink',
    array('n' => $after, 'b' => $before, 'c' => $nComp, 'h' => $nHeld));

// Log retention (canonical daily job). The bootstrap also does it at most once a day.
if (function_exists('aut_log_purge')) { aut_log_purge(); lic_log('Logs purged (retention).'); }

lic_log('Synchronisation done.');
flock($lock, LOCK_UN);
exit(0);

/* ══════════════════════════════════════════════════════════════════ */

function lic_import_json($archers) {
    $ioc = 'FRA';
    safe_w_sql("DELETE FROM LookUpEntries WHERE LueIocCode='$ioc'");
    safe_w_BeginTransaction();
    $n = 0;
    foreach ($archers as $r) {
        $d = "LueCode="         . StrSafe_DB(isset($r->WaId) ? $r->WaId : $r->Id)
           . ", LueIocCode="    . StrSafe_DB($ioc)
           . ", LueFamilyName=" . StrSafe_DB($r->FamilyName)
           . ", LueName="       . StrSafe_DB($r->GivenName)
           . ", LueSex="        . ($r->Gender === 'M' ? 0 : 1)
           . ", LueClassified=" . (empty($r->Para) ? 0 : 1)
           . ", LueCtrlCode='"  . ConvertDateLoc($r->BirthDate) . "'"
           . ", LueCountry="    . StrSafe_DB($r->CountryCode)
           . ", LueCoDescr="    . StrSafe_DB($r->CountryName)
           . ", LueCoShort="    . StrSafe_DB($r->ShortCountryName)
           . ", LueNameOrder="  . intval($r->NameOrder)
           . ", LueStatus="     . intval($r->Status)
           . ", LueDefault=1";
        safe_w_sql("INSERT INTO LookUpEntries SET $d ON DUPLICATE KEY UPDATE $d");
        $n++;
    }
    safe_w_Commit();
    safe_w_sql("UPDATE LookUpPaths SET LupLastUpdate='" . date('Y-m-d H:i:s') . "' WHERE LupIocCode='$ioc'");
    return $n;
}

function lic_import_tabulated($data) {
    $work = tempnam(sys_get_temp_dir(), 'lic_');
    file_put_contents($work, $data);
    $fp = fopen($work, 'r');
    if (!$fp) { @unlink($work); lic_fail('working file cannot be read.'); }

    $buf = fgets($fp);
    if (!preg_match('/VERSION: [0-9]+\.[0-9]+/', $buf)) { fclose($fp); @unlink($work); lic_fail('invalid format (VERSION).'); }
    list(, $ver) = explode(':', $buf);
    if (trim($ver) !== '2.0') { fclose($fp); @unlink($work); lic_fail('incompatible format version: ' . trim($ver)); }

    $buf = fgets($fp);
    if (!preg_match('/DATE: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $buf)) { fclose($fp); @unlink($work); lic_fail('invalid date.'); }
    $date = str_replace('DATE: ', '', trim($buf));

    $buf = rtrim(fgets($fp));
    // bytes: an ASCII header of the federation file
    if (substr($buf, 0, 4) !== 'IOC:') { fclose($fp); @unlink($work); lic_fail('IOC code missing.'); }
    $ioc = preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim(str_replace('IOC:', '', $buf))));
    if (empty($ioc)) $ioc = 'FRA';

    $buf = fgets($fp);
    if (!preg_match('/CLUBS/', $buf)) { fclose($fp); @unlink($work); lic_fail('CLUBS section missing.'); }

    safe_w_sql("DELETE FROM LookUpEntries WHERE LueIocCode='$ioc'");
    safe_w_BeginTransaction();

    $clubs = array();
    while (($buf = fgets($fp)) !== false) {
        // bytes: removes the line feed
        $buf = substr($buf, 0, -1);
        if ($buf === 'ENTRIES') break;
        $row = explode("\t", $buf);
        $clubs[$row[0]] = array($row[1] ?? '', $row[2] ?? '');
    }

    $tpl = "INSERT IGNORE INTO LookUpEntries SET "
         . "LueCode=%s, LueIocCode=" . StrSafe_DB($ioc)
         . ", LueFamilyName=%s, LueName=%s, LueSex=%d, LueCtrlCode='%s'"
         . ", LueCountry=%s, LueCoDescr=%s, LueCoShort=%s"
         . ", LueCountry2=%s, LueCoDescr2=%s, LueCoShort2=%s"
         . ", LueDivision=%s, LueStatus=%d, LueStatusValidUntil=%s"
         . ", LueClass=%s, LueSubClass=%s, LueDefault=%s";

    $n = 0;
    while (($buf = fgets($fp)) !== false) {
        $row = explode("\t", rtrim($buf));
        $c1  = $clubs[$row[9]  ?? ''] ?? array('', '');
        $c2  = $clubs[$row[10] ?? ''] ?? array('', '');
        for ($i = 11; $i < count($row); $i += 3) {
            safe_w_sql(sprintf($tpl,
                StrSafe_DB($row[0]),
                StrSafe_DB($row[2]), StrSafe_DB($row[3]),
                intval($row[4]), $row[5],
                StrSafe_DB($row[9]),
                StrSafe_DB($c1[0]), StrSafe_DB($c1[1] ?: $c1[0]),
                StrSafe_DB($row[10] ?? ''),
                StrSafe_DB($c2[0]), StrSafe_DB($c2[1] ?: $c2[0]),
                StrSafe_DB($row[6]   ?? ''),
                intval($row[7]       ?? 0),
                StrSafe_DB($row[8]   ?? ''),
                StrSafe_DB($row[$i]  ?? ''),
                StrSafe_DB($row[$i+1] ?? ''),
                StrSafe_DB($row[$i+2] ?? '')
            ));
        }
        $n++;
    }
    fclose($fp);
    @unlink($work);
    safe_w_Commit();

    safe_w_sql("INSERT INTO LookUpPaths SET LupIocCode='$ioc', LupLastUpdate='$date'"
             . " ON DUPLICATE KEY UPDATE LupLastUpdate='$date'");
    return $n;
}

/**
 * Passes the licence statuses on to the registrations of a competition. Left alone: 1 (written
 * by the online registration, until the check-in desk), 6, 7 and 8 — decisions of the organiser
 * or of the check-in desk (AUTH/shop/lib/desk.php), which the file of the night must not undo.
 */
function lic_entries_check($tid) {
    $tid = intval($tid);
    $now = date('Y-m-d H:i:s');

    safe_w_sql("UPDATE Entries
        INNER JOIN Tournament ON EnTournament=ToId
        INNER JOIN LookUpEntries ON EnCode=LueCode
            AND LueIocCode=IF(EnIocCode!='',EnIocCode,ToIocCode)
        SET EnTimestamp=IF(EnStatus!=IF(ToWhenTo>LueStatusValidUntil
                AND LueStatusValidUntil<>'0000-00-00',5,LueStatus),'$now',EnTimestamp),
            EnStatus=IF(ToWhenTo>LueStatusValidUntil
                AND LueStatusValidUntil<>'0000-00-00',5,LueStatus),
            EnNameOrder=LueNameOrder, EnClassified=LueClassified
        WHERE EnTournament=$tid
          AND NOT (EnStatus=6 OR EnStatus=7 OR EnStatus=1 OR EnStatus=8)");

    safe_w_sql("UPDATE Entries
        INNER JOIN Tournament ON EnTournament=ToId
        INNER JOIN LookUpEntries ON EnCode=LueCode
            AND LueIocCode=IF(EnIocCode!='',EnIocCode,ToIocCode)
            AND EnClass=LueClass
            AND EnDivision=IF(ToIocCode='ITA_i',LueDivision,EnDivision)
        SET EnSubClass=LueSubClass,
            EnTimestamp=IF(EnSubClass=LueSubClass,EnTimestamp,'$now')
        WHERE EnTournament=$tid");

    safe_w_sql("UPDATE Entries
        INNER JOIN LookUpPaths ON EnIocCode=LupIocCode
        SET EnLueTimeStamp=LupLastUpdate
        WHERE EnTournament=$tid");

    $rs = safe_r_SQL("SELECT EnId FROM Entries WHERE EnTournament=$tid");
    while ($r = safe_fetch($rs)) checkAgainstLUE($r->EnId);
}
