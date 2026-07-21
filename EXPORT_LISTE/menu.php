<?php
// ── Menu ianseo — module EXPORT_LISTE ─────────────────────────────────────────
// Entrée visible seulement si une compétition est ouverte et que l'utilisateur a
// l'accès en lecture aux participants.
if (!empty($on) && !empty($_SESSION['TourId'])
    && subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadOnly) {

    if (!isset($ret['MODS']['EXPORTLISTE']))
        $ret['MODS']['EXPORTLISTE'][] = 'Export liste';
    $ret['MODS']['EXPORTLISTE'][] = 'Export participants (format import)'
        . '|' . $CFG->ROOT_DIR . 'Modules/Custom/EXPORT_LISTE/index.php';

    // Réservé à l'administrateur. Avec AUTH actif, authCheckACL accorde AclRoot à tout
    // organisateur connecté hors compétition → exiger en plus la vue Administrateur
    // serveur (AUTH_ROOT). Sans AUTH ($_SESSION['AUTH_User'] absent), comportement
    // ianseo classique.
    if (subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
        && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
        $ret['MODS']['EXPORTLISTE'][] = 'Mise à jour module'
            . '|' . $CFG->ROOT_DIR . 'Modules/Custom/EXPORT_LISTE/admin/update.php';
    }
}
