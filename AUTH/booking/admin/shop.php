<?php
/**
 * admin/shop.php — Boutique de la compétition (organisateur).
 * Sections libres, articles simples ou à variantes (stock propre), plafond par
 * personne, date limite propre. Le moteur (lib/shop.php) fait foi côté serveur.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/schema.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/shop.php';
require_once dirname(__DIR__) . '/lib/archer.php';   // bk_csrf_*
require_once dirname(__DIR__) . '/lib/ui.php';       // bk_e

bk_schema();

$TOUR = intval($_SESSION['TourId']);
$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!bk_csrf_check()) {
        $err = 'Session expirée — rechargez la page et réessayez.';
    } else {
        // Enregistre les articles (upsert), leurs variantes, et supprime ce qui a
        // été retiré de l'écran (réconciliation par identifiants).
        $keptItems = array();
        $order = 0;
        foreach ((array) ($_POST['item'] ?? array()) as $row) {
            if (!is_array($row)) continue;
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') continue;                       // ligne vide ignorée
            $itemId = bk_shop_item_upsert($TOUR, array(
                'id' => $row['id'] ?? 0, 'section' => $row['section'] ?? '', 'label' => $label,
                'description' => $row['description'] ?? '', 'price' => $row['price'] ?? 0,
                'stock' => $row['stock'] ?? 0, 'maxper' => $row['maxper'] ?? 0,
                'option' => $row['option'] ?? '', 'order' => $order++, 'active' => !empty($row['active']),
            ));
            $keptItems[] = $itemId;

            $keptVars = array();
            if (trim((string) ($row['option'] ?? '')) !== '') {
                $vo = 0;
                foreach ((array) ($row['var'] ?? array()) as $vrow) {
                    if (!is_array($vrow)) continue;
                    if (trim((string) ($vrow['label'] ?? '')) === '') continue;
                    $keptVars[] = bk_shop_variant_upsert($itemId, array(
                        'id' => $vrow['id'] ?? 0, 'label' => $vrow['label'] ?? '',
                        'stock' => $vrow['stock'] ?? 0, 'order' => $vo++,
                    ));
                }
            }
            // supprime les variantes retirées de cet article
            $existing = array();
            $rs = safe_r_sql("SELECT SvId FROM BK_ShopVariants WHERE SvItem = " . intval($itemId));
            while ($r = safe_fetch($rs)) $existing[] = intval($r->SvId);
            foreach (array_diff($existing, $keptVars) as $del) bk_shop_variant_delete($del);
        }
        // supprime les articles retirés de la compétition
        $existing = array();
        $rs = safe_r_sql("SELECT SiId FROM BK_ShopItems WHERE SiTournament = $TOUR");
        while ($r = safe_fetch($rs)) $existing[] = intval($r->SiId);
        foreach (array_diff($existing, $keptItems) as $del) bk_shop_item_delete($TOUR, $del);

        bk_shop_set_deadline($TOUR, $_POST['shop_until'] ?? '');
        $msg = 'Boutique enregistrée.';
    }
}

$cfg   = bk_comp_config($TOUR);
$items = bk_shop_items($TOUR);
$open  = bk_shop_open($cfg);

$sections = array();
foreach ($items as $it) if ($it['section'] !== '' && !in_array($it['section'], $sections, true)) $sections[] = $it['section'];

/** Champ datetime-local depuis une colonne DATETIME. */
function bk_shop_dtval($v)
{
    $v = trim((string) $v);
    return ($v === '' || strpos($v, '0000') === 0) ? '' : str_replace(' ', 'T', substr($v, 0, 16));
}

/** Montant en champ texte (2 déc., virgule) ; vide si null/''. */
function bk_amt2($v)
{
    return ($v === '' || $v === null) ? '' : number_format((float) $v, 2, ',', '');
}

/** Une variante (rendu serveur ET gabarit JS avec indices '__i__' / '__v__'). */
function bk_var_row($iidx, $vidx, $v)
{
    $v = array_merge(array('id' => 0, 'label' => '', 'stock' => 0), (array) $v);
    ob_start(); ?>
    <div class="si-var">
      <input type="hidden" name="item[<?= $iidx ?>][var][<?= $vidx ?>][id]" value="<?= intval($v['id']) ?>">
      <input type="text" name="item[<?= $iidx ?>][var][<?= $vidx ?>][label]" value="<?= bk_e($v['label']) ?>" placeholder="ex. M">
      <input type="number" min="0" name="item[<?= $iidx ?>][var][<?= $vidx ?>][stock]" value="<?= intval($v['stock']) ?>" title="Stock (0 = illimité)">
      <button type="button" class="si-vdel" title="Retirer cette variante">✕</button>
    </div>
    <?php return ob_get_clean();
}

/** Une carte d'article (rendu serveur ET gabarit JS avec indice '__i__'). */
function bk_item_card($idx, $it)
{
    $it = array_merge(array('id' => 0, 'section' => '', 'label' => '', 'description' => '',
        'price' => '', 'stock' => 0, 'maxper' => 0, 'option' => '', 'active' => 1, 'variants' => array()), $it);
    $hasOpt = trim((string) $it['option']) !== '';
    ob_start(); ?>
    <div class="shop-item" data-i="<?= $idx ?>">
      <input type="hidden" name="item[<?= $idx ?>][id]" value="<?= intval($it['id']) ?>">
      <div class="si-row">
        <label class="si-f"><span>Section</span>
          <input type="text" list="shop-sections" name="item[<?= $idx ?>][section]" value="<?= bk_e($it['section']) ?>" placeholder="ex. Buvette"></label>
        <label class="si-f si-grow"><span>Article</span>
          <input type="text" name="item[<?= $idx ?>][label]" value="<?= bk_e($it['label']) ?>" placeholder="ex. Sandwich"></label>
        <label class="si-f"><span>Prix (€)</span>
          <input type="text" name="item[<?= $idx ?>][price]" value="<?= bk_e(bk_amt2($it['price'])) ?>" size="6"></label>
      </div>
      <label class="si-f si-grow"><span>Description (facultative)</span>
        <input type="text" name="item[<?= $idx ?>][description]" value="<?= bk_e($it['description']) ?>"></label>
      <div class="si-row">
        <label class="si-f si-grow"><span>Options / variantes</span>
          <input type="text" class="si-opt" name="item[<?= $idx ?>][option]" value="<?= bk_e($it['option']) ?>" placeholder="ex. Taille — vide = article simple"></label>
        <label class="si-f si-simple-stock"<?= $hasOpt ? ' style="display:none"' : '' ?>><span>Stock (0 = illimité)</span>
          <input type="number" min="0" name="item[<?= $idx ?>][stock]" value="<?= intval($it['stock']) ?>"></label>
        <label class="si-f"><span>Max / pers. (0 = illimité)</span>
          <input type="number" min="0" name="item[<?= $idx ?>][maxper]" value="<?= intval($it['maxper']) ?>"></label>
      </div>
      <div class="si-variants"<?= $hasOpt ? '' : ' style="display:none"' ?>>
        <div class="si-vhead">Variantes — chacune avec son stock</div>
        <div class="si-vlist">
          <?php foreach ((array) $it['variants'] as $vid => $v) echo bk_var_row($idx, $vid, $v); ?>
        </div>
        <button type="button" class="bk-btn bk-add si-addvar">+ Ajouter une variante</button>
      </div>
      <div class="si-foot">
        <label class="si-chk"><input type="checkbox" name="item[<?= $idx ?>][active]" value="1" <?= $it['active'] ? 'checked' : '' ?>> Visible par les archers</label>
        <button type="button" class="bk-btn si-del">Supprimer l'article</button>
      </div>
    </div>
    <?php return ob_get_clean();
}

$PAGE_TITLE = 'Boutique de la compétition';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#bkshop { max-width: 900px; }
#bkshop h1 { font-size: 22px; color: #01367c; margin: 0 0 6px; }
#bkshop .bk-lead { color: #4c4e50; font-size: 14px; margin: 0 0 16px; }
#bkshop .bk-sec { background:#fff; border:1px solid #d2d4d6; border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.08); padding:14px 16px; margin:0 0 14px; }
#bkshop .bk-sec h2 { margin:0 0 10px; font-size:15px; color:#0254a8; }
#bkshop label { font-size:13px; }
#bkshop input[type=text], #bkshop input[type=number], #bkshop input[type=datetime-local] {
    padding:6px 8px; border:1px solid #d2d4d6; border-radius:6px; font-size:14px; }
#bkshop .si-row { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
#bkshop .si-f { display:flex; flex-direction:column; gap:3px; margin:8px 0 0; }
#bkshop .si-f > span { font-size:12px; color:#7d8183; }
#bkshop .si-f.si-grow { flex:1 1 240px; }
#bkshop .si-f.si-grow input { width:100%; }
#bkshop .shop-item { border:1px solid #d2d4d6; border-left:4px solid #0254a8; border-radius:8px;
    padding:12px 14px; margin:0 0 12px; background:#fbfcfe; }
#bkshop .si-variants { margin:10px 0 0; padding:10px 12px; background:#f0f4ff; border-radius:6px; }
#bkshop .si-vhead { font-size:12px; color:#01367c; font-weight:600; margin-bottom:6px; }
#bkshop .si-var { display:flex; gap:8px; align-items:center; margin:0 0 6px; }
#bkshop .si-var input[type=text] { flex:1 1 auto; }
#bkshop .si-var input[type=number] { width:90px; }
#bkshop .si-vdel { border:1px solid #e8b4ae; background:#fff; color:#c0392b; border-radius:6px;
    padding:5px 9px; cursor:pointer; }
#bkshop .si-vdel:hover { background:#ffd6db; }
#bkshop .si-foot { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;
    gap:10px; margin-top:10px; padding-top:8px; border-top:1px solid #eef; }
#bkshop .si-chk { font-size:13px; }
#bkshop .bk-btn { padding:8px 16px; border:1px solid #0254a8; border-radius:6px;
    background:#0254a8; color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
#bkshop .bk-btn:hover { background:#01367c; border-color:#01367c; }
#bkshop .bk-add { background:#f0f4ff; color:#0254a8; border-color:#a7d6ff; font-weight:400; }
#bkshop .si-del { background:#fff; color:#c0392b; border-color:#e8b4ae; font-weight:400; }
#bkshop .si-del:hover { background:#ffd6db; }
#bkshop .bk-msg { padding:9px 12px; border-radius:6px; margin:0 0 14px; font-size:13px; }
#bkshop .bk-ok  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkshop .bk-err { background:#ffd6db; border:1px solid #bb7575; color:#a80000; }
#bkshop .bk-hint { margin:6px 0 0; font-size:12px; color:#7d8183; }
#bkshop .bk-tag { display:inline-block; padding:2px 9px; border-radius:5px; font-size:12px; }
#bkshop .bk-on  { background:#d2f4cd; border:1px solid #75ae77; color:#04ac0b; }
#bkshop .bk-off { background:#fdecea; border:1px solid #e8b4ae; color:#c0392b; }
</style>

<div id="bkshop">
<h1>Boutique</h1>
<p class="bk-lead">Proposez aux archers des articles à réserver (buvette, repas, souvenirs,
   hébergement, accès…). Ils commandent depuis leur espace ; les montants s'ajoutent à leur reçu.</p>

<?php if ($msg): ?><div class="bk-msg bk-ok"><?= bk_e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="bk-msg bk-err"><?= bk_e($err) ?></div><?php endif; ?>

<form method="post">
<?= bk_csrf_field() ?>

<div class="bk-sec">
  <h2>Disponibilité</h2>
  <div class="si-row">
    <label class="si-f"><span>Ouverte jusqu'au (facultatif)</span>
      <input type="datetime-local" name="shop_until" value="<?= bk_e(bk_shop_dtval($cfg->BcShopUntil ?? '')) ?>"></label>
    <span class="bk-tag <?= $open ? 'bk-on' : 'bk-off' ?>"><?= $open ? 'Boutique ouverte' : 'Boutique fermée' ?></span>
  </div>
  <p class="bk-hint">Sans date, la boutique suit l'ouverture des inscriptions. Avec une date, elle
     reste commandable jusque-là (même après la clôture des inscriptions, ou l'inverse).</p>
</div>

<div class="bk-sec">
  <h2>Articles</h2>
  <div id="shop-items">
    <?php foreach ($items as $idx => $it) echo bk_item_card($idx, $it); ?>
  </div>
  <button type="button" class="bk-btn bk-add" onclick="shopAddItem()">+ Ajouter un article</button>
  <p class="bk-hint">Un article sans « option » est simple (un seul stock). Renseignez une option
     (ex. « Taille », « Menu ») pour lui donner des variantes, chacune avec son propre stock.</p>
</div>

<button type="submit" class="bk-btn">Enregistrer la boutique</button>
</form>

<datalist id="shop-sections">
  <?php foreach ($sections as $s): ?><option value="<?= bk_e($s) ?>"></option><?php endforeach; ?>
</datalist>

<template id="shop-item-tpl"><?= bk_item_card('__i__', array()) ?></template>
<template id="shop-var-tpl"><?= bk_var_row('__i__', '__v__', array()) ?></template>
</div>

<script>
var shopIC = <?= count($items) ?>, shopVC = 100000;
function shopAddItem() {
  var html = document.getElementById('shop-item-tpl').innerHTML.replace(/__i__/g, 'i' + (shopIC++));
  var w = document.createElement('div'); w.innerHTML = html.trim();
  document.getElementById('shop-items').appendChild(w.firstElementChild);
}
function shopAddVar(card) {
  var i = card.getAttribute('data-i');
  var html = document.getElementById('shop-var-tpl').innerHTML.replace(/__i__/g, i).replace(/__v__/g, 'v' + (shopVC++));
  var w = document.createElement('div'); w.innerHTML = html.trim();
  card.querySelector('.si-vlist').appendChild(w.firstElementChild);
}
document.addEventListener('click', function (e) {
  var el = e.target.closest ? e.target : e.target.parentElement;
  if (!el || !el.closest) return;
  if (el.closest('.si-addvar')) { e.preventDefault(); shopAddVar(el.closest('.shop-item')); }
  else if (el.closest('.si-del')) { e.preventDefault(); if (confirm('Supprimer cet article ?')) el.closest('.shop-item').remove(); }
  else if (el.closest('.si-vdel')) { e.preventDefault(); el.closest('.si-var').remove(); }
});
document.addEventListener('input', function (e) {
  if (e.target.classList && e.target.classList.contains('si-opt')) {
    var card = e.target.closest('.shop-item'), has = e.target.value.trim() !== '';
    card.querySelector('.si-variants').style.display = has ? '' : 'none';
    var ss = card.querySelector('.si-simple-stock'); if (ss) ss.style.display = has ? 'none' : '';
  }
});
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
