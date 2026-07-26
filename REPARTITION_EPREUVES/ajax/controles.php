<?php
/** ajax/controles.php — recalcul du panneau de contrôles, sans rien écrire. */
define('HTDOCS', dirname(__DIR__, 4));
require_once dirname(__DIR__) . '/lib/boot.php';
rep_check_token();

$ctrl = rep_controles($REP_TOUR);
$ctrl['ok'] = true;
JsonOut($ctrl);
