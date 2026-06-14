<?php

if (!empty($on) && (subFeatureAcl($acl, AclQualification, '') > AclReadOnly )) {
	if (!isset($ret['MODS']['TNM'])) {
        $ret['MODS']['TNM'][] = 'Trophée National des Mixtes';
    }
    $ret['MODS']['TNM'][] = 'Configuration' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/config.php';
	$ret['MODS']['TNM'][] = 'Impr. feuilles de marques' .'|'.$CFG->ROOT_DIR.'Modules/RoundRobin/PrintScore.php?team=1';
    $ret['MODS']['TNM'][] = 'Scan feuilles de marques' . '|' . $CFG->ROOT_DIR . 'Modules/Barcodes/GetRobinScoreBarCode.php';
    $ret['MODS']['TNM'][] = 'Validation tour' . '|' . $CFG->ROOT_DIR . 'Modules/RoundRobin/AbsRobin.php?team=1';
	$ret['MODS']['TNM'][] = 'Assistant poules T2' .'|'.$CFG->ROOT_DIR.'Modules/Custom/TNM/PoolsAssist.php';
	$ret['MODS']['TNM'][] = 'Impressions poules & clsmts' .'|'.$CFG->ROOT_DIR.'Modules/Custom/TNM/';
    $ret['MODS']['TNM'][] = 'BSO - validation équipes + pgm' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/config.php';
	$ret['MODS']['TNM'][] = 'BSO - Saisie' .'|'.$CFG->ROOT_DIR.'Modules/Custom/TNM/BsoSaisie.php';
	$ret['MODS']['TNM'][] = 'BSO - Commentateur' .'|'.$CFG->ROOT_DIR.'Modules/Custom/TNM/BsoCommentateur.php';
}

?>