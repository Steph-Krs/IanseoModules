<?php
/**
 * Module AUTH — bascule de vue (club / CD / CR / Fédération / Admin).
 * Change le rôle effectif de la SESSION courante (AUT_Sessions.AsnRole/Scope)
 * parmi les vues auxquelles le compte a droit, mémorise la dernière vue pour
 * la prochaine connexion, puis renvoie à l'accueil.
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
            safe_w_sql("UPDATE AUT_Sessions SET AsnRole=" . StrSafe_DB($v['role'])
                . ", AsnScope=" . StrSafe_DB($v['scope']) . " WHERE AsnTokenHash='$h'");
        }
        safe_w_sql("UPDATE AUT_Users SET AuLastRole=" . StrSafe_DB($v['role'])
            . ", AuLastScope=" . StrSafe_DB($v['scope']) . " WHERE AuId={$u->AuId}");
        aut_log('VIEW_SWITCH', $u->AuUsername . ' ' . aut_owner_label($v['role'], $v['scope']));

        // compétition ouverte inaccessible dans la nouvelle vue → on la ferme
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
