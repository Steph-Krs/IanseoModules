<?php
/**
 * legal-accept.php — acceptation des CGU par un ORGANISATEUR connecté.
 *
 * Écran BLOQUANT : le bootstrap (aut_request_bootstrap) y redirige tout organisateur
 * connecté qui n'a pas accepté la version courante des CGU. L'acceptation est
 * enregistrée HORODATÉE (AUT_Users.AuCguAt) et VERSIONNÉE (AuCguVer). Cette page est
 * exemptée de la garde (aut_is_legal_script) pour éviter toute boucle.
 */
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/legal-lib.php');

// Réservé à un organisateur connecté (le bootstrap a validé la session).
if (empty($_SESSION['AUTH_User'])) {
    CD_redirect($CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php'); die();
}
$user = (string) $_SESSION['AUTH_User'];

// Déjà accepté → retour à l'accueil.
if (aut_legal_org_ok($user)) {
    $_SESSION['AUTH_CGU_OK'] = aut_legal_version();
    CD_redirect($CFG->ROOT_DIR); die();
}

$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!aut_csrf_check()) {
        $err = 'Session expirée — réessayez.';
    } elseif (empty($_POST['accept'])) {
        $err = "Vous devez cocher la case pour accepter les conditions.";
    } else {
        aut_legal_org_record($user);
        $_SESSION['AUTH_CGU_OK'] = aut_legal_version();
        aut_log('CGU_ACCEPT', $user);
        CD_redirect($CFG->ROOT_DIR); die();
    }
}

$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
$root = $CFG->ROOT_DIR;
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Conditions générales d'utilisation</title>
<style>
* { box-sizing:border-box; }
body { margin:0; font-family:Verdana,Arial,sans-serif; background:#eef2f6; color:#20263d; line-height:1.5; }
.wrap { max-width:760px; margin:0 auto; padding:24px 16px 60px; }
h1 { font-size:22px; color:#01367c; margin:4px 0 6px; }
.lead { font-size:14px; color:#4c4e50; margin:0 0 16px; }
.err { background:#fde8e8; border:1px solid #e8b4b4; color:#8b1a1a; padding:9px 12px; border-radius:6px; font-size:13px; margin:0 0 12px; }
.box { background:#fff; border:1px solid #c9d4df; border-radius:10px; padding:6px 18px; max-height:46vh; overflow:auto;
    box-shadow:inset 0 -10px 12px -12px rgba(0,0,0,.15); overflow-wrap:anywhere; }
.box h2 { font-size:15px; color:#0254a8; margin:16px 0 5px; }
.box h2:first-child { margin-top:10px; }
.box p, .box li { font-size:13px; }
.box ul { padding-left:20px; }
.box a { color:#0254a8; }
.lg-note { margin:16px 0 0; padding:10px 12px; background:#fdf7e6; border:1px solid #eadfb8; border-radius:8px; }
.lg-note p { margin:0; font-size:12px; color:#6a5a2a; }
.lg-ver { color:#7d8183; font-size:12px; }
.links { margin:12px 0; font-size:13px; }
.links a { color:#0254a8; margin-right:14px; }
.accept { display:flex; align-items:flex-start; gap:10px; margin:14px 0; font-size:14px; }
.accept input { margin-top:3px; width:18px; height:18px; }
.row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
button { padding:11px 22px; background:#1a4f8b; color:#fff; border:0; border-radius:6px; font-size:15px; font-weight:600; cursor:pointer; }
button:hover { background:#14396b; }
.logout { color:#8a92a0; text-decoration:none; font-size:13px; }
.logout:hover { color:#5b6470; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Conditions générales d'utilisation</h1>
  <p class="lead">Avant d'accéder à votre espace, merci de lire et d'accepter les conditions générales
     d'utilisation et la politique de confidentialité de ce serveur.</p>

  <?php if ($err) echo '<div class="err">' . $e($err) . '</div>'; ?>

  <div class="box"><?= aut_legal_render('cgu') ?></div>

  <p class="links">
    Documents complets :
    <a href="<?= $e(aut_legal_url('cgu')) ?>" target="_blank" rel="noopener">CGU</a>
    <a href="<?= $e(aut_legal_url('confidentialite')) ?>" target="_blank" rel="noopener">Confidentialité</a>
    <a href="<?= $e(aut_legal_url('mentions')) ?>" target="_blank" rel="noopener">Mentions légales</a>
    <a href="<?= $e(aut_legal_url('cookies')) ?>" target="_blank" rel="noopener">Cookies</a>
  </p>

  <form method="post">
    <?= aut_csrf_field() ?>
    <label class="accept">
      <input type="checkbox" name="accept" value="1">
      <span>J'ai lu et j'accepte les <b>conditions générales d'utilisation</b> et la
        <b>politique de confidentialité</b> (version <?= $e(aut_legal_version()) ?>).</span>
    </label>
    <div class="row">
      <button type="submit">Accepter et continuer</button>
      <a class="logout" href="<?= $e($root) ?>Modules/Authentication/LogOut.php">Refuser et se déconnecter</a>
    </div>
  </form>
</div>
</body>
</html>
