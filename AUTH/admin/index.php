<?php
/**
 * Module AUTH — Gestion des utilisateurs (ADMIN uniquement).
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once('Common/Fun_FormatText.inc.php');

checkFullACL(AclRoot, '', AclReadWrite);
// même verrou que Update/index.php : réservé au compte ADMIN quand l'auth est active
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

aut_ensure_schema();

$msgOk = '';
$msgErr = '';
$tmpPwd = '';   // mot de passe temporaire affiché une seule fois

function aut_admin_count() {
    $q = safe_r_sql("SELECT COUNT(*) AS n FROM AUT_Users WHERE AuRole='ADMIN' AND AuActive=1");
    $r = safe_fetch($q);
    return $r ? intval($r->n) : 0;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!aut_csrf_check()) {
        $msgErr = 'Session expirée : action non effectuée, réessayez.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = intval($_POST['id'] ?? 0);

        if ($action == 'create') {
            $username = trim($_POST['username'] ?? '');
            $role  = $_POST['role'] ?? AUT_ROLE_CLUB;
            $scope = in_array($role, array(AUT_ROLE_FED, AUT_ROLE_ADMIN)) ? '' : trim($_POST['scope'] ?? '');
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if (!preg_match('/^[0-9a-z._-]{3,64}$/i', $username)) {
                $msgErr = 'Identifiant invalide (3-64 caractères : lettres, chiffres, . _ -).';
            } elseif (!array_key_exists($role, aut_roles())) {
                $msgErr = 'Rôle invalide.';
            } elseif ($e = aut_scope_error($role, $scope)) {
                $msgErr = $e;
            } elseif (aut_get_user($username)) {
                $msgErr = 'Cet identifiant existe déjà.';
            } else {
                $tmpPwd = aut_gen_password();
                safe_w_sql("INSERT INTO AUT_Users (AuUsername, AuPassword, AuName, AuEmail, AuRole, AuScope, AuMustChangePwd)
                    VALUES (" . StrSafe_DB($username) . "," . StrSafe_DB(password_hash($tmpPwd, PASSWORD_DEFAULT)) . ","
                    . StrSafe_DB($name) . "," . StrSafe_DB($email) . "," . StrSafe_DB($role) . "," . StrSafe_DB($scope) . ", 1)");
                aut_log('USER_CREATE', $username);
                $msgOk = "Compte <b>" . htmlspecialchars($username) . "</b> créé.";
            }
        }

        if ($action == 'save' && $id) {
            $role  = $_POST['role'] ?? AUT_ROLE_CLUB;
            $scope = in_array($role, array(AUT_ROLE_FED, AUT_ROLE_ADMIN)) ? '' : trim($_POST['scope'] ?? '');
            $active = !empty($_POST['active']) ? 1 : 0;
            $q = safe_r_sql("SELECT * FROM AUT_Users WHERE AuId=$id");
            $u = safe_fetch($q);
            if (!$u) {
                $msgErr = 'Compte introuvable.';
            } elseif (!array_key_exists($role, aut_roles())) {
                $msgErr = 'Rôle invalide.';
            } elseif ($e = aut_scope_error($role, $scope)) {
                $msgErr = $e;
            } elseif ($u->AuRole == 'ADMIN' && $u->AuActive && ($role != 'ADMIN' || !$active) && aut_admin_count() <= 1) {
                $msgErr = 'Impossible : c\'est le dernier compte ADMIN actif.';
            } else {
                safe_w_sql("UPDATE AUT_Users SET
                    AuName="  . StrSafe_DB(trim($_POST['name'] ?? ''))  . ",
                    AuEmail=" . StrSafe_DB(trim($_POST['email'] ?? '')) . ",
                    AuRole="  . StrSafe_DB($role) . ",
                    AuScope=" . StrSafe_DB($scope) . ",
                    AuActive=$active
                    WHERE AuId=$id");
                if (!$active) aut_sessions_revoke($id);
                aut_log('USER_SAVE', $u->AuUsername);
                $msgOk = 'Compte <b>' . htmlspecialchars($u->AuUsername) . '</b> mis à jour.';
            }
        }

        if ($action == 'resetpwd' && $id) {
            $q = safe_r_sql("SELECT * FROM AUT_Users WHERE AuId=$id");
            if ($u = safe_fetch($q)) {
                $tmpPwd = aut_gen_password();
                safe_w_sql("UPDATE AUT_Users SET AuPassword=" . StrSafe_DB(password_hash($tmpPwd, PASSWORD_DEFAULT))
                    . ", AuMustChangePwd=1 WHERE AuId=$id");
                aut_sessions_revoke($id);
                aut_log('USER_PWDRESET', $u->AuUsername);
                $msgOk = 'Mot de passe de <b>' . htmlspecialchars($u->AuUsername) . '</b> réinitialisé (sessions déconnectées).';
            }
        }

        if ($action == 'reset2fa' && $id) {
            $q = safe_r_sql("SELECT * FROM AUT_Users WHERE AuId=$id");
            if ($u = safe_fetch($q)) {
                safe_w_sql("UPDATE AUT_Users SET AuTotpSecret='', AuTotpEnabled=0, AuTotpLastSlot=0 WHERE AuId=$id");
                aut_sessions_revoke($id);
                aut_log('TOTP_RESET', $u->AuUsername);
                $msgOk = '2FA de <b>' . htmlspecialchars($u->AuUsername) . '</b> réinitialisée (sessions déconnectées).';
            }
        }

        if ($action == 'killsessions' && $id) {
            $q = safe_r_sql("SELECT * FROM AUT_Users WHERE AuId=$id");
            if ($u = safe_fetch($q)) {
                aut_sessions_revoke($id);
                aut_log('SESSIONS_REVOKE', $u->AuUsername);
                $msgOk = 'Sessions de <b>' . htmlspecialchars($u->AuUsername) . '</b> déconnectées.';
            }
        }

        if ($action == 'delete' && $id) {
            $q = safe_r_sql("SELECT * FROM AUT_Users WHERE AuId=$id");
            $u = safe_fetch($q);
            if (!$u) {
                $msgErr = 'Compte introuvable.';
            } elseif ($u->AuRole == 'ADMIN' && $u->AuActive && aut_admin_count() <= 1) {
                $msgErr = 'Impossible : c\'est le dernier compte ADMIN actif.';
            } else {
                safe_w_sql("DELETE FROM AUT_Users WHERE AuId=$id");
                aut_sessions_revoke($id);
                aut_log('USER_DELETE', $u->AuUsername);
                $msgOk = 'Compte <b>' . htmlspecialchars($u->AuUsername) . '</b> supprimé.';
            }
        }
    }
}

$users = array();
$q = safe_r_sql("SELECT * FROM AUT_Users ORDER BY AuRole, AuScope, AuUsername");
while ($r = safe_fetch($q)) $users[] = $r;

$sessCount = array();
$q = safe_r_sql("SELECT AsnUser, COUNT(*) AS n FROM AUT_Sessions
    WHERE AsnLastSeen > DATE_SUB(NOW(), INTERVAL " . AUT_SESSION_IDLE_H . " HOUR) GROUP BY AsnUser");
while ($r = safe_fetch($q)) $sessCount[$r->AsnUser] = intval($r->n);

$logs = array();
$q = safe_r_sql("SELECT * FROM AUT_Log ORDER BY AlId DESC LIMIT 30");
while ($r = safe_fetch($q)) $logs[] = $r;

$roles = aut_roles();
$PAGE_TITLE = 'Multi-comptes — Utilisateurs';
include('Common/Templates/head.php');
?>
<table class="Tabella">
<tr><th class="Title" colspan="10">Utilisateurs du serveur</th></tr>
<?php
if ($msgOk)  echo '<tr><td colspan="10" class="Center" style="background:#e8f4e8; color:#1a5c1a;">' . $msgOk . '</td></tr>';
if ($msgErr) echo '<tr><td colspan="10" class="Center" style="background:#fde8e8; color:#8b1a1a;">' . $msgErr . '</td></tr>';
if ($tmpPwd) {
    echo '<tr><td colspan="10" class="Center" style="background:#fff6df; color:#6b5a1a; font-size:14px;">'
        . 'Mot de passe temporaire (affiché une seule fois, à transmettre à l\'utilisateur) : '
        . '<b style="font-family:monospace; font-size:16px;">' . htmlspecialchars($tmpPwd) . '</b><br>'
        . '<small>Il devra le changer à sa première connexion.</small></td></tr>';
}
?>
<tr><td colspan="10" style="font-size:11px;">
    <b>Périmètre</b> : club = n° d'agrément complet (ex. <code>0760171</code> = ligue 07, dept 60, club 171),
    CD = 2 chiffres du département (ex. <code>60</code>), CR = 2 chiffres de la ligue (ex. <code>07</code>),
    FFTA / Administrateur = vide. Un motif avec <code>%</code>/<code>_</code> est accepté pour les cas atypiques.
    Le nom des compétitions est libre (mais unique) : la propriété est enregistrée à la création,
    et CD/CR/FFTA ne voient que ce que les clubs de leur périmètre ont partagé.
    Les comptes <b>SSO</b> sont créés automatiquement à la première connexion espace dirigeant
    (pas de mot de passe local) ; les désactiver ici bloque leur accès.
    La <b>2FA</b> est obligatoire pour les comptes ADMIN (configurée à leur première connexion).
</td></tr>
<tr>
    <th class="Title">Identifiant</th>
    <th class="Title">Nom</th>
    <th class="Title">Email</th>
    <th class="Title">Rôle</th>
    <th class="Title">Périmètre</th>
    <th class="Title">Actif</th>
    <th class="Title">2FA</th>
    <th class="Title">Sessions</th>
    <th class="Title">Dernière connexion</th>
    <th class="Title">Actions</th>
</tr>
<?php foreach ($users as $u) { $isSso = ($u->AuPassword === ''); ?>
<tr>
    <td><b><?php echo htmlspecialchars($u->AuUsername); ?></b>
        <?php if ($isSso) echo ' <span style="background:#1a4f8b; color:#fff; font-size:9px; padding:1px 6px; border-radius:8px;">SSO</span>'; ?>
        <form method="post" action="" id="f<?php echo $u->AuId; ?>">
            <?php echo aut_csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo $u->AuId; ?>">
            <input type="hidden" name="action" value="save">
        </form>
    </td>
    <td><input form="f<?php echo $u->AuId; ?>" type="text" name="name" size="16" value="<?php echo htmlspecialchars($u->AuName); ?>"></td>
    <td><input form="f<?php echo $u->AuId; ?>" type="text" name="email" size="18" value="<?php echo htmlspecialchars($u->AuEmail); ?>"></td>
    <td><select form="f<?php echo $u->AuId; ?>" name="role">
        <?php foreach ($roles as $k => $lbl) echo '<option value="' . $k . '"' . ($u->AuRole == $k ? ' selected' : '') . '>' . $lbl . '</option>'; ?>
    </select></td>
    <td><input form="f<?php echo $u->AuId; ?>" type="text" name="scope" size="8" value="<?php echo htmlspecialchars($u->AuScope); ?>"></td>
    <td class="Center"><input form="f<?php echo $u->AuId; ?>" type="checkbox" name="active"<?php echo $u->AuActive ? ' checked' : ''; ?>></td>
    <td class="Center" style="white-space:nowrap;"><?php echo $u->AuTotpEnabled ? '✅' : '—'; ?>
        <?php if ($u->AuTotpEnabled) { ?>
        <button form="f<?php echo $u->AuId; ?>" type="submit" name="action" value="reset2fa"
            onclick="return confirm('Réinitialiser la 2FA de <?php echo htmlspecialchars($u->AuUsername); ?> ? (perte/changement de téléphone)');">RàZ</button>
        <?php } ?>
    </td>
    <td class="Center" style="white-space:nowrap;"><?php echo $sessCount[$u->AuId] ?? 0; ?>
        <?php if (!empty($sessCount[$u->AuId])) { ?>
        <button form="f<?php echo $u->AuId; ?>" type="submit" name="action" value="killsessions"
            onclick="return confirm('Déconnecter toutes les sessions de <?php echo htmlspecialchars($u->AuUsername); ?> ?');">Déco.</button>
        <?php } ?>
    </td>
    <td class="Center"><?php echo $u->AuLastLogin ?: '—'; ?></td>
    <td class="Center" style="white-space:nowrap;">
        <button form="f<?php echo $u->AuId; ?>" type="submit">Enregistrer</button>
        <?php if (!$isSso) { ?>
        <button form="f<?php echo $u->AuId; ?>" type="submit" name="action" value="resetpwd"
            onclick="return confirm('Réinitialiser le mot de passe de <?php echo htmlspecialchars($u->AuUsername); ?> ?');">RàZ mdp</button>
        <?php } ?>
        <button form="f<?php echo $u->AuId; ?>" type="submit" name="action" value="delete"
            onclick="return confirm('Supprimer définitivement <?php echo htmlspecialchars($u->AuUsername); ?> ?');">Supprimer</button>
    </td>
</tr>
<?php } ?>
<?php if (!count($users)) echo '<tr><td colspan="10" class="Center">Aucun compte — créez d\'abord un compte ADMIN ci-dessous.</td></tr>'; ?>

<tr><th class="Title" colspan="10">Créer un compte</th></tr>
<tr>
    <td><input form="fnew" type="text" name="username" size="14" placeholder="identifiant"></td>
    <td><input form="fnew" type="text" name="name" size="16" placeholder="Nom / club"></td>
    <td><input form="fnew" type="text" name="email" size="18" placeholder="email"></td>
    <td><select form="fnew" name="role">
        <?php foreach ($roles as $k => $lbl) echo '<option value="' . $k . '">' . $lbl . '</option>'; ?>
    </select></td>
    <td><input form="fnew" type="text" name="scope" size="8" placeholder="périmètre"></td>
    <td colspan="4" style="font-size:10px;">Le mot de passe temporaire sera généré et affiché après création.</td>
    <td class="Center">
        <form method="post" action="" id="fnew"><?php echo aut_csrf_field(); ?><input type="hidden" name="action" value="create"></form>
        <button form="fnew" type="submit">Créer</button>
    </td>
</tr>
</table>

<table class="Tabella">
<tr><th class="Title" colspan="4">Journal (30 derniers événements)</th></tr>
<tr><th class="Title w-20">Date</th><th class="Title w-20">Utilisateur</th><th class="Title w-20">IP</th><th class="Title w-40">Événement</th></tr>
<?php foreach ($logs as $l) {
    echo '<tr><td>' . $l->AlWhen . '</td><td>' . htmlspecialchars($l->AlUser) . '</td><td>'
        . htmlspecialchars($l->AlIP) . '</td><td>' . htmlspecialchars($l->AlEvent) . '</td></tr>';
} ?>
<?php if (!count($logs)) echo '<tr><td colspan="4" class="Center">Aucun événement.</td></tr>'; ?>
</table>
<?php
include('Common/Templates/tail.php');
