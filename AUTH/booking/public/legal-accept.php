<?php
/**
 * public/legal-accept.php — acceptation des CGU par un ARCHER connecté.
 *
 * Écran BLOQUANT : bk_require_archer() y redirige tout archer connecté n'ayant pas
 * accepté la version courante des CGU. L'acceptation est HORODATÉE (BK_Archers.BaCguAt)
 * et VERSIONNÉE (BaCguVer). bk_require_archer s'exempte lui-même sur cette page (anti-boucle).
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__, 2) . '/legal-lib.php';

$archer = bk_require_archer();

// Déjà accepté → son espace.
if (aut_legal_archer_ok($archer)) bk_redirect('index.php');

$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = 'Session expirée — réessayez.';
    } elseif (empty($_POST['accept'])) {
        $err = "Vous devez cocher la case pour accepter les conditions.";
    } else {
        aut_legal_archer_record($archer->BaId);
        bk_log('CGU_ACCEPT', $archer->BaLicence);
        bk_redirect('index.php');
    }
}

bk_head("Conditions d'utilisation", 'card');
?>
<style>
/* La carte « card » de bk.css cape .bk-main à 420px : on l'élargit ici et on fait remplir
   .bk-cgu à 100% (jamais de valeur en vw qui déborderait). Centrage assuré par #bk.bk-card. */
#bk.bk-card .bk-main { max-width:820px; }
#bk .bk-cgu { width:100%; max-width:100%; box-sizing:border-box; }
#bk .bk-cgu-box { background:#fff; border:1px solid #c9d4df; border-radius:10px; padding:4px 16px;
    max-height:46vh; overflow:auto; box-shadow:inset 0 -10px 12px -12px rgba(0,0,0,.15); text-align:left;
    box-sizing:border-box; overflow-wrap:anywhere; }
#bk .bk-cgu-box h2 { font-size:15px; color:#0254a8; margin:15px 0 5px; }
#bk .bk-cgu-box h2:first-child { margin-top:8px; }
#bk .bk-cgu-box p, #bk .bk-cgu-box li { font-size:13px; }
#bk .bk-cgu-box ul { padding-left:20px; }
#bk .bk-cgu .lg-note { margin:14px 0 0; padding:10px 12px; background:#fdf7e6; border:1px solid #eadfb8; border-radius:8px; text-align:left; }
#bk .bk-cgu .lg-note p { margin:0; font-size:12px; color:#6a5a2a; }
#bk .bk-cgu-links { margin:12px 0; font-size:13px; text-align:left; }
#bk .bk-cgu-links a { margin-right:14px; }
#bk .bk-cgu-accept { display:flex; align-items:flex-start; gap:10px; margin:14px 0; font-size:14px; text-align:left; }
#bk .bk-cgu-accept input { margin-top:3px; width:18px; height:18px; }
#bk .bk-cgu-row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
#bk .bk-cgu-out { color:#8a92a0; text-decoration:none; font-size:13px; }
</style>
<div class="bk-cgu">
  <h1 style="text-align:left">Conditions générales d'utilisation</h1>
  <p class="bk-hint" style="text-align:left">Avant d'accéder à votre espace, merci de lire et d'accepter les
     conditions générales d'utilisation et la politique de confidentialité de ce serveur.</p>

  <?php if ($err) echo bk_msg('err', $err); ?>

  <div class="bk-cgu-box"><?= aut_legal_render('cgu') ?></div>

  <p class="bk-cgu-links">Documents complets :
    <a href="<?= bk_e(aut_legal_url('cgu')) ?>" target="_blank" rel="noopener">CGU</a>
    <a href="<?= bk_e(aut_legal_url('confidentialite')) ?>" target="_blank" rel="noopener">Confidentialité</a>
    <a href="<?= bk_e(aut_legal_url('mentions')) ?>" target="_blank" rel="noopener">Mentions légales</a>
    <a href="<?= bk_e(aut_legal_url('cookies')) ?>" target="_blank" rel="noopener">Cookies</a>
  </p>

  <form method="post">
    <?= bk_csrf_field() ?>
    <label class="bk-cgu-accept">
      <input type="checkbox" name="accept" value="1">
      <span>J'ai lu et j'accepte les <b>conditions générales d'utilisation</b> et la
        <b>politique de confidentialité</b> (version <?= bk_e(aut_legal_version()) ?>).</span>
    </label>
    <div class="bk-cgu-row">
      <button type="submit" class="bk-btn bk-btn-primary">Accepter et continuer</button>
      <a class="bk-cgu-out" href="<?= bk_e(bk_public_url('logout.php')) ?>">Refuser et se déconnecter</a>
    </div>
  </form>
</div>
<?php bk_foot(); ?>
