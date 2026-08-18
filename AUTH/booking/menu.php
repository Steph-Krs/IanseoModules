<?php
/**
 * menu.php — entrées de menu du module BOOKING.
 *
 * ATTENTION : ce fichier est inclus par get_which_menu() sur TOUTES les pages
 * de ianseo. Une erreur fatale ici casse le site entier — d'où les gardes
 * function_exists() sur tout appel de fonction optionnelle.
 *
 * Tout est regroupé sous « Modules › Inscriptions en ligne » : les écrans du
 * module restent ensemble plutôt que d'être dispersés dans les menus du cœur.
 *
 * L'espace licencié (public/) n'apparaît pas ici : il s'adresse aux archers,
 * pas aux organisateurs. Son adresse est rappelée sur la page d'ouverture des
 * inscriptions.
 */

$bkEntries = array();

// Écrans liés à la compétition ouverte : accessibles à l'organisateur qui gère
// les participants, pas seulement à l'administrateur du serveur.
if (!empty($on) && isset($acl)) {
    if (subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadWrite) {
        $bkEntries[] = 'Boutique|'
            . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/shop.php';
        $bkEntries[] = 'Sommes dues|'
            . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/dues.php';
        $bkEntries[] = 'Mandat de compétition|'
            . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/mandate.php';
    }
    if (subFeatureAcl($acl, AclParticipants, 'pTarget') >= AclReadWrite) {
        $bkEntries[] = 'Plan du terrain|'
            . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/field.php';
        $bkEntries[] = 'Attribution des cibles|'
            . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/targets.php';
    }
}

if ($bkEntries) {
    // Titre de section CLIQUABLE (getSubMenuItem gère « Titre|URL ») → accès direct
    // à la page de configuration des inscriptions.
    $ret['MODS']['BOOKING'][] = 'Inscriptions en ligne|'
        . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php';
    foreach ($bkEntries as $bkE) {
        $ret['MODS']['BOOKING'][] = $bkE;
    }
}

unset($bkEntries, $bkE);
