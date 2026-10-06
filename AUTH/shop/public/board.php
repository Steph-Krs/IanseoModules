<?php
/**
 * public/board.php — public screen of the orders, for a TV or a tablet at a stand, in three
 * columns as in a fast-food restaurant: received (with the state of the payment), being prepared
 * (with the time announced, when there is one), ready. Each title carries the number of orders of
 * its column; a wide screen holds 15 orders per column, a note says when some are not shown.
 * Next to every number: the given name and initial of a licensee ("Stéphane K") or the nickname
 * of a visitor (public/api/board.php); nothing else names a customer.
 *
 *   ?k=<public key>&s=<stand id>      (no s: every stand together)
 *
 * Refreshes by itself (public/api/board.php), readable from 5 metres, asks the screen not to go
 * to sleep when the browser allows it.
 */

require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/public.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');

$key = (string) ($_GET['k'] ?? '');
$tourId = shp_tour_by_key($key);
if ($tourId <= 0 || !shp_enabled($tourId)) {
    shp_page_message(shp_t('ShUnavailableTitle'), shp_t('ShUnavailable'), 'info', 404);
}
$standId = intval($_GET['s'] ?? 0);
$stand = $standId > 0 ? shp_stand($standId) : null;
if ($standId > 0 && (!$stand || intval($stand->SdTournament) !== $tourId || intval($stand->SdActive) !== 1)) {
    shp_page_message(shp_t('ShUnavailableTitle'), shp_t('ShErrStand'), 'info', 404);
}
$title = $stand ? (string) $stand->SdName : shp_cus_tour_name($tourId);

/** One column: its title with the number of orders it holds, the list, and the note shown when
    the screen cannot hold them all. */
function shp_board_col($class, $id, $listClass, $title)
{
    return '    <section class="cus-board-col ' . $class . '"><h2>' . shp_e($title)
        . ' <span class="cus-board-count" id="' . $id . '-n" hidden></span></h2>'
        . '<div class="' . $listClass . '" id="' . $id . '"' . ($id === 'cb-ready' ? ' aria-live="polite"' : '') . '></div>'
        . '<p class="cus-board-more" id="' . $id . '-more" hidden>' . shp_e(shp_t('ShCusBoardMore')) . '</p></section>' . "\n";
}

$cfg = shp_page_cfg($tourId, 'board-' . $key, array(
    'api' => shp_url('public/api/board.php?k=' . rawurlencode($key) . '&s=' . $standId),
));
shp_head($title, array('layout' => 'app', 'css' => array('shop.css')));
echo '  <main class="shp-main cus-board" id="cus-board">' . "\n"
    . '    <h1 class="cus-board-title">' . shp_e($title) . '</h1>' . "\n"
    . '    <div class="cus-board-cols">' . "\n"
    . shp_board_col('cus-board-wait', 'cb-wait', 'cus-board-list', shp_t('ShCusBoardWaiting'))
    . shp_board_col('cus-board-prep', 'cb-prep', 'cus-board-list', shp_t('ShCusBoardPrep'))
    . shp_board_col('cus-board-ready', 'cb-ready', 'cus-board-nums', shp_t('ShCusBoardReady'))
    . '    </div>' . "\n"
    . '  </main>' . "\n"
    . '  ' . shp_json_script('shp-texts', shp_ts(array('ShCusBoardNone', 'ShCusBoardPaid', 'ShCusBoardToPay', 'ShCusBoardPickup',
        'ShCusBoardTab', 'ShCusBoardAt', 'ShPosMinutes')) + shp_base_texts())
    . '  ' . shp_json_script('shp-cfg', $cfg);
shp_foot(array('board.js'));
