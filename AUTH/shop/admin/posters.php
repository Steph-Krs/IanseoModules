<?php
/**
 * admin/posters.php — the PDF documents of the points of sale, made with the core's classes:
 *   ?pdf=poster[&s=]  QR code poster (A4) leading to the shop, all the stands or one;
 *   ?pdf=cards[&s=]   table cards (A6), same;
 *   ?pdf=till         poster of the volunteers' till.
 * The links live where they are used: the posters and cards in the settings of the points of
 * sale (address block), the till poster on the volunteers' page. Without a document asked for,
 * this address leads to the settings.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/posters.php';
require_once dirname(__DIR__) . '/lib/admin-ui.php';

shp_schema();

$TOUR = intval($_SESSION['TourId']);
shp_settings_ensure($TOUR);
$stands = shp_stands($TOUR);

$pdf = (string) ($_GET['pdf'] ?? '');
if ($pdf === 'till') shp_pdf_send(shp_till_poster_pdf($TOUR), 'shop-till.pdf');
if ($pdf === 'poster' || $pdf === 'cards') {
    $sid = intval($_GET['s'] ?? 0);
    if ($sid > 0 && !isset($stands[$sid])) $sid = 0;
    $bytes = $pdf === 'poster' ? shp_poster_pdf($TOUR, $sid) : shp_cards_pdf($TOUR, $sid);
    shp_pdf_send($bytes, ($pdf === 'poster' ? 'shop-poster' : 'shop-cards') . ($sid > 0 ? '-' . $sid : '') . '.pdf');
}
header('Location: ' . shp_adm_url('index.php') . '#address');
exit;
