<?php
/**
 * lib/posters.php — paper QR codes of the shop: an A4 poster (general, or for one point of
 * sale) and A6 table cards, four to an A4 sheet.
 *
 * Core PDF class (Common/pdf/IanseoPdf.php): the competition header and logos come with it.
 * The QR code is drawn by TCPDF (write2DBarcode), nothing else is needed. These functions run
 * on an organiser page, where the competition is open.
 */

if (defined('SHP_POSTERS_LOADED')) return;
define('SHP_POSTERS_LOADED', true);

require_once __DIR__ . '/catalog.php';

/** Address a QR code leads to: the shop of the competition, or one point of sale. */
function shp_poster_url($tourId, $standId = 0)
{
    $s = shp_settings($tourId);
    if (!$s) return '';
    return shp_abs_url('public/index.php?k=' . $s->SgPublicKey . (intval($standId) > 0 ? '&s=' . intval($standId) : ''));
}

/** Address of the volunteers' till of a competition (the sign-in page when the phone is not signed in). */
function shp_till_url($tourId)
{
    $s = shp_settings($tourId);
    return $s ? shp_abs_url('staff/index.php?k=' . $s->SgPublicKey) : '';
}

/** Address of the public screen of the orders (all the points of sale, or one). */
function shp_board_url($tourId, $standId = 0)
{
    $s = shp_settings($tourId);
    return $s ? shp_abs_url('public/board.php?k=' . $s->SgPublicKey . (intval($standId) > 0 ? '&s=' . intval($standId) : '')) : '';
}

/** QR code of an address at ($x, $y), $size mm wide, black on white. */
function shp_pdf_qr($pdf, $url, $x, $y, $size)
{
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($x - 3, $y - 3, $size + 6, $size + 6, 'F');
    $pdf->write2DBarcode($url, 'QRCODE,M', $x, $y, $size, $size, array(
        'border' => 0, 'vpadding' => 0, 'hpadding' => 0, 'fgcolor' => array(0, 0, 0), 'bgcolor' => false,
        'module_width' => 1, 'module_height' => 1), 'N');
}

/** Text centred in a block of width $w at ($x, $y); returns the height used. */
function shp_pdf_centered($pdf, $text, $x, $y, $w, $size, $style = '', array $color = array(0, 0, 0))
{
    $pdf->SetFont($pdf->FontStd, $style, $size);
    $pdf->SetTextColor($color[0], $color[1], $color[2]);
    $pdf->SetXY($x, $y);
    $pdf->MultiCell($w, $size * 0.45, $text, 0, 'C', false, 1, $x, $y, true, 0, false, true, 0, 'T');
    $h = $pdf->GetY() - $y;
    $pdf->SetTextColor(0, 0, 0);
    return $h;
}

/**
 * A4 poster "Order from your phone" with the QR code. $standId 0 = general poster (lists the
 * points of sale open to phone orders), else the one of that point of sale.
 * Returns the PDF bytes.
 */
function shp_poster_pdf($tourId, $standId = 0)
{
    require_once('Common/pdf/IanseoPdf.php');
    $tourId = intval($tourId); $standId = intval($standId);
    $url = shp_poster_url($tourId, $standId);
    $stand = $standId > 0 ? shp_stand($standId) : null;
    if ($stand && intval($stand->SdTournament) !== $tourId) $stand = null;

    $pdf = new IanseoPdf(shp_t('ShSetPosterTitle'), true);
    $pdf->setPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $left = IanseoPdf::sideMargin;
    $w = $pdf->getPageWidth() - 2 * $left;
    $blue = array(2, 84, 168);

    $y = 36;
    $y += shp_pdf_centered($pdf, shp_t('ShSetPosterTitle'), $left, $y, $w, 34, 'B', $blue) + 6;
    if ($stand) {
        $y += shp_pdf_centered($pdf, (string) $stand->SdName, $left, $y, $w, 28, 'B') + 4;
    } else {
        $names = array();
        foreach (shp_stands($tourId) as $s) if (intval($s->SdOnline) === 1) $names[] = (string) $s->SdName;
        if ($names) $y += shp_pdf_centered($pdf, implode('  -  ', $names), $left, $y, $w, 20, 'B') + 4;
    }
    $y += shp_pdf_centered($pdf, shp_t('ShSetPosterScan'), $left, $y, $w, 20, '', array(60, 60, 60)) + 8;

    $qr = 95;
    shp_pdf_qr($pdf, $url, ($pdf->getPageWidth() - $qr) / 2, $y, $qr);
    $y += $qr + 14;

    foreach (array('ShSetPosterStep1', 'ShSetPosterStep2', 'ShSetPosterStep3') as $k) {
        $y += shp_pdf_centered($pdf, shp_t($k), $left, $y, $w, 18, 'B', $blue) + 3;
    }
    $pdf->SetFont($pdf->FontStd, '', 9);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->SetXY($left, $pdf->getPageHeight() - 16);
    $pdf->Cell($w, 5, $url, 0, 0, 'C');
    return $pdf->Output('', 'S');
}

/**
 * A4 poster for the volunteers, kept at the stand: the QR code opens the till on a phone already
 * in the team, or its sign-in page. Returns the PDF bytes.
 */
function shp_till_poster_pdf($tourId)
{
    require_once('Common/pdf/IanseoPdf.php');
    $tourId = intval($tourId);
    $url = shp_till_url($tourId);
    $pdf = new IanseoPdf(shp_t('ShSetTillTitle'), true);
    $pdf->setPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $left = IanseoPdf::sideMargin;
    $w = $pdf->getPageWidth() - 2 * $left;
    $blue = array(2, 84, 168);
    $y = 36;
    $y += shp_pdf_centered($pdf, shp_t('ShSetTillTitle'), $left, $y, $w, 34, 'B', $blue) + 6;
    $y += shp_pdf_centered($pdf, shp_t('ShSetTillScan'), $left, $y, $w, 20, '', array(60, 60, 60)) + 8;
    $qr = 95;
    shp_pdf_qr($pdf, $url, ($pdf->getPageWidth() - $qr) / 2, $y, $qr);
    $y += $qr + 14;
    foreach (array('ShSetTillStep1', 'ShSetTillStep2', 'ShSetTillStep3') as $k) {
        $y += shp_pdf_centered($pdf, shp_t($k), $left, $y, $w, 14, 'B', $blue) + 4;
    }
    $pdf->SetFont($pdf->FontStd, '', 9);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->SetXY($left, $pdf->getPageHeight() - 16);
    $pdf->Cell($w, 5, $url, 0, 0, 'C');
    return $pdf->Output('', 'S');
}

/**
 * Table cards: A6 portrait, four to an A4 sheet (2 x 2) with cut lines. Same QR code on the
 * four cards; $standId 0 = the whole shop. No page header: the competition name is on the card.
 * Returns the PDF bytes.
 */
function shp_cards_pdf($tourId, $standId = 0)
{
    require_once('Common/pdf/IanseoPdf.php');
    $tourId = intval($tourId); $standId = intval($standId);
    $url = shp_poster_url($tourId, $standId);
    $stand = $standId > 0 ? shp_stand($standId) : null;
    if ($stand && intval($stand->SdTournament) !== $tourId) $stand = null;

    $pdf = new IanseoPdf(shp_t('ShSetCardsTitle'), true);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->AddPage();
    $cw = $pdf->getPageWidth() / 2;
    $ch = $pdf->getPageHeight() / 2;
    $blue = array(2, 84, 168);

    // Cut lines first, so that the cards are drawn over them.
    $pdf->SetDrawColor(160, 160, 160);
    $pdf->SetLineStyle(array('width' => 0.2, 'dash' => '2,2'));
    $pdf->Line($cw, 0, $cw, $pdf->getPageHeight());
    $pdf->Line(0, $ch, $pdf->getPageWidth(), $ch);
    $pdf->SetLineStyle(array('width' => 0.2, 'dash' => 0));

    for ($i = 0; $i < 4; $i++) {
        $x = ($i % 2) * $cw;
        $y = intdiv($i, 2) * $ch;
        $pad = 8;
        $iw = $cw - 2 * $pad;
        $yy = $y + 10;
        $yy += shp_pdf_centered($pdf, (string) $pdf->Name, $x + $pad, $yy, $iw, 9, '', array(110, 110, 110)) + 5;
        $yy += shp_pdf_centered($pdf, shp_t('ShSetCardTitle'), $x + $pad, $yy, $iw, 21, 'B', $blue) + 3;
        if ($stand) $yy += shp_pdf_centered($pdf, (string) $stand->SdName, $x + $pad, $yy, $iw, 15, 'B') + 3;
        $qr = 66;
        shp_pdf_qr($pdf, $url, $x + ($cw - $qr) / 2, $yy + 3, $qr);
        $yy += $qr + 11;
        shp_pdf_centered($pdf, shp_t('ShSetCardScan'), $x + $pad, $yy, $iw, 12, '', array(60, 60, 60));
    }
    return $pdf->Output('', 'S');
}

/** Sends PDF bytes to the browser, shown inline, and ends the script. */
function shp_pdf_send($bytes, $filename)
{
    $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $filename);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));   // bytes on purpose: an HTTP length
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}
