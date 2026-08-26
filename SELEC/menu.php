<?php
// ── Menu ianseo — module SELEC ────────────────────────────────────────────────
// Ce fichier est inclus sur TOUTES les pages de ianseo (get_which_menu() fait un
// glob sur Modules/Custom/*/menu.php) : toute erreur fatale ici casse le site
// entier. D'où le try/catch autour de la requête (la table peut ne pas encore
// exister) et le drapeau de cache pour n'interroger la base qu'une fois.
// Déploiement du set « Sélection » dans Modules/Sets/ (écrasé à chaque mise à
// jour de ianseo). Quasi gratuit une fois en place : un drapeau puis un test de
// fichier. Protégé par function_exists : une fatale ici casserait tout le site.
if (is_file(__DIR__ . '/lib/selfheal.php')) {
    require_once __DIR__ . '/lib/selfheal.php';
    if (function_exists('selec_selfheal_leger')) selec_selfheal_leger();
}

if (!empty($on) && !empty($_SESSION['TourId'])
    && (subFeatureAcl($acl, AclQualification, '') >= AclReadOnly)) {

    if (!array_key_exists('_selec_actif', $GLOBALS)) {
        $GLOBALS['_selec_actif'] = false;
        try {
            $_sl_tid = intval($_SESSION['TourId']);
            $_sl_rs  = safe_r_sql("SELECT ScMode FROM SELEC_Config WHERE ScTournament=$_sl_tid LIMIT 1");
            if ($_sl_rs && safe_fetch($_sl_rs)) $GLOBALS['_selec_actif'] = true;
            unset($_sl_tid, $_sl_rs);
        } catch (Exception $e) {
            $GLOBALS['_selec_actif'] = false;
        }
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
