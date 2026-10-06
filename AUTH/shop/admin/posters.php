<?php
/**
 * admin/posters.php — QR code posters (A4) and table cards (A6) leading to the shop of the open
 * competition. The documents are PDF files made with the core's classes; this page only lists
 * them.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/posters.php';
require_once dirname(__DIR__) . '/lib/admin-ui.php';

shp_schema();

$TOUR = intval($_SESSION['TourId']);
$set = shp_settings_ensure($TOUR);
$stands = shp_stands($TOUR);

$pdf = (string) ($_GET['pdf'] ?? '');
if ($pdf === 'till') shp_pdf_send(shp_till_poster_pdf($TOUR), 'shop-till.pdf');
if ($pdf === 'poster' || $pdf === 'cards') {
    $sid = intval($_GET['s'] ?? 0);
    if ($sid > 0 && !isset($stands[$sid])) $sid = 0;
    $bytes = $pdf === 'poster' ? shp_poster_pdf($TOUR, $sid) : shp_cards_pdf($TOUR, $sid);
    shp_pdf_send($bytes, ($pdf === 'poster' ? 'shop-poster' : 'shop-cards') . ($sid > 0 ? '-' . $sid : '') . '.pdf');
}

/** A link to one PDF, with the core's PDF icon next to its text. */
function shp_adm_pdf_link($kind, $standId, $label)
{
    global $CFG;
    return '<a class="sa-pdf" target="_blank" href="' . shp_e(shp_adm_url('posters.php') . '?pdf=' . $kind . ($standId > 0 ? '&s=' . intval($standId) : '')) . '">'
        . '<img src="' . shp_e($CFG->ROOT_DIR . 'Common/Images/pdf_small.gif') . '" alt="" width="16" height="16"> ' . shp_e($label) . '</a>';
}

$online = array();
foreach ($stands as $sid => $s) if (intval($s->SdOnline) === 1) $online[$sid] = $s;

$rows = '<tr><td><b>' . shp_e(shp_t('ShSetPostersAll')) . '</b></td><td>' . shp_adm_pdf_link('poster', 0, shp_t('ShSetPosterA4'))
    . '</td><td>' . shp_adm_pdf_link('cards', 0, shp_t('ShSetCardsA6')) . '</td></tr>';
foreach ($online as $sid => $s) {
    $rows .= '<tr><td>' . shp_e($s->SdName) . '</td><td>' . shp_adm_pdf_link('poster', $sid, shp_t('ShSetPosterA4'))
        . '</td><td>' . shp_adm_pdf_link('cards', $sid, shp_t('ShSetCardsA6')) . '</td></tr>';
}

$body = '<div class="sa-card"><table class="sa-table"><thead><tr><th>' . shp_e(shp_t('ShSetPostersFor')) . '</th><th>'
    . shp_e(shp_t('ShSetPosterA4')) . '</th><th>' . shp_e(shp_t('ShSetCardsA6')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
    . ($online ? '' : '<p class="sa-hint">' . shp_e(shp_t('ShSetPostersNoStand')) . '</p>')
    . '<p class="sa-hint">' . shp_e(shp_t('ShSetPostersHint')) . '</p></div>';

// The volunteers' till: a poster for the stand, the address to keep on the phone.
$tillUrl = shp_till_url($TOUR);
$body .= '<div class="sa-card"><h2>' . shp_e(shp_t('ShSetTillTitle')) . '</h2><p class="sa-hint">' . shp_e(shp_t('ShSetTillHint')) . '</p>'
    . '<p>' . shp_adm_pdf_link('till', 0, shp_t('ShSetTillPoster')) . '</p>'
    . '<p><code class="sa-url">' . shp_e($tillUrl) . '</code></p></div>';

// The public screen of the orders, for a television at the stand.
$boards = '<li><a target="_blank" href="' . shp_e(shp_board_url($TOUR)) . '">' . shp_e(shp_t('ShSetPostersAll')) . '</a></li>';
foreach ($stands as $sid => $s) {
    if ((string) $s->SdMode === 'prep') $boards .= '<li><a target="_blank" href="' . shp_e(shp_board_url($TOUR, $sid)) . '">' . shp_e($s->SdName) . '</a></li>';
}
$body .= '<div class="sa-card"><h2>' . shp_e(shp_t('ShSetBoardTitle')) . '</h2><p class="sa-hint">' . shp_e(shp_t('ShSetBoardHint')) . '</p>'
    . '<ul class="sa-links">' . $boards . '</ul></div>';

$PAGE_TITLE = shp_t('MnuPosters') . ' — ' . shp_t('MnuTitle');
$JS_SCRIPT = shp_adm_assets();
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

echo '<div id="shpadm"><h1>' . shp_e(shp_t('ShSetPostersTitle')) . '</h1>' . shp_adm_nav('posters.php')
    . '<p class="sa-lead">' . shp_e(shp_t('ShSetPostersLead')) . '</p>'
    . (intval($set->SgEnabled) === 1 ? '' : shp_adm_msg('warn', array(shp_t('ShSetPostersOff'))))
    . $body . '</div>';

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
