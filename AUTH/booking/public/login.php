<?php
/**
 * public/login.php — connexion d'un licencié via son Espace Licencié FFTA.
 *
 * Le module ne gère AUCUN mot de passe : les identifiants sont relayés à
 * monespace.ffta.fr, qui fait seul autorité.
 *
 * RATTACHEMENT : l'identifiant saisi n'est PAS forcément le numéro de licence
 * (il peut être nominatif, choisi par le licencié). La licence vient donc uniquement de
 * la page servie après connexion — déclarée par la FFTA pour la session ouverte,
 * donc non choisie par l'utilisateur. Si elle n'est pas lisible avec certitude,
 * la connexion est refusée plutôt que rattachée au hasard.
 *
 * Le mot de passe n'est ni stocké, ni journalisé, ni conservé en session.
 *
 * Limitation de débit : indispensable ici, sinon cette page deviendrait un
 * relais de bourrage contre le site de la fédération.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/ffta.php';

if (bk_current_archer()) bk_redirect('index.php');

$err     = '';
$ident   = '';
$needOtp = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ident = trim((string) ($_POST['identifiant'] ?? ''));
    $pwd   = (string) ($_POST['password'] ?? '');
    $otp   = trim((string) ($_POST['otp'] ?? ''));

    if (!bk_csrf_check()) {
        $err = 'Session expirée — merci de réessayer.';
    } elseif (!bk_ffta_enabled()) {
        $err = "La connexion à l'espace licencié est désactivée sur ce serveur.";
    } elseif ($ident === '' || $pwd === '') {
        $err = 'Renseignez votre identifiant et votre mot de passe.';
    } elseif (bk_too_many(['LOGIN_FAIL'], BK_MAX_LOGIN_FAIL, $ident)) {
        bk_log('LOGIN_BLOCK', $ident);
        $err = 'Trop de tentatives. Réessayez dans quelques minutes.';
    } else {
        $res = bk_ffta_login($ident, $pwd, $otp);
        $pwd = null;                      // le mot de passe ne survit pas à l'appel

        if (!empty($res['ok'])) {
            // La licence vient de la FFTA, jamais de la saisie de l'utilisateur.
            $licence = $res['licence'];
            $lue     = bk_lookup_licence($licence);

            if (!$lue) {
                // Identité prouvée, mais licence absente du fichier chargé dans
                // ianseo : sans nom ni club, aucune inscription n'est possible.
                bk_log('LICENCE_UNKNOWN', $licence);
                $err = "Votre licence n'est pas encore connue de ce serveur. "
                     . "Signalez-le à l'organisateur (fichier des licences à synchroniser).";
            } elseif (!bk_ffta_name_matches($res['displayName'] ?? '', $lue)) {
                // Le nom affiché par la FFTA contredit la fiche de la licence
                // lue : une erreur de lecture rattacherait le mauvais archer.
                bk_log('NAME_MISMATCH', $licence);
                $err = "Les informations lues sur l'espace licencié sont incohérentes. "
                     . "Signalez-le à l'organisateur.";
            } else {
                $id = bk_provision_archer($lue);
                if ($id) {
                    $a = bk_get_archer($id);
                    if ($a && $a->BaActive) {
                        session_regenerate_id(true);
                        bk_session_open($a);
                        // Conserve le cookie de session monespace + l'id Exalto (attestation de
                        // licence) — même geste que la login.php unifiée. Sans ça, le relais
                        // d'attestation n'a pas de cookie et retombe sur le lien direct.
                        bk_ffta_espace_store($res['cookies'] ?? '', $res['exaltoId'] ?? '', $a->BaId);
                        bk_log('LOGIN_OK', $licence);
                        bk_redirect('index.php');
                    }
                    bk_log('LOGIN_DISABLED', $licence);
                    $err = "Votre compte a été désactivé sur ce serveur.";
                } else {
                    $err = "La création du compte a échoué.";
                }
            }
        } elseif (($res['err'] ?? '') === 'MFA_NEEDED') {
            // Pas un échec d'identifiants : on ne compte pas cette tentative.
            $needOtp = true;
            $err = $res['msg'];
        } else {
            if (($res['err'] ?? '') === 'MFA_BAD_CODE') $needOtp = true;
            // Un incident réseau ou une page FFTA modifiée n'est pas une erreur
            // de l'utilisateur : ne pas la compter dans la limitation de débit,
            // sinon une panne de la FFTA verrouille tous les archers.
            // Idem pour un problème de lecture de la licence : la connexion
            // elle-même a réussi, ce n'est pas une tentative frauduleuse.
            if (!in_array($res['err'] ?? '', ['NETWORK', 'NO_CSRF', 'NO_LICENCE', 'AMBIGUOUS_LICENCE'], true)) {
                bk_log('LOGIN_FAIL', $ident);
            } else {
                bk_log('READ_' . ($res['err'] ?? 'ERR'), $ident);
            }
            $err = $res['msg'];
        }
    }
}

bk_head('Connexion', 'card');
?>
<div class="bk-card">
  <h1>Espace licencié</h1>
  <p class="bk-sub">Connectez-vous avec vos identifiants de l'espace licencié de la fédération
     pour vous inscrire aux compétitions et suivre vos inscriptions.</p>

  <?= $err ? bk_msg('err', $err) : '' ?>

  <form method="post" autocomplete="on">
    <?= bk_csrf_field() ?>
    <label for="identifiant">Identifiant</label>
    <input id="identifiant" name="identifiant" type="text" required autofocus
           autocomplete="username" value="<?= bk_e($ident) ?>">
    <p class="bk-hint">Votre identifiant de l'espace licencié : numéro de licence ou identifiant nominatif.</p>

    <label for="password">Mot de passe</label>
    <input id="password" name="password" type="password" required autocomplete="current-password">

    <div class="bk-otp"<?= $needOtp ? '' : ' hidden' ?>>
      <label for="otp">Code de double authentification</label>
      <input id="otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-password"
             <?= $needOtp ? 'required' : '' ?>>
      <p class="bk-hint">Code affiché par votre application d'authentification.</p>
    </div>

    <button type="submit" class="bk-btn bk-btn-primary">Se connecter</button>
  </form>

  <p class="bk-alt">
    Vos identifiants sont ceux de votre espace licencié fédéral : ce site ne les conserve pas.<br>
    <a href="https://monespace.ffta.fr/retrouver-mes-identifiants" target="_blank" rel="noopener noreferrer">Identifiants oubliés ?</a>
  </p>
</div>
<?php bk_foot(); ?>
