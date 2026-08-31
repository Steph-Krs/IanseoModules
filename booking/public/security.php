<?php
/**
 * public/security.php — double authentification (2FA/TOTP) du licencié, OPTIONNELLE.
 *
 * L'archer connecté peut ACTIVER une 2FA (application d'authentification) pour
 * renforcer sa connexion, ou la DÉSACTIVER. Jamais imposée. En cas de perte du
 * téléphone : RàZ par l'administrateur (page Comptes). Voir lib/totp.php.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/totp.php';

$archer = bk_require_archer();

// Observation admin (lecture seule) : pas de gestion 2FA depuis cette vue.
$readonly = function_exists('bk_impersonating') && bk_impersonating();

$msg = '';
$err = '';
$done = false;      // activation réussie
$off  = false;      // désactivation réussie

if (!$readonly && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = 'Session expirée — rechargez la page et réessayez.';
    } elseif (isset($_POST['disable']) && $archer->BaTotpEnabled) {
        // Désactivation : exige un code valide (preuve de possession).
        $usedSlot = 0;
        if (bk_too_many(array('TOTP_FAIL'), BK_MAX_LOGIN_FAIL, $archer->BaLicence)) {
            bk_log('LOGIN_BLOCK', $archer->BaLicence);
            $err = 'Trop de tentatives. Réessayez dans quelques minutes.';
        } elseif (!bk_totp_verify($archer->BaTotpSecret, $_POST['code'] ?? '', intval($archer->BaTotpLastSlot), $usedSlot)) {
            bk_log('TOTP_FAIL', $archer->BaLicence);
            $err = 'Code incorrect — la double authentification reste active.';
        } else {
            safe_w_sql("UPDATE BK_Archers SET BaTotpSecret='', BaTotpEnabled=0, BaTotpLastSlot=0 WHERE BaId=" . intval($archer->BaId));
            safe_w_sql("DELETE FROM BK_Sessions WHERE BsArcher=" . intval($archer->BaId)
                . " AND BsTokenHash <> '" . bk_current_token_hash() . "'");   // révoque les autres sessions
            bk_log('TOTP_DISABLE', $archer->BaLicence);
            $archer->BaTotpEnabled = 0;
            $off = true;
        }
    } elseif (isset($_POST['confirm'])) {
        // Activation : secret provisoire en session tant qu'un code valide ne l'a pas confirmé.
        $secret = $_SESSION['BK_2FA_NewSecret'] ?? '';
        $usedSlot = 0;
        if ($secret === '') {
            $err = 'Session expirée, régénérez une clé.';
        } elseif (bk_too_many(array('TOTP_FAIL'), BK_MAX_LOGIN_FAIL, $archer->BaLicence)) {
            bk_log('LOGIN_BLOCK', $archer->BaLicence);
            $err = 'Trop de tentatives. Réessayez dans quelques minutes.';
        } elseif (!bk_totp_verify($secret, $_POST['code'] ?? '', 0, $usedSlot)) {
            bk_log('TOTP_FAIL', $archer->BaLicence);
            $err = 'Code incorrect — vérifiez l\'heure de votre téléphone et réessayez.';
        } else {
            safe_w_sql("UPDATE BK_Archers SET BaTotpSecret=" . StrSafe_DB($secret)
                . ", BaTotpEnabled=1, BaTotpLastSlot=$usedSlot WHERE BaId=" . intval($archer->BaId));
            safe_w_sql("DELETE FROM BK_Sessions WHERE BsArcher=" . intval($archer->BaId)
                . " AND BsTokenHash <> '" . bk_current_token_hash() . "'");   // révoque les autres sessions
            bk_log('TOTP_ENABLE', $archer->BaLicence);
            unset($_SESSION['BK_2FA_NewSecret']);
            $archer->BaTotpEnabled = 1;
            $done = true;
        }
    }
}

$enabled = !empty($archer->BaTotpEnabled);

// (re)génère un secret provisoire pour l'affichage de l'enrôlement
if (!$readonly && !$done && (empty($_SESSION['BK_2FA_NewSecret']) || isset($_POST['regen']))) {
    $_SESSION['BK_2FA_NewSecret'] = bk_totp_new_secret();
}
$secret = $_SESSION['BK_2FA_NewSecret'] ?? '';
$secretDisplay = $secret !== '' ? trim(chunk_split($secret, 4, ' ')) : '';
$uri = $secret !== '' ? bk_totp_uri($archer->BaLicence, $secret) : '';
$qr  = $uri !== '' ? bk_qr_svg($uri) : '';

// Bloc d'enrôlement (QR + secret + confirmation), réutilisé tel quel pour l'activation
// initiale ET la reconfiguration (changement d'appareil, dans un <details>).
$renderEnroll = function () use ($qr, $secretDisplay, $uri, $enabled) { ?>
    <ol class="bk-hint" style="line-height:1.7">
      <li>Installez une application d'authentification sur votre téléphone.</li>
      <li><b>Scannez ce QR code</b> avec l'application :</li>
    </ol>
    <?php if ($qr): ?><div class="sec-qr"><?= $qr ?></div><?php endif; ?>
    <details<?= $qr ? '' : ' open' ?>>
      <summary class="bk-hint" style="cursor:pointer">Impossible de scanner ? Saisie manuelle</summary>
      <div class="sec-secret"><?= bk_e($secretDisplay) ?></div>
      <div class="sec-uri"><?= bk_e($uri) ?></div>
    </details>
    <form method="post" style="margin-top:12px">
      <?= bk_csrf_field() ?>
      <label for="code2" class="bk-hint" style="display:block;margin-bottom:4px">Code à 6 chiffres affiché par l'application</label>
      <input type="text" id="code2" name="code" class="sec-code" inputmode="numeric" pattern="[0-9]{6}"
             maxlength="6" autocomplete="one-time-code" placeholder="123456" autofocus>
      <button type="submit" name="confirm" value="1" class="bk-btn bk-btn-primary"><?= $enabled ? 'Reconfigurer' : 'Activer' ?></button>
    </form>
    <form method="post" style="margin-top:6px">
      <?= bk_csrf_field() ?>
      <button type="submit" name="regen" value="1" class="bk-btn">Générer une nouvelle clé</button>
    </form>
<?php };

bk_head('Sécurité');
?>
<style>
#bk .sec-qr { text-align:center; margin:14px 0; }
#bk .sec-qr svg { border:1px solid #e0e6ec; border-radius:6px; }
#bk .sec-secret { font-family:monospace; font-size:15px; background:#f4f7fa; border:1px dashed #b6c2cf;
    padding:8px; text-align:center; border-radius:4px; letter-spacing:1px; }
#bk .sec-uri { font-size:10px; word-break:break-all; color:#889; margin-top:4px; }
#bk .sec-code { max-width:220px; }
#bk .sec-status { display:inline-block; padding:3px 10px; border-radius:12px; font-weight:600; font-size:13px; }
#bk .sec-on  { background:#d2f4cd; color:#1a7a2b; }
#bk .sec-off { background:#eef1f4; color:#5b6470; }
</style>

<div class="bk-block" style="max-width:560px">
  <h1>Sécurité — double authentification</h1>
  <p class="bk-hint">Fonction <b>facultative</b>. Activez-la pour qu'un code de votre application
     d'authentification (Google/Microsoft Authenticator, FreeOTP, Aegis…) soit demandé à chaque
     connexion, en plus de vos identifiants FFTA.</p>

  <?php if ($readonly): ?>
    <?= bk_msg('err', 'Vue administrateur (lecture seule) : la 2FA se gère depuis le compte du licencié.') ?>
  <?php else: ?>

  <?php if ($msg) echo bk_msg('ok', $msg); ?>
  <?php if ($err) echo bk_msg('err', $err); ?>

  <p>État : <span class="sec-status <?= $enabled ? 'sec-on' : 'sec-off' ?>">
     <?= $enabled ? '🔒 double authentification active' : '🔓 non activée' ?></span></p>

  <?php if ($done): ?>
    <?= bk_msg('ok', 'Double authentification activée. Un code vous sera demandé à chaque connexion.') ?>
  <?php elseif ($off): ?>
    <?= bk_msg('ok', 'Double authentification désactivée.') ?>
  <?php endif; ?>

  <?php if ($enabled && !$done): ?>
    <p class="bk-hint">Pour la désactiver, saisissez un code de votre application :</p>
    <form method="post" style="margin-bottom:16px">
      <?= bk_csrf_field() ?>
      <input type="text" name="code" class="sec-code" inputmode="numeric" pattern="[0-9]{6}"
             maxlength="6" autocomplete="one-time-code" placeholder="123456">
      <button type="submit" name="disable" value="1" class="bk-btn bk-btn-danger">Désactiver</button>
    </form>
    <details>
      <summary class="bk-hint" style="cursor:pointer">Changer d'appareil (reconfigurer la 2FA)</summary>
      <div style="margin-top:10px"><?php $renderEnroll(); ?></div>
    </details>
  <?php elseif (!$done): ?>
    <?php $renderEnroll(); ?>
  <?php endif; ?>

  <?php endif; /* readonly */ ?>

  <p style="margin-top:18px"><a class="bk-btn" href="<?= bk_e(bk_public_url()) ?>">← Mon espace</a></p>
</div>
<?php
bk_foot();
