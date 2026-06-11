<?php

if (!empty($on) && (subFeatureAcl($acl, AclQualification, '') > AclReadOnly )) {
	if (!isset($ret['MODS']['TNM'])) {
        $ret['MODS']['TNM'][] = 'Trophée National des Mixtes';
    }
	$ret['MODS']['TNM'][] = 'Impressions' .'|'.$CFG->ROOT_DIR.'Modules/Custom/TNM/';
    $ret['MODS']['TNM'][] = 'Configuration' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TNM/config.php';
}

?>