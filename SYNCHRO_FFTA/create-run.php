<?php
/**
 * SYNCHRO_FFTA — exécution de la création assistée.
 *
 * Reçoit le formulaire #review (infos de base déjà validées côté extranet + paramètres
 * assistés : ISK, départs, cibles, rythme), crée la compétition puis redirige vers
 * la saisie des participants.
 *
 * Principe : on réutilise au MAXIMUM le code cœur de ianseo — GetSetupFile (distances/
 * blasons par type), insertSession (départs + cibles + rythme + régénération des cibles),
 * setModuleParameter/Set_Tournament_Option (ISK), CreateTourSession (ouverture). Seul
 * l'INSERT de base est répliqué depuis Tournament/index.php (schéma stable) — à garder
 * synchronisé si ianseo ajoute une colonne obligatoire à la création.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once($CFG->DOCUMENT_PATH . 'Common/Fun_ScriptsOnNewTour.inc.php');   // GetSetupFile
require_once($CFG->DOCUMENT_PATH . 'Common/Fun_Sessions.inc.php');
require_once($CFG->DOCUMENT_PATH . 'Common/Fun_Various.inc.php');            // calcMaxTeamPerson
require_once($CFG->DOCUMENT_PATH . 'Common/Lib/Fun_Modules.php');            // setModuleParameter
require_once($CFG->DOCUMENT_PATH . 'Tournament/Fun_ManSessions.inc.php');    // insertSession
require_once(__DIR__ . '/mapping.php');

CheckTourSession(false);

$backTo = $CFG->ROOT_DIR . 'Modules/Custom/SYNCHRO_FFTA/create.php';

/** Retour à la page de création avec un message d'erreur. */
function sfa_fail(string $msg): void
{
    global $backTo;
    CD_redirect($backTo . '?err=' . rawurlencode($msg));
    exit;
}

// ── Champs de base ───────────────────────────────────────────────────────────
$code    = preg_replace('/[^0-9a-z._-]+/i', '_', trim($_POST['d_ToCode'] ?? ''));
$name    = trim($_POST['d_ToName'] ?? '');
$toType  = (int) ($_POST['d_ToType'] ?? 0);
$subIdx  = (int) ($_POST['d_SubRule'] ?? 0);
$fromY   = (int) ($_POST['xx_ToWhenFromYear'] ?? 0);
$fromM   = (int) ($_POST['xx_ToWhenFromMonth'] ?? 0);
$fromD   = (int) ($_POST['xx_ToWhenFromDay'] ?? 0);
$toY     = (int) ($_POST['xx_ToWhenToYear'] ?? 0);
$toM     = (int) ($_POST['xx_ToWhenToMonth'] ?? 0);
$toD     = (int) ($_POST['xx_ToWhenToDay'] ?? 0);

if ($code === '' || strlen($code) > 8) {
    sfa_fail('Code compétition invalide.');
}
if ($name === '' || $toType === 0 || $fromY === 0) {
    sfa_fail('Nom, discipline et dates sont requis.');
}

// Droit de créer (et, sous AUTH, enregistrement de la revendication du code — comme la
// page native le fait via possibleFeature).
$authOn = !empty($CFG->USERAUTH) && !empty($_SESSION['AUTH_ENABLE']);
if ($authOn && empty($_SESSION['AUTH_ROOT']) && !possibleFeature(AclRoot, AclReadWrite, $code)) {
    sfa_fail('Vous n\'avez pas le droit de créer cette compétition, ou le code est déjà utilisé.');
} elseif (!$authOn) {
    // hors AUTH : refuser quand même un code déjà porté par une compétition existante
    $q = safe_r_sql('SELECT ToId FROM Tournament WHERE ToCode=' . StrSafe_DB($code));
    if (safe_num_rows($q)) {
        sfa_fail('Ce code de compétition existe déjà.');
    }
}

// Sous-règle : index (formulaire) → code (stockage) via les règles FR réelles.
$fr      = sfa_fr_sets();
$subCode = $fr['rules'][$toType][$subIdx - 1] ?? '';

$dbVer   = GetParameter('DBUpdate');
$tz      = trim($_POST['d_ToTimeZone'] ?? '+01:00');

// ── INSERT de base (répliqué depuis Tournament/index.php, schéma stable) ─────
$fromDate = sprintf('%04d-%02d-%02d', $fromY, $fromM, $fromD);
$toDate   = sprintf('%04d-%02d-%02d', $toY, $toM, $toD);

$Insert = "INSERT INTO Tournament SET "
    . "ToType="        . StrSafe_DB($toType)
    . ", ToCode="      . StrSafe_DB($code)
    . ", ToName="      . StrSafe_DB($name)
    . ", ToNameShort=" . StrSafe_DB(mb_substr($name, 0, 60))
    . ", ToIocCode="   . StrSafe_DB(trim($_POST['d_ToIocCode'] ?? ''))
    . ", ToCommitee="  . StrSafe_DB(trim($_POST['d_ToCommitee'] ?? ''))
    . ", ToComDescr="  . StrSafe_DB(trim($_POST['d_ToComDescr'] ?? ''))
    . ", ToWhere="     . StrSafe_DB(trim($_POST['d_ToWhere'] ?? ''))
    . ", ToTimeZone="  . StrSafe_DB($tz)
    . ", ToWhenFrom="  . StrSafe_DB($fromDate)
    . ", ToWhenTo="    . StrSafe_DB($toDate)
    . ", ToCurrency="  . StrSafe_DB($_POST['xx_ToCurrency'] ?? 'EUR')
    . ", ToPrintLang=" . StrSafe_DB($_POST['xx_ToPrintLang'] ?? '')
    . ", ToPrintChars=". StrSafe_DB((int) ($_POST['xx_ToPrintChars'] ?? 0))
    . ", ToPrintPaper=". (int) ($_POST['xx_ToPaperSize'] ?? 0)
    . ", ToUseHHT="    . (int) ($_POST['xx_ToUseHHT'] ?? 0)
    . ", ToDbVersion=" . StrSafe_DB($dbVer)
    . ", ToTypeSubRule=" . StrSafe_DB($subCode)
    . ", ToLocRule="   . StrSafe_DB('FR')
    . ", ToIsORIS="    . StrSafe_DB('')
    . ", ToVenue="     . StrSafe_DB(trim($_POST['d_ToVenue'] ?? ''))
    . ", ToCountry="   . StrSafe_DB(trim($_POST['d_ToCountry'] ?? 'FRA'));

safe_w_sql($Insert);
$tid = safe_w_last_id();
if (!$tid) {
    sfa_fail('Échec de la création (INSERT).');
}

// ── Setup natif du type (distances / blasons / classes) ──────────────────────
// On renseigne la session pour d'éventuels besoins internes ; l'ouverture propre
// se fera par TourOn.php à la fin (flux natif).
$_SESSION['TourId']           = $tid;
$_SESSION['TourCode']         = $code;
$_SESSION['TourRealWhenFrom'] = $fromDate;
$_SESSION['TourRealWhenTo']   = $toDate;

GetSetupFile($tid, $toType, 'FR', $subIdx ?: 1, $subCode);
calcMaxTeamPerson([], true, $tid);

// ── Départs / cibles / rythme (si renseignés) ────────────────────────────────
$departs = max(1, (int) ($_POST['sfa_departs'] ?? 1));
$cibles  = (int) ($_POST['sfa_cibles'] ?? 0);
$rythme  = (int) ($_POST['sfa_rythme'] ?? 0);   // archers/cible : 2 (AB), 3 (ABC), 4 (AB-CD)

if ($cibles > 0 && in_array($rythme, [2, 3, 4], true)) {
    for ($i = 1; $i <= $departs; $i++) {
        // SesType 'Q' : insertSession met à jour ToNumSession, régénère les cibles
        // et recopie les distances du départ précédent.
        insertSession($tid, $i, 'Q', '', null, $cibles, $rythme, 1, 0);
    }
}

// ── Saisie par téléphone (ISK-NG) ────────────────────────────────────────────
// Le formulaire envoie les champs natifs Module[ISK-NG][...] (bloc et champs rendus
// par ianseo). On les stocke via setModuleParameter (générique → suit l'ajout de
// nouveaux champs côté ianseo), et on mappe le mode vers UseApi comme la page native.
$isk = $_POST['Module']['ISK-NG'] ?? [];
$iskMode = $isk['Mode'] ?? '';
if (in_array($iskMode, ['ng-lite', 'ng-pro', 'ng-live'], true)) {
    // Pro sans licence → Lite (règle native).
    if ($iskMode === 'ng-pro' && trim($isk['LicenseNumber'] ?? '') === '') {
        $iskMode = 'ng-lite';
        $isk['Mode'] = 'ng-lite';
        unset($isk['LicenseNumber']);
    }

    foreach ($isk as $param => $value) {
        setModuleParameter('ISK-NG', $param, $value, $tid);
    }

    $apiType = ['ng-lite' => 11, 'ng-pro' => 12, 'ng-live' => 13];
    Set_Tournament_Option('UseApi', $apiType[$iskMode], false, $tid);
}

// ── Ouverture propre (TourOn natif) puis saisie des participants ─────────────
CD_redirect($CFG->ROOT_DIR . 'Common/TourOn.php?ToId=' . $tid
    . '&BackTo=' . $CFG->ROOT_DIR . 'Partecipants/index.php');
exit;
