<?php
/**
 * Déployé depuis Modules/Custom/AUTH/dist/ — ne pas modifier ici, modifier la
 * copie source dans le module puis redéployer (admin/deploy.php).
 *
 * Inclus par Common/BlockDefines.php quand $CFG->USERAUTH est actif.
 * Implémente l'interface ACL attendue par le cœur ianseo.
 */

require_once(__DIR__ . '/../Custom/AUTH/lib.php');

/**
 * Retourne array($authEnabled, $checkCompAcl).
 * $checkCompAcl=0 : les ACL par IP (AclDetails) ne s'appliquent pas — sur un
 * serveur en ligne, seuls les comptes utilisateurs font foi.
 */
function isAuthEnabled($ToCode = '') {
    if (aut_is_localhost()) return array(0, 1);
    return array(1, 0);
}

function authActualACL($authEnabled, &$acl) {
    if (!$authEnabled) return;
    if (!empty($_SESSION['AUTH_User']) && !empty($_SESSION['AUTH_ENABLE'])) {
        // Vue « depuis un autre compte » (AUTH_RO) : plafond LECTURE SEULE.
        $grant = empty($_SESSION['AUTH_RO']) ? AclReadWrite : AclReadOnly;
        foreach ($acl as $k => $v) $acl[$k] = $grant;
    }
}

/**
 * Grant → niveau (int), refus → false (déclenche noAccess dans checkFullACL).
 * Ne JAMAIS retourner AclNoAccess (0) : checkFullACL le prendrait pour un
 * grant et ne bloquerait pas la page.
 */
function authCheckACL($authEnabled, $checkCompAcl, $feature, $subFeature, $level, $toCode) {
    if (!$authEnabled) return null;
    if (empty($_SESSION['AUTH_User']) || empty($_SESSION['AUTH_ENABLE'])) return false;
    if (!empty($_SESSION['AUTH_ROOT'])) return AclReadWrite;
    // Vue « depuis un autre compte » (AUTH_RO) : tout octroi est plafonné à la
    // LECTURE SEULE → le cœur refuse lui-même toute écriture (défense unique).
    $grant = empty($_SESSION['AUTH_RO']) ? AclReadWrite : AclReadOnly;
    // Opérations serveur (feature AclRoot SANS compétition = mises à jour DB,
    // réglages globaux) : réservées à l'administrateur, jamais accordées à un
    // simple organisateur, même si la page cœur ne le vérifie pas elle-même.
    if (empty($toCode) && in_array(AclRoot, (array)$feature, true)) return false;
    if (empty($toCode)) return $grant;   // pages hors compétition (choix, langue…)
    if (aut_code_allowed($toCode)) return $grant;
    if (stripos(aut_script_rel(), '/Tournament/TournamentImport.php') === 0) {
        // import d'une sauvegarde dont le code appartient à une compétition
        // d'un autre club : expliquer le refus (affiché par menu.php)
        aut_flash_set('Import refusé — le code « ' . htmlspecialchars($toCode)
            . ' » correspond à une compétition existante qui ne vous appartient pas. '
            . 'Renommez le code de votre compétition avant de l\'exporter, ou contactez l\'administrateur.');
    }
    return false;
}

function authHasACL($authEnabled, $feature, $level, $toCode) {
    return authCheckACL($authEnabled, 0, (array)$feature, '', $level, $toCode);
}

function subFeatureAcl($acl, $feature, $subfeature = '') {
    if (array_key_exists($feature, $acl)) {
        return $acl[$feature];
    }
    return AclNoAccess;
}

/**
 * L'utilisateur pourrait-il obtenir $level sur une compétition codée $toCode ?
 * Utilisé par le cœur pour autoriser la CRÉATION et l'IMPORT. Le nommage est
 * libre, mais un code déjà utilisé est refusé (anti-écrasement) — seul le
 * ré-import de sa propre compétition est permis. Voir aut_can_use_code().
 */
function possibleFeature($feature, $level, $toCode = null) {
    if (empty($_SESSION['AUTH_User']) || empty($_SESSION['AUTH_ENABLE'])) return false;
    if (!empty($_SESSION['AUTH_ROOT'])) return true;
    if (!empty($_SESSION['AUTH_RO'])) return false;   // observation lecture seule : ni création ni import
    $role = $_SESSION['AUTH_ROLE'] ?? '';
    if (!in_array($role, array('CLUB', 'CD', 'CR', 'FED'))) return false;
    if (is_null($toCode)) return true;
    $isImport = stripos(aut_script_rel(), '/Tournament/TournamentImport.php') === 0;
    $reason = '';
    $ok = aut_can_use_code($toCode, $role, $_SESSION['AUTH_SCOPE'] ?? '', $_SESSION['AUTH_User'], $isImport, $reason);
    if (!$ok && $isImport && $reason !== '') {
        // le cœur redirige vers l'accueil : le message y sera affiché (menu.php)
        aut_flash_set('Import refusé — ' . $reason);
    }
    return $ok;
}
