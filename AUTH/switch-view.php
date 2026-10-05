<?php
/**
 * AUTH module — view switch (club / CD / CR / Federation / Admin).
 * Changes the effective role of the current SESSION (AuthSessions.AsnRole/Scope) among the
 * views the account is entitled to, remembers the last view for the next sign-in, then goes
 * back to the home page.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/lib.php');

if (empty($_SESSION['AUTH_User'])) {
    CD_redirect($CFG->ROOT_DIR);
    die();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && aut_csrf_check()) {
    $u = aut_get_user($_SESSION['AUTH_User']);
    $views = $u ? aut_user_views($u) : array();
    $i = intval($_POST['view'] ?? -1);
    if ($u && isset($views[$i])) {
        $v = $views[$i];
        $h = aut_current_token_hash();
        if ($h) {
            safe_w_sql("UPDATE AuthSessions SET AsnRole=" . StrSafe_DB($v['role'])
                . ", AsnScope=" . StrSafe_DB($v['scope']) . " WHERE AsnTokenHash='$h'");
        }
        safe_w_sql("UPDATE AuthUsers SET AuLastRole=" . StrSafe_DB($v['role'])
            . ", AuLastScope=" . StrSafe_DB($v['scope']) . " WHERE AuId={$u->AuId}");
        aut_log('VIEW_SWITCH', $u->AuUsername . ' ' . aut_owner_label($v['role'], $v['scope']));

        // open competition not reachable in the new view → it is closed
        if ($v['role'] != AUT_ROLE_ADMIN && !empty($_SESSION['TourCode'])) {
            $comp = aut_compute_comp($v['role'], $v['scope']);
            if (!aut_code_allowed($_SESSION['TourCode'], $comp)) {
                EraseTourSession();
            }
        }
    }
}

CD_redirect($CFG->ROOT_DIR);
die();
