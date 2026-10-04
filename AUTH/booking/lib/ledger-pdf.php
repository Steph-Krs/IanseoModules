<?php
/**
 * lib/ledger-pdf.php — paper documents of the accounts: an archer's receipt (everything
 * consumed, every movement, what is left) and the list of a competition's accounts.
 *
 * Core PDF class (Common/pdf/IanseoPdf.php): competition header, logos, page numbers. It
 * needs a competition session, which the archer's pages do not have — bk_ledger_pdf_build()
 * opens one around the drawing and restores the archer's session afterwards.
 */

if (defined('BK_LEDGER_PDF_LOADED')) return;
define('BK_LEDGER_PDF_LOADED', true);

require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/registration.php';   // bk_with_tournament

/**
 * An amount for the paper documents: bk_eur() (language's separators, competition's currency),
 * with an ASCII minus — the core fonts have no U+2212.
 */
function bk_pdf_eur($n)
{
    return str_replace('−', '-', bk_eur($n));
}

/**
 * Builds a PDF of competition $tourId and returns its bytes. $draw($pdf) writes the pages.
 * Opens the competition session only when it is not the open one (archer side).
 */
function bk_ledger_pdf_build($tourId, $title, $draw, $portrait = true)
{
    bk_money_tour($tourId);
    $make = function () use ($title, $draw, $portrait) {
        require_once('Common/pdf/IanseoPdf.php');
        $pdf = new IanseoPdf($title, $portrait);
        $pdf->setPrintFooter(true);
        $pdf->startPageGroup();   // the footer prints "page / pages of the group"
        $draw($pdf);
        return $pdf->Output('', 'S');
    };
    if (intval($_SESSION['TourId'] ?? 0) === intval($tourId)) return $make();
    return bk_with_tournament(intval($tourId), $make);
}

/** Sends PDF bytes to the browser, shown inline, and ends the script. */
function bk_ledger_pdf_send($bytes, $filename)
{
    $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $filename);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));   // bytes on purpose: an HTTP length
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

/** Title line of a document, under the competition header. */
function bk_pdf_title($pdf, $title, $sub = '')
{
    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell(0, 8, $title, 0, 1, 'L');
    if ($sub !== '') {
        $pdf->SetFont($pdf->FontStd, '', 9);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->MultiCell(0, 4.5, $sub, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }
    $pdf->Ln(2);
}

/** Section heading with a light band. */
function bk_pdf_section($pdf, $label)
{
    if ($pdf->GetY() > $pdf->getPageHeight() - 40) $pdf->AddPage();
    $pdf->Ln(2);
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->SetFillColor(225, 233, 245);
    $pdf->Cell(0, 6.5, $label, 0, 1, 'L', true);
    $pdf->SetFillColor(255, 255, 255);
}

/** One row of cells: [[width, text, align, style]]. Wraps the widest text column if needed. */
function bk_pdf_row($pdf, $cells, $border = 'B', $h = 5.5)
{
    if ($pdf->GetY() > $pdf->getPageHeight() - 22) $pdf->AddPage();
    $lines = 1;
    foreach ($cells as $c) {
        $pdf->SetFont($pdf->FontStd, $c[3] ?? '', 9);
        $lines = max($lines, $pdf->getNumLines((string) $c[1], $c[0]));
    }
    $rowH = $h * $lines;
    $x = $pdf->GetX(); $y = $pdf->GetY();
    foreach ($cells as $c) {
        $pdf->SetFont($pdf->FontStd, $c[3] ?? '', 9);
        $pdf->MultiCell($c[0], $rowH, (string) $c[1], $border, $c[2] ?? 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M');
        $x += $c[0];
    }
    $pdf->SetXY($pdf->getMargins()['left'], $y + $rowH);
}

/**
 * Receipt of one account (bk_account()): who, what was consumed with the tariff detail,
 * every movement of the journal (cancelled ones marked), and where it stands. $known false:
 * competition over and its organiser records no payment here — no balance is claimed.
 */
function bk_ledger_pdf_account($pdf, $a, $newPage = true, $known = true)
{
    if ($newPage) $pdf->AddPage();
    $methods = bk_payment_methods();
    $kinds = bk_ledger_kinds();
    $w = $pdf->getPageWidth() - 2 * IanseoPdf::sideMargin;

    $who = trim($a['name']) !== '' ? $a['name'] : $a['account'];
    $sub = array();
    if ($a['account'] !== '' && !bk_account_enid($a['account'])) $sub[] = bk_t('LicenceX', $a['account']);
    if ($a['club_name'] !== '' || $a['club_code'] !== '') $sub[] = trim($a['club_code'] . ' ' . $a['club_name']);
    $sub[] = bk_t('IssuedOn', bk_now_local_text());
    bk_pdf_title($pdf, bk_t('ReceiptOf', $who), implode(' · ', $sub));

    // What was consumed.
    bk_pdf_section($pdf, bk_t('Consumption'));
    $wa = 30; $wl = $w - $wa;
    foreach ($a['registrations'] as $r) {
        bk_pdf_row($pdf, array(array($wl, bk_t('RegDepLine', $r['session']) . ' — ' . $r['category'], 'L', 'B'),
            array($wa, bk_pdf_eur($r['price']), 'R', 'B')), '');
        foreach ($r['lines'] as $l) {
            bk_pdf_row($pdf, array(array($wl, '      ' . str_replace('ᵉ', 'e', $l['label']), 'L'),
                array($wa, bk_pdf_eur($l['amount']), 'R')), '', 4.5);
        }
    }
    foreach ($a['shop_lines'] as $s) {
        bk_pdf_row($pdf, array(array($wl, ($s['section'] !== '' ? $s['section'] : bk_t('Shop')) . ' — ' . $s['label']
                . '  (' . $s['qty'] . ' × ' . bk_pdf_eur($s['unit']) . ')', 'L'),
            array($wa, bk_pdf_eur($s['amount']), 'R')), '');
    }
    if (!$a['registrations'] && !$a['shop_lines']) {
        bk_pdf_row($pdf, array(array($w, bk_t('NoConsumption'), 'L', 'I')), '');
    }
    $pdf->Line(IanseoPdf::sideMargin, $pdf->GetY() + 0.5, IanseoPdf::sideMargin + $w, $pdf->GetY() + 0.5);
    $pdf->Ln(1);
    bk_pdf_row($pdf, array(array($wl, bk_t('TotalDue'), 'R', 'B'), array($wa, bk_pdf_eur($a['due']), 'R', 'B')), '');

    // Movements.
    bk_pdf_section($pdf, bk_t('PaymentsMoves'));
    $wd = 22; $wk = 30; $wm = 30; $wa = 30; $wlab = $w - $wd - $wk - $wm - $wa;
    if (!$a['moves']) {
        bk_pdf_row($pdf, array(array($w, bk_t('NoPayment'), 'L', 'I')), '');
    } else {
        bk_pdf_row($pdf, array(array($wd, bk_t('ColDate'), 'L', 'B'), array($wk, bk_t('ColKind'), 'L', 'B'), array($wm, bk_t('ColMeans'), 'L', 'B'),
            array($wlab, bk_t('ColLabel'), 'L', 'B'), array($wa, bk_t('ColAmount'), 'R', 'B')));
        foreach ($a['moves'] as $m) {
            $cancelled = intval($m->BlgCancelled) > 0;
            if ($cancelled) $pdf->SetTextColor(130, 130, 130);
            bk_pdf_row($pdf, array(
                array($wd, bk_date_dmy($m->BlgWhen), 'L'),
                array($wk, $kinds[$m->BlgKind] ?? $m->BlgKind, 'L'),
                array($wm, $methods[$m->BlgMethod] ?? '', 'L'),
                array($wlab, trim($m->BlgLabel . ($cancelled ? ' — ' . bk_t('CancelledWord') : ''), ' —'), 'L'),
                array($wa, bk_pdf_eur($m->BlgAmount), 'R'),
            ));
            $pdf->SetTextColor(0, 0, 0);
        }
    }
    $pdf->Ln(1);
    bk_pdf_row($pdf, array(array($w - 30, bk_t('TotalPaid'), 'R', 'B'), array(30, bk_pdf_eur($a['paid']), 'R', 'B')), '');

    // Balance.
    $pdf->Ln(3);
    $state = bk_account_state($a);
    $pdf->SetFont($pdf->FontStd, 'B', 12);
    if (!$known) {
        $pdf->SetFont($pdf->FontStd, '', 10);
        $pdf->MultiCell(0, 5, bk_t('PdfNotTracked'), 1, 'C');
    } elseif ($state === 'over') {
        $pdf->Cell(0, 8, bk_t('PdfOver', bk_pdf_eur(-$a['remaining'])), 1, 1, 'C');
    } elseif ($state === 'settled') {
        $pdf->Cell(0, 8, bk_t('PdfSettled'), 1, 1, 'C');
    } elseif ($state === 'none') {
        $pdf->Cell(0, 8, bk_t('StateNone'), 1, 1, 'C');
    } else {
        $pdf->Cell(0, 8, bk_t('LeftToPayX', bk_pdf_eur($a['remaining'])), 1, 1, 'C');
    }
    $pdf->Ln(3);
    $pdf->SetFont($pdf->FontStd, '', 8);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->MultiCell(0, 4, bk_t('PdfNote'), 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

/**
 * List of accounts (bk_accounts() rows): one line each, then the totals. $title above it.
 */
function bk_ledger_pdf_list($pdf, $rows, $title, $sub = '')
{
    $pdf->AddPage();
    bk_pdf_title($pdf, $title, $sub);
    $w = $pdf->getPageWidth() - 2 * IanseoPdf::sideMargin;
    $wn = 18; $wm = 24; $we = 32; $wl = 22;
    $rest = $w - $wl - $wn - 3 * $wm - $we;
    $wa = round($rest * 0.55, 1); $wc = $rest - $wa;
    $head = array(array($wa, bk_t('ColArcher'), 'L', 'B'), array($wl, bk_t('Licence'), 'L', 'B'), array($wc, bk_t('Club'), 'L', 'B'),
        array($wn, bk_t('ColDeps'), 'R', 'B'), array($wm, bk_t('TotalDue'), 'R', 'B'), array($wm, bk_t('ColPaid'), 'R', 'B'),
        array($wm, bk_t('ColLeft'), 'R', 'B'), array($we, '   ' . bk_t('ColState'), 'L', 'B'));
    bk_pdf_row($pdf, $head);
    $t = array('due' => 0.0, 'paid' => 0.0, 'remaining' => 0.0, 'over' => 0.0);
    foreach ($rows as $a) {
        if ($pdf->GetY() > $pdf->getPageHeight() - 24) { $pdf->AddPage(); bk_pdf_row($pdf, $head); }
        bk_pdf_row($pdf, array(array($wa, $a['name'] !== '' ? $a['name'] : $a['account'], 'L'),
            array($wl, $a['licence'], 'L'), array($wc, trim($a['club_code'] . ' ' . $a['club_name']), 'L'),
            array($wn, $a['count'] ?: '', 'R'), array($wm, bk_pdf_eur($a['due']), 'R'),
            array($wm, bk_pdf_eur($a['paid']), 'R'), array($wm, bk_pdf_eur($a['remaining']), 'R'),
            array($we, '   ' . bk_account_state_label(bk_account_state($a)), 'L')));
        $t['due'] += $a['due']; $t['paid'] += $a['paid'];
        if ($a['remaining'] > 0) $t['remaining'] += $a['remaining']; else $t['over'] -= $a['remaining'];
    }
    bk_pdf_row($pdf, array(array($wa + $wl + $wc + $wn, bk_t(count($rows) > 1 ? 'TotalAccountsMany' : 'TotalAccountsOne', count($rows)), 'L', 'B'),
        array($wm, bk_pdf_eur($t['due']), 'R', 'B'), array($wm, bk_pdf_eur($t['paid']), 'R', 'B'),
        array($wm, bk_pdf_eur($t['remaining']), 'R', 'B'),
        array($we, $t['over'] > 0.005 ? '   ' . bk_t('ToGiveBack', bk_pdf_eur($t['over'])) : '', 'L', 'B')), 'T');
}

/** Current date and time in the server's zone (PHP itself runs in UTC), day/month/year. */
function bk_now_local_text()
{
    $now = new DateTime('now', bk_server_tz());
    return bk_t('DateAt', array('date' => $now->format('d/m/Y'), 'time' => $now->format(bk_t('TimeFormat'))));
}
