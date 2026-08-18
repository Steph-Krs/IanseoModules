<?php
/**
 * Déployé depuis Modules/Custom/AUTH/dist/ — changement de mot de passe.
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

$err = '';
$done = false;
$forced = !empty($u->AuMustChangePwd);

// Compte SSO (pas de mot de passe local) : le mot de passe se gère sur
// l'Espace Dirigeant FFTA, pas ici.
if ($u->AuPassword === '') {
    $PAGE_TITLE = 'Mot de passe';
    include('Common/Templates/head-min.php');
    echo '<div class="Center" style="padding:24px; font-family:Verdana,Arial,sans-serif;">'
        . '<p>Votre compte utilise la connexion <b>Espace Dirigeant FFTA</b>.</p>'
        . '<p>Votre mot de passe se modifie directement sur '
        . '<a href="https://dirigeant.ffta.fr" target="_blank" rel="noopener">dirigeant.ffta.fr</a>, '
        . 'pas sur ce serveur.</p>'
        . '<p><a href="' . $CFG->ROOT_DIR . '">Retour à ianseo</a></p></div>';
    include('Common/Templates/tail-min.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $old = $_POST['old'] ?? '';
    $new1 = $_POST['new1'] ?? '';
    $new2 = $_POST['new2'] ?? '';
    if (!password_verify($old, $u->AuPassword)) {
        $err = 'Mot de passe actuel incorrect.';
    } elseif ($new1 !== $new2) {
        $err = 'Les deux saisies ne correspondent pas.';
    } elseif (!aut_password_ok($new1)) {
        $err = 'Mot de passe trop faible : 10 caractères minimum, avec au moins une lettre et un chiffre.';
    } elseif (password_verify($new1, $u->AuPassword)) {
        $err = 'Le nouveau mot de passe doit être différent de l\'actuel.';
    } else {
        $hash = password_hash($new1, PASSWORD_DEFAULT);
        safe_w_sql("UPDATE AUT_Users SET AuPassword=" . StrSafe_DB($hash) . ", AuMustChangePwd=0 WHERE AuId={$u->AuId}");
        // révoque toutes les autres sessions (le jeton courant reste valide)
        aut_sessions_revoke($u->AuId, aut_current_token_hash());
        aut_log('PWD_CHANGE', $u->AuUsername);
        $done = true;
        $forced = false;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ianseo — Mot de passe</title>
<style>
body { margin:0; font-family:Verdana,Arial,sans-serif; background:#eef2f6;
       display:flex; align-items:center; justify-content:center; min-height:100vh; }
.card { background:#fff; border:1px solid #c9d4df; border-radius:8px; padding:32px 36px;
        box-shadow:0 4px 16px rgba(0,0,0,.08); width:340px; }
h1 { font-size:18px; margin:0 0 4px; color:#1a4f8b; }
.sub { font-size:11px; color:#667; margin-bottom:20px; }
label { display:block; font-size:12px; margin:12px 0 4px; color:#334; }
input[type=password] { width:100%; box-sizing:border-box; padding:8px;
        border:1px solid #b6c2cf; border-radius:4px; font-size:14px; }
button { margin-top:18px; width:100%; padding:9px; background:#1a4f8b; color:#fff;
        border:0; border-radius:4px; font-size:14px; cursor:pointer; }
.err  { background:#fde8e8; border:1px solid #e8b4b4; color:#8b1a1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.info { background:#fff6df; border:1px solid #e8d8a4; color:#6b5a1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.ok   { background:#e8f4e8; border:1px solid #b4d8b4; color:#1a5c1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.links { margin-top:14px; font-size:11px; text-align:center; }
.links a { color:#1a4f8b; }
</style>
</head>
<body>
<div class="card">
    <h1>Changement de mot de passe</h1>
    <div class="sub">Compte : <b><?php echo htmlspecialchars($u->AuUsername); ?></b></div>
    <?php if ($forced) echo '<div class="info">Première connexion (ou mot de passe réinitialisé) : vous devez définir un nouveau mot de passe avant de continuer.</div>'; ?>
    <?php if ($err)  echo '<div class="err">' . $err . '</div>'; ?>
    <?php if ($done) echo '<div class="ok">Mot de passe modifié. <a href="' . $CFG->ROOT_DIR . '">Accéder à ianseo</a></div>'; ?>
    <?php if (!$done) { ?>
    <form method="post" action="">
        <label for="old">Mot de passe actuel</label>
        <input type="password" id="old" name="old" autocomplete="current-password" autofocus>
        <label for="new1">Nouveau mot de passe <small>(10 caractères min., lettres + chiffres)</small></label>
        <input type="password" id="new1" name="new1" autocomplete="new-password">
        <label for="new2">Confirmer le nouveau mot de passe</label>
        <input type="password" id="new2" name="new2" autocomplete="new-password">
        <button type="submit">Modifier</button>
    </form>
    <?php } ?>
    <div class="links"><a href="LogOut.php">Se déconnecter</a></div>
</div>
</body>
</html>
