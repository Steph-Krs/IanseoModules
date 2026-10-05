<?php
/**
 * public/shop.php — the shop, competitor side.
 *
 * Booking of items (refreshments, meals, souvenirs, accommodation, access…) for a competition.
 * Everything is checked again by the server (stock, limit, opening): this screen informs and
 * offers, the engine (lib/shop.php) decides.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/competition.php';
require_once dirname(__DIR__) . '/lib/shop.php';

$archer = bk_require_archer();

$tourId = intval($_GET['t'] ?? $_POST['t'] ?? 0);
$cfg    = bk_comp_config($tourId);
$rs     = $tourId ? safe_r_sql("SELECT ToName, ToWhere, ToWhenFrom, ToWhenTo FROM Tournament WHERE ToId = $tourId") : null;
$tour   = $rs ? safe_fetch($rs) : null;

// A closed competition (level 1) using the shop is not in the calendar: its shop is for its
// own participants (entered in ianseo), or whoever already ordered there.
$member = true;
if ($tourId && intval($cfg->BcPublishLevel ?? 1) === 1) {
    $l = StrSafe_DB(bk_clean_licence($archer->BaLicence));
    $member = (bool) safe_fetch(safe_r_sql("SELECT 1 FROM Entries WHERE EnTournament = $tourId AND EnCode = $l
        UNION SELECT 1 FROM BookingShopOrders WHERE SoTournament = $tourId AND SoLicence = $l LIMIT 1"));
}
if (!$tourId || !$tour || !bk_shop_has_items($tourId) || !bk_comp_payments_on($cfg) || !$member) {
    bk_head(bk_t('Shop'), 'card');
    echo '<div class="bk-card"><h1>' . bk_e(bk_t('ShopUnavailable')) . '</h1>'
       . bk_msg('err', bk_t('ShopNone'))
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">' . bk_e(bk_t('NavMyRegs')) . '</a></p></div>';
    bk_foot();
    exit;
}
bk_money_tour($tourId);

$open = bk_shop_open($cfg);
$errs = array();   // "item_variant" => message
$ok   = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['go'] ?? '') === '1') {
    if (!bk_csrf_check()) {
        $errs['_'] = bk_t('SessionExpired');
    } elseif (!$open) {
        $errs['_'] = bk_t('ShopClosedComp');
    } else {
        foreach ((array) ($_POST['q'] ?? array()) as $iid => $vars) {
            foreach ((array) $vars as $vid => $qty) {
                $res = bk_shop_order_set($tourId, $archer->BaLicence, $iid, $vid, $qty);
                if (empty($res['ok'])) $errs[intval($iid) . '_' . intval($vid)] = $res['msg'] ?? bk_t('Refused');
            }
        }
        bk_log('SHOP_ORDER', $archer->BaLicence);
        $ok = true;
    }
}

$items = bk_shop_items($tourId, true, $archer->BaLicence);
$total = bk_shop_order_total($tourId, $archer->BaLicence);

// Grouped by section, in order of appearance.
$bySection = array();
foreach ($items as $it) $bySection[$it['section']][] = $it;

/** One quantity line: stock left and the input, then the error of this line if any. */
function sh_line($name, $price, $rem, $mine, $open, $err, $label = null)
{
    $soldOut = ($rem === 0 && $mine === 0);
    $stock = $rem === null ? '' : ($soldOut ? bk_t('SoldOut') : bk_t($rem > 1 ? 'LeftMany' : 'LeftOne', $rem));
    return '<div class="bk-shop-line">'
        . ($label !== null ? '<span class="bk-shop-vname">' . bk_e($label) . '</span>' : '')
        . '<span class="bk-shop-stock">' . bk_e($stock) . '</span>'
        . '<input class="bk-shop-q" type="number" name="' . $name . '" value="' . intval($mine) . '" min="0"'
        . ($rem === null ? '' : ' max="' . ($rem + $mine) . '"') . ' data-price="' . $price . '"'
        . ((!$open || $soldOut) ? ' disabled' : '') . '></div>'
        . ($err !== '' ? '<p class="bk-shop-err">' . bk_e($err) . '</p>' : '');
}

bk_head(bk_t('Shop'));
echo '<p class="bk-back"><a href="' . bk_e(bk_public_url('registrations.php')) . '">' . bk_e(bk_t('BackMyRegs')) . '</a></p>'
    . '<h1>' . bk_e(bk_t('Shop')) . '</h1>'
    . '<div class="bk-block" style="margin-bottom:14px"><h2>' . bk_e($tour->ToName) . '</h2>'
    . '<p class="bk-meta"><span>' . bk_e(bk_date_range($tour->ToWhenFrom, $tour->ToWhenTo)) . '</span>'
    . ($tour->ToWhere ? '<span>' . bk_e($tour->ToWhere) . '</span>' : '') . '</p></div>';

if ($ok && !array_filter($errs, function ($k) { return $k !== '_'; }, ARRAY_FILTER_USE_KEY)) {
    echo bk_msg('ok', bk_t('ShopOrderSaved'));
} elseif ($ok) {
    echo bk_msg('err', bk_t('ShopOrderPartial'));
}
if (!empty($errs['_'])) echo bk_msg('err', $errs['_']);
if (!$open) echo bk_msg('err', bk_t('ShopClosedNoChange'));

echo '<form method="post" id="bkshopform">' . bk_csrf_field()
    . '<input type="hidden" name="t" value="' . intval($tourId) . '"><input type="hidden" name="go" value="1">';
foreach ($bySection as $section => $list) {
    echo '<div class="bk-block bk-shop-sec"><h2>' . bk_e($section !== '' ? $section : bk_t('ShopItems')) . '</h2>';
    foreach ($list as $it) {
        echo '<div class="bk-shop-item"><div class="bk-shop-h"><span class="bk-shop-name">' . bk_e($it['label']) . '</span>'
            . '<span class="bk-shop-price">' . bk_e(bk_eur($it['price'])) . '</span></div>'
            . ($it['description'] !== '' ? '<p class="bk-hint">' . bk_e($it['description']) . '</p>' : '')
            . ($it['maxper'] > 0 ? '<p class="bk-hint">' . bk_e(bk_t('ShopMaxPerHint', intval($it['maxper']))) . '</p>' : '');
        if (empty($it['variants'])) {
            echo sh_line('q[' . $it['id'] . '][0]', $it['price'], $it['remaining'], $it['mine'], $open, $errs[$it['id'] . '_0'] ?? '');
        } else {
            echo '<div class="bk-shop-opt">' . bk_e($it['option']) . '</div>';
            foreach ($it['variants'] as $v) {
                echo sh_line('q[' . $it['id'] . '][' . $v['id'] . ']', $it['price'], $v['remaining'], $v['mine'], $open,
                    $errs[$it['id'] . '_' . $v['id']] ?? '', $v['label']);
            }
        }
        echo '</div>';
    }
    echo '</div>';
}
echo '<div class="bk-shop-bar"><span>' . bk_e(bk_t('ShopSubtotal')) . ' <b id="bk-shop-total">' . bk_e(bk_eur($total)) . '</b></span>'
    . ($open ? '<button type="submit" class="bk-btn bk-btn-primary">' . bk_e(bk_t('ShopValidate')) . '</button>' : '')
    . '</div></form>';

$seps = bk_number_seps();
$fmt = array('cur' => bk_currency($tourId), 'dec' => $seps['dec'], 'th' => $seps['thousands']);
?>
<script>
(function () {
  var form = document.getElementById('bkshopform'); if (!form) return;
  var F = <?= json_encode($fmt, JSON_UNESCAPED_UNICODE) ?>;
  var qs = form.querySelectorAll('.bk-shop-q'), out = document.getElementById('bk-shop-total');
  function eur(n) {
    var p = Math.abs(n).toFixed(2).split('.');
    return (n < 0 ? '−' : '') + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, F.th) + F.dec + p[1] + ' ' + F.cur;
  }
  function calc() {
    var t = 0;
    Array.prototype.forEach.call(qs, function (i) { t += (parseInt(i.value, 10) || 0) * parseFloat(i.dataset.price || 0); });
    out.textContent = eur(t);
  }
  Array.prototype.forEach.call(qs, function (i) { i.addEventListener('input', calc); });
})();
</script>
<?php
bk_foot();
