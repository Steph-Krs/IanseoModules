<?php
/**
 * Page de connexion UNIFIÉE (serveur partagé) — point d'entrée unique, traité
 * ENTIÈREMENT sur place (pas de délégation) : les deux flux sont ici.
 *
 *   • Organisateur → Espace Dirigeant FFTA (dirigeant.ffta.fr) — handlers
 *     aut_handle_org_login()/aut_handle_org_totp() de lib.php (source unique,
 *     MFA/2FA/extranet/cookie dirigeant compris).
 *   • Compétiteur  → Espace Licencié FFTA (monespace.ffta.fr) — fonctions
 *     bk_ffta_login()/bk_provision_archer()… du module BOOKING.
 *
 * $SKIP_AUTH : joignable anonymement même quand l'auth organisateur est active.
 * Aucun mot de passe n'est stocké ni journalisé (les deux relais l'effacent).
 */
$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 3));
require_once(HTDOCS . '/config.php');
require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/lib.php');
require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/legal-lib.php');

$root = $CFG->ROOT_DIR;
aut_ensure_schema();

/* ---- Modules présents ---- */
$hasOrganiser = !empty($CFG->USERAUTH);
$hasCompetitor = is_file($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/archer.php');
$compEnabled = true;
if ($hasCompetitor) {
    require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/schema.php');
    require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/archer.php');
    require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/ffta.php');
    require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/AUTH/booking/lib/totp.php');   // 2FA licencié (optionnelle)
    bk_schema();
    $compEnabled = bk_ffta_enabled();
    if (bk_current_archer()) { CD_redirect($root . 'Modules/Custom/AUTH/booking/public/index.php'); die(); }
}

$errO = $errC = '';
$stage = 'password';        // organisateur : 'password' ou 'totp'
$stageC = 'password';       // compétiteur : 'password' ou 'totp' (2FA locale du licencié)
$needOtpC = false;          // compétiteur : code MFA demandé
// Par défaut, l'onglet COMPÉTITEUR (la grande majorité des visiteurs sont des
// licenciés). L'organisateur reste accessible via ?p=org ou l'onglet.
$active = ($_GET['p'] ?? '') === 'org' ? 'org' : 'comp';
if (!$hasCompetitor && $hasOrganiser) $active = 'org';   // repli si compétiteur indisponible
if (!$hasOrganiser && $hasCompetitor) $active = 'comp';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Mesure d'audience de la page d'accueil (visiteur anonyme → cookie de mesure exempté ;
// self-gardée sur les GET de page, isolée, jamais fatale). Doit précéder toute sortie HTML.
require_once(__DIR__ . '/stats-usage.php');
if (function_exists('aut_track')) aut_track('public');

/* ---- POST organisateur ---- */
if ($method === 'POST' && ($_POST['role'] ?? '') === 'org' && $hasOrganiser) {
    $active = 'org';
    if (!aut_csrf_check()) {
        $errO = 'Session expirée — réessayez.';
    } elseif (($_POST['stage'] ?? '') === 'totp') {
        $stage = 'totp';
        aut_handle_org_totp($errO, $stage);      // succès → redirige et termine
    } else {
        aut_handle_org_login($errO, $stage);     // succès → redirige et termine
    }
}

/* ---- POST compétiteur ---- */
if ($method === 'POST' && ($_POST['role'] ?? '') === 'comp' && $hasCompetitor
        && ($_POST['stage'] ?? '') === 'totp') {
    /* Étape 2 : code 2FA locale du licencié. Les identifiants FFTA ont déjà été
       validés à l'étape 1 ; on n'a plus qu'à vérifier le code TOTP du serveur, en
       reprenant les cookies FFTA mis en attente (le code MFA FFTA étant à usage
       unique, on ne relance pas la connexion fédérale). */
    $active = 'comp';
    $stageC = 'totp';
    $pend = $_SESSION['BK_2FA'] ?? null;
    if (!aut_csrf_check()) {
        $errC = 'Session expirée — réessayez.';
    } elseif (!is_array($pend) || (time() - intval($pend['time'] ?? 0)) > 300) {
        unset($_SESSION['BK_2FA']);
        $stageC = 'password';
        $errC = 'Délai dépassé, reconnectez-vous.';
    } elseif (bk_too_many(array('LOGIN_FAIL', 'TOTP_FAIL'), BK_MAX_LOGIN_FAIL, (string) $pend['licence'])) {
        bk_log('LOGIN_BLOCK', (string) $pend['licence']);
        $errC = 'Trop de tentatives. Réessayez dans quelques minutes.';
    } else {
        $a = bk_get_archer(intval($pend['archer']));
        $usedSlot = 0;
        $code = (string) ($_POST['code'] ?? '');
        if ($a && $a->BaActive && $a->BaTotpEnabled
                && bk_totp_verify($a->BaTotpSecret, $code, intval($a->BaTotpLastSlot), $usedSlot)) {
            safe_w_sql("UPDATE BK_Archers SET BaTotpLastSlot=$usedSlot WHERE BaId={$a->BaId}");
            unset($_SESSION['BK_2FA']);
            session_regenerate_id(true);
            bk_session_open($a);
            bk_ffta_espace_store((string) $pend['cookies'], (string) $pend['exalto'], $a->BaId);
            bk_log('LOGIN_OK', $a->BaLicence);
            CD_redirect($root . 'Modules/Custom/AUTH/booking/public/index.php');
            die();
        }
        // Échec : horloge serveur déréglée (diagnostic, jamais une acceptation) ou code faux.
        $skew = ($a && $a->BaTotpEnabled) ? bk_totp_skew($a->BaTotpSecret, $code) : null;
        if ($skew !== null) {
            bk_log('TOTP_SKEW', (string) $pend['licence']);
            $mins = round(abs($skew) / 60);
            $errC = "Code refusé : l'horloge de ce serveur est décalée d'environ {$mins} min "
                  . "(le code de votre application est basé sur l'heure réelle). Signalez-le à "
                  . "l'organisateur — votre code est bon.";
        } else {
            bk_log('TOTP_FAIL', (string) $pend['licence']);
            $errC = 'Code incorrect.';
        }
    }
} elseif ($method === 'POST' && ($_POST['role'] ?? '') === 'comp' && $hasCompetitor) {
    $active = 'comp';
    $ident = trim((string) ($_POST['identifiant'] ?? ''));
    $pwd   = (string) ($_POST['password'] ?? '');
    $otp   = trim((string) ($_POST['otp'] ?? ''));

    if (!aut_csrf_check()) {
        $errC = 'Session expirée — réessayez.';
    } elseif (!$compEnabled) {
        $errC = "La connexion des compétiteurs est désactivée sur ce serveur.";
    } elseif ($ident === '' || $pwd === '') {
        $errC = 'Renseignez votre identifiant et votre mot de passe.';
    } elseif (bk_too_many(array('LOGIN_FAIL'), BK_MAX_LOGIN_FAIL, $ident)) {
        bk_log('LOGIN_BLOCK', $ident);
        $errC = 'Trop de tentatives. Réessayez dans quelques minutes.';
    } else {
        $res = bk_ffta_login($ident, $pwd, $otp);
        $pwd = null;                              // le mot de passe ne survit pas à l'appel
        if (!empty($res['ok'])) {
            $licence = $res['licence'];           // vient de la FFTA, jamais de la saisie
            $lue = bk_lookup_licence($licence);
            if (!$lue) {
                bk_log('LICENCE_UNKNOWN', $licence);
                $errC = "Votre licence n'est pas encore connue de ce serveur. Signalez-le à l'organisateur.";
            } elseif (!bk_ffta_name_matches($res['displayName'] ?? '', $lue)) {
                bk_log('NAME_MISMATCH', $licence);
                $errC = "Les informations lues sur l'espace licencié sont incohérentes. Signalez-le à l'organisateur.";
            } else {
                $id = bk_provision_archer($lue);
                $a = $id ? bk_get_archer($id) : null;
                if ($a && $a->BaActive && !empty($a->BaTotpEnabled)) {
                    // 2FA locale activée : on met en attente les cookies FFTA (déjà captés)
                    // et on demande le code avant d'ouvrir la session. cf. branche 'totp'.
                    $_SESSION['BK_2FA'] = array(
                        'archer'  => intval($a->BaId),
                        'licence' => $licence,
                        'cookies' => (string) ($res['cookies'] ?? ''),
                        'exalto'  => (string) ($res['exaltoId'] ?? ''),
                        'time'    => time(),
                    );
                    $stageC = 'totp';
                } elseif ($a && $a->BaActive) {
                    session_regenerate_id(true);
                    bk_session_open($a);
                    // Conserve le cookie de session monespace + l'id Exalto (attestation de licence).
                    bk_ffta_espace_store($res['cookies'] ?? '', $res['exaltoId'] ?? '', $a->BaId);
                    bk_log('LOGIN_OK', $licence);
                    CD_redirect($root . 'Modules/Custom/AUTH/booking/public/index.php');
                    die();
                } else {
                    $errC = $a ? "Votre compte a été désactivé sur ce serveur." : "La création du compte a échoué.";
                    if ($a) bk_log('LOGIN_DISABLED', $licence);
                }
            }
        } elseif (($res['err'] ?? '') === 'MFA_NEEDED') {
            $needOtpC = true;                     // pas un échec d'identifiants : non compté
            $errC = $res['msg'];
        } else {
            if (($res['err'] ?? '') === 'MFA_BAD_CODE') $needOtpC = true;
            // réseau / page FFTA / lecture licence : pas une tentative frauduleuse → non compté
            if (!in_array($res['err'] ?? '', array('NETWORK', 'NO_CSRF', 'NO_LICENCE', 'AMBIGUOUS_LICENCE'), true)) {
                bk_log('LOGIN_FAIL', $ident);
            } else {
                bk_log('READ_' . ($res['err'] ?? 'ERR'), $ident);
            }
            $errC = $res['msg'];
        }
    }
}

$csrf = aut_csrf_field();
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ianseo — Connexion</title>
<style>
* { box-sizing:border-box; }
body { margin:0; font-family:Verdana,Arial,sans-serif; color:#20263d;
       background:linear-gradient(160deg,#eaf1fb 0%,#eef2f6 55%,#e7eef6 100%);
       min-height:100vh; display:flex; flex-direction:column; }
.page { flex:1; display:flex; align-items:center; justify-content:center; padding:28px 14px; }
.landing { display:flex; flex-direction:row-reverse; align-items:center; justify-content:center;
    gap:34px; flex-wrap:wrap; width:100%; max-width:940px; }

/* Colonne connexion (carte) */
.auth-col { flex:0 0 auto; }
.card { background:#fff; border:1px solid #c9d4df; border-radius:10px; padding:26px 30px;
        box-shadow:0 8px 26px rgba(20,60,120,.12); width:372px; max-width:100%; }
@media (max-width:420px){ .card { padding:22px 18px; } }
h1 { font-size:19px; margin:0 0 2px; color:#1a4f8b; }
.sub { font-size:11px; color:#667; margin-bottom:14px; }
.relay-note { font-size:11px; color:#7a6a3a; background:#fdf7e6; border:1px solid #eadfb8;
    border-radius:6px; padding:7px 9px; margin:0 0 14px; line-height:1.45; }
.tabs { display:flex; gap:8px; margin-bottom:16px; }
.tab { flex:1; text-align:center; padding:9px 6px; border:1px solid #c9d4df; border-radius:6px;
       background:#f4f7fa; color:#334; font-size:13px; cursor:pointer; text-decoration:none; }
.tab .ic { display:block; font-size:20px; margin-bottom:2px; }
.tab.active { background:#1a4f8b; color:#fff; border-color:#1a4f8b; }
label { display:block; font-size:12px; margin:12px 0 4px; color:#334; }
input[type=text], input[type=password] { width:100%; padding:8px;
        border:1px solid #b6c2cf; border-radius:4px; font-size:14px; }
button { margin-top:18px; width:100%; padding:9px; background:#1a4f8b; color:#fff;
        border:0; border-radius:4px; font-size:14px; cursor:pointer; }
button:hover { background:#14396b; }
button[disabled] { opacity:.9; cursor:progress; }
.optional { font-size:10px; color:#889; }
.err  { background:#fde8e8; border:1px solid #e8b4b4; color:#8b1a1a; padding:8px;
        border-radius:4px; font-size:12px; margin-bottom:8px; }
.foot { margin-top:14px; font-size:11px; text-align:center; color:#667; }
.foot a { color:#1a4f8b; }
.pane { display:none; } .pane.active { display:block; }

/* Colonne argumentaire (landing) */
.pitch-col { flex:1 1 320px; max-width:440px; }
.pitch-brand { font-size:13px; color:#1a4f8b; font-weight:700; letter-spacing:.3px; margin:0 0 4px; }
.pitch { display:none; }
.pitch.active { display:block; }
.pitch h2 { font-size:23px; color:#01367c; margin:0 0 14px; line-height:1.25; }
.pitch ul { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:11px; }
.pitch li { display:flex; gap:11px; font-size:14px; line-height:1.45; color:#33404f; }
.pitch li .pi { flex:0 0 auto; font-size:18px; line-height:1.2; }
.pitch .pt { margin:16px 0 0; font-size:12.5px; color:#5b6470; }

/* Pied de page légal */
.site-foot { text-align:center; padding:16px 14px 26px; font-size:11px; color:#8a92a0; line-height:1.7; }
.site-foot .legal a { color:#1a4f8b; text-decoration:none; margin:0 7px; white-space:nowrap; }
.site-foot .legal a:hover { text-decoration:underline; }
.site-foot .credits { max-width:560px; margin:8px auto 0; }
.site-foot .credits b { color:#66707e; font-weight:600; }
.site-foot .credits a { color:#1a4f8b; text-decoration:none; }

#dots { display:inline-flex; gap:5px; vertical-align:middle; margin-left:7px; }
#dots i { width:7px; height:7px; border-radius:50%; background:#fff; opacity:.5;
    animation:bnc 1s ease-in-out infinite; }
#dots i:nth-child(2){ animation-delay:.16s; } #dots i:nth-child(3){ animation-delay:.32s; }
@keyframes bnc { 0%,80%,100%{ transform:translateY(0); opacity:.5; } 40%{ transform:translateY(-5px); opacity:1; } }
@media (max-width:760px){
    .landing { flex-direction:column; gap:22px; align-items:center; }
    /* .card a une largeur fixe (372px) qui, via flex:0 0 auto, débordait l'écran mobile.
       On repasse les colonnes en 100% capé → plus de débordement, centrage propre. */
    .auth-col { width:100%; max-width:372px; }
    .card { width:100%; }
    .pitch-col { width:100%; max-width:440px; flex-basis:auto; }
    .pitch h2 { font-size:20px; }
}
</style>
</head>
<body>
<div class="page">
<div class="landing">
<div class="auth-col">
<div class="card">
    <h1>ianseo — Serveur partagé</h1>

    <?php if ($stage === 'totp') { /* étape 2FA serveur (compte administrateur) */ ?>
    <div class="sub">Double authentification — compte administrateur.</div>
    <?php if ($errO) echo '<div class="err">' . $e($errO) . '</div>'; ?>
    <form method="post" action="login.php">
        <?= $csrf ?>
        <input type="hidden" name="role" value="org">
        <input type="hidden" name="stage" value="totp">
        <label for="t-code">Code de votre application d'authentification</label>
        <input type="text" id="t-code" name="code" inputmode="numeric" pattern="[0-9]{6}"
               maxlength="6" autocomplete="one-time-code" autofocus>
        <button type="submit">Valider</button>
    </form>
    <div class="foot"><a href="login.php">Annuler</a></div>

    <?php } elseif ($stageC === 'totp') { /* étape 2FA locale du licencié */ ?>
    <div class="sub">Double authentification — espace licencié.</div>
    <?php if ($errC) echo '<div class="err">' . $e($errC) . '</div>'; ?>
    <form method="post" action="login.php">
        <?= $csrf ?>
        <input type="hidden" name="role" value="comp">
        <input type="hidden" name="stage" value="totp">
        <label for="c-code">Code de votre application d'authentification</label>
        <input type="text" id="c-code" name="code" inputmode="numeric" pattern="[0-9]{6}"
               maxlength="6" autocomplete="one-time-code" autofocus>
        <button type="submit">Valider</button>
    </form>
    <div class="foot"><a href="login.php">Annuler</a></div>

    <?php } else { ?>
    <div class="sub">Connectez-vous avec vos identifiants fédéraux.</div>
    <p class="relay-note">Vos identifiants transitent par ce serveur le temps de la connexion — ils
       ne sont ni conservés ni enregistrés. Connectez-vous uniquement si vous avez confiance en ce serveur.</p>

    <?php if ($hasOrganiser && $hasCompetitor) { ?>
    <div class="tabs">
        <a class="tab<?= $active === 'org' ? ' active' : '' ?>"  data-pane="org"  href="?p=org"><span class="ic">🏹</span>Organisateur</a>
        <a class="tab<?= $active === 'comp' ? ' active' : '' ?>" data-pane="comp" href="?p=comp"><span class="ic">🎯</span>Compétiteur</a>
    </div>
    <?php } ?>

    <?php if ($hasOrganiser) { ?>
    <div class="pane<?= $active === 'org' ? ' active' : '' ?>" id="pane-org">
        <div class="sub">Espace <b>Dirigeant</b> FFTA — clubs, comités, fédération.</div>
        <?php if ($errO) echo '<div class="err">' . $e($errO) . '</div>'; ?>
        <form method="post" action="login.php">
            <?= $csrf ?>
            <input type="hidden" name="role" value="org">
            <input type="hidden" name="stage" value="password">
            <label for="o-user">Identifiant Espace Dirigeant</label>
            <input type="text" id="o-user" name="username" autocomplete="username"
                   value="<?= $active === 'org' ? $e($_POST['username'] ?? '') : '' ?>">
            <label for="o-pwd">Mot de passe</label>
            <input type="password" id="o-pwd" name="password" autocomplete="current-password">
            <label for="o-otp">Code de double authentification <span class="optional">(si activé sur votre compte FFTA)</span></label>
            <input type="text" id="o-otp" name="otp" inputmode="numeric" maxlength="8" autocomplete="one-time-code">
            <button type="submit">Se connecter</button>
        </form>
        <div class="foot">Vérifié auprès de dirigeant.ffta.fr. Vos identifiants ne sont pas conservés.<br>
            <a href="https://dirigeant.ffta.fr/retrouver-mes-identifiants" target="_blank" rel="noopener noreferrer">Identifiants oubliés ?</a></div>
    </div>
    <?php } ?>

    <?php if ($hasCompetitor) { ?>
    <div class="pane<?= $active === 'comp' ? ' active' : '' ?>" id="pane-comp">
        <div class="sub">Espace <b>Licencié</b> FFTA — pour vous inscrire aux compétitions.</div>
        <?php if (!$compEnabled) { ?>
            <div class="err">La connexion des compétiteurs est désactivée sur ce serveur.</div>
        <?php } else { ?>
        <?php if ($errC) echo '<div class="err">' . $e($errC) . '</div>'; ?>
        <form method="post" action="login.php">
            <?= $csrf ?>
            <input type="hidden" name="role" value="comp">
            <label for="c-user">Identifiant Espace Licencié</label>
            <input type="text" id="c-user" name="identifiant" autocomplete="username"
                   value="<?= $active === 'comp' ? $e($_POST['identifiant'] ?? '') : '' ?>">
            <label for="c-pwd">Mot de passe</label>
            <input type="password" id="c-pwd" name="password" autocomplete="current-password">
            <label for="c-otp">Code de double authentification <span class="optional">(si activé sur votre compte FFTA)</span></label>
            <input type="text" id="c-otp" name="otp" inputmode="numeric" autocomplete="one-time-code"
                   <?= $needOtpC ? 'autofocus' : '' ?>>
            <button type="submit">Se connecter</button>
        </form>
        <div class="foot">Vérifié auprès de monespace.ffta.fr. Vos identifiants ne sont pas conservés.<br>
            <a href="https://monespace.ffta.fr/retrouver-mes-identifiants" target="_blank" rel="noopener noreferrer">Identifiants oubliés ?</a></div>
        <?php } ?>
    </div>
    <?php } ?>
    <?php } ?>
</div><!-- card -->
</div><!-- auth-col -->

<div class="pitch-col">
    <p class="pitch-brand">SERVEUR PARTAGÉ</p>
    <?php if ($hasOrganiser) { ?>
    <div class="pitch<?= $active === 'org' ? ' active' : '' ?>" id="pitch-org">
        <h2>Gérez vos compétitions en ligne, sans rien installer.</h2>
        <ul>
            <li><span class="pi">🗂️</span><span>Inscriptions, plan du terrain, mandat, feuilles de marque, sommes dues — <b>tout au même endroit</b>.</span></li>
            <li><span class="pi">🚀</span><span><b>Aucune installation</b>, aucune mise à jour, aucune maintenance à votre charge.</span></li>
            <li><span class="pi">🔄</span><span>Base fédérale des licenciés <b>tenue à jour automatiquement</b>.</span></li>
            <li><span class="pi">🛡️</span><span><b>Sauvegardes et sécurité</b> assurées par le serveur.</span></li>
        </ul>
        <p class="pt">Connectez-vous avec vos identifiants de l'Espace Dirigeant FFTA.</p>
    </div>
    <?php } ?>
    <?php if ($hasCompetitor) { ?>
    <div class="pitch<?= $active === 'comp' ? ' active' : '' ?>" id="pitch-comp">
        <h2>Inscrivez-vous aux compétitions en quelques clics.</h2>
        <ul>
            <li><span class="pi">🎯</span><span>Inscription en ligne avec vos <b>identifiants fédéraux</b>.</span></li>
            <li><span class="pi">🗺️</span><span><b>Calendrier et carte</b> des compétitions ouvertes près de chez vous.</span></li>
            <li><span class="pi">📄</span><span>Vos documents : <b>feuille de marque, reçu, attestation de licence</b>.</span></li>
            <li><span class="pi">📊</span><span>Suivi de vos <b>inscriptions et statistiques</b>.</span></li>
        </ul>
        <p class="pt">Connectez-vous avec vos identifiants de l'Espace Licencié FFTA.</p>
    </div>
    <?php } ?>
</div>
</div><!-- landing -->
</div><!-- page -->

<footer class="site-foot">
    <div class="legal">
        <a href="<?= $e(aut_legal_url('mentions')) ?>">Mentions légales</a> ·
        <a href="<?= $e(aut_legal_url('cgu')) ?>">CGU</a> ·
        <a href="<?= $e(aut_legal_url('confidentialite')) ?>">Confidentialité</a> ·
        <a href="<?= $e(aut_legal_url('cookies')) ?>">Cookies</a>
    </div>
    <div class="credits">
        Le calcul et la publication des <b>résultats</b> reposent sur le logiciel
        <a href="https://www.ianseo.net" target="_blank" rel="noopener">ianseo</a>.
        La gestion des <b>comptes</b>, des <b>inscriptions</b> et du <b>calendrier</b> est un développement indépendant.
        Ce site n'utilise qu'un <b>cookie de session</b> strictement nécessaire.
    </div>
</footer>
<script>
document.querySelectorAll('.tab').forEach(function (t) {
    t.addEventListener('click', function (ev) {
        ev.preventDefault();
        var pane = t.getAttribute('data-pane');
        document.querySelectorAll('.tab').forEach(function (x) { x.classList.toggle('active', x === t); });
        document.querySelectorAll('.pane').forEach(function (p) { p.classList.toggle('active', p.id === 'pane-' + pane); });
        document.querySelectorAll('.pitch').forEach(function (p) { p.classList.toggle('active', p.id === 'pitch-' + pane); });
        history.replaceState(null, '', '?p=' + pane);
        var inp = document.querySelector('#pane-' + pane + ' input'); if (inp) inp.focus();
    });
});
document.querySelectorAll('.pane form, form[action="login.php"]').forEach(function (f) {
    f.addEventListener('submit', function () {
        var b = f.querySelector('button[type=submit]');
        if (b) { b.disabled = true; b.innerHTML = 'Connexion en cours <span id="dots"><i></i><i></i><i></i></span>'; }
    });
});
</script>
</body>
</html>
