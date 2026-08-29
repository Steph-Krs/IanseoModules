<?php
/**
 * SYNCHRO_FFTA — préremplissage post-création : logos (FFTA + club) et dossard par défaut.
 *
 * Appelé en tant que BackTo de Common/TourOn.php (donc APRÈS create-run.php) : la session
 * tournoi complète (ACL, TourCodeSafe...) n'existe qu'une fois CreateTourSession() passée, ce
 * qui n'est vrai qu'à partir de cette page — CheckTourSession(true) échouerait encore dans
 * create-run.php lui-même.
 *
 * Le dossard est importé en rejouant EXACTEMENT ce que fait le bouton natif « Importer »
 * (baseForm(this)) de Accreditation/IdCards.php : requête HTTP interne portant la même
 * session, jamais de réimplémentation du format .ianseo (gzcompress(serialize(...))).
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once($CFG->DOCUMENT_PATH . 'Common/CheckPictures.php');
require_once(__DIR__ . '/mapping.php');   // sfa_booking_present()

CheckTourSession(true);

$tid = (int) $_SESSION['TourId'];

/**
 * Trouve le fichier image par défaut d'un emplacement (ToLeft/ToRight/ToBottom) dans assets/,
 * quelle que soit son extension. Convention : il suffit de remplacer le fichier en conservant
 * ce nom de base pour changer l'image par défaut sur ce serveur — jamais resynchronisé par les
 * mises à jour du module (pas dans files[] de version.json, comme module.json).
 */
function sfa_asset_image(string $base): ?string
{
    $matches = glob(__DIR__ . '/assets/' . $base . '.*');
    return $matches ? $matches[0] : null;
}

// ── Logos + footer ────────────────────────────────────────────────────────────
// Écrit comme le fait Tournament/ManLogo.php (StrSafe_DB(file_get_contents(...)), limite native
// 262143 octets pour un mediumblob Tournament.ToImgL/ToImgR/ToImgB).
$leftFile = sfa_asset_image('ToLeft');
if ($leftFile && filesize($leftFile) > 0 && filesize($leftFile) <= 262143) {
    safe_w_sql("UPDATE Tournament SET ToImgL=" . StrSafe_DB(file_get_contents($leftFile)) . " WHERE ToId=$tid");
}

$bottomFile = sfa_asset_image('ToBottom');
if ($bottomFile && filesize($bottomFile) > 0 && filesize($bottomFile) <= 262143) {
    safe_w_sql("UPDATE Tournament SET ToImgB=" . StrSafe_DB(file_get_contents($bottomFile)) . " WHERE ToId=$tid");
}

// ToRight = logo du club, récupéré depuis l'extranet FFTA (endpoint public, vérifié sans
// authentification : renvoie directement du image/png). Si l'appel échoue (club sans logo, pas
// d'accès internet...), on retombe sur l'image par défaut assets/ToRight.* — jamais de blocage
// de la création pour ça.
$toRightData = null;
$q = safe_r_sql("SELECT ToCommitee FROM Tournament WHERE ToId=$tid");
if (($r = safe_fetch($q)) && trim((string) $r->ToCommitee) !== '') {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://extranet.ffta.fr/ianseo/logo.php?png=' . urlencode(trim($r->ToCommitee)),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($resp !== false && $code === 200 && stripos($ctype, 'image/') === 0) {
        $body = substr($resp, $hsize);
        if ($body !== '' && strlen($body) <= 262143) {
            $toRightData = $body;
        }
    }
}
if ($toRightData === null) {
    $rightFile = sfa_asset_image('ToRight');
    if ($rightFile && filesize($rightFile) > 0 && filesize($rightFile) <= 262143) {
        $toRightData = file_get_contents($rightFile);
    }
}
if ($toRightData !== null) {
    safe_w_sql("UPDATE Tournament SET ToImgR=" . StrSafe_DB($toRightData) . " WHERE ToId=$tid");
}

CheckPictures($_SESSION['TourCodeSafe']);

// ── Dossard par défaut ───────────────────────────────────────────────────────
// IMPORTANT : ne JAMAIS passer CardNumber dans cette requête. IdCards.php redirige (cd_redirect,
// avant même d'atteindre la logique d'import) dès que CardNumber est présent dans la requête
// mais qu'aucune ligne IdCards ne correspond encore — ce qui est TOUJOURS le cas ici (compétition
// neuve). Omettre CardNumber reproduit le tout premier accès natif à la page (aucun dossard
// existant) : le script continue alors normalement avec CardNumber=0 par défaut.
$dossardFile = __DIR__ . '/assets/Dossard-A6-2027.ianseo';
if (is_readable($dossardFile)) {
    $sid  = session_id();
    $name = session_name();
    session_write_close();   // libère le verrou : la requête interne partage la même session

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url    = $scheme . '://' . $host . $CFG->ROOT_DIR . 'Accreditation/IdCards.php?CardType=Q';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'CardType'          => 'Q',
            'ImportBackNumbers' => new CURLFile($dossardFile, 'application/octet-stream', 'Dossard-A6-2027.ianseo'),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE         => $name . '=' . $sid,   // même session ianseo
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,   // requête vers soi-même (certif local possible)
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    curl_exec($ch);
    curl_close($ch);

    @session_start();   // réouvre la session pour la suite du script

    // baseForm(this) ne transmet pas de libellé : IdCards.php reprend le nom existant ou, à
    // défaut (notre cas), une traduction générique ("Qualification Athlète Numéro"). On force
    // ici le libellé demandé, seule différence volontaire avec l'import natif.
    if (safe_num_rows(safe_r_sql("SELECT 1 FROM IdCards WHERE IcTournament=$tid AND IcType='Q' AND IcNumber=0"))) {
        safe_w_sql("UPDATE IdCards SET IcName=" . StrSafe_DB('Dossard')
            . " WHERE IcTournament=$tid AND IcType='Q' AND IcNumber=0");
    }
}

// ── Étape suivante (identique à create-run.php) ──────────────────────────────
$nextPage = sfa_booking_present()
    ? 'Modules/Custom/AUTH/booking/admin/competition.php'
    : 'Partecipants/index.php';

CD_redirect($CFG->ROOT_DIR . $nextPage);
exit;
