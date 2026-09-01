<?php
/**
 * Module AUTH — Synchronisation des licences FFTA par cron (CLI uniquement).
 *
 * Télécharge parametres_ianseo.ffta depuis l'Espace Dirigeant avec un compte
 * de service (config.local.json → "licsync") et importe dans LookUpEntries.
 * Les organisateurs n'ont ainsi jamais à synchroniser eux-mêmes : la base de
 * rapprochement licenciés est maintenue à jour côté serveur.
 *
 * Flux d'import repris de l'intégration FR existante (FFTAAjax.php).
 *
 * crontab (tous les jours à 03h15) :
 *   15 3 * * * www-data /usr/bin/php /var/www/ianseo/Modules/Custom/AUTH/cron/sync-licences.php >> /var/log/ianseo-licsync.log 2>&1
 *
 * config.local.json (chmod 600) :
 *   { "licsync": { "username": "compte-service", "password": "…", "otp": "" } }
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Script cron : exécution en ligne de commande uniquement.');
}

$SKIP_AUTH = 1;   // pas de bootstrap web en CLI
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once('Common/Fun_FormatText.inc.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Lib/Fun_DateTime.inc.php');

ini_set('memory_limit', '512M');

function lic_log($msg) {
    // Heure LOCALE (ianseo force PHP en UTC) — voir aut_log_time().
    echo '[' . aut_log_time() . '] ' . $msg . "\n";
}

function lic_fail($msg) {
    lic_log('ERREUR : ' . $msg);
    aut_log('LICSYNC_FAIL', 'cron', 'cli');
    exit(1);
}

/**
 * Espace dirigeant en maintenance : ce n'est PAS une panne de la synchro. Journal
 * distinct (LICSYNC_SKIP) et sortie 0, pour ne pas déclencher d'alerte inutile —
 * le fichier fédéral de la veille reste en base, la nuit suivante rattrapera.
 */
function lic_skip($msg) {
    lic_log('REPORTÉ : ' . $msg);
    aut_log('LICSYNC_SKIP', 'cron', 'cli');
    exit(0);
}

/* ---- Verrou anti-double-exécution ---- */
$lock = fopen(__DIR__ . '/.sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    lic_fail('une synchronisation est déjà en cours.');
}

/* ---- Identifiants du compte de service ---- */
$cfg = aut_local_config()['licsync'] ?? array();
$username = $cfg['username'] ?? '';
$password = $cfg['password'] ?? '';
$otp      = $cfg['otp'] ?? '';
if ($username === '' || $password === '') {
    lic_fail('identifiants absents de config.local.json (clé "licsync").');
}

/* ---- Connexion + téléchargement ---- */
lic_log('Connexion à l\'Espace Dirigeant FFTA…');
$landing = '';
$error = '';
$errCode = '';
$ch = aut_ffta_curl_login($username, $password, $otp, $landing, $error, $ckOut, $errCode);
$password = '';
if (!$ch) {
    if ($errCode === 'OUTAGE' || $errCode === 'NETWORK') lic_skip($error);
    lic_fail($error);
}

lic_log('Authentifié. Téléchargement de parametres_ianseo.ffta…');
curl_setopt_array($ch, array(
    CURLOPT_URL     => AUT_FFTA_BASE . '/ianseo/download/parametres_ianseo.ffta',
    CURLOPT_HTTPGET => true,
    CURLOPT_TIMEOUT => 120,
));
$data = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

if (strpos($finalUrl, '/login') !== false) lic_fail('session expirée pendant le téléchargement (MFA sur le compte de service ?).');
if (!$data || $http !== 200) lic_fail("téléchargement échoué (HTTP $http).");
lic_log('Fichier reçu (' . number_format(strlen($data)) . ' octets).');

if ($u = @gzuncompress($data)) $data = $u;

/* ---- Import (formats JSON ou tabulé 2.0, comme le set FR) ---- */
$archers = json_decode($data);
if ($archers !== null) {
    $n = lic_import_json($archers);
} else {
    $n = lic_import_tabulated($data);
}
unset($data, $archers);
lic_log(number_format($n) . ' licenciés importés dans LookUpEntries.');

/* ---- Mise à jour des statuts pour les compétitions non terminées ---- */
$q = safe_r_sql("SELECT ToId, ToCode FROM Tournament WHERE ToWhenTo >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)");
while ($t = safe_fetch($q)) {
    lic_entries_check($t->ToId);
    lic_log("Statuts mis à jour : {$t->ToCode}");
}

aut_log('LICSYNC_OK', 'cron', 'cli');

// Rétention des journaux (job quotidien canonique). Le bootstrap le fait aussi au plus 1×/jour.
if (function_exists('aut_log_purge')) { aut_log_purge(); lic_log('Journaux purgés (rétention).'); }

lic_log('Synchronisation terminée.');
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
    if (!$fp) { @unlink($work); lic_fail('fichier de travail illisible.'); }

    $buf = fgets($fp);
    if (!preg_match('/VERSION: [0-9]+\.[0-9]+/', $buf)) { fclose($fp); @unlink($work); lic_fail('format invalide (VERSION).'); }
    list(, $ver) = explode(':', $buf);
    if (trim($ver) !== '2.0') { fclose($fp); @unlink($work); lic_fail('version de format incompatible : ' . trim($ver)); }

    $buf = fgets($fp);
    if (!preg_match('/DATE: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $buf)) { fclose($fp); @unlink($work); lic_fail('date invalide.'); }
    $date = str_replace('DATE: ', '', trim($buf));

    $buf = rtrim(fgets($fp));
    if (substr($buf, 0, 4) !== 'IOC:') { fclose($fp); @unlink($work); lic_fail('code IOC manquant.'); }
    $ioc = preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim(str_replace('IOC:', '', $buf))));
    if (empty($ioc)) $ioc = 'FRA';

    $buf = fgets($fp);
    if (!preg_match('/CLUBS/', $buf)) { fclose($fp); @unlink($work); lic_fail('section CLUBS manquante.'); }

    safe_w_sql("DELETE FROM LookUpEntries WHERE LueIocCode='$ioc'");
    safe_w_BeginTransaction();

    $clubs = array();
    while (($buf = fgets($fp)) !== false) {
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

/** Répercute les statuts de licence sur les inscriptions d'une compétition. */
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
          AND NOT (EnStatus=6 OR EnStatus=7 OR EnStatus=1)");

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
