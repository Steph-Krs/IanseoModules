<?php
/**
 * Fermeture rapide, appelée par la barre flottante présente sur toutes les pages de
 * ianseo. Un aller-retour, puis retour à la page d'origine.
 *
 * Ferme, pour chaque épreuve, la PROCHAINE phase non terminée dont l'horaire prévu
 * (FinSchedule, natif ianseo) est passé ou tombe dans l'heure — jamais une bascule
 * globale : la grille de la console permet de rouvrir une cellule précise si besoin.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclQualification, '', AclReadWrite);

require_once dirname(__DIR__) . '/lib/engine.php';

$tid   = prono_active_tournament() ?: intval($_SESSION['TourId']);
$count = 0;

if ($tid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $count = count(prono_quickclose($tid));
    prono_poll($tid, true);
}

// Retour d'où l'on vient, en restant sur le site : la barre est utilisable depuis
// n'importe quelle page et ne doit pas faire perdre le fil.
// Chemin local uniquement : un « // » ou un « : » ouvrirait une redirection vers
// l'extérieur depuis une page authentifiée.
$back = (string) ($_POST['back'] ?? '');
if ($back === '' || $back[0] !== '/' || strpos($back, '//') === 0
    || !preg_match('#^/[A-Za-z0-9_\-/.?=&%]*$#', $back)) {
    $back = $CFG->ROOT_DIR . 'Modules/Custom/PRONO/index.php';
}
// Petit retour visuel sur la barre (nombre de phases fermées), sans session ni état
// serveur supplémentaire : un paramètre d'URL, lu une fois par menu.php puis nettoyé
// côté client (history.replaceState), pour ne pas polluer un lien qu'on recharge.
$sep  = strpos($back, '?') === false ? '?' : '&';
$back .= $sep . 'prono_closed=' . $count;
cd_redirect($back);
