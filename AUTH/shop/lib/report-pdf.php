<?php
/**
 * lib/report-pdf.php — paper documents of the points of sale: the cash closing of a stand for a
 * day, and the summary of the whole competition.
 *
 * Core PDF class (Common/pdf/IanseoPdf.php): competition header, logos, page numbers; the
 * drawing helpers of the payments' documents (booking/lib/ledger-pdf.php) are reused. The
 * functions run on an organiser page, where the competition is open. Volunteers are named
 * while they exist; once erased (the day after the competition) they print as "Volunteer no. X"
 * — which is why the page reminds the organiser to take the nominal closing before that date.
 *
 * The core fonts have no U+2212, ellipsis, arrows or superscript letters: shp_pdf_text() maps
 * them to plain characters, and amounts go through bk_pdf_eur() (ASCII minus).
 */

if (defined('SHP_REPORT_PDF_LOADED')) return;
define('SHP_REPORT_PDF_LOADED', true);

require_once __DIR__ . '/report.php';
require_once dirname(__DIR__, 2) . '/booking/lib/ledger-pdf.php';

/** A text for the PDF: characters the base fonts lack are replaced. */
function shp_pdf_text($s)
{
    return str_replace(array('−', '…', '→', '←', 'ᵉ', "\u{00A0}", "\u{202F}", "\u{2009}"),
        array('-', '...', '>', '<', 'e', ' ', ' ', ' '), (string) $s);
}

/** An amount for the PDF. */
function shp_pdf_eur($n)
{
    return shp_pdf_text(bk_pdf_eur($n));
}

/**
 * A table: $cols [[label, width, align]], $rows [[cell, …]] (text), $total an optional last row in
 * bold. The header is printed again after a page break.
 */
function shp_pdf_table($pdf, array $cols, array $rows, array $total = array())
{
    $head = array();
    foreach ($cols as $c) $head[] = array($c[1], shp_pdf_text($c[0]), $c[2] ?? 'L', 'B');
    bk_pdf_row($pdf, $head);
    foreach ($rows as $r) {
        if ($pdf->GetY() > $pdf->getPageHeight() - 24) {
            $pdf->AddPage();
            bk_pdf_row($pdf, $head);
        }
        $cells = array();
        foreach ($cols as $i => $c) $cells[] = array($c[1], shp_pdf_text($r[$i] ?? ''), $c[2] ?? 'L');
        bk_pdf_row($pdf, $cells);
    }
    if ($total) {
        $cells = array();
        foreach ($cols as $i => $c) $cells[] = array($c[1], shp_pdf_text($total[$i] ?? ''), $c[2] ?? 'L', 'B');
        bk_pdf_row($pdf, $cells, 'T');
    }
}

/** Width of the writing area. */
function shp_pdf_width($pdf)
{
    return $pdf->getPageWidth() - 2 * IanseoPdf::sideMargin;
}

/** Title block, with the date of issue under it. */
function shp_pdf_head($pdf, $title, $sub)
{
    bk_pdf_title($pdf, shp_pdf_text($title), shp_pdf_text($sub . ' · ' . shp_t('ShRepIssued', bk_now_local_text())));
}

/** A line "label ........ amount" in the width of the page, label left, value right. */
function shp_pdf_pair($pdf, $label, $value, $bold = false, $border = '', $valueWidth = 40)
{
    $w = shp_pdf_width($pdf);
    $style = $bold ? 'B' : '';
    bk_pdf_row($pdf, array(array($w - $valueWidth, shp_pdf_text($label), 'L', $style), array($valueWidth, shp_pdf_text($value), 'R', $style)), $border);
}

/** Cells of a cash group in the order: collected, refunded, cancelled/rejected, net. */
function shp_pdf_cash_cells(array $g)
{
    return array(shp_pdf_eur($g['payment']), shp_pdf_eur($g['refund']), shp_pdf_eur($g['other']), shp_pdf_eur($g['net']));
}

/** Sales rows as table rows grouped under their stand's name; [rows, total qty, total amount]. */
function shp_pdf_sales_rows(array $sales, array $stands, $withStand)
{
    $rows = array(); $q = 0; $a = 0.0; $last = null;
    foreach ($sales as $s) {
        if ($withStand && $s['stand'] !== $last) {
            $last = $s['stand'];
            $rows[] = array(shp_rep_stand_name($stands[$s['stand']] ?? null), '', '', '');
        }
        $rows[] = array(($withStand ? '   ' : '') . $s['label'], shp_pdf_eur($s['unit']), (string) $s['qty'], shp_pdf_eur($s['amount']));
        $q += $s['qty'];
        $a = round($a + $s['amount'], 2);
    }
    return array($rows, $q, $a);
}

/**
 * Cash closing of a stand, for one day ($day '' = the whole competition). Returns the PDF bytes.
 * What it shows: collections by means, the cash expected in the till, collections by volunteer,
 * refunds, what was sold, and the signature of the person in charge of the stand.
 */
function shp_report_pdf_closing($tourId, $standId, $day)
{
    require_once('Common/pdf/IanseoPdf.php');
    $tourId = intval($tourId);
    bk_money_tour($tourId);
    $f = shp_rep_filter($tourId, $day, $standId);
    $stands = shp_stands($tourId, false);
    $stand = $stands[$f['stand']] ?? null;
    $standName = shp_rep_stand_name($stand);
    $dayText = $f['day'] !== '' ? bk_date_dmy($f['day']) : shp_t('ShRepWholeComp');

    $cash = shp_rep_cash($f);
    $sum = shp_rep_summary($f);
    $sales = shp_rep_sales($f);
    $refunds = shp_rep_refunds($f);

    $pdf = new IanseoPdf(shp_pdf_text(shp_t('ShRepClosingTitle')), true);
    $pdf->setPrintFooter(true);
    $pdf->startPageGroup();   // the footer prints "page / pages of the group"
    $pdf->AddPage();
    $w = shp_pdf_width($pdf);
    shp_pdf_head($pdf, shp_t('ShRepClosingTitle'), $standName . ' — ' . $dayText);

    // Collections by means of payment.
    bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepByMethod')));
    $wm = 36; $wn = ($w - $wm) / 4;
    $cols = array(array(shp_t('ShRepColMethod'), $wm), array(shp_t('ShRepColCollected'), $wn, 'R'), array(shp_t('ShRepColRefunded'), $wn, 'R'),
        array(shp_t('ShRepColCancelled2'), $wn, 'R'), array(shp_t('ShRepColNet'), $wn, 'R'));
    $rows = array();
    foreach ($cash['methods'] as $code => $g) $rows[] = array_merge(array(shp_rep_method_label((string) $code)), shp_pdf_cash_cells($g));
    if (!$rows) $rows[] = array(shp_t('ShRepNoMoney'), '', '', '', '');
    shp_pdf_table($pdf, $cols, $rows, array_merge(array(shp_t('ShRepTotal')), shp_pdf_cash_cells($cash['total'])));

    // Cash expected in the till.
    $c = $cash['methods']['cash'] ?? shp_rep_cash_blank();
    bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepCashExpected')));
    shp_pdf_pair($pdf, shp_t('ShRepCashIn'), shp_pdf_eur($c['payment']));
    shp_pdf_pair($pdf, shp_t('ShRepCashOut'), shp_pdf_eur($c['refund']));
    shp_pdf_pair($pdf, shp_t('ShRepCashOther'), shp_pdf_eur($c['other']));
    shp_pdf_pair($pdf, shp_t('ShRepCashToFind'), shp_pdf_eur($c['net']), true, 'T');
    $pdf->Ln(1);
    $pdf->SetFont($pdf->FontStd, '', 8);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->MultiCell(0, 4, shp_pdf_text(shp_t('ShRepCashNote')), 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);
    shp_pdf_pair($pdf, shp_t('ShRepCashCounted') . ' ' . str_repeat('.', 40), '');
    shp_pdf_pair($pdf, shp_t('ShRepCashGap') . ' ' . str_repeat('.', 40), '');

    // By volunteer.
    bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepByStaff')));
    $cols[0] = array(shp_t('ShRepColStaff'), $wm);
    $rows = array();
    foreach ($cash['staff'] as $id => $g) $rows[] = array_merge(array(shp_rep_staff_name($id)), shp_pdf_cash_cells($g));
    if (!$rows) $rows[] = array(shp_t('ShRepNoMoney'), '', '', '', '');
    shp_pdf_table($pdf, $cols, $rows);

    // Refunds.
    bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepRefundsTitle')));
    if (!$refunds) {
        bk_pdf_row($pdf, array(array($w, shp_pdf_text(shp_t('ShRepNoRefund')), 'L', 'I')), '');
    } else {
        $wd = 30; $wo = 20; $wa = 26; $wl = $w - $wd - $wo - $wa - 28 - 28;
        $rows = array();
        foreach ($refunds as $r) {
            $rows[] = array(shp_csv_when($r['when'])[0] . ' ' . shp_csv_when($r['when'])[1], $r['order'], shp_pdf_eur($r['amount']),
                shp_rep_method_label($r['method']), trim($r['label'] . ($r['cancelled'] ? ' — ' . shp_t('ShRepCancelledWord') : ''), ' —'),
                shp_rep_staff_name($r['staff']));
        }
        shp_pdf_table($pdf, array(array(shp_t('ShRepColDate'), $wd), array(shp_t('ShRepColOrder'), $wo), array(shp_t('ShRepColAmount'), $wa, 'R'),
            array(shp_t('ShRepColMethod'), 28), array(shp_t('ShRepColLabel'), $wl), array(shp_t('ShRepColStaff'), 28)), $rows);
    }

    // Sold.
    bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepSalesTitle')));
    shp_pdf_pair($pdf, shp_t('ShRepOrdersCount'), (string) $sum['orders']);
    shp_pdf_pair($pdf, shp_t('ShRepSalesTotal'), shp_pdf_eur($sum['sales']), true);
    if ($sum['tab_orders'] > 0) shp_pdf_pair($pdf, shp_t('ShRepOnTab', $sum['tab_orders']), shp_pdf_eur($sum['tab_amount']));
    if ($sum['cancelled_orders'] > 0 || $sum['cancelled_qty'] > 0) {
        shp_pdf_pair($pdf, shp_t('ShRepCancelledLine', array('orders' => $sum['cancelled_orders'], 'qty' => $sum['cancelled_qty'])),
            shp_pdf_eur($sum['cancelled_value']));
    }
    $pdf->Ln(2);
    [$rows, $q, $a] = shp_pdf_sales_rows($sales, $stands, false);
    if ($rows) {
        shp_pdf_table($pdf, array(array(shp_t('ShRepColProduct'), $w - 80), array(shp_t('ShRepColUnit'), 26, 'R'), array(shp_t('ShRepColQty'), 20, 'R'),
            array(shp_t('ShRepColAmount'), 34, 'R')), $rows, array(shp_t('ShRepTotal'), '', (string) $q, shp_pdf_eur($a)));
    }

    // Signature: name, date and signature on one row, so that it rarely needs a page of its own.
    if ($pdf->GetY() > $pdf->getPageHeight() - 52) $pdf->AddPage();
    $pdf->Ln(6);
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->Cell(0, 6, shp_pdf_text(shp_t('ShRepSignTitle')), 0, 1, 'L');
    $pdf->SetFont($pdf->FontStd, '', 9);
    $x = IanseoPdf::sideMargin;
    $y = $pdf->GetY() + 14;
    $cw = ($w - 12) / 3;
    foreach (array('ShRepSignName', 'ShRepSignDate', 'ShRepSignSignature') as $i => $key) {
        $cx = $x + $i * ($cw + 6);
        $pdf->Line($cx, $y, $cx + $cw, $y);
        $pdf->SetXY($cx, $y + 1);
        $pdf->Cell($cw, 5, shp_pdf_text(shp_t($key)), 0, 0, 'L');
    }
    return $pdf->Output('', 'S');
}

/**
 * Summary of the whole competition (or one day, one stand): sales, collections, refunds and
 * cancellations, stock, accounts still open. Returns the PDF bytes. Volunteers are not listed.
 */
function shp_report_pdf_summary($tourId, $day = '', $standId = 0)
{
    require_once('Common/pdf/IanseoPdf.php');
    $tourId = intval($tourId);
    bk_money_tour($tourId);
    $f = shp_rep_filter($tourId, $day, $standId);
    $stands = shp_stands($tourId, false);
    $sum = shp_rep_summary($f);
    $sales = shp_rep_sales($f);
    $cash = shp_rep_cash($f);
    $hours = shp_rep_hours($f);
    $stock = shp_rep_stock($tourId, $f['stand']);
    $accounts = shp_rep_open_accounts($tourId);

    $pdf = new IanseoPdf(shp_pdf_text(shp_t('ShRepSummaryTitle')), true);
    $pdf->setPrintFooter(true);
    $pdf->startPageGroup();
    $pdf->AddPage();
    $w = shp_pdf_width($pdf);
    $sub = array($f['stand'] > 0 ? shp_rep_stand_name($stands[$f['stand']] ?? null) : shp_t('ShRepAllStands'),
        $f['day'] !== '' ? bk_date_dmy($f['day']) : shp_t('ShRepWholeComp'));
    shp_pdf_head($pdf, shp_t('ShRepSummaryTitle'), implode(' — ', $sub));

    // Key figures.
    bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepKeyFigures')));
    shp_pdf_pair($pdf, shp_t('ShRepOrdersCount'), (string) $sum['orders']);
    shp_pdf_pair($pdf, shp_t('ShRepSalesTotal'), shp_pdf_eur($sum['sales']), true);
    shp_pdf_pair($pdf, shp_t('ShRepCollectedNet'), shp_pdf_eur($cash['total']['net']), true);
    shp_pdf_pair($pdf, shp_t('ShRepStillToCollect'), shp_pdf_eur(round($sum['sales'] - $cash['total']['net'], 2)));
    shp_pdf_pair($pdf, shp_t('ShRepOnTab', $sum['tab_orders']), shp_pdf_eur($sum['tab_amount']));
    shp_pdf_pair($pdf, shp_t('ShRepCancelledLine', array('orders' => $sum['cancelled_orders'], 'qty' => $sum['cancelled_qty'])),
        shp_pdf_eur($sum['cancelled_value']));
    if ($hours) {
        $top = $hours;
        usort($top, function ($a, $b) { return $b['orders'] <=> $a['orders'] ?: strcmp($a['day'], $b['day']); });
        $peaks = array();
        foreach (array_slice($top, 0, 3) as $h) {
            $peaks[] = ($f['day'] === '' ? bk_date_dmy($h['day']) . ' ' : '') . $h['hour'] . ':00 (' . $h['orders'] . ')';
        }
        shp_pdf_pair($pdf, shp_t('ShRepPeaks'), implode(' · ', $peaks), false, '', 110);
    }

    // By stand.
    bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepByStand')));
    $byStand = shp_rep_by_stand($sales);
    $ids = array_unique(array_merge(array_keys($byStand), array_keys($cash['stands'])));
    $order = array_flip(array_keys($stands));
    usort($ids, function ($a, $b) use ($order) { return ($order[$a] ?? 999999) <=> ($order[$b] ?? 999999); });
    $rows = array();
    foreach ($ids as $sd) {
        $rows[] = array(shp_rep_stand_name($stands[$sd] ?? null), (string) ($byStand[$sd]['qty'] ?? 0), shp_pdf_eur($byStand[$sd]['amount'] ?? 0),
            shp_pdf_eur(($cash['stands'][$sd]['net'] ?? 0)));
    }
    if (!$rows) $rows[] = array(shp_t('ShRepNothing'), '', '', '');
    shp_pdf_table($pdf, array(array(shp_t('ShRepColStand'), $w - 90), array(shp_t('ShRepColQty'), 24, 'R'), array(shp_t('ShRepColSales'), 33, 'R'),
        array(shp_t('ShRepColNet'), 33, 'R')), $rows);

    // By category.
    $cats = shp_rep_by_category($sales);
    if ($cats) {
        bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepByCategory')));
        $rows = array();
        foreach ($cats as $c) $rows[] = array($c['category'] !== '' ? $c['category'] : shp_t('ShRepNoCategory'), (string) $c['qty'], shp_pdf_eur($c['amount']));
        shp_pdf_table($pdf, array(array(shp_t('ShRepColCategory'), $w - 60), array(shp_t('ShRepColQty'), 24, 'R'), array(shp_t('ShRepColAmount'), 36, 'R')), $rows);
    }

    // Collections by means.
    bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepByMethod')));
    $wm = 36; $wn = ($w - $wm) / 4;
    $rows = array();
    foreach ($cash['methods'] as $code => $g) $rows[] = array_merge(array(shp_rep_method_label((string) $code)), shp_pdf_cash_cells($g));
    if (!$rows) $rows[] = array(shp_t('ShRepNoMoney'), '', '', '', '');
    shp_pdf_table($pdf, array(array(shp_t('ShRepColMethod'), $wm), array(shp_t('ShRepColCollected'), $wn, 'R'), array(shp_t('ShRepColRefunded'), $wn, 'R'),
        array(shp_t('ShRepColCancelled2'), $wn, 'R'), array(shp_t('ShRepColNet'), $wn, 'R')), $rows,
        array_merge(array(shp_t('ShRepTotal')), shp_pdf_cash_cells($cash['total'])));

    // Sales by article.
    [$rows, $q, $a] = shp_pdf_sales_rows($sales, $stands, $f['stand'] === 0);
    if ($rows) {
        bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepSalesByProduct')));
        shp_pdf_table($pdf, array(array(shp_t('ShRepColProduct'), $w - 80), array(shp_t('ShRepColUnit'), 26, 'R'), array(shp_t('ShRepColQty'), 20, 'R'),
            array(shp_t('ShRepColAmount'), 34, 'R')), $rows, array(shp_t('ShRepTotal'), '', (string) $q, shp_pdf_eur($a)));
    }

    // Stock.
    if ($stock) {
        bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepStockTitle')));
        $rows = array();
        foreach ($stock as $s) {
            $rows[] = array($s['label'], (string) $s['initial'], (string) $s['restock'], (string) $s['sold'], (string) $s['lost'],
                $s['left'] . ($s['low'] ? ' !' : ''));
        }
        $wn = 22;
        shp_pdf_table($pdf, array(array(shp_t('ShRepColProduct'), $w - 5 * $wn), array(shp_t('ShRepColInitial'), $wn, 'R'),
            array(shp_t('ShRepColRestock'), $wn, 'R'), array(shp_t('ShRepColSold'), $wn, 'R'), array(shp_t('ShRepColLost'), $wn, 'R'),
            array(shp_t('ShRepColLeft'), $wn, 'R')), $rows);
        $pdf->SetFont($pdf->FontStd, '', 8);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->MultiCell(0, 4, shp_pdf_text('! = ' . shp_t('ShRepLow')), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    // Accounts still open (count and sum only: the names are on the payments page).
    bk_pdf_section($pdf, shp_pdf_text(shp_t('ShRepAccountsTitle')));
    shp_pdf_pair($pdf, shp_t('ShRepAccountsCount'), (string) count($accounts['rows']));
    shp_pdf_pair($pdf, shp_t('ShRepAccountsLeft'), shp_pdf_eur($accounts['remaining']), true);
    return $pdf->Output('', 'S');
}
