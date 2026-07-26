<?php
// ── Menu ianseo — module REPARTITION_EPREUVES ────────────────────────────────
// Ce fichier est inclus sur TOUTES les pages ianseo (glob de get_which_menu()) :
// rien d'autre qu'une construction de menu ici, et aucun appel qui puisse fatal.
if (!empty($on) && !empty($_SESSION['TourId'])
    && (subFeatureAcl($acl, AclQualification, '') >= AclReadWrite)) {

    if (!isset($ret['MODS']['REPARTITION_EPREUVES'])) {
        $ret['MODS']['REPARTITION_EPREUVES'][] = 'Répartition des épreuves';
    }
    $_rep_root = $CFG->ROOT_DIR . 'Modules/Custom/REPARTITION_EPREUVES/';
    $ret['MODS']['REPARTITION_EPREUVES'][] = 'Plan des départs|'   . $_rep_root . 'index.php';
    $ret['MODS']['REPARTITION_EPREUVES'][] = 'Classements nationaux|' . $_rep_root . 'classements.php';
    $ret['MODS']['REPARTITION_EPREUVES'][] = 'Correspondances|'     . $_rep_root . 'mapping.php';
    $ret['MODS']['REPARTITION_EPREUVES'][] = 'Ordre des clubs|'     . $_rep_root . 'ordre-clubs.php';
    $ret['MODS']['REPARTITION_EPREUVES'][] = 'Import des arrêtés|' . $_rep_root . 'import-arretes.php';

    // Réservé à l'administrateur. Avec AUTH actif, authCheckACL accorde AclRoot à tout
    // organisateur connecté hors compétition → exiger en plus la vue Administrateur serveur
    // (AUTH_ROOT). Sans AUTH ($_SESSION['AUTH_User'] absent), comportement ianseo classique.
    if (subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
        && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
        $ret['MODS']['REPARTITION_EPREUVES'][] = 'Mise à jour module|' . $_rep_root . 'admin/update.php';
    }
    unset($_rep_root);
}
