<?php
/**
 * public/shop.php — Boutique côté compétiteur.
 *
 * Réservation d'articles (buvette, repas, souvenirs, hébergement, accès…) pour
 * une compétition. Tout est revérifié côté serveur (stock, plafond, ouverture) :
 * cet écran informe et propose, le moteur (lib/shop.php) tranche.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/shop.php';

$archer = bk_require_archer();

$tourId = intval($_GET['t'] ?? $_POST['t'] ?? 0);
$cfg    = bk_comp_config($tourId);
$rs     = $tourId ? safe_r_sql("SELECT ToName, ToWhere, ToWhenFrom, ToWhenTo FROM Tournament WHERE ToId = $tourId") : null;
$tour   = $rs ? safe_fetch($rs) : null;

if (!$tourId || !$tour || !bk_shop_has_items($tourId)) {
    bk_head('Boutique', 'card');
    echo '<div class="bk-card"><h1>Boutique indisponible</h1>'
       . bk_msg('err', "Aucune boutique n'est proposée pour cette compétition.")
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">Mes inscriptions</a></p></div>';
    bk_foot();
    exit;
}

$open = bk_shop_open($cfg);
$errs = array();   // "item_variant" => message
$ok   = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['go'] ?? '') === '1') {
    if (!bk_csrf_check()) {
        $errs['_'] = 'Session expirée — merci de réessayer.';
    } elseif (!$open) {
        $errs['_'] = 'La boutique est fermée pour cette compétition.';
    } else {
        foreach ((array) ($_POST['q'] ?? array()) as $iid => $vars) {
            foreach ((array) $vars as $vid => $qty) {
                $res = bk_shop_order_set($tourId, $archer->BaLicence, $iid, $vid, $qty);
                if (empty($res['ok'])) $errs[intval($iid) . '_' . intval($vid)] = $res['msg'] ?? 'Refusé.';
            }
        }
        bk_log('SHOP_ORDER', $archer->BaLicence);
        $ok = true;
    }
}

$items = bk_shop_items($tourId, true, $archer->BaLicence);
$total = bk_shop_order_total($tourId, $archer->BaLicence);

// Regroupement par section, dans l'ordre d'apparition.
$bySection = array();
foreach ($items as $it) $bySection[$it['section']][] = $it;

bk_head('Boutique');
?>
<p class="bk-back"><a href="<?= bk_e(bk_public_url('registrations.php')) ?>">← Mes inscriptions</a></p>
<h1>Boutique</h1>

<div class="bk-block" style="margin-bottom:14px">
  <h2><?= bk_e($tour->ToName) ?></h2>
  <p class="bk-meta"><span><?= bk_e(bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo)) ?></span>
    <?php if ($tour->ToWhere): ?><span><?= bk_e($tour->ToWhere) ?></span><?php endif; ?></p>
</div>

<?php if ($ok && !array_filter($errs, function ($k) { return $k !== '_'; }, ARRAY_FILTER_USE_KEY)): ?>
  <?= bk_msg('ok', 'Votre commande a été enregistrée.') ?>
<?php elseif ($ok): ?>
  <?= bk_msg('err', "Certaines quantités n'ont pas pu être enregistrées (voir ci-dessous). Le reste est bien pris en compte.") ?>
<?php endif; ?>
<?php if (!empty($errs['_'])): ?><?= bk_msg('err', $errs['_']) ?><?php endif; ?>

<?php if (!$open): ?>
  <?= bk_msg('err', "La boutique est fermée : vous ne pouvez plus modifier vos commandes. Contactez l'organisateur.") ?>
<?php endif; ?>

<form method="post" id="bkshopform">
  <?= bk_csrf_field() ?>
  <input type="hidden" name="t" value="<?= intval($tourId) ?>">
  <input type="hidden" name="go" value="1">

  <?php foreach ($bySection as $section => $list): ?>
    <div class="bk-block bk-shop-sec">
      <h2><?= bk_e($section !== '' ? $section : 'Articles') ?></h2>
      <?php foreach ($list as $it):
          $hasVar = !empty($it['variants']); ?>
        <div class="bk-shop-item">
          <div class="bk-shop-h">
            <span class="bk-shop-name"><?= bk_e($it['label']) ?></span>
            <span class="bk-shop-price"><?= bk_e(number_format($it['price'], 2, ',', ' ')) ?> €</span>
          </div>
          <?php if ($it['description'] !== ''): ?><p class="bk-hint"><?= bk_e($it['description']) ?></p><?php endif; ?>
          <?php if ($it['maxper'] > 0): ?><p class="bk-hint">Maximum <?= intval($it['maxper']) ?> par personne.</p><?php endif; ?>

          <?php if (!$hasVar):
              $rem = $it['remaining']; $mine = $it['mine'];
              $soldOut = ($rem === 0 && $mine === 0);
              $max = $rem === null ? '' : ' max="' . ($rem + $mine) . '"';
              $ekey = $it['id'] . '_0'; ?>
            <div class="bk-shop-line">
              <span class="bk-shop-stock"><?= $rem === null ? '' : ($soldOut ? 'Épuisé' : $rem . ' restant' . ($rem > 1 ? 's' : '')) ?></span>
              <input class="bk-shop-q" type="number" name="q[<?= $it['id'] ?>][0]" value="<?= intval($mine) ?>"
                     min="0"<?= $max ?> data-price="<?= $it['price'] ?>" <?= (!$open || $soldOut) ? 'disabled' : '' ?>>
            </div>
            <?php if (!empty($errs[$ekey])): ?><p class="bk-shop-err"><?= bk_e($errs[$ekey]) ?></p><?php endif; ?>
          <?php else: ?>
            <div class="bk-shop-opt"><?= bk_e($it['option']) ?></div>
            <?php foreach ($it['variants'] as $v):
                $rem = $v['remaining']; $mine = $v['mine'];
                $soldOut = ($rem === 0 && $mine === 0);
                $max = $rem === null ? '' : ' max="' . ($rem + $mine) . '"';
                $ekey = $it['id'] . '_' . $v['id']; ?>
              <div class="bk-shop-line">
                <span class="bk-shop-vname"><?= bk_e($v['label']) ?></span>
                <span class="bk-shop-stock"><?= $rem === null ? '' : ($soldOut ? 'Épuisé' : $rem . ' restant' . ($rem > 1 ? 's' : '')) ?></span>
                <input class="bk-shop-q" type="number" name="q[<?= $it['id'] ?>][<?= $v['id'] ?>]" value="<?= intval($mine) ?>"
                       min="0"<?= $max ?> data-price="<?= $it['price'] ?>" <?= (!$open || $soldOut) ? 'disabled' : '' ?>>
              </div>
              <?php if (!empty($errs[$ekey])): ?><p class="bk-shop-err"><?= bk_e($errs[$ekey]) ?></p><?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <div class="bk-shop-bar">
    <span>Sous-total boutique : <b id="bk-shop-total"><?= bk_e(number_format($total, 2, ',', ' ')) ?> €</b></span>
    <?php if ($open): ?><button type="submit" class="bk-btn bk-btn-primary">Valider ma commande</button><?php endif; ?>
  </div>
</form>

<script>
(function () {
  var form = document.getElementById('bkshopform'); if (!form) return;
  var qs = form.querySelectorAll('.bk-shop-q'), out = document.getElementById('bk-shop-total');
  function eur(n) { return n.toFixed(2).replace('.', ',') + ' €'; }
  function calc() {
    var t = 0;
    Array.prototype.forEach.call(qs, function (i) { t += (parseInt(i.value, 10) || 0) * parseFloat(i.dataset.price || 0); });
    out.textContent = eur(t);
  }
  Array.prototype.forEach.call(qs, function (i) { i.addEventListener('input', calc); });
})();
</script>
<?php bk_foot(); ?>
