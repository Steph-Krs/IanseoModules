<?php
/**
 * lib/boot.php — amorçage commun des pages et des points AJAX du module.
 *
 * L'appelant définit HTDOCS (la profondeur diffère entre une page racine et
 * ajax/), puis inclut ce fichier. Ensuite : session compétition, ACL, tables.
 */
if (!defined('HTDOCS')) die('HTDOCS non défini');

require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclQualification, '', AclReadWrite);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/mapping.php';
require_once __DIR__ . '/ffta.php';
require_once __DIR__ . '/moteur.php';
require_once __DIR__ . '/controles.php';
require_once __DIR__ . '/ecriture.php';
require_once __DIR__ . '/arretes.php';
require_once __DIR__ . '/arretes_ecriture.php';

rep_schema();

$REP_TOUR = intval($_SESSION['TourId']);
$REP_ROOT = $CFG->ROOT_DIR . 'Modules/Custom/REPARTITION_EPREUVES/';

/**
 * Version du module (version.json), pour casser le cache navigateur des
 * assets statiques (`assets/*.js?v=…`, `assets/*.css?v=…`) — sans ça, un
 * navigateur peut servir une version JS périmée après une mise à jour du
 * module (aucune des pages n'avait de paramètre de cache jusqu'ici),
 * provoquant des symptômes trompeurs (boutons qui semblent exiger deux clics,
 * alors que le serveur est déjà à jour). Lue une seule fois par requête.
 */
function rep_version()
{
    static $v = null;
    if ($v === null) {
        $j = json_decode((string) @file_get_contents(__DIR__ . '/../version.json'), true);
        $v = (is_array($j) && !empty($j['version'])) ? $j['version'] : '0';
    }
    return $v;
}

/** Jeton anti-CSRF partagé par les points AJAX du module. */
function rep_token()
{
    if (empty($_SESSION['REP_TOKEN'])) {
        $_SESSION['REP_TOKEN'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['REP_TOKEN'];
}

/** Vérifie le jeton d'une requête AJAX ; termine la requête si invalide. */
function rep_check_token()
{
    $t = $_POST['jeton'] ?? $_GET['jeton'] ?? '';
    if (!hash_equals(rep_token(), (string) $t)) {
        JsonOut(['ok' => false, 'err' => 'Jeton de session invalide — rechargez la page.']);
    }
}
