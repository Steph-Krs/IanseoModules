<?php
/**
 * Module AUTH — Déploiement des fichiers d'authentification.
 *
 * Copie dist/ vers htdocs/Modules/Authentication/ et gère le flag
 * $CFG->USERAUTH dans Common/config.inc.php (qui survit aux MaJ ianseo).
 *
 * À relancer après chaque mise à jour officielle d'ianseo si les fichiers
 * ont été écrasés/supprimés (la barre admin affiche une alerte le cas échéant).
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once('Common/Fun_FormatText.inc.php');

checkFullACL(AclRoot, '', AclReadWrite);
if (!empty($_SESSION['AUTH_ENABLE']) && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

aut_ensure_schema();

$msgOk = '';
$msgErr = '';

$q = safe_r_sql("SELECT COUNT(*) AS n FROM AUT_Users WHERE AuRole='ADMIN' AND AuActive=1");
$r = safe_fetch($q);
$nbAdmins = $r ? intval($r->n) : 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!aut_csrf_check()) {
        $msgErr = 'Session expirée : action non effectuée, réessayez.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action == 'deploy') {
            $errors = array();
            if (aut_deploy($errors)) {
                aut_log('DEPLOY', $_SESSION['AUTH_User'] ?? 'local');
                $msgOk = 'Fichiers déployés dans Modules/Authentication/.';
            } else {
                $msgErr = 'Déploiement incomplet : ' . implode(' ; ', $errors);
            }
        }
        if ($action == 'enable') {
            $st = aut_dist_status();
            if (!$st['deployed']) {
                $msgErr = 'Déployez d\'abord les fichiers.';
            } elseif (!$nbAdmins) {
                $msgErr = 'Créez d\'abord un compte ADMIN actif (page Utilisateurs), sinon plus personne ne pourra administrer le serveur à distance.';
            } else {
                $e = '';
                if (aut_set_userauth(true, $e)) {
                    aut_log('USERAUTH_ON', $_SESSION['AUTH_User'] ?? 'local');
                    $msgOk = 'Authentification ACTIVÉE. Testez immédiatement la connexion dans une fenêtre de navigation privée avant de fermer cette session.';
                } else {
                    $msgErr = $e;
                }
            }
        }
        if ($action == 'disable') {
            $e = '';
            if (aut_set_userauth(false, $e)) {
                aut_log('USERAUTH_OFF', $_SESSION['AUTH_User'] ?? 'local');
                $msgOk = 'Authentification désactivée : le serveur est de nouveau OUVERT à tous (pensez au htdigest Apache en secours).';
            } else {
                $msgErr = $e;
            }
        }
    }
}

$st = aut_dist_status();
$flag = aut_userauth_flag_state();

$PAGE_TITLE = 'Multi-comptes — Déploiement';
include('Common/Templates/head.php');
?>
<table class="Tabella">
<tr><th class="Title" colspan="3">Déploiement de l'authentification</th></tr>
<?php
if ($msgOk)  echo '<tr><td colspan="3" class="Center" style="background:#e8f4e8; color:#1a5c1a;">' . $msgOk . '</td></tr>';
if ($msgErr) echo '<tr><td colspan="3" class="Center" style="background:#fde8e8; color:#8b1a1a;">' . $msgErr . '</td></tr>';
?>
<tr><td colspan="3" style="font-size:11px;">
    ianseo cherche nativement ses hooks d'authentification dans <code>Modules/Authentication/</code>
    (dossier hors <code>Modules/Custom/</code>, donc potentiellement écrasé par une mise à jour officielle).
    Cette page copie les fichiers sources du module (<code>dist/</code>) vers ce dossier, puis active le
    flag <code>$CFG-&gt;USERAUTH</code> dans <code>Common/config.inc.php</code> (fichier préservé lors des MaJ).
</td></tr>

<tr><th class="Title" colspan="3">État des fichiers</th></tr>
<tr><th class="Title w-40">Fichier</th><th class="Title w-30">Déployé</th><th class="Title w-30">Identique à dist/</th></tr>
<?php foreach ($st['files'] as $f => $s) {
    echo '<tr><td><code>Modules/Authentication/' . $f . '</code></td>'
        . '<td class="Center">' . ($s['deployed'] ? '✅' : '❌ absent') . '</td>'
        . '<td class="Center">' . ($s['deployed'] ? ($s['same'] ? '✅' : '⚠ différent (redéployer)') : '—') . '</td></tr>';
} ?>

<tr><th class="Title" colspan="3">État de l'activation</th></tr>
<tr><td colspan="3">
    Flag <code>$CFG-&gt;USERAUTH</code> dans <code>Common/config.inc.php</code> :
    <?php
    echo array(
        'on'     => '<b style="color:#1a5c1a;">ACTIVÉ</b>',
        'off'    => '<b style="color:#8b1a1a;">désactivé</b>',
        'absent' => '<b style="color:#8b1a1a;">absent (jamais activé)</b>',
        'nofile' => '<b>config.inc.php introuvable ?!</b>',
    )[$flag];
    ?>
    — valeur effective sur cette requête : <b><?php echo !empty($CFG->USERAUTH) ? 'active' : 'inactive'; ?></b>
    — comptes ADMIN actifs : <b><?php echo $nbAdmins; ?></b>
</td></tr>

<tr><td colspan="3" class="Center">
    <form method="post" action="" style="display:inline;"><?php echo aut_csrf_field(); ?>
        <input type="hidden" name="action" value="deploy">
        <button type="submit">1. Déployer / redéployer les fichiers</button>
    </form>
    <form method="post" action="" style="display:inline;"><?php echo aut_csrf_field(); ?>
        <input type="hidden" name="action" value="enable">
        <button type="submit" <?php echo (!$st['deployed'] || !$nbAdmins) ? 'disabled title="Fichiers déployés + un compte ADMIN actif requis"' : ''; ?>
            onclick="return confirm('Activer l\'authentification pour tout le serveur ?');">2. Activer l'authentification</button>
    </form>
    <form method="post" action="" style="display:inline;"><?php echo aut_csrf_field(); ?>
        <input type="hidden" name="action" value="disable">
        <button type="submit"
            onclick="return confirm('DÉSACTIVER l\'authentification ? Le serveur sera accessible sans compte.');">Désactiver</button>
    </form>
</td></tr>

<tr><td colspan="3" style="font-size:11px;">
    <b>Auto-réparation après MaJ ianseo</b> : un filet est posé dans <code>Common/config.inc.php</code>
    (bloc <code>AUTH-SELFHEAL</code>, ajouté automatiquement à l'activation et à chaque déploiement).
    Il recopie <code>dist/</code> vers <code>Modules/Authentication/</code> dès la première requête si une
    MaJ ianseo a effacé ces fichiers → plus besoin de redéployer à la main. Il faut simplement que le
    serveur web puisse écrire dans <code>Modules/</code>.<br>
    <b>Secours manuel</b> (si l'écriture est impossible) : recopiez <code>Modules/Custom/AUTH/dist/*</code>
    vers <code>Modules/Authentication/</code>, ou passez <code>$CFG-&gt;USERAUTH = false;</code>.
    Détail dans <code>Modules/Custom/AUTH/SERVEUR.md</code>.
</td></tr>
</table>
<?php
include('Common/Templates/tail.php');
