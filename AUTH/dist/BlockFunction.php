<?php
/**
 * Deployed from Modules/Custom/AUTH/dist/ — do not edit here, edit the source copy in the
 * module then redeploy (admin/deploy.php).
 *
 * Included by Common/BlockDefines.php when $CFG->USERAUTH is on.
 * Implements the ACL interface expected by the ianseo core.
 */

require_once(__DIR__ . '/../Custom/AUTH/lib.php');

/**
 * Returns array($authEnabled, $checkCompAcl).
 * $checkCompAcl=0: the ACLs per IP (AclDetails) do not apply — on an online server, only the
 * user accounts count.
 */
function isAuthEnabled($ToCode = '') {
    if (aut_is_localhost()) return array(0, 1);
    return array(1, 0);
}

function authActualACL($authEnabled, &$acl) {
    if (!$authEnabled) return;
    if (!empty($_SESSION['AUTH_User']) && !empty($_SESSION['AUTH_ENABLE'])) {
        // "From another account" view (AUTH_RO): READ-ONLY ceiling.
        $grant = empty($_SESSION['AUTH_RO']) ? AclReadWrite : AclReadOnly;
        foreach ($acl as $k => $v) $acl[$k] = $grant;
    }
}

/**
 * Grant → level (int), refusal → false (triggers noAccess in checkFullACL).
 * NEVER return AclNoAccess (0): checkFullACL would take it for a grant and would not block
 * the page.
 */
function authCheckACL($authEnabled, $checkCompAcl, $feature, $subFeature, $level, $toCode) {
    if (!$authEnabled) return null;
    if (empty($_SESSION['AUTH_User']) || empty($_SESSION['AUTH_ENABLE'])) return false;
    if (!empty($_SESSION['AUTH_ROOT'])) return AclReadWrite;
    // "From another account" view (AUTH_RO): every grant is capped at READ ONLY → the core
    // itself refuses any write (single defence).
    $grant = empty($_SESSION['AUTH_RO']) ? AclReadWrite : AclReadOnly;
    // Server operations (AclRoot feature WITHOUT a competition = database updates, global
    // settings): reserved to the administrator, never granted to a mere organiser, even when
    // the core page does not check it itself.
    if (empty($toCode) && in_array(AclRoot, (array)$feature, true)) return false;
    if (empty($toCode)) return $grant;   // pages outside a competition (choice, language…)
    if (aut_code_allowed($toCode)) return $grant;
    if (stripos(aut_script_rel(), '/Tournament/TournamentImport.php') === 0) {
        // import of a backup whose code belongs to a competition of another club: explain the
        // refusal (shown by menu.php)
        aut_flash_set(aut_t('ImportRefusedCode', htmlspecialchars($toCode)));
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
 * Could the user get $level on a competition coded $toCode?
 * Used by the core to allow the CREATION and the IMPORT. Naming is free, but a code already
 * used is refused (anti-overwrite) — only re-importing one's own competition is allowed. See
 * aut_can_use_code().
 */
function possibleFeature($feature, $level, $toCode = null) {
    if (empty($_SESSION['AUTH_User']) || empty($_SESSION['AUTH_ENABLE'])) return false;
    if (!empty($_SESSION['AUTH_ROOT'])) return true;
    if (!empty($_SESSION['AUTH_RO'])) return false;   // read-only observation: no creation, no import
    $role = $_SESSION['AUTH_ROLE'] ?? '';
    if (!in_array($role, array('CLUB', 'CD', 'CR', 'FED'))) return false;
    if (is_null($toCode)) return true;
    $isImport = stripos(aut_script_rel(), '/Tournament/TournamentImport.php') === 0;
    $reason = '';
    $ok = aut_can_use_code($toCode, $role, $_SESSION['AUTH_SCOPE'] ?? '', $_SESSION['AUTH_User'], $isImport, $reason);
    if (!$ok && $isImport && $reason !== '') {
        // the core redirects to the home page: the message is shown there (menu.php)
        aut_flash_set(aut_t('ImportRefused', $reason));
    }
    return $ok;
}
