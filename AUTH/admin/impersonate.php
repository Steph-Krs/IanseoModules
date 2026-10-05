<?php
/**
 * admin/impersonate.php — "From another account" view (impersonation).
 *
 * Server ADMIN only, READ ONLY. Opens/closes the observation of an ORGANISER account
 * (redirects to the list of competitions as the target sees it) or of a LICENSEE's space
 * (redirects to their booking space).
 *
 * The state is kept per session in the database (AuthSessions.AsnImp) to survive
 * CreateTourSession — see aut_imp_* in lib.php. The organiser read-only mode is enforced by
 * the core (AUTH_RO → dist/BlockFunction.php); on the licensee side, by public/boot.php (every
 * POST refused). Logged (IMPERSONATE_START/END).
 *
 * Pure controller (no HTML output): POST + CSRF to ENTER, GET to LEAVE.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/../lib.php');

// ---- LEAVING an observation -------------------------------------------------
// Do NOT require AclRoot: during an organiser observation, AUTH_RO precisely caps AclRoot.
// Only require that the observation was opened by THIS user (shared session), with a
// best-effort CSRF token.
if (isset($_GET['exit'])) {
    $i  = aut_imp_get();
    $me = (string) ($_SESSION['AUTH_User'] ?? '');
    if ($i && $me !== '' && (string) ($i['by'] ?? '') === $me) {
        $tok = (string) ($_GET['aut_csrf'] ?? '');
        if ($tok === '' || hash_equals((string) ($_SESSION['AUT_CSRF'] ?? ''), $tok)) {
            aut_log('IMPERSONATE_END', $me . ' -> ' . ($i['type'] ?? '') . ':' . ($i['label'] ?? ''));
            aut_imp_forget();
        }
    }
    CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/');
    exit;
}

// ---- ENTERING an observation: server administrator only -----------------
// Guard consistent with the other admin pages of the module (AclRoot + admin view).
checkFullACL(AclRoot, '', AclReadWrite);
if (empty($_SESSION['AUTH_ROOT'])) { CD_redirect($CFG->ROOT_DIR . 'noAccess.php'); exit; }

$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && aut_csrf_check()) {
    aut_imp_forget();                       // start from a clean observation
    $me   = (string) $_SESSION['AUTH_User'];
    $type = (string) ($_POST['type'] ?? '');

    if ($type === 'org') {
        // The organiser read-only mode is enforced by the DEPLOYED BlockFunction (AUTH_RO).
        // While the deployed version ignores it, observing would give WRITE access to the
        // target's competitions → refused first.
        $deployed = HTDOCS . '/Modules/Authentication/BlockFunction.php';
        $roReady  = is_file($deployed) && strpos((string) @file_get_contents($deployed), 'AUTH_RO') !== false;

        $user = (string) ($_POST['user'] ?? '');
        $t = aut_get_user($user);
        if (!$roReady)                        $err = aut_t('ImpRedeploy');
        elseif (!$t)                          $err = aut_t('UsNotFound');
        elseif ($t->AuRole == AUT_ROLE_ADMIN) $err = aut_t('ImpNoAdmin');
        elseif ($t->AuUsername === $me)       $err = aut_t('ImpSelf');
        else {
            $label = $t->AuUsername . ' (' . (aut_roles()[$t->AuRole] ?? $t->AuRole)
                   . ($t->AuScope !== '' ? ' ' . $t->AuScope : '') . ')';
            aut_imp_store(array('type' => 'org', 'user' => $t->AuUsername,
                'label' => $label, 'by' => $me, 'at' => time()));
            aut_log('IMPERSONATE_START', $me . ' -> org:' . $t->AuUsername);
            CD_redirect($CFG->ROOT_DIR . 'index.php');   // competitions as the target sees them
            exit;
        }
    } elseif ($type === 'archer') {
        // bytes: licence numbers and club codes are ASCII letters and digits
        $lic = strtoupper(trim((string) ($_POST['licence'] ?? '')));
        $a = null;
        if ($lic !== '') {
            $q = safe_r_sql("SELECT BaId, BaLicence, BaName, BaFamilyName
                FROM BookingArchers WHERE BaLicence=" . StrSafe_DB($lic), false, true);
            $a = $q ? safe_fetch($q) : null;
        }
        if (!$a) $err = aut_t('ImpNoArcher');
        else {
            $label = trim($a->BaName . ' ' . $a->BaFamilyName) . ' — ' . $a->BaLicence;
            aut_imp_store(array('type' => 'archer', 'archer' => intval($a->BaId),
                'label' => $label, 'by' => $me, 'at' => time()));
            aut_log('IMPERSONATE_START', $me . ' -> archer:' . $a->BaLicence);
            CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/index.php');
            exit;
        }
    } else {
        $err = aut_t('ImpBadType');
    }
}

// Failure (or direct access): back to the accounts page with the reason in a banner.
if ($err !== '') aut_flash_set(htmlspecialchars(aut_t('ImpFailed', $err)));
CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/');
exit;
