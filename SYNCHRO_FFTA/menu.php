<?php
// Dépôt des résultats TXT : depuis une compétition ouverte (menu Compétition › Exports)
if (!empty($on) && subFeatureAcl($acl, AclCompetition, 'cExport') >= AclReadOnly) {
    $ret['COMP']['EXPT'][] = MENU_DIVIDER;
    $ret['COMP']['EXPT'][] = 'Dépôt résultats extranet FFTA|'
        . $CFG->ROOT_DIR . 'Modules/Custom/SYNCHRO_FFTA/index.php';
}

// Création depuis l'extranet : hors compétition, visible partout où « Nouveau » l'est
// (localhost inclus). On ne masque que si AUTH est actif ET l'utilisateur n'a pas le droit.
if (empty($on)) {
    $sfaAuthOn = !empty($CFG->USERAUTH) && !empty($_SESSION['AUTH_ENABLE']);
    $sfaCanCreate = !$sfaAuthOn || !empty($_SESSION['AUTH_ROOT'])
        || possibleFeature(AclRoot, AclReadWrite)
        || (isset($acl) && subFeatureAcl($acl, AclRoot) == AclReadWrite);

    if ($sfaCanCreate) {
        $sfaEntry = 'Créer une compétition depuis l\'extranet FFTA|'
            . $CFG->ROOT_DIR . 'Modules/Custom/SYNCHRO_FFTA/create.php';
        $sfaNewUrl = $CFG->ROOT_DIR . 'Tournament/index.php?New=';

        // Insertion juste AVANT l'entrée « Nouveau », en préservant les clés du menu.
        $sfaNew = [];
        $sfaDone = false;
        foreach (($ret['COMP'] ?? []) as $k => $v) {
            if (!$sfaDone && is_string($v) && strpos($v, $sfaNewUrl) !== false) {
                $sfaNew[] = $sfaEntry;
                $sfaDone = true;
            }
            if (is_int($k)) {
                $sfaNew[] = $v;
            } else {
                $sfaNew[$k] = $v;
            }
        }
        if (!$sfaDone) {
            $sfaNew[] = $sfaEntry;
        }
        $ret['COMP'] = $sfaNew;
    }
}

// Administration du module : entrée « Modules », réservée à l'administrateur.
// Sans elle, la page de MaJ n'est atteignable que par URL directe.
// function_exists() : ce fichier est inclus sur TOUTES les pages, une erreur
// fatale ici casserait tout le site.
$sfaAuthOn  = !empty($CFG->USERAUTH) && !empty($_SESSION['AUTH_ENABLE']);
$sfaIsAdmin = (isset($acl) && subFeatureAcl($acl, AclRoot, '') >= AclReadWrite)
              || (function_exists('possibleFeature') && possibleFeature(AclRoot, AclReadWrite));
if ($sfaAuthOn) {
    $sfaIsAdmin = $sfaIsAdmin && !empty($_SESSION['AUTH_ROOT']);
}
if ($sfaIsAdmin) {
    if (!isset($ret['MODS']['SFA'])) {
        $ret['MODS']['SFA'][] = 'Synchro FFTA';
    }
    $ret['MODS']['SFA'][] = 'Mise à jour module|'
        . $CFG->ROOT_DIR . 'Modules/Custom/SYNCHRO_FFTA/admin/update.php';
}
unset($sfaAuthOn, $sfaIsAdmin);

// Synchro licenciés depuis l'Espace Dirigeant : injection sur LookupTableLoad.php.
// Le fichier inclus s'auto-garde (ne fait rien hors de cette page).
require_once(__DIR__ . '/licences-inject.php');
