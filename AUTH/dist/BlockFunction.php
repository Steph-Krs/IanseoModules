<?php
/**
 * Deployed from Modules/Custom/AUTH/dist/ — do not edit here, edit the source copy in the
 * module then redeploy (admin/deploy.php).
 *
 * Included by Common/BlockDefines.php when $CFG->USERAUTH is on.
 * Implements the ACL interface expected by the ianseo core by forwarding to the module (lib.php,
 * aut_acl_*): the rules change with an update of the module, without deploying this file again.
 */

require_once(__DIR__ . '/../Custom/AUTH/lib.php');

// This file forwards every decision to the module: the volunteers' access to ianseo's screens
// (vol-lib.php) relies on it and stays closed without it.
define('AUT_ACL_HOOKS', 1);

function isAuthEnabled($ToCode = '') {
    return aut_acl_enabled($ToCode);
}

function authActualACL($authEnabled, &$acl) {
    aut_acl_actual($authEnabled, $acl);
}

/**
 * Grant → level (int), refusal → false (triggers noAccess in checkFullACL).
 * NEVER return AclNoAccess (0) for a refusal: checkFullACL would take it for a grant.
 */
function authCheckACL($authEnabled, $checkCompAcl, $feature, $subFeature, $level, $toCode) {
    return aut_acl_check($authEnabled, $checkCompAcl, $feature, $subFeature, $level, $toCode);
}

function authHasACL($authEnabled, $feature, $level, $toCode) {
    return aut_acl_check($authEnabled, 0, (array)$feature, '', $level, $toCode);
}

function subFeatureAcl($acl, $feature, $subfeature = '') {
    return aut_acl_sub($acl, $feature, $subfeature);
}

/** Could the user get $level on a competition coded $toCode? (creation and import) */
function possibleFeature($feature, $level, $toCode = null) {
    return aut_acl_possible($feature, $level, $toCode);
}
