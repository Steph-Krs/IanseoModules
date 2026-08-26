<?php
/**
 * lib/boot.php — amorçage commun des pages et des points AJAX du module.
 *
 * L'appelant définit HTDOCS (la profondeur diffère entre une page racine et
 * ajax/), puis inclut ce fichier : session compétition, ACL, tables, libs.
 */
if (!defined('HTDOCS')) die('HTDOCS non défini');

require_once(HTDOCS . '/config.php');

CheckTourSession(true);
// Le module lit et écrit des données de compétition (rattachement des épreuves,
// barrages saisis, recalculs). AclQualification/ReadWrite est le niveau des
// écrans de qualification, cohérent avec ce que fait le module.
checkFullACL(AclQualification, '', AclReadWrite);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/donnees.php';
require_once __DIR__ . '/classement.php';
require_once __DIR__ . '/moteur.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/archive.php';
require_once __DIR__ . '/structure.php';
require_once __DIR__ . '/preparation.php';
require_once __DIR__ . '/sessions.php';
require_once __DIR__ . '/selfheal.php';
// Paramètres de module (verrouillage ISK-NG) : chargé par les pages de ianseo
// qui en ont besoin, pas par config.php.
if (!function_exists('getModuleParameter')) {
    require_once($CFG->DOCUMENT_PATH . 'Common/Lib/Fun_Modules.php');
}

selec_schema();

$SELEC_TOUR = intval($_SESSION['TourId']);
$SELEC_ROOT = $CFG->ROOT_DIR . 'Modules/Custom/SELEC/';

/** Version du module (version.json) — sert de paramètre de cache des assets. */
function selec_version()
{
    static $v = null;
    if ($v === null) {
        $j = json_decode((string) @file_get_contents(__DIR__ . '/../version.json'), true);
        $v = (is_array($j) && !empty($j['version'])) ? $j['version'] : '0';
    }
    return $v;
}

/** Jeton anti-CSRF partagé par les points AJAX du module. */
function selec_token()
{
    if (empty($_SESSION['SELEC_TOKEN'])) {
        $_SESSION['SELEC_TOKEN'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['SELEC_TOKEN'];
}

/** Vérifie le jeton d'une requête AJAX ; termine la requête si invalide. */
function selec_check_token()
{
    $t = $_POST['jeton'] ?? $_GET['jeton'] ?? '';
    if (!hash_equals(selec_token(), (string) $t)) {
        JsonOut(array('ok' => false, 'err' => 'Jeton de session invalide — rechargez la page.'));
    }
}
