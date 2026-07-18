<?php
/**
 * Modules/Custom/SYNCHRO_FFTA/licences-sync.php
 * Endpoint de synchronisation des licenciés depuis l'Espace Dirigeant FFTA
 * (dirigeant.ffta.fr → parametres_ianseo.ffta → table LookUpEntries).
 *
 * Appelé uniquement par le JS injecté dans LookupTableLoad.php (licences-inject.php).
 * Espace DISTINCT de l'extranet (voir ExtranetClient.php) : ici c'est l'Espace Dirigeant.
 *
 * Réutilise le cookie de session Espace Dirigeant s'il existe (celui d'AUTH via la
 * convention FFTA_DIRIGEANT_*, ou un cookie propre déjà ouvert) : dans ce cas aucun
 * identifiant n'est demandé. Sinon, login (avec MFA via AUTH si présent — DirigeantClient).
 */

if (empty($_POST['ffta_action']) || !in_array($_POST['ffta_action'], ['sync', 'status'], true)) {
    http_response_code(405); exit;
}

chdir(dirname(dirname(dirname(dirname(__FILE__)))));

require_once('config.php');
require_once('Common/Fun_FormatText.inc.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Lib/Fun_DateTime.inc.php');
require_once('Common/CheckPictures.php');
require_once(__DIR__ . '/session.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pAdvancedTarget', AclReadWrite);

// ── Statut : une session Espace Dirigeant est-elle déjà disponible ? ──────
if ($_POST['ffta_action'] === 'status') {
    while (ob_get_level()) ob_end_clean();   // pas de sortie parasite avant le JSON
    $f = sfa_any_cookie('dir');
    $logged = $f && (new DirigeantClient($f, sfa_base('dir')))->session();
    header('Content-Type: application/json; charset=UTF-8');
    JsonOut(['ok' => true, 'logged' => (bool) $logged]);
}

// ── Limite mémoire identique à LookupTableLoad.php ───────────────────────
ini_set('memory_limit', '512M');

// ── Identifiants (éventuels) copiés puis effacés de $_POST ────────────────
$_ffta_username = $_POST['ffta_username'] ?? '';
$_ffta_password = $_POST['ffta_password'] ?? '';
$_ffta_otp      = $_POST['ffta_otp']      ?? '';

foreach (['ffta_username', 'ffta_password', 'ffta_otp'] as $k) {
    if (isset($_POST[$k])) {
        $_POST[$k] = str_repeat("\0", max(1, strlen($_POST[$k])));
        unset($_POST[$k]);
    }
}

// ── Streaming direct — pas de JSON, pas de buffer ─────────────────────────
while (ob_get_level()) ob_end_clean();

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');    // désactive le buffer nginx si présent
ob_implicit_flush(true);

// Padding initial pour forcer le passage des buffers minimaux éventuels
echo str_repeat(' ', 1024);
flush();

_sfa_lic_run($_ffta_username, $_ffta_password, $_ffta_otp);

// Effacement des copies locales après usage
$n = strlen($_ffta_username); $_ffta_username = str_repeat("\0", max(1,$n)); unset($_ffta_username, $n);
$n = strlen($_ffta_password); $_ffta_password = str_repeat("\0", max(1,$n)); unset($_ffta_password, $n);
$n = strlen($_ffta_otp);      $_ffta_otp      = str_repeat("\0", max(1,$n)); unset($_ffta_otp, $n);

exit;


// ══════════════════════════════════════════════════════════════════════════
// FONCTIONS
// ══════════════════════════════════════════════════════════════════════════

function _sfa_lic_run(string $username, string $password, string $otp = ''): void {

    // Identifiants fournis → login Espace Dirigeant (MFA gérée via AUTH si présent).
    if ($username !== '' && $password !== '') {
        echo '1) Connexion à l\'Espace Dirigeant FFTA…<br>'; flush();
        $res = sfa_login($username, $password, $otp, ['dir']);
        if (empty($res['dir']['ok'])) {
            echo '<b style="color:red">' . htmlspecialchars($res['dir']['msg'] ?? 'Connexion refusée.') . '</b>'; flush();
            return;
        }
    }

    // Réutilise la session Espace Dirigeant (celle ouverte à l'instant, celle d'AUTH,
    // ou une précédente du module).
    $cookie = sfa_any_cookie('dir');
    if (!$cookie) {
        echo '<b style="color:red">Aucune session Espace Dirigeant — renseignez vos identifiants.</b>'; flush();
        return;
    }

    echo '2) Téléchargement du fichier des licenciés…<br>'; flush();
    $dl = (new DirigeantClient($cookie, sfa_base('dir')))->downloadLicences();
    if (!$dl['ok']) {
        echo '<b style="color:red">Échec du téléchargement : ' . htmlspecialchars($dl['error']) . '</b>';
        if (!empty($dl['relogin'])) echo '<br>Session expirée — reconnectez-vous.';
        flush();
        return;
    }

    $size = strlen($dl['body']);
    echo '3) Fichier reçu (' . number_format($size) . ' octets). Import…<br>'; flush();

    $tmpFile = tempnam(sys_get_temp_dir(), 'ffta_imp_');
    chmod($tmpFile, 0600);
    file_put_contents($tmpFile, $dl['body']);
    unset($dl);

    register_shutdown_function(function () use ($tmpFile) {
        if (file_exists($tmpFile)) @unlink($tmpFile);
    });

    _ffta_import($tmpFile);

    if (file_exists($tmpFile)) @unlink($tmpFile);
}

function _ffta_import(string $file): void {
    $data = file_get_contents($file);
    if (!$data) {
        echo '<b style="color:red">Lecture du fichier temporaire impossible</b>'; flush();
        return;
    }
    if ($u = @gzuncompress($data)) $data = $u;

    $archers = json_decode($data);
    if ($archers !== null) {
        unset($data);
        _ffta_importJson($archers);
    } else {
        _ffta_importTabulated($data);
        unset($data);
    }
}

function _ffta_importJson($archers): void {
    $ioc = 'FRA';
    safe_w_sql("DELETE FROM LookUpEntries WHERE LueIocCode='$ioc'");
    echo '4) Insertion :<br>'; flush();
    $n = 0;
    safe_w_BeginTransaction();
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
        if (($n++ % 100) === 0) { echo '- '; flush(); }
        if ($n % 5000 === 0)    { echo '<br>'; flush(); }
    }
    safe_w_Commit();
    safe_w_sql("UPDATE LookUpPaths SET LupLastUpdate='" . date('Y-m-d H:i:s')
             . "' WHERE LupIocCode='$ioc'");
    echo '<br>' . number_format($n) . ' athlètes importés.<br>'; flush();
    _ffta_entriesCheck();
    echo '6) Synchronisation terminée.'; flush();
}

function _ffta_importTabulated(string $data): void {
    global $CFG;
    $dir = $CFG->DOCUMENT_PATH . 'Tournament/TmpDownload';
    if (!is_dir($dir)) { mkdir($dir, 0777, true); chmod($dir, 0777); }
    $work = $dir . '/archers_ffta.dat';
    file_put_contents($work, $data);

    $fp = @fopen($work, 'r');
    if (!$fp) {
        echo '<b style="color:red">Ouverture fichier de travail impossible</b>'; flush();
        return;
    }

    $buf = fgets($fp);
    if (!preg_match('/VERSION: [0-9]+\.[0-9]+/', $buf)) {
        fclose($fp); @unlink($work);
        echo '<b style="color:red">Format invalide (VERSION)</b>'; flush(); return;
    }
    [, $ver] = explode(':', $buf);
    if (trim($ver) !== '2.0') {
        fclose($fp); @unlink($work);
        echo '<b style="color:red">Version incompatible : ' . htmlspecialchars(trim($ver)) . '</b>'; flush(); return;
    }

    $buf = fgets($fp);
    if (!preg_match('/DATE: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $buf)) {
        fclose($fp); @unlink($work);
        echo '<b style="color:red">Format de date invalide</b>'; flush(); return;
    }
    $date = str_replace('DATE: ', '', trim($buf));

    $buf = rtrim(fgets($fp));
    if (substr($buf, 0, 4) !== 'IOC:') {
        fclose($fp); @unlink($work);
        echo '<b style="color:red">Code IOC manquant</b>'; flush(); return;
    }
    $ioc = preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim(str_replace('IOC:', '', $buf))));
    if (empty($ioc)) $ioc = 'FRA';

    $buf = fgets($fp);
    if (!preg_match('/CLUBS/', $buf)) {
        fclose($fp); @unlink($work);
        echo '<b style="color:red">Section CLUBS manquante</b>'; flush(); return;
    }

    safe_w_sql("DELETE FROM LookUpEntries WHERE LueIocCode='$ioc'");
    safe_w_BeginTransaction();

    $clubs = [];
    while (($buf = fgets($fp)) !== false) {
        $buf = substr($buf, 0, -1);
        if ($buf === 'ENTRIES') break;
        $row = explode("\t", $buf);
        $clubs[$row[0]] = [$row[1] ?? '', $row[2] ?? ''];
    }

    echo '4) Insertion :<br>'; flush();
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
        $c1  = $clubs[$row[9]  ?? ''] ?? ['', ''];
        $c2  = $clubs[$row[10] ?? ''] ?? ['', ''];
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
                StrSafe_DB($row[$i+1]?? ''),
                StrSafe_DB($row[$i+2]?? '')
            ));
        }
        if (($n++ % 100) === 0) { echo '- '; flush(); }
        if ($n % 5000 === 0)    { echo '<br>'; flush(); }
    }
    fclose($fp);
    @unlink($work);
    safe_w_Commit();

    safe_w_sql("INSERT INTO LookUpPaths SET LupIocCode='$ioc', LupLastUpdate='$date'"
             . " ON DUPLICATE KEY UPDATE LupLastUpdate='$date'");
    echo '<br>' . number_format($n) . ' athlètes importés.<br>'; flush();
    _ffta_entriesCheck();
    echo '6) Synchronisation terminée.'; flush();
}

function _ffta_entriesCheck(): void {
    if (IsBlocked(BIT_BLOCK_PARTICIPANT)) return;
    echo '5) Mise à jour des statuts…<br>'; flush();
    $tid = StrSafe_DB($_SESSION['TourId']);
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
