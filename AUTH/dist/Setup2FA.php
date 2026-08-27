<?php
/**
 * Déployé depuis Modules/Custom/AUTH/dist/ — activation de la double
 * authentification TOTP (obligatoire pour les comptes ADMIN).
 *
 * Confirmation d'identité avant activation :
 *  - compte local (mot de passe stocké) : vérification du mot de passe local ;
 *  - compte SSO (pas de mot de passe local) : ré-authentification auprès de
 *    l'Espace Dirigeant FFTA (le mot de passe n'est pas conservé).
 */
if (basename(__DIR__) !== 'Authentication') {
    http_response_code(403);
    die('Ce fichier doit être exécuté depuis Modules/Authentication/.');
}
define('HTDOCS', dirname(__DIR__, 2));
require_once(HTDOCS . '/config.php');
require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/lib.php');

if (empty($_SESSION['AUTH_User'])) {
    CD_redirect($CFG->ROOT_DIR . 'Modules/Authentication/LogIn.php');
    die();
}
$u = aut_get_user($_SESSION['AUTH_User']);
if (!$u) {
    CD_redirect($CFG->ROOT_DIR . 'Modules/Authentication/LogOut.php');
    die();
}

$isSso = ($u->AuPassword === '');   // compte provisionné via l'espace dirigeant
$isAdmin = ($u->AuRole == AUT_ROLE_ADMIN);
$err = '';
$done = false;
$off  = false;
$mandatory = ($isAdmin && !$u->AuTotpEnabled);   // ADMIN sans 2FA : obligatoire

/* La 2FA est activable par TOUS les comptes (option de sécurité) ; elle reste
   OBLIGATOIRE et non désactivable pour les administrateurs. */
$allowed = true;

/* Désactivation (comptes NON-admin uniquement) : confirmée par un code valide. */
if (!$isAdmin && $u->AuTotpEnabled && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['disable'])) {
    $usedSlot = 0;
    if (aut_too_many_failures($u->AuUsername)) {
        aut_log('LOGIN_BLOCK', $u->AuUsername);
        $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } elseif (!aut_totp_verify($u->AuTotpSecret, $_POST['code'] ?? '', intval($u->AuTotpLastSlot), $usedSlot)) {
        aut_log('TOTP_FAIL', $u->AuUsername);
        $err = 'Code incorrect — la 2FA reste active.';
    } else {
        safe_w_sql("UPDATE AUT_Users SET AuTotpSecret='', AuTotpEnabled=0, AuTotpLastSlot=0 WHERE AuId={$u->AuId}");
        aut_sessions_revoke($u->AuId, aut_current_token_hash());   // révoque les autres sessions
        aut_log('TOTP_DISABLE', $u->AuUsername);
        $u->AuTotpEnabled = 0;
        $off = true;
    }
}

if ($allowed && !$off && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm'])) {
    // secret provisoire en session tant que non confirmé par un code valide
    $secret = $_SESSION['AUT_2FA_NewSecret'] ?? '';
    $usedSlot = 0;
    if ($secret === '') {
        $err = 'Session expirée, régénérez une clé.';
    } elseif (aut_too_many_failures($u->AuUsername)) {
        aut_log('LOGIN_BLOCK', $u->AuUsername);
        $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } elseif (!aut_totp_verify($secret, $_POST['code'] ?? '', 0, $usedSlot)) {
        aut_log('TOTP_FAIL', $u->AuUsername);
        $err = 'Code incorrect — vérifiez que l\'application est bien synchronisée et réessayez.';
    } else {
        // confirmation d'identité
        $identityOk = false;
        if ($isSso) {
            $structs = array();
            $e = '';
            $identityOk = aut_ffta_verify($u->AuUsername, $_POST['password'] ?? '', trim($_POST['fftaotp'] ?? ''), $structs, $e);
            if (!$identityOk) $err = $e ?: 'Mot de passe Espace Dirigeant incorrect.';
        } else {
            $identityOk = password_verify($_POST['password'] ?? '', $u->AuPassword);
            if (!$identityOk) $err = 'Mot de passe incorrect.';
        }
        if (!$identityOk) {
            aut_log('TOTP_FAIL', $u->AuUsername);
        } else {
            safe_w_sql("UPDATE AUT_Users SET AuTotpSecret=" . StrSafe_DB($secret)
                . ", AuTotpEnabled=1, AuTotpLastSlot=$usedSlot WHERE AuId={$u->AuId}");
            aut_sessions_revoke($u->AuId, aut_current_token_hash());   // révoque les autres sessions
            aut_log('TOTP_ENABLE', $u->AuUsername);
            unset($_SESSION['AUT_2FA_NewSecret']);
            $done = true;
        }
    }
}

// (re)génère un secret provisoire pour l'affichage
if ($allowed && !$done && !$off && (empty($_SESSION['AUT_2FA_NewSecret']) || isset($_POST['regen']))) {
    $_SESSION['AUT_2FA_NewSecret'] = aut_totp_new_secret();
}
$secret = $_SESSION['AUT_2FA_NewSecret'] ?? '';
$secretDisplay = $secret !== '' ? trim(chunk_split($secret, 4, ' ')) : '';
$uri = $secret !== '' ? aut_totp_uri($u->AuUsername, $secret) : '';
$qr  = $uri !== '' ? aut_qr_svg($uri) : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ianseo — Double authentification</title>
<style>
body { margin:0; font-family:Verdana,Arial,sans-serif; background:#eef2f6;
       display:flex; align-items:center; justify-content:center; min-height:100vh; }
.card { background:#fff; border:1px solid #c9d4df; border-radius:8px; padding:32px 36px;
        box-shadow:0 4px 16px rgba(0,0,0,.08); width:420px; }
h1 { font-size:18px; margin:0 0 4px; color:#1a4f8b; }
.sub { font-size:11px; color:#667; margin-bottom:16px; }
label { display:block; font-size:12px; margin:12px 0 4px; color:#334; }
input[type=text], input[type=password] { width:100%; box-sizing:border-box; padding:8px;
        border:1px solid #b6c2cf; border-radius:4px; font-size:14px; }
button { margin-top:14px; width:100%; padding:9px; background:#1a4f8b; color:#fff;
        border:0; border-radius:4px; font-size:14px; cursor:pointer; }
.qr { text-align:center; margin:14px 0; }
.qr svg { border:1px solid #e0e6ec; border-radius:6px; }
.secret { font-family:monospace; font-size:15px; background:#f4f7fa; border:1px dashed #b6c2cf;
        padding:8px; text-align:center; border-radius:4px; letter-spacing:1px; }
.err  { background:#fde8e8; border:1px solid #e8b4b4; color:#8b1a1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.warn { background:#fff6df; border:1px solid #e8d8a4; color:#6b5a1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.ok   { background:#e8f4e8; border:1px solid #b4d8b4; color:#1a5c1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
ol { font-size:12px; color:#334; padding-left:18px; margin:8px 0; }
details { margin-top:8px; font-size:11px; }
.uri { font-size:10px; word-break:break-all; color:#889; margin-top:4px; }
.links { margin-top:14px; font-size:11px; text-align:center; }
.links a { color:#1a4f8b; }
</style>
</head>
<body>
<div class="card">
    <h1>Double authentification (2FA)</h1>
    <div class="sub">Compte : <b><?php echo htmlspecialchars($u->AuUsername); ?></b></div>

    <?php if (!$isAdmin) echo '<div class="sub">Fonction <b>facultative</b> : une fois activée, un code de votre application sera demandé à chaque connexion, en plus de votre '
        . ($isSso ? 'connexion Espace Dirigeant' : 'mot de passe') . '.</div>'; ?>
    <?php if ($mandatory) echo '<div class="warn">La 2FA est <b>obligatoire</b> pour les comptes administrateur. Configurez-la pour continuer.</div>'; ?>
    <?php if ($err)  echo '<div class="err">' . $err . '</div>'; ?>
    <?php if ($done) echo '<div class="ok">2FA activée. Un code sera demandé à chaque connexion. <a href="' . $CFG->ROOT_DIR . '">Accéder à ianseo</a></div>'; ?>
    <?php if ($off)  echo '<div class="ok">2FA désactivée. <a href="' . $CFG->ROOT_DIR . '">Accéder à ianseo</a></div>'; ?>

    <?php if (!$done && !$off) {
        $reconf = !empty($u->AuTotpEnabled); ?>
        <?php if ($reconf) { ?>
        <div class="ok">🔒 Double authentification <b>active</b> sur ce compte.</div>
        <?php if (!$isAdmin) { ?>
        <form method="post" action="">
            <label for="dcode">Pour la désactiver, saisissez un code de votre application</label>
            <input type="text" id="dcode" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
            <button type="submit" name="disable" value="1" style="background:#a33;">Désactiver la 2FA</button>
        </form>
        <?php } ?>
        <details style="margin-top:10px"><summary>Changer d'appareil (reconfigurer)</summary>
        <?php } ?>

        <ol>
            <li>Installez une application d'authentification (Google&nbsp;Authenticator, Microsoft&nbsp;Authenticator, FreeOTP, Aegis…).</li>
            <li><b>Scannez ce QR code</b> avec l'application :</li>
        </ol>
        <?php if ($qr) { ?>
        <div class="qr"><?php echo $qr; ?></div>
        <?php } ?>
        <details<?php echo $qr ? '' : ' open'; ?>>
            <summary>Impossible de scanner ? Saisie manuelle</summary>
            <div class="secret"><?php echo $secretDisplay; ?></div>
            <div class="uri"><?php echo htmlspecialchars($uri); ?></div>
        </details>
        <form method="post" action="">
            <label for="code">Code à 6 chiffres affiché par l'application</label>
            <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus>
            <?php if ($isSso) { ?>
            <label for="password">Confirmez avec votre mot de passe Espace Dirigeant FFTA</label>
            <input type="password" id="password" name="password" autocomplete="current-password">
            <label for="fftaotp">Code MFA Espace Dirigeant <small>(si activé sur votre compte FFTA)</small></label>
            <input type="text" id="fftaotp" name="fftaotp" inputmode="numeric" maxlength="8" autocomplete="one-time-code">
            <?php } else { ?>
            <label for="password">Confirmez avec votre mot de passe</label>
            <input type="password" id="password" name="password" autocomplete="current-password">
            <?php } ?>
            <button type="submit" name="confirm" value="1"><?php echo $reconf ? 'Reconfigurer la 2FA' : 'Activer la 2FA'; ?></button>
        </form>
        <form method="post" action="">
            <button type="submit" name="regen" value="1" style="background:#889;">Générer une nouvelle clé</button>
        </form>
        <?php if ($reconf) echo '</details>'; ?>
    <?php } ?>
    <div class="links"><a href="<?php echo $CFG->ROOT_DIR; ?>Modules/Authentication/LogOut.php">Se déconnecter</a></div>
</div>
</body>
</html>
