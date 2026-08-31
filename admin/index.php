<?php
/**
 * Module AUTH — Gestion des utilisateurs (ADMIN uniquement).
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/legal-lib.php');   // acceptation CGU (colonnes + version)
require_once('Common/Fun_FormatText.inc.php');

checkFullACL(AclRoot, '', AclReadWrite);
// même verrou que Update/index.php : réservé au compte ADMIN quand l'auth est active
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

aut_ensure_schema();
aut_legal_ensure_schema();   // colonnes AuCguVer/AuCguAt

// Le module d'inscriptions (booking) est-il installé ? → onglet « Archers » (comptes BK_Archers).
$hasArchers = (bool) safe_fetch(safe_r_sql("SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BK_Archers'"));
// Onglet actif (les formulaires archers portent tab=archers → on y reste après une action).
$activeTab = (($_REQUEST['tab'] ?? '') === 'archers' && $hasArchers) ? 'archers' : 'org';

$msgOk = '';
$msgErr = '';
$tmpPwd = '';   // mot de passe temporaire affiché une seule fois

function aut_admin_count() {
    $q = safe_r_sql("SELECT COUNT(*) AS n FROM AUT_Users WHERE AuRole='ADMIN' AND AuActive=1");
    $r = safe_fetch($q);
    return $r ? intval($r->n) : 0;
}

/** Rend un journal (filtre par événement + recherche utilisateur + pagination « charger plus »). */
function aut_journal_block($root, $title, $rows, $more, $events, $curEv, $curU, $off, $lim, $pEv, $pU, $pOff, $tab) {
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
    $url = function ($ev, $u, $o) use ($root, $tab, $pEv, $pU, $pOff) {
        $a = array('tab' => $tab);
        if ($ev !== '') $a[$pEv] = $ev;
        if ($u  !== '') $a[$pU]  = $u;
        if ($o  > 0)    $a[$pOff] = $o;
        return $root . 'index.php?' . http_build_query($a);
    };
    ?>
    <h3 style="margin:22px 0 8px; color:#01367c; font-size:15px;"><?= $e($title) ?></h3>
    <form method="get" action="<?= $e($root) ?>index.php" style="margin:0 0 10px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <input type="hidden" name="tab" value="<?= $e($tab) ?>">
        <label style="font-size:12px; color:#4c4e50;">Événement<br>
            <select name="<?= $e($pEv) ?>" onchange="this.form.submit()">
                <option value="">— tous —</option>
                <?php foreach ($events as $ev): ?><option value="<?= $e($ev) ?>"<?= $curEv === $ev ? ' selected' : '' ?>><?= $e($ev) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label style="font-size:12px; color:#4c4e50;">Utilisateur<br>
            <input type="text" name="<?= $e($pU) ?>" value="<?= $e($curU) ?>" placeholder="licence / identifiant" size="18">
        </label>
        <button type="submit">Filtrer</button>
        <?php if ($curEv !== '' || $curU !== '') echo '<a style="font-size:13px;" href="' . $e($url('', '', 0)) . '">Réinitialiser</a>'; ?>
    </form>
    <table class="Tabella">
        <tr><th class="Title w-20">Date</th><th class="Title w-20">Utilisateur</th><th class="Title w-20">IP</th><th class="Title w-40">Événement</th></tr>
        <?php foreach ($rows as $l): ?>
        <tr><td><?= $e($l->w) ?></td><td><?= $e($l->u) ?></td><td><?= $e($l->ip) ?></td><td><?= $e($l->ev) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!count($rows)) echo '<tr><td colspan="4" class="Center">Aucun événement.</td></tr>'; ?>
    </table>
    <div style="display:flex; gap:16px; align-items:center; margin:8px 0 0; font-size:13px;">
        <?php if ($off > 0) echo '<a href="' . $e($url($curEv, $curU, max(0, $off - $lim))) . '">← Plus récents</a>'; ?>
        <span style="color:#7d8183;"><?= count($rows) ? ($off + 1) . '–' . ($off + count($rows)) : '0' ?></span>
        <?php if ($more) echo '<a href="' . $e($url($curEv, $curU, $off + $lim)) . '">Plus anciens →</a>'; ?>
    </div>
    <?php
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

        // ---- Actions sur les comptes ARCHER (BK_Archers) ----
        if ($hasArchers && strpos($action, 'archer_') === 0 && $id) {
            $r = safe_fetch(safe_r_sql("SELECT BaId, BaLicence FROM BK_Archers WHERE BaId=$id"));
            if (!$r) {
                $msgErr = 'Compte archer introuvable.';
            } else {
                $lic = htmlspecialchars($r->BaLicence);
                if ($action == 'archer_save') {
                    $active = !empty($_POST['active']) ? 1 : 0;
                    safe_w_sql("UPDATE BK_Archers SET BaActive=$active WHERE BaId=$id");
                    if (!$active) safe_w_sql("DELETE FROM BK_Sessions WHERE BsArcher=$id");   // désactivation → déconnexion
                    aut_log('ARCHER_SAVE', $r->BaLicence);
                    $msgOk = "Compte archer <b>$lic</b> mis à jour" . ($active ? '.' : ' (désactivé, sessions déconnectées).');
                } elseif ($action == 'archer_killsessions') {
                    safe_w_sql("DELETE FROM BK_Sessions WHERE BsArcher=$id");
                    aut_log('ARCHER_SESSIONS', $r->BaLicence);
                    $msgOk = "Sessions de l'archer <b>$lic</b> déconnectées.";
                } elseif ($action == 'archer_resetcgu') {
                    safe_w_sql("UPDATE BK_Archers SET BaCguVer='', BaCguAt=NULL WHERE BaId=$id");
                    aut_log('ARCHER_CGU_RESET', $r->BaLicence);
                    $msgOk = "Acceptation des CGU de <b>$lic</b> réinitialisée (re-demandée à sa prochaine connexion).";
                } elseif ($action == 'archer_reset2fa') {
                    // Récupération perte de téléphone : retire la 2FA + déconnecte (l'archer
                    // pourra se reconnecter sans code, puis la réactiver s'il le souhaite).
                    safe_w_sql("UPDATE BK_Archers SET BaTotpSecret='', BaTotpEnabled=0, BaTotpLastSlot=0 WHERE BaId=$id");
                    safe_w_sql("DELETE FROM BK_Sessions WHERE BsArcher=$id");
                    aut_log('ARCHER_TOTP_RESET', $r->BaLicence);
                    $msgOk = "2FA de l'archer <b>$lic</b> réinitialisée (sessions déconnectées).";
                } elseif ($action == 'archer_delete') {
                    safe_w_sql("DELETE FROM BK_Sessions WHERE BsArcher=$id");
                    safe_w_sql("DELETE FROM BK_Archers WHERE BaId=$id");
                    aut_log('ARCHER_DELETE', $r->BaLicence);
                    $msgOk = "Compte archer <b>$lic</b> supprimé (il sera recréé automatiquement à sa prochaine connexion).";
                }
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

// Comptes ARCHER (si le module d'inscriptions est installé).
$archers = array();
$arcSess = array();
if ($hasArchers) {
    $q = safe_r_sql("SELECT * FROM BK_Archers ORDER BY BaFamilyName, BaName, BaLicence");
    while ($r = safe_fetch($q)) $archers[] = $r;
    $q = safe_r_sql("SELECT BsArcher, COUNT(*) AS n FROM BK_Sessions
        WHERE BsLastSeen > DATE_SUB(NOW(), INTERVAL 12 HOUR) GROUP BY BsArcher");
    while ($r = safe_fetch($q)) $arcSess[intval($r->BsArcher)] = intval($r->n);
}

// Journaux par public : org = AUT_Log, archer = BK_Log. Filtrables (événement + recherche) + paginés.
$LOG_LIM = 150;
$logFetch = function ($table, $p, $ev, $user, $off, $lim) {
    $w = "1=1";
    if ($ev !== '')   $w .= " AND {$p}Event = " . StrSafe_DB($ev);
    if ($user !== '') $w .= " AND {$p}User LIKE " . StrSafe_DB('%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $user) . '%');
    $rs = safe_r_sql("SELECT {$p}When AS w, {$p}User AS u, {$p}IP AS ip, {$p}Event AS ev
        FROM $table WHERE $w ORDER BY {$p}Id DESC LIMIT " . (intval($lim) + 1) . " OFFSET " . intval($off));
    $out = array(); while ($r = safe_fetch($rs)) $out[] = $r; return $out;
};
$logEvents = function ($table, $p) {
    $rs = safe_r_sql("SELECT DISTINCT {$p}Event AS ev FROM $table ORDER BY {$p}Event LIMIT 200");
    $out = array(); while ($r = safe_fetch($rs)) if ((string) $r->ev !== '') $out[] = $r->ev; return $out;
};

$oEv = trim((string) ($_GET['oev'] ?? '')); $oU = trim((string) ($_GET['ou'] ?? '')); $oOff = max(0, intval($_GET['ooff'] ?? 0));
$orgLogs = $logFetch('AUT_Log', 'Al', $oEv, $oU, $oOff, $LOG_LIM);
$orgMore = count($orgLogs) > $LOG_LIM; if ($orgMore) array_pop($orgLogs);
$orgEvents = $logEvents('AUT_Log', 'Al');

$hasBkLog = $hasArchers && (bool) safe_fetch(safe_r_sql("SELECT 1 AS x FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BK_Log'"));
$arcLogs = array(); $arcMore = false; $arcEvents = array();
$aEv = trim((string) ($_GET['aev'] ?? '')); $aU = trim((string) ($_GET['au'] ?? '')); $aOff = max(0, intval($_GET['aoff'] ?? 0));
if ($hasBkLog) {
    $arcLogs = $logFetch('BK_Log', 'Bl', $aEv, $aU, $aOff, $LOG_LIM);
    $arcMore = count($arcLogs) > $LOG_LIM; if ($arcMore) array_pop($arcLogs);
    $arcEvents = $logEvents('BK_Log', 'Bl');
}

$roles = aut_roles();
$PAGE_TITLE = 'Comptes du serveur';
include('Common/Templates/head.php');
?>
<style>
#aut-tabs { display:flex; gap:8px; margin:6px 0 14px; }
#aut-tabs button { padding:9px 18px; border:1px solid #c9d4df; border-radius:6px 6px 0 0;
    background:#eef2f6; color:#334; font-size:14px; cursor:pointer; }
#aut-tabs button.on { background:#1a4f8b; color:#fff; border-color:#1a4f8b; font-weight:600; }
.aut-pane { display:none; }
.aut-pane.on { display:block; }
.aut-banner { padding:10px 14px; border-radius:6px; margin:0 0 14px; font-size:14px; }
.aut-banner.ok  { background:#e8f4e8; color:#1a5c1a; border:1px solid #9ccf9c; }
.aut-banner.err { background:#fde8e8; color:#8b1a1a; border:1px solid #e0a0a0; }
.aut-banner.pwd { background:#fff6df; color:#6b5a1a; border:1px solid #e6d18a; }
</style>
<?php
if ($msgOk)  echo '<div class="aut-banner ok">' . $msgOk . '</div>';
if ($msgErr) echo '<div class="aut-banner err">' . $msgErr . '</div>';
if ($tmpPwd) echo '<div class="aut-banner pwd">Mot de passe temporaire (affiché une seule fois, à transmettre à l\'utilisateur) : '
    . '<b style="font-family:monospace; font-size:16px;">' . htmlspecialchars($tmpPwd) . '</b> — il devra le changer à sa première connexion.</div>';
?>
<div id="aut-tabs">
  <button type="button" data-pane="org" class="<?php echo $activeTab === 'org' ? 'on' : ''; ?>">🏹 Organisateurs (<?php echo count($users); ?>)</button>
  <?php if ($hasArchers) echo '<button type="button" data-pane="archers" class="' . ($activeTab === 'archers' ? 'on' : '') . '">🎯 Archers (' . count($archers) . ')</button>'; ?>
</div>

<div class="aut-pane<?php echo $activeTab === 'org' ? ' on' : ''; ?>" id="pane-org">
<table class="Tabella">
<tr><th class="Title" colspan="11">Organisateurs</th></tr>
<tr><td colspan="11" style="font-size:11px;">
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
    <th class="Title">CGU</th>
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
    <td class="Center" style="white-space:nowrap; font-size:11px;">
        <?php if (!empty($u->AuCguAt)) {
            $cguCur = ((string) ($u->AuCguVer ?? '') === (string) aut_legal_version());
            echo '<span style="color:' . ($cguCur ? '#1a5c1a' : '#8a6d1a') . '" title="Version acceptée : '
               . htmlspecialchars($u->AuCguVer) . '">v' . htmlspecialchars($u->AuCguVer) . '<br>'
               . htmlspecialchars(date('d/m/Y H:i', strtotime($u->AuCguAt))) . '</span>';
            if (!$cguCur) echo '<br><small style="color:#8a6d1a">(à re-accepter)</small>';
        } else {
            echo '<span style="color:#a0006d">non acceptées</span>';
        } ?>
    </td>
    <td class="Center" style="white-space:nowrap;">
        <button form="f<?php echo $u->AuId; ?>" type="submit">Enregistrer</button>
        <?php if (!$isSso) { ?>
        <button form="f<?php echo $u->AuId; ?>" type="submit" name="action" value="resetpwd"
            onclick="return confirm('Réinitialiser le mot de passe de <?php echo htmlspecialchars($u->AuUsername); ?> ?');">RàZ mdp</button>
        <?php } ?>
        <button form="f<?php echo $u->AuId; ?>" type="submit" name="action" value="delete"
            onclick="return confirm('Supprimer définitivement <?php echo htmlspecialchars($u->AuUsername); ?> ?');">Supprimer</button>
        <?php if ($u->AuRole != 'ADMIN') { ?>
        <form method="post" action="<?php echo $CFG->ROOT_DIR; ?>Modules/Custom/AUTH/admin/impersonate.php" style="display:inline;">
            <?php echo aut_csrf_field(); ?>
            <input type="hidden" name="type" value="org">
            <input type="hidden" name="user" value="<?php echo htmlspecialchars($u->AuUsername); ?>">
            <button type="submit" title="Voir ses compétitions en lecture seule">👁 Voir</button>
        </form>
        <?php } ?>
    </td>
</tr>
<?php } ?>
<?php if (!count($users)) echo '<tr><td colspan="11" class="Center">Aucun compte — créez d\'abord un compte ADMIN ci-dessous.</td></tr>'; ?>

<tr><th class="Title" colspan="11">Créer un compte</th></tr>
<tr>
    <td><input form="fnew" type="text" name="username" size="14" placeholder="identifiant"></td>
    <td><input form="fnew" type="text" name="name" size="16" placeholder="Nom / club"></td>
    <td><input form="fnew" type="text" name="email" size="18" placeholder="email"></td>
    <td><select form="fnew" name="role">
        <?php foreach ($roles as $k => $lbl) echo '<option value="' . $k . '">' . $lbl . '</option>'; ?>
    </select></td>
    <td><input form="fnew" type="text" name="scope" size="8" placeholder="périmètre"></td>
    <td colspan="5" style="font-size:10px;">Le mot de passe temporaire sera généré et affiché après création.</td>
    <td class="Center">
        <form method="post" action="" id="fnew"><?php echo aut_csrf_field(); ?><input type="hidden" name="action" value="create"></form>
        <button form="fnew" type="submit">Créer</button>
    </td>
</tr>
</table>

<?php aut_journal_block($CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/', 'Journal — organisateurs',
    $orgLogs, $orgMore, $orgEvents, $oEv, $oU, $oOff, $LOG_LIM, 'oev', 'ou', 'ooff', 'org'); ?>
</div><!-- pane-org -->

<?php if ($hasArchers): ?>
<div class="aut-pane<?php echo $activeTab === 'archers' ? ' on' : ''; ?>" id="pane-archers">
<table class="Tabella">
<tr><th class="Title" colspan="9">Archers (comptes licenciés)</th></tr>
<tr><td colspan="9" style="font-size:11px;">
    Comptes créés automatiquement à la première connexion d'un licencié (via ses identifiants de l'espace licencié).
    Le <b>nom, prénom et club</b> proviennent du fichier fédéral (réalignés à chaque connexion) et ne sont pas modifiables ici.
    <b>Désactiver</b> bloque l'accès ; <b>Supprimer</b> retire le compte (recréé automatiquement à la prochaine connexion,
    sans historique d'acceptation des CGU).
</td></tr>
<tr>
    <th class="Title">Licence</th>
    <th class="Title">Nom</th>
    <th class="Title">Club</th>
    <th class="Title">Email</th>
    <th class="Title">Actif</th>
    <th class="Title">Sessions</th>
    <th class="Title">Dernière connexion</th>
    <th class="Title">CGU</th>
    <th class="Title">Actions</th>
</tr>
<?php foreach ($archers as $a): $aid = intval($a->BaId); $alic = htmlspecialchars($a->BaLicence); ?>
<tr>
    <td><b><?= $alic ?></b>
        <form method="post" action="" id="a<?= $aid ?>">
            <?= aut_csrf_field() ?>
            <input type="hidden" name="tab" value="archers">
            <input type="hidden" name="id" value="<?= $aid ?>">
        </form>
    </td>
    <td><?= htmlspecialchars(trim($a->BaFamilyName . ' ' . $a->BaName)) ?: '—' ?></td>
    <td><?= htmlspecialchars($a->BaClubCode) ?: '—' ?></td>
    <td><?= htmlspecialchars($a->BaEmail) ?: '—' ?></td>
    <td class="Center"><input form="a<?= $aid ?>" type="checkbox" name="active"<?= $a->BaActive ? ' checked' : '' ?>></td>
    <td class="Center" style="white-space:nowrap;"><?= $arcSess[$aid] ?? 0 ?>
        <?php if (!empty($arcSess[$aid])): ?>
        <button form="a<?= $aid ?>" type="submit" name="action" value="archer_killsessions"
            onclick="return confirm('Déconnecter toutes les sessions de <?= $alic ?> ?');">Déco.</button>
        <?php endif; ?>
    </td>
    <td class="Center"><?= $a->BaLastLogin ?: '—' ?></td>
    <td class="Center" style="white-space:nowrap; font-size:11px;">
        <?php if (!empty($a->BaCguAt)) {
            $aCur = ((string) ($a->BaCguVer ?? '') === (string) aut_legal_version());
            echo '<span style="color:' . ($aCur ? '#1a5c1a' : '#8a6d1a') . '">v' . htmlspecialchars($a->BaCguVer) . '<br>'
               . htmlspecialchars(date('d/m/Y H:i', strtotime($a->BaCguAt))) . '</span>';
            if (!$aCur) echo '<br><small style="color:#8a6d1a">(à re-accepter)</small>';
            echo '<br><button form="a' . $aid . '" type="submit" name="action" value="archer_resetcgu"'
               . ' onclick="return confirm(\'Réinitialiser l’acceptation des CGU de ' . $alic . ' ?\');">RàZ</button>';
        } else {
            echo '<span style="color:#a0006d">non</span>';
        } ?>
    </td>
    <td class="Center" style="white-space:nowrap;">
        <button form="a<?= $aid ?>" type="submit" name="action" value="archer_save">Enregistrer</button>
        <button form="a<?= $aid ?>" type="submit" name="action" value="archer_delete"
            onclick="return confirm('Supprimer le compte archer <?= $alic ?> ? (recréé à sa prochaine connexion)');">Supprimer</button>
        <form method="post" action="<?= $CFG->ROOT_DIR ?>Modules/Custom/AUTH/admin/impersonate.php" style="display:inline;">
            <?= aut_csrf_field() ?>
            <input type="hidden" name="type" value="archer">
            <input type="hidden" name="licence" value="<?= $alic ?>">
            <button type="submit" title="Voir son espace licencié en lecture seule">👁 Voir</button>
        </form>
        <?php if (!empty($a->BaTotpEnabled)): ?>
        <button form="a<?= $aid ?>" type="submit" name="action" value="archer_reset2fa"
            title="Réinitialiser la 2FA (perte de téléphone)"
            onclick="return confirm('Réinitialiser la 2FA de <?= $alic ?> ? (il pourra se reconnecter sans code)');">🔒 RàZ 2FA</button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (!count($archers)) echo '<tr><td colspan="9" class="Center">Aucun archer ne s\'est encore connecté.</td></tr>'; ?>
</table>
<?php if ($hasBkLog) aut_journal_block($CFG->ROOT_DIR . 'Modules/Custom/AUTH/admin/', 'Journal — archers',
    $arcLogs, $arcMore, $arcEvents, $aEv, $aU, $aOff, $LOG_LIM, 'aev', 'au', 'aoff', 'archers'); ?>
</div><!-- pane-archers -->
<?php endif; ?>

<script>
(function () {
  var active = <?= json_encode($activeTab) ?>;
  var tabs = [].slice.call(document.querySelectorAll('#aut-tabs button'));
  function show(p) {
    if (!document.getElementById('pane-' + p)) p = 'org';
    [].forEach.call(document.querySelectorAll('.aut-pane'), function (x) { x.classList.toggle('on', x.id === 'pane-' + p); });
    tabs.forEach(function (t) { t.classList.toggle('on', t.getAttribute('data-pane') === p); });
    try { history.replaceState(null, '', location.pathname + '?tab=' + p); } catch (e) {}
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-pane')); }); });
  show(active);
})();
</script>
<?php
include('Common/Templates/tail.php');
