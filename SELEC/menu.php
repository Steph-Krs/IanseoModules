<?php
// ── Menu ianseo — module SELEC ────────────────────────────────────────────────
// Ce fichier est inclus sur TOUTES les pages de ianseo (get_which_menu() fait un
// glob sur Modules/Custom/*/menu.php) : toute erreur fatale ici casse le site
// entier. D'où le try/catch autour de la requête (la table peut ne pas encore
// exister) et le drapeau de cache pour n'interroger la base qu'une fois.
//
// ⚠ ON NE FAIT RIEN D'AUTRE ICI QUE REMPLIR $ret.
// Le déploiement du set « Sélection » y a vécu un temps : écriture de fichiers
// dans Modules/Sets/ et Common/Languages/, plus une insertion en base, à chaque
// page de ianseo. Sur un serveur de production, cela rendait le site
// inaccessible (403). Un menu doit lire, jamais écrire — surtout depuis un
// fichier chargé partout. Le déploiement se fait désormais depuis lib/boot.php,
// c'est-à-dire uniquement quand on ouvre une page du module.

if (!empty($on) && !empty($_SESSION['TourId'])
    && (subFeatureAcl($acl, AclQualification, '') >= AclReadOnly)) {

    if (!array_key_exists('_selec_actif', $GLOBALS)) {
        $GLOBALS['_selec_actif'] = false;

        // ⚠ Le 3e argument ($force) n'est PAS décoratif.
        // Sans lui, une requête en erreur fait appeler safe_error() par ianseo,
        // qui envoie « HTTP/1.0 404 Not Found » puis exit (Fun_DB.inc.php:264).
        // Ni try/catch ni test du retour n'y peuvent quoi que ce soit : la page
        // est terminée. Or les tables du module ne sont créées qu'à l'ouverture
        // d'une de ses pages — sur une installation neuve, SELEC_Config n'existe
        // pas, et ce SELECT rendait TOUT ianseo inaccessible dès qu'une
        // compétition était ouverte. Avec $force, safe_r_sql retourne false.
        $_sl_tid = intval($_SESSION['TourId']);
        $_sl_rs  = safe_r_sql("SELECT ScMode FROM SELEC_Config
            WHERE ScTournament=$_sl_tid LIMIT 1", false, true);
        if ($_sl_rs && safe_fetch($_sl_rs)) $GLOBALS['_selec_actif'] = true;
        unset($_sl_tid, $_sl_rs);
    }

    // Réservé à l'administrateur. Avec AUTH actif, authCheckACL accorde AclRoot à
    // tout organisateur connecté hors compétition → exiger en plus la vue
    // Administrateur serveur (AUTH_ROOT). Sans AUTH, comportement ianseo classique.
    $_sl_admin = (subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
        && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT'])));

    // La compétition n'est rattachée à aucun mode : seul un administrateur voit
    // l'entrée, pour ne pas polluer le menu de toutes les compétitions du serveur.
    if ($GLOBALS['_selec_actif'] || $_sl_admin) {
        if (!isset($ret['MODS']['SELEC'])) $ret['MODS']['SELEC'][] = 'Sélection Équipe de France';

        $ret['MODS']['SELEC'][] = 'Configuration de la sélection|'
            . $CFG->ROOT_DIR . 'Modules/Custom/SELEC/index.php';

        if ($GLOBALS['_selec_actif']) {
            $ret['MODS']['SELEC'][] = 'Classements et traçabilité|'
                . $CFG->ROOT_DIR . 'Modules/Custom/SELEC/classement.php';
            $ret['MODS']['SELEC'][] = 'Transfert entre serveurs|'
                . $CFG->ROOT_DIR . 'Modules/Custom/SELEC/transfert.php';
        }

        if ($_sl_admin) {
            $ret['MODS']['SELEC'][] = 'Mise à jour module|'
                . $CFG->ROOT_DIR . 'Modules/Custom/SELEC/admin/update.php';
        }
    }
    unset($_sl_admin);
}
