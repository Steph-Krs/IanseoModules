<?php
/**
 * lib/ledger-export.php — the treasurer's documents of a competition, from the payment journal
 * (BookingLedger). READ ONLY.
 *
 * - The journal as CSV (bk_ledger_csv_send): every line, cancelled ones and their counter lines
 *   included, with where the money was taken (check-in desk, a stand, online, the organiser) and
 *   the closing of the desk's till it belongs to. A spreadsheet rebuilds any total from it.
 * - The cheque deposit slip (bk_cheque_slip_pdf): the cheques to take to the bank. One cheque is
 *   one line of the journal, or one GROUP of lines (a club settling all its archers with a single
 *   cheque, bk_ledger_pay_club). A cheque cancelled or rejected (counter line) is left out, so are
 *   refunds: they are cheques given, not received.
 */

if (defined('BK_LEDGER_EXPORT_LOADED')) return;
define('BK_LEDGER_EXPORT_LOADED', true);

require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/ledger-pdf.php';

/* ------------------------------------------------------------------ */
/* CSV                                                                 */
/* ------------------------------------------------------------------ */

/**
 * A text cell of an Excel-friendly CSV (separator ';'). A cell starting with =, +, - or @ would be
 * run as a formula by a spreadsheet: it is prefixed with an apostrophe.
 */
function bk_csv_text($v)
{
    $v = str_replace(array("\r", "\n", "\t"), ' ', (string) $v);
    if ($v !== '' && strpos('=+-@', $v[0]) !== false) $v = "'" . $v;   // bytes: the first character is ASCII
    return '"' . str_replace('"', '""', $v) . '"';
}

/** An amount with the decimal separator of the visitor's language, no grouping. */
function bk_csv_num($n)
{
    return number_format((float) $n, 2, bk_number_seps()['dec'], '');
}

/** Name, licence and club of each account of the journal: [account => ['name', 'licence', 'club']]. */
function bk_ledger_export_names($tourId)
{
    $accounts = bk_accounts($tourId);
    $out = array();
    foreach ($accounts as $k => $a) {
        $out[(string) $k] = array('name' => (string) $a['name'], 'licence' => (string) $a['licence'],
            'club' => trim($a['club_code'] . ' ' . $a['club_name']));
        foreach ($a['accounts'] ?? array() as $tk => $t) {   // the summary line carries the accounts without a name
            $out[(string) $tk] = array('name' => (string) ($t['name'] ?? $tk), 'licence' => '', 'club' => '');
        }
    }
    return $out;
}

/** Where the money of a journal line was taken. */
function bk_ledger_origin($l, array $standNames)
{
    if (intval($l->BlgStand) > 0) return bk_t('CsvOriginStand', $standNames[intval($l->BlgStand)] ?? ('#' . intval($l->BlgStand)));
    if ((string) $l->BlgProvider !== 'manual') return bk_t('CsvOriginOnline');
    if (intval($l->BlgStaff) > 0) return bk_t('CsvOriginDesk');
    return bk_t('CsvOriginOrganiser');
}

/**
 * Sends the whole journal of a competition as CSV and ends the script. UTF-8 with a byte-order
 * mark, so that Excel reads the accents; separator ';' as Excel in French expects.
 */
function bk_ledger_csv_send($tourId, $filename)
{
    bk_schema();
    $tourId = intval($tourId);
    bk_money_tour($tourId);
    $names = bk_ledger_export_names($tourId);
    $kinds = bk_ledger_kinds();
    $methods = bk_payment_methods();
    $states = array('done' => bk_t('CsvStatusDone'), 'pending' => bk_t('CsvStatusPending'), 'failed' => bk_t('CsvStatusFailed'));
    $standNames = array();
    $staff = function ($id) { return ''; };
    if (bk_shp()) {
        foreach (shp_stands($tourId, false) as $sid => $sd) $standNames[intval($sid)] = (string) $sd->SdName;
        if (function_exists('shp_staff_label')) $staff = function ($id) { return shp_staff_label($id); };
    }
    // The desk's closings, when the till exists (ShopDeskCloses comes with the shop's schema).
    $closes = array();
    $rs = safe_r_sql("SELECT DcId, DcSession FROM ShopDeskCloses WHERE DcTournament = $tourId", false, true);
    if ($rs) while ($r = safe_fetch($rs)) $closes[intval($r->DcId)] = bk_t('DepCap', intval($r->DcSession));

    $rs = safe_r_sql("SELECT * FROM BookingLedger WHERE BlgTournament = $tourId ORDER BY BlgWhen, BlgId");
    $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $filename);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store');
    echo "\u{FEFF}";   // byte-order mark: without it Excel reads the file as ANSI and breaks the accents
    $line = function (array $cells) { echo implode(';', $cells) . "\r\n"; };
    $line(array_map('bk_csv_text', array(bk_t('CsvLine'), bk_t('ColDate'), bk_t('CsvHour'), bk_t('CsvAccount'), bk_t('ColArcher'),
        bk_t('Licence'), bk_t('Club'), bk_t('ColKind'), bk_t('ColMeans'), bk_t('DuChequeNo'), bk_t('ColAmount'), bk_t('ColLabel'),
        bk_t('CsvOrigin'), bk_t('DuEnteredBy'), bk_t('CsvStatus'), bk_t('CsvCancels'), bk_t('CsvCancelledBy'), bk_t('CsvDeskClose'))));
    while ($l = safe_fetch($rs)) {
        $n = $names[(string) $l->BlgAccount] ?? array('name' => '', 'licence' => '', 'club' => '');
        $date = $hour = '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/', (string) $l->BlgWhen, $m)) {
            $date = "$m[3]/$m[2]/$m[1]";
            $hour = "$m[4]:$m[5]";
        }
        $by = intval($l->BlgStaff) > 0 ? $staff(intval($l->BlgStaff)) : '';
        $line(array(intval($l->BlgId), bk_csv_text($date), bk_csv_text($hour), bk_csv_text($l->BlgAccount), bk_csv_text($n['name']),
            bk_csv_text($n['licence']), bk_csv_text($n['club']), bk_csv_text($kinds[$l->BlgKind] ?? $l->BlgKind),
            bk_csv_text($methods[$l->BlgMethod] ?? $l->BlgMethod), bk_csv_text($l->BlgMethod === 'cheque' ? $l->BlgRef : ''),
            bk_csv_num($l->BlgAmount), bk_csv_text($l->BlgLabel), bk_csv_text(bk_ledger_origin($l, $standNames)),
            bk_csv_text($by !== '' ? $by : $l->BlgBy), bk_csv_text($states[$l->BlgStatus] ?? $l->BlgStatus),
            intval($l->BlgCancels) ?: '', intval($l->BlgCancelled) ?: '',
            bk_csv_text($closes[intval($l->BlgDeskClose ?? 0)] ?? '')));
    }
    exit;
}

/* ------------------------------------------------------------------ */
/* Cheque deposit slip                                                 */
/* ------------------------------------------------------------------ */

/**
 * The cheques received for a competition, oldest first: [['when', 'drawer', 'number', 'amount',
 * 'lines']]. A group of lines (club payment) is one cheque, drawn by the club.
 */
function bk_cheques($tourId)
{
    bk_schema();
    $tourId = intval($tourId);
    $names = bk_ledger_export_names($tourId);
    $out = array();
    $rs = safe_r_sql("SELECT BlgId, BlgAccount, BlgAmount, BlgRef, BlgGroup, BlgClub, BlgWhen FROM BookingLedger
        WHERE BlgTournament = $tourId AND BlgMethod = 'cheque' AND BlgKind = 'payment' AND BlgStatus = 'done' AND BlgCancelled = 0
        ORDER BY BlgWhen, BlgId");
    while ($l = safe_fetch($rs)) {
        $key = intval($l->BlgGroup) > 0 ? 'g' . intval($l->BlgGroup) : 'l' . intval($l->BlgId);
        $n = $names[(string) $l->BlgAccount] ?? array('name' => (string) $l->BlgAccount, 'club' => '');
        if (!isset($out[$key])) {
            $club = trim((string) $l->BlgClub);
            $drawer = intval($l->BlgGroup) > 0 && $club !== ''
                ? trim($club . ' ' . bk_ledger_club_name($tourId, $club))
                : trim(($n['name'] !== '' ? $n['name'] : (string) $l->BlgAccount) . ($n['club'] !== '' ? ' (' . $n['club'] . ')' : ''));
            $out[$key] = array('when' => (string) $l->BlgWhen, 'drawer' => $drawer, 'number' => '', 'amount' => 0.0, 'lines' => 0);
        }
        $out[$key]['amount'] += (float) $l->BlgAmount;
        $out[$key]['lines']++;
        if ($out[$key]['number'] === '') $out[$key]['number'] = (string) $l->BlgRef;
    }
    foreach ($out as $k => $c) $out[$k]['amount'] = round($c['amount'], 2);
    return array_values($out);
}

/** Name of a club code, from the participants of the competition ('' when unknown). */
function bk_ledger_club_name($tourId, $code)
{
    $r = safe_fetch(safe_r_sql("SELECT CoName FROM Countries WHERE CoTournament = " . intval($tourId) . "
        AND CoCode = " . StrSafe_DB((string) $code) . " LIMIT 1"));
    return $r ? (string) $r->CoName : '';
}

/**
 * The cheque deposit slip of a competition as a PDF of the core classes (competition header,
 * logos, page numbers): every cheque with its drawer, number and amount, the total, then the
 * bank's fields and the signature of who takes it to the bank. Returns the PDF bytes.
 */
function bk_cheque_slip_pdf($tourId)
{
    $tourId = intval($tourId);
    $cheques = bk_cheques($tourId);
    return bk_ledger_pdf_build($tourId, bk_t('DuSlip'), function ($pdf) use ($cheques) {
        $pdf->AddPage();
        bk_pdf_title($pdf, bk_t('DuSlip'), bk_t('SituationOn', bk_now_local_text()));
        $w = $pdf->getPageWidth() - 2 * IanseoPdf::sideMargin;
        $wn = 10; $wd = 22; $wc = 36; $wa = 28;
        $wt = $w - $wn - $wd - $wc - $wa;
        $head = array(array($wn, '#', 'R', 'B'), array($wd, bk_t('ColDate'), 'L', 'B'), array($wt, bk_t('SlipDrawer'), 'L', 'B'),
            array($wc, bk_t('DuChequeNo'), 'L', 'B'), array($wa, bk_t('ColAmount'), 'R', 'B'));
        bk_pdf_row($pdf, $head);
        if (!$cheques) bk_pdf_row($pdf, array(array($w, bk_t('SlipNone'), 'L')));
        $total = 0.0;
        foreach ($cheques as $i => $c) {
            if ($pdf->GetY() > $pdf->getPageHeight() - 24) { $pdf->AddPage(); bk_pdf_row($pdf, $head); }
            bk_pdf_row($pdf, array(array($wn, (string) ($i + 1), 'R'), array($wd, bk_date_dmy(bk_date_iso($c['when'])), 'L'),
                array($wt, $c['drawer'], 'L'), array($wc, $c['number'], 'L'), array($wa, bk_pdf_eur($c['amount']), 'R')));
            $total += $c['amount'];
        }
        bk_pdf_row($pdf, array(array($w - $wa, bk_t('SlipCount', count($cheques)), 'L', 'B'),
            array($wa, bk_pdf_eur(round($total, 2)), 'R', 'B')), 'T');

        // The bank's fields and the signature, kept together on one page.
        if ($pdf->GetY() > $pdf->getPageHeight() - 70) $pdf->AddPage();
        $pdf->Ln(8);
        $pdf->SetFont($pdf->FontStd, '', 9);
        $x = IanseoPdf::sideMargin;
        foreach (array('SlipBank', 'SlipAccount') as $key) {
            $y = $pdf->GetY() + 6;
            $pdf->SetXY($x, $y - 5);
            $pdf->Cell(40, 5, bk_t($key), 0, 0, 'L');
            $pdf->Line($x + 40, $y, $x + $w, $y);
            $pdf->SetY($y + 3);
        }
        $pdf->Ln(6);
        $pdf->SetFont($pdf->FontStd, 'B', 10);
        $pdf->Cell(0, 6, bk_t('SlipSign'), 0, 1, 'L');
        $pdf->SetFont($pdf->FontStd, '', 9);
        $y = $pdf->GetY() + 14;
        $cw = ($w - 12) / 3;
        foreach (array('SlipSignName', 'SlipSignDate', 'SlipSignSignature') as $i => $key) {
            $cx = $x + $i * ($cw + 6);
            $pdf->Line($cx, $y, $cx + $cw, $y);
            $pdf->SetXY($cx, $y + 1);
            $pdf->Cell($cw, 5, bk_t($key), 0, 0, 'L');
        }
    });
}
