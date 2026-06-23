<?php
// PdfFop.php — Plan de cible TNM
// Même format qu'ianseo, mais les 3 rencontres d'un même tour/épreuve sont
// fusionnées en une seule ligne : heure de début de la 1ère, heure de fin
// de la dernière, affectation de cibles identique (inchangée par ianseo).
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);

// Fun_Scheduler.php utilise des chemins relatifs depuis htdocs
chdir(HTDOCS);
require_once('Common/Lib/Fun_Scheduler.php');
require_once(__DIR__ . '/tnm-scheduler.inc.php');

$Schedule = new TNM_Scheduler();
$Schedule->FOP(); // FOP() active FopMergeRounds avant d'appeler parent::FOP()
