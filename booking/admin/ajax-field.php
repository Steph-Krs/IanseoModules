<?php
/**
 * admin/ajax-field.php — enregistrement des capacités du terrain.
 *
 * Mêmes gardes que la page : compétition ouverte, droit sur les cibles, jeton
 * anti-CSRF. Un point AJAX n'est pas moins exposé qu'une page.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pTarget', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/caps.php';
require_once dirname(__DIR__) . '/lib/archer.php';

bk_schema();

if (!bk_csrf_check()) JsonOut(array('ok' => false, 'err' => 'Jeton invalide — rechargez la page.'));

$TOUR = intval($_SESSION['TourId']);
$act  = (string) ($_POST['action'] ?? '');
$ses  = intval($_POST['session'] ?? 0);

// Le départ doit exister sur CETTE compétition (jamais un numéro arbitraire).
$valid = false;
$capacity = array();
foreach (bk_comp_sessions($TOUR) as $s) {
    $o = intval($s->SesOrder);
    if ($o === $ses) $valid = true;
    $first = intval($s->SesFirstTarget) ?: 1;
    $capacity[$o] = array($first, $first + intval($s->SesTar4Session) - 1);
}
if (!$valid) JsonOut(array('ok' => false, 'err' => 'Départ inconnu.'));

if ($act === 'set') {
    $targets = array_map('intval', (array) ($_POST['targets'] ?? array()));
    $f = array_map('intval', (array) ($_POST['f'] ?? array()));

    // Les blasons doivent exister sur la compétition — jamais ce que le
    // navigateur envoie. Les distances sont des entiers bornés (une plage n'est
    // pas limitée aux distances déclarées : une cible peut se régler au-delà).
    $okF = array_keys(bk_caps_faces($TOUR));
    $f = array_values(array_intersect($f, $okF));

    $borne = function ($v) { return max(0, min(500, intval($v))); };
    $def = $borne($_POST['def'] ?? 0);
    $dmin = $borne($_POST['min'] ?? 0);
    $dmax = $borne($_POST['max'] ?? 0);

    list($tmin, $tmax) = $capacity[$ses];
    $n = 0;
    foreach ($targets as $t) {
        if ($t < $tmin || $t > $tmax) continue;
        bk_caps_set($TOUR, $ses, $t, $def, $dmin, $dmax, $f);
        $n++;
    }
    JsonOut(array('ok' => true, 'n' => $n, 'caps' => (object) bk_caps_get($TOUR, $ses)));
}

if ($act === 'clear') {
    bk_caps_clear($TOUR, $ses);
    JsonOut(array('ok' => true, 'caps' => (object) array()));
}

if ($act === 'copy') {
    $to = intval($_POST['to'] ?? 0);
    if (!isset($capacity[$to]) || $to === $ses) {
        JsonOut(array('ok' => false, 'err' => 'Départ de destination invalide.'));
    }
    bk_caps_copy($TOUR, $ses, $to);
    JsonOut(array('ok' => true, 'msg' => "Capacités copiées vers le départ $to."));
}

if ($act === 'copyfrom') {
    // Reprend la configuration d'un départ SOURCE sur le départ COURANT ($ses).
    $from = intval($_POST['from'] ?? 0);
    if (!isset($capacity[$from]) || $from === $ses) {
        JsonOut(array('ok' => false, 'err' => 'Départ source invalide.'));
    }
    bk_caps_copy($TOUR, $from, $ses);
    // caps du départ courant renvoyées → la grille se rafraîchit tout de suite
    JsonOut(array('ok' => true, 'msg' => "Configuration du départ $from reprise sur ce départ.",
        'caps' => (object) bk_caps_get($TOUR, $ses)));
}

JsonOut(array('ok' => false, 'err' => 'Action inconnue.'));
