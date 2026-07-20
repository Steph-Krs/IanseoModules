<?php
// ── Menu ianseo — module POULES ───────────────────────────────────────────────
// Entrée visible seulement si la compétition courante contient des poules
// Round Robin par équipes.
if (!empty($on) && (subFeatureAcl($acl, AclRobin, '') >= AclReadOnly) && !empty($_SESSION['TourId'])) {
    if (!array_key_exists('_poules_has_rr', $GLOBALS)) {
        $GLOBALS['_poules_has_rr'] = false;
        $_pl_tid = intval($_SESSION['TourId']);
        $_pl_rs  = safe_r_sql("SELECT 1 FROM RoundRobinLevel
            WHERE RrLevTournament=$_pl_tid AND RrLevTeam=1 LIMIT 1");
        if ($_pl_rs && safe_fetch($_pl_rs)) $GLOBALS['_poules_has_rr'] = true;
        unset($_pl_tid, $_pl_rs);
    }
    if ($GLOBALS['_poules_has_rr']) {
        if (!isset($ret['MODS']['POULES']))
            $ret['MODS']['POULES'][] = 'Poules — Enjeux & classement live';
        $ret['MODS']['POULES'][] = 'Vue commentateur' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/POULES/index.php';
        // Réservé à l'administrateur. Avec AUTH actif, authCheckACL accorde AclRoot à tout
        // organisateur connecté hors compétition → exiger en plus la vue Administrateur serveur
        // (AUTH_ROOT). Sans AUTH ($_SESSION['AUTH_User'] absent), comportement ianseo classique.
        if (subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
            && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
            $ret['MODS']['POULES'][] = 'Mise à jour module' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/POULES/admin/update.php';
        }
    }
}
