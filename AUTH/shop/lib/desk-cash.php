<?php
/**
 * lib/desk-cash.php — the till of the check-in desk, counted at the end of each departure
 * (desk/cash.php).
 *
 * WHAT is in the till: the lines of the payment journal (BookingLedger) taken AT THE DESK
 * (lib/desk.php, shp_pay_account with no stand: a volunteer, or the organiser who opened the
 * desk on their device — BlgStaff > 0), and the counter lines (cancel, reject) of those lines,
 * wherever they were made, with their negative amount: the till counts what was really taken at
 * the time of the closing. What the organiser records on the Payments page (cheques by post,
 * transfers, a club paying beforehand) never went through the desk's drawer: not in the till.
 * Neither are online payments nor the stands (their own cash report).
 *
 * WHEN: a CLOSING (ShopDeskCloses) takes every line of the till not closed yet and ties it to the
 * departure chosen by the organiser (BookingLedger.BlgDeskClose = DcId). The departures' times
 * are rarely filled in ianseo (2026-11): the organiser says when a departure ends, not the
 * clock. A departure may be closed several times (a late payment after the closing): its till
 * is the sum of its closings. Only the LAST closing may be undone (its lines go back to the
 * open till).
 *
 * COUNT: a closing may carry the count of the drawer: the float put in at the start (DcFloat)
 * and the cash found at the end (DcCounted), typed at the closing or later. The gap is
 * counted - float - cash expected, the cash expected being the closing's cash lines: a line
 * cancelled after the closing has its counter line in the open till, so a closing's figures
 * never move once made.
 */

if (defined('SHP_DESK_CASH_LOADED')) return;
define('SHP_DESK_CASH_LOADED', true);

require_once __DIR__ . '/desk.php';

/** SQL condition: the lines of the till of a competition (alias-free, on BookingLedger). */
function shp_cash_where($tourId)
{
    $t = intval($tourId);
    return "BlgTournament = $t AND BlgStand = 0 AND BlgOrder = 0 AND BlgStatus = 'done' AND BlgProvider = 'manual'
        AND (BlgStaff > 0 OR BlgCancels IN (SELECT BlgId FROM BookingLedger
            WHERE BlgTournament = $t AND BlgStand = 0 AND BlgOrder = 0 AND BlgStaff > 0 AND BlgCancels = 0))";
}

/**
 * Closings of a competition, oldest first: [DcId => ['id', 'session', 'when', 'by', 'total', 'n',
 * 'cash' (cash expected), 'float', 'counted' (null while not counted), 'gap' (null likewise)]].
 */
function shp_cash_closes($tourId)
{
    shp_schema();
    $tourId = intval($tourId);
    $out = array();
    $rs = safe_r_sql("SELECT DcId, DcSession, DcWhen, DcBy, DcFloat, DcCounted,
            (SELECT IFNULL(SUM(BlgAmount), 0) FROM BookingLedger WHERE BlgDeskClose = DcId AND " . shp_cash_where($tourId) . ") AS Total,
            (SELECT IFNULL(SUM(BlgAmount), 0) FROM BookingLedger WHERE BlgDeskClose = DcId AND BlgMethod = 'cash' AND " . shp_cash_where($tourId) . ") AS Cash,
            (SELECT COUNT(*) FROM BookingLedger WHERE BlgDeskClose = DcId AND " . shp_cash_where($tourId) . ") AS N
        FROM ShopDeskCloses WHERE DcTournament = $tourId ORDER BY DcId");
    while ($r = safe_fetch($rs)) {
        $cash = round((float) $r->Cash, 2);
        $float = $r->DcFloat === null ? null : round((float) $r->DcFloat, 2);
        $counted = $r->DcCounted === null ? null : round((float) $r->DcCounted, 2);
        $out[intval($r->DcId)] = array('id' => intval($r->DcId), 'session' => intval($r->DcSession), 'when' => (string) $r->DcWhen,
            'by' => (string) $r->DcBy, 'total' => round((float) $r->Total, 2), 'n' => intval($r->N),
            'cash' => $cash, 'float' => $float, 'counted' => $counted,
            'gap' => $counted === null ? null : round($counted - (float) $float - $cash, 2));
    }
    return $out;
}

/**
 * Count of a departure from its closings: ['cash', 'float', 'counted', 'gap', 'all' (every closing
 * counted)], sums over the closings counted; null when none is.
 */
function shp_cash_session_count(array $closes, $session)
{
    $t = array('cash' => 0.0, 'float' => 0.0, 'counted' => 0.0, 'gap' => 0.0, 'all' => true);
    $any = false;
    foreach ($closes as $c) {
        if ($c['session'] !== intval($session)) continue;
        if ($c['counted'] === null) {
            $t['all'] = false;
            continue;
        }
        $any = true;
        $t['cash'] += $c['cash'];
        $t['float'] += (float) $c['float'];
        $t['counted'] += $c['counted'];
        $t['gap'] += $c['gap'];
    }
    if (!$any) return null;
    foreach (array('cash', 'float', 'counted', 'gap') as $k) $t[$k] = round($t[$k], 2);
    return $t;
}

/**
 * An amount typed for the count: null when left empty, false when unreadable, else the amount
 * (0 allowed: an empty drawer is a count).
 */
function shp_cash_amount_in($v)
{
    $v = str_replace(array(' ', "\u{00A0}", "\u{20AC}"), '', str_replace(',', '.', trim((string) $v)));
    if ($v === '') return null;
    return preg_match('/^\d{1,6}(\.\d{1,2})?$/', $v) ? round((float) $v, 2) : false;
}

/** The float of the last closing of a competition, proposed for the next one (null: none). */
function shp_cash_last_float($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT DcFloat FROM ShopDeskCloses WHERE DcTournament = " . intval($tourId) . "
        AND DcFloat IS NOT NULL ORDER BY DcId DESC LIMIT 1"));
    return $r ? round((float) $r->DcFloat, 2) : null;
}

/**
 * Records the count of a closing (typed at the closing or later, corrected as often as needed).
 * $counted null clears the count. Returns false for a closing of another competition.
 */
function shp_cash_count($tourId, $closeId, $float, $counted)
{
    shp_schema();
    $tourId = intval($tourId);
    $closeId = intval($closeId);
    if ($closeId <= 0 || !safe_fetch(safe_r_sql("SELECT DcId FROM ShopDeskCloses WHERE DcId = $closeId AND DcTournament = $tourId"))) return false;
    $sql = function ($v) { return $v === null ? 'NULL' : StrSafe_DB(number_format((float) $v, 2, '.', '')); };
    safe_w_sql("UPDATE ShopDeskCloses SET DcFloat = " . ($counted === null ? 'NULL' : $sql($float ?? 0.0)) . ",
        DcCounted = " . $sql($counted) . " WHERE DcId = $closeId AND DcTournament = $tourId");
    return true;
}

/**
 * Lines of the till for a view: 'open' (not closed yet) or a departure number (its closings).
 * Oldest first: ['id', 'account', 'kind', 'amount',
 * 'method', 'label', 'when', 'staff', 'by', 'cancelled', 'close'].
 */
function shp_cash_lines($tourId, $view)
{
    shp_schema();
    $tourId = intval($tourId);
    $w = shp_cash_where($tourId);
    if ($view === 'open') {
        $w .= " AND BlgDeskClose = 0";
    } else {
        $w .= " AND BlgDeskClose IN (SELECT DcId FROM ShopDeskCloses WHERE DcTournament = $tourId AND DcSession = " . intval($view) . ")";
    }
    $out = array();
    $rs = safe_r_sql("SELECT BlgId, BlgAccount, BlgKind, BlgAmount, BlgMethod, BlgLabel, BlgWhen, BlgStaff, BlgBy, BlgCancelled, BlgDeskClose
        FROM BookingLedger WHERE $w ORDER BY BlgWhen, BlgId");
    while ($r = safe_fetch($rs)) {
        $out[] = array('id' => intval($r->BlgId), 'account' => (string) $r->BlgAccount, 'kind' => (string) $r->BlgKind,
            'amount' => round((float) $r->BlgAmount, 2), 'method' => (string) $r->BlgMethod, 'label' => (string) $r->BlgLabel,
            'when' => (string) $r->BlgWhen, 'staff' => intval($r->BlgStaff), 'by' => (string) $r->BlgBy,
            'cancelled' => intval($r->BlgCancelled) > 0, 'close' => intval($r->BlgDeskClose));
    }
    return $out;
}

/** Who took a line: the volunteer (their current label), else the organiser's name. */
function shp_cash_who(array $l)
{
    if ($l['staff'] > 0 && function_exists('shp_staff_label')) return shp_staff_label($l['staff']);
    return $l['by'] !== '' ? $l['by'] : shp_t('ShRepOrganiser');
}

/**
 * Totals of lines: ['total', 'n' (payments and refunds, not their counter lines),
 * 'methods' => [method => ['amount', 'n']], 'who' => [label => [method => amount, '' => total]]].
 */
function shp_cash_totals(array $lines)
{
    $t = array('total' => 0.0, 'n' => 0, 'methods' => array(), 'who' => array());
    foreach ($lines as $l) {
        $m = $l['method'];
        $who = shp_cash_who($l);
        if (!isset($t['methods'][$m])) $t['methods'][$m] = array('amount' => 0.0, 'n' => 0);
        if (!isset($t['who'][$who])) $t['who'][$who] = array('' => 0.0);
        if (!isset($t['who'][$who][$m])) $t['who'][$who][$m] = 0.0;
        $t['total'] += $l['amount'];
        $t['methods'][$m]['amount'] += $l['amount'];
        $t['who'][$who][$m] += $l['amount'];
        $t['who'][$who][''] += $l['amount'];
        if ($l['kind'] === 'payment' || $l['kind'] === 'refund') {
            $t['n']++;
            $t['methods'][$m]['n']++;
        }
    }
    $round = function (&$v) { if (is_float($v)) $v = round($v, 2); };
    array_walk_recursive($t, $round);
    return $t;
}

/**
 * Closes the open till on a departure: every line not closed yet (but the ones paid before the
 * desk opens) is tied to it, with the count of the drawer when typed (shp_cash_count). Returns
 * the closing's id, 0 when there was nothing to close.
 */
function shp_cash_close($tourId, $session, $by, $float = null, $counted = null)
{
    shp_schema();
    $tourId = intval($tourId);
    $session = intval($session);
    if ($session <= 0 || $session > 255) return 0;
    $now = shp_local_now_sql($tourId);
    safe_w_BeginTransaction();
    // The lines first, then updated by their ids: MySQL refuses an UPDATE whose condition reads
    // the same table (the counter lines of the desk's lines, shp_cash_where).
    $ids = array();
    $rs = safe_w_sql("SELECT BlgId FROM BookingLedger WHERE " . shp_cash_where($tourId) . " AND BlgDeskClose = 0 FOR UPDATE");
    while ($r = safe_fetch($rs)) $ids[] = intval($r->BlgId);
    if (!$ids) {
        safe_w_Rollback();
        return 0;
    }
    safe_w_sql("INSERT INTO ShopDeskCloses SET DcTournament = $tourId, DcSession = $session, DcWhen = $now,
        DcBy = " . StrSafe_DB(mb_substr((string) $by, 0, 64)));
    $id = intval(safe_w_last_id());
    safe_w_sql("UPDATE BookingLedger SET BlgDeskClose = $id WHERE BlgTournament = $tourId AND BlgId IN (" . implode(',', $ids) . ")");
    safe_w_Commit();
    if ($counted !== null) shp_cash_count($tourId, $id, $float, $counted);
    return $id;
}

/** Undoes the LAST closing of a competition: its lines go back to the open till. */
function shp_cash_reopen($tourId, $closeId)
{
    shp_schema();
    $tourId = intval($tourId);
    $closeId = intval($closeId);
    safe_w_BeginTransaction();
    $last = safe_fetch(safe_w_sql("SELECT MAX(DcId) AS id FROM ShopDeskCloses WHERE DcTournament = $tourId FOR UPDATE"));
    if (!$last || intval($last->id) !== $closeId || $closeId <= 0) {
        safe_w_Rollback();
        return false;
    }
    safe_w_sql("UPDATE BookingLedger SET BlgDeskClose = 0 WHERE BlgTournament = $tourId AND BlgDeskClose = $closeId");
    safe_w_sql("DELETE FROM ShopDeskCloses WHERE DcId = $closeId AND DcTournament = $tourId");
    safe_w_Commit();
    return true;
}

/**
 * Till sheet of a view ('open' or a departure number) as a PDF of the core classes (IanseoPdf:
 * competition header, logos, page numbers), drawn with the helpers of the stands' cash closing
 * (lib/report-pdf.php). Same figures as the page: totals by means, by volunteer, the closings of a
 * departure, every line; then the count of the drawer and the signature. Returns the PDF bytes.
 * Runs on an organiser page, where the competition is open.
 */
function shp_cash_pdf($tourId, $view, array $sessions)
{
    require_once __DIR__ . '/report-pdf.php';
    require_once('Common/pdf/IanseoPdf.php');
    $tourId = intval($tourId);
    bk_money_tour($tourId);
    $lines = shp_cash_lines($tourId, $view);
    $tot = shp_cash_totals($lines);
    $methods = bk_payment_methods();
    $mLabel = function ($m) use ($methods) { return $methods[$m] ?? ($m !== '' ? $m : '-'); };
    $names = array();
    foreach (bk_accounts($tourId) as $k => $a) $names[(string) $k] = $a;
    $viewLabel = $view === 'open' ? shp_t('DkCashViewOpen') : ($sessions[$view]['label'] ?? bk_t('DepCap', $view));

    $pdf = new IanseoPdf(shp_pdf_text(shp_t('DkCashTitle')), true);
    $pdf->setPrintFooter(true);
    $pdf->startPageGroup();   // the footer prints "page / pages of the group"
    $pdf->AddPage();
    $w = shp_pdf_width($pdf);
    shp_pdf_head($pdf, shp_t('DkCashTitle'), $viewLabel);

    // By means of payment.
    bk_pdf_section($pdf, shp_pdf_text(shp_t('DkCashTotal')));
    $rows = array();
    foreach ($tot['methods'] as $m => $x) $rows[] = array($mLabel($m), shp_t('DkCashCount', $x['n']), shp_pdf_eur($x['amount']));
    if (!$rows) $rows[] = array(shp_t('DkCashNone'), '', '');
    shp_pdf_table($pdf, array(array(shp_t('DkCashMethod'), $w - 80), array('', 40, 'R'), array(shp_t('DkCashAmount'), 40, 'R')),
        $rows, array(shp_t('DkCashTotal'), shp_t('DkCashCount', $tot['n']), shp_pdf_eur($tot['total'])));
    // Count of the drawer: the figures once every closing of the departure is counted, else the
    // lines to fill in by hand.
    $closes = shp_cash_closes($tourId);
    $count = $view === 'open' ? null : shp_cash_session_count($closes, $view);
    $pdf->Ln(2);
    if ($count && $count['all']) {
        shp_pdf_pair($pdf, shp_t('DkCashFloat'), shp_pdf_eur($count['float']));
        shp_pdf_pair($pdf, shp_t('DkCashExpected'), shp_pdf_eur($count['cash']));
        shp_pdf_pair($pdf, shp_t('ShRepCashCounted'), shp_pdf_eur($count['counted']));
        shp_pdf_pair($pdf, shp_t('ShRepCashGap'), shp_pdf_eur($count['gap']));
    } else {
        shp_pdf_pair($pdf, shp_t('DkCashFloat') . ' ' . str_repeat('.', 40), '');
        shp_pdf_pair($pdf, shp_t('ShRepCashCounted') . ' ' . str_repeat('.', 40), '');
        shp_pdf_pair($pdf, shp_t('ShRepCashGap') . ' ' . str_repeat('.', 40), '');
    }

    // By volunteer: what each one must hand over.
    if ($lines) {
        bk_pdf_section($pdf, shp_pdf_text(shp_t('DkCashByWho')));
        $ms = array_keys($tot['methods']);
        $wn = min(30, ($w - 50) / (count($ms) + 1));
        $cols = array(array(shp_t('DkCashWho'), $w - $wn * (count($ms) + 1)));
        foreach ($ms as $m) $cols[] = array($mLabel($m), $wn, 'R');
        $cols[] = array(shp_t('DkCashTotal'), $wn, 'R');
        $rows = array();
        foreach ($tot['who'] as $who => $x) {
            $r = array($who);
            foreach ($ms as $m) $r[] = isset($x[$m]) ? shp_pdf_eur($x[$m]) : '';
            $r[] = shp_pdf_eur($x['']);
            $rows[] = $r;
        }
        shp_pdf_table($pdf, $cols, $rows);
    }

    // Closings of the departure.
    if ($view !== 'open') {
        $rows = array();
        foreach ($closes as $c) {
            if ($c['session'] !== $view) continue;
            $rows[] = array(bk_date_time($c['when']), $c['by'], (string) $c['n'], shp_pdf_eur($c['total']), shp_pdf_eur($c['cash']),
                $c['counted'] === null ? shp_t('DkCashNotCounted') : shp_pdf_eur($c['counted']),
                $c['gap'] === null ? '' : shp_pdf_eur($c['gap']));
        }
        if ($rows) {
            bk_pdf_section($pdf, shp_pdf_text(shp_t('DkCashClosesTitle')));
            shp_pdf_table($pdf, array(array(shp_t('DkCashWhen'), 28), array(shp_t('DkCashClosedBy'), $w - 142),
                array(shp_t('DkCashLinesN'), 14, 'R'), array(shp_t('DkCashTotal'), 24, 'R'), array(shp_t('DkCashExpected'), 26, 'R'),
                array(shp_t('DkCashCounted'), 26, 'R'), array(shp_t('DkCashGap'), 24, 'R')), $rows);
        }
    }

    // The lines themselves.
    if ($lines) {
        bk_pdf_section($pdf, shp_pdf_text(shp_t('DkCashLines')));
        $rows = array();
        foreach ($lines as $l) {
            $a = $names[$l['account']] ?? null;
            $who = $a ? trim($a['name'] . ($a['licence'] !== '' ? ' (' . $a['licence'] . ')' : '')) : $l['account'];
            $rows[] = array(bk_date_time($l['when']), $who,
                $l['label'] . ($l['cancelled'] ? ' - ' . shp_t('DkCashCancelled') : ''),
                $mLabel($l['method']), shp_cash_who($l), shp_pdf_eur($l['amount']));
        }
        shp_pdf_table($pdf, array(array(shp_t('DkCashWhen'), 28), array(shp_t('DkCashArcher'), $w - 140),
            array(shp_t('DkCashLabel'), 44), array(shp_t('DkCashMethod'), 22), array(shp_t('DkCashWho'), 26),
            array(shp_t('DkCashAmount'), 20, 'R')), $rows);
    }

    // Signature: name, date and signature on one row.
    if ($pdf->GetY() > $pdf->getPageHeight() - 52) $pdf->AddPage();
    $pdf->Ln(6);
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->Cell(0, 6, shp_pdf_text(shp_t('DkCashSignTitle')), 0, 1, 'L');
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
