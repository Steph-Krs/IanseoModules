<?php
/**
 * admin/impersonate.php — Vue « depuis un autre compte » (impersonation).
 *
 * ADMIN serveur uniquement, LECTURE SEULE. Ouvre/ferme l'observation d'un compte
 * ORGANISATEUR (redirige vers la liste des compétitions vue par la cible) ou de
 * l'espace d'un LICENCIÉ (redirige vers son espace booking).
 *
 * L'état est persisté par session en base (AUT_Sessions.AsnImp) pour survivre à
 * CreateTourSession — voir aut_imp_* dans lib.php. La lecture seule organisateur
 * est imposée par le cœur (AUTH_RO → dist/BlockFunction.php) ; côté licencié, par
 * public/boot.php (refus de tout POST). Journalisé (IMPERSONATE_START/END).
 *
 * Contrôleur pur (aucune sortie HTML) : POST + CSRF pour ENTRER, GET pour SORTIR.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/../lib.php');

// ---- SORTIE d'observation ------------------------------------------------
// Ne PAS exiger AclRoot : pendant une observation organisateur, AUTH_RO plafonne
// justement AclRoot. On exige seulement que l'observation ait été ouverte par CET
// utilisateur (session partagée), avec un jeton CSRF best-effort.
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

// ---- ENTRÉE en observation : administrateur serveur uniquement -----------
// Garde cohérente avec les autres pages admin du module (AclRoot + vue admin).
checkFullACL(AclRoot, '', AclReadWrite);
if (empty($_SESSION['AUTH_ROOT'])) { CD_redirect($CFG->ROOT_DIR . 'noAccess.php'); exit; }

$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && aut_csrf_check()) {
    aut_imp_forget();                       // repartir d'une observation propre
    $me   = (string) $_SESSION['AUTH_User'];
    $type = (string) ($_POST['type'] ?? '');

    if ($type === 'org') {
        // La lecture seule organisateur est imposée par le BlockFunction DÉPLOYÉ
        // (AUTH_RO). Tant que la version déployée l'ignore, observer donnerait un
        // accès en ÉCRITURE aux compétitions de la cible → on refuse d'abord.
        $deployed = HTDOCS . '/Modules/Authentication/BlockFunction.php';
        $roReady  = is_file($deployed) && strpos((string) @file_get_contents($deployed), 'AUTH_RO') !== false;

        $user = (string) ($_POST['user'] ?? '');
        $t = aut_get_user($user);
        if (!$roReady)                        $err = 'Redéployez d\'abord l\'authentification (page « Déploiement ») : la lecture seule organisateur n\'est pas encore active sur le serveur.';
        elseif (!$t)                          $err = 'Compte introuvable.';
        elseif ($t->AuRole == AUT_ROLE_ADMIN) $err = 'On ne peut pas observer un compte administrateur.';
        elseif ($t->AuUsername === $me)       $err = 'C\'est déjà votre compte.';
        else {
            $label = $t->AuUsername . ' (' . (aut_roles()[$t->AuRole] ?? $t->AuRole)
                   . ($t->AuScope !== '' ? ' ' . $t->AuScope : '') . ')';
            aut_imp_store(array('type' => 'org', 'user' => $t->AuUsername,
                'label' => $label, 'by' => $me, 'at' => time()));
            aut_log('IMPERSONATE_START', $me . ' -> org:' . $t->AuUsername);
            CD_redirect($CFG->ROOT_DIR . 'index.php');   // compétitions vues par la cible
            exit;
        }
    } elseif ($type === 'archer') {
        $lic = strtoupper(trim((string) ($_POST['licence'] ?? '')));
        $a = null;
        if ($lic !== '') {
            $q = safe_r_sql("SELECT BaId, BaLicence, BaName, BaFamilyName
                FROM BK_Archers WHERE BaLicence=" . StrSafe_DB($lic), false, true);
            $a = $q ? safe_fetch($q) : null;
        }
        if (!$a) $err = 'Aucun espace licencié pour cette licence (l\'archer ne s\'est jamais connecté).';
        else {
            $label = trim($a->BaName . ' ' . $a->BaFamilyName) . ' — ' . $a->BaLicence;
            aut_imp_store(array('type' => 'archer', 'archer' => intval($a->BaId),
                'label' => $label, 'by' => $me, 'at' => time()));
            aut_log('IMPERSONATE_START', $me . ' -> archer:' . $a->BaLicence);
            CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/public/index.php');
            exit;
        }
    } else {
        $err = 'Type d\'observation inconnu.';
    }
}

// Échec (ou accès direct) : retour à la page comptes avec le motif en bannière.
if ($err !== '') aut_flash_set('Observation impossible — ' . htmlspecialchars($err));
CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/');
exit;
