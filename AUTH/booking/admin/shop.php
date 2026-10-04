<?php
/**
 * admin/shop.php — the competition's shop (organiser).
 * Free sections, simple items or items with variants (own stock), limit per person, own
 * deadline. The engine (lib/shop.php) has the last word on the server side.
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
        $err = bk_t('SessionExpired');
    } else {
        // Saves the items (upsert), their variants, and deletes what was removed from the screen
        // (reconciled by id).
        $keptItems = array();
        $order = 0;
        foreach ((array) ($_POST['item'] ?? array()) as $row) {
            if (!is_array($row)) continue;
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') continue;                       // empty line ignored
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
            // deletes the variants removed from this item
            $existing = array();
            $rs = safe_r_sql("SELECT SvId FROM BK_ShopVariants WHERE SvItem = " . intval($itemId));
            while ($r = safe_fetch($rs)) $existing[] = intval($r->SvId);
            foreach (array_diff($existing, $keptVars) as $del) bk_shop_variant_delete($del);
        }
        // deletes the items removed from the competition
        $existing = array();
        $rs = safe_r_sql("SELECT SiId FROM BK_ShopItems WHERE SiTournament = $TOUR");
        while ($r = safe_fetch($rs)) $existing[] = intval($r->SiId);
        foreach (array_diff($existing, $keptItems) as $del) bk_shop_item_delete($TOUR, $del);

        bk_shop_set_deadline($TOUR, $_POST['shop_until'] ?? '');
        $msg = bk_t('AshSaved');
    }
}

$cfg   = bk_comp_config($TOUR);
$items = bk_shop_items($TOUR);
$open  = bk_shop_open($cfg);

$sections = array();
foreach ($items as $it) if ($it['section'] !== '' && !in_array($it['section'], $sections, true)) $sections[] = $it['section'];

/** datetime-local field from a DATETIME column. */
function bk_shop_dtval($v)
{
    $v = trim((string) $v);
    return ($v === '' || strpos($v, '0000') === 0) ? '' : str_replace(' ', 'T', substr($v, 0, 16));   // bytes: ASCII date
}

/** Amount in a text field (2 decimals, the language's separator); empty when null/''. */
function bk_amt2($v)
{
    return ($v === '' || $v === null) ? '' : number_format((float) $v, 2, bk_number_seps()['dec'], '');
}

/** One variant (server rendering AND script template with the '__i__' / '__v__' indexes). */
function bk_var_row($iidx, $vidx, $v)
{
    $v = array_merge(array('id' => 0, 'label' => '', 'stock' => 0), (array) $v);
    $n = 'item[' . $iidx . '][var][' . $vidx . ']';
    return '<div class="si-var"><input type="hidden" name="' . $n . '[id]" value="' . intval($v['id']) . '">'
        . '<input type="text" name="' . $n . '[label]" value="' . bk_e($v['label']) . '" placeholder="' . bk_e(bk_t('AshVarPh')) . '">'
        . '<input type="number" min="0" name="' . $n . '[stock]" value="' . intval($v['stock']) . '" title="' . bk_e(bk_t('AshStock')) . '">'
        . '<button type="button" class="si-vdel" title="' . bk_e(bk_t('AshVarDel')) . '" aria-label="' . bk_e(bk_t('AshVarDel')) . '">✕</button></div>';
}

/** One item card (server rendering AND script template with the '__i__' index). */
function bk_item_card($idx, $it, $cur)
{
    $it = array_merge(array('id' => 0, 'section' => '', 'label' => '', 'description' => '',
        'price' => '', 'stock' => 0, 'maxper' => 0, 'option' => '', 'active' => 1, 'variants' => array()), $it);
    $hasOpt = trim((string) $it['option']) !== '';
    $n = 'item[' . $idx . ']';
    $field = function ($cls, $label, $input) {
        return '<label class="si-f' . $cls . '"><span>' . bk_e($label) . '</span>' . $input . '</label>';
    };
    $vars = '';
    foreach ((array) $it['variants'] as $vid => $v) $vars .= bk_var_row($idx, $vid, $v);
    return '<div class="shop-item" data-i="' . $idx . '"><input type="hidden" name="' . $n . '[id]" value="' . intval($it['id']) . '">'
        . '<div class="si-row">'
        . $field('', bk_t('AshSection'), '<input type="text" list="shop-sections" name="' . $n . '[section]" value="' . bk_e($it['section']) . '" placeholder="' . bk_e(bk_t('AshSectionPh')) . '">')
        . $field(' si-grow', bk_t('AshItem'), '<input type="text" name="' . $n . '[label]" value="' . bk_e($it['label']) . '" placeholder="' . bk_e(bk_t('AshItemPh')) . '">')
        . $field('', bk_t('AshPrice', $cur), '<input type="text" name="' . $n . '[price]" value="' . bk_e(bk_amt2($it['price'])) . '" size="6">')
        . '</div>'
        . $field(' si-grow', bk_t('AshDescr'), '<input type="text" name="' . $n . '[description]" value="' . bk_e($it['description']) . '">')
        . '<div class="si-row">'
        . $field(' si-grow', bk_t('AshOptions'), '<input type="text" class="si-opt" name="' . $n . '[option]" value="' . bk_e($it['option']) . '" placeholder="' . bk_e(bk_t('AshOptionsPh')) . '">')
        . '<label class="si-f si-simple-stock"' . ($hasOpt ? ' style="display:none"' : '') . '><span>' . bk_e(bk_t('AshStock')) . '</span>'
        . '<input type="number" min="0" name="' . $n . '[stock]" value="' . intval($it['stock']) . '"></label>'
        . $field('', bk_t('AshMaxPer'), '<input type="number" min="0" name="' . $n . '[maxper]" value="' . intval($it['maxper']) . '">')
        . '</div>'
        . '<div class="si-variants"' . ($hasOpt ? '' : ' style="display:none"') . '><div class="si-vhead">' . bk_e(bk_t('AshVariants')) . '</div>'
        . '<div class="si-vlist">' . $vars . '</div>'
        . '<button type="button" class="bk-btn bk-add si-addvar">' . bk_e(bk_t('AshAddVar')) . '</button></div>'
        . '<div class="si-foot"><label class="si-chk"><input type="checkbox" name="' . $n . '[active]" value="1"' . ($it['active'] ? ' checked' : '') . '> '
        . bk_e(bk_t('AshVisible')) . '</label><button type="button" class="bk-btn si-del">' . bk_e(bk_t('AshDelItem')) . '</button></div></div>';
}

$cur = bk_currency($TOUR);
$PAGE_TITLE = bk_t('AshTitle');
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
<?php
$cards = '';
foreach ($items as $idx => $it) $cards .= bk_item_card($idx, $it, $cur);
$opts = '';
foreach ($sections as $s) $opts .= '<option value="' . bk_e($s) . '"></option>';

echo '<div id="bkshop"><h1>' . bk_e(bk_t('Shop')) . '</h1><p class="bk-lead">' . bk_e(bk_t('AshLead')) . '</p>'
    . ($msg ? '<div class="bk-msg bk-ok">' . bk_e($msg) . '</div>' : '')
    . ($err ? '<div class="bk-msg bk-err">' . bk_e($err) . '</div>' : '')
    . (bk_comp_payments_on($cfg) ? '' : '<div class="bk-msg bk-err">'
        . bk_t('AshClosedComp', bk_e($CFG->ROOT_DIR . 'Modules/Custom/AUTH/booking/admin/competition.php')) . '</div>')
    . '<form method="post">' . bk_csrf_field()
    . '<div class="bk-sec"><h2>' . bk_e(bk_t('AshAvailability')) . '</h2><div class="si-row">'
    . '<label class="si-f"><span>' . bk_e(bk_t('AshUntil')) . '</span>'
    . '<input type="datetime-local" name="shop_until" value="' . bk_e(bk_shop_dtval($cfg->BcShopUntil ?? '')) . '"></label>'
    . '<span class="bk-tag ' . ($open ? 'bk-on' : 'bk-off') . '">' . bk_e(bk_t($open ? 'AshOpen' : 'AshClosed')) . '</span></div>'
    . '<p class="bk-hint">' . bk_e(bk_t('AshUntilHint')) . '</p></div>'
    . '<div class="bk-sec"><h2>' . bk_e(bk_t('ShopItems')) . '</h2><div id="shop-items">' . $cards . '</div>'
    . '<button type="button" class="bk-btn bk-add" onclick="shopAddItem()">' . bk_e(bk_t('AshAddItem')) . '</button>'
    . '<p class="bk-hint">' . bk_e(bk_t('AshOptionHint')) . '</p></div>'
    . '<button type="submit" class="bk-btn">' . bk_e(bk_t('AshSave')) . '</button></form>'
    . '<datalist id="shop-sections">' . $opts . '</datalist>'
    . '<template id="shop-item-tpl">' . bk_item_card('__i__', array(), $cur) . '</template>'
    . '<template id="shop-var-tpl">' . bk_var_row('__i__', '__v__', array()) . '</template></div>';
?>
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
  else if (el.closest('.si-del')) { e.preventDefault(); if (confirm(<?= json_encode(bk_t('AshDelConfirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>)) el.closest('.shop-item').remove(); }
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
<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
